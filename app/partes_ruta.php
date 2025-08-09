<?php
// Habilitar la visualización de errores para depuración (¡DESACTIVAR EN PRODUCCIÓN! EN PRODUCCIÓN DEBE ESTAR EN 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Incluir el archivo de verificación de autenticación al inicio de cada página protegida
// Asegúrate de que 'auth_check.php' exista y contenga tu lógica de autenticación
include 'auth_check.php';

// Iniciar sesión para gestionar mensajes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Inicializar variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// Función para mostrar mensajes (usada internamente y para guardar en sesión)
function mostrarMensaje($msg, $type) {
    $_SESSION['mensaje'] = $msg;
    $_SESSION['tipo_mensaje'] = $type;
}

// Cargar mensajes de la sesión si existen
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    $tipo_mensaje = $_SESSION['tipo_mensaje'];
    unset($_SESSION['mensaje']);
    unset($_SESSION['tipo_mensaje']);
}

// Si hay un error de conexión a la base de datos, mostrar un mensaje y salir
if (isset($pdo_error) && $pdo_error) { // Check if $pdo_error is set and true
    $mensaje = $pdo_error;
    $tipo_mensaje = 'danger';
    // Fix: Check if HTTP_X_REQUESTED_WITH is set before accessing it
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos: ' . $pdo_error]);
        exit();
    }
}

// Función auxiliar para formatear duración (segundos a HH:MM:SS) en PHP
function formatDurationPHP($seconds) {
    if (!is_numeric($seconds) || $seconds < 0) {
        return 'N/A';
    }
    $hours = floor($seconds / 3600);
    $seconds %= 3600;
    $minutes = floor($seconds / 60);
    $remainingSeconds = $seconds % 60;

    $result = '';
    if ($hours > 0) {
        $result .= "{$hours}h ";
    }
    // Show minutes if there are any, or if there are hours but no minutes (e.g., "1h 0m")
    if ($minutes > 0 || $hours > 0) {
        $result .= "{$minutes}m ";
    }
    // Show seconds if there are any, or if the total duration is less than a minute (e.g., "30s")
    if ($remainingSeconds > 0 || ($hours == 0 && $minutes == 0 && $seconds == 0)) {
        $result .= "{$remainingSeconds}s";
    }
    return trim($result);
}


// --- Lógica para cargar pedidos pendientes (para el modal "Añadir Pedidos") ---
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_orders') {
    
    header('Content-Type: application/json');

    $fecha_filtro = $_GET['fecha'] ?? null;
    $search_term = $_GET['search'] ?? null;

    try {
        // Esta consulta busca pedidos que están en estado 'pendiente'
        // y que no han sido completamente servidos a través de una factura,
        // o que no tienen factura asociada.
        // Se añade fv.estado_pago para obtener el estado de cobro de la factura.
        $sql = "
            SELECT
                p.id_pedido,
                p.fecha_pedido,
                p.id_cliente,
                c.nombre_cliente,
                COALESCE(p.total_pedido_iva_incluido, 0) AS total_importe, -- Usar el total del pedido directamente
                p.id_factura_asociada,
                fv.estado_pago, -- Nuevo campo: estado de pago de la factura
                -- Calcular total_unidades_solicitadas para el pedido
                (SELECT SUM(dp_sub.cantidad_solicitada) FROM detalle_pedidos dp_sub WHERE dp_sub.id_pedido = p.id_pedido) AS total_unidades,
                -- Calcular total_cantidad_facturada para el pedido
                (SELECT SUM(dfv_sub.cantidad) FROM detalle_factura_ventas dfv_sub WHERE dfv_sub.id_factura = p.id_factura_asociada) AS total_cantidad_facturada,
                -- Calcular total_unidades_servidas (unidades retiradas de facturas asociadas)
                (SELECT SUM(dfv_sub.unidades_retiradas) FROM detalle_factura_ventas dfv_sub WHERE dfv_sub.id_factura = p.id_factura_asociada) AS total_unidades_servidas,
                -- Datos de dirección de envío
                p.id_direccion_envio,
                de.direccion AS direccion_envio,
                de.ciudad AS ciudad_envio,
                de.provincia AS provincia_envio,
                de.codigo_postal AS codigo_postal_envio,
                de.nombre_direccion AS nombre_direccion_envio,
                de.direccion_google_maps AS direccion_google_maps_envio,
                -- Datos de dirección principal del cliente (fallback)
                c.direccion AS cliente_direccion_principal,
                c.ciudad AS cliente_ciudad_principal,
                c.provincia AS cliente_provincia_principal,
                c.codigo_postal AS cliente_codigo_postal_principal,
                c.direccion_google_maps AS cliente_direccion_google_maps_principal

            FROM
                pedidos p
            JOIN
                clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN
                facturas_ventas fv ON p.id_factura_asociada = fv.id_factura
            LEFT JOIN
                direcciones_envio de ON p.id_direccion_envio = de.id_direccion_envio
            WHERE
                p.estado_pedido = 'pendiente' -- Solo pedidos pendientes
                AND p.tipo_entrega = 'reparto_propio' -- Solo pedidos de reparto propio
                AND p.id_pedido NOT IN (SELECT id_pedido FROM partes_ruta_pedidos) -- Excluir pedidos ya en cualquier parte de ruta
        ";

        $params = [];

        if ($fecha_filtro) {
            $sql .= " AND p.fecha_pedido = ?";
            $params[] = $fecha_filtro;
        }

        if ($search_term) {
            $sql .= " AND (c.nombre_cliente LIKE ? OR p.id_pedido LIKE ?)";
            $params[] = '%' . $search_term . '%';
            $params[] = '%' . $search_term . '%';
        }

        // No necesitamos GROUP BY aquí porque las subconsultas ya agregan por pedido
        // Y las columnas seleccionadas son todas de la tabla 'pedidos' o 'clientes' (o fv para estado_pago)
        $sql .= "
            ORDER BY
                p.fecha_pedido ASC;
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pedidos_pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'pedidos' => $pedidos_pendientes]);
    } catch (PDOException $e) {
        error_log("Error fetching pending orders: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al cargar pedidos pendientes: ' . $e->getMessage()]);
    }
    exit();
}

// --- Lógica para obtener pedidos por parte de ruta para el mapa (AJAX) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_pedidos_by_parte_ruta') {
    header('Content-Type: application/json');

    $id_parte_ruta = $_GET['id_parte_ruta'] ?? null;

    if (empty($id_parte_ruta)) {
        echo json_encode(['success' => false, 'message' => 'ID de parte de ruta es obligatorio.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id_pedido,
                p.fecha_pedido,
                p.id_cliente,
                c.nombre_cliente,
                p.total_pedido_iva_incluido AS total_pedido, -- Usar el campo correcto
                p.estado_pedido,
                p.id_ruta,
                c.latitud,
                c.longitud,
                c.direccion_google_maps,
                p.tipo_entrega,
                p.empresa_transporte,
                p.numero_seguimiento,
                p.observaciones,
                p.id_direccion_envio, -- NEW: Select id_direccion_envio from pedidos
                de.latitud AS latitud_envio, -- NEW: Select shipping address latitude
                de.longitud AS longitud_envio, -- NEW: Select shipping address longitude
                de.direccion_google_maps AS direccion_google_maps_envio, -- NEW: Select shipping address Google Maps address
                de.direccion AS direccion_envio, -- Full address string for display
                de.ciudad AS ciudad_envio,
                de.provincia AS provincia_envio,
                de.codigo_postal AS codigo_postal_envio,
                de.nombre_direccion AS nombre_direccion_envio,
                c.direccion AS cliente_direccion_principal,
                c.ciudad AS cliente_ciudad_principal,
                c.provincia AS cliente_provincia_principal,
                c.codigo_postal AS cliente_codigo_postal_principal

            FROM
                partes_ruta_pedidos prp
            JOIN
                pedidos p ON prp.id_pedido = p.id_pedido
            JOIN
                clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN
                direcciones_envio de ON p.id_direccion_envio = de.id_direccion_envio -- NEW: Join with direcciones_envio
            WHERE
                prp.id_parte_ruta = ?
            ORDER BY
                p.fecha_pedido ASC
        ");
        $stmt->execute([$id_parte_ruta]);
        $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'pedidos' => $pedidos]);
    } catch (PDOException $e) {
        error_log("Error fetching pedidos by parte ruta: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al cargar pedidos para el mapa: ' . $e->getMessage()]);
    }
    exit();
}

// --- Lógica para obtener detalles completos de pedidos por parte de ruta (AJAX) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_pedidos_details_by_parte_ruta') {
        header('Content-Type: application/json');

    $id_parte_ruta = $_GET['id_parte_ruta'] ?? null;

    if (empty($id_parte_ruta)) {
        echo json_encode(['success' => false, 'message' => 'ID de parte de ruta es obligatorio.']);
        exit();
    }

    try {
        // MODIFICACIÓN CRÍTICA AQUÍ: Se añade DISTINCT para evitar duplicaciones
        $stmt_pedidos = $pdo->prepare("
            SELECT DISTINCT
                prp.id_parte_ruta_pedido,
                p.id_pedido,
                p.fecha_pedido,
                p.id_cliente,
                c.nombre_cliente,
                p.total_pedido_iva_incluido AS total_pedido,
                p.estado_pedido,
                p.id_factura_asociada,
                fv.estado_pago,
                prp.estado_pedido_ruta,
                prp.observaciones_detalle,
                c.latitud,
                c.longitud,
                c.direccion_google_maps,
                p.tipo_entrega,
                p.empresa_transporte,
                p.numero_seguimiento,
                p.observaciones AS pedido_observaciones,
                p.id_direccion_envio,
                de.latitud AS latitud_envio,
                de.longitud AS longitud_envio,
                de.direccion_google_maps AS direccion_google_maps_envio,
                de.direccion AS direccion_envio, -- NEW: Full address string for display
                de.ciudad AS ciudad_envio,       -- NEW: City for shipping address
                de.provincia AS provincia_envio, -- NEW: Province for shipping address
                de.codigo_postal AS codigo_postal_envio, -- NEW: Postal Code for shipping address
                de.nombre_direccion AS nombre_direccion_envio, -- NEW: Name of the shipping address
                c.direccion AS cliente_direccion_principal, -- NEW: Client's main address
                c.ciudad AS cliente_ciudad_principal,       -- NEW: Client's main city
                c.provincia AS cliente_provincia_principal, -- NEW: Client's main province
                c.codigo_postal AS cliente_codigo_postal_principal, -- NEW: Client's main postal code
                -- Subconsultas para obtener totales de unidades/facturación/retiro por pedido
                (SELECT SUM(dp_sub.cantidad_solicitada) FROM detalle_pedidos dp_sub WHERE dp_sub.id_pedido = p.id_pedido) AS total_unidades,
                (SELECT SUM(dfv_sub.cantidad) FROM detalle_factura_ventas dfv_sub WHERE dfv_sub.id_factura = p.id_factura_asociada) AS total_cantidad_facturada,
                (SELECT SUM(dfv_sub.unidades_retiradas) FROM detalle_factura_ventas dfv_sub WHERE dfv_sub.id_factura = p.id_factura_asociada) AS total_unidades_servidas
            FROM
                partes_ruta_pedidos prp
            JOIN
                pedidos p ON prp.id_pedido = p.id_pedido
            JOIN
                clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN
                direcciones_envio de ON p.id_direccion_envio = de.id_direccion_envio
            LEFT JOIN
                facturas_ventas fv ON p.id_factura_asociada = fv.id_factura
            WHERE
                prp.id_parte_ruta = ?
            ORDER BY
                p.fecha_pedido ASC
        ");
        $stmt_pedidos->execute([$id_parte_ruta]);
        $pedidos_data = $stmt_pedidos->fetchAll(PDO::FETCH_ASSOC);

        // Bucle para obtener los detalles de línea de cada pedido
        foreach ($pedidos_data as &$pedido) {
            $stmt_detalles = $pdo->prepare("
                SELECT
                    dp.id_detalle_pedido,
                    dp.id_producto,
                    prod.nombre_producto,
                    dp.cantidad_solicitada,
                    prod.litros_por_unidad, -- Added for PDF generation
                    prod.porcentaje_iva_actual, -- Added for PDF generation
                    dp.precio_unitario_venta,
                    dp.subtotal_linea_total AS subtotal_linea,
                    dp.observaciones_detalle,
                    -- Unir a detalle_factura_ventas solo si hay una factura asociada
                    dfv.id_detalle_factura AS id_detalle_factura_asociada,
                    dfv.unidades_retiradas AS unidades_retiradas_factura
                FROM
                    detalle_pedidos dp
                JOIN
                    productos prod ON dp.id_producto = prod.id_producto
                LEFT JOIN
                    detalle_factura_ventas dfv ON dfv.id_factura = ? AND dp.id_producto = dfv.id_producto
                WHERE
                    dp.id_pedido = ?
            ");
            $stmt_detalles->execute([$pedido['id_factura_asociada'], $pedido['id_pedido']]);
            $pedido['detalles'] = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['success' => true, 'pedidos' => $pedidos_data]);
    } catch (PDOException $e) {
        error_log("Error fetching full pedido details by parte ruta: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al cargar detalles de pedidos: ' . $e->getMessage()]);
    }
    exit();
}


// --- Lógica para PROCESAR el formulario de editar parte de ruta ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) { // Check for general form submission, not specific action
    // Asegurarse de que la respuesta sea JSON si es una solicitud AJAX
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
    }

    $id_parte_ruta = $_POST['id_parte_ruta'] ?? null;
    $id_ruta = $_POST['id_ruta'] ?? null;
    $fecha_parte = $_POST['fecha_parte'] ?? null; // Esta es la fecha programada para el reparto
    $observaciones = trim($_POST['observaciones'] ?? '');
    $pedidos_seleccionados_json = $_POST['pedidos_seleccionados'] ?? '[]'; // JSON string
    $pedidos_seleccionados = json_decode($pedidos_seleccionados_json, true);

    // Nuevos campos para kilómetros y duración
    // Casting explícito para asegurar el tipo de dato correcto
    $total_kilometros = (float)($_POST['total_kilometros'] ?? 0);
    $duracion_estimada_segundos = (int)($_POST['duracion_estimada_segundos'] ?? 0);

    // --- NUEVA LÍNEA DE DEPURACIÓN ---
    error_log("Recibido POST para parte de ruta. ID Parte: " . ($id_parte_ruta ?: 'Nuevo') . ", Pedidos JSON String recibido: " . $pedidos_seleccionados_json);
    error_log("Recibido POST para parte de ruta. Pedidos (decoded): " . print_r($pedidos_seleccionados, true));
    error_log("Recibido POST para parte de ruta. Kilómetros: " . $total_kilometros . ", Duración (segundos): " . $duracion_estimada_segundos);


    if (empty($id_ruta) || empty($fecha_parte)) {
        $mensaje = "Error: La ruta y la fecha del parte son obligatorias.";
        $tipo_mensaje = 'danger';
        error_log("Error de validación: Ruta o fecha vacías.");
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => false, 'message' => $mensaje]);
            exit();
        }
    } else {
        try {
            $pdo->beginTransaction();

            if ($id_parte_ruta) {
                // Actualizar parte de ruta existente
                $stmt = $pdo->prepare("UPDATE partes_ruta SET id_ruta = ?, fecha_parte = ?, observaciones = ?, total_kilometros = ?, duracion_estimada_segundos = ? WHERE id_parte_ruta = ?");
                $exec_result = $stmt->execute([$id_ruta, $fecha_parte, $observaciones, $total_kilometros, $duracion_estimada_segundos, $id_parte_ruta]);

                // Depuración: Verificar si la actualización afectó alguna fila y errores de PDO
                error_log("PDO execute result: " . ($exec_result ? 'true' : 'false'));
                error_log("PDO rowCount: " . $stmt->rowCount());
                error_log("PDO errorInfo: " . var_export($stmt->errorInfo(), true));


                // Eliminar asociaciones existentes en partes_ruta_pedidos para reinsertar
                $stmt_delete_asociaciones = $pdo->prepare("DELETE FROM partes_ruta_pedidos WHERE id_parte_ruta = ?");
                $stmt_delete_asociaciones->execute([$id_parte_ruta]);
                error_log("Parte de ruta actualizado: " . $id_parte_ruta . ". Pedidos antiguos eliminados.");

                $mensaje = "Parte de ruta actualizado exitosamente.";
                $tipo_mensaje = 'success';
            } else {
                // This block should ideally not be reached if creation is handled by crear_parte_ruta.php
                // However, keeping it for robustness in case of direct POST to this file for new creation.
                $stmt = $pdo->prepare("INSERT INTO partes_ruta (id_ruta, fecha_parte, observaciones, estado, total_kilometros, duracion_estimada_segundos) VALUES (?, ?, ?, 'Pendiente', ?, ?)");
                $exec_result = $stmt->execute([$id_ruta, $fecha_parte, $observaciones, $total_kilometros, $duracion_estimada_segundos]);
                $id_parte_ruta = $pdo->lastInsertId(); // Obtener el ID del nuevo parte de ruta

                error_log("PDO execute result (insert): " . ($exec_result ? 'true' : 'false'));
                error_log("PDO rowCount (insert): " . $stmt->rowCount());
                error_log("PDO errorInfo (insert): " . var_export($stmt->errorInfo(), true));
                error_log("Nuevo parte de ruta creado con ID: " . $id_parte_ruta);


                $mensaje = "Parte de ruta creado exitosamente.";
                $tipo_mensaje = 'success';
            }

            // Insertar nuevas asociaciones de pedidos en partes_ruta_pedidos
            if (!empty($pedidos_seleccionados)) {
                $stmt_asociacion = $pdo->prepare("INSERT INTO partes_ruta_pedidos (id_parte_ruta, id_pedido, estado_pedido_ruta) VALUES (?, ?, 'Pendiente')");
                foreach ($pedidos_seleccionados as $pedido_id) {
                    $stmt_asociacion->execute([$id_parte_ruta, $pedido_id]);
                    error_log("Pedido " . $pedido_id . " asociado al parte " . $id_parte_ruta);
                }
            } else {
                error_log("No se seleccionaron pedidos para asociar al parte " . $id_parte_ruta);
            }

            $pdo->commit();
            error_log("Transacción completada exitosamente para parte de ruta " . $id_parte_ruta);

            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => true, 'message' => $mensaje, 'id_parte_ruta' => $id_parte_ruta]);
                exit();
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = "Error al guardar el parte de ruta: " . $e->getMessage();
            $tipo_mensaje = 'danger';
            error_log("Error de base de datos al guardar parte de ruta: " . $e->getMessage());
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => false, 'message' => $mensaje]);
                exit();
            }
        }
    }
}

