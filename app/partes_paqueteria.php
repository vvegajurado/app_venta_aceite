<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Incluir el archivo de verificación de autenticación al inicio de cada página protegida
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

// --- Lógica para procesar el formulario de añadir/eliminar partes de paquetería o pedidos asociados ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // Asegurarse de que la respuesta sea JSON si es una solicitud AJAX
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
    }

    $in_transaction = false;
    // Iniciar transacción si la acción no es solo para obtener datos
    if ($accion !== 'get_available_orders_for_paqueteria' && $accion !== 'search_clients_for_orders') {
        $pdo->beginTransaction();
        $in_transaction = true;
    }

    try {
        switch ($accion) {
            case 'agregar_parte_paqueteria':
                $fecha_recogida = $_POST['fecha_recogida'] ?? date('Y-m-d');
                $empresa_transporte = trim($_POST['empresa_transporte'] ?? '');
                $observaciones = trim($_POST['observaciones'] ?? '');
                $selected_orders_ids = isset($_POST['selected_orders_ids']) ? explode(',', $_POST['selected_orders_ids']) : [];

                if (empty($empresa_transporte)) {
                    throw new Exception("Debe especificar la empresa de transporte.");
                }

                // Determinar el estado inicial del parte
                $estado_parte_inicial = empty($selected_orders_ids) ? 'creado' : 'en_transito';

                // 1. Insertar el nuevo parte de paquetería
                $stmt = $pdo->prepare("INSERT INTO partes_paqueteria (fecha_recogida, empresa_transporte, observaciones, estado_parte) VALUES (?, ?, ?, ?)");
                $stmt->execute([$fecha_recogida, $empresa_transporte, $observaciones, $estado_parte_inicial]);
                $id_parte_paqueteria_nuevo = $pdo->lastInsertId();

                // 2. Asociar los pedidos seleccionados al parte y actualizar su estado
                if (!empty($selected_orders_ids)) {
                    $stmt_insert_pedido_parte = $pdo->prepare("INSERT INTO partes_paqueteria_pedidos (id_parte_paqueteria, id_pedido) VALUES (?, ?)");
                    $stmt_update_pedido_status = $pdo->prepare("UPDATE pedidos SET estado_pedido = 'en_transito_paqueteria', id_parte_paqueteria_asociada = ? WHERE id_pedido = ? AND tipo_entrega = 'envio_paqueteria'");

                    foreach ($selected_orders_ids as $id_pedido) {
                        if (!empty($id_pedido)) {
                            $stmt_insert_pedido_parte->execute([$id_parte_paqueteria_nuevo, $id_pedido]);
                            $stmt_update_pedido_status->execute([$id_parte_paqueteria_nuevo, $id_pedido]);
                        }
                    }
                }

                if ($in_transaction) $pdo->commit();
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    echo json_encode(['success' => true, 'message' => 'Parte de paquetería creado con éxito. ID: ' . $id_parte_paqueteria_nuevo, 'id_parte' => $id_parte_paqueteria_nuevo]);
                } else {
                    mostrarMensaje("Parte de paquetería creado con éxito. ID: " . htmlspecialchars($id_parte_paqueteria_nuevo), "success");
                    header("Location: partes_paqueteria.php?view=details&id=" . htmlspecialchars($id_parte_paqueteria_nuevo));
                    exit();
                }
                break;

            case 'eliminar_parte_paqueteria':
                $id_parte_paqueteria = $_POST['id_parte_paqueteria'];

                // 1. Revertir el estado de los pedidos asociados
                $stmt_get_pedidos = $pdo->prepare("SELECT id_pedido FROM partes_paqueteria_pedidos WHERE id_parte_paqueteria = ?");
                $stmt_get_pedidos->execute([$id_parte_paqueteria]);
                $pedidos_asociados_to_revert = $stmt_get_pedidos->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($pedidos_asociados_to_revert)) {
                    $placeholders = implode(',', array_fill(0, count($pedidos_asociados_to_revert), '?'));
                    $stmt_revert_pedidos = $pdo->prepare("UPDATE pedidos SET estado_pedido = 'pendiente', id_parte_paqueteria_asociada = NULL WHERE id_pedido IN ($placeholders)");
                    $stmt_revert_pedidos->execute($pedidos_asociados_to_revert);
                }

                // 2. Eliminar las asociaciones de pedidos
                $stmt_delete_pedidos_parte = $pdo->prepare("DELETE FROM partes_paqueteria_pedidos WHERE id_parte_paqueteria = ?");
                $stmt_delete_pedidos_parte->execute([$id_parte_paqueteria]);

                // 3. Eliminar el parte de paquetería
                $stmt_delete_parte = $pdo->prepare("DELETE FROM partes_paqueteria WHERE id_parte_paqueteria = ?");
                $stmt_delete_parte->execute([$id_parte_paqueteria]);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Parte de paquetería eliminado con éxito y pedidos revertidos.", "success");
                header("Location: partes_paqueteria.php");
                exit();
                break;

            case 'eliminar_pedido_de_parte':
                $id_parte_paqueteria = $_POST['id_parte_paqueteria'];
                $id_pedido = $_POST['id_pedido'];

                // 1. Revertir el estado del pedido
                $stmt_revert_pedido = $pdo->prepare("UPDATE pedidos SET estado_pedido = 'pendiente', id_parte_paqueteria_asociada = NULL WHERE id_pedido = ?");
                $stmt_revert_pedido->execute([$id_pedido]);

                // 2. Eliminar la asociación del pedido con el parte
                $stmt_delete_association = $pdo->prepare("DELETE FROM partes_paqueteria_pedidos WHERE id_parte_paqueteria = ? AND id_pedido = ?");
                $stmt_delete_association->execute([$id_parte_paqueteria, $id_pedido]);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Pedido desvinculado del parte de paquetería con éxito.", "success");
                header("Location: partes_paqueteria.php?view=details&id=" . htmlspecialchars($id_parte_paqueteria));
                exit();
                break;

            case 'get_available_orders_for_paqueteria':
                ob_clean(); // Limpiar cualquier búfer de salida anterior
                header('Content-Type: application/json');

                $search_term = $_POST['search_term'] ?? '';
                $current_parte_id = $_POST['current_parte_id'] ?? null; // ID del parte actual si se está editando

                $sql = "
                    SELECT
                        p.id_pedido,
                        p.fecha_pedido,
                        c.nombre_cliente,
                        p.total_pedido_iva_incluido,
                        p.estado_pedido
                    FROM
                        pedidos p
                    JOIN
                        clientes c ON p.id_cliente = c.id_cliente
                    WHERE
                        p.tipo_entrega = 'envio_paqueteria'
                        AND (p.estado_pedido = 'pendiente' OR p.estado_pedido = 'en_transito_paqueteria')
                ";
                $params = [];

                if (!empty($search_term)) {
                    $sql .= " AND (c.nombre_cliente LIKE ? OR p.id_pedido LIKE ?)";
                    $params[] = '%' . $search_term . '%';
                    $params[] = '%' . $search_term . '%';
                }

                // Si estamos editando un parte, también queremos mostrar los pedidos que ya están asociados a este parte
                if ($current_parte_id) {
                    $sql .= " OR p.id_parte_paqueteria_asociada = ?";
                    $params[] = $current_parte_id;
                }

                $sql .= " ORDER BY p.fecha_pedido DESC, p.id_pedido DESC LIMIT 20";

                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'orders' => $orders]);
                } catch (PDOException $e) {
                    error_log("Error al obtener pedidos disponibles para paquetería (AJAX): " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Error de base de datos al buscar pedidos.']);
                }
                exit();
                break;

            case 'add_orders_to_parte':
                $id_parte_paqueteria = $_POST['id_parte_paqueteria'];
                $orders_to_add_ids = isset($_POST['orders_to_add_ids']) ? explode(',', $_POST['orders_to_add_ids']) : [];

                if (empty($orders_to_add_ids)) {
                    throw new Exception("No se seleccionaron pedidos para añadir.");
                }

                $stmt_insert_pedido_parte = $pdo->prepare("INSERT INTO partes_paqueteria_pedidos (id_parte_paqueteria, id_pedido) VALUES (?, ?)");
                $stmt_update_pedido_status = $pdo->prepare("UPDATE pedidos SET estado_pedido = 'en_transito_paqueteria', id_parte_paqueteria_asociada = ? WHERE id_pedido = ? AND tipo_entrega = 'envio_paqueteria'");
                // Actualizar el estado del parte a 'en_transito' si se añaden pedidos y no estaba ya finalizado
                $stmt_update_parte_status = $pdo->prepare("UPDATE partes_paqueteria SET estado_parte = 'en_transito' WHERE id_parte_paqueteria = ? AND estado_parte != 'finalizado'");


                foreach ($orders_to_add_ids as $id_pedido) {
                    if (!empty($id_pedido)) {
                        // Verificar si el pedido ya está asociado a este parte para evitar duplicados
                        $stmt_check_existing = $pdo->prepare("SELECT COUNT(*) FROM partes_paqueteria_pedidos WHERE id_parte_paqueteria = ? AND id_pedido = ?");
                        $stmt_check_existing->execute([$id_parte_paqueteria, $id_pedido]);
                        if ($stmt_check_existing->fetchColumn() == 0) {
                            $stmt_insert_pedido_parte->execute([$id_parte_paqueteria, $id_pedido]);
                            $stmt_update_pedido_status->execute([$id_parte_paqueteria, $id_pedido]);
                        }
                    }
                }
                $stmt_update_parte_status->execute([$id_parte_paqueteria]);


                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Pedidos añadidos al parte de paquetería con éxito.", "success");
                header("Location: partes_paqueteria.php?view=details&id=" . htmlspecialchars($id_parte_paqueteria));
                exit();
                break;

            case 'finalizar_parte_paqueteria':
                $id_parte_paqueteria = $_POST['id_parte_paqueteria'];

                // 1. Actualizar el estado del parte de paquetería a 'finalizado'
                $stmt_update_parte = $pdo->prepare("UPDATE partes_paqueteria SET estado_parte = 'finalizado' WHERE id_parte_paqueteria = ?");
                $stmt_update_parte->execute([$id_parte_paqueteria]);

                // 2. Obtener los IDs de los pedidos asociados a este parte
                $stmt_get_pedidos_parte = $pdo->prepare("SELECT id_pedido FROM partes_paqueteria_pedidos WHERE id_parte_paqueteria = ?");
                $stmt_get_pedidos_parte->execute([$id_parte_paqueteria]);
                $pedidos_a_completar = $stmt_get_pedidos_parte->fetchAll(PDO::FETCH_COLUMN);

                // 3. Actualizar el estado de los pedidos asociados a 'completado'
                if (!empty($pedidos_a_completar)) {
                    $placeholders = implode(',', array_fill(0, count($pedidos_a_completar), '?'));
                    $stmt_update_pedidos_completado = $pdo->prepare("UPDATE pedidos SET estado_pedido = 'completado' WHERE id_pedido IN ($placeholders)");
                    $stmt_update_pedidos_completado->execute($pedidos_a_completar);
                }

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Parte de paquetería finalizado con éxito y pedidos asociados completados.", "success");
                header("Location: partes_paqueteria.php?view=details&id=" . htmlspecialchars($id_parte_paqueteria));
                exit();
                break;

            default:
                throw new Exception("Acción no reconocida.");
        }
    } catch (Exception $e) {
        if ($in_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Para solicitudes AJAX, devolver JSON con error
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
            exit();
        } else {
            // Para solicitudes POST normales, establecer mensaje y redirigir
            mostrarMensaje("Error: " . $e->getMessage(), "danger");
            $redirect_id = $_POST['id_parte_paqueteria'] ?? null;
            if ($redirect_id) {
                header("Location: partes_paqueteria.php?view=details&id=" . htmlspecialchars($redirect_id)); // Redirigir a la vista de detalles del parte
            } else {
                header("Location: partes_paqueteria.php");
            }
            exit();
        }
    }
}