// --- Lógica para actualizar el estado de un pedido en un parte de ruta (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_pedido_ruta_status') {
    ob_clean(); // Limpiar cualquier salida anterior
    header('Content-Type: application/json');

    $id_parte_ruta_pedido = $_POST['id_parte_ruta_pedido'] ?? null;
    $new_status = $_POST['new_status'] ?? null;
    $new_observations = trim($_POST['new_observations'] ?? ''); // Asegúrate de que este campo se reciba

    if (empty($id_parte_ruta_pedido)) { // Removed new_status from required check
        echo json_encode(['success' => false, 'message' => 'ID de detalle de parte de ruta es obligatorio.']);
        exit();
    }

    // Validar que el nuevo estado sea uno de los valores permitidos del ENUM
    // Removed status validation as status is no longer updated via this action
    // $allowed_statuses = ['Pendiente', 'En Ruta', 'Completado', 'Entregado', 'Cancelado'];
    // if (!in_array($new_status, $allowed_statuses)) {
    //     echo json_encode(['success' => false, 'message' => 'Estado no válido.']);
    //     exit();
    // }

    try {
        // Updated query to only update observations
        $stmt = $pdo->prepare("UPDATE partes_ruta_pedidos SET observaciones_detalle = ? WHERE id_parte_ruta_pedido = ?");
        $stmt->execute([$new_observations, $id_parte_ruta_pedido]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Observaciones del pedido en ruta actualizadas exitosamente.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No se encontró el detalle del pedido o no hubo cambios.']);
        }
    } catch (PDOException $e) {
        error_log("Error updating pedido ruta status: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error de base de datos al actualizar las observaciones: ' . $e->getMessage()]);
    }
    exit();
}

// --- Lógica para actualizar el estado general del parte de ruta (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_parte_ruta_status') {
    // Temporarily disable display_errors for this JSON endpoint
    // to prevent PHP notices/warnings from breaking the JSON output.
    ini_set('display_errors', 0);

    ob_clean(); // Limpiar cualquier salida anterior
    header('Content-Type: application/json');

    // NEW: Add a check for the PDO object to prevent fatal errors
    if (!isset($pdo) || !$pdo) {
        // Log the error for debugging on the server side
        error_log("Error crítico en partes_ruta.php: El objeto PDO no está disponible para la acción 'update_parte_ruta_status'.");
        // Send a clean JSON error response
        echo json_encode(['success' => false, 'message' => 'Error crítico: No se pudo establecer la conexión con la base de datos.']);
        exit();
    }

    $id_parte_ruta = $_POST['id_parte_ruta'] ?? null;
    $new_status = $_POST['new_status'] ?? null;

    if (empty($id_parte_ruta) || empty($new_status)) {
        echo json_encode(['success' => false, 'message' => 'ID de parte de ruta y nuevo estado son obligatorios.']);
        exit();
    }

    // Validar que el nuevo estado sea uno de los valores permitidos del ENUM
    $allowed_statuses = ['Pendiente', 'En Curso', 'Completado', 'Cancelado']; // Asume estos estados para partes_ruta
    if (!in_array($new_status, $allowed_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Estado de parte de ruta no válido.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE partes_ruta SET estado = ? WHERE id_parte_ruta = ?");
        $stmt->execute([$new_status, $id_parte_ruta]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Estado del parte de ruta actualizado exitosamente.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No se encontró el parte de ruta o no hubo cambios.']);
        }
    } catch (PDOException $e) {
        error_log("Error updating parte ruta status: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error de base de datos al actualizar el estado: ' . $e->getMessage()]);
    }
    exit();
}

// --- Lógica para eliminar un parte de ruta (AJAX) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $id_parte_ruta_to_delete = $_GET['id'] ?? null;

    if ($id_parte_ruta_to_delete) {
        try {
            $pdo->beginTransaction();

            // Eliminar las asociaciones de pedidos primero
            $stmt_delete_pedidos = $pdo->prepare("DELETE FROM partes_ruta_pedidos WHERE id_parte_ruta = ?");
            $stmt_delete_pedidos->execute([$id_parte_ruta_to_delete]);

            // Luego eliminar el parte de ruta
            $stmt_delete_parte = $pdo->prepare("DELETE FROM partes_ruta WHERE id_parte_ruta = ?");
            $stmt_delete_parte->execute([$id_parte_ruta_to_delete]);

            $pdo->commit();
            mostrarMensaje("Parte de ruta eliminado exitosamente.", "success");
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            mostrarMensaje("Error al eliminar el parte de ruta: " . $e->getMessage(), "danger");
        }
    } else {
        mostrarMensaje("ID de parte de ruta no proporcionado para eliminar.", "danger");
    }
    header("Location: partes_ruta.php"); // Redirigir a la vista de lista
    exit();
}


// --- Lógica para cargar datos de apoyo (Rutas, Partes de Ruta existentes) ---
$rutas_disponibles = [];
$partes_ruta_existentes = [];

// Initialize totals for the list view
$totalLitrosGeneral = 0;
$totalEurosGeneral = 0;
$totalCobradoGeneral = 0; // New total
$totalDeudaGeneral = 0;   // New total