// Cargar mensajes de la sesión si existen
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    $tipo_mensaje = $_SESSION['tipo_mensaje'];
    unset($_SESSION['mensaje']);
    unset($_SESSION['tipo_mensaje']);
}

// --- Lógica para CARGAR DATOS EN LA VISTA ---
$view = $_GET['view'] ?? 'list';
$partes_paqueteria = [];
$parte_actual = null;
$pedidos_asociados = [];
$total_pedidos_iva_incluido = 0;
$total_facturado_general = 0;
$total_cobrado_general = 0;

if ($view == 'list') {
    $filter_estado = $_GET['estado_parte_filter'] ?? 'todos'; // Nuevo filtro de estado

    try {
        $sql_partes = "
            SELECT
                pp.*,
                COUNT(ppp.id_pedido) AS total_pedidos_asociados
            FROM
                partes_paqueteria pp
            LEFT JOIN
                partes_paqueteria_pedidos ppp ON pp.id_parte_paqueteria = ppp.id_parte_paqueteria
        ";
        $params_partes = [];

        if ($filter_estado !== 'todos') {
            $sql_partes .= " WHERE pp.estado_parte = ?";
            $params_partes[] = $filter_estado;
        }

        $sql_partes .= "
            GROUP BY
                pp.id_parte_paqueteria
            ORDER BY
                pp.fecha_recogida DESC, pp.id_parte_paqueteria DESC
        ";

        $stmt_partes = $pdo->prepare($sql_partes);
        $stmt_partes->execute($params_partes);
        $partes_paqueteria = $stmt_partes->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos al cargar partes de paquetería: " . $e->getMessage(), "danger");
    }
} elseif ($view == 'details' && isset($_GET['id'])) {
    $id_parte = $_GET['id'];
    try {
        // Obtener el parte actual, incluyendo el nuevo campo estado_parte
        $stmt_parte_actual = $pdo->prepare("SELECT * FROM partes_paqueteria WHERE id_parte_paqueteria = ?");
        $stmt_parte_actual->execute([$id_parte]);
        $parte_actual = $stmt_parte_actual->fetch(PDO::FETCH_ASSOC);

        if (!$parte_actual) {
            mostrarMensaje("Parte de paquetería no encontrado.", "danger");
            header("Location: partes_paqueteria.php");
            exit();
        }

        // Modificación de la consulta para incluir facturas y cobros
        $stmt_pedidos_asociados = $pdo->prepare("
            SELECT
                p.id_pedido,
                p.fecha_pedido,
                c.nombre_cliente,
                p.total_pedido_iva_incluido,
                p.estado_pedido,
                p.numero_seguimiento,
                fv.id_factura,
                fv.fecha_factura,
                fv.total_factura_iva_incluido AS total_facturado,
                fv.estado_pago AS estado_pago_factura,
                COALESCE(SUM(cf.cantidad_cobrada), 0) AS total_cobrado
            FROM
                partes_paqueteria_pedidos ppp
            JOIN
                pedidos p ON ppp.id_pedido = p.id_pedido
            JOIN
                clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN
                facturas_ventas fv ON p.id_factura_asociada = fv.id_factura
            LEFT JOIN
                cobros_factura cf ON fv.id_factura = cf.id_factura
            WHERE
                ppp.id_parte_paqueteria = ?
            GROUP BY
                p.id_pedido, p.fecha_pedido, c.nombre_cliente, p.total_pedido_iva_incluido, p.estado_pedido, p.numero_seguimiento, fv.id_factura, fv.fecha_factura, fv.total_factura_iva_incluido, fv.estado_pago
            ORDER BY
                p.fecha_pedido DESC, p.id_pedido DESC
        ");
        $stmt_pedidos_asociados->execute([$id_parte]);
        $pedidos_asociados = $stmt_pedidos_asociados->fetchAll(PDO::FETCH_ASSOC);

        // Calcular totales
        foreach ($pedidos_asociados as $pedido) {
            $total_pedidos_iva_incluido += $pedido['total_pedido_iva_incluido'];
            $total_facturado_general += ($pedido['total_facturado'] ?? 0);
            $total_cobrado_general += ($pedido['total_cobrado'] ?? 0);
        }

    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos al cargar detalles del parte de paquetería: " . $e->getMessage(), "danger");
        header("Location: partes_paqueteria.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Partes de Paquetería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Estilos generales */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
        }
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        .content {
            flex-grow: 1;
            padding: 20px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        /* COLORES ESPECÍFICOS PARA PARTES DE PAQUETERÍA */
        .card-header {
            background-color: #6f42c1; /* Púrpura para partes de paquetería */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #6f42c1; /* Púrpura para botones primarios */
            border-color: #6f42c1;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background-color: #5935a1; /* Púrpura más oscuro al pasar el ratón */
            border-color: #5935a1;
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
        .table thead {
            background-color: #e9ecef;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .form-control, .form-select {
            border-radius: 8px;
        }
        .modal-content {
            border-radius: 15px;
        }
        .modal-header {
            background-color: #6f42c1; /* Púrpura para encabezados de modal */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .alert {
            border-radius: 8px;
        }

        /* Estilos del Sidebar (copia de sidebar.php) */
        :root {
            --sidebar-width: 250px;
            --primary-color: #007bff; /* Un azul más estándar de Bootstrap */
            --sidebar-bg: #343a40; /* Fondo oscuro */
            --sidebar-link: #adb5bd; /* Gris claro para enlaces */
            --sidebar-hover: #495057; /* Gris un poco más oscuro al pasar el ratón */
            --sidebar-active: #0056b3; /* Azul más oscuro para el elemento activo */
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            flex-shrink: 0; /* Evita que el sidebar se encoja */
            height: 100vh; /* Asegura que el sidebar ocupe toda la altura de la ventana */
            position: sticky; /* Hace que el sidebar se mantenga visible al hacer scroll */
            top: 0; /* Alinea el sidebar con la parte superior de la ventana */
            overflow-y: auto; /* Permite el scroll si el contenido del sidebar es demasiado largo */
        }

        .sidebar-header {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header .app-logo {
            max-width: 100px; /* Adjust as needed */
            height: auto;
            margin-bottom: 10px;
        }

        .sidebar-header .app-icon {
            font-size: 3rem;
            color: #fff;
            margin-bottom: 10px;
        }

        .sidebar-header .app-name {
            color: #fff;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            display: flex;
            flex-direction: column;
            height: 100%; /* Asegura que la lista ocupe toda la altura disponible */
        }

        .sidebar-menu-item {
            margin-bottom: 5px;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            color: var(--sidebar-link);
            text-decoration: none;
            border-radius: 8px;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .sidebar-menu-link i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .sidebar-menu-link:hover {
            background-color: var(--sidebar-hover);
            color: white;
        }

        .sidebar-menu-link.active {
            background-color: var(--sidebar-active);
            color: white;
            font-weight: bold;
        }

        /* Estilos adicionales para los menús desplegables */
        .sidebar-submenu-list {
            padding-left: 0;
            margin-top: 5px;
            margin-bottom: 0;
            list-style: none;
        }

        .sidebar-submenu-item {
            margin-bottom: 2px;
        }

        .sidebar-submenu-link {
            display: block;
            padding: 8px 15px 8px 55px; /* Ajuste para la indentación */
            color: var(--sidebar-link);
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
            font-size: 0.95rem;
            border-left: 5px solid transparent; /* Para la línea activa del submenú */
        }

        .sidebar-submenu-link:hover, .sidebar-submenu-link.active {
            background-color: var(--sidebar-hover);
            color: white;
            border-left-color: var(--primary-color);
        }

        /* Ajuste del icono de flecha del colapsable */
        .sidebar-menu-link .sidebar-collapse-icon {
            transition: transform 0.3s ease;
        }
        .sidebar-menu-link[aria-expanded="true"] .sidebar-collapse-icon {
            transform: rotate(180deg);
        }

        /* Estilo para empujar el elemento de cerrar sesión al final */
        .sidebar-menu-item.mt-auto {
            margin-top: auto !important; /* Empuja este elemento al final de la flexbox */
            border-top: 1px solid rgba(255, 255, 255, 0.1); /* Separador visual */
            padding-top: 10px;
        }

        /* Custom style for scrollable table */
        .scrollable-table-container {
            max-height: 500px; /* Adjust as needed, e.g., 500px for about 15 rows */
            overflow-y: auto;
            border: 1px solid #e9ecef; /* Optional: add a border to the scrollable area */
            border-radius: 8px;
        }
        .scrollable-table-container table {
            margin-bottom: 0; /* Remove default table margin if inside a scrollable div */
        }

        /* Styles for order selection in modal */
        #availableOrdersList {
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
        #selectedOrdersContainer {
            border: 1px solid #ced4da;
            border-radius: 8px;
            min-height: 50px;
            padding: 10px;
            background-color: #e9ecef;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .selected-order-tag {
            background-color: #6f42c1;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.85em;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .selected-order-tag .remove-tag {
            cursor: pointer;
            font-weight: bold;
            font-size: 1.1em;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluir la barra lateral ?>
        <div class="content">
            <div class="container-fluid">
                <h1 class="mb-4">Gestión de Partes de Paquetería</h1>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($view == 'list'): ?>
                    <div class="card">
                        <div class="card-header">
                            Lista de Partes de Paquetería
                        </div>
                        <div class="card-body">
                            <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addParteModal">
                                <i class="bi bi-plus-circle"></i> Nuevo Parte de Paquetería
                            </button>

                            <div class="mb-3">
                                <label for="estadoParteFilter" class="form-label">Filtrar por Estado:</label>
                                <select class="form-select" id="estadoParteFilter" onchange="window.location.href = 'partes_paqueteria.php?view=list&estado_parte_filter=' + this.value;">
                                    <option value="todos" <?php echo ($filter_estado == 'todos') ? 'selected' : ''; ?>>Todos</option>
                                    <option value="creado" <?php echo ($filter_estado == 'creado') ? 'selected' : ''; ?>>Creado</option>
                                    <option value="en_transito" <?php echo ($filter_estado == 'en_transito') ? 'selected' : ''; ?>>En Tránsito</option>
                                    <option value="finalizado" <?php echo ($filter_estado == 'finalizado') ? 'selected' : ''; ?>>Finalizado</option>
                                </select>
                            </div>

                            <div class="table-responsive scrollable-table-container">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID Parte</th>
                                            <th>Fecha Recogida</th>
                                            <th>Empresa Transporte</th>
                                            <th>Observaciones</th>
                                            <th>Estado Parte</th> <!-- Nueva columna para el estado -->
                                            <th class="text-end">Pedidos Asociados</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($partes_paqueteria)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center">No hay partes de paquetería registrados con el filtro actual.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($partes_paqueteria as $parte):
                                                $badge_class_parte = '';
                                                switch ($parte['estado_parte']) {
                                                    case 'creado': $badge_class_parte = 'bg-secondary'; break;
                                                    case 'en_transito': $badge_class_parte = 'bg-info'; break;
                                                    case 'finalizado': $badge_class_parte = 'bg-success'; break;
                                                    default: $badge_class_parte = 'bg-secondary'; break;
                                                }
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($parte['id_parte_paqueteria']); ?></td>
                                                    <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($parte['fecha_recogida']))); ?></td>
                                                    <td><?php echo htmlspecialchars($parte['empresa_transporte']); ?></td>
                                                    <td><?php echo htmlspecialchars($parte['observaciones'] ?: 'N/A'); ?></td>
                                                    <td><span class="badge <?php echo htmlspecialchars($badge_class_parte); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $parte['estado_parte']))); ?></span></td>
                                                    <td class="text-end"><?php echo htmlspecialchars($parte['total_pedidos_asociados']); ?></td>
                                                    <td class="text-center">
                                                        <a href="partes_paqueteria.php?view=details&id=<?php echo htmlspecialchars($parte['id_parte_paqueteria']); ?>" class="btn btn-info btn-sm me-1" title="Ver Detalles">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <button class="btn btn-danger btn-sm" onclick="confirmDeleteParte(<?php echo htmlspecialchars($parte['id_parte_paqueteria']); ?>)" title="Eliminar Parte">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Añadir Nuevo Parte de Paquetería -->
                    <div class="modal fade" id="addParteModal" tabindex="-1" aria-labelledby="addParteModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form action="partes_paqueteria.php" method="POST" id="addParteForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addParteModalLabel">Nuevo Parte de Paquetería</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="agregar_parte_paqueteria">
                                        <input type="hidden" name="selected_orders_ids" id="selectedOrdersIdsInput">

                                        <div class="mb-3">
                                            <label for="fecha_recogida" class="form-label">Fecha de Recogida</label>
                                            <input type="date" class="form-control" id="fecha_recogida" name="fecha_recogida" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="empresa_transporte" class="form-label">Empresa de Transporte</label>
                                            <input type="text" class="form-control" id="empresa_transporte" name="empresa_transporte" placeholder="Ej: Correos Express" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="observaciones" class="form-label">Observaciones</label>
                                            <textarea class="form-control" id="observaciones" name="observaciones" rows="3"></textarea>
                                        </div>

                                        <hr>
                                        <h5>Pedidos a Incluir (Tipo: Envío por Paquetería)</h5>
                                        <div class="mb-3">
                                            <input type="text" class="form-control" id="orderSearchInput" placeholder="Buscar pedidos por ID o cliente...">
                                        </div>
                                        <div id="availableOrdersList" class="mb-3">
                                            <!-- Orders will be loaded here via AJAX -->
                                            <p class="text-muted text-center">Empieza a escribir para buscar pedidos...</p>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Pedidos Seleccionados:</label>
                                            <div id="selectedOrdersContainer">
                                                <p class="text-muted">Ningún pedido seleccionado aún.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Crear Parte</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php elseif ($view == 'details' && $parte_actual): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            Detalles del Parte de Paquetería #<?php echo htmlspecialchars($parte_actual['id_parte_paqueteria']); ?>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p><strong>Fecha de Recogida:</strong> <?php echo htmlspecialchars(date('d/m/Y', strtotime($parte_actual['fecha_recogida']))); ?></p>
                                    <p><strong>Empresa de Transporte:</strong> <?php echo htmlspecialchars($parte_actual['empresa_transporte']); ?></p>
                                    <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($parte_actual['observaciones'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="col-md-6 text-end">
                                    <p><strong>Fecha de Creación:</strong> <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($parte_actual['fecha_creacion']))); ?></p>
                                    <p><strong>Estado del Parte:</strong>
                                        <?php
                                            $badge_class_parte = '';
                                            switch ($parte_actual['estado_parte']) {
                                                case 'creado': $badge_class_parte = 'bg-secondary'; break;
                                                case 'en_transito': $badge_class_parte = 'bg-info'; break;
                                                case 'finalizado': $badge_class_parte = 'bg-success'; break;
                                                default: $badge_class_parte = 'bg-secondary'; break;
                                            }
                                        ?>
                                        <span class="badge <?php echo htmlspecialchars($badge_class_parte); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $parte_actual['estado_parte']))); ?></span>
                                    </p>
                                </div>
                            </div>
                            <a href="partes_paqueteria.php" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Volver a la Lista</a>
                            <button class="btn btn-primary mb-3 ms-2" data-bs-toggle="modal" data-bs-target="#addOrdersToParteModal" onclick="loadOrdersForExistingParte(<?php echo htmlspecialchars($parte_actual['id_parte_paqueteria']); ?>)" <?php echo ($parte_actual['estado_parte'] == 'finalizado') ? 'disabled' : ''; ?>>
                                <i class="bi bi-plus-circle"></i> Añadir Pedidos
                            </button>
                            <button class="btn btn-success mb-3 ms-2" onclick="confirmFinalizeParte(<?php echo htmlspecialchars($parte_actual['id_parte_paqueteria']); ?>)" <?php echo ($parte_actual['estado_parte'] == 'finalizado') ? 'disabled' : ''; ?>>
                                <i class="bi bi-check-circle"></i> Finalizar Parte
                            </button>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            Pedidos Asociados
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID Pedido</th>
                                            <th>Fecha Pedido</th>
                                            <th>Cliente</th>
                                            <th class="text-end">Total Pedido</th>
                                            <th>Estado Pedido</th>
                                            <th>Nº Seguimiento</th>
                                            <th>Factura</th>
                                            <th class="text-end">Total Facturado</th>
                                            <th class="text-end">Total Cobrado</th>
                                            <th>Estado Cobro</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($pedidos_asociados)): ?>
                                            <tr>
                                                <td colspan="11" class="text-center">No hay pedidos asociados a este parte de paquetería.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($pedidos_asociados as $pedido):
                                                $badge_class_pedido = '';
                                                switch ($pedido['estado_pedido']) {
                                                    case 'pendiente': $badge_class_pedido = 'bg-warning text-dark'; break;
                                                    case 'completado': $badge_class_pedido = 'bg-success'; break;
                                                    case 'cancelado': $badge_class_pedido = 'bg-danger'; break;
                                                    case 'en_transito_paqueteria': $badge_class_pedido = 'bg-info'; break;
                                                }

                                                // Lógica para el estado de cobro
                                                $estado_cobro = 'N/A';
                                                $badge_class_cobro = 'bg-secondary';
                                                if ($pedido['id_factura']) {
                                                    if (abs($pedido['total_facturado'] - $pedido['total_cobrado']) < 0.01) { // Usar una pequeña tolerancia para flotantes
                                                        $estado_cobro = 'Cobrado Total';
                                                        $badge_class_cobro = 'bg-success';
                                                    } elseif ($pedido['total_cobrado'] > 0 && $pedido['total_cobrado'] < $pedido['total_facturado']) {
                                                        $estado_cobro = 'Cobro Parcial';
                                                        $badge_class_cobro = 'bg-warning text-dark';
                                                    } else {
                                                        $estado_cobro = 'Pendiente de Cobro';
                                                        $badge_class_cobro = 'bg-danger';
                                                    }
                                                }
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($pedido['id_pedido']); ?></td>
                                                    <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($pedido['fecha_pedido']))); ?></td>
                                                    <td><?php echo htmlspecialchars($pedido['nombre_cliente']); ?></td>
                                                    <td class="text-end"><?php echo number_format($pedido['total_pedido_iva_incluido'], 2, ',', '.'); ?> €</td>
                                                    <td><span class="badge <?php echo htmlspecialchars($badge_class_pedido); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $pedido['estado_pedido']))); ?></span></td>
                                                    <td><?php echo htmlspecialchars($pedido['numero_seguimiento'] ?: 'N/A'); ?></td>
                                                    <td>
                                                        <?php if ($pedido['id_factura']): ?>
                                                            <a href="facturas_ventas.php?view=details&id=<?php echo htmlspecialchars($pedido['id_factura']); ?>" title="Ver Factura">
                                                                #<?php echo htmlspecialchars($pedido['id_factura']); ?> (<?php echo htmlspecialchars(date('d/m/Y', strtotime($pedido['fecha_factura']))); ?>)
                                                            </a>
                                                        <?php else: ?>
                                                            N/A
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php echo $pedido['total_facturado'] !== null ? number_format($pedido['total_facturado'], 2, ',', '.') . ' €' : 'N/A'; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php echo $pedido['total_cobrado'] !== null ? number_format($pedido['total_cobrado'], 2, ',', '.') . ' €' : 'N/A'; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($pedido['id_factura']): ?>
                                                            <span class="badge <?php echo htmlspecialchars($badge_class_cobro); ?>"><?php echo htmlspecialchars($estado_cobro); ?></span>
                                                        <?php else: ?>
                                                            N/A
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <a href="pedidos.php?view=details&id=<?php echo htmlspecialchars($pedido['id_pedido']); ?>" class="btn btn-info btn-sm me-1" title="Ver Detalles del Pedido">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <button class="btn btn-warning btn-sm" onclick="confirmRemoveOrderFromParte(<?php echo htmlspecialchars($parte_actual['id_parte_paqueteria']); ?>, <?php echo htmlspecialchars($pedido['id_pedido']); ?>)" title="Desvincular Pedido" <?php echo ($parte_actual['estado_parte'] == 'finalizado') ? 'disabled' : ''; ?>>
                                                            <i class="bi bi-link-slash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th colspan="3" class="text-end">Totales:</th>
                                            <th class="text-end"><?php echo number_format($total_pedidos_iva_incluido, 2, ',', '.'); ?> €</th>
                                            <th colspan="3"></th>
                                            <th class="text-end"><?php echo number_format($total_facturado_general, 2, ',', '.'); ?> €</th>
                                            <th class="text-end"><?php echo number_format($total_cobrado_general, 2, ',', '.'); ?> €</th>
                                            <th colspan="2"></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Añadir Pedidos a un Parte Existente -->
                    <div class="modal fade" id="addOrdersToParteModal" tabindex="-1" aria-labelledby="addOrdersToParteModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form action="partes_paqueteria.php" method="POST" id="addOrdersToParteForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addOrdersToParteModalLabel">Añadir Pedidos a Parte #<?php echo htmlspecialchars($parte_actual['id_parte_paqueteria']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="add_orders_to_parte">
                                        <input type="hidden" name="id_parte_paqueteria" value="<?php echo htmlspecialchars($parte_actual['id_parte_paqueteria']); ?>">
                                        <input type="hidden" name="orders_to_add_ids" id="ordersToAddIdsInput">

                                        <div class="mb-3">
                                            <input type="text" class="form-control" id="orderSearchInputExisting" placeholder="Buscar pedidos por ID o cliente...">
                                        </div>
                                        <div id="availableOrdersListExisting" class="mb-3">
                                            <!-- Orders will be loaded here via AJAX -->
                                            <p class="text-muted text-center">Empieza a escribir para buscar pedidos...</p>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Pedidos Seleccionados para Añadir:</label>
                                            <div id="selectedOrdersContainerExisting">
                                                <p class="text-muted">Ningún pedido seleccionado aún.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Añadir Pedidos</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php endif; // Cierre del if/elseif principal ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        // Array para almacenar los IDs de los pedidos seleccionados en el modal de nuevo parte
        let selectedOrdersForNewParte = new Set();
        // Array para almacenar los IDs de los pedidos seleccionados en el modal de añadir a parte existente
        let selectedOrdersForExistingParte = new Set();
        // Variable para almacenar los pedidos ya asociados al parte actual (para el modal de añadir)
        let existingAssociatedOrders = new Set();


        // Función para enviar formularios dinámicamente
        function createAndSubmitForm(action, inputs) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'partes_paqueteria.php';

            const inputAction = document.createElement('input');
            inputAction.type = 'hidden';
            inputAction.name = 'accion';
            inputAction.value = action;
            form.appendChild(inputAction);

            for (const key in inputs) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = inputs[key];
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }

        // Función para mostrar modal de confirmación personalizado
        function showCustomModal(title, message, type, callback) {
            const modalHtml = `
                <div class="modal fade" id="customConfirmModal" tabindex="-1" aria-labelledby="customConfirmModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title" id="customConfirmModalLabel">${title}</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>${message}</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="window.customConfirmCallback(false)">Cancelar</button>
                                <button type="button" class="btn btn-${type === 'confirm' ? 'danger' : 'primary'}" data-bs-dismiss="modal" onclick="window.customConfirmCallback(true)">Aceptar</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            // Eliminar modal anterior si existe
            const existingModal = document.getElementById('customConfirmModal');
            if (existingModal) {
                existingModal.remove();
            }
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const customConfirmModal = new bootstrap.Modal(document.getElementById('customConfirmModal'));
            window.customConfirmCallback = callback; // Guardar el callback globalmente
            customConfirmModal.show();
        }

        function confirmDeleteParte(id) {
            showCustomModal("Eliminar Parte de Paquetería", "¿Seguro que quieres eliminar este parte de paquetería? Se desvincularán todos los pedidos asociados y su estado volverá a 'Pendiente'.", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('eliminar_parte_paqueteria', { id_parte_paqueteria: id });
            });
        }

        function confirmRemoveOrderFromParte(idParte, idPedido) {
            showCustomModal("Desvincular Pedido", "¿Seguro que quieres desvincular este pedido del parte de paquetería? Su estado volverá a 'Pendiente'.", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('eliminar_pedido_de_parte', { id_parte_paqueteria: idParte, id_pedido: idPedido });
            });
        }

        function confirmFinalizeParte(idParte) {
            showCustomModal("Finalizar Parte de Paquetería", "¿Estás seguro de que quieres finalizar este parte de paquetería? Esto marcará el parte como 'Finalizado' y todos los pedidos asociados como 'Completados'.", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('finalizar_parte_paqueteria', { id_parte_paqueteria: idParte });
            });
        }

        // --- Funciones para la selección de pedidos en el modal de NUEVO PARTE ---
        let orderSearchTimeoutNew;

        async function loadAvailableOrders(searchTerm, targetListId, targetSelectedContainerId, targetSelectedInputId, currentParteId = null, isNewParte = true) {
            const availableOrdersList = document.getElementById(targetListId);
            const selectedOrdersContainer = document.getElementById(targetSelectedContainerId);
            const selectedOrdersInput = document.getElementById(targetSelectedInputId);

            availableOrdersList.innerHTML = '<p class="text-muted text-center">Cargando pedidos...</p>';

            try {
                const formData = new FormData();
                formData.append('accion', 'get_available_orders_for_paqueteria');
                formData.append('search_term', searchTerm);
                if (currentParteId) {
                    formData.append('current_parte_id', currentParteId);
                }

                const response = await fetch('partes_paqueteria.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (!data.success) {
                    availableOrdersList.innerHTML = `<p class="text-danger text-center">Error: ${data.message}</p>`;
                    console.error('Error al cargar pedidos:', data.message);
                    return;
                }

                availableOrdersList.innerHTML = '';
                if (data.orders.length === 0) {
                    availableOrdersList.innerHTML = '<p class="text-muted text-center">No se encontraron pedidos de paquetería pendientes o en tránsito.</p>';
                } else {
                    data.orders.forEach(order => {
                        const orderId = order.id_pedido;
                        const isSelected = isNewParte ? selectedOrdersForNewParte.has(orderId.toString()) : selectedOrdersForExistingParte.has(orderId.toString());
                        const isAlreadyAssociated = existingAssociatedOrders.has(orderId.toString()); // Check for existing parte orders

                        const item = document.createElement('div');
                        item.classList.add('order-item');
                        if (isSelected) {
                            item.classList.add('selected');
                        }
                        if (isAlreadyAssociated && !isNewParte) { // Only disable if it's for existing parte and already associated
                             item.classList.add('text-muted');
                             item.style.cursor = 'not-allowed';
                        } else {
                            item.addEventListener('click', () => {
                                toggleOrderSelection(order, isNewParte);
                            });
                        }
                        
                        let statusBadge = '';
                        if (order.estado_pedido === 'pendiente') {
                            statusBadge = '<span class="badge bg-warning text-dark">Pendiente</span>';
                        } else if (order.estado_pedido === 'en_transito_paqueteria') {
                            statusBadge = '<span class="badge bg-info">En Tránsito</span>';
                        } else if (order.estado_pedido === 'completado') {
                            statusBadge = '<span class="badge bg-success">Completado</span>';
                        }

                        item.innerHTML = `
                            <strong>Pedido #${order.id_pedido}</strong> - ${order.nombre_cliente} <br>
                            <small>Fecha: ${new Date(order.fecha_pedido).toLocaleDateString()} | Total: ${parseFloat(order.total_pedido_iva_incluido).toFixed(2)} € | Estado: ${statusBadge}</small>
                        `;
                        availableOrdersList.appendChild(item);
                    });
                }
                updateSelectedOrdersDisplay(isNewParte);

            } catch (error) {
                availableOrdersList.innerHTML = `<p class="text-danger text-center">Error de conexión al cargar pedidos.</p>`;
                console.error('Error fetching available orders:', error);
            }
        }

        function toggleOrderSelection(order, isNewParte) {
            const orderId = order.id_pedido.toString();
            let selectedSet = isNewParte ? selectedOrdersForNewParte : selectedOrdersForExistingParte;

            if (selectedSet.has(orderId)) {
                selectedSet.delete(orderId);
            } else {
                selectedSet.add(orderId);
            }
            updateSelectedOrdersDisplay(isNewParte);
            // Re-render the available orders list to update selection highlights
            const searchTerm = isNewParte ? document.getElementById('orderSearchInput').value : document.getElementById('orderSearchInputExisting').value;
            const currentParteId = isNewParte ? null : <?php echo json_encode($parte_actual['id_parte_paqueteria'] ?? null); ?>;
            loadAvailableOrders(searchTerm, isNewParte ? 'availableOrdersList' : 'availableOrdersListExisting', isNewParte ? 'selectedOrdersContainer' : 'selectedOrdersContainerExisting', isNewParte ? 'selectedOrdersIdsInput' : 'ordersToAddIdsInput', currentParteId, isNewParte);
        }

        function updateSelectedOrdersDisplay(isNewParte) {
            const selectedOrdersContainer = document.getElementById(isNewParte ? 'selectedOrdersContainer' : 'selectedOrdersContainerExisting');
            const selectedOrdersInput = document.getElementById(isNewParte ? 'selectedOrdersIdsInput' : 'ordersToAddIdsInput');
            let selectedSet = isNewParte ? selectedOrdersForNewParte : selectedOrdersForExistingParte;

            selectedOrdersContainer.innerHTML = '';
            if (selectedSet.size === 0) {
                selectedOrdersContainer.innerHTML = '<p class="text-muted">Ningún pedido seleccionado aún.</p>';
            } else {
                selectedSet.forEach(orderId => {
                    const orderInfo = document.createElement('span');
                    orderInfo.classList.add('selected-order-tag');
                    orderInfo.textContent = `Pedido #${orderId}`;
                    const removeBtn = document.createElement('span');
                    removeBtn.classList.add('remove-tag');
                    removeBtn.innerHTML = '&times;';
                    removeBtn.addEventListener('click', () => {
                        selectedSet.delete(orderId);
                        updateSelectedOrdersDisplay(isNewParte);
                        // Re-render the available orders list to update selection highlights
                        const searchTerm = isNewParte ? document.getElementById('orderSearchInput').value : document.getElementById('orderSearchInputExisting').value;
                        const currentParteId = isNewParte ? null : <?php echo json_encode($parte_actual['id_parte_paqueteria'] ?? null); ?>;
                        loadAvailableOrders(searchTerm, isNewParte ? 'availableOrdersList' : 'availableOrdersListExisting', isNewParte ? 'selectedOrdersContainer' : 'selectedOrdersContainerExisting', isNewParte ? 'selectedOrdersIdsInput' : 'ordersToAddIdsInput', currentParteId, isNewParte);
                    });
                    orderInfo.appendChild(removeBtn);
                    selectedOrdersContainer.appendChild(orderInfo);
                });
            }
            selectedOrdersInput.value = Array.from(selectedSet).join(',');
        }

        // Event Listeners for New Parte Modal
        const addParteModalElement = document.getElementById('addParteModal');
        if (addParteModalElement) {
            addParteModalElement.addEventListener('shown.bs.modal', () => {
                selectedOrdersForNewParte.clear(); // Clear previous selections
                document.getElementById('orderSearchInput').value = ''; // Clear search input
                loadAvailableOrders('', 'availableOrdersList', 'selectedOrdersContainer', 'selectedOrdersIdsInput', null, true); // Load all initially
            });

            document.getElementById('orderSearchInput').addEventListener('input', function() {
                clearTimeout(orderSearchTimeoutNew);
                orderSearchTimeoutNew = setTimeout(() => {
                    loadAvailableOrders(this.value.trim(), 'availableOrdersList', 'selectedOrdersContainer', 'selectedOrdersIdsInput', null, true);
                }, 300);
            });

            document.getElementById('addParteForm').addEventListener('submit', function(event) {
                const empresaTransporte = document.getElementById('empresa_transporte').value.trim();
                if (!empresaTransporte) {
                    event.preventDefault();
                    // Reemplazar alert con un modal personalizado si es necesario
                    showCustomModal("Error de Validación", "Por favor, especifique la empresa de transporte.", 'danger', () => {});
                    return;
                }
                // No es necesario validar si hay pedidos seleccionados, puede crearse un parte vacío
            });
        }

        // --- Funciones para la selección de pedidos en el modal de AÑADIR A PARTE EXISTENTE ---
        let orderSearchTimeoutExisting;

        async function loadOrdersForExistingParte(parteId) {
            // Reset selected orders for this modal
            selectedOrdersForExistingParte.clear();
            document.getElementById('orderSearchInputExisting').value = '';

            // Load currently associated orders to disable them in the selection list
            existingAssociatedOrders.clear();
            <?php if ($view == 'details' && $parte_actual): ?>
                <?php foreach ($pedidos_asociados as $pedido): ?>
                    existingAssociatedOrders.add('<?php echo $pedido['id_pedido']; ?>');
                <?php endforeach; ?>
            <?php endif; ?>

            loadAvailableOrders('', 'availableOrdersListExisting', 'selectedOrdersContainerExisting', 'ordersToAddIdsInput', parteId, false);
        }

        const addOrdersToParteModalElement = document.getElementById('addOrdersToParteModal');
        if (addOrdersToParteModalElement) {
            addOrdersToParteModalElement.addEventListener('shown.bs.modal', () => {
                // This will be triggered by the button click, which calls loadOrdersForExistingParte()
                // So no need to duplicate logic here, just ensure the fields are reset
                document.getElementById('orderSearchInputExisting').value = '';
                selectedOrdersForExistingParte.clear();
                updateSelectedOrdersDisplay(false); // Update display for existing parte modal
            });

            document.getElementById('orderSearchInputExisting').addEventListener('input', function() {
                clearTimeout(orderSearchTimeoutExisting);
                orderSearchTimeoutExisting = setTimeout(() => {
                    const currentParteId = document.getElementById('addOrdersToParteForm').querySelector('input[name="id_parte_paqueteria"]').value;
                    loadAvailableOrders(this.value.trim(), 'availableOrdersListExisting', 'selectedOrdersContainerExisting', 'ordersToAddIdsInput', currentParteId, false);
                }, 300);
            });

            document.getElementById('addOrdersToParteForm').addEventListener('submit', function(event) {
                if (selectedOrdersForExistingParte.size === 0) {
                    event.preventDefault();
                    // Reemplazar alert con un modal personalizado si es necesario
                    showCustomModal("Error de Validación", "Por favor, seleccione al menos un pedido para añadir.", 'danger', () => {});
                }
            });
        }

    </script>
</body>
</html>