try {
    // Cargar Rutas
    $stmt_rutas = $pdo->query("SELECT id_ruta, nombre_ruta FROM rutas ORDER BY nombre_ruta ASC");
    $rutas_disponibles = $stmt_rutas->fetchAll(PDO::FETCH_ASSOC);

    // Cargar Partes de Ruta existentes para la tabla
    $stmt_partes_ruta = $pdo->query("
        SELECT
            pr.id_parte_ruta,
            r.nombre_ruta,
            pr.fecha_parte,
            pr.observaciones,
            pr.estado,
            pr.fecha_creacion,
            COUNT(DISTINCT prp.id_pedido) AS total_pedidos,
            COALESCE(SUM(p.total_pedido_iva_incluido), 0) AS total_euros_ruta,
            COALESCE(SUM(dp.cantidad_solicitada), 0) AS total_litros_ruta,
            COALESCE(SUM(CASE WHEN fv.estado_pago = 'pagada' THEN fv.total_factura_iva_incluido ELSE 0 END), 0) AS total_cobrado,
            COALESCE(SUM(p.total_pedido_iva_incluido), 0) - COALESCE(SUM(CASE WHEN fv.estado_pago = 'pagada' THEN fv.total_factura_iva_incluido ELSE 0 END), 0) AS total_deuda
        FROM
            partes_ruta pr
        JOIN
            rutas r ON pr.id_ruta = r.id_ruta
        LEFT JOIN
            partes_ruta_pedidos prp ON pr.id_parte_ruta = prp.id_parte_ruta
        LEFT JOIN
            pedidos p ON prp.id_pedido = p.id_pedido
        LEFT JOIN
            detalle_pedidos dp ON p.id_pedido = dp.id_pedido -- Unir a detalle_pedidos para sumar cantidad_solicitada
        LEFT JOIN
            facturas_ventas fv ON p.id_factura_asociada = fv.id_factura
        GROUP BY
            pr.id_parte_ruta, r.nombre_ruta, pr.fecha_parte, pr.observaciones, pr.estado, pr.fecha_creacion
        ORDER BY
            pr.fecha_parte DESC, pr.fecha_creacion DESC
    ");
    $partes_ruta_existentes = $stmt_partes_ruta->fetchAll(PDO::FETCH_ASSOC);

    // Calculate general totals after fetching all parts
    // These totals will be updated by JavaScript based on filters
    foreach ($partes_ruta_existentes as $parte) {
        $totalLitrosGeneral += $parte['total_litros_ruta'];
        $totalEurosGeneral += $parte['total_euros_ruta'];
        $totalCobradoGeneral += $parte['total_cobrado']; // Accumulate new total
        $totalDeudaGeneral += $parte['total_deuda'];     // Accumulate new total
    }

} catch (PDOException $e) {
    mostrarMensaje("Error al cargar datos: " . $e->getMessage(), "danger");
}

// --- Lógica para cargar detalles de un parte de ruta específico (vista no modal) ---
$display_details_section = false;
$parte_ruta_details = null;
$parte_ruta_pedidos_details = [];
$parte_ruta_orden_carga = [];
$initial_selected_orders_for_js = []; // Nueva variable para inicializar el mapa de pedidos seleccionados en JS

if (isset($_GET['view_id']) && is_numeric($_GET['view_id'])) {
    $id_parte_ruta_view = $_GET['view_id'];
    $display_details_section = true; // Indica que se debe mostrar la sección de detalles/edición

    try {
        // Obtener detalles del parte de ruta principal
        $stmt_main = $pdo->prepare("
            SELECT pr.id_parte_ruta, r.nombre_ruta, pr.fecha_parte, pr.observaciones, pr.estado, pr.id_ruta,
                   pr.total_kilometros, pr.duracion_estimada_segundos
            FROM partes_ruta pr
            JOIN rutas r ON pr.id_ruta = r.id_ruta
            WHERE pr.id_parte_ruta = ?
        ");
        $stmt_main->execute([$id_parte_ruta_view]);
        $parte_ruta_details = $stmt_main->fetch(PDO::FETCH_ASSOC);

        if ($parte_ruta_details) {
            // Obtener detalles de los pedidos asociados desde partes_ruta_pedidos
            // MODIFICACIÓN CRÍTICA AQUÍ: Se añade DISTINCT para evitar duplicaciones
            // Y se incluyen todos los campos de dirección de envío y cliente.
            $stmt_details = $pdo->prepare("
                SELECT DISTINCT
                    prp.id_parte_ruta_pedido,
                    p.id_pedido,
                    p.fecha_pedido,
                    p.id_cliente,
                    c.nombre_cliente,
                    p.total_pedido_iva_incluido AS total_pedido,
                    p.estado_pedido,
                    p.id_factura_asociada,
                    fv.estado_pago,
                    prp.estado_pedido_ruta,
                    prp.observaciones_detalle,
                    c.latitud,
                    c.longitud,
                    c.direccion_google_maps,
                    p.tipo_entrega,
                    p.empresa_transporte,
                    p.numero_seguimiento,
                    p.observaciones AS pedido_observaciones,
                    p.id_direccion_envio,
                    de.latitud AS latitud_envio,
                    de.longitud AS longitud_envio,
                    de.direccion_google_maps AS direccion_google_maps_envio,
                    de.direccion AS direccion_envio,
                    de.ciudad AS ciudad_envio,
                    de.provincia AS provincia_envio,
                    de.codigo_postal AS codigo_postal_envio,
                    de.nombre_direccion AS nombre_direccion_envio,
                    c.direccion AS cliente_direccion_principal,
                    c.ciudad AS cliente_ciudad_principal,
                    c.provincia AS cliente_provincia_principal,
                    c.codigo_postal AS cliente_codigo_postal_principal,
                    -- Subconsultas para obtener totales de unidades/facturación/retiro por pedido
                    (SELECT SUM(dp_sub.cantidad_solicitada) FROM detalle_pedidos dp_sub WHERE dp_sub.id_pedido = p.id_pedido) AS total_unidades,
                    (SELECT SUM(dfv_sub.cantidad) FROM detalle_factura_ventas dfv_sub WHERE dfv_sub.id_factura = p.id_factura_asociada) AS total_cantidad_facturada,
                    (SELECT SUM(dfv_sub.unidades_retiradas) FROM detalle_factura_ventas dfv_sub WHERE dfv_sub.id_factura = p.id_factura_asociada) AS total_unidades_servidas
                FROM
                    partes_ruta_pedidos prp
                JOIN
                    pedidos p ON prp.id_pedido = p.id_pedido
                JOIN
                    clientes c ON p.id_cliente = c.id_cliente
                LEFT JOIN
                    direcciones_envio de ON p.id_direccion_envio = de.id_direccion_envio
                LEFT JOIN
                    facturas_ventas fv ON p.id_factura_asociada = fv.id_factura
                WHERE
                    prp.id_parte_ruta = ?
                ORDER BY
                    p.fecha_pedido ASC
            ");
            $stmt_details->execute([$id_parte_ruta_view]);
            $parte_ruta_pedidos_details = $stmt_details->fetchAll(PDO::FETCH_ASSOC);

            // Preparar initial_selected_orders_for_js para el formulario de edición
            foreach ($parte_ruta_pedidos_details as $pedido) {
                // Determine the effective address for display
                $effective_address_name = '';
                if (!empty($pedido['nombre_direccion_envio'])) {
                    $effective_address_name = htmlspecialchars($pedido['nombre_direccion_envio']) . ' (' . htmlspecialchars($pedido['direccion_envio']) . ', ' . htmlspecialchars($pedido['ciudad_envio']) . ')';
                } elseif (!empty($pedido['cliente_direccion_principal'])) {
                    $effective_address_name = 'Principal: ' . htmlspecialchars($pedido['cliente_direccion_principal']) . ' (' . htmlspecialchars($pedido['cliente_ciudad_principal']) . ')';
                } else {
                    $effective_address_name = 'N/A';
                }

                $initial_selected_orders_for_js[$pedido['id_pedido']] = [
                    'id_pedido' => $pedido['id_pedido'],
                    'fecha_pedido' => $pedido['fecha_pedido'],
                    'id_cliente' => $pedido['id_cliente'],
                    'nombre_cliente' => $pedido['nombre_cliente'],
                    'total_unidades' => $pedido['total_unidades'] ?? 0,
                    'total_importe' => $pedido['total_pedido'] ?? 0, // Usar total_pedido
                    'id_factura_asociada' => $pedido['id_factura_asociada'],
                    'estado_pago' => $pedido['estado_pago'],
                    'total_cantidad_facturada' => $pedido['total_cantidad_facturada'] ?? 0,
                    'total_unidades_servidas' => $pedido['total_unidades_servidas'] ?? 0,
                    'tipo_entrega' => $pedido['tipo_entrega'],
                    'empresa_transporte' => $pedido['empresa_transporte'],
                    'numero_seguimiento' => $pedido['numero_seguimiento'],
                    'observaciones' => $pedido['pedido_observaciones'],
                    'id_direccion_envio' => $pedido['id_direccion_envio'],
                    'latitud_envio' => $pedido['latitud_envio'],
                    'longitud_envio' => $pedido['longitud_envio'],
                    'direccion_google_maps_envio' => $pedido['direccion_google_maps_envio'],
                    'direccion_envio' => $pedido['direccion_envio'],
                    'ciudad_envio' => $pedido['ciudad_envio'],
                    'provincia_envio' => $pedido['provincia_envio'],
                    'codigo_postal_envio' => $pedido['codigo_postal_envio'],
                    'nombre_direccion_envio' => $pedido['nombre_direccion_envio'],
                    'cliente_direccion_principal' => $pedido['cliente_direccion_principal'],
                    'cliente_ciudad_principal' => $pedido['cliente_ciudad_principal'],
                    'cliente_provincia_principal' => $pedido['cliente_provincia_principal'],
                    'cliente_codigo_postal_principal' => $pedido['cliente_codigo_postal_principal'],
                    'effective_address_name' => $effective_address_name // Add the effective address for display
                ];
            }

            // Obtener la "Orden de Carga" (total de unidades por producto para este parte de ruta)
            $stmt_load_order = $pdo->prepare("
                SELECT
                    prod.nombre_producto,
                    SUM(dp.cantidad_solicitada) AS total_cantidad_cargada
                FROM
                    partes_ruta_pedidos prp
                JOIN
                    detalle_pedidos dp ON prp.id_pedido = dp.id_pedido
                JOIN
                    productos prod ON dp.id_producto = prod.id_producto
                WHERE
                    prp.id_parte_ruta = ?
                GROUP BY
                    prod.id_producto, prod.nombre_producto
                ORDER BY
                    prod.nombre_producto ASC
            ");
            $stmt_load_order->execute([$id_parte_ruta_view]);
            $parte_ruta_orden_carga = $stmt_load_order->fetchAll(PDO::FETCH_ASSOC);

        } else {
            mostrarMensaje("Parte de ruta con ID " . htmlspecialchars($id_parte_ruta_view) . " no encontrado.", "warning");
            $display_details_section = false; // Volver a la vista de lista si no se encuentra
        }
    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos al cargar detalles: " . $e->getMessage(), "danger");
        $display_details_section = false; // Volver a la vista de lista en caso de error
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Partes de Ruta</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Estilos generales del sidebar (copia de sidebar.php) */
        :root {
            --sidebar-bg: #2c3e50; /* Azul oscuro */
            --sidebar-link: #ecf0f1; /* Gris claro */
            --sidebar-hover: #34495e; /* Azul más oscuro al pasar el ratón */
            --primary-color: #6f42c1; /* Púrpura para partes de paquetería */
            --primary-dark: #5935a1; /* Púrpura más oscuro */
            --secondary-color: #007bff; /* Azul estándar de Bootstrap */
            --secondary-dark: #0056b3; /* Azul más oscuro */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
            color: #333;
            margin: 0;
            padding: 0;
            overflow-x: hidden; /* Evitar el scroll horizontal */
        }
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-link);
            padding: 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
            position: fixed; /* Hacer el sidebar fijo */
            top: 0;
            left: 0;
            z-index: 1000; /* Asegurar que el sidebar esté encima */
            transition: all 0.3s; /* Transición suave para colapsar */
        }
        .sidebar.active { /* Para colapsar el sidebar */
            margin-left: -250px;
        }
        #content {
            width: 100%;
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s;
            margin-left: 250px; /* Ajustar esto para que coincida con el ancho del sidebar */
        }
        .sidebar.active + #content { /* Cuando el sidebar está colapsado */
            margin-left: 0;
        }
        .navbar {
            background-color: #fff;
            /* border-bottom: 1px solid #ddd; REMOVED AS PER USER REQUEST */
            padding: 10px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .form-container, .table-container, .card { /* Unified styling for containers and cards */
            background-color: #fff;
            padding: 30px;
            border-radius: 15px; /* More rounded corners */
            box-shadow: 0 4px 8px rgba(0,0,0,0.05); /* Stronger shadow */
            margin-bottom: 30px;
        }
        .card-header { /* Specific for card headers */
            background-color: var(--primary-color); /* Purple for headers */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            font-weight: bold;
            padding: 15px 30px; /* Match padding of card-body */
            margin: -30px -30px 20px -30px; /* Adjust to cover padding of parent */
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px; /* Consistent rounded buttons */
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
            border-radius: 8px;
        }
        .btn-success:hover {
            background-color: #218838;
            border-color: #218838;
        }
        .btn-info {
            background-color: #17a2b8;
            border-color: #17a2b8;
            border-radius: 8px;
        }
        .btn-info:hover {
            background-color: #138496;
            border-color: #138496;
        }
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            border-radius: 8px;
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #c82333;
        }
        .btn-secondary { /* Ensure secondary button is styled */
            border-radius: 8px;
        }
        .btn-warning { /* Ensure warning button is styled */
            border-radius: 8px;
        }
        .table thead th {
            background-color: #e9ecef;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        .table tbody tr:hover {
            background-color: #f2f2f2;
        }
        .table-responsive {
            margin-top: 20px;
        }
        .modal-content { /* Consistent modal styling */
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .modal-header {
            background-color: var(--primary-color); /* Purple for modal headers */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .modal-header .btn-close {
            filter: invert(1) brightness(2); /* White close button on dark background */
        }
        .form-control, .form-select {
            border-radius: 8px; /* Rounded form elements */
        }

        /* Insignias personalizadas para el estado del pedido en ruta */
        .badge.bg-pending-route {
            background-color: #ffc107 !important; /* Amarillo de advertencia */
            color: #212529 !important; /* Texto oscuro */
        }
        .badge.bg-en-ruta {
            background-color: #17a2b8 !important; /* Azul de información */
        }
        .badge.bg-completado-entregado {
            background-color: #28a745 !important; /* Verde de éxito */
        }
        .badge.bg-cancelado-route {
            background-color: #dc3545 !important; /* Rojo de peligro */
        }
        /* Insignias personalizadas para el estado del servicio (de la lógica de pedidos.php) */
        .badge.bg-served-complete {
            background-color: #28a745 !important; /* Verde */
        }
        .badge.bg-served-partial {
            background-color: #fd7e14 !important; /* Naranja */
        }
        .badge.bg-pending-invoice {
            background-color: #6c757d !important; /* Gris */
        }
        .badge.bg-pending-no-invoice {
            background-color: #ffc107 !important; /* Amarillo */
            color: #212529 !important; /* Texto oscuro para contraste */
        }

        /* Insignias personalizadas para el estado general del parte de ruta */
        .badge.bg-parte-pendiente {
            background-color: #ffc107 !important; /* Amarillo de advertencia, similar al pedido pendiente */
            color: #212529 !important;
        }
        .badge.bg-parte-en-curso {
            background-color: #17a2b8 !important; /* Azul de información, similar al pedido en ruta */
        }
        .badge.bg-parte-completado {
            background-color: #28a745 !important; /* Verde de éxito, similar al pedido completado */
        }
        .badge.bg-parte-cancelado {
            background-color: #dc3545 !important; /* Rojo de peligro, similar al pedido cancelado */
        }
        /* Insignias personalizadas para el estado de pago */
        .badge.bg-pago-pendiente {
            background-color: #ffc107 !important; /* Amarillo para pago pendiente */
            color: #212529 !important;
        }
        .badge.bg-pago-parcial {
            background-color: #fd7e14 !important; /* Naranja para pago parcial */
        }
        .badge.bg-pago-pagado {
            background-color: #28a745 !important; /* Verde para pagado */
        }
        .badge.bg-pago-anulado {
            background-color: #dc3545 !important; /* Rojo para anulado */
        }


        .message-container {
            margin-top: 20px;
        }
        .list-group-item-action:hover {
            background-color: #e9ecef;
        }
        /* Style for order selection in modal - copied from partes_paqueteria.php */
        #pendingOrdersList {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px;
        }
        .order-item {
            padding: 8px 5px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        .order-item:hover {
            background-color: #f8f9fa;
        }
        .order-item.selected {
            background-color: #e2f0ff; /* Light blue for selected items */
            font-weight: bold;
        }
        .order-item:last-child {
            border-bottom: none;
        }
        /* Adjusted selected-order-item for table display in main form */
        .selected-order-item {
            background-color: #e2f0ff; /* Azul claro para elementos seleccionados */
            border-left: 5px solid var(--primary-color); /* Use primary color */
            padding: 8px 15px;
            margin-bottom: 5px;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .selected-order-item .remove-btn {
            background: none;
            border: none;
            color: #dc3545;
            font-size: 1.2rem;
            cursor: pointer;
        }
        .selected-order-item .remove-btn:hover {
            color: #c82333;
        }

        /* Estilos para el mapa */
        #map {
            height: 400px;
            width: 100%;
            border-radius: 8px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .info-window-content {
            font-family: 'Inter', sans-serif;
            padding: 5px;
        }
        .info-window-content h6 {
            margin-bottom: 5px;
            color: #2c3e50;
        }
        .info-window-content p {
            margin-bottom: 3px;
            font-size: 0.9em;
        }
        .info-window-content a {
            color: #007bff;
            text-decoration: none;
        }
        .info-window-content a:hover {
            text-decoration: underline;
        }
        .form-check-label {
            cursor: pointer;
        }
        .form-check-input {
            margin-right: 5px;
        }
        .route-details-section {
            margin-top: 20px;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        .route-details-section h6 {
            color: var(--primary-color); /* Use primary color for section headers */
            margin-bottom: 10px;
        }
        .route-details-section ol {
            padding-left: 20px;
        }
        .route-details-section li {
            margin-bottom: 5px;
            font-size: 0.95em;
        }
        .route-details-section li strong {
            color: #2c3e50;
        }
        /* Estilos para el input de URL */
        #googleMapsUrlInput {
            width: 100%;
            padding: 8px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 0.9em;
            background-color: #e9ecef;
            cursor: pointer;
        }
        .share-buttons-container {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap; /* Para que los botones se ajusten en pantallas pequeñas */
        }

        /* Estilos para las nuevas secciones de detalles de pedidos */
        .order-details-container {
            max-height: 400px; /* Altura máxima para scroll */
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        .order-item-card {
            border: 1px solid #dcdcdc;
            border-radius: 10px;
            margin-bottom: 15px;
            padding: 15px;
            background-color: #fdfdfd;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }
        .order-item-card h5 {
            color: var(--primary-color); /* Use primary color for order card headers */
            margin-bottom: 10px;
        }
        .order-item-card .table {
            margin-top: 10px;
            font-size: 0.9em;
        }
        .order-item-card .table th, .order-item-card .table td {
            padding: 8px;
        }
        .order-item-card .table thead th {
            background-color: #f2f2f2;
        }
        .load-summary-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        .load-summary-table th, .load-summary-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .load-summary-table th {
            background-color: #f2f2f2;
        }

        /* Estilos de arrastrar y soltar */
        #delivery_order_list li {
            padding: 8px 12px;
            margin-bottom: 5px;
            background-color: #e9f5ff;
            border: 1px solid #cce5ff;
            border-radius: 5px;
            cursor: grab;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #delivery_order_list li.dragging {
            opacity: 0.5;
            border: 2px dashed var(--primary-color);
        }
        #delivery_order_list li.drag-over {
            background-color: #d0e9ff;
            border: 1px dashed var(--primary-color);
        }
        .recalculate-btn-container {
            text-align: center;
            margin-top: 15px;
        }

        /* NEW: Styles for scrollable table container */
        .scrollable-table-container {
            max-height: 750px; /* Approximate height for 15 rows */
            overflow-y: auto; /* Vertical scrollbar */
            overflow-x: auto; /* Horizontal scrollbar */
            border: 1px solid #e9ecef; /* Optional: add a border for visual separation */
            border-radius: 8px; /* Optional: rounded corners */
            position: relative; /* Needed for sticky footer */
        }
        /* Sticky table header */
        .scrollable-table-container table thead th {
            position: sticky;
            top: 0;
            background-color: #e9ecef; /* Match the existing header background */
            z-index: 10; /* Ensure it stays above scrolling content */
        }
        /* Sticky table footer */
        .scrollable-table-container table tfoot th {
            position: sticky;
            bottom: 0;
            background-color: #cce5ff; /* Light blue for footer, adjust as needed */
            z-index: 10; /* Ensure it stays above scrolling content */
            box-shadow: 0 -2px 5px rgba(0,0,0,0.1); /* Optional: shadow for visual separation */
        }
        /* Ensure table takes full width for consistent sticky headers/footers */
        .scrollable-table-container table {
            width: 100%;
            table-layout: fixed; /* Helps maintain column widths with sticky headers */
            margin-bottom: 0; /* Remove default table margin to prevent double scrollbars */
        }

        /* Column Width Adjustments */
        #partesRutaListTable th:nth-child(1) { width: 8%; } /* ID Parte */
        #partesRutaListTable th:nth-child(2) { width: 10%; } /* Fecha */
        #partesRutaListTable th:nth-child(3) { width: 12%; } /* Ruta */
        #partesRutaListTable th:nth-child(4) { width: 10%; } /* Estado */
        #partesRutaListTable th:nth-child(5) { width: 8%; } /* Pedidos */
        #partesRutaListTable th:nth-child(6) { width: 9%; } /* Total Litros */
        #partesRutaListTable th:nth-child(7) { width: 9%; } /* Total Euros */
        #partesRutaListTable th:nth-child(8) { width: 9%; } /* Total Cobrado */
        #partesRutaListTable th:nth-child(9) { width: 9%; } /* Total Deuda */
        #partesRutaListTable th:nth-child(10) { width: 16%; } /* Acciones */
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar (Unificado) -->
        <?php include 'sidebar.php'; ?>
        <!-- Contenido de la Página -->
        <div id="content">
            <nav class="navbar navbar-expand-lg navbar-light bg-light">
                <div class="container-fluid">
                    <!-- Botón de menú, texto de bienvenida y cerrar sesión eliminados según la solicitud -->
                    <!-- Contenido de la barra de navegación ajustado para ser más simple o vacío si no se necesita nada más aquí -->
                </div>
            </nav>

            <h2 class="mb-4">Gestión de Partes de Ruta</h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($display_details_section && $parte_ruta_details): ?>
                <!-- Sección para mostrar los detalles del Parte de Ruta (Visible solo con view_id) -->
                <div class="form-container" id="parteRutaDisplaySection">
                    <div class="card-header">
                        <h3 class="mb-0">Detalles del Parte de Ruta #<?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta']); ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-4"><strong>ID Parte:</strong> <?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta']); ?></div>
                            <div class="col-md-4"><strong>Ruta:</strong> <?php echo htmlspecialchars($parte_ruta_details['nombre_ruta']); ?></div>
                            <div class="col-md-4"><strong>Fecha:</strong> <?php echo htmlspecialchars(date('d/m/Y', strtotime($parte_ruta_details['fecha_parte']))); ?></div>
                            <div class="col-md-4"><strong>Estado:</strong>
                                <?php
                                    $parteRutaBadgeClass = '';
                                    switch ($parte_ruta_details['estado']) {
                                        case 'Pendiente': $parteRutaBadgeClass = 'bg-parte-pendiente'; break;
                                        case 'En Curso': $parteRutaBadgeClass = 'bg-parte-en-curso'; break;
                                        case 'Completado': $parteRutaBadgeClass = 'bg-parte-completado'; break;
                                        case 'Cancelado': $parteRutaBadgeClass = 'bg-parte-cancelado'; break;
                                        default: $parteRutaBadgeClass = 'bg-secondary'; break;
                                    }
                                ?>
                                <span class="badge <?php echo $parteRutaBadgeClass; ?>" id="parteRutaEstadoBadge"><?php echo htmlspecialchars($parte_ruta_details['estado']); ?></span>
                            </div>
                            <div class="col-md-4"><strong>Kilómetros:</strong> <?php echo htmlspecialchars(number_format($parte_ruta_details['total_kilometros'] ?? 0, 2, ',', '.')); ?> km</div>
                            <div class="col-md-4"><strong>Duración Est.:</strong> <?php echo htmlspecialchars(formatDurationPHP($parte_ruta_details['duracion_estimada_segundos'] ?? 0)); ?></div>
                            <div class="col-md-8"><strong>Observaciones:</strong> <?php echo htmlspecialchars($parte_ruta_details['observaciones'] ?: 'N/A'); ?></div>
                        </div>

                        <div class="mb-4 text-center">
                            <button type="button" class="btn btn-warning rounded-pill me-2" id="editParteRutaBtn">
                                <i class="bi bi-pencil"></i> Editar Parte de Ruta
                            </button>
                            <button type="button" class="btn btn-primary rounded-pill me-2" onclick="window.updateParteRutaStatus(<?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta']); ?>, 'En Curso')">
                                <i class="bi bi-truck"></i> Marcar como En Curso
                            </button>
                            <button type="button" class="btn btn-success rounded-pill me-2" onclick="window.updateParteRutaStatus(<?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta']); ?>, 'Completado')">
                                <i class="bi bi-check-circle"></i> Marcar como Completado
                            </button>
                            <button type="button" class="btn btn-info rounded-pill view-map-btn"
                                    data-id-parte="<?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta']); ?>"
                                    data-ruta-nombre="<?php echo htmlspecialchars($parte_ruta_details['nombre_ruta']); ?>"
                                    title="Ver en Mapa">
                                <i class="bi bi-map"></i> Ver en Mapa
                            </button>
                        </div>

                        <h4 class="mb-3 mt-5">Pedidos Asociados (<?php echo count($parte_ruta_pedidos_details); ?>)</h4>
                        <div class="table-responsive mb-4">
                            <table class="table table-bordered table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID Pedido</th>
                                        <th>Fecha Pedido</th>
                                        <th>Cliente</th>
                                        <th>Dirección de Envío</th> <!-- NEW COLUMN -->
                                        <th class="text-end">Unidades Totales</th>
                                        <th class="text-end">Importe Total</th>
                                        <th>Observaciones Detalle</th>
                                        <th>Estado Factura</th>
                                        <th>Estado Cobro</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($parte_ruta_pedidos_details)): ?>
                                        <tr><td colspan="10" class="text-center">No hay pedidos asociados a este parte de ruta.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($parte_ruta_pedidos_details as $p):
                                            $current_estado_pedido_ruta = $p['estado_pedido_ruta'] ?? 'Desconocido';
                                            $badgeClassRoute = '';
                                            switch ($current_estado_pedido_ruta) {
                                                case 'Pendiente': $badgeClassRoute = 'bg-pending-route'; break;
                                                case 'En Ruta': $badgeClassRoute = 'bg-en-ruta'; break;
                                                case 'Completado':
                                                case 'Entregado': $badgeClassRoute = 'bg-completado-entregado'; break;
                                                case 'Cancelado': $badgeClassRoute = 'bg-cancelado-route'; break;
                                                default: $badgeClassRoute = 'bg-secondary'; break;
                                            }
                                            $isFacturado = $p['id_factura_asociada'] !== null;

                                            $estadoCobroTexto = 'N/A';
                                            $badgeClassCobro = 'bg-secondary';
                                            if ($isFacturado) {
                                                $estadoCobroTexto = htmlspecialchars($p['estado_pago'] ?: 'Pendiente');
                                                switch ($p['estado_pago']) {
                                                    case 'pagada': $badgeClassCobro = 'bg-pago-pagado'; break;
                                                    case 'parcialmente_pagada': $badgeClassCobro = 'bg-pago-parcial'; break;
                                                    case 'anulado': $badgeClassCobro = 'bg-pago-anulado'; break;
                                                    case 'pendiente':
                                                    default: $badgeClassCobro = 'bg-pago-pendiente'; break;
                                                }
                                            }

                                            // Determine the effective address for display
                                            $display_address = 'N/A';
                                            if (!empty($p['nombre_direccion_envio'])) {
                                                $display_address = htmlspecialchars($p['nombre_direccion_envio']) . ' (' . htmlspecialchars($p['direccion_envio']) . ', ' . htmlspecialchars($p['ciudad_envio']) . ')';
                                            } elseif (!empty($p['cliente_direccion_principal'])) {
                                                $display_address = 'Principal: ' . htmlspecialchars($p['cliente_direccion_principal']) . ' (' . htmlspecialchars($p['cliente_ciudad_principal']) . ')';
                                            }
                                        ?>
                                            <tr id="pedido-row-<?php echo htmlspecialchars($p['id_parte_ruta_pedido']); ?>">
                                                <td><?php echo htmlspecialchars($p['id_pedido']); ?></td>
                                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($p['fecha_pedido']))); ?></td>
                                                <td><?php echo htmlspecialchars($p['nombre_cliente']); ?></td>
                                                <td><?php echo $display_address; ?></td> <!-- NEW CELL FOR ADDRESS -->
                                                <td class="text-end"><?php echo number_format($p['total_unidades'] ?? 0, 2, ',', '.'); ?></td>
                                                <td class="text-end"><?php echo number_format($p['total_pedido'] ?? 0, 2, ',', '.'); ?>€</td>
                                                <td>
                                                    <textarea class="form-control form-control-sm rounded-pill pedido-obs-textarea"
                                                              data-id-parte-ruta-pedido="<?php echo htmlspecialchars($p['id_parte_ruta_pedido']); ?>"
                                                              rows="2"><?php echo htmlspecialchars($p['observaciones_detalle'] ?: ''); ?></textarea>
                                                </td>
                                                <td>
                                                    <?php echo $isFacturado ? '<span class="badge bg-success">Facturado</span>' : '<span class="badge bg-secondary">Sin Factura</span>'; ?>
                                                    <?php if ($isFacturado): ?>
                                                        <a href="facturas_ventas.php?view=details&id=<?php echo htmlspecialchars($p['id_factura_asociada']); ?>&from_parte_ruta_id=<?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta'] ?? 'null'); ?>" class="btn btn-sm btn-outline-primary ms-2" title="Ver Factura" target="_blank"><i class="bi bi-receipt-cutoff"></i></a>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="badge <?php echo $badgeClassCobro; ?>"><?php echo $estadoCobroTexto; ?></span></td>
                                                <td class="text-center">
                                                    <a href="pedidos.php?view=details&id=<?php echo htmlspecialchars($p['id_pedido']); ?>&from_parte_ruta_id=<?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta']); ?>" class="btn btn-sm btn-outline-primary me-1" title="Ver detalles del pedido" target="_blank">
                                                        <i class="bi bi-eye"></i> Ver Pedido
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-success" title="Convertir a Factura"
                                                        <?php echo $isFacturado ? 'disabled' : ''; ?>
                                                        onclick="window.createInvoiceFromOrder(<?php echo htmlspecialchars($p['id_cliente']); ?>, '<?php echo htmlspecialchars(addslashes($p['nombre_cliente'])); ?>', <?php echo htmlspecialchars($p['id_pedido']); ?>, <?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta'] ?? 'null'); ?>)">
                                                        <i class="bi bi-receipt"></i> Facturar
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <h4 class="mb-3">Orden de Carga (Total por Producto)</h4>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th class="text-end">Cantidad Total a Cargar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($parte_ruta_orden_carga)): ?>
                                        <tr><td colspan="2" class="text-center">No hay productos en la orden de carga para este parte.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($parte_ruta_orden_carga as $item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($item['nombre_producto']); ?></td>
                                                <td class="text-end"><?php echo number_format($item['total_cantidad_cargada'], 2, ',', '.'); ?> uds.</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="text-center mt-4">
                            <a href="partes_ruta.php" class="btn btn-secondary rounded-pill"><i class="bi bi-arrow-left-circle"></i> Volver a la Lista de Partes de Ruta</a>
                        </div>
                    </div>
                </div>

                <!-- Formulario para Editar Parte de Ruta (Inicialmente oculto, se muestra con botón) -->
                <div class="form-container mt-5" id="parteRutaEditFormContainer">
                    <div class="card-header">
                        <h3 class="mb-0">Editar Parte de Ruta #<?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta']); ?></h3>
                    </div>
                    <div class="card-body">
                        <form id="parteRutaEditModeForm">
                            <input type="hidden" id="edit_id_parte_ruta" name="id_parte_ruta" value="<?php echo htmlspecialchars($parte_ruta_details['id_parte_ruta'] ?? ''); ?>">
                            <input type="hidden" id="edit_total_kilometros" name="total_kilometros" value="<?php echo htmlspecialchars($parte_ruta_details['total_kilometros'] ?? 0); ?>">
                            <input type="hidden" id="edit_duracion_estimada_segundos" name="duracion_estimada_segundos" value="<?php echo htmlspecialchars($parte_ruta_details['duracion_estimada_segundos'] ?? 0); ?>">

                            <div class="mb-3">
                                <label for="edit_id_ruta" class="form-label">Ruta <span class="text-danger">*</span></label>
                                <select class="form-select rounded-pill" id="edit_id_ruta" name="id_ruta" required>
                                    <option value="">Seleccione una ruta</option>
                                    <?php foreach ($rutas_disponibles as $ruta): ?>
                                        <option value="<?php echo htmlspecialchars($ruta['id_ruta']); ?>"
                                            <?php echo (isset($parte_ruta_details['id_ruta']) && $parte_ruta_details['id_ruta'] == $ruta['id_ruta']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($ruta['nombre_ruta']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="edit_fecha_parte" class="form-label">Fecha del Parte (Fecha programada para el reparto) <span class="text-danger">*</span></label>
                                <input type="date" class="form-control rounded-pill" id="edit_fecha_parte" name="fecha_parte" required value="<?php echo htmlspecialchars($parte_ruta_details['fecha_parte'] ?? date('Y-m-d')); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="edit_observaciones" class="form-label">Observaciones</label>
                                <textarea class="form-control rounded-pill" id="edit_observaciones" name="observaciones" rows="3"></textarea>
                            </div>

                            <h4 class="mt-4">Pedidos Asignados</h4>
                            <div class="mb-3">
                                <button type="button" class="btn btn-success rounded-pill" data-bs-toggle="modal" data-bs-target="#addOrdersModal" id="addOrdersToEditFormBtn">
                                    <i class="bi bi-plus-circle"></i> Añadir Pedidos
                                </button>
                            </div>

                            <div id="editSelectedOrdersContainer" class="mb-4 border p-3 rounded">
                                <p id="editNoOrdersMessage" class="text-muted text-center">No hay pedidos seleccionados para este parte de ruta.</p>
                            </div>
                            <input type="hidden" id="edit_pedidos_seleccionados" name="pedidos_seleccionados" value="[]">

                            <button type="submit" class="btn btn-primary rounded-pill">Guardar Cambios</button>
                            <button type="button" class="btn btn-secondary rounded-pill" id="btnCancelarEditMode">Cancelar Edición</button>
                        </form>
                    </div>
                </div>

            <?php else: // Bloque para la Lista de Partes de Ruta (Visible por defecto) ?>

                <!-- Tabla para Consultar Partes de Ruta Existentes (visible por defecto) -->
                <div class="table-container mb-5">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3 class="mb-0">Lista de Partes de Ruta</h3>
                            <div class="d-flex align-items-center">
                                <button type="button" class="btn btn-warning rounded-pill me-2" id="filterPendingBtn">
                                    <i class="bi bi-hourglass-split"></i> Ver Pendientes
                                </button>
                                <button type="button" class="btn btn-success rounded-pill me-2" id="filterCompletedBtn">
                                    <i class="bi bi-check-circle"></i> Ver Completados
                                </button>
                                <button type="button" class="btn btn-secondary rounded-pill me-5" id="showAllBtn">
                                    <i class="bi bi-list-ul"></i> Ver Todos
                                </button>
                                <a href="crear_parte_ruta.php" class="btn btn-success rounded-pill ms-5" id="addParteRutaBtn">
                                    <i class="bi bi-plus-circle me-2"></i>Añadir Nuevo Parte de Ruta
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive scrollable-table-container"> <!-- Added scrollable-table-container class -->
                            <table class="table table-hover table-striped" id="partesRutaListTable"> <!-- Added ID for JS -->
                                <thead>
                                    <tr>
                                        <th>ID Parte</th>
                                        <th>Fecha</th>
                                        <th>Ruta</th>
                                        <th>Estado</th>
                                        <th>Pedidos</th>
                                        <th class="text-end">Total Litros</th>
                                        <th class="text-end">Total Euros</th>
                                        <th class="text-end">Total Cobrado</th>
                                        <th class="text-end">Total Deuda</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($partes_ruta_existentes)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center">No hay partes de ruta registrados.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($partes_ruta_existentes as $parte): ?>
                                            <tr data-estado="<?php echo htmlspecialchars($parte['estado']); ?>"
                                                data-litros="<?php echo htmlspecialchars($parte['total_litros_ruta'] ?? 0); ?>"
                                                data-euros="<?php echo htmlspecialchars($parte['total_euros_ruta'] ?? 0); ?>"
                                                data-cobrado="<?php echo htmlspecialchars($parte['total_cobrado'] ?? 0); ?>"
                                                data-deuda="<?php echo htmlspecialchars($parte['total_deuda'] ?? 0); ?>">
                                                <td><?php echo htmlspecialchars($parte['id_parte_ruta']); ?></td>
                                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($parte['fecha_parte']))); ?></td>
                                                <td><?php echo htmlspecialchars($parte['nombre_ruta']); ?></td>
                                                <td>
                                                    <?php
                                                        $parteRutaBadgeClass = '';
                                                        switch ($parte['estado']) {
                                                            case 'Pendiente': $parteRutaBadgeClass = 'bg-parte-pendiente'; break;
                                                            case 'En Curso': $parteRutaBadgeClass = 'bg-parte-en-curso'; break;
                                                            case 'Completado': $parteRutaBadgeClass = 'bg-parte-completado'; break;
                                                            case 'Cancelado': $parteRutaBadgeClass = 'bg-parte-cancelado'; break;
                                                            default: $parteRutaBadgeClass = 'bg-secondary'; break;
                                                        }
                                                    ?>
                                                    <span class="badge <?php echo $parteRutaBadgeClass; ?>"><?php echo htmlspecialchars($parte['estado']); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($parte['total_pedidos']); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars(number_format($parte['total_litros_ruta'] ?? 0, 2, ',', '.')); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars(number_format($parte['total_euros_ruta'] ?? 0, 2, ',', '.')); ?>€</td>
                                                <td class="text-end"><?php echo htmlspecialchars(number_format($parte['total_cobrado'] ?? 0, 2, ',', '.')); ?>€</td>
                                                <td class="text-end">
                                                    <span class="<?php echo ($parte['total_deuda'] > 0) ? 'text-danger' : ''; ?>">
                                                        <?php echo htmlspecialchars(number_format($parte['total_deuda'] ?? 0, 2, ',', '.')); ?>€
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="partes_ruta.php?view_id=<?php echo htmlspecialchars($parte['id_parte_ruta']); ?>" class="btn btn-sm btn-info rounded-pill" title="Ver Detalles">
                                                        <i class="bi bi-eye"></i> Ver
                                                    </a
                                                    ><button type="button" class="btn btn-sm btn-primary rounded-pill view-map-btn"
                                                            data-id-parte="<?php echo htmlspecialchars($parte['id_parte_ruta']); ?>"
                                                            data-ruta-nombre="<?php echo htmlspecialchars($parte['nombre_ruta']); ?>"
                                                            title="Ver en Mapa">
                                                        <i class="bi bi-map"></i> Mapa
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger rounded-pill delete-parte-btn"
                                                            data-id="<?php echo htmlspecialchars($parte['id_parte_ruta']); ?>"
                                                            title="Eliminar Parte de Ruta" style="display: none;">
                                                        <i class="bi bi-trash"></i> Eliminar
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-info">
                                        <th colspan="5" class="text-end">Total General:</th>
                                        <th class="text-end" id="footerTotalLitros">
                                            <?php echo htmlspecialchars(number_format($totalLitrosGeneral ?? 0, 2, ',', '.')); ?>
                                        </th>
                                        <th class="text-end" id="footerTotalEuros">
                                            <?php echo htmlspecialchars(number_format($totalEurosGeneral ?? 0, 2, ',', '.')); ?>€
                                        </th>
                                        <th class="text-end" id="footerTotalCobrado">
                                            <?php echo htmlspecialchars(number_format($totalCobradoGeneral ?? 0, 2, ',', '.')); ?>€
                                        </th>
                                        <th class="text-end" id="footerTotalDeuda">
                                            <span class="<?php echo ($totalDeudaGeneral > 0) ? 'text-danger' : ''; ?>">
                                                <?php echo htmlspecialchars(number_format($totalDeudaGeneral ?? 0, 2, ',', '.')); ?>€
                                            </span>
                                        </th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Modal para Añadir Pedidos (existente) -->
    <div class="modal fade" id="addOrdersModal" tabindex="-1" aria-labelledby="addOrdersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content rounded-lg shadow-lg">
                <div class="modal-header">
                    <h5 class="modal-title" id="addOrdersModalLabel">Añadir Pedidos Pendientes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="filterFechaEntrega" class="form-label">Filtrar por Fecha de Pedido:</label>
                        <input type="date" class="form-control rounded-pill" id="filterFechaEntrega">
                    </div>
                    <div class="mb-3">
                        <label for="searchOrder" class="form-label">Buscar por Cliente o ID de Pedido:</label>
                        <input type="text" class="form-control rounded-pill" id="searchOrder" placeholder="Escriba para buscar pedidos...">
                    </div>
                    <div id="pendingOrdersList" class="list-group">
                        <!-- Pedidos pendientes se cargarán aquí -->
                        <p class="text-center text-muted mt-3">Cargando pedidos...</p>
                    </div>
                    <div id="loadingOrders" class="text-center mt-3 d-none">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary rounded-pill" id="addSelectedOrdersBtn">Añadir Seleccionados</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para el Mapa y Detalles de Pedidos -->
    <div class="modal fade" id="mapModal" tabindex="-1" aria-labelledby="mapModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="mapModalLabel">Ruta en Mapa: <span id="mapRutaNombre"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="map_info" class="alert alert-info text-center" role="alert" style="display: none;"></div>
                    <div id="map"></div>
                    <div id="route_summary" class="mt-3">
                        <p><strong>Distancia Total:</strong> <span id="total_distance">N/A</span></p>
                        <p><strong>Tiempo Estimado:</strong> <span id="total_duration">N/A</span></p>
                    </div>
                    <div id="shareButtonsContainer" class="share-buttons-container" style="display: none;">
                        <input type="text" id="googleMapsUrlInput" class="form-control" readonly onclick="this.select();" title="Haz clic para copiar el enlace">
                        <button type="button" class="btn btn-success" id="copyUrlBtn">
                            <i class="bi bi-clipboard"></i> Copiar Enlace
                        </button>
                        <a href="#" id="whatsappShareBtn" class="btn btn-success" target="_blank" style="background-color: #25D366; border-color: #25D366;">
                            <i class="bi bi-whatsapp"></i> Compartir por WhatsApp
                        </a>
                        <!-- NUEVO: Botón Imprimir PDF -->
                        <button type="button" class="btn btn-danger" id="printPdfBtn">
                            <i class="bi bi-file-earmark-pdf"></i> Imprimir PDF
                        </button>
                    </div>
                    <!-- Nueva sección para el orden de entrega optimizado -->
                    <div id="optimized_delivery_order" class="route-details-section">
                        <h6>Orden de Reparto Optimizado (Arrastra para reordenar):</h6>
                        <ol id="delivery_order_list">
                            <!-- Los elementos de pedido se cargarán dinámicamente aquí y serán arrastrables -->
                        </ol>
                        <div class="recalculate-btn-container">
                            <button type="button" class="btn btn-primary rounded-pill" id="recalculateRouteBtn">
                                <i class="bi bi-arrow-clockwise"></i> Recalcular Ruta
                            </button>
                        </div>
                    </div>
                    <div id="route_details" class="route-details-section" style="display: none;">
                        <h6>Detalles de la Ruta (Indicaciones):</h6>
                        <ol id="route_steps_list"></ol>
                    </div>

                    <hr>

                    <h4>Pedidos en este Parte de Ruta</h4>
                    <div id="pedidos_en_ruta_container" class="order-details-container">
                        <!-- Los pedidos se cargarán aquí dinámicamente -->
                        <p class="text-muted text-center">Cargando pedidos...</p>
                    </div>

                    <hr>

                    <h4>Parte de Carga por Artículo</h4>
                    <div id="parte_carga_container">
                        <table class="load-summary-table table table-bordered">
                            <thead>
                                <tr>
                                    <th>Artículo</th>
                                    <th>Unidades a Cargar</th>
                                </tr>
                            </thead>
                            <tbody id="parte_carga_body">
                                <tr><td colspan="2" class="text-center text-muted">Calculando parte de carga...</td></tr>
                            </tbody>
                        </table>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmación de Eliminación -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteConfirmModalLabel">Confirmar Eliminación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    ¿Estás seguro de que deseas eliminar este parte de ruta? Se desvincularán los pedidos asociados.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Eliminar</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Editar Pedido (desde Parte de Ruta) -->
    <div class="modal fade" id="editPedidoFromParteModal" tabindex="-1" aria-labelledby="editPedidoFromParteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="pedidos.php" method="POST" id="editPedidoFromParteForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editPedidoFromParteModalLabel">Editar Pedido #<span id="editPedidoFromParteId"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="editPedidoFromParteAccion" value="editar_pedido">
                        <input type="hidden" name="id_pedido" id="editPedidoFromParteHiddenId">
                        <div class="mb-3">
                            <label for="edit_parte_fecha_pedido" class="form-label">Fecha Pedido</label>
                            <input type="date" class="form-control rounded-pill" id="edit_parte_fecha_pedido" name="fecha_pedido" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_parte_client_name" class="form-label">Cliente</label>
                            <input type="text" class="form-control rounded-pill" id="edit_parte_client_name" readonly disabled>
                            <input type="hidden" id="edit_parte_id_cliente" name="id_cliente">
                        </div>
                        <div class="mb-3">
                            <label for="edit_parte_estado_pedido" class="form-label">Estado</label>
                            <select class="form-select rounded-pill" id="edit_parte_estado_pedido" name="estado_pedido" required>
                                <option value="pendiente">Pendiente</option>
                                <option value="completado">Completado</option>
                                <option value="cancelado">Cancelado</option>
                                <option value="en_transito_paqueteria">En Tránsito (Paquetería)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_parte_id_ruta" class="form-label">Ruta</label>
                            <select class="form-select rounded-pill" id="edit_parte_id_ruta" name="id_ruta">
                                <option value="">Seleccione una ruta</option>
                                <?php foreach ($rutas_disponibles as $ruta): ?>
                                    <option value="<?php echo htmlspecialchars($ruta['id_ruta']); ?>">
                                        <?php echo htmlspecialchars($ruta['nombre_ruta']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_parte_tipo_entrega" class="form-label">Tipo de Entrega</label>
                            <select class="form-select rounded-pill" id="edit_parte_tipo_entrega" name="tipo_entrega" required>
                                <option value="reparto_propio">Reparto Propio (Ruta)</option>
                                <option value="envio_paqueteria">Envío por Paquetería</option>
                            </select>
                        </div>
                        <div class="mb-3" id="editParteEmpresaTransporteGroup" style="display: none;">
                            <label for="edit_parte_empresa_transporte" class="form-label">Empresa de Transporte</label>
                            <input type="text" class="form-control rounded-pill" id="edit_parte_empresa_transporte" name="empresa_transporte" placeholder="Nombre de la empresa de paquetería">
                        </div>
                        <div class="mb-3" id="editParteNumeroSeguimientoGroup" style="display: none;">
                            <label for="edit_parte_numero_seguimiento" class="form-label">Número de Seguimiento</label>
                            <input type="text" class="form-control rounded-pill" id="edit_parte_numero_seguimiento" name="numero_seguimiento" placeholder="Número de tracking de paquetería">
                        </div>
                        <div class="mb-3">
                            <label for="edit_parte_observaciones" class="form-label">Observaciones</label>
                            <textarea class="form-control rounded-pill" id="edit_parte_observaciones" name="observaciones" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary rounded-pill">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Modal para Confirmaciones / Alertas -->
    <div class="modal fade" id="customMessageModal" tabindex="-1" aria-labelledby="customMessageModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content rounded-lg shadow-lg">
                <div class="modal-header">
                    <h5 class="modal-title" id="customMessageModalLabel"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="customMessageModalBody"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>


    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        // --- Funciones Globales (accesibles desde atributos onclick de HTML) ---

        // Función para mostrar mensajes modales personalizados
        function showCustomModal(title, message, type = 'info', callback = null) {
            const customMessageModal = new bootstrap.Modal(document.getElementById('customMessageModal'));
            document.getElementById('customMessageModalLabel').innerText = title;
            document.getElementById('customMessageModalBody').innerText = message;
            const modalHeader = document.querySelector('#customMessageModal .modal-header');
            modalHeader.classList.remove('bg-danger', 'bg-success', 'bg-info', 'bg-warning', 'bg-primary'); // Remove all possible previous classes
            // Add specific class based on type
            switch (type) {
                case 'danger': modalHeader.classList.add('bg-danger'); break;
                case 'success': modalHeader.classList.add('bg-success'); break;
                case 'warning': modalHeader.classList.add('bg-warning'); break;
                case 'info': modalHeader.classList.add('bg-info'); break;
                default: modalHeader.classList.add('bg-primary'); break; // Default to primary color
            }
            // Ensure close button is white on dark backgrounds
            const closeButton = modalHeader.querySelector('.btn-close');
            if (type === 'danger' || type === 'success' || type === 'info' || type === 'primary') {
                closeButton.classList.add('btn-close-white');
            } else {
                closeButton.classList.remove('btn-close-white');
            }


            const modalFooter = document.querySelector('#customMessageModal .modal-footer');
            modalFooter.innerHTML = ''; // Limpiar botones anteriores

            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.classList.add('btn', 'btn-secondary', 'rounded-pill');
            closeBtn.setAttribute('data-bs-dismiss', 'modal');
            closeBtn.innerText = 'Cerrar';
            modalFooter.appendChild(closeBtn);

            if (callback) {
                // If it's a confirmation, add an "Accept" button
                if (type === 'confirm') {
                    const confirmBtn = document.createElement('button');
                    confirmBtn.type = 'button';
                    confirmBtn.classList.add('btn', 'btn-danger', 'rounded-pill'); // Danger for delete confirmation
                    confirmBtn.setAttribute('data-bs-dismiss', 'modal');
                    confirmBtn.innerText = 'Eliminar';
                    confirmBtn.addEventListener('click', () => callback(true), { once: true });
                    modalFooter.appendChild(confirmBtn);
                    closeBtn.innerText = 'Cancelar'; // Change text for cancel button
                } else {
                    // For simple alerts, the callback is triggered on close
                    closeBtn.addEventListener('click', callback, { once: true });
                }
            }
            customMessageModal.show();
        }

        // Función para manejar la redirección a facturas_ventas.php
        window.createInvoiceFromOrder = function(clientId, clientName, orderId = null, idParteRuta = null) {
            let url = `facturas_ventas.php?new_invoice_client_id=${clientId}&new_invoice_client_name=${encodeURIComponent(clientName)}`;
            if (orderId) {
                url += `&new_invoice_order_id=${orderId}`;
            }
            // Asegurarse de que idParteRuta se pase como un número o nulo, no como la cadena 'null'
            const parteRutaIdToSend = (idParteRuta !== null && idParteRuta !== undefined && idParteRuta !== 'null') ? parseInt(idParteRuta) : null;
            if (parteRutaIdToSend) {
                url += `&new_invoice_parte_ruta_id=${parteRutaIdToSend}`;
            }
            window.open(url, '_blank'); // Abrir en una nueva pestaña para facilitar la depuración
        };

        // Función para eliminar un pedido seleccionado de la lista
        window.removeSelectedOrder = function(orderId) {
            selectedOrders.delete(orderId);
            // Pass the current active form's container elements to update the display
            const currentSelectedOrdersContainer = document.getElementById('editSelectedOrdersContainer');
            const currentPedidosSeleccionadosInput = document.getElementById('edit_pedidos_seleccionados');
            updateSelectedOrdersDisplay(currentSelectedOrdersContainer, currentPedidosSeleccionadosInput);
        };

        // Función para actualizar el estado individual del pedido en ruta
        window.updatePedidoRutaStatus = async function(idParteRutaPedido) {
            const row = document.getElementById(`pedido-row-${idParteRutaPedido}`);
            // Removed statusSelect as the column is removed
            const obsTextarea = row.querySelector('.pedido-obs-textarea');

            // Removed newStatus as the column is removed
            const newObservations = obsTextarea.value;

            const formData = new FormData();
            formData.append('action', 'update_pedido_ruta_status');
            formData.append('id_parte_ruta_pedido', idParteRutaPedido);
            // Removed newStatus from formData
            formData.append('new_observations', newObservations);

            try {
                const response = await fetch('partes_ruta.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();

                if (result.success) {
                    showCustomModal("Éxito", result.message, "success");
                    // Removed statusBadge update as the column is removed
                } else {
                    showCustomModal("Error", result.message, "danger");
                }
            } catch (error) {
                console.error('Error al actualizar el estado del pedido en ruta:', error);
                showCustomModal("Error", "Error al actualizar el estado del pedido en ruta: " + error.message, "danger");
            }
        };

        // Función para actualizar el estado general del parte de ruta
        window.updateParteRutaStatus = async function(idParteRuta, newStatus) {
            const formData = new FormData();
            formData.append('action', 'update_parte_ruta_status');
            formData.append('id_parte_ruta', idParteRuta);
            formData.append('new_status', newStatus);

            try {
                const response = await fetch('partes_ruta.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();

                if (result.success) {
                    showCustomModal("Éxito", result.message, "success");
                    const parteRutaEstadoBadge = document.getElementById('parteRutaEstadoBadge');
                    if (parteRutaEstadoBadge) {
                        parteRutaEstadoBadge.innerText = newStatus;
                        parteRutaEstadoBadge.classList.remove('bg-parte-pendiente', 'bg-parte-en-curso', 'bg-parte-completado', 'bg-parte-cancelado', 'bg-secondary', 'bg-info');
                        switch (newStatus) {
                            case 'Pendiente': parteRutaEstadoBadge.classList.add('bg-parte-pendiente'); break;
                            case 'En Curso': parteRutaEstadoBadge.classList.add('bg-parte-en-curso'); break;
                            case 'Completado': parteRutaEstadoBadge.classList.add('bg-parte-completado'); break;
                            case 'Cancelado': parteRutaEstadoBadge.classList.add('bg-parte-cancelado'); break;
                            default: parteRutaEstadoBadge.classList.add('bg-secondary'); break;
                        }
                    }
                } else {
                    showCustomModal("Error", result.message, "danger");
                }
            } catch (error) {
                console.error('Error al actualizar el estado del parte de ruta:', error);
                showCustomModal("Error", "Error al actualizar el estado general del parte de ruta: " + error.message, "danger");
            }
        };

        // Variables globales para el mapa
        let map;
        let directionsService;
        let directionsRenderer;
        let infoWindow;
        let currentMarkers = [];
        let currentGoogleMapsUrl = '';
        let currentRouteWaypoints = [];
        let currentTotalDistance = 0; // Para almacenar la distancia total de la ruta
        let currentTotalDuration = 0; // Para almacenar la duración total de la ruta

        // Coordenadas fijas de las instalaciones de la empresa
        const COMPANY_LOCATION = { lat: 36.8698556, lng: -5.8068993 };
        const COMPANY_ADDRESS_NAME = "Instalaciones de la Empresa (Espera, Cádiz)";

        // Función de inicialización del mapa (callback de Google Maps API)
        // MOVIDA FUERA DEL DOMContentLoaded para que sea globalmente accesible
        window.initMap = function() {
            directionsService = new google.maps.DirectionsService();
            directionsRenderer = new google.maps.DirectionsRenderer({
                suppressMarkers: true
            });

            map = new google.maps.Map(document.getElementById('map'), {
                zoom: 10,
                center: COMPANY_LOCATION,
                mapId: 'DEMO_MAP_ID'
            });
            directionsRenderer.setMap(map);
            infoWindow = new google.maps.InfoWindow();
        };

        // Función para añadir marcador (ahora usando AdvancedMarkerElement)
        function addMarker(location, title, content) {
            const marker = new google.maps.marker.AdvancedMarkerElement({
                map: map,
                position: location,
                title: title
            });
            currentMarkers.push(marker);

            marker.addListener('click', () => {
                infoWindow.setContent(content);
                infoWindow.open(map, marker);
            });
            return marker;
        }

        // Función para calcular y mostrar la ruta
        async function calculateAndDisplayRoute(pedidos, optimize = true) {
            // Clear previous markers and directions
            for (let i = 0; i < currentMarkers.length; i++) {
                currentMarkers[i].map = null;
            }
            currentMarkers = [];
            if (directionsRenderer) {
                directionsRenderer.setDirections({ routes: [] });
            } else {
                console.error("directionsRenderer no está inicializado. Esto es crítico.");
                document.getElementById('map').innerHTML = '<div class="alert alert-danger text-center">Error: El servicio de mapas no se pudo inicializar correctamente.</div>';
                return;
            }

            const mapInfoDiv = document.getElementById('map_info');
            const routeSummaryDiv = document.getElementById('route_summary');
            const totalDistanceSpan = document.getElementById('total_distance');
            const totalDurationSpan = document.getElementById('total_duration');
            const routeDetailsSection = document.getElementById('route_details');
            const routeStepsList = document.getElementById('route_steps_list');
            const googleMapsUrlInput = document.getElementById('googleMapsUrlInput');
            const shareButtonsContainer = document.getElementById('shareButtonsContainer');
            const optimizedDeliveryOrderSection = document.getElementById('optimized_delivery_order');
            const deliveryOrderList = document.getElementById('delivery_order_list');
            const recalculateRouteBtn = document.getElementById('recalculateRouteBtn');

            // Reset UI elements
            mapInfoDiv.style.display = 'none'; // Hide any previous messages
            routeSummaryDiv.style.display = 'none';
            routeDetailsSection.style.display = 'none';
            shareButtonsContainer.style.display = 'none';
            optimizedDeliveryOrderSection.style.display = 'block'; // Ensure this section is visible
            totalDistanceSpan.textContent = 'N/A';
            totalDurationSpan.textContent = 'N/A';
            routeStepsList.innerHTML = '';
            deliveryOrderList.innerHTML = ''; // Clear the list
            googleMapsUrlInput.value = '';
            currentTotalDistance = 0;
            currentTotalDuration = 0;
            currentRouteWaypoints = []; // Reset waypoints before populating

            const origin = COMPANY_LOCATION;
            const destination = COMPANY_LOCATION;

            addMarker(COMPANY_LOCATION, COMPANY_ADDRESS_NAME, `<div class="info-window-content"><h6>${COMPANY_ADDRESS_NAME}</h6><p>Punto de inicio y fin de la ruta.</p></div>`);

            const validPedidos = pedidos.filter(pedido => {
                // Prioritize shipping address coordinates if available and valid
                const lat = parseFloat(pedido.latitud_envio);
                const lng = parseFloat(pedido.longitud_envio);
                let address_name_for_map = '';

                if (pedido.id_direccion_envio && !isNaN(lat) && !isNaN(lng)) {
                    // Use shipping address coordinates and construct a display name
                    pedido._effective_lat = lat;
                    pedido._effective_lng = lng;
                    if (pedido.nombre_direccion_envio) {
                        address_name_for_map = `${pedido.nombre_direccion_envio} (${pedido.direccion_envio}, ${pedido.ciudad_envio})`;
                    } else if (pedido.direccion_envio) {
                        address_name_for_map = `${pedido.direccion_envio}, ${pedido.ciudad_envio}`;
                    } else {
                        address_name_for_map = pedido.direccion_google_maps_envio || 'Dirección de Envío Desconocida';
                    }
                    pedido._effective_address_name = `Dirección de Envío: ${address_name_for_map}`;
                    return true;
                } else {
                    // Fallback to client's main address coordinates and construct a display name
                    const clientLat = parseFloat(pedido.latitud);
                    const clientLng = parseFloat(pedido.longitud);
                    if (!isNaN(clientLat) && !isNaN(clientLng)) {
                        pedido._effective_lat = clientLat;
                        pedido._effective_lng = clientLng;
                        if (pedido.cliente_direccion_principal) {
                            address_name_for_map = `Principal: ${pedido.cliente_direccion_principal}, ${pedido.cliente_ciudad_principal}`;
                        } else {
                            address_name_for_map = pedido.direccion_google_maps || 'Dirección Principal Desconocida';
                        }
                        pedido._effective_address_name = `Dirección Principal Cliente: ${address_name_for_map}`;
                        return true;
                    }
                }
                console.warn(`Coordenadas inválidas o no disponibles para el cliente ${pedido.nombre_cliente} (Pedido #${pedido.id_pedido}). Saltando este marcador y no se incluirá en la ruta.`);
                return false;
            });

            if (validPedidos.length === 0) {
                console.warn("No hay pedidos con coordenadas válidas para calcular la ruta.");
                mapInfoDiv.textContent = 'No hay pedidos con coordenadas válidas para mostrar en el mapa (solo se muestra la ubicación de la empresa).';
                mapInfoDiv.classList.remove('alert-danger', 'alert-warning'); // Ensure correct styling
                mapInfoDiv.classList.add('alert-info');
                mapInfoDiv.style.display = 'block';
                map.setCenter(COMPANY_LOCATION);
                map.setZoom(12);
                // currentRouteWaypoints already reset to empty
                updateDeliveryOrderList(); // Call to update the list, which will show "No hay pedidos..." message
                return;
            }

            currentRouteWaypoints = validPedidos.map(pedido => {
                const location = { lat: pedido._effective_lat, lng: pedido._effective_lng };
                const infoContent = `
                    <div class="info-window-content">
                        <h6>Cliente: ${pedido.nombre_cliente}</h6>
                        <p>Pedido #${pedido.id_pedido}</p>
                        <p>Total: €${number_format(pedido.total_pedido ?? 0, 2, ',', '.')}</p>
                        <p>Estado: ${pedido.estado_pedido}</p>
                        <p>Dirección: ${pedido._effective_address_name}</p>
                        <button class="btn btn-sm btn-primary mt-2" onclick="showEditPedidoFromParteModal(${pedido.id_pedido}, '${pedido.fecha_pedido}', '${pedido.id_cliente}', '${pedido.nombre_cliente.replace(/'/g, "\\'")}', '${pedido.estado_pedido}', '${pedido.id_ruta || ''}', '${pedido.tipo_entrega || ''}', '${pedido.empresa_transporte || ''}', '${pedido.numero_seguimiento || ''}', '${pedido.observaciones || ''}')">
                            <i class="bi bi-pencil"></i> Editar Pedido
                        </button>
                    </div>
                `;
                return {
                    location: location,
                    stopover: true,
                    infoContent: infoContent,
                    clientName: pedido.nombre_cliente,
                    idPedido: pedido.id_pedido,
                    originalPedidoData: pedido,
                    _effective_address_name: pedido._effective_address_name // Add effective address name here
                };
            });

            currentRouteWaypoints.forEach(waypoint => {
                addMarker(waypoint.location, '', waypoint.infoContent);
            });

            const directionsWaypoints = currentRouteWaypoints.map(wp => ({ location: wp.location, stopover: wp.stopover }));

            try {
                const request = {
                    origin: origin,
                    destination: destination,
                    waypoints: directionsWaypoints,
                    optimizeWaypoints: optimize,
                    travelMode: google.maps.TravelMode.DRIVING
                };

                const response = await directionsService.route(request);
                directionsRenderer.setDirections(response);

                if (response.routes && response.routes.length > 0) {
                    const route = response.routes[0];
                    currentTotalDistance = 0;
                    currentTotalDuration = 0;

                    if (optimize && route.waypoint_order) {
                        const optimizedOrder = route.waypoint_order.map(index => currentRouteWaypoints[index]);
                        currentRouteWaypoints = optimizedOrder;
                    }
                    console.log("Ruta calculada exitosamente. Waypoints:", currentRouteWaypoints); // Debug log
                    updateDeliveryOrderList(); // Update the draggable list

                    let googleMapsUrl = `https://www.google.com/maps/dir/?api=1`;
                    googleMapsUrl += `&origin=${origin.lat},${origin.lng}`;
                    googleMapsUrl += `&destination=${destination.lat},${destination.lng}`;

                    const optimizedWaypointsCoords = currentRouteWaypoints.map(wp => `${wp.location.lat},${wp.location.lng}`).join('|');
                    if (optimizedWaypointsCoords) {
                        googleMapsUrl += `&waypoints=${optimizedWaypointsCoords}`;
                    }
                    googleMapsUrl += `&travelmode=driving`;
                    currentGoogleMapsUrl = googleMapsUrl;
                    googleMapsUrlInput.value = currentGoogleMapsUrl;
                    shareButtonsContainer.style.display = 'flex';

                    routeDetailsSection.style.display = 'block';
                    routeStepsList.innerHTML = '';
                    let stepCounter = 1;

                    if (currentRouteWaypoints.length > 0) {
                        const firstClientName = currentRouteWaypoints[0].clientName;
                        const firstLeg = route.legs[0];
                        const legHeaderFirst = document.createElement('li');
                        legHeaderFirst.innerHTML = `<strong>Tramo 1: Desde ${COMPANY_ADDRESS_NAME} hasta ${firstClientName}</strong>`;
                        routeStepsList.appendChild(legHeaderFirst);
                        firstLeg.steps.forEach(step => {
                            const listItem = document.createElement('li');
                            listItem.innerHTML = `${stepCounter}. ${step.instructions} (Distancia: ${step.distance.text}, Tiempo: ${step.duration.text})`;
                            routeStepsList.appendChild(listItem);
                            stepCounter++;
                        });
                        currentTotalDistance += firstLeg.distance.value;
                        currentTotalDuration += firstLeg.duration.value;
                    }

                    for (let i = 1; i < route.legs.length; i++) {
                        const leg = route.legs[i];
                        const prevClientName = currentRouteWaypoints[i - 1].clientName;
                        const currentClientName = (i < currentRouteWaypoints.length) ? currentRouteWaypoints[i].clientName : COMPANY_ADDRESS_NAME;

                        const legHeader = document.createElement('li');
                        legHeader.innerHTML = `<strong>Tramo ${i + 1}: Desde ${prevClientName} hasta ${currentClientName}</strong>`;
                        routeStepsList.appendChild(legHeader);

                        leg.steps.forEach(step => {
                            const listItem = document.createElement('li');
                            listItem.innerHTML = `${stepCounter}. ${step.instructions} (Distancia: ${step.distance.text}, Tiempo: ${step.duration.text})`;
                            routeStepsList.appendChild(listItem);
                            stepCounter++;
                        });
                        currentTotalDistance += leg.distance.value;
                        currentTotalDuration += leg.duration.value;
                    }

                    totalDistanceSpan.textContent = (currentTotalDistance / 1000).toFixed(2) + ' km';
                    totalDurationSpan.textContent = formatDuration(currentTotalDuration);
                    routeSummaryDiv.style.display = 'block';

                    const editTotalKilometrosInput = document.getElementById('edit_total_kilometros');
                    const editDuracionEstimadaSegundosInput = document.getElementById('edit_duracion_estimada_segundos');
                    if (editTotalKilometrosInput) {
                        editTotalKilometrosInput.value = (currentTotalDistance / 1000).toFixed(2);
                        console.log('Updated edit_total_kilometros to:', editTotalKilometrosInput.value); // New log
                    }
                    if (editDuracionEstimadaSegundosInput) {
                        editDuracionEstimadaSegundosInput.value = currentTotalDuration;
                        console.log('Updated edit_duracion_estimada_segundos to:', editDuracionEstimadaSegundosInput.value); // New log
                    }

                } else {
                    // This else block handles cases where response.routes is empty, but no error was thrown
                    console.warn("La API de Directions no devolvió ninguna ruta.");
                    mapInfoDiv.textContent = 'La API de Google Maps no pudo calcular una ruta válida para los puntos proporcionados. Esto podría deberse a que los puntos están demasiado lejos, son inaccesibles, o hay un problema con la clave de la API (verifica que la API de Directions esté habilitada).';
                    mapInfoDiv.classList.remove('alert-info', 'alert-warning');
                    mapInfoDiv.classList.add('alert-danger');
                    mapInfoDiv.style.display = 'block';
                    shareButtonsContainer.style.display = 'none';
                    googleMapsUrlInput.value = '';
                    deliveryOrderList.innerHTML = '';
                    currentTotalDistance = 0;
                    currentTotalDuration = 0;
                }

                // Fit bounds only if there's a route to fit
                if (response.routes && response.routes.length > 0) {
                    map.fitBounds(response.routes[0].bounds);
                } else {
                    // If no route, fit bounds to company location or a default zoom
                    map.setCenter(COMPANY_LOCATION);
                    map.setZoom(12);
                }


            } catch (e) {
                console.error('Error al calcular la ruta (catch block):', e);
                mapInfoDiv.textContent = `Error al cargar la ruta: ${e.message}. Esto puede deberse a coordenadas inválidas de los clientes, un problema de conexión, o un error con la clave de la API de Google Maps (verifica que la clave es correcta y que la API de Directions está habilitada). Intenta de nuevo.`;
                mapInfoDiv.classList.remove('alert-info', 'alert-warning');
                mapInfoDiv.classList.add('alert-danger');
                mapInfoDiv.style.display = 'block';
                shareButtonsContainer.style.display = 'none';
                googleMapsUrlInput.value = '';
                deliveryOrderList.innerHTML = '';
                currentTotalDistance = 0;
                currentTotalDuration = 0;
            }
        }

        // Función para actualizar la lista de pedidos arrastrable
        function updateDeliveryOrderList() {
            const deliveryOrderList = document.getElementById('delivery_order_list');
            deliveryOrderList.innerHTML = ''; // Clear existing content

            if (currentRouteWaypoints.length === 0) {
                deliveryOrderList.innerHTML = '<p class="text-muted text-center">No hay pedidos para mostrar en la lista de reparto.</p>';
                return;
            }

            console.log("Actualizando lista de reparto con waypoints:", currentRouteWaypoints); // Debug log

            currentRouteWaypoints.forEach((waypoint, index) => {
                const listItem = document.createElement('li');
                // Updated to include the effective address name
                listItem.textContent = `${index + 1}. ${waypoint.clientName} (Pedido #${waypoint.idPedido}) - ${waypoint._effective_address_name}`;
                listItem.setAttribute('draggable', 'true');
                listItem.dataset.index = index;

                listItem.addEventListener('dragstart', handleDragStart);
                listItem.addEventListener('dragover', handleDragOver);
                listItem.addEventListener('dragleave', handleDragLeave);
                listItem.addEventListener('drop', handleDrop);
                listItem.addEventListener('dragend', handleDragEnd);

                deliveryOrderList.appendChild(listItem);
            });
        }

        // Variables de arrastrar y soltar
        let draggedItem = null;

        function handleDragStart(e) {
            draggedItem = this;
            setTimeout(() => this.classList.add('dragging'), 0);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.dataset.index);
        }

        function handleDragOver(e) {
            e.preventDefault();
            const targetItem = this;
            if (targetItem && draggedItem && targetItem !== draggedItem) {
                const bounding = targetItem.getBoundingClientRect();
                const offset = bounding.y + (bounding.height / 2);
                if (e.clientY > offset) {
                    targetItem.classList.remove('drag-over-up');
                    targetItem.classList.add('drag-over-down');
                } else {
                    targetItem.classList.remove('drag-over-down');
                    targetItem.classList.add('drag-over-up');
                }
            }
        }

        function handleDragLeave() {
            this.classList.remove('drag-over-up', 'drag-over-down');
        }

        function handleDrop(e) {
            e.preventDefault();
            this.classList.remove('drag-over-up', 'drag-over-down');

            if (draggedItem && draggedItem !== this) {
                const draggedIndex = parseInt(draggedItem.dataset.index);
                const droppedIndex = parseInt(this.dataset.index);

                const [removed] = currentRouteWaypoints.splice(draggedIndex, 1);
                currentRouteWaypoints.splice(droppedIndex, 0, removed);

                updateDeliveryOrderList();
            }
        }

        function handleDragEnd() {
            this.classList.remove('dragging');
            draggedItem = null;
            document.querySelectorAll('#delivery_order_list li').forEach(item => {
                item.classList.remove('drag-over-up', 'drag-over-down');
            });
        }

        // Función para recalcular la ruta basándose en el orden actual de currentRouteWaypoints
        function recalculateRoute() {
            if (currentRouteWaypoints.length > 0) {
                calculateAndDisplayRoute(currentRouteWaypoints.map(wp => wp.originalPedidoData), false);
            } else {
                showCustomModal("Advertencia", "No hay pedidos en la lista para recalcular la ruta.", "warning");
            }
        }


        // Función auxiliar para formatear duración (segundos a HH:MM:SS)
        function formatDuration(seconds) {
            const hours = Math.floor(seconds / 3600);
            seconds %= 3600;
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;

            let result = '';
            if (hours > 0) {
                result += `${hours}h `;
            }
            if (minutes > 0 || hours > 0) {
                result += `${minutes}m `;
            }
            // Only add seconds if there are any, or if the total duration is 0 (e.g., "0s")
            if (remainingSeconds > 0 || (hours === 0 && minutes === 0)) {
                result += `${remainingSeconds}s`;
            }
            return result.trim(); // Use .trim()
        }

        // Función auxiliar para formatear números (similar a number_format de PHP)
        function number_format(number, decimals, dec_point, thousands_sep) {
            number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
            var n = !isFinite(+number) ? 0 : +number,
                prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
                sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
                dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
                s = '',
                toFixedFix = function(n, prec) {
                    var k = Math.pow(10, prec);
                    return '' + Math.round(n * k) / k;
                };
            s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
            if (s[0].length > 3) {
                s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
            }
            if ((s[1] || '').length < prec) {
                s[1] = s[1] || '';
                s[1] += new Array(prec - s[1].length + 1).join('0');
            }
            return s.join(dec);
        }

        // Función para cargar y mostrar los detalles de los pedidos en el modal del mapa
        async function loadPedidosDetailsForMap(idParteRuta) {
            const pedidosEnRutaContainer = document.getElementById('pedidos_en_ruta_container');
            const parteCargaBody = document.getElementById('parte_carga_body');
            pedidosEnRutaContainer.innerHTML = '<p class="text-muted text-center">Cargando pedidos...</p>';
            parteCargaBody.innerHTML = '<tr><td colspan="2" class="text-center text-muted">Calculando parte de carga...</td></tr>';

            try {
                const response = await fetch(`partes_ruta.php?action=get_pedidos_details_by_parte_ruta&id_parte_ruta=${idParteRuta}`);
                const data = await response.json();

                if (data.success) {
                    const orderIdToDetailsMap = new Map();
                    data.pedidos.forEach(pedido => {
                        orderIdToDetailsMap.set(pedido.id_pedido, pedido);
                    });

                    let htmlContent = '';
                    const cargaSummary = {};

                    if (currentRouteWaypoints.length === 0) {
                        htmlContent = '<p class="text-muted text-center">No hay pedidos asociados a este parte de ruta.</p>';
                    } else {
                        currentRouteWaypoints.forEach(waypoint => {
                            const pedido = orderIdToDetailsMap.get(waypoint.idPedido);

                            if (pedido) {
                                htmlContent += `
                                    <div class="order-item-card">
                                        <h5>Pedido #${pedido.id_pedido} - Cliente: ${pedido.nombre_cliente}</h5>
                                        <p><strong>Fecha:</strong> ${new Date(pedido.fecha_pedido).toLocaleDateString()}</p>
                                        <p><strong>Total:</strong> ${number_format(pedido.total_pedido ?? 0, 2, ',', '.')}</p>
                                        <p><strong>Estado:</strong> <span class="badge bg-primary">${pedido.estado_pedido}</span></p>
                                        <p><strong>Factura Asociada:</strong>
                                            ${pedido.id_factura_asociada ?
                                                `<a href="facturas_ventas.php?view=details&id=${pedido.id_factura_asociada}&from_parte_ruta_id=${idParteRuta}" target="_blank" class="badge bg-info text-dark">#${pedido.id_factura_asociada}</a>` :
                                                `<span class="badge bg-secondary">Ninguna</span>`
                                            }
                                        </p>
                                        <h6>Líneas de Detalle:</h6>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Producto</th>
                                                        <th>Cant. Solicitada</th>
                                                        <th>Precio Unitario</th>
                                                        <th>Subtotal</th>
                                                        <th>Acciones</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                `;

                                if (pedido.detalles && pedido.detalles.length > 0) {
                                    pedido.detalles.forEach(detalle => {
                                        htmlContent += `
                                            <tr>
                                                <td>${detalle.nombre_producto}</td>
                                                <td>${detalle.cantidad_solicitada}</td>
                                                <td>${number_format(detalle.precio_unitario_venta, 2, ',', '.')} €</td>
                                                <td>${number_format(detalle.subtotal_linea, 2, ',', '.')} €</td>
                                                <td>
                                                    <button class="btn btn-sm btn-warning" onclick="showEditPedidoLineModal(${detalle.id_detalle_pedido}, ${pedido.id_pedido}, '${detalle.nombre_producto.replace(/'/g, "\\'")}', ${detalle.cantidad_solicitada}, ${detalle.precio_unitario_venta}, '${(detalle.observaciones_detalle || '').replace(/'/g, "\\'")}')">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    ${pedido.id_factura_asociada ?
                                                        `<button class="btn btn-sm btn-success ms-1" onclick="redirectToRetirarStock(${pedido.id_factura_asociada}, ${detalle.id_detalle_factura_asociada || 'null'}, ${detalle.cantidad_solicitada}, ${detalle.unidades_retiradas_factura || 0}, '${detalle.nombre_producto.replace(/'/g, "\\'")}', ${detalle.id_producto}, ${idParteRuta})">
                                                            <i class="bi bi-box-seam"></i> Retirar
                                                        </button>` :
                                                        `<button class="btn btn-sm btn-secondary ms-1" disabled title="Requiere factura asociada">
                                                            <i class="bi bi-box-seam"></i> Retirar
                                                        </button>`
                                                    }
                                                </td>
                                            </tr>
                                        `;

                                        if (cargaSummary[detalle.nombre_producto]) {
                                            cargaSummary[detalle.nombre_producto] += parseInt(detalle.cantidad_solicitada);
                                        } else {
                                            cargaSummary[detalle.nombre_producto] = parseInt(detalle.cantidad_solicitada);
                                        }
                                    });
                                } else {
                                    htmlContent += `<tr><td colspan="5" class="text-center">No hay líneas de detalle para este pedido.</td></tr>`;
                                }
                                htmlContent += `
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="d-flex justify-content-end mt-2">
                                            <button class="btn btn-sm btn-primary me-2" onclick="showEditPedidoFromParteModal(${pedido.id_pedido}, '${pedido.fecha_pedido}', '${pedido.id_cliente}', '${pedido.nombre_cliente.replace(/'/g, "\\'")}', '${pedido.estado_pedido}', '${pedido.id_ruta || ''}', '${pedido.tipo_entrega || ''}', '${pedido.empresa_transporte || ''}', '${pedido.numero_seguimiento || ''}', '${pedido.pedido_observaciones || ''}')">
                                                <i class="bi bi-pencil"></i> Editar Pedido
                                            </button>
                                            ${pedido.id_factura_asociada ?
                                                `<a href="facturas_ventas.php?view=details&id=${pedido.id_factura_asociada}&from_parte_ruta_id=${idParteRuta}" target="_blank" class="btn btn-sm btn-info">
                                                    <i class="bi bi-receipt"></i> Ver Factura
                                                </a>` :
                                                `<button class="btn btn-sm btn-success" onclick="createInvoiceFromOrder(${pedido.id_cliente}, '${pedido.nombre_cliente.replace(/'/g, "\\'")}', ${pedido.id_pedido}, ${idParteRuta})">
                                                    <i class="bi bi-receipt"></i> Crear Factura
                                                </button>`
                                            }
                                        </div>
                                    </div>
                                `;
                            }
                        });
                    }
                    pedidosEnRutaContainer.innerHTML = htmlContent;

                    let cargaHtml = '';
                    if (Object.keys(cargaSummary).length > 0) {
                        for (const product in cargaSummary) {
                            cargaHtml += `
                                <tr>
                                    <td>${product}</td>
                                    <td>${cargaSummary[product]} unidades</td>
                                </tr>
                            `;
                        }
                    } else {
                        cargaHtml = '<tr><td colspan="2" class="text-center text-muted">No hay artículos para cargar.</td></tr>';
                    }
                    parteCargaBody.innerHTML = cargaHtml;

                } else {
                    pedidosEnRutaContainer.innerHTML = `<div class="alert alert-danger text-center">Error al cargar detalles de pedidos: ${data.message}</div>`;
                    parteCargaBody.innerHTML = '<tr><td colspan="2" class="text-center text-danger">Error al cargar parte de carga.</td></tr>';
                    console.error('Error al obtener detalles de pedidos para el parte de ruta:', data.message);
                }
            } catch (error) {
                pedidosEnRutaContainer.innerHTML = `<div class="alert alert-danger text-center">Error de conexión al cargar detalles de pedidos.</div>`;
                parteCargaBody.innerHTML = '<tr><td colspan="2" class="text-center text-danger">Error de conexión al cargar parte de carga.</td></tr>';
                console.error('Error en la llamada API para detalles de pedidos del parte de ruta:', error);
            }
        }


        // Función para redirigir a facturas_ventas.php para la retirada de stock
        function redirectToRetirarStock(idFactura, idDetalleFactura, cantidadTotal, unidadesRetiradas, productName, idProducto, parteRutaId) {
            const url = `facturas_ventas.php?view=details&id=${idFactura}` +
                        `&open_retirar_stock_modal=true` +
                        `&id_detalle_factura=${idDetalleFactura}` +
                        `&cantidad_total=${cantidadTotal}` +
                        `&unidades_retiradas=${unidadesRetiradas}` +
                        `&product_name=${encodeURIComponent(productName)}` +
                        `&id_product=${idProducto}` +
                        `&from_parte_ruta_id=${parteRutaId}`;

            window.location.href = url;
        }

        // Función para mostrar el modal de edición de pedido desde el parte de ruta
        function showEditPedidoFromParteModal(idPedido, fechaPedido, idCliente, nombreCliente, estadoPedido, idRuta, tipoEntrega, empresaTransporte, numeroSeguimiento, observaciones) {
            const modal = new bootstrap.Modal(document.getElementById('editPedidoFromParteModal'));
            document.getElementById('editPedidoFromParteId').textContent = idPedido;
            document.getElementById('editPedidoFromParteHiddenId').value = idPedido;
            document.getElementById('edit_parte_fecha_pedido').value = fechaPedido;
            document.getElementById('edit_parte_client_name').value = nombreCliente;
            document.getElementById('edit_parte_id_cliente').value = idCliente;
            document.getElementById('edit_parte_estado_pedido').value = estadoPedido;
            document.getElementById('edit_parte_id_ruta').value = idRuta;
            document.getElementById('edit_parte_tipo_entrega').value = tipoEntrega;
            document.getElementById('edit_parte_observaciones').value = observaciones;

            // Manejar la visibilidad y los valores de los campos de paquetería
            const editParteEmpresaTransporteGroup = document.getElementById('editParteEmpresaTransporteGroup');
            const editParteNumeroSeguimientoGroup = document.getElementById('editParteNumeroSeguimientoGroup');
            const editParteEmpresaTransporteInput = document.getElementById('edit_parte_empresa_transporte');
            const editParteNumeroSeguimientoInput = document.getElementById('edit_parte_numero_seguimiento');
            const editParteIdRutaSelect = document.getElementById('edit_parte_id_ruta');


            if (tipoEntrega === 'envio_paqueteria') {
                editParteEmpresaTransporteGroup.style.display = 'block';
                editParteNumeroSeguimientoGroup.style.display = 'block';
                // Keep current values if they exist, otherwise empty
                editParteEmpresaTransporteInput.value = empresaTransporte || '';
                editParteNumeroSeguimientoInput.value = numeroSeguimiento || '';
                editParteIdRutaSelect.removeAttribute('required'); // Ruta no es obligatoria para paquetería
                editParteIdRutaSelect.value = ''; // Limpiar ruta seleccionada
            } else {
                editParteEmpresaTransporteGroup.style.display = 'none';
                editParteNumeroSeguimientoGroup.style.display = 'none';
                editParteEmpresaTransporteInput.value = '';
                editParteNumeroSeguimientoInput.value = '';
                editParteIdRutaSelect.setAttribute('required', 'required'); // Ruta es obligatoria para reparto propio
            }

            modal.show();
        }

        // Función para mostrar el modal de edición de línea de pedido (si se decide implementar)
        function showEditPedidoLineModal(idDetallePedido, idPedido, nombreProducto, cantidadSolicitada, precioUnitario, observacionesDetalle) {
            // Por ahora, esto solo mostrará una alerta, ya que la edición de líneas de pedido
            // no está implementada directamente aquí para simplificar.
            // Si se necesita, se podría crear un modal similar al de "Add Linea" en pedidos.php
            // y precargarlo con estos datos.
            showCustomModal("Edición de Línea de Pedido", `Funcionalidad de edición de línea de pedido no implementada directamente aquí. \n\nDetalles: \nID Detalle: ${idDetallePedido}\nProducto: ${nombreProducto}\nCantidad: ${cantidadSolicitada}\nPrecio: ${precioUnitario}\nObservaciones: ${observacionesDetalle}`, "info");
            // Alternativamente, redirigir a pedidos.php con parámetros de edición de línea
            // window.location.href = `pedidos.php?view=details&id=${idPedido}&edit_line=${idDetallePedido}`;
        }

        // Nueva función para generar PDF
        function generateParteRutaPdf(idParteRuta) {
            // Extraer solo los IDs de pedido de currentRouteWaypoints
            const orderedPedidoIds = currentRouteWaypoints.map(wp => wp.idPedido);
            const orderedPedidosJson = JSON.stringify(orderedPedidoIds);

            const pdfUrl = `generate_parte_ruta_pdf.php?id_parte_ruta=${idParteRuta}&ordered_pedidos=${encodeURIComponent(orderedPedidosJson)}`;
            window.open(pdfUrl, '_blank');
        }


        let selectedOrders = new Map(); // Global map for selected orders (only for edit form in this page)
        let currentPendingOrders = [];

        // Modified updateSelectedOrdersDisplay to accept container elements as arguments
        function updateSelectedOrdersDisplay(container, pedidosInput) { // Removed noOrdersMessage from arguments
            console.log('Debugging updateSelectedOrdersDisplay:');
            console.log('container element:', container);
            console.log('pedidosInput element:', pedidosInput);

            if (!container || !pedidosInput) {
                console.error("Error: Uno o más elementos de contenedor de pedidos no se encontraron. Asegúrate de que los IDs son correctos y los elementos existen en el DOM para el contexto actual.");
                return; // Exit if elements are not found
            }

            container.innerHTML = ''; // Clear existing content

            if (selectedOrders.size === 0) {
                const noOrdersMessage = document.createElement('p');
                noOrdersMessage.id = 'editNoOrdersMessage'; // Re-add the ID for consistency
                noOrdersMessage.classList.add('text-muted', 'text-center');
                noOrdersMessage.textContent = 'No hay pedidos seleccionados para este parte de ruta.';
                container.appendChild(noOrdersMessage);
            } else {
                let tableHtml = `
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>ID Pedido</th>
                                    <th>Fecha</th>
                                    <th>Cliente</th>
                                    <th>Dirección de Envío</th> <!-- NEW COLUMN for JS rendered table -->
                                    <th class="text-end">Unidades Totales</th>
                                    <th class="text-end">Importe Total</th>
                                    <th class="text-center">Estado Factura</th>
                                    <th class="text-center">Estado Cobro</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                selectedOrders.forEach((order, id) => {
                    let estadoServicioTexto = '';
                    let badgeClassServicio = '';

                    const totalUnidadesServidas = order.total_unidades_servidas ?? 0;
                    const totalCantidadFacturada = order.total_cantidad_facturada ?? 0;
                    const totalUnidades = order.total_unidades ?? 0;
                    const totalImporte = order.total_importe ?? 0;


                    if (order.id_factura_asociada === null) {
                        estadoServicioTexto = 'Pendiente (sin factura)';
                        badgeClassServicio = 'bg-pending-no-invoice';
                    } else if (totalUnidadesServidas == 0 && totalCantidadFacturada > 0) {
                        estadoServicioTexto = 'Pendiente (vía factura)';
                        badgeClassServicio = 'bg-pending-invoice';
                    } else if (totalUnidadesServidas > 0 && totalUnidadesServidas < totalCantidadFacturada) {
                        estadoServicioTexto = 'Parcialmente Servido';
                        badgeClassServicio = 'bg-served-partial';
                    } else if (totalUnidadesServidas > 0 && totalUnidadesServidas == totalCantidadFacturada && totalCantidadFacturada > 0) {
                        estadoServicioTexto = 'Servido Completo';
                        badgeClassServicio = 'bg-served-complete';
                    } else {
                        estadoServicioTexto = 'Estado Desconocido';
                        badgeClassServicio = 'bg-dark';
                    }

                    const isFacturado = order.id_factura_asociada !== null;

                    let estadoCobroTexto = 'N/A';
                    let badgeClassCobro = 'bg-secondary';
                    if (isFacturado) {
                        estadoCobroTexto = order.estado_pago || 'Pendiente';
                        switch (estadoCobroTexto) {
                            case 'pagada': badgeClassCobro = 'bg-pago-pagado'; break;
                            case 'parcialmente_pagada': badgeClassCobro = 'bg-pago-parcial'; break;
                            case 'anulado': badgeClassCobro = 'bg-pago-anulado'; break;
                            case 'pendiente':
                            default: badgeClassCobro = 'bg-pago-pendiente'; break;
                        }
                    }

                    // Determine the effective address for display in JS
                    let display_address_js = 'N/A';
                    if (order.nombre_direccion_envio) {
                        display_address_js = `${order.nombre_direccion_envio} (${order.direccion_envio}, ${order.ciudad_envio})`;
                    } else if (order.cliente_direccion_principal) {
                        display_address_js = `Principal: ${order.cliente_direccion_principal}, ${order.cliente_ciudad_principal}`;
                    }


                    tableHtml += `
                        <tr>
                            <td>${order.id_pedido}</td>
                            <td>${new Date(order.fecha_pedido).toLocaleDateString('es-ES')}</td>
                            <td>${order.nombre_cliente}</td>
                            <td>${display_address_js}</td> <!-- NEW CELL FOR ADDRESS in JS rendered table -->
                            <td class="text-end">${parseFloat(totalUnidades).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                            <td class="text-end">${parseFloat(totalImporte).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}€</td>
                            <td class="text-center">
                                <span class="badge ${badgeClassServicio}">${estadoServicioTexto}</span>
                                ${isFacturado ? `<a href="facturas_ventas.php?view=details&id=${order.id_factura_asociada}" class="btn btn-sm btn-outline-primary ms-2" title="Ver Factura" target="_blank"><i class="bi bi-receipt-cutoff"></i></a>` : ''}
                            </td>
                            <td><span class="badge ${badgeClassCobro}">${estadoCobroTexto}</span></td>
                            <td class="text-center">
                                <button type="button" class="btn btn-success btn-sm me-1" title="Convertir a Factura"
                                    ${isFacturado ? 'disabled' : ''}
                                    onclick="window.createInvoiceFromOrder(${order.id_cliente}, '${order.nombre_cliente.replace(/'/g, "\\'")}', ${order.id_pedido}, ${document.getElementById('edit_id_parte_ruta')?.value || 'null'})">
                                    <i class="bi bi-receipt"></i> Facturar
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" title="Quitar de la lista" onclick="window.removeSelectedOrder(${order.id_pedido})">
                                    <i class="bi bi-x-circle"></i> Eliminar
                                </button>
                            </td>
                        </tr>
                    `;
                });
                tableHtml += `
                            </tbody>
                        </table>
                    </div>
                `;
                container.innerHTML = tableHtml;
            }
            pedidosInput.value = JSON.stringify(Array.from(selectedOrders.keys()));
            console.log('Selected Orders after updateDisplay:', Array.from(selectedOrders.keys()));
        }

        // Function to update footer totals based on visible rows
        function updateFooterTotals() {
            const table = document.getElementById('partesRutaListTable');
            if (!table) return;

            const rows = table.querySelectorAll('tbody tr');
            let totalLitros = 0;
            let totalEuros = 0;
            let totalCobrado = 0;
            let totalDeuda = 0;

            rows.forEach(row => {
                // Only sum if the row is visible
                if (row.style.display !== 'none') {
                    totalLitros += parseFloat(row.dataset.litros || 0);
                    totalEuros += parseFloat(row.dataset.euros || 0);
                    totalCobrado += parseFloat(row.dataset.cobrado || 0);
                    totalDeuda += parseFloat(row.dataset.deuda || 0);
                }
            });

            document.getElementById('footerTotalLitros').textContent = number_format(totalLitros, 2, ',', '.');
            document.getElementById('footerTotalEuros').textContent = number_format(totalEuros, 2, ',', '.') + '€';
            document.getElementById('footerTotalCobrado').textContent = number_format(totalCobrado, 2, ',', '.') + '€';
            const footerTotalDeudaSpan = document.getElementById('footerTotalDeuda').querySelector('span');
            footerTotalDeudaSpan.textContent = number_format(totalDeuda, 2, ',', '.') + '€';
            if (totalDeuda > 0) {
                footerTotalDeudaSpan.classList.add('text-danger');
            } else {
                footerTotalDeudaSpan.classList.remove('text-danger');
            }
        }


        document.addEventListener('DOMContentLoaded', () => {
            const sidebarCollapse = document.getElementById('sidebarCollapse');
            const sidebar = document.getElementById('sidebar');
            const content = document.getElementById('content');
            const addOrdersModal = new bootstrap.Modal(document.getElementById('addOrdersModal'));
            const pendingOrdersList = document.getElementById('pendingOrdersList');
            const loadingOrders = document.getElementById('loadingOrders');
            const filterFechaEntrega = document.getElementById('filterFechaEntrega');
            const searchOrderInput = document.getElementById('searchOrder');
            const addSelectedOrdersBtn = document.getElementById('addSelectedOrdersBtn');

            const parteRutaDisplaySection = document.getElementById('parteRutaDisplaySection'); // Detalles
            const parteRutaEditFormContainer = document.getElementById('parteRutaEditFormContainer'); // Contenedor del formulario de edición
            const parteRutaEditModeForm = document.getElementById('parteRutaEditModeForm'); // El FORMULARIO real


            // Get references to the button that opens the "add orders" modal for editing
            const addOrdersToEditFormBtn = document.getElementById('addOrdersToEditFormBtn');


            // Lógica para determinar qué sección mostrar y qué formulario gestionar
            if (parteRutaDisplaySection && parteRutaEditFormContainer && parteRutaEditModeForm && <?php echo json_encode($display_details_section); ?>) {
                // Estamos en la vista de detalles de un parte de ruta existente
                parteRutaDisplaySection.style.display = 'block';
                parteRutaEditFormContainer.style.display = 'none'; // Inicialmente oculto

                const editParteRutaBtn = document.getElementById('editParteRutaBtn');
                if (editParteRutaBtn) {
                    editParteRutaBtn.addEventListener('click', () => {
                        parteRutaDisplaySection.style.display = 'none';
                        parteRutaEditFormContainer.style.display = 'block';

                        // Clear selectedOrders and repopulate with initial data for editing
                        selectedOrders.clear();
                        <?php if (isset($initial_selected_orders_for_js) && !empty($initial_selected_orders_for_js)): ?>
                            const initialOrdersForEdit = <?php echo json_encode(array_values($initial_selected_orders_for_js)); ?>;
                            initialOrdersForEdit.forEach(order => {
                                selectedOrders.set(order.id_pedido, order);
                            });
                        <?php endif; ?>

                        // Get references to the edit form's elements
                        const editSelectedOrdersContainer = document.getElementById('editSelectedOrdersContainer');
                        const editPedidosSeleccionadosInput = document.getElementById('edit_pedidos_seleccionados');

                        // NEW DEBUG LOGS HERE
                        console.log('DEBUG (on edit button click): editSelectedOrdersContainer =', editSelectedOrdersContainer);
                        console.log('DEBUG (on edit button click): editPedidosSeleccionadosInput =', editPedidosSeleccionadosInput);


                        // IMPORTANT: Only call updateSelectedOrdersDisplay if elements are found
                        if (editSelectedOrdersContainer && editPedidosSeleccionadosInput) {
                            updateSelectedOrdersDisplay(editSelectedOrdersContainer, editPedidosSeleccionadosInput); // Actualizar la lista de pedidos seleccionados en el formulario de edición
                        } else {
                            console.error("DEBUG: Elementos del formulario de edición no encontrados al hacer clic en 'Editar Parte de Ruta'. No se actualizará la visualización de pedidos.");
                        }


                        // Set initial values for kilometers and duration in the edit form
                        document.getElementById('edit_total_kilometros').value = <?php echo json_encode($parte_ruta_details['total_kilometros'] ?? 0); ?>;
                        document.getElementById('edit_duracion_estimada_segundos').value = <?php echo json_encode($parte_ruta_details['duracion_estimada_segundos'] ?? 0); ?>;
                        // Set initial value for observations
                        document.getElementById('edit_observaciones').value = <?php echo json_encode($parte_ruta_details['observaciones'] ?? ''); ?>;


                        window.scrollTo({ top: 0, behavior: 'smooth' }); // Desplazar al inicio del formulario
                    });
                }

                const btnCancelarEditMode = document.getElementById('btnCancelarEditMode');
                if (btnCancelarEditMode) {
                    btnCancelarEditMode.addEventListener('click', () => {
                        parteRutaEditFormContainer.style.display = 'none';
                        parteRutaDisplaySection.style.display = 'block';
                        // Opcional: recargar la página para resetear el estado del formulario de edición
                        // location.reload();
                    });
                }

            } else {
                // Estamos en la vista de lista por defecto
                if (parteRutaDisplaySection) parteRutaDisplaySection.style.display = 'none'; // Asegurarse de que el de detalles esté oculto
                if (parteRutaEditFormContainer) parteRutaEditFormContainer.style.display = 'none'; // Asegurarse de que el de edición esté oculto
            }


            async function searchPendingOrders() {
                pendingOrdersList.innerHTML = '';
                loadingOrders.classList.remove('d-none');

                const fecha = filterFechaEntrega.value;
                const searchTerm = searchOrderInput.value;
                let queryString = '?action=get_pending_orders';
                if (fecha) {
                    queryString += `&fecha=${fecha}`;
                }
                if (searchTerm) {
                    queryString += `&search=${encodeURIComponent(searchTerm)}`;
                }

                try {
                    const response = await fetch(`partes_ruta.php${queryString}`);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const result = await response.json();

                    if (result.success) {
                        currentPendingOrders = result.pedidos;
                        displayFilteredOrders();
                    } else {
                        pendingOrdersList.innerHTML = `<p class="text-danger text-center">Error: ${result.message}</p>`;
                    }
                } catch (error) {
                    console.error('Error al buscar pedidos pendientes:', error);
                    pendingOrdersList.innerHTML = `<p class="text-danger text-center">Error al buscar pedidos pendientes: ${error.message}</p>`;
                } finally {
                    loadingOrders.classList.add('d-none');
                }
            }

            function displayFilteredOrders() {
                pendingOrdersList.innerHTML = '';
                const searchQuery = searchOrderInput.value.toLowerCase();
                const filteredOrders = currentPendingOrders.filter(order => {
                    const matchesSearch = order.nombre_cliente.toLowerCase().includes(searchQuery) ||
                                          order.id_pedido.toString().includes(searchQuery);
                    return matchesSearch;
                });


                if (filteredOrders.length === 0) {
                    pendingOrdersList.innerHTML = `<p class="text-center text-muted">No se encontraron pedidos pendientes con los criterios de búsqueda.</p>`;
                } else {
                    filteredOrders.forEach(order => {
                        const isSelected = selectedOrders.has(order.id_pedido);
                        let estadoServicioTexto = '';
                        let badgeClassServicio = '';

                        const totalUnidadesServidas = order.total_unidades_servidas ?? 0;
                        const totalCantidadFacturada = order.total_cantidad_facturada ?? 0;
                        const totalUnidades = order.total_unidades ?? 0;
                        const totalImporte = order.total_importe ?? 0;

                        if (order.id_factura_asociada === null) {
                            estadoServicioTexto = 'Pendiente (sin factura)';
                            badgeClassServicio = 'bg-pending-no-invoice';
                        } else if (totalUnidadesServidas == 0 && totalCantidadFacturada > 0) {
                            estadoServicioTexto = 'Pendiente (vía factura)';
                            badgeClassServicio = 'bg-pending-invoice';
                        } else if (totalUnidadesServidas > 0 && totalUnidadesServidas < totalCantidadFacturada) {
                            estadoServicioTexto = 'Parcialmente Servido';
                            badgeClassServicio = 'bg-served-partial';
                        } else if (totalUnidadesServidas > 0 && totalUnidadesServidas == totalCantidadFacturada && totalCantidadFacturada > 0) {
                            estadoServicioTexto = 'Servido Completo';
                            badgeClassServicio = 'bg-served-complete';
                        } else {
                            estadoServicioTexto = 'Estado Desconocido';
                            badgeClassServicio = 'bg-dark';
                        }

                        let estadoCobroTexto = 'N/A';
                        let badgeClassCobro = 'bg-secondary';
                        if (order.id_factura_asociada !== null) {
                            estadoCobroTexto = order.estado_pago || 'Pendiente';
                            switch (estadoCobroTexto) {
                                case 'pagada': badgeClassCobro = 'bg-pago-pagado'; break;
                                case 'parcialmente_pagada': badgeClassCobro = 'bg-pago-parcial'; break;
                                case 'anulado': badgeClassCobro = 'bg-pago-anulado'; break;
                                case 'pendiente':
                                default: badgeClassCobro = 'bg-pago-pendiente'; break;
                            }
                        }

                        // Determine the effective address for display in modal
                        let modal_display_address = '';
                        if (order.nombre_direccion_envio) {
                            modal_display_address = `Dirección de Envío: ${order.nombre_direccion_envio} (${order.direccion_envio}, ${order.ciudad_envio})`;
                        } else if (order.cliente_direccion_principal) {
                            modal_display_address = `Dirección Principal: ${order.cliente_direccion_principal}, ${order.cliente_ciudad_principal}`;
                        } else {
                            modal_display_address = 'Dirección no disponible';
                        }


                        const orderItem = document.createElement('label');
                        orderItem.classList.add('list-group-item', 'list-group-item-action', 'd-flex', 'justify-content-between', 'align-items-center', 'rounded', 'mb-1');
                        orderItem.style.cursor = 'pointer';
                        orderItem.innerHTML = `
                            <div class="form-check d-flex align-items-center w-100">
                                <input class="form-check-input me-3" type="checkbox" value="${order.id_pedido}" ${isSelected ? 'checked' : ''} data-order-details='${JSON.stringify(order)}'>
                                <div>
                                    <h6 class="mb-0">Pedido #${order.id_pedido} - ${order.nombre_cliente}</h6>
                                    <small class="text-muted">Fecha: ${new Date(order.fecha_pedido).toLocaleDateString('es-ES')}</small><br>
                                    <small class="text-muted">${modal_display_address}</small> <!-- Display address in modal list -->
                                    <div class="d-flex mt-1">
                                        <span class="badge bg-info me-2"><i class="bi bi-box"></i> Unidades: ${parseFloat(totalUnidades).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-currency-euro"></i> Importe: ${parseFloat(totalImporte).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}€</span>
                                        <span class="badge ${badgeClassServicio} ms-2">${estadoServicioTexto}</span>
                                        <span class="badge ${badgeClassCobro} ms-2">Cobro: ${estadoCobroTexto}</span>
                                    </div>
                                </div>
                            </div>
                        `;
                        pendingOrdersList.appendChild(orderItem);
                    });
                }
            }

            // Manejo del formulario de edición
            if (parteRutaEditModeForm) {
                parteRutaEditModeForm.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    const formData = new FormData(parteRutaEditModeForm); // Usar el formulario de edición
                    formData.set('pedidos_seleccionados', JSON.stringify(Array.from(selectedOrders.keys())));

                    // Add current calculated distance and duration to form data
                    formData.set('total_kilometros', document.getElementById('edit_total_kilometros').value);
                    formData.set('duracion_estimada_segundos', document.getElementById('edit_duracion_estimada_segundos').value);


                    console.log('Submitting edit form. Selected Orders:', Array.from(selectedOrders.keys()));
                    console.log('Submitting edit form. Kilometers:', formData.get('total_kilometros'));
                    console.log('Submitting edit form. Duration (seconds):', formData.get('duracion_estimada_segundos'));

                    // New log: Inspect all form data before sending
                    console.log('Form data before fetch:');
                    for (let [key, value] of formData.entries()) {
                        console.log(`${key}: ${value}`);
                    }


                    try {
                        const response = await fetch('partes_ruta.php', {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        const result = await response.json();

                        if (result.success) {
                            console.log("Edit form submission successful. ID Parte Ruta:", result.id_parte_ruta); // Debug log
                            showCustomModal("Éxito", result.message, "success");
                            setTimeout(() => {
                                // Redirigir a la vista de detalles del parte editado
                                window.location.href = `partes_ruta.php?view_id=${result.id_parte_ruta}`;
                            }, 1500);
                        } else {
                            console.error("Edit form submission failed:", result.message); // Debug log
                            showCustomModal("Error", result.message, "danger");
                        }
                    }
                    catch (error) {
                        console.error('Error al guardar los cambios del parte de ruta:', error);
                        showCustomModal("Error", "Error al guardar los cambios del parte de ruta: " + error.message, "danger");
                    }
                });
            }


            filterFechaEntrega.addEventListener('change', searchPendingOrders);
            searchOrderInput.addEventListener('input', searchPendingOrders);

            // Modified listener for addOrdersModal shown event
            addOrdersModal._element.addEventListener('shown.bs.modal', () => {
                // Blur the element that triggered this modal to prevent aria-hidden issues
                if (document.activeElement === addOrdersToEditFormBtn) {
                     document.activeElement.blur();
                }
                searchPendingOrders(); // Call the search function after blur
            });


            addSelectedOrdersBtn.addEventListener('click', () => {
                pendingOrdersList.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
                    const orderId = parseInt(checkbox.value);
                    const orderDetails = JSON.parse(checkbox.dataset.orderDetails);
                    if (!selectedOrders.has(orderId)) {
                        selectedOrders.set(orderId, orderDetails);
                    }
                });
                // Determine which form is currently active to update its display
                // CORRECTED: Use getElementById for direct access
                const currentSelectedOrdersContainer = document.getElementById('editSelectedOrdersContainer');
                const currentPedidosSeleccionadosInput = document.getElementById('edit_pedidos_seleccionados');

                // NEW DEBUG LOGS HERE for the addSelectedOrdersBtn click
                console.log('DEBUG (on addSelectedOrdersBtn click): editSelectedOrdersContainer =', currentSelectedOrdersContainer);
                console.log('DEBUG (on addSelectedOrdersBtn click): editPedidosSeleccionadosInput =', currentPedidosSeleccionadosInput);


                // IMPORTANT: Only call updateSelectedOrdersDisplay if elements are found
                if (currentSelectedOrdersContainer && currentPedidosSeleccionadosInput) {
                    updateSelectedOrdersDisplay(currentSelectedOrdersContainer, currentPedidosSeleccionadosInput);
                } else {
                    console.error("DEBUG: Elementos del formulario de edición no encontrados al añadir pedidos. No se actualizará la visualización de pedidos.");
                }

                // Blur the button to remove focus before hiding the modal
                addSelectedOrdersBtn.blur();

                addOrdersModal.hide();
            });

            const parteRutaDetailsSection = document.getElementById('parteRutaDisplaySection');
            if (parteRutaDetailsSection) {
                parteRutaDetailsSection.addEventListener('click', (event) => {
                    if (event.target.classList.contains('update-pedido-status-btn')) {
                        const idParteRutaPedido = event.target.dataset.idParteRutaPedido;
                        if (idParteRutaPedido) {
                            window.updatePedidoRutaStatus(idParteRutaPedido);
                        }
                    }
                });
            }

            document.querySelectorAll('.view-map-btn').forEach(button => {
                button.addEventListener('click', async function() {
                    const idParteRuta = this.dataset.idParte;
                    const rutaNombre = this.dataset.rutaNombre;

                    document.getElementById('mapModalLabel').innerHTML = `Ruta en Mapa: <span id="mapRutaNombre">${rutaNombre}</span>`;

                    const mapModal = new bootstrap.Modal(document.getElementById('mapModal'));
                    mapModal.show();

                    // Store the button that triggered the modal
                    const triggeredButton = this;

                    document.getElementById('mapModal').addEventListener('shown.bs.modal', async () => {
                        // Blur the element that triggered this modal to prevent aria-hidden issues
                        if (document.activeElement === triggeredButton) {
                             document.activeElement.blur();
                        }
                        try {
                            const responseMap = await fetch(`partes_ruta.php?action=get_pedidos_by_parte_ruta&id_parte_ruta=${idParteRuta}`);
                            const dataMap = await responseMap.json();

                            if (dataMap.success) {
                                calculateAndDisplayRoute(dataMap.pedidos, true);
                            } else {
                                console.error('Error al obtener pedidos para el mapa:', dataMap.message);
                                const mapDiv = document.getElementById('map');
                                const mapInfoDiv = document.getElementById('map_info');
                                mapInfoDiv.textContent = `No se pudieron cargar los pedidos para la ruta: ${dataMap.message}`;
                                mapInfoDiv.classList.remove('alert-info');
                                mapInfoDiv.classList.add('alert-warning');
                                mapInfoDiv.style.display = 'block';
                                mapDiv.innerHTML = '';
                            }

                            await loadPedidosDetailsForMap(idParteRuta);

                        } catch (error) {
                            console.error('Error en la llamada API para pedidos del mapa o detalles:', error);
                            const mapDiv = document.getElementById('map');
                            const mapInfoDiv = document.getElementById('map_info');
                            mapInfoDiv.textContent = 'Error de conexión al cargar los pedidos del mapa o sus detalles.';
                            mapInfoDiv.classList.remove('alert-info');
                            mapInfoDiv.classList.add('alert-danger');
                            mapInfoDiv.style.display = 'block';
                            mapDiv.innerHTML = '';
                        }
                    }, { once: true });
                });
            });

            const recalculateRouteBtn = document.getElementById('recalculateRouteBtn');
            if (recalculateRouteBtn) {
                recalculateRouteBtn.addEventListener('click', recalculateRoute);
            }

            const printPdfBtn = document.getElementById('printPdfBtn');
            if (printPdfBtn) {
                printPdfBtn.addEventListener('click', function() {
                    const currentIdParteRuta = document.querySelector('.view-map-btn[data-id-parte]:focus')?.dataset.idParte;

                    if (currentIdParteRuta) {
                        generateParteRutaPdf(currentIdParteRuta);
                    } else {
                        const urlParams = new URLSearchParams(window.location.search);
                        const viewId = urlParams.get('view_id');
                        if (viewId) {
                            generateParteRutaPdf(viewId);
                        } else {
                            showCustomModal("Error", "No se pudo determinar el ID del Parte de Ruta para imprimir.", "danger");
                        }
                    }
                });
            }


            document.querySelectorAll('.delete-parte-btn').forEach(button => {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    const idParteRutaToDelete = this.dataset.id;
                    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

                    // Use the custom modal for confirmation
                    showCustomModal("Confirmar Eliminación", "¿Estás seguro de que deseas eliminar este parte de ruta? Se desvincularán los pedidos asociados.", "confirm", (confirmed) => {
                        if (confirmed) {
                            window.location.href = `partes_ruta.php?action=delete&id=${idParteRutaToDelete}`;
                        }
                    });
                });
            });

            document.getElementById('copyUrlBtn').addEventListener('click', function() {
                const googleMapsUrlInput = document.getElementById('googleMapsUrlInput');
                googleMapsUrlInput.select();
                document.execCommand('copy');
                const originalText = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check-lg"></i> Copiado!';
                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 2000);
            });

            document.getElementById('whatsappShareBtn').addEventListener('click', function(event) {
                event.preventDefault();
                if (currentGoogleMapsUrl) {
                    const message = `Hola, aquí tienes la ruta de entrega optimizada para hoy: ${currentGoogleMapsUrl}`;
                    const whatsappUrl = `https://wa.me/?text=${encodeURIComponent(message)}`;
                    window.open(whatsappUrl, '_blank');
                } else {
                    console.warn('No hay URL de Google Maps para compartir.');
                }
            });

            // Manejar el cambio de tipo de entrega en el modal de edición de pedido
            const editParteTipoEntregaSelect = document.getElementById('edit_parte_tipo_entrega');
            if (editParteTipoEntregaSelect) {
                editParteTipoEntregaSelect.addEventListener('change', function() {
                    const editParteEmpresaTransporteGroup = document.getElementById('editParteEmpresaTransporteGroup');
                    const editParteNumeroSeguimientoGroup = document.getElementById('editParteNumeroSeguimientoGroup');
                    const editParteEmpresaTransporteInput = document.getElementById('edit_parte_empresa_transporte');
                    const editParteNumeroSeguimientoInput = document.getElementById('edit_parte_numero_seguimiento');
                    const editParteIdRutaSelect = document.getElementById('edit_parte_id_ruta');

                    if (this.value === 'envio_paqueteria') {
                        editParteEmpresaTransporteGroup.style.display = 'block';
                        editParteNumeroSeguimientoGroup.style.display = 'block';
                        // Keep current values if they exist, otherwise empty
                        editParteEmpresaTransporteInput.value = editParteEmpresaTransporteInput.value || '';
                        editParteNumeroSeguimientoInput.value = editParteNumeroSeguimientoInput.value || '';
                        editParteIdRutaSelect.removeAttribute('required'); // Ruta no es obligatoria para paquetería
                        editParteIdRutaSelect.value = ''; // Limpiar ruta seleccionada
                    } else { // reparto_propio
                        editParteEmpresaTransporteGroup.style.display = 'none';
                        editParteNumeroSeguimientoGroup.style.display = 'none';
                        editParteEmpresaTransporteInput.value = '';
                        editParteNumeroSeguimientoInput.value = '';
                        editParteIdRutaSelect.setAttribute('required', 'required'); // Ruta es obligatoria para reparto propio
                    }
                });
            }

            // JavaScript for filtering the table by status
            const partesRutaListTable = document.getElementById('partesRutaListTable');
            const filterPendingBtn = document.getElementById('filterPendingBtn');
            const filterCompletedBtn = document.getElementById('filterCompletedBtn'); // New button
            const showAllBtn = document.getElementById('showAllBtn');

            if (filterPendingBtn && filterCompletedBtn && showAllBtn && partesRutaListTable) {
                filterPendingBtn.addEventListener('click', () => {
                    const rows = partesRutaListTable.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        const estado = row.dataset.estado;
                        if (estado === 'Pendiente' || estado === 'En Curso') { // Consider 'En Curso' as pending for filtering
                            row.style.display = ''; // Show
                        } else {
                            row.style.display = 'none'; // Hide
                        }
                    });
                    updateFooterTotals(); // Update totals after filtering
                });

                // New event listener for "Ver Completados" button
                filterCompletedBtn.addEventListener('click', () => {
                    const rows = partesRutaListTable.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        const estado = row.dataset.estado;
                        if (estado === 'Completado') {
                            row.style.display = ''; // Show
                        } else {
                            row.style.display = 'none'; // Hide
                        }
                    });
                    updateFooterTotals(); // Update totals after filtering
                });

                showAllBtn.addEventListener('click', () => {
                    const rows = partesRutaListTable.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        row.style.display = ''; // Show all
                    });
                    updateFooterTotals(); // Update totals after showing all
                });

                // Initial call to update totals when the page loads
                updateFooterTotals();
            }
        });
    </script>
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAwQDkg4J9qi7toUQ6eSVjul8HKTYoRzz8&libraries=marker,routes&callback=initMap" async defer loading="async"></script>
</body>
</html>
