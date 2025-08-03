<?php
// Habilitar la visualización de errores para depuración (¡DESACTIVAR EN PRODUCCIÓN! EN PRODUCCIÓN DEBE ESTAR EN 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// --- Cargar datos de apoyo (Clientes, Productos, Rutas) ---
$clientes_disponibles = [];
$productos_disponibles = [];
$rutas_disponibles = [];
$productos_map = []; // Mapa para acceder fácilmente a los detalles del producto
$clientes_map = []; // Mapa para acceder fácilmente a los detalles del cliente por ID

try {
    // Clientes y sus direcciones de envío
    // MODIFICACIÓN: Ahora también cargamos las direcciones de envío para cada cliente
    $stmt_clientes = $pdo->query("SELECT id_cliente, nombre_cliente, nif, telefono, email, direccion, ciudad, provincia, codigo_postal FROM clientes ORDER BY nombre_cliente ASC");
    $clientes_disponibles = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clientes_disponibles as $cli) {
        $clientes_map[$cli['id_cliente']] = [
            'nombre_cliente' => $cli['nombre_cliente'],
            'nif' => $cli['nif'],
            'telefono' => $cli['telefono'],
            'email' => $cli['email'],
            'direccion' => $cli['direccion'],
            'ciudad' => $cli['ciudad'],
            'provincia' => $cli['provincia'],
            'codigo_postal' => $cli['codigo_postal'],
            'direcciones_envio' => [] // Inicializar array para direcciones de envío
        ];

        // Cargar direcciones de envío para cada cliente
        $stmt_direcciones = $pdo->prepare("SELECT id_direccion_envio, nombre_direccion, direccion, ciudad, provincia, codigo_postal, es_principal FROM direcciones_envio WHERE id_cliente = ? ORDER BY es_principal DESC, nombre_direccion ASC");
        $stmt_direcciones->execute([$cli['id_cliente']]);
        $clientes_map[$cli['id_cliente']]['direcciones_envio'] = $stmt_direcciones->fetchAll(PDO::FETCH_ASSOC);
    }

    // Productos (ahora asumiendo que porcentaje_iva_actual está directamente en la tabla 'productos')
    // NEW: Added litros_por_unidad for calculation of total liters
    $stmt_productos = $pdo->query("
        SELECT
            p.id_producto,
            p.nombre_producto,
            p.precio_venta,
            p.stock_actual_unidades,
            COALESCE(p.porcentaje_iva_actual, 0.00) AS porcentaje_iva_actual,
            COALESCE(p.litros_por_unidad, 1.00) AS litros_por_unidad -- Assuming a default of 1.0 if not set, ideally this comes from DB
        FROM productos p
        ORDER BY p.nombre_producto ASC
    ");
    $productos_disponibles = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);
    foreach ($productos_disponibles as $prod) {
        $productos_map[$prod['id_producto']] = [
            'nombre_producto' => $prod['nombre_producto'],
            'precio_venta' => $prod['precio_venta'],
            'stock_actual_unidades' => $prod['stock_actual_unidades'],
            'porcentaje_iva' => $prod['porcentaje_iva_actual'], // Se mapea a 'porcentaje_iva' para consistencia interna
            'litros_por_unidad' => $prod['litros_por_unidad'] // NEW: Added for total liters calculation
        ];
    }

    // Rutas
    $stmt_rutas = $pdo->query("SELECT id_ruta, nombre_ruta FROM rutas ORDER BY nombre_ruta ASC");
    $rutas_disponibles = $stmt_rutas->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensaje = "Error de base de datos al cargar datos de apoyo: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

// Variables para precargar el formulario si se está editando un pedido
$pedido_a_editar = null;
$detalles_pedido_a_editar = [];

/**
 * Recalcula y actualiza los totales de un pedido, aplicando un descuento global y un recargo global
 * proporcionalmente a cada línea de detalle.
 *
 * @param PDO $pdo Objeto PDO de la conexión a la base de datos.
 * @param int $id_pedido ID del pedido a actualizar.
 * @throws Exception Si ocurre un error al recalcular los totales.
 */
function recalcularTotalPedido(PDO $pdo, int $id_pedido) {
    try {
        // Set precision for bcmath operations
        bcscale(4);

        // 1. Obtener el descuento global y el recargo global aplicados a este pedido
        $stmt_get_order_adjustments = $pdo->prepare("SELECT descuento_global_aplicado, recargo_global_aplicado FROM pedidos WHERE id_pedido = ?");
        $stmt_get_order_adjustments->execute([$id_pedido]);
        $adjustments = $stmt_get_order_adjustments->fetch(PDO::FETCH_ASSOC);

        $descuento_global_aplicado_str = (string)($adjustments['descuento_global_aplicado'] ?? "0.00");
        $recargo_global_aplicado_str = (string)($adjustments['recargo_global_aplicado'] ?? "0.00");

        // 2. Obtener todas las líneas de detalle de este pedido
        // Necesitamos los valores originales de cantidad, precio_unitario_venta, descuento_porcentaje, recargo_porcentaje, iva_porcentaje
        // NEW: Also fetch litros_por_unidad from products for total liters calculation
        $stmt_get_line_items = $pdo->prepare("
            SELECT
                dp.id_detalle_pedido,
                dp.cantidad_solicitada,
                dp.precio_unitario_venta,
                dp.descuento_porcentaje,
                dp.recargo_porcentaje,
                dp.iva_porcentaje,
                COALESCE(p.litros_por_unidad, 1.00) AS litros_por_unidad
            FROM detalle_pedidos dp
            JOIN productos p ON dp.id_producto = p.id_producto
            WHERE dp.id_pedido = ? FOR UPDATE
        ");
        $stmt_get_line_items->execute([$id_pedido]);
        $line_items = $stmt_get_line_items->fetchAll(PDO::FETCH_ASSOC);

        // Calcular la suma total de los precios (IVA incluido) de las líneas antes de aplicar descuento/recargo global
        $total_sum_of_line_totals_before_global_adjustment = "0";
        foreach ($line_items as $item) {
            $cantidad_str = (string)$item['cantidad_solicitada'];
            $precio_unitario_venta_str = (string)$item['precio_unitario_venta']; // Este es el precio IVA incluido
            $descuento_linea_str = (string)$item['descuento_porcentaje'];
            $recargo_linea_str = (string)$item['recargo_porcentaje'];

            // Calcular el total de la línea con sus propios descuentos/recargos (IVA incluido)
            $total_linea_before_global_adjustment = bcmul(
                $cantidad_str,
                bcmul(
                    $precio_unitario_venta_str,
                    bcmul(
                        bcsub("1", bcdiv($descuento_linea_str, "100")),
                        bcadd("1", bcdiv($recargo_linea_str, "100"))
                    )
                )
            );
            $total_sum_of_line_totals_before_global_adjustment = bcadd($total_sum_of_line_totals_before_global_adjustment, $total_linea_before_global_adjustment);
        }

        // Calcular el ajuste neto global (recargo global - descuento global)
        $net_global_adjustment = bcsub($recargo_global_aplicado_str, $descuento_global_aplicado_str);

        $final_total_base_imponible_pedido = "0";
        $final_total_iva_pedido = "0";
        $final_total_pedido_iva_incluido = "0";
        $final_total_litros_pedido = "0"; // NEW

        // 3. Recalcular cada línea de detalle aplicando el ajuste global proporcionalmente
        $stmt_update_line_item = $pdo->prepare("
            UPDATE detalle_pedidos
            SET
                subtotal_linea_base = ?,
                subtotal_linea_iva = ?,
                subtotal_linea_total = ?
            WHERE id_detalle_pedido = ?
        ");

        foreach ($line_items as $item) {
            $cantidad_str = (string)$item['cantidad_solicitada'];
            $precio_unitario_venta_str = (string)$item['precio_unitario_venta']; // Este es el precio IVA incluido
            $descuento_linea_str = (string)$item['descuento_porcentaje'];
            $recargo_linea_str = (string)$item['recargo_porcentaje'];
            $iva_linea_str = (string)$item['iva_porcentaje'];
            $litros_por_unidad_str = (string)$item['litros_por_unidad']; // NEW

            // Recalcular el total de la línea con sus propios descuentos/recargos (IVA incluido)
            $total_linea_before_global_adjustment = bcmul(
                $cantidad_str,
                bcmul(
                    $precio_unitario_venta_str,
                    bcmul(
                        bcsub("1", bcdiv($descuento_linea_str, "100")),
                        bcadd("1", bcdiv($recargo_linea_str, "100"))
                    )
                )
            );

            $proportional_global_adjustment_for_line = "0";
            if (bccomp($total_sum_of_line_totals_before_global_adjustment, "0", 4) > 0) {
                $proportional_global_adjustment_for_line = bcmul(
                    bcdiv($total_linea_before_global_adjustment, $total_sum_of_line_totals_before_global_adjustment),
                    $net_global_adjustment
                );
            }

            // Aplicar el ajuste global proporcional al total de la línea (IVA incluido)
            $total_linea_iva_incluido_final = bcadd($total_linea_before_global_adjustment, $proportional_global_adjustment_for_line);

            // Recalcular la base imponible y el IVA de la línea a partir del total final (IVA incluido)
            $subtotal_linea_base_final = bcdiv($total_linea_iva_incluido_final, bcadd("1", bcdiv($iva_linea_str, "100")));
            $subtotal_linea_iva_final = bcsub($total_linea_iva_incluido_final, $subtotal_linea_base_final);

            $stmt_update_line_item->execute([
                round((float)$subtotal_linea_base_final, 2),
                round((float)$subtotal_linea_iva_final, 2),
                round((float)$total_linea_iva_incluido_final, 2), // subtotal_linea_total es el total con IVA incluido
                $item['id_detalle_pedido']
            ]);

            $final_total_base_imponible_pedido = bcadd($final_total_base_imponible_pedido, $subtotal_linea_base_final);
            $final_total_iva_pedido = bcadd($final_total_iva_pedido, $subtotal_linea_iva_final);
            $final_total_pedido_iva_incluido = bcadd($final_total_pedido_iva_incluido, $total_linea_iva_incluido_final);

            $final_total_litros_pedido = bcadd($final_total_litros_pedido, bcmul($cantidad_str, $litros_por_unidad_str)); // NEW
        }

        // 4. Actualizar la cabecera del pedido con los nuevos totales
        $stmt_update_pedido = $pdo->prepare("
            UPDATE pedidos
            SET
                total_base_imponible_pedido = ?,
                total_iva_pedido = ?,
                total_pedido_iva_incluido = ?,
                descuento_global_aplicado = ?,
                recargo_global_aplicado = ?,
                total_litros = ? -- NEW
            WHERE id_pedido = ?
        ");
        $stmt_update_pedido->execute([
            round((float)$final_total_base_imponible_pedido, 2),
            round((float)$final_total_iva_pedido, 2),
            round((float)$final_total_pedido_iva_incluido, 2),
            round((float)$descuento_global_aplicado_str, 2),
            round((float)$recargo_global_aplicado_str, 2),
            round((float)$final_total_litros_pedido, 2), // NEW
            $id_pedido
        ]);

    } catch (Exception $e) {
        error_log("Error al recalcular total de pedido (ID: " . $id_pedido . "): " . $e->getMessage());
        throw new Exception("Error al recalcular total de pedido: " . $e->getMessage());
    }
}


// --- Lógica para procesar el formulario de añadir/editar pedido ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // DEBUG: Log the entire POST array for debugging
    error_log("DEBUG: Full POST data received: " . print_r($_POST, true));

    $accion = $_POST['accion'] ?? '';

    // Asegurarse de que la respuesta sea JSON si es una solicitud AJAX
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
    }

    $in_transaction = false;
    // Iniciar transacción si la acción no es solo para obtener datos
    if ($accion !== 'get_client_details' && $accion !== 'search_clients' && $accion !== 'get_product_details' && $accion !== 'get_shipping_addresses' && $accion !== 'search_shipping_addresses') { // MODIFICACIÓN: Añadir 'get_shipping_addresses' y 'search_shipping_addresses'
        $pdo->beginTransaction();
        $in_transaction = true;
    }

    try {
        switch ($accion) {
            case 'agregar_pedido':
                $id_cliente = $_POST['id_cliente'] ?? null;
                $fecha_pedido = $_POST['fecha_pedido'] ?? date('Y-m-d');
                $estado_pedido = $_POST['estado_pedido'] ?? 'pendiente';
                // Convertir cadena vacía a null para id_ruta si no está seleccionada
                $id_ruta = empty($_POST['id_ruta']) ? null : (int)$_POST['id_ruta'];
                $tipo_entrega = $_POST['tipo_entrega'] ?? 'reparto_propio'; // Nuevo campo
                // Empresa de transporte y número de seguimiento ahora son opcionales
                $empresa_transporte = ($tipo_entrega == 'envio_paqueteria') ? trim($_POST['empresa_transporte'] ?? '') : null;
                // MODIFICACIÓN: Asegurarse de que el número de seguimiento sea una URL válida si se proporciona
                $numero_seguimiento = ($tipo_entrega == 'envio_paqueteria') ? filter_var(trim($_POST['numero_seguimiento'] ?? ''), FILTER_VALIDATE_URL) : null;
                // Si la URL no es válida, se puede manejar el error o simplemente guardar null
                if ($tipo_entrega == 'envio_paqueteria' && !empty($_POST['numero_seguimiento']) && $numero_seguimiento === false) {
                    throw new Exception("El número de seguimiento debe ser una URL válida.");
                }

                $observaciones = trim($_POST['observaciones'] ?? ''); // Nueva observación para el pedido
                // MODIFICACIÓN: Capturar id_direccion_envio
                $id_direccion_envio = empty($_POST['id_direccion_envio']) ? null : (int)$_POST['id_direccion_envio'];


                if (empty($id_cliente)) {
                    throw new Exception("Debe seleccionar un cliente para el pedido.");
                }
                // La ruta ya no es obligatoria para todos los tipos de entrega
                if ($tipo_entrega == 'reparto_propio' && empty($id_ruta)) {
                    throw new Exception("Debe seleccionar una ruta para el pedido de reparto propio.");
                }
                // Si el tipo de entrega es "Entrega en Molino", la ruta, empresa de transporte y número de seguimiento son nulos
                if ($tipo_entrega == 'entrega_en_molino') {
                    $id_ruta = null;
                    $empresa_transporte = null;
                    $numero_seguimiento = null;
                }

                error_log("DEBUG: Tipo de entrega antes de INSERT: " . $tipo_entrega); // Debugging line
                // Inicializar totales y descuentos/recargos globales a 0 para el nuevo pedido
                // NEW: Initialize total_litros to 0.00
                // MODIFICACIÓN: Añadir id_direccion_envio a la inserción
                $stmt = $pdo->prepare("INSERT INTO pedidos (id_cliente, id_direccion_envio, fecha_pedido, estado_pedido, id_ruta, descuento_global_aplicado, recargo_global_aplicado, total_base_imponible_pedido, total_iva_pedido, total_pedido_iva_incluido, tipo_entrega, empresa_transporte, numero_seguimiento, observaciones, total_litros) VALUES (?, ?, ?, ?, ?, 0.00, 0.00, 0.00, 0.00, 0.00, ?, ?, ?, ?, 0.00)");
                $stmt->execute([$id_cliente, $id_direccion_envio, $fecha_pedido, $estado_pedido, $id_ruta, $tipo_entrega, $empresa_transporte, $numero_seguimiento, $observaciones]);
                $id_pedido_nuevo = $pdo->lastInsertId();
                error_log("DEBUG: Pedido insertado con ID: " . $id_pedido_nuevo); // Debugging line

                if ($in_transaction) $pdo->commit();
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    echo json_encode(['success' => true, 'message' => 'Pedido creado con éxito. ID: ' . $id_pedido_nuevo, 'id_pedido' => $id_pedido_nuevo]);
                } else {
                    mostrarMensaje("Pedido creado con éxito. ID: " . htmlspecialchars($id_pedido_nuevo), "success");
                    header("Location: pedidos.php?view=details&id=" . htmlspecialchars($id_pedido_nuevo));
                    exit();
                }
                break;

            case 'editar_pedido':
                $id_pedido = $_POST['id_pedido'];
                $id_cliente = $_POST['id_cliente'] ?? null;
                $fecha_pedido = $_POST['fecha_pedido'] ?? date('Y-m-d');
                $estado_pedido = $_POST['estado_pedido'] ?? 'pendiente';
                // Convertir cadena vacía a null para id_ruta si no está seleccionada
                $id_ruta = empty($_POST['id_ruta']) ? null : (int)$_POST['id_ruta'];
                $tipo_entrega = $_POST['tipo_entrega'] ?? 'reparto_propio'; // Nuevo campo
                // Empresa de transporte y número de seguimiento ahora son opcionales
                $empresa_transporte = ($tipo_entrega == 'envio_paqueteria') ? trim($_POST['empresa_transporte'] ?? '') : null;
                // MODIFICACIÓN: Asegurarse de que el número de seguimiento sea una URL válida si se proporciona
                $numero_seguimiento = ($tipo_entrega == 'envio_paqueteria') ? filter_var(trim($_POST['numero_seguimiento'] ?? ''), FILTER_VALIDATE_URL) : null;
                // Si la URL no es válida, se puede manejar el error o simplemente guardar null
                if ($tipo_entrega == 'envio_paqueteria' && !empty($_POST['numero_seguimiento']) && $numero_seguimiento === false) {
                    throw new Exception("El número de seguimiento debe ser una URL válida.");
                }

                $observaciones = trim($_POST['observaciones'] ?? ''); // Nueva observación para el pedido
                // MODIFICACIÓN: Capturar id_direccion_envio para edición
                $id_direccion_envio = empty($_POST['id_direccion_envio']) ? null : (int)$_POST['id_direccion_envio'];


                if (empty($id_cliente)) {
                    throw new Exception("Debe seleccionar un cliente para el pedido.");
                }
                if ($tipo_entrega == 'reparto_propio' && empty($id_ruta)) {
                    throw new Exception("Debe seleccionar una ruta para el pedido de reparto propio.");
                }
                // Si el tipo de entrega es "Entrega en Molino", la ruta, empresa de transporte y número de seguimiento son nulos
                if ($tipo_entrega == 'entrega_en_molino') {
                    $id_ruta = null;
                    $empresa_transporte = null;
                    $numero_seguimiento = null;
                }
                error_log("DEBUG: Tipo de entrega antes de UPDATE (ID: " . $id_pedido . "): " . $tipo_entrega); // Debugging line

                // MODIFICACIÓN: Añadir id_direccion_envio a la actualización
                $stmt = $pdo->prepare("UPDATE pedidos SET id_cliente = ?, id_direccion_envio = ?, fecha_pedido = ?, estado_pedido = ?, id_ruta = ?, tipo_entrega = ?, empresa_transporte = ?, numero_seguimiento = ?, observaciones = ? WHERE id_pedido = ?");
                $stmt->execute([$id_cliente, $id_direccion_envio, $fecha_pedido, $estado_pedido, $id_ruta, $tipo_entrega, $empresa_transporte, $numero_seguimiento, $observaciones, $id_pedido]);
                error_log("DEBUG: Pedido actualizado (ID: " . $id_pedido . ")"); // Debugging line

                // Recalcular los totales del pedido principal para actualizar total_litros
                recalcularTotalPedido($pdo, $id_pedido);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Pedido actualizado con éxito.", "success");
                // Redirigir a la vista de lista con un parámetro de refresco
                header("Location: pedidos.php?refresh=true");
                exit();
                break;

            case 'eliminar_pedido':
                $id_pedido = $_POST['id_pedido'];

                // Eliminar detalles del pedido primero (si existen)
                $stmt_delete_detalles = $pdo->prepare("DELETE FROM detalle_pedidos WHERE id_pedido = ?");
                $stmt_delete_detalles->execute([$id_pedido]);

                // Eliminar cualquier relación en partes_ruta_pedidos
                $stmt_delete_parte_ruta_rel = $pdo->prepare("DELETE FROM partes_ruta_pedidos WHERE id_pedido = ?");
                $stmt_delete_parte_ruta_rel->execute([$id_pedido]);

                // Luego eliminar el pedido principal
                $stmt_delete_pedido = $pdo->prepare("DELETE FROM pedidos WHERE id_pedido = ?");
                $stmt_delete_pedido->execute([$id_pedido]);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Pedido eliminado con éxito.", "success");
                header("Location: pedidos.php");
                exit();
                break;

            case 'agregar_detalle_linea':
                $id_pedido = $_POST['id_pedido'];
                $id_producto = $_POST['id_producto'];
                $cantidad_solicitada = (int)$_POST['cantidad_solicitada'];
                // Precio unitario y IVA se obtendrán del mapa de productos, no del POST directo
                $precio_unitario_venta = $productos_map[$id_producto]['precio_venta'] ?? 0.00; // Este es el precio IVA incluido
                $iva_porcentaje = $productos_map[$id_producto]['porcentaje_iva'] ?? 21.00; // Se mantiene para el cálculo del backend
                $litros_por_unidad = $productos_map[$id_producto]['litros_por_unidad'] ?? 1.00; // NEW: Get liters per unit

                // Descuento y recargo de línea se establecen a 0 ya que los campos fueron removidos del UI
                $descuento_porcentaje = 0;
                $recargo_porcentaje = 0;
                $observaciones_detalle = trim($_POST['observaciones_detalle'] ?? '');

                if ($cantidad_solicitada <= 0) {
                    throw new Exception("La cantidad solicitada debe ser un número positivo.");
                }
                if ($precio_unitario_venta <= 0) {
                    throw new Exception("El precio unitario debe ser un número positivo.");
                }

                // Set precision for bcmath operations
                bcscale(4);

                // Calcular subtotal base (antes de IVA, con descuento/recargo de línea)
                // Si precio_unitario_venta es IVA incluido, la base imponible es: precio_unitario_venta / (1 + iva_porcentaje/100)
                $precio_unitario_base_imponible_str = bcdiv((string)$precio_unitario_venta, bcadd("1", bcdiv((string)$iva_porcentaje, "100")));

                $subtotal_base_original_linea_str = bcmul((string)$cantidad_solicitada, $precio_unitario_base_imponible_str);
                $subtotal_linea_base_str = bcmul(bcmul($subtotal_base_original_linea_str, bcsub("1", bcdiv((string)$descuento_porcentaje, "100"))), bcadd("1", bcdiv((string)$recargo_porcentaje, "100")));
                
                // Calcular IVA de la línea
                $subtotal_linea_iva_str = bcmul($subtotal_linea_base_str, bcdiv((string)$iva_porcentaje, "100"));
                
                // Calcular total de la línea (IVA incluido)
                $subtotal_linea_total_str = bcadd($subtotal_linea_base_str, $subtotal_linea_iva_str);

                $stmt = $pdo->prepare("INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad_solicitada, precio_unitario_venta, descuento_porcentaje, recargo_porcentaje, iva_porcentaje, subtotal_linea_base, subtotal_linea_iva, subtotal_linea_total, estado_linea, observaciones_detalle) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente', ?)");
                $stmt->execute([
                    $id_pedido,
                    $id_producto,
                    $cantidad_solicitada,
                    round((float)$precio_unitario_venta, 2),
                    round((float)$descuento_porcentaje, 2),
                    round((float)$recargo_porcentaje, 2),
                    round((float)$iva_porcentaje, 2),
                    round((float)$subtotal_linea_base_str, 2),
                    round((float)$subtotal_linea_iva_str, 2),
                    round((float)$subtotal_linea_total_str, 2),
                    $observaciones_detalle
                ]);

                // Recalcular los totales del pedido principal para aplicar descuentos/recargos globales y actualizar litros
                recalcularTotalPedido($pdo, $id_pedido);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Línea de detalle agregada con éxito.", "success");
                header("Location: pedidos.php?view=details&id=" . htmlspecialchars($id_pedido));
                exit();
                break;

            case 'eliminar_detalle_linea':
                $id_detalle_pedido = $_POST['id_detalle_pedido'];
                $id_pedido_original = $_POST['id_pedido_original'];

                $stmt_delete = $pdo->prepare("DELETE FROM detalle_pedidos WHERE id_detalle_pedido = ?");
                $stmt_delete->execute([$id_detalle_pedido]);

                // Recalcular los totales del pedido principal después de eliminar la línea
                recalcularTotalPedido($pdo, $id_pedido_original);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Línea de detalle eliminada con éxito.", "success");
                header("Location: pedidos.php?view=details&id=" . htmlspecialchars($id_pedido_original));
                exit();
                break;

            case 'update_global_discount_pedido': // NUEVA ACCIÓN para actualizar el descuento global
                $id_pedido = $_POST['id_pedido'];
                $new_descuento_global = (float)($_POST['descuento_global'] ?? 0.00);

                if ($new_descuento_global < 0) {
                    throw new Exception("El descuento global no puede ser negativo.");
                }

                // Actualizar el descuento global en la cabecera del pedido
                $stmt_update_discount = $pdo->prepare("UPDATE pedidos SET descuento_global_aplicado = ? WHERE id_pedido = ?");
                $stmt_update_discount->execute([round($new_descuento_global, 2), $id_pedido]);

                // Recalcular todo el pedido para aplicar el descuento proporcionalmente (y el recargo)
                recalcularTotalPedido($pdo, $id_pedido);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Descuento global del pedido actualizado con éxito y líneas recalculadas.", "success");
                header("Location: pedidos.php?view=details&id=" . htmlspecialchars($id_pedido));
                exit();
                break;

            case 'update_global_surcharge_pedido': // NUEVA ACCIÓN para actualizar el recargo global
                $id_pedido = $_POST['id_pedido'];
                $new_recargo_global = (float)($_POST['recargo_global'] ?? 0.00);

                if ($new_recargo_global < 0) {
                    throw new Exception("El recargo global no puede ser negativo.");
                }

                // Actualizar el recargo global en la cabecera del pedido
                $stmt_update_surcharge = $pdo->prepare("UPDATE pedidos SET recargo_global_aplicado = ? WHERE id_pedido = ?");
                $stmt_update_surcharge->execute([round($new_recargo_global, 2), $id_pedido]);

                // Recalcular todo el pedido para aplicar el recargo proporcionalmente (y el descuento)
                recalcularTotalPedido($pdo, $id_pedido);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Recargo global del pedido actualizado con éxito y líneas recalculadas.", "success");
                header("Location: pedidos.php?view=details&id=" . htmlspecialchars($id_pedido));
                exit();
                break;

            case 'get_client_details':
                ob_clean(); // Clean any previous output buffer
                header('Content-Type: application/json');
                $client_id = $_POST['id_cliente'] ?? null;
                if ($client_id) {
                    $stmt = $pdo->prepare("SELECT id_cliente, nombre_cliente, nif, telefono, email FROM clientes WHERE id_cliente = ?");
                    $stmt->execute([$client_id]);
                    $client = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode($client ?: ['error' => 'Cliente no encontrado.']);
                } else {
                    echo json_encode(['error' => 'ID de cliente no proporcionado.']);
                }
                exit();
                break;

            case 'search_clients':
               
                header('Content-Type: application/json');
                $search_term = $_POST['search_term'] ?? '';

                if (empty($search_term)) {
                    echo json_encode([]);
                    exit();
                }

                $like_param = '%' . $search_term . '%';
                try {
                    $stmt_clients = $pdo->prepare("
                        SELECT id_cliente, nombre_cliente, ciudad, provincia, telefono, email, nif
                        FROM clientes
                        WHERE nombre_cliente LIKE ? OR ciudad LIKE ? OR provincia LIKE ? OR telefono LIKE ? OR email LIKE ? OR nif LIKE ?
                        ORDER BY nombre_cliente ASC
                        LIMIT 10
                    ");
                    $stmt_clients->execute([$like_param, $like_param, $like_param, $like_param, $like_param, $like_param]);
                    $clients = $stmt_clients->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode($clients);
                } catch (PDOException $e) {
                    error_log("Error searching clients (AJAX): " . $e->getMessage());
                    echo json_encode(['error' => 'Database error searching clients.']);
                }
                exit();
                break;

            // MODIFICACIÓN: Nueva acción AJAX para obtener direcciones de envío de un cliente
            case 'get_shipping_addresses': // This action now can also handle search
            case 'search_shipping_addresses': // Explicit action for search
                
                header('Content-Type: application/json');
                $id_cliente = $_POST['id_cliente'] ?? null;
                $search_term = $_POST['search_term'] ?? ''; // New search term parameter

                if (!$id_cliente) {
                    echo json_encode(['success' => false, 'message' => 'ID de cliente no proporcionado.']);
                    exit();
                }

                $query_params = [$id_cliente];
                $sql = "SELECT id_direccion_envio, nombre_direccion, direccion, ciudad, provincia, codigo_postal, es_principal FROM direcciones_envio WHERE id_cliente = ?";

                if (!empty($search_term)) {
                    $like_term = '%' . $search_term . '%';
                    $sql .= " AND (nombre_direccion LIKE ? OR direccion LIKE ? OR ciudad LIKE ? OR provincia LIKE ? OR codigo_postal LIKE ?)";
                    $query_params = array_merge($query_params, [$like_term, $like_term, $like_term, $like_term, $like_term]);
                }

                $sql .= " ORDER BY es_principal DESC, nombre_direccion ASC LIMIT 10"; // Limit results

                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($query_params);
                    $direcciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'direcciones' => $direcciones]);
                } catch (PDOException $e) {
                    error_log("Error searching shipping addresses (AJAX): " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Error al buscar direcciones de envío: ' . $e->getMessage()]);
                }
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
            echo json_encode(['success' => false, 'message' => "Error de base de datos: " . $e->getMessage()]);
            exit();
        } else {
            // Para solicitudes POST normales, establecer mensaje y redirigir
            mostrarMensaje("Error: " . $e->getMessage(), "danger");
            // Mantener los parámetros de edición si es un error al editar
            $redirect_id = $_POST['id_pedido'] ?? null;
            if ($redirect_id) {
                header("Location: pedidos.php?view=details&id=" . htmlspecialchars($redirect_id));
            } else {
                header("Location: pedidos.php"); // O a la vista de lista si no hay ID
            }
            exit();
        }
    }
}

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

// --- Lógica para CARGAR DATOS EN LA VISTA ---
$view = $_GET['view'] ?? 'list';
$pedidos = [];
$pedido_actual = null;
$detalles_pedido = [];

if ($view == 'list') {
    try {
        // MODIFICACIÓN: Incluir total_cantidad_facturada y total_unidades_servidas directamente en la consulta principal
        $stmt_pedidos = $pdo->query("
            SELECT
                p.id_pedido,
                p.fecha_pedido,
                p.id_cliente,
                p.id_direccion_envio,
                p.tipo_entrega,
                p.empresa_transporte,
                p.numero_seguimiento,
                p.id_ruta,
                p.estado_pedido,
                p.id_factura_asociada,
                p.id_parte_paqueteria_asociada,
                p.total_pedido_iva_incluido,
                p.total_litros,
                c.nombre_cliente,
                de.nombre_direccion AS nombre_direccion_envio,
                de.direccion AS direccion_envio_completa,
                r.nombre_ruta,
                GROUP_CONCAT(DISTINCT prp.id_parte_ruta) AS partes_ruta_asociadas,
                COUNT(DISTINCT prp.id_parte_ruta) > 0 AS en_parte_ruta_flag,
                COALESCE(SUM(dfv.cantidad), 0) AS total_cantidad_facturada,
                COALESCE(SUM(dfv.unidades_retiradas), 0) AS total_unidades_servidas
            FROM pedidos p
            JOIN clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN rutas r ON p.id_ruta = r.id_ruta
            LEFT JOIN direcciones_envio de ON p.id_direccion_envio = de.id_direccion_envio
            LEFT JOIN partes_ruta_pedidos prp ON p.id_pedido = prp.id_pedido
            LEFT JOIN facturas_ventas fv ON p.id_factura_asociada = fv.id_factura
            LEFT JOIN detalle_factura_ventas dfv ON fv.id_factura = dfv.id_factura
            GROUP BY
                p.id_pedido, p.fecha_pedido, p.id_cliente, p.id_direccion_envio, p.tipo_entrega,
                p.empresa_transporte, p.numero_seguimiento, p.id_ruta, p.estado_pedido,
                p.id_factura_asociada, p.id_parte_paqueteria_asociada, p.total_pedido_iva_incluido,
                p.total_litros, c.nombre_cliente, de.nombre_direccion, de.direccion, r.nombre_ruta
            ORDER BY p.fecha_pedido DESC, p.id_pedido DESC
        ");
        $pedidos = $stmt_pedidos->fetchAll(PDO::FETCH_ASSOC);

        // Post-process to determine estado_servicio (now with guaranteed keys)
        foreach ($pedidos as &$pedido) {
            if ($pedido['id_factura_asociada'] === null) {
                $pedido['estado_servicio_calculado'] = 'Pendiente de Servir (sin factura)';
            } elseif ($pedido['total_cantidad_facturada'] > 0 && $pedido['total_unidades_servidas'] == 0) {
                $pedido['estado_servicio_calculado'] = 'Pendiente de Servir (vía factura)';
            } elseif ($pedido['total_unidades_servidas'] > 0 && $pedido['total_unidades_servidas'] < $pedido['total_cantidad_facturada']) {
                $pedido['estado_servicio_calculado'] = 'Parcialmente Servido';
            } elseif ($pedido['total_unidades_servidas'] > 0 && $pedido['total_unidades_servidas'] == $pedido['total_cantidad_facturada'] && $pedido['total_cantidad_facturada'] > 0) {
                $pedido['estado_servicio_calculado'] = 'Servido Completo';
            } else {
                $pedido['estado_servicio_calculado'] = 'Estado Desconocido'; // Fallback for edge cases
            }
        }
        unset($pedido); // Break the reference with the last element

    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos al cargar pedidos: " . $e->getMessage(), "danger");
    }
} elseif ($view == 'details' && isset($_GET['id'])) {
    $id_pedido = $_GET['id'];
    try {
        $stmt_pedido_actual = $pdo->prepare("
            SELECT
                p.*,
                c.nombre_cliente, c.nif, c.telefono, c.email, c.direccion AS cliente_direccion_principal, c.ciudad AS cliente_ciudad_principal, c.provincia AS cliente_provincia_principal, c.codigo_postal AS cliente_codigo_postal_principal, -- NEW: Fetch client's main address
                de.nombre_direccion AS nombre_direccion_envio, -- MODIFICACIÓN: Nombre de la dirección de envío
                de.direccion AS direccion_envio, -- MODIFICACIÓN: Dirección de envío
                de.ciudad AS ciudad_envio,
                de.provincia AS provincia_envio,
                de.codigo_postal AS codigo_postal_envio,
                r.nombre_ruta,
                GROUP_CONCAT(DISTINCT prp.id_parte_ruta) AS partes_ruta_asociadas,
                COUNT(DISTINCT prp.id_parte_ruta) > 0 AS en_parte_ruta_flag
            FROM pedidos p
            JOIN clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN rutas r ON p.id_ruta = r.id_ruta
            LEFT JOIN direcciones_envio de ON p.id_direccion_envio = de.id_direccion_envio -- MODIFICACIÓN: JOIN con direcciones_envio
            LEFT JOIN partes_ruta_pedidos prp ON p.id_pedido = prp.id_pedido
            WHERE p.id_pedido = ?
            GROUP BY p.id_pedido
        ");
        $stmt_pedido_actual->execute([$id_pedido]);
        $pedido_actual = $stmt_pedido_actual->fetch(PDO::FETCH_ASSOC);

        error_log("DEBUG: Pedido actual ID: " . $id_pedido . ", Tipo de entrega recuperado: " . ($pedido_actual['tipo_entrega'] ?? 'NULL')); // Debugging line

        if (!$pedido_actual) {
            mostrarMensaje("Pedido no encontrado.", "danger");
            header("Location: pedidos.php");
            exit();
        }

        // Fetch total_cantidad_facturada and total_unidades_servidas for this specific order
        // This needs to be a separate query as it's based on facturas_ventas linked to this pedido
        $stmt_service_status = $pdo->prepare("
            SELECT
                COALESCE(SUM(dfv.cantidad), 0) AS total_cantidad_facturada,
                COALESCE(SUM(dfv.unidades_retiradas), 0) AS total_unidades_servidas
            FROM pedidos p
            LEFT JOIN facturas_ventas fv ON p.id_factura_asociada = fv.id_factura
            LEFT JOIN detalle_factura_ventas dfv ON fv.id_factura = dfv.id_factura
            WHERE p.id_pedido = ?
            GROUP BY p.id_pedido
        ");
        $stmt_service_status->execute([$id_pedido]);
        $service_status_data = $stmt_service_status->fetch(PDO::FETCH_ASSOC);

        $pedido_actual['total_cantidad_facturada'] = $service_status_data['total_cantidad_facturada'] ?? 0;
        $pedido_actual['total_unidades_servidas'] = $service_status_data['total_unidades_servidas'] ?? 0;

        // Determine estado_servicio for the single pedido
        if ($pedido_actual['id_factura_asociada'] === null) {
            $pedido_actual['estado_servicio_calculado'] = 'Pendiente de Servir (sin factura)';
        } elseif ($pedido_actual['total_cantidad_facturada'] > 0 && $pedido_actual['total_unidades_servidas'] == 0) { // Corrected: $pedido to $pedido_actual
            $pedido_actual['estado_servicio_calculado'] = 'Pendiente de Servir (vía factura)';
        } elseif ($pedido_actual['total_unidades_servidas'] > 0 && $pedido_actual['total_unidades_servidas'] < $pedido_actual['total_cantidad_facturada']) {
            $pedido_actual['estado_servicio_calculado'] = 'Parcialmente Servido';
        } elseif ($pedido_actual['total_unidades_servidas'] > 0 && $pedido_actual['total_unidades_servidas'] == $pedido_actual['total_cantidad_facturada'] && $pedido_actual['total_cantidad_facturada'] > 0) {
            $pedido_actual['estado_servicio_calculado'] = 'Servido Completo';
        } else {
            $pedido_actual['estado_servicio_calculado'] = 'Estado Desconocido'; // Fallback
        }


        $stmt_detalles = $pdo->prepare("
            SELECT dp.*, prod.nombre_producto, COALESCE(prod.litros_por_unidad, 1.00) AS litros_por_unidad
            FROM detalle_pedidos dp
            JOIN productos prod ON dp.id_producto = prod.id_producto
            WHERE dp.id_pedido = ?
            ORDER BY dp.id_detalle_pedido ASC
        ");
        $stmt_detalles->execute([$id_pedido]);
        $detalles_pedido = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos al cargar detalles del pedido: " . $e->getMessage(), "danger");
        header("Location: pedidos.php");
        exit();
    }
}

// Si se recibe un ID de cliente para un nuevo pedido (desde clientes.php)
$new_invoice_client_id = $_GET['new_invoice_client_id'] ?? null;
$new_invoice_client_name = $_GET['new_invoice_client_name'] ?? '';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Pedidos</title>
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
        /* COLORES ESPECÍFICOS PARA PEDIDOS */
        .card-header {
            background-color: #28a745; /* Verde para encabezados de card */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #28a745; /* Verde para botones primarios de pedidos */
            border-color: #28a745;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background-color: #218838; /* Verde más oscuro al pasar el ratón */
            border-color: #218838;
        }
        .btn-success {
            background-color: #17a2b8; /* Azul claro para éxito/crear factura */
            border-color: #17a2b8;
            border-radius: 8px;
        }
        .btn-success:hover {
            background-color: #138496;
            border-color: #138496;
        }
        .btn-info {
            background-color: #ffc107; /* Amarillo para info/ver */
            border-color: #ffc107;
            color: #343a40; /* Texto oscuro para contraste */
            border-radius: 8px;
        }
        .btn-info:hover {
            background-color: #e0a800;
            border-color: #e0a800;
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
            background-color: #28a745; /* Verde para encabezados de modal de pedidos */
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

        /* Styles for client search dropdown */
        .client-search-container {
            position: relative;
        }
        .client-search-results {
            position: absolute;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .client-search-results-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        .client-search-results-item:last-child {
            border-bottom: none;
        }
        .client-search-results-item:hover {
            background-color: #f8f9fa;
        }

        /* Custom style for scrollable table */
        .scrollable-table-container {
            max-height: 750px; /* Adjusted height for about 10-15 rows */
            overflow-y: auto;
            border: 1px solid #e9ecef; /* Optional: add a border to the scrollable area */
            border-radius: 8px;
        }
        .scrollable-table-container table {
            margin-bottom: 0; /* Remove default table margin if inside a scrollable div */
        }
        /* Sticky table headers */
        .scrollable-table-container thead th {
            position: sticky;
            top: 0;
            background-color: #e9ecef; /* Match existing header background */
            z-index: 10; /* Ensure it stays above scrolling content */
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1); /* Optional: subtle shadow for depth */
        }


        /* New badges for service status */
        .badge.bg-served-complete {
            background-color: #28a745 !important; /* Green */
        }
        .badge.bg-served-partial {
            background-color: #fd7e14 !important; /* Orange */
        }
        .badge.bg-pending-invoice {
            background-color: #6c757d !important; /* Gray */
        }
        .badge.bg-pending-no-invoice {
            background-color: #ffc107 !important; /* Yellow */
            color: #212529 !important; /* Dark text for contrast */
        }
        .badge.bg-purple {
            background-color: #6f42c1; /* Púrpura */
            color: white;
        }
        /* Custom badge for Entrega en Molino */
        .badge.bg-molino-delivery {
            background-color: #d2b48c !important; /* Light brown/tan */
            color: #343a40 !important; /* Dark text for contrast */
        }

        /* NEW: Styles for 3-column layout in order header details (similar to invoice) */
        .order-header-details p {
            margin-bottom: 0.5rem; /* Reduce space between paragraphs */
        }
        .order-header-financials .form-control {
            max-width: 120px; /* Adjust width of input fields for discount/surcharge */
        }
        /* Justify right for financial values in header */
        .order-header-financials .col-md-3 p {
            text-align: right;
        }
        .order-header-financials .col-md-3 p strong {
            float: left; /* Keep labels left-aligned */
        }
        .order-header-financials .col-md-3 p span {
            display: inline-block; /* Allow span to respect text-align: right */
        }
        /* Specific alignment for discount/surcharge input fields */
        .order-header-financials .d-flex .form-control {
            text-align: right; /* Justify input values to the right */
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluir la barra lateral ?>
        <div class="content">
            <div class="container-fluid">
                <h1 class="mb-4">Gestión de Pedidos</h1>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($view == 'list'): ?>
                    <div class="card">
                        <div class="card-header">
                            Lista de Pedidos
                        </div>
                        <div class="card-body">
                            <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addPedidoModal">
                                <i class="bi bi-plus-circle"></i> Nuevo Pedido
                            </button>
                            <a href="informe_necesidades_envasado.php" class="btn btn-info mb-3 ms-2">
                                <i class="bi bi-box-seam"></i> Informe Necesidades Envasado
                            </a>

                            <!-- Main Service Status Filter Buttons -->
                            <button class="btn btn-warning mb-3 ms-2" id="filterPendingServiceBtn">
                                <i class="bi bi-funnel"></i> Pedidos Pendientes
                            </button>
                            <button class="btn btn-success mb-3 ms-2" id="filterCompletedServiceBtn">
                                <i class="bi bi-check-circle"></i> Pedidos Completados
                            </button>

                            <!-- Delivery Type Sub-Filter Buttons (initially hidden) -->
                            <div id="deliveryTypeFilters" style="display:none;">
                                <button class="btn btn-primary mb-3 ms-2" id="filterRepartoPropioBtn">
                                    <i class="bi bi-truck"></i> Reparto Propio
                                </button>
                                <button class="btn btn-info mb-3 ms-2" id="filterEnvioPaqueteriaBtn">
                                    <i class="bi bi-box"></i> Envío Paquetería
                                </button>
                                <button class="btn btn-success mb-3 ms-2" id="filterEntregaMolinoBtn">
                                    <i class="bi bi-shop"></i> Entrega en Molino
                                </button>
                            </div>
                            
                            <!-- Show All Orders Button (initially hidden) -->
                            <button class="btn btn-secondary mb-3 ms-2" id="showAllOrdersBtn" style="display:none;">
                                <i class="bi bi-arrow-counterclockwise"></i> Mostrar Todos
                            </button>

                            <div class="table-responsive scrollable-table-container">
                                <table class="table table-striped table-hover" id="pedidosListTable">
                                    <thead>
                                        <tr>
                                            <th>ID Pedido</th>
                                            <th>Fecha</th>
                                            <th>Cliente</th>
                                            <th>Dirección Envío</th> <!-- MODIFICACIÓN: Nueva columna -->
                                            <th>Tipo Entrega</th>
                                            <!-- <th>Empresa Transp.</th> REMOVED -->
                                            <th>Nº Seguimiento</th>
                                            <th>Ruta Asignada</th>
                                            <th>Estado de Ruta</th> <!-- Columna para estado de ruta -->
                                            <th>Estado de Servicio</th> <!-- Nueva columna para estado de servicio -->
                                            <th class="text-end">Total Litros</th> <!-- NEW COLUMN -->
                                            <th class="text-end">Total (IVA Inc.)</th>
                                            <th>Estado Pedido</th>
                                            <th>Factura Asociada</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($pedidos)): ?>
                                            <tr>
                                                <td colspan="12" class="text-center">No hay pedidos registrados.</td> <!-- MODIFICACIÓN: colspan ajustado -->
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($pedidos as $pedido):
                                                $badge_class_pedido = '';
                                                switch ($pedido['estado_pedido']) {
                                                    case 'pendiente':
                                                        $badge_class_pedido = 'bg-warning text-dark';
                                                        break;
                                                    case 'completado':
                                                        $badge_class_pedido = 'bg-success';
                                                        break;
                                                    case 'cancelado':
                                                        $badge_class_pedido = 'bg-danger';
                                                        break;
                                                    case 'en_transito_paqueteria':
                                                        $badge_class_pedido = 'bg-info';
                                                        break;
                                                    case 'parcialmente_facturado':
                                                        $badge_class_pedido = 'bg-primary'; // Custom for partially invoiced
                                                        break;
                                                }

                                                // Estado de Ruta
                                                $estado_ruta_texto = 'Sin Ruta Asignada';
                                                $badge_class_ruta = 'bg-secondary';
                                                if ($pedido['tipo_entrega'] == 'reparto_propio') {
                                                    if (!empty($pedido['nombre_ruta'])) {
                                                        if ($pedido['en_parte_ruta_flag']) {
                                                            $estado_ruta_texto = 'En Parte de Ruta';
                                                            $badge_class_ruta = 'bg-info';
                                                        } else {
                                                            $estado_ruta_texto = 'Asignado (Pendiente de Parte)';
                                                            $badge_class_ruta = 'bg-primary';
                                                        }
                                                    }
                                                } else if ($pedido['tipo_entrega'] == 'entrega_en_molino') {
                                                    $estado_ruta_texto = 'N/A (Entrega en Molino)';
                                                    $badge_class_ruta = 'bg-molino-delivery'; // Custom color for Entrega en Molino
                                                } else { // envio_paqueteria
                                                    $estado_ruta_texto = 'N/A (Paquetería)';
                                                    $badge_class_ruta = 'bg-dark';
                                                }


                                                // Estado de Servicio
                                                $estado_servicio_texto = $pedido['estado_servicio_calculado'];
                                                $badge_class_servicio = '';
                                                switch ($estado_servicio_texto) {
                                                    case 'Servido Completo':
                                                        $badge_class_servicio = 'bg-served-complete';
                                                        break;
                                                    case 'Parcialmente Servido':
                                                        $badge_class_servicio = 'bg-served-partial';
                                                        break;
                                                    case 'Pendiente de Servir (vía factura)':
                                                        $badge_class_servicio = 'bg-pending-invoice';
                                                        break;
                                                    case 'Pendiente de Servir (sin factura)':
                                                        $badge_class_servicio = 'bg-pending-no-invoice';
                                                        break;
                                                    default:
                                                        $badge_class_servicio = 'bg-dark'; // Fallback
                                                        break;
                                                }
                                            ?>
                                                <tr
                                                    data-id-pedido="<?php echo htmlspecialchars($pedido['id_pedido']); ?>"
                                                    data-en-parte-ruta-flag="<?php echo htmlspecialchars($pedido['en_parte_ruta_flag']); ?>"
                                                    data-id-parte-paqueteria-asociada="<?php echo htmlspecialchars($pedido['id_parte_paqueteria_asociada'] ?? ''); ?>"
                                                    data-tipo-entrega="<?php echo htmlspecialchars($pedido['tipo_entrega']); ?>"
                                                    data-estado-servicio-calculado="<?php echo htmlspecialchars($estado_servicio_texto); ?>"
                                                    data-total-litros="<?php echo htmlspecialchars($pedido['total_litros']); ?>"
                                                    data-total-iva-inc="<?php echo htmlspecialchars($pedido['total_pedido_iva_incluido']); ?>"
                                                >
                                                    <td><?php echo htmlspecialchars($pedido['id_pedido']); ?></td>
                                                    <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($pedido['fecha_pedido']))); ?></td>
                                                    <td><?php echo htmlspecialchars($pedido['nombre_cliente']); ?></td>
                                                    <td>
                                                        <?php
                                                            // MODIFICACIÓN: Mostrar nombre y dirección de envío o dirección principal del cliente
                                                            if (!empty($pedido['nombre_direccion_envio'])) {
                                                                echo htmlspecialchars($pedido['nombre_direccion_envio']);
                                                                if (!empty($pedido['direccion_envio_completa'])) {
                                                                    echo ' <small class="text-muted">(' . htmlspecialchars($pedido['direccion_envio_completa']) . ')</small>';
                                                                }
                                                            } else {
                                                                // Display client's main address if no specific shipping address is chosen
                                                                $client_main_address = $clientes_map[$pedido['id_cliente']]['direccion'] ?? 'N/A';
                                                                $client_main_city = $clientes_map[$pedido['id_cliente']]['ciudad'] ?? 'N/A';
                                                                echo 'Dirección Principal';
                                                                echo ' <small class="text-muted">(' . htmlspecialchars($client_main_address) . ', ' . htmlspecialchars($client_main_city) . ')</small>';
                                                            }
                                                        ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $pedido['tipo_entrega']))); ?></td>
                                                    <!-- <td><?php echo htmlspecialchars($pedido['empresa_transporte'] ?: 'N/A'); ?></td> REMOVED -->
                                                    <td>
                                                        <?php
                                                        echo htmlspecialchars($pedido['numero_seguimiento'] ?: 'N/A');
                                                        if ($pedido['tipo_entrega'] == 'envio_paqueteria' && isset($pedido['id_parte_paqueteria_asociada']) && $pedido['id_parte_paqueteria_asociada']) { // Check if 'id_parte_paqueteria_asociada' exists
                                                            echo ' <a href="partes_paqueteria.php?view=details&id=' . htmlspecialchars($pedido['id_parte_paqueteria_asociada']) . '" class="badge bg-purple" title="Ver Parte de Paquetería"><i class="bi bi-box-seam"></i></a>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($pedido['nombre_ruta'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo htmlspecialchars($badge_class_ruta); ?>">
                                                            <?php echo htmlspecialchars($estado_ruta_texto); ?>
                                                        </span>
                                                        <?php if ($pedido['en_parte_ruta_flag'] && !empty($pedido['partes_ruta_asociadas'])): ?>
                                                            <small class="d-block text-muted">Partes: <?php echo htmlspecialchars($pedido['partes_ruta_asociadas']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo htmlspecialchars($badge_class_servicio); ?>">
                                                            <?php echo htmlspecialchars($estado_servicio_texto); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end"><?php echo number_format($pedido['total_litros'], 2, ',', '.'); ?></td> <!-- NEW -->
                                                    <td class="text-end"><?php echo number_format($pedido['total_pedido_iva_incluido'], 2, ',', '.'); ?> €</td>
                                                    <td><span class="badge <?php echo htmlspecialchars($badge_class_pedido); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $pedido['estado_pedido']))); ?></span></td>
                                                    <td>
                                                        <?php if ($pedido['id_factura_asociada']): ?>
                                                            <a href="facturas_ventas.php?view=details&id=<?php echo htmlspecialchars($pedido['id_factura_asociada']); ?>" class="badge bg-primary">Factura #<?php echo htmlspecialchars($pedido['id_factura_asociada']); ?></a>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Ninguna</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <a href="pedidos.php?view=details&id=<?php echo htmlspecialchars($pedido['id_pedido']); ?>" class="btn btn-info btn-sm me-1" title="Ver Detalles">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <button class="btn btn-danger btn-sm" onclick="confirmDeletePedido(<?php echo htmlspecialchars($pedido['id_pedido']); ?>)" title="Eliminar Pedido">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-group-divider">
                                            <th colspan="9" class="text-end">Total General:</th> <!-- MODIFICACIÓN: colspan ajustado -->
                                            <th class="text-end" id="totalLitrosGeneral">0.00</th>
                                            <th class="text-end" id="totalIvaIncGeneral">0.00 €</th>
                                            <th colspan="2"></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Añadir Nuevo Pedido -->
                    <div class="modal fade" id="addPedidoModal" tabindex="-1" aria-labelledby="addPedidoModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="pedidos.php" method="POST" id="pedidoForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addPedidoModalLabel">Nuevo Pedido</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="agregar_pedido">
                                        <div class="mb-3">
                                            <label for="fecha_pedido" class="form-label">Fecha Pedido</label>
                                            <input type="date" class="form-control" id="fecha_pedido" name="fecha_pedido" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="client_search_input" class="form-label">Cliente</label>
                                            <div class="client-search-container">
                                                <input type="text" class="form-control" id="client_search_input" placeholder="Buscar cliente por nombre, NIF, ciudad..." autocomplete="off" value="<?php echo htmlspecialchars($new_invoice_client_name); ?>">
                                                <input type="hidden" id="id_cliente_selected" name="id_cliente" value="<?php echo htmlspecialchars($new_invoice_client_id); ?>">
                                                <div id="client_search_results" class="client-search-results"></div>
                                            </div>
                                            <small class="text-danger" id="client_selection_error" style="display:none;">Por favor, seleccione un cliente de la lista.</small>
                                            <div class="mt-2">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="createNewClientFromOrderModal()">
                                                    <i class="bi bi-person-plus"></i> Crear Nuevo Cliente
                                                </button>
                                            </div>
                                        </div>
                                        <!-- MODIFICACIÓN: Nuevo campo para Dirección de Envío con búsqueda -->
                                        <div class="mb-3" id="shippingAddressGroup" style="display: none;">
                                            <label for="shipping_address_search_input" class="form-label">Dirección de Envío</label>
                                            <div class="client-search-container">
                                                <input type="text" class="form-control" id="shipping_address_search_input" placeholder="Buscar dirección de envío..." autocomplete="off">
                                                <input type="hidden" id="id_direccion_envio_selected" name="id_direccion_envio">
                                                <div id="shipping_address_search_results" class="client-search-results"></div>
                                            </div>
                                            <small class="text-danger" id="shipping_address_error" style="display:none;">Por favor, seleccione una dirección de envío.</small>
                                            <small class="text-muted" id="no_shipping_addresses_info" style="display:none;">Este cliente no tiene direcciones de envío registradas. Se usará la dirección principal del cliente.</small>
                                        </div>

                                        <div class="mb-3">
                                            <label for="estado_pedido" class="form-label">Estado</label>
                                            <select class="form-select" id="estado_pedido" name="estado_pedido" required>
                                                <option value="pendiente">Pendiente</option>
                                                <option value="completado">Completado</option>
                                                <option value="cancelado">Cancelado</option>
                                                <option value="en_transito_paqueteria">En Tránsito (Paquetería)</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="tipo_entrega" class="form-label">Tipo de Entrega</label>
                                            <select class="form-select" id="tipo_entrega" name="tipo_entrega" required>
                                                <option value="reparto_propio">Reparto Propio (Ruta)</option>
                                                <option value="envio_paqueteria">Envío por Paquetería</option>
                                                <option value="entrega_en_molino" selected>Entrega en Molino</option>
                                            </select>
                                        </div>
                                        <div class="mb-3" id="empresaTransporteGroup" style="display: none;">
                                            <label for="empresa_transporte" class="form-label">Empresa de Transporte</label>
                                            <input type="text" class="form-control" id="empresa_transporte" name="empresa_transporte" placeholder="Nombre de la empresa de paquetería">
                                        </div>
                                        <div class="mb-3" id="numeroSeguimientoGroup" style="display: none;">
                                            <label for="numero_seguimiento" class="form-label">Número de Seguimiento (URL)</label>
                                            <input type="url" class="form-control" id="numero_seguimiento" name="numero_seguimiento" placeholder="URL de seguimiento de paquetería (ej: https://www.ejemplo.com/tracking/123)">
                                        </div>
                                        <div class="mb-3" id="rutaGroup" style="display: none;">
                                            <label for="id_ruta" class="form-label">Ruta</label>
                                            <select class="form-select" id="id_ruta" name="id_ruta">
                                                <option value="">Seleccione una ruta</option>
                                                <?php foreach ($rutas_disponibles as $ruta): ?>
                                                    <option value="<?php echo htmlspecialchars($ruta['id_ruta']); ?>">
                                                        <?php echo htmlspecialchars($ruta['nombre_ruta']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-danger" id="ruta_selection_error" style="display:none;">Debe seleccionar una ruta.</small>
                                        </div>
                                        <div class="mb-3">
                                            <label for="observaciones" class="form-label">Observaciones</label>
                                            <textarea class="form-control" id="observaciones" name="observaciones" rows="3"></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary" id="submitNewPedidoBtn">Crear Pedido</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php elseif ($view == 'details' && $pedido_actual): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            Detalles del Pedido #<?php echo htmlspecialchars($pedido_actual['id_pedido']); ?>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3 order-header-details">
                                <div class="col-md-6">
                                    <p><strong>Fecha Pedido:</strong> <?php echo htmlspecialchars(date('d/m/Y', strtotime($pedido_actual['fecha_pedido']))); ?></p>
                                    <p>
                                        <strong>Cliente:</strong>
                                        <?php echo htmlspecialchars($pedido_actual['nombre_cliente']); ?>
                                        <a href="clientes.php?id=<?php echo htmlspecialchars($pedido_actual['id_cliente']); ?>" class="btn btn-sm btn-outline-secondary ms-2" title="Ver/Modificar Ficha Cliente">
                                            <i class="bi bi-person-lines-fill"></i> Ver Ficha
                                        </a>
                                    </p>
                                    <p>
                                        <strong>Dirección de Envío:</strong>
                                        <?php
                                            // MODIFICACIÓN: Mostrar dirección de envío del pedido o dirección principal del cliente
                                            if (!empty($pedido_actual['nombre_direccion_envio'])) {
                                                echo htmlspecialchars($pedido_actual['nombre_direccion_envio']);
                                                echo ' <small class="text-muted">(' . htmlspecialchars($pedido_actual['direccion_envio'] ?? '') . ', ' . htmlspecialchars($pedido_actual['ciudad_envio'] ?? '') . ', ' . htmlspecialchars($pedido_actual['provincia_envio'] ?? '') . ' ' . htmlspecialchars($pedido_actual['codigo_postal_envio'] ?? '') . ')</small>';
                                            } else {
                                                // Display client's main address if no specific shipping address is chosen
                                                $client_main_address_full = '';
                                                if (!empty($pedido_actual['cliente_direccion_principal'])) {
                                                    $client_main_address_full .= htmlspecialchars($pedido_actual['cliente_direccion_principal']);
                                                }
                                                if (!empty($pedido_actual['cliente_ciudad_principal'])) {
                                                    $client_main_address_full .= (!empty($client_main_address_full) ? ', ' : '') . htmlspecialchars($pedido_actual['cliente_ciudad_principal']);
                                                }
                                                if (!empty($pedido_actual['cliente_provincia_principal'])) {
                                                    $client_main_address_full .= (!empty($client_main_address_full) ? ', ' : '') . htmlspecialchars($pedido_actual['cliente_provincia_principal']);
                                                }
                                                if (!empty($pedido_actual['cliente_codigo_postal_principal'])) {
                                                    $client_main_address_full .= (!empty($client_main_address_full) ? ' ' : '') . htmlspecialchars($pedido_actual['cliente_codigo_postal_principal']);
                                                }

                                                echo 'Dirección Principal del Cliente';
                                                if (!empty($client_main_address_full)) {
                                                    echo ' <small class="text-muted">(' . $client_main_address_full . ')</small>';
                                                }
                                            }
                                        ?>
                                    </p>
                                    <p><strong>NIF:</strong> <?php echo htmlspecialchars($pedido_actual['nif'] ?? 'N/A'); ?></p>
                                    <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($pedido_actual['telefono'] ?? 'N/A'); ?></p>
                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($pedido_actual['email'] ?? 'N/A'); ?></p>
                                    <!-- Las direcciones principales del cliente se han movido a la sección de direcciones de envío en el card de cliente en clientes.php -->
                                </div>
                                <div class="col-md-3 order-header-financials">
                                    <!-- Formulario para Descuento Global -->
                                    <form id="updateDiscountFormPedido" action="pedidos.php" method="POST" class="mb-2">
                                        <input type="hidden" name="accion" value="update_global_discount_pedido">
                                        <input type="hidden" name="id_pedido" value="<?php echo htmlspecialchars($pedido_actual['id_pedido']); ?>">
                                        <div class="d-flex justify-content-start align-items-center">
                                            <label for="descuento_global_edit_pedido" class="form-label mb-0 me-2"><strong>Descuento Global:</strong></label>
                                            <input type="number" class="form-control w-auto text-end me-2" id="descuento_global_edit_pedido" name="descuento_global" step="0.01" min="0" value="<?php echo number_format($pedido_actual['descuento_global_aplicado'], 2, '.', ''); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Actualizar Descuento">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                    </form>
                                    <!-- Formulario para Recargo Global -->
                                    <form id="updateSurchargeFormPedido" action="pedidos.php" method="POST" class="mb-2">
                                        <input type="hidden" name="accion" value="update_global_surcharge_pedido">
                                        <input type="hidden" name="id_pedido" value="<?php echo htmlspecialchars($pedido_actual['id_pedido']); ?>">
                                        <div class="d-flex justify-content-start align-items-center">
                                            <label for="recargo_global_edit_pedido" class="form-label mb-0 me-2"><strong>Recargo Global:</strong></label>
                                            <input type="number" class="form-control w-auto text-end me-2" id="recargo_global_edit_pedido" name="recargo_global" step="0.01" min="0" value="<?php echo number_format($pedido_actual['recargo_global_aplicado'], 2, '.', ''); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Actualizar Recargo">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                    </form>
                                    <p><strong>Base Imponible Total:</strong> <span class="fs-5 text-dark"><?php echo number_format($pedido_actual['total_base_imponible_pedido'], 2, ',', '.'); ?> €</span></p>
                                    <p><strong>Total IVA:</strong> <span class="fs-5 text-dark"><?php echo number_format($pedido_actual['total_iva_pedido'], 2, ',', '.'); ?> €</span></p>
                                </div>
                                <div class="col-md-3">
                                    <p><strong>Total Pedido (IVA Inc.):</strong> <span class="fs-4 text-primary"><?php echo number_format($pedido_actual['total_pedido_iva_incluido'], 2, ',', '.'); ?> €</span></p>
                                    <p><strong>Total Litros:</strong> <span class="fs-5 text-dark"><?php echo number_format($pedido_actual['total_litros'], 2, ',', '.'); ?></span></p> <!-- NEW -->
                                    <p><strong>Tipo de Entrega:</strong> <span class="badge bg-secondary fs-6"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $pedido_actual['tipo_entrega'] ?? 'No especificado'))); ?></span></p>
                                    <?php if ($pedido_actual['tipo_entrega'] == 'envio_paqueteria'): ?>
                                        <p><strong>Empresa Transporte:</strong> <?php echo htmlspecialchars($pedido_actual['empresa_transporte'] ?: 'N/A'); ?></p>
                                        <p>
                                            <strong>Número Seguimiento:</strong>
                                            <?php
                                            echo htmlspecialchars($pedido_actual['numero_seguimiento'] ?: 'N/A');
                                            // Corrected: Use $pedido_actual instead of $pedido and ensure value is not null before htmlspecialchars
                                            if (isset($pedido_actual['id_parte_paqueteria_asociada']) && !empty($pedido_actual['id_parte_paqueteria_asociada'])) {
                                                echo ' <a href="partes_paqueteria.php?view=details&id=' . htmlspecialchars($pedido_actual['id_parte_paqueteria_asociada']) . '" class="badge bg-purple" title="Ver Parte de Paquetería"><i class="bi bi-box-seam"></i></a>';
                                            }
                                            ?>
                                        </p>
                                        <!-- NUEVO BOTÓN DE SEGUIMIENTO -->
                                        <?php if (!empty($pedido_actual['numero_seguimiento'])): ?>
                                            <a href="<?php echo htmlspecialchars($pedido_actual['numero_seguimiento']); ?>" target="_blank" class="btn btn-info btn-sm mt-2">
                                                <i class="bi bi-truck"></i> Ver Estado del Envío
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($pedido_actual['tipo_entrega'] == 'reparto_propio'): ?>
                                        <p><strong>Ruta Asignada:</strong> <?php echo htmlspecialchars($pedido_actual['nombre_ruta'] ?? 'N/A'); ?></p>
                                        <p>
                                            <strong>Estado de Ruta:</strong>
                                            <?php
                                                $estado_ruta_texto_det = 'Sin Ruta Asignada';
                                                $badge_class_ruta_det = 'bg-secondary';
                                                if (!empty($pedido_actual['nombre_ruta'])) {
                                                    if ($pedido_actual['en_parte_ruta_flag']) {
                                                        $estado_ruta_texto_det = 'En Parte de Ruta';
                                                        $badge_class_ruta_det = 'bg-info';
                                                    } else {
                                                        $estado_ruta_texto_det = 'Asignado (Pendiente de Parte)';
                                                        $badge_class_ruta_det = 'bg-primary';
                                                    }
                                                }
                                            ?>
                                            <span class="badge <?php echo htmlspecialchars($badge_class_ruta_det); ?>">
                                                <?php echo htmlspecialchars($estado_ruta_texto_det); ?>
                                            </span>
                                            <?php if ($pedido_actual['en_parte_ruta_flag'] && !empty($pedido_actual['partes_ruta_asociadas'])): ?>
                                                <small class="d-block text-muted">Partes de Ruta:
                                                    <?php
                                                        $partes_ids = explode(',', $pedido_actual['partes_ruta_asociadas']);
                                                        $links = [];
                                                        foreach ($partes_ids as $p_id) {
                                                            $links[] = "<a href='partes_ruta.php?view=details&id=" . htmlspecialchars($p_id) . "' class='badge bg-light text-dark'>#" . htmlspecialchars($p_id) . "</a>";
                                                        }
                                                        echo implode(', ', $links);
                                                    ?>
                                                </small>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                    <p>
                                        <strong>Estado de Servicio:</strong>
                                        <?php
                                            $estado_servicio_texto_det = $pedido_actual['estado_servicio_calculado'];
                                            $badge_class_servicio_det = '';
                                            switch ($estado_servicio_texto_det) {
                                                case 'Servido Completo':
                                                    $badge_class_servicio_det = 'bg-served-complete';
                                                    break;
                                                case 'Parcialmente Servido':
                                                    $badge_class_servicio_det = 'bg-served-partial';
                                                    break;
                                                case 'Pendiente de Servir (vía factura)':
                                                    $badge_class_servicio_det = 'bg-pending-invoice';
                                                    break;
                                                case 'Pendiente de Servir (sin factura)':
                                                    $badge_class_servicio_det = 'bg-pending-no-invoice';
                                                    break;
                                                default:
                                                    $badge_class_servicio_det = 'bg-dark'; // Fallback
                                                    break;
                                            }
                                        ?>
                                        <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($pedido_actual['observaciones'] ?? 'N/A'); ?></p>
                                        <span class="badge <?php echo htmlspecialchars($badge_class_servicio_det); ?> fs-6"><?php echo htmlspecialchars($estado_servicio_texto_det); ?></span>
                                    </p>
                                    <?php
                                        $badge_class_pedido_det = '';
                                        switch ($pedido_actual['estado_pedido']) {
                                            case 'pendiente': $badge_class_pedido_det = 'bg-warning text-dark'; break;
                                            case 'completado': $badge_class_pedido_det = 'bg-success'; break;
                                            case 'cancelado': $badge_class_pedido_det = 'bg-danger'; break;
                                            case 'en_transito_paqueteria': $badge_class_pedido_det = 'bg-info'; break;
                                            case 'parcialmente_facturado': $badge_class_pedido_det = 'bg-primary'; break;
                                        }
                                    ?>
                                    <p><strong>Estado Pedido:</strong> <span class="badge <?php echo htmlspecialchars($badge_class_pedido_det); ?> fs-6"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $pedido_actual['estado_pedido']))); ?></span></p>
                                    <?php if ($pedido_actual['id_factura_asociada']): ?>
                                        <p><strong>Factura Asociada:</strong> <a href="facturas_ventas.php?view=details&id=<?php echo htmlspecialchars($pedido_actual['id_factura_asociada']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-receipt"></i> Ver Factura #<?php echo htmlspecialchars($pedido_actual['id_factura_asociada']); ?></a></p>
                                    <?php else: ?>
                                        <p><strong>Factura Asociada:</strong> <span class="badge bg-secondary">Ninguna</span></p>
                                        <button class="btn btn-success mb-2" onclick="createInvoiceFromOrder(<?php echo htmlspecialchars($pedido_actual['id_cliente']); ?>, '<?php echo htmlspecialchars($pedido_actual['nombre_cliente']); ?>', <?php echo htmlspecialchars($pedido_actual['id_pedido']); ?>, <?php echo htmlspecialchars($pedido_actual['id_ruta'] ?? 'null'); ?>)">
                                            <i class="bi bi-receipt"></i> Crear Factura desde Pedido
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="pedidos.php" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Volver a la Lista</a>
                            <button class="btn btn-warning mb-3 ms-2" data-bs-toggle="modal" data-bs-target="#editPedidoModal">
                                <i class="bi bi-pencil"></i> Editar Pedido
                            </button>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            Líneas de Detalle del Pedido
                        </div>
                        <div class="card-body">
                            <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addLineaModal">
                                <i class="bi bi-plus-circle"></i> Añadir Línea
                            </button>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th class="text-end">Cantidad</th>
                                            <th class="text-end">Precio Unitario (IVA Inc.)</th>
                                            <th class="text-end">IVA (%)</th>
                                            <th class="text-end">Base Imponible</th>
                                            <th class="text-end">IVA Línea</th>
                                            <th class="text-end">Total Línea (IVA Inc.)</th>
                                            <th class="text-end">Litros Línea</th> <!-- NEW -->
                                            <th>Observaciones</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($detalles_pedido)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center">No hay líneas de detalle para este pedido.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($detalles_pedido as $detalle): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($detalle['nombre_producto']); ?></td>
                                                    <td class="text-end"><?php echo htmlspecialchars($detalle['cantidad_solicitada']); ?></td>
                                                    <td class="text-end"><?php echo number_format($detalle['precio_unitario_venta'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['iva_porcentaje'], 2, ',', '.'); ?></td>
                                                    <td class="text-end"><?php echo number_format($detalle['subtotal_linea_base'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['subtotal_linea_iva'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['subtotal_linea_total'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['cantidad_solicitada'] * $detalle['litros_por_unidad'], 2, ',', '.'); ?></td> <!-- NEW -->
                                                    <td><?php echo htmlspecialchars($detalle['observaciones_detalle'] ?? 'N/A'); ?></td>
                                                    <td class="text-center">
                                                        <button class="btn btn-danger btn-sm" onclick="confirmDeleteDetalle(<?php echo htmlspecialchars($detalle['id_detalle_pedido']); ?>, <?php echo htmlspecialchars($pedido_actual['id_pedido']); ?>)" title="Eliminar Línea">
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

                    <!-- Modal para Editar Pedido -->
                    <div class="modal fade" id="editPedidoModal" tabindex="-1" aria-labelledby="editPedidoModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="pedidos.php" method="POST" id="editPedidoForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="editPedidoModalLabel">Editar Pedido #<?php echo htmlspecialchars($pedido_actual['id_pedido']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="editar_pedido">
                                        <input type="hidden" name="id_pedido" value="<?php echo htmlspecialchars($pedido_actual['id_pedido']); ?>">
                                        <div class="mb-3">
                                            <label for="edit_fecha_pedido" class="form-label">Fecha Pedido</label>
                                            <input type="date" class="form-control" id="edit_fecha_pedido" name="fecha_pedido" value="<?php echo htmlspecialchars($pedido_actual['fecha_pedido']); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="edit_client_search_input" class="form-label">Cliente</label>
                                            <div class="client-search-container">
                                                <input type="text" class="form-control" id="edit_client_search_input" placeholder="Buscar cliente por nombre, NIF, ciudad..." autocomplete="off" value="<?php echo htmlspecialchars($pedido_actual['nombre_cliente']); ?>">
                                                <input type="hidden" id="edit_id_cliente_selected" name="id_cliente" value="<?php echo htmlspecialchars($pedido_actual['id_cliente']); ?>">
                                                <div id="edit_client_search_results" class="client-search-results"></div>
                                            </div>
                                            <small class="text-danger" id="edit_client_selection_error" style="display:none;">Por favor, seleccione un cliente de la lista.</small>
                                        </div>
                                        <!-- MODIFICACIÓN: Campo para Dirección de Envío en edición con búsqueda -->
                                        <div class="mb-3" id="editShippingAddressGroup">
                                            <label for="edit_shipping_address_search_input" class="form-label">Dirección de Envío</label>
                                            <div class="client-search-container">
                                                <input type="text" class="form-control" id="edit_shipping_address_search_input" placeholder="Buscar dirección de envío..." autocomplete="off">
                                                <input type="hidden" id="edit_id_direccion_envio_selected" name="id_direccion_envio">
                                                <div id="edit_shipping_address_search_results" class="client-search-results"></div>
                                            </div>
                                            <small class="text-danger" id="edit_shipping_address_error" style="display:none;">Por favor, seleccione una dirección de envío.</small>
                                            <small class="text-muted" id="edit_no_shipping_addresses_info" style="display:none;">Este cliente no tiene direcciones de envío registradas. Se usará la dirección principal del cliente.</small>
                                        </div>

                                        <div class="mb-3">
                                            <label for="edit_estado_pedido" class="form-label">Estado</label>
                                            <select class="form-select" id="edit_estado_pedido" name="estado_pedido" required>
                                                <option value="pendiente" <?php echo ($pedido_actual['estado_pedido'] == 'pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                                                <option value="completado" <?php echo ($pedido_actual['estado_pedido'] == 'completado') ? 'selected' : ''; ?>>Completado</option>
                                                <option value="cancelado" <?php echo ($pedido_actual['estado_pedido'] == 'cancelado') ? 'selected' : ''; ?>>Cancelado</option>
                                                <option value="en_transito_paqueteria" <?php echo ($pedido_actual['estado_pedido'] == 'en_transito_paqueteria') ? 'selected' : ''; ?>>En Tránsito (Paquetería)</option>
                                                <option value="parcialmente_facturado" <?php echo ($pedido_actual['estado_pedido'] == 'parcialmente_facturado') ? 'selected' : ''; ?>>Parcialmente Facturado</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="edit_tipo_entrega" class="form-label">Tipo de Entrega</label>
                                            <select class="form-select" id="edit_tipo_entrega" name="tipo_entrega" required>
                                                <option value="reparto_propio" <?php echo ($pedido_actual['tipo_entrega'] == 'reparto_propio') ? 'selected' : ''; ?>>Reparto Propio (Ruta)</option>
                                                <option value="envio_paqueteria" <?php echo ($pedido_actual['tipo_entrega'] == 'envio_paqueteria') ? 'selected' : ''; ?>>Envío por Paquetería</option>
                                                <option value="entrega_en_molino" <?php echo ($pedido_actual['tipo_entrega'] == 'entrega_en_molino') ? 'selected' : ''; ?>>Entrega en Molino</option>
                                            </select>
                                        </div>
                                        <div class="mb-3" id="editEmpresaTransporteGroup" style="display: <?php echo ($pedido_actual['tipo_entrega'] == 'envio_paqueteria') ? 'block' : 'none'; ?>;">
                                            <label for="edit_empresa_transporte" class="form-label">Empresa de Transporte</label>
                                            <input type="text" class="form-control" id="edit_empresa_transporte" name="empresa_transporte" placeholder="Nombre de la empresa de paquetería" value="<?php echo htmlspecialchars($pedido_actual['empresa_transporte'] ?? ''); ?>">
                                        </div>
                                        <div class="mb-3" id="editNumeroSeguimientoGroup" style="display: <?php echo ($pedido_actual['tipo_entrega'] == 'envio_paqueteria') ? 'block' : 'none'; ?>;">
                                            <label for="edit_numero_seguimiento" class="form-label">Número de Seguimiento (URL)</label>
                                            <input type="url" class="form-control" id="edit_numero_seguimiento" name="numero_seguimiento" placeholder="URL de tracking de paquetería (ej: https://www.ejemplo.com/tracking/123)" value="<?php echo htmlspecialchars($pedido_actual['numero_seguimiento'] ?? ''); ?>">
                                        </div>
                                        <div class="mb-3" id="editRutaGroup" style="display: <?php echo ($pedido_actual['tipo_entrega'] == 'reparto_propio') ? 'block' : 'none'; ?>;">
                                            <label for="edit_id_ruta" class="form-label">Ruta</label>
                                            <select class="form-select" id="edit_id_ruta" name="id_ruta">
                                                <option value="">Seleccione una ruta</option>
                                                <?php foreach ($rutas_disponibles as $ruta): ?>
                                                    <option value="<?php echo htmlspecialchars($ruta['id_ruta']); ?>"
                                                        <?php echo ($pedido_actual['id_ruta'] == $ruta['id_ruta']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($ruta['nombre_ruta']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-danger" id="edit_ruta_selection_error" style="display:none;">Debe seleccionar una ruta.</small>
                                        </div>
                                        <div class="mb-3">
                                            <label for="edit_observaciones" class="form-label">Observaciones</label>
                                            <textarea class="form-control" id="edit_observaciones" name="observaciones" rows="3"><?php echo htmlspecialchars($pedido_actual['observaciones'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Añadir Línea de Detalle -->
                    <div class="modal fade" id="addLineaModal" tabindex="-1" aria-labelledby="addLineaModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="pedidos.php" method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addLineaModalLabel">Añadir Línea a Pedido #<?php echo htmlspecialchars($pedido_actual['id_pedido']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="agregar_detalle_linea">
                                        <input type="hidden" name="id_pedido" value="<?php echo htmlspecialchars($pedido_actual['id_pedido']); ?>">
                                        <div class="mb-3">
                                            <label for="id_producto" class="form-label">Producto</label>
                                            <select class="form-select" id="id_producto" name="id_producto" required onchange="updatePrecioUnitario()">
                                                <option value="">Seleccione un producto</option>
                                                <?php foreach ($productos_disponibles as $producto): ?>
                                                    <option
                                                        value="<?php echo htmlspecialchars($producto['id_producto']); ?>"
                                                        data-precio="<?php echo htmlspecialchars($producto['precio_venta'] ?? 0); ?>"
                                                        data-stock="<?php echo htmlspecialchars($producto['stock_actual_unidades'] ?? 0); ?>"
                                                        data-iva="<?php echo htmlspecialchars($producto['porcentaje_iva'] ?? 0); ?>"
                                                        data-litros-por-unidad="<?php echo htmlspecialchars($producto['litros_por_unidad'] ?? 1); ?>"
                                                    >
                                                        <?php echo htmlspecialchars($producto['nombre_producto'] ?? 'N/A'); ?> (Stock actual: <?php echo htmlspecialchars($producto['stock_actual_unidades'] ?? 0); ?> unidades)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted" id="stockProductoInfo"></small>
                                        </div>
                                        <div class="mb-3">
                                            <label for="cantidad_solicitada" class="form-label">Cantidad Solicitada (unidades)</label>
                                            <input type="number" class="form-control" id="cantidad_solicitada" name="cantidad_solicitada" min="1" required oninput="updatePrecioUnitario()">
                                        </div>
                                        <div class="mb-3">
                                            <label for="precio_unitario_venta" class="form-label">Precio Unitario Venta (IVA Inc.)</label>
                                            <input type="number" class="form-control" id="precio_unitario_venta" step="0.01" min="0.01" readonly required>
                                        </div>
                                        <div class="mb-3">
                                            <p><strong>Base Imponible Línea:</strong> <span id="linea_base_display">0.00 €</span></p>
                                            <p><strong>IVA Línea:</strong> <span id="linea_iva_display">0.00 €</span></p>
                                            <p><strong>Total Línea (IVA Incl.):</strong> <span id="linea_total_display">0.00 €</span></p>
                                            <p><strong>Litros Línea:</strong> <span id="linea_litros_display">0.00</span></p> <!-- NEW -->
                                        </div>
                                        <div class="mb-3">
                                            <label for="observaciones_detalle" class="form-label">Observaciones</label>
                                            <textarea class="form-control" id="observaciones_detalle" name="observaciones_detalle" rows="3"></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Añadir Línea</button>
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
        // Mapeo de clientes para acceso rápido por ID
        // MODIFICACIÓN: Ahora clientesMapJs incluye las direcciones de envío
        const clientesMapJs = <?php echo json_encode($clientes_map); ?>;
        const productosMapJs = <?php echo json_encode($productos_map); ?>;

        // Función para enviar formularios dinámicamente
        function createAndSubmitForm(action, inputs) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'pedidos.php';

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

        function confirmDeletePedido(id) {
            showCustomModal("Eliminar Pedido", "¿Seguro que quieres eliminar este pedido? Se eliminarán todas sus líneas de detalle y su asociación con partes de ruta.", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('eliminar_pedido', { id_pedido: id });
            });
        }

        function confirmDeleteDetalle(id, idPedido) {
            showCustomModal("Eliminar Línea de Detalle", "¿Seguro que quieres eliminar esta línea de detalle del pedido?", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('eliminar_detalle_linea', { id_detalle_pedido: id, id_pedido_original: idPedido });
            });
        }

        // Función para actualizar el precio unitario y el stock al seleccionar un producto
        function updatePrecioUnitario() {
            const selectProducto = document.getElementById('id_producto');
            const precioUnitarioInput = document.getElementById('precio_unitario_venta');
            const cantidadInput = document.getElementById('cantidad_solicitada');
            const stockProductoInfo = document.getElementById('stockProductoInfo');

            const lineaBaseDisplay = document.getElementById('linea_base_display');
            const lineaIvaDisplay = document.getElementById('linea_iva_display');
            const lineaTotalDisplay = document.getElementById('linea_total_display');
            const lineaLitrosDisplay = document.getElementById('linea_litros_display'); // NEW

            const selectedOption = selectProducto.options[selectProducto.selectedIndex];
            const selectedProductId = selectedOption.value;

            let cantidad = parseFloat(cantidadInput.value) || 0;
            let precioUnitarioVentaIVAIncluido = 0;
            let ivaPorcentaje = 0;
            let litrosPorUnidad = 0; // NEW

            // Actualizar información de stock y establecer precio del producto seleccionado
            if (selectedProductId && productosMapJs[selectedProductId]) {
                const productData = productosMapJs[selectedProductId];
                precioUnitarioVentaIVAIncluido = parseFloat(productData.precio_venta);
                ivaPorcentaje = parseFloat(productData.porcentaje_iva);
                litrosPorUnidad = parseFloat(productData.litros_por_unidad); // NEW
                
                precioUnitarioInput.value = precioUnitarioVentaIVAIncluido.toFixed(2); // Establecer precio de solo lectura

                const stock = parseFloat(productData.stock_actual_unidades);
                stockProductoInfo.textContent = `Stock actual: ${stock} unidades`;
            } else {
                stockProductoInfo.textContent = '';
                precioUnitarioInput.value = '0.00';
            }

            // Descuento y recargo de línea son 0 (campos eliminados del UI)
            let descuento = 0;
            let recargo = 0;

            // Calcular base imponible de la línea a partir del precio unitario con IVA incluido
            let precioUnitarioBaseImponible = precioUnitarioVentaIVAIncluido / (1 + (ivaPorcentaje / 100));

            let subtotalBaseOriginal = cantidad * precioUnitarioBaseImponible;
            let subtotalLineaBase = subtotalBaseOriginal * (1 - (descuento / 100)) * (1 + (recargo / 100));
            
            let subtotalLineaIva = subtotalLineaBase * (ivaPorcentaje / 100); 
            let subtotalLineaTotal = subtotalLineaBase + subtotalLineaIva;
            let totalLitrosLinea = cantidad * litrosPorUnidad; // NEW

            lineaBaseDisplay.textContent = subtotalLineaBase.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            lineaIvaDisplay.textContent = subtotalLineaIva.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            lineaTotalDisplay.textContent = subtotalLineaTotal.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            lineaLitrosDisplay.textContent = totalLitrosLinea.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); // NEW
        }


        // Función para redirigir a facturas_ventas.php para crear una factura desde este pedido
        function createInvoiceFromOrder(clientId, clientName, orderId, parteRutaId) {
            // NOTA: La lógica para actualizar el estado del pedido a 'completado'
            // para pedidos de "Entrega en Molino" una vez facturados debe implementarse
            // en 'facturas_ventas.php' al crear/asociar la factura.
            let url = `facturas_ventas.php?new_invoice_client_id=${clientId}&new_invoice_client_name=${encodeURIComponent(clientName)}&new_invoice_order_id=${orderId}`;
            if (parteRutaId && parteRutaId !== 'null') { // Check if parteRutaId is not null or undefined
                url += `&new_invoice_parte_ruta_id=${parteRutaId}`;
            }
            window.location.href = url;
        }

        // NEW FUNCTION: Redirect to clientes.php to create a new client from the order modal
        function createNewClientFromOrderModal() {
            const clientName = document.getElementById('client_search_input').value.trim();
            let url = 'clientes.php?action=new';
            if (clientName) {
                url += '&new_client_name=' + encodeURIComponent(clientName);
            }
            window.location.href = url;
        }

        // Wrap the entire script in an IIFE to prevent global variable conflicts
        (function() {
            let clientSearchTimeout; // Moved inside IIFE
            let shippingAddressSearchTimeout; // NEW: Timeout for shipping address search

            // Consolidated DOMContentLoaded listener
            document.addEventListener('DOMContentLoaded', () => {
                // New Order Modal elements for delivery type
                const newPedidoTipoEntregaSelect = document.getElementById('tipo_entrega');
                const newPedidoEmpresaTransporteGroup = document.getElementById('empresaTransporteGroup');
                const newPedidoEmpresaTransporteInput = document.getElementById('empresa_transporte');
                const newPedidoNumeroSeguimientoGroup = document.getElementById('numeroSeguimientoGroup');
                const newPedidoNumeroSeguimientoInput = document.getElementById('numero_seguimiento');
                const newPedidoRutaGroup = document.getElementById('rutaGroup');
                const newPedidoIdRutaSelect = document.getElementById('id_ruta');
                const newPedidoRutaSelectionError = document.getElementById('ruta_selection_error');

                // Edit Order Modal elements for delivery type
                // These will only exist if $view == 'details'
                const editPedidoTipoEntregaSelect = document.getElementById('edit_tipo_entrega');
                const editPedidoEmpresaTransporteGroup = document.getElementById('editEmpresaTransporteGroup');
                const editPedidoEmpresaTransporteInput = document.getElementById('edit_empresa_transporte');
                const editPedidoNumeroSeguimientoGroup = document.getElementById('editNumeroSeguimientoGroup');
                const editPedidoNumeroSeguimientoInput = document.getElementById('edit_numero_seguimiento');
                const editPedidoRutaGroup = document.getElementById('editRutaGroup');
                const editPedidoIdRutaSelect = document.getElementById('edit_id_ruta');
                const editPedidoRutaSelectionError = document.getElementById('edit_ruta_selection_error');

                // Client Search elements (New Order Modal)
                const clientSearchInput = document.getElementById('client_search_input');
                const idClienteSelectedInput = document.getElementById('id_cliente_selected');
                const clientSearchResultsDiv = document.getElementById('client_search_results');
                const clientSelectionError = document.getElementById('client_selection_error');
                const pedidoForm = document.getElementById('pedidoForm'); // Get the form for new order

                // Client Search elements (Edit Order Modal)
                const editClientSearchInput = document.getElementById('edit_client_search_input');
                const editIdClienteSelectedInput = document.getElementById('edit_id_cliente_selected');
                const editClientSearchResultsDiv = document.getElementById('edit_client_search_results');
                const editClientSelectionError = document.getElementById('edit_client_selection_error');
                const editPedidoForm = document.getElementById('editPedidoForm'); // Get the form for edit order

                // MODIFICACIÓN: Elementos para la selección de dirección de envío (Nuevo Pedido)
                const shippingAddressGroup = document.getElementById('shippingAddressGroup');
                const shippingAddressSearchInput = document.getElementById('shipping_address_search_input'); // NEW
                const idDireccionEnvioSelectedInput = document.getElementById('id_direccion_envio_selected'); // NEW
                const shippingAddressSearchResultsDiv = document.getElementById('shipping_address_search_results'); // NEW
                const shippingAddressError = document.getElementById('shipping_address_error');
                const noShippingAddressesInfo = document.getElementById('no_shipping_addresses_info');

                // MODIFICACIÓN: Elementos para la selección de dirección de envío (Editar Pedido)
                const editShippingAddressGroup = document.getElementById('editShippingAddressGroup');
                const editShippingAddressSearchInput = document.getElementById('edit_shipping_address_search_input'); // NEW
                const editIdDireccionEnvioSelectedInput = document.getElementById('edit_id_direccion_envio_selected'); // NEW
                const editShippingAddressSearchResultsDiv = document.getElementById('edit_shipping_address_search_results'); // NEW
                const editShippingAddressError = document.getElementById('edit_shipping_address_error');
                const editNoShippingAddressesInfo = document.getElementById('edit_no_shipping_addresses_info');


                // Filter buttons and table
                const filterPendingServiceBtn = document.getElementById('filterPendingServiceBtn');
                const filterCompletedServiceBtn = document.getElementById('filterCompletedServiceBtn'); // New button
                const deliveryTypeFilters = document.getElementById('deliveryTypeFilters'); // Container for sub-filters
                const filterRepartoPropioBtn = document.getElementById('filterRepartoPropioBtn');
                const filterEnvioPaqueteriaBtn = document.getElementById('filterEnvioPaqueteriaBtn');
                const filterEntregaMolinoBtn = document.getElementById('filterEntregaMolinoBtn'); // Now a sub-filter
                const showAllOrdersBtn = document.getElementById('showAllOrdersBtn');
                const pedidosListTable = document.getElementById('pedidosListTable');

                // Total display elements
                const totalLitrosGeneralDisplay = document.getElementById('totalLitrosGeneral');
                const totalIvaIncGeneralDisplay = document.getElementById('totalIvaIncGeneral');


                function handleDeliveryTypeChange(selectElement, empresaTransporteGroup, empresaTransporteInput, numeroSeguimientoGroup, numeroSeguimientoInput, rutaGroup, idRutaSelect, rutaSelectionError) {
                    console.log('Tipo de entrega seleccionado:', selectElement.value);
                    if (selectElement.value === 'envio_paqueteria') {
                        if (empresaTransporteGroup) empresaTransporteGroup.style.display = 'block';
                        if (empresaTransporteInput) empresaTransporteInput.removeAttribute('required');
                        if (numeroSeguimientoGroup) numeroSeguimientoGroup.style.display = 'block';
                        // MODIFICACIÓN: El campo de número de seguimiento no es estrictamente requerido, pero se valida como URL si se rellena
                        if (numeroSeguimientoInput) numeroSeguimientoInput.setAttribute('type', 'url'); // Asegurar que sea tipo URL
                        if (rutaGroup) rutaGroup.style.display = 'none';
                        if (idRutaSelect) {
                            idRutaSelect.removeAttribute('required');
                            idRutaSelect.value = ''; // Clear selected route
                        }
                        if (rutaSelectionError) rutaSelectionError.style.display = 'none'; // Hide route error
                        console.log('Mostrando campos de paquetería, ocultando ruta.');
                    } else if (selectElement.value === 'reparto_propio') {
                        if (empresaTransporteGroup) empresaTransporteGroup.style.display = 'none';
                        if (empresaTransporteInput) {
                            empresaTransporteInput.removeAttribute('required');
                            empresaTransporteInput.value = '';
                        }
                        if (numeroSeguimientoGroup) numeroSeguimientoGroup.style.display = 'none';
                        if (numeroSeguimientoInput) {
                            numeroSeguimientoInput.removeAttribute('required');
                            numeroSeguimientoInput.value = '';
                        }
                        if (rutaGroup) rutaGroup.style.display = 'block';
                        if (idRutaSelect) idRutaSelect.setAttribute('required', 'required');
                        console.log('Mostrando campos de ruta, ocultando paquetería.');
                    } else if (selectElement.value === 'entrega_en_molino') {
                        if (empresaTransporteGroup) empresaTransporteGroup.style.display = 'none';
                        if (empresaTransporteInput) {
                            empresaTransporteInput.removeAttribute('required');
                            empresaTransporteInput.value = '';
                        }
                        if (numeroSeguimientoGroup) numeroSeguimientoGroup.style.display = 'none';
                        if (numeroSeguimientoInput) {
                            numeroSeguimientoInput.removeAttribute('required');
                            numeroSeguimientoInput.value = '';
                        }
                        if (rutaGroup) rutaGroup.style.display = 'none';
                        if (idRutaSelect) {
                            idRutaSelect.removeAttribute('required');
                            idRutaSelect.value = ''; // Clear selected route
                        }
                        if (rutaSelectionError) rutaSelectionError.style.display = 'none'; // Hide route error
                        console.log('Ocultando campos de paquetería y ruta.');
                    }
                }

                // Initial setup for addLineaModalElement
                const addLineaModalElement = document.getElementById('addLineaModal');
                if (addLineaModalElement) {
                    addLineaModalElement.addEventListener('shown.bs.modal', () => {
                        // Reset fields when modal is shown
                        document.getElementById('id_producto').value = '';
                        document.getElementById('cantidad_solicitada').value = '';
                        document.getElementById('precio_unitario_venta').value = '0.00';
                        document.getElementById('observaciones_detalle').value = '';
                        document.getElementById('stockProductoInfo').textContent = '';
                        // Reiniciar los displays de totales de línea
                        document.getElementById('linea_base_display').textContent = '0.00 €';
                        document.getElementById('linea_iva_display').textContent = '0.00 €';
                        document.getElementById('linea_total_display').textContent = '0.00 €';
                        document.getElementById('linea_litros_display').textContent = '0.00'; // NEW
                        updatePrecioUnitario(); // Call to reset displayed totals and stock info
                    });
                }

                // If a client ID was passed to pre-fill the new order modal
                const urlParams = new URLSearchParams(window.location.search); // Declared once here
                const newInvoiceClientId = urlParams.get('new_invoice_client_id');
                const newInvoiceClientName = urlParams.get('new_invoice_client_name');
                const openModalParam = urlParams.get('open_modal'); // Get the new parameter

                if (newInvoiceClientId && newInvoiceClientName) {
                    // Asegurarse de que el cliente exista en nuestros datos cargados
                    if (clientesMapJs[newInvoiceClientId]) {
                        const clientInfo = clientesMapJs[newInvoiceClientId];
                        // Formatear el nombre del cliente de la misma manera que el datalist
                        // Decodificar el nombre para asegurar que caracteres especiales se muestren correctamente
                        const clientDisplayValue = `${decodeURIComponent(newInvoiceClientName)} (${clientInfo.nif || 'N/A'})`;
                        
                        if (clientSearchInput) { // Check if element exists before accessing
                            clientSearchInput.value = clientDisplayValue;
                        }
                        if (idClienteSelectedInput) { // Check if element exists before accessing
                            idClienteSelectedInput.value = newInvoiceClientId;
                            // MODIFICACIÓN: Cargar direcciones de envío al precargar cliente
                            loadShippingAddresses(newInvoiceClientId, shippingAddressSearchInput, idDireccionEnvioSelectedInput, shippingAddressSearchResultsDiv, shippingAddressGroup, shippingAddressError, noShippingAddressesInfo);
                        }
                    }
                }

                // Initial state for new order modal delivery type fields
                if (newPedidoTipoEntregaSelect) {
                    newPedidoTipoEntregaSelect.addEventListener('change', () => handleDeliveryTypeChange(newPedidoTipoEntregaSelect, newPedidoEmpresaTransporteGroup, newPedidoEmpresaTransporteInput, newPedidoNumeroSeguimientoGroup, newPedidoNumeroSeguimientoInput, newPedidoRutaGroup, newPedidoIdRutaSelect, newPedidoRutaSelectionError));
                    // Disparar el evento change al cargar para establecer el estado inicial correcto
                    newPedidoTipoEntregaSelect.dispatchEvent(new Event('change'));
                }

                // Initial state for edit order modal delivery type fields
                // Only attach listeners if the edit modal elements exist (i.e., we are in 'details' view)
                if (editPedidoTipoEntregaSelect) {
                    editPedidoTipoEntregaSelect.addEventListener('change', () => handleDeliveryTypeChange(editPedidoTipoEntregaSelect, editPedidoEmpresaTransporteGroup, editPedidoEmpresaTransporteInput, editPedidoNumeroSeguimientoGroup, editPedidoNumeroSeguimientoInput, editPedidoRutaGroup, editPedidoIdRutaSelect, editPedidoRutaSelectionError));
                    // Disparar el evento change al cargar para establecer el estado inicial correcto
                    editPedidoTipoEntregaSelect.dispatchEvent(new Event('change'));
                }

                // Add focus return for modals
                const addPedidoModalElement = document.getElementById('addPedidoModal');
                if (addPedidoModalElement) {
                    addPedidoModalElement.addEventListener('hidden.bs.modal', () => {
                        const newPedidoBtn = document.querySelector('[data-bs-target="#addPedidoModal"]');
                        if (newPedidoBtn) {
                            newPedidoBtn.focus();
                        }
                    });
                }

                const editPedidoModalElement = document.getElementById('editPedidoModal');
                if (editPedidoModalElement) {
                    editPedidoModalElement.addEventListener('hidden.bs.modal', () => {
                        // Attempt to find the edit button that opened it, or fallback to new pedido button
                        const editButton = document.querySelector('.btn-warning[data-bs-target="#editPedidoModal"]');
                        if (editButton) {
                            editButton.focus();
                        } else {
                            const newPedidoBtn = document.querySelector('[data-bs-target="#addPedidoModal"]');
                            if (newPedidoBtn) {
                                newPedidoBtn.focus();
                            }
                        }
                    });
                }


                // --- Client Search Functionality for NEW ORDER MODAL ---
                if (clientSearchInput) {
                    clientSearchInput.addEventListener('input', function() {
                        const searchTerm = this.value.trim();
                        idClienteSelectedInput.value = ''; // Clear selected client ID on new search
                        clientSelectionError.style.display = 'none'; // Hide error message
                        // MODIFICACIÓN: Ocultar y limpiar direcciones de envío
                        shippingAddressGroup.style.display = 'none';
                        shippingAddressSearchInput.value = ''; // Clear search input
                        idDireccionEnvioSelectedInput.value = ''; // Clear selected ID
                        shippingAddressSearchResultsDiv.innerHTML = ''; // Clear results
                        shippingAddressError.style.display = 'none';
                        noShippingAddressesInfo.style.display = 'none';


                        console.log('Search term being sent (New Order):', searchTerm); // Debugging log

                        clearTimeout(clientSearchTimeout);
                        if (searchTerm.length > 1) { // Start search after 2 characters
                            clientSearchTimeout = setTimeout(async () => {
                                try {
                                    const formData = new FormData();
                                    formData.append('accion', 'search_clients');
                                    formData.append('search_term', searchTerm);

                                    const response = await fetch('pedidos.php', {
                                        method: 'POST',
                                        body: formData
                                    });
                                    const clients = await response.json();

                                    console.log('Clients response (New Order):', clients); // Debugging log

                                    clientSearchResultsDiv.innerHTML = '';
                                    if (clients.error) {
                                        clientSearchResultsDiv.innerHTML = `<div class="client-search-results-item text-danger">Error: ${clients.error}</div>`;
                                    } else if (clients.length > 0) {
                                        clients.forEach(client => {
                                            const item = document.createElement('div');
                                            item.classList.add('client-search-results-item');
                                            item.textContent = `${client.nombre_cliente} (${client.nif || 'N/A'}) - ${client.ciudad}`;
                                            item.dataset.clientId = client.id_cliente;
                                            item.dataset.clientName = client.nombre_cliente;
                                            item.addEventListener('click', function() {
                                                clientSearchInput.value = this.dataset.clientName;
                                                idClienteSelectedInput.value = this.dataset.clientId;
                                                clientSearchResultsDiv.innerHTML = ''; // Clear results
                                                // MODIFICACIÓN: Cargar direcciones de envío al seleccionar cliente
                                                loadShippingAddresses(this.dataset.clientId, shippingAddressSearchInput, idDireccionEnvioSelectedInput, shippingAddressSearchResultsDiv, shippingAddressGroup, shippingAddressError, noShippingAddressesInfo);
                                            });
                                            clientSearchResultsDiv.appendChild(item);
                                        });
                                    } else {
                                        clientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-muted">No se encontraron clientes.</div>';
                                    }
                                } catch (error) {
                                    console.error('Error searching clients (New Order):', error);
                                    clientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-danger">Error al buscar clientes.</div>';
                                }
                            }, 300); // Debounce search
                        } else {
                            clientSearchResultsDiv.innerHTML = ''; // Clear results if search term is too short
                        }
                    });

                    // Hide search results when clicking outside
                    document.addEventListener('click', function(event) {
                        if (!clientSearchInput.contains(event.target) && !clientSearchResultsDiv.contains(event.target)) {
                            clientSearchResultsDiv.innerHTML = '';
                        }
                    });

                    // Validate client selection and route selection before submitting the form
                    if (pedidoForm) {
                        pedidoForm.addEventListener('submit', function(event) {
                            let isValid = true;

                            // Validate client selection
                            if (!idClienteSelectedInput.value) {
                                event.preventDefault(); // Prevent form submission
                                clientSelectionError.style.display = 'block'; // Show error message
                                isValid = false;
                            } else {
                                clientSelectionError.style.display = 'none'; // Hide error message
                            }

                            // MODIFICACIÓN: Validar selección de dirección de envío si el grupo es visible Y hay direcciones disponibles
                            if (shippingAddressGroup.style.display === 'block') {
                                // Solo validar si hay opciones reales para seleccionar (es decir, no "No hay direcciones...")
                                if (!idDireccionEnvioSelectedInput.value && noShippingAddressesInfo.style.display !== 'block') {
                                    event.preventDefault();
                                    shippingAddressError.style.display = 'block';
                                    isValid = false;
                                } else {
                                    shippingAddressError.style.display = 'none';
                                }
                            }


                            // Validate ruta selection ONLY if delivery type is 'reparto_propio'
                            if (newPedidoTipoEntregaSelect.value === 'reparto_propio') {
                                if (!newPedidoIdRutaSelect.value) { // This checks for empty string
                                    event.preventDefault();
                                    newPedidoRutaSelectionError.style.display = 'block';
                                    isValid = false;
                                } else {
                                    newPedidoRutaSelectionError.style.display = 'none';
                                }
                            }

                            if (!isValid) {
                                event.preventDefault(); // Ensure form is not submitted if any validation fails
                            }
                        });

                        // Optional: Hide error when user starts interacting with the select again
                        if (newPedidoIdRutaSelect) {
                            newPedidoIdRutaSelect.addEventListener('change', function() {
                                if (this.value) {
                                    newPedidoRutaSelectionError.style.display = 'none';
                                }
                            });
                        }
                        // MODIFICACIÓN: Ocultar error de dirección de envío al cambiar la selección
                        if (idDireccionEnvioSelectedInput) {
                            idDireccionEnvioSelectedInput.addEventListener('change', function() {
                                if (this.value) {
                                    shippingAddressError.style.display = 'none';
                                }
                            });
                        }
                    }
                }

                // --- Client Search Functionality for EDIT ORDER MODAL ---
                // Only attach listeners if the edit modal elements exist (i.e., we are in 'details' view)
                if (editClientSearchInput) {
                    editClientSearchInput.addEventListener('input', function() {
                        const searchTerm = this.value.trim();
                        // Do NOT clear editIdClienteSelectedInput here, it should retain its current value unless a new client is selected
                        if (editClientSelectionError) editClientSelectionError.style.display = 'none';
                        // MODIFICACIÓN: Ocultar y limpiar direcciones de envío en edición
                        if (editShippingAddressGroup) editShippingAddressGroup.style.display = 'none';
                        if (editShippingAddressSearchInput) editShippingAddressSearchInput.value = ''; // Clear search input
                        if (editIdDireccionEnvioSelectedInput) editIdDireccionEnvioSelectedInput.value = ''; // Clear selected ID
                        if (editShippingAddressSearchResultsDiv) editShippingAddressSearchResultsDiv.innerHTML = ''; // Clear results
                        if (editShippingAddressError) editShippingAddressError.style.display = 'none';
                        if (editNoShippingAddressesInfo) editNoShippingAddressesInfo.style.display = 'none';


                        clearTimeout(clientSearchTimeout); // Reuse the same timeout variable
                        if (searchTerm.length > 1) {
                            clientSearchTimeout = setTimeout(async () => {
                                try {
                                    const formData = new FormData();
                                    formData.append('accion', 'search_clients');
                                    formData.append('search_term', searchTerm);

                                    const response = await fetch('pedidos.php', {
                                        method: 'POST',
                                        body: formData
                                    });
                                    const clients = await response.json();

                                    console.log('Clients response (Edit Order):', clients); // Debugging log

                                    if (editClientSearchResultsDiv) editClientSearchResultsDiv.innerHTML = '';
                                    if (clients.error) {
                                        if (editClientSearchResultsDiv) editClientSearchResultsDiv.innerHTML = `<div class="client-search-results-item text-danger">Error: ${clients.error}</div>`;
                                    } else if (clients.length > 0) {
                                        clients.forEach(client => {
                                            const item = document.createElement('div');
                                            item.classList.add('client-search-results-item');
                                            item.textContent = `${client.nombre_cliente} (${client.nif || 'N/A'}) - ${client.ciudad}`;
                                            item.dataset.clientId = client.id_cliente;
                                            item.dataset.clientName = client.nombre_cliente;
                                            item.addEventListener('click', function() {
                                                if (editClientSearchInput) editClientSearchInput.value = this.dataset.clientName;
                                                if (editIdClienteSelectedInput) editIdClienteSelectedInput.value = this.dataset.clientId;
                                                if (editClientSearchResultsDiv) editClientSearchResultsDiv.innerHTML = ''; // Clear results
                                                // MODIFICACIÓN: Cargar direcciones de envío al seleccionar cliente en edición
                                                loadShippingAddresses(this.dataset.clientId, editShippingAddressSearchInput, editIdDireccionEnvioSelectedInput, editShippingAddressSearchResultsDiv, editShippingAddressGroup, editShippingAddressError, editNoShippingAddressesInfo, '<?php echo $pedido_actual['id_direccion_envio'] ?? ''; ?>');
                                            });
                                            if (editClientSearchResultsDiv) editClientSearchResultsDiv.appendChild(item);
                                        });
                                    } else {
                                        if (editClientSearchResultsDiv) editClientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-muted">No se encontraron clientes.</div>';
                                    }
                                } catch (error) {
                                    console.error('Error searching clients (Edit Order):', error);
                                    if (editClientSearchResultsDiv) editClientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-danger">Error al buscar clientes.</div>';
                                }
                            }, 300);
                        } else {
                            if (editClientSearchResultsDiv) editClientSearchResultsDiv.innerHTML = '';
                        }
                    });

                    // Hide search results when clicking outside
                    document.addEventListener('click', function(event) {
                        if (editClientSearchInput && !editClientSearchInput.contains(event.target) && editClientSearchResultsDiv && !editClientSearchResultsDiv.contains(event.target)) {
                            editClientSearchResultsDiv.innerHTML = '';
                        }
                    });

                    // Validate client and ruta selection before submitting the edit form
                    if (editPedidoForm) {
                        editPedidoForm.addEventListener('submit', function(event) {
                            let isValid = true;

                            // Validate client selection
                            if (editIdClienteSelectedInput && !editIdClienteSelectedInput.value) {
                                event.preventDefault();
                                if (editClientSelectionError) editClientSelectionError.style.display = 'block';
                                isValid = false;
                            } else {
                                if (editClientSelectionError) editClientSelectionError.style.display = 'none';
                            }

                            // MODIFICACIÓN: Validar selección de dirección de envío si el grupo es visible Y hay direcciones disponibles en edición
                            if (editShippingAddressGroup && editShippingAddressGroup.style.display === 'block') {
                                // Solo validar si hay opciones reales para seleccionar (es decir, no "No hay direcciones...")
                                if (!editIdDireccionEnvioSelectedInput.value && editNoShippingAddressesInfo.style.display !== 'block') {
                                    event.preventDefault();
                                    editShippingAddressError.style.display = 'block';
                                    isValid = false;
                                } else {
                                    editShippingAddressError.style.display = 'none';
                                }
                            }

                            // Validate ruta selection ONLY if delivery type is 'reparto_propio'
                            if (editPedidoTipoEntregaSelect && editPedidoTipoEntregaSelect.value === 'reparto_propio') {
                                if (editPedidoIdRutaSelect && !editPedidoIdRutaSelect.value) { // This checks for empty string
                                    event.preventDefault();
                                    if (editPedidoRutaSelectionError) editPedidoRutaSelectionError.style.display = 'block';
                                    isValid = false;
                                } else {
                                    if (editPedidoRutaSelectionError) editPedidoRutaSelectionError.style.display = 'none';
                                }
                            }

                            if (!isValid) {
                                event.preventDefault();
                            }
                        });

                        // Optional: Hide error when user starts interacting with the select again
                        if (editPedidoIdRutaSelect) {
                            editPedidoIdRutaSelect.addEventListener('change', function() {
                                if (this.value) {
                                    if (editPedidoRutaSelectionError) editPedidoRutaSelectionError.style.display = 'none';
                                }
                            });
                        }
                        // MODIFICACIÓN: Ocultar error de dirección de envío al cambiar la selección en edición
                        if (editIdDireccionEnvioSelectedInput) {
                            editIdDireccionEnvioSelectedInput.addEventListener('change', function() {
                                if (this.value) {
                                    editShippingAddressError.style.display = 'none';
                                }
                            });
                        }
                    }
                }

                // MODIFICACIÓN: Función para cargar y popular el select/input de direcciones de envío
                async function loadShippingAddresses(clientId, searchInputElement, selectedIdElement, resultsDivElement, groupElement, errorElement, noInfoElement, selectedAddressId = null) {
                    groupElement.style.display = 'block'; // Show the group container
                    searchInputElement.value = ''; // Clear search input
                    selectedIdElement.value = ''; // Clear selected ID
                    resultsDivElement.innerHTML = ''; // Clear results
                    errorElement.style.display = 'none';
                    noInfoElement.style.display = 'none';

                    if (!clientId) {
                        searchInputElement.placeholder = 'Seleccione un cliente primero';
                        return;
                    }

                    searchInputElement.placeholder = 'Buscar dirección de envío...'; // Reset placeholder

                    try {
                        const formData = new FormData();
                        formData.append('accion', 'get_shipping_addresses');
                        formData.append('id_cliente', clientId);
                        // No search_term initially, load all for the client

                        const response = await fetch('pedidos.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();

                        if (data.success) {
                            if (data.direcciones.length > 0) {
                                // Populate resultsDivElement with initial options
                                data.direcciones.forEach(dir => {
                                    const item = document.createElement('div');
                                    item.classList.add('client-search-results-item'); // Reuse client-search-results-item class
                                    const isPrincipalText = dir.es_principal == 1 ? ' (Principal)' : '';
                                    item.textContent = `${dir.nombre_direccion} - ${dir.direccion}, ${dir.ciudad}${isPrincipalText}`;
                                    item.dataset.idDireccionEnvio = dir.id_direccion_envio;
                                    item.dataset.fullAddress = `${dir.nombre_direccion} - ${dir.direccion}, ${dir.ciudad}, ${dir.provincia} ${dir.codigo_postal}`;
                                    item.addEventListener('click', function() {
                                        searchInputElement.value = this.dataset.fullAddress;
                                        selectedIdElement.value = this.dataset.idDireccionEnvio;
                                        resultsDivElement.innerHTML = ''; // Clear results
                                        errorElement.style.display = 'none'; // Hide error if selection is made
                                    });
                                    resultsDivElement.appendChild(item);
                                });

                                // Pre-select address if selectedAddressId is provided (for edit modal)
                                if (selectedAddressId) {
                                    const preselectedDir = data.direcciones.find(dir => dir.id_direccion_envio == selectedAddressId);
                                    if (preselectedDir) {
                                        searchInputElement.value = `${preselectedDir.nombre_direccion} - ${preselectedDir.direccion}, ${preselectedDir.ciudad}, ${preselectedDir.provincia} ${preselectedDir.codigo_postal}`;
                                        selectedIdElement.value = selectedAddressId;
                                    }
                                } else {
                                    // If no specific address is selected (new order), try to select the principal one
                                    const principalDir = data.direcciones.find(dir => dir.es_principal == 1);
                                    if (principalDir) {
                                        searchInputElement.value = `${principalDir.nombre_direccion} - ${principalDir.direccion}, ${principalDir.ciudad}, ${principalDir.provincia} ${principalDir.codigo_postal}`;
                                        selectedIdElement.value = principalDir.id_direccion_envio;
                                    }
                                }
                                // If there are addresses, the field is required (validation handled by JS)
                                // searchInputElement.setAttribute('required', 'required'); // Not on text input, but on hidden input via JS validation
                            } else {
                                searchInputElement.value = ''; // Clear any previous value
                                selectedIdElement.value = ''; // Ensure hidden input is empty
                                resultsDivElement.innerHTML = ''; // Clear results
                                noInfoElement.style.display = 'block';
                                // If no addresses, the field is not strictly required
                                // searchInputElement.removeAttribute('required'); // Handled by JS validation
                            }
                        } else {
                            searchInputElement.value = '';
                            selectedIdElement.value = '';
                            resultsDivElement.innerHTML = `<div class="client-search-results-item text-danger">Error al cargar: ${data.message}</div>`;
                            errorElement.style.display = 'block';
                            noInfoElement.style.display = 'none';
                            // searchInputElement.removeAttribute('required'); // Handled by JS validation
                        }
                    } catch (error) {
                        console.error('Error fetching shipping addresses:', error);
                        searchInputElement.value = '';
                        selectedIdElement.value = '';
                        resultsDivElement.innerHTML = '<div class="client-search-results-item text-danger">Error al cargar direcciones</div>';
                        errorElement.style.display = 'block';
                        noInfoElement.style.display = 'none';
                        // searchInputElement.removeAttribute('required'); // Handled by JS validation
                    }
                }

                // NEW: Event listeners for shipping address search inputs
                if (shippingAddressSearchInput) {
                    shippingAddressSearchInput.addEventListener('input', function() {
                        const searchTerm = this.value.trim();
                        const currentClientId = idClienteSelectedInput.value;
                        idDireccionEnvioSelectedInput.value = ''; // Clear selected ID on new search
                        shippingAddressError.style.display = 'none'; // Hide error message
                        noShippingAddressesInfo.style.display = 'none'; // Hide info message

                        clearTimeout(shippingAddressSearchTimeout);
                        if (searchTerm.length > 1 && currentClientId) { // Search after 2 characters and if client is selected
                            shippingAddressSearchTimeout = setTimeout(async () => {
                                try {
                                    const formData = new FormData();
                                    formData.append('accion', 'search_shipping_addresses'); // Use new action
                                    formData.append('id_cliente', currentClientId);
                                    formData.append('search_term', searchTerm);

                                    const response = await fetch('pedidos.php', {
                                        method: 'POST',
                                        body: formData
                                    });
                                    const data = await response.json();

                                    shippingAddressSearchResultsDiv.innerHTML = '';
                                    if (data.success && data.direcciones.length > 0) {
                                        data.direcciones.forEach(dir => {
                                            const item = document.createElement('div');
                                            item.classList.add('client-search-results-item');
                                            const isPrincipalText = dir.es_principal == 1 ? ' (Principal)' : '';
                                            item.textContent = `${dir.nombre_direccion} - ${dir.direccion}, ${dir.ciudad}${isPrincipalText}`;
                                            item.dataset.idDireccionEnvio = dir.id_direccion_envio;
                                            item.dataset.fullAddress = `${dir.nombre_direccion} - ${dir.direccion}, ${dir.ciudad}, ${dir.provincia} ${dir.codigo_postal}`;
                                            item.addEventListener('click', function() {
                                                shippingAddressSearchInput.value = this.dataset.fullAddress;
                                                idDireccionEnvioSelectedInput.value = this.dataset.idDireccionEnvio;
                                                shippingAddressSearchResultsDiv.innerHTML = '';
                                                shippingAddressError.style.display = 'none';
                                            });
                                            shippingAddressSearchResultsDiv.appendChild(item);
                                        });
                                    } else {
                                        shippingAddressSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-muted">No se encontraron direcciones.</div>';
                                    }
                                } catch (error) {
                                    console.error('Error searching shipping addresses:', error);
                                    shippingAddressSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-danger">Error al buscar direcciones.</div>';
                                }
                            }, 300);
                        } else {
                            shippingAddressSearchResultsDiv.innerHTML = '';
                        }
                    });

                    document.addEventListener('click', function(event) {
                        if (!shippingAddressSearchInput.contains(event.target) && !shippingAddressSearchResultsDiv.contains(event.target)) {
                            shippingAddressSearchResultsDiv.innerHTML = '';
                        }
                    });
                }

                if (editShippingAddressSearchInput) {
                    editShippingAddressSearchInput.addEventListener('input', function() {
                        const searchTerm = this.value.trim();
                        const currentClientId = editIdClienteSelectedInput.value;
                        editIdDireccionEnvioSelectedInput.value = ''; // Clear selected ID on new search
                        editShippingAddressError.style.display = 'none'; // Hide error message
                        editNoShippingAddressesInfo.style.display = 'none'; // Hide info message

                        clearTimeout(shippingAddressSearchTimeout);
                        if (searchTerm.length > 1 && currentClientId) {
                            shippingAddressSearchTimeout = setTimeout(async () => {
                                try {
                                    const formData = new FormData();
                                    formData.append('accion', 'search_shipping_addresses');
                                    formData.append('id_cliente', currentClientId);
                                    formData.append('search_term', searchTerm);

                                    const response = await fetch('pedidos.php', {
                                        method: 'POST',
                                        body: formData
                                    });
                                    const data = await response.json();

                                    editShippingAddressSearchResultsDiv.innerHTML = '';
                                    if (data.success && data.direcciones.length > 0) {
                                        data.direcciones.forEach(dir => {
                                            const item = document.createElement('div');
                                            item.classList.add('client-search-results-item');
                                            const isPrincipalText = dir.es_principal == 1 ? ' (Principal)' : '';
                                            item.textContent = `${dir.nombre_direccion} - ${dir.direccion}, ${dir.ciudad}${isPrincipalText}`;
                                            item.dataset.idDireccionEnvio = dir.id_direccion_envio;
                                            item.dataset.fullAddress = `${dir.nombre_direccion} - ${dir.direccion}, ${dir.ciudad}, ${dir.provincia} ${dir.codigo_postal}`;
                                            item.addEventListener('click', function() {
                                                editShippingAddressSearchInput.value = this.dataset.fullAddress;
                                                editIdDireccionEnvioSelectedInput.value = this.dataset.idDireccionEnvio;
                                                editShippingAddressSearchResultsDiv.innerHTML = '';
                                                editShippingAddressError.style.display = 'none';
                                            });
                                            editShippingAddressSearchResultsDiv.appendChild(item);
                                        });
                                    } else {
                                        editShippingAddressSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-muted">No se encontraron direcciones.</div>';
                                    }
                                } catch (error) {
                                    console.error('Error searching shipping addresses:', error);
                                    editShippingAddressSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-danger">Error al buscar direcciones.</div>';
                                }
                            }, 300);
                        } else {
                            editShippingAddressSearchResultsDiv.innerHTML = '';
                        }
                    });

                    document.addEventListener('click', function(event) {
                        if (editShippingAddressSearchInput && !editShippingAddressSearchInput.contains(event.target) && editShippingAddressSearchResultsDiv && !editShippingAddressSearchResultsDiv.contains(event.target)) {
                            editShippingAddressSearchResultsDiv.innerHTML = '';
                        }
                    });
                }


                // Global filter state
                let activeFilters = {
                    estadoServicio: 'all', // Default to 'all' on load
                    tipoEntrega: null // Can be 'reparto_propio', 'envio_paqueteria', 'entrega_en_molino', or null
                };

                // Function to apply filters to the table rows
                function applyFilters() {
                    if (!pedidosListTable) return;
                    const rows = pedidosListTable.querySelectorAll('tbody tr');
                    let totalLitros = 0;
                    let totalIvaInc = 0;

                    rows.forEach(row => {
                        const estadoServicioCalculado = row.dataset.estadoServicioCalculado;
                        const tipoEntrega = row.dataset.tipoEntrega;

                        let passesServiceFilter = true;
                        let passesTypeFilter = true;

                        // Step 1: Filter by service status
                        if (activeFilters.estadoServicio === 'pending') {
                            const isPending = estadoServicioCalculado.includes('Pendiente de Servir') || estadoServicioCalculado.includes('Parcialmente Servido');
                            if (!isPending) {
                                passesServiceFilter = false;
                            }
                        } else if (activeFilters.estadoServicio === 'completed') {
                            if (estadoServicioCalculado !== 'Servido Completo') {
                                passesServiceFilter = false;
                            }
                        }
                        // If activeFilters.estadoServicio is 'all', no service filter is applied here

                        // Step 2: Filter by delivery type if active
                        if (activeFilters.tipoEntrega && passesServiceFilter) { // Only apply type filter if service filter passed
                            if (tipoEntrega !== activeFilters.tipoEntrega) {
                                passesTypeFilter = false;
                            }
                        }

                        if (passesServiceFilter && passesTypeFilter) {
                            row.style.display = '';
                            totalLitros += parseFloat(row.dataset.totalLitros);
                            totalIvaInc += parseFloat(row.dataset.totalIvaInc);
                        } else {
                            row.style.display = 'none';
                        }
                    });

                    // Update totals in the footer
                    if (totalLitrosGeneralDisplay) {
                        totalLitrosGeneralDisplay.textContent = totalLitros.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    }
                    if (totalIvaIncGeneralDisplay) {
                        totalIvaIncGeneralDisplay.textContent = totalIvaInc.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
                    }
                }

                // Function to manage button visibility based on activeFilters
                function updateButtonVisibility() {
                    // Reset all buttons to default hidden state for sub-filters, and visible for main filters
                    // This simplifies the logic by setting a baseline before applying specific rules
                    if (filterPendingServiceBtn) filterPendingServiceBtn.style.display = 'inline-block';
                    if (filterCompletedServiceBtn) filterCompletedServiceBtn.style.display = 'inline-block';
                    if (deliveryTypeFilters) deliveryTypeFilters.style.display = 'none'; // Hide the container
                    if (filterRepartoPropioBtn) filterRepartoPropioBtn.style.display = 'inline-block';
                    if (filterEnvioPaqueteriaBtn) filterEnvioPaqueteriaBtn.style.display = 'inline-block';
                    if (filterEntregaMolinoBtn) filterEntregaMolinoBtn.style.display = 'inline-block';
                    if (showAllOrdersBtn) showAllOrdersBtn.style.display = 'none';

                    if (activeFilters.estadoServicio === 'all' && activeFilters.tipoEntrega === null) {
                        // Initial state: All orders are shown. Only main service filters are visible.
                        // Already set above, no changes needed here.
                    } else if (activeFilters.estadoServicio === 'pending') {
                        // Showing pending service orders (potentially with a type filter)
                        if (filterPendingServiceBtn) filterPendingServiceBtn.style.display = 'none'; // Hide "Pedidos Pendientes" as it's active
                        if (deliveryTypeFilters) deliveryTypeFilters.style.display = 'inline-block'; // Show sub-filters
                        if (showAllOrdersBtn) showAllOrdersBtn.style.display = 'inline-block';
                    } else if (activeFilters.estadoServicio === 'completed') {
                        // Showing completed service orders (potentially with a type filter)
                        if (filterCompletedServiceBtn) filterCompletedServiceBtn.style.display = 'none'; // Hide "Pedidos Completados" as it's active
                        if (deliveryTypeFilters) deliveryTypeFilters.style.display = 'inline-block'; // Show sub-filters
                        if (showAllOrdersBtn) showAllOrdersBtn.style.display = 'inline-block';
                    }

                    // If a specific delivery type filter is active, hide that button
                    if (activeFilters.tipoEntrega === 'reparto_propio' && filterRepartoPropioBtn) {
                        filterRepartoPropioBtn.style.display = 'none';
                    } else if (activeFilters.tipoEntrega === 'envio_paqueteria' && filterEnvioPaqueteriaBtn) {
                        filterEnvioPaqueteriaBtn.style.display = 'none';
                    } else if (activeFilters.tipoEntrega === 'entrega_en_molino' && filterEntregaMolinoBtn) {
                        filterEntregaMolinoBtn.style.display = 'none';
                    }
                }


                // Event Listener for "Filtrar Pedidos Pendientes"
                if (filterPendingServiceBtn) {
                    filterPendingServiceBtn.addEventListener('click', () => {
                        activeFilters.estadoServicio = 'pending';
                        activeFilters.tipoEntrega = null; // Reset type filter when changing main service filter
                        applyFilters();
                        updateButtonVisibility();
                    });
                }

                // Event Listener for "Filtrar Pedidos Completados"
                if (filterCompletedServiceBtn) {
                    filterCompletedServiceBtn.addEventListener('click', () => {
                        activeFilters.estadoServicio = 'completed';
                        activeFilters.tipoEntrega = null; // Reset type filter when changing main service filter
                        applyFilters();
                        updateButtonVisibility();
                    });
                }

                // Event Listener for "Reparto Propio"
                if (filterRepartoPropioBtn) {
                    filterRepartoPropioBtn.addEventListener('click', () => {
                        // This button should only be clickable if a service status filter is already active
                        if (activeFilters.estadoServicio === 'pending' || activeFilters.estadoServicio === 'completed') {
                            activeFilters.tipoEntrega = 'reparto_propio';
                            applyFilters();
                            updateButtonVisibility();
                        }
                    });
                }

                // Event Listener for "Envío Paquetería"
                if (filterEnvioPaqueteriaBtn) {
                    filterEnvioPaqueteriaBtn.addEventListener('click', () => {
                        if (activeFilters.estadoServicio === 'pending' || activeFilters.estadoServicio === 'completed') {
                            activeFilters.tipoEntrega = 'envio_paqueteria';
                            applyFilters();
                            updateButtonVisibility();
                        }
                    });
                }

                // Event Listener for "Entrega en Molino"
                if (filterEntregaMolinoBtn) {
                    filterEntregaMolinoBtn.addEventListener('click', () => {
                        if (activeFilters.estadoServicio === 'pending' || activeFilters.estadoServicio === 'completed') {
                            activeFilters.tipoEntrega = 'entrega_en_molino';
                            applyFilters();
                            updateButtonVisibility();
                        }
                    });
                }

                // Event Listener for "Mostrar Todos"
                if (showAllOrdersBtn) {
                    showAllOrdersBtn.addEventListener('click', () => {
                        activeFilters.estadoServicio = 'all';
                        activeFilters.tipoEntrega = null;
                        applyFilters();
                        updateButtonVisibility();
                    });
                }

                // Check for refresh parameter in URL and force reload
                if (urlParams.get('refresh') === 'true') {
                    // Remove the refresh parameter to prevent infinite loops
                    urlParams.delete('refresh');
                    const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                    window.history.replaceState(null, '', newUrl); // Clean up URL
                    window.location.reload(true); // Force a hard reload from the server
                }

                // Add console.log for debugging tipo_entrega in table rows
                if (pedidosListTable) {
                    const rows = pedidosListTable.querySelectorAll('tbody tr');
                    console.log('Current table rows tipo_entrega data attributes:');
                    rows.forEach(row => {
                        console.log(`Pedido ID: ${row.dataset.idPedido}, Tipo Entrega: ${row.dataset.tipoEntrega}`);
                    });
                }

                // NEW: Check for open_modal parameter and open the modal
                if (openModalParam === 'true') {
                    const addPedidoModal = new bootstrap.Modal(document.getElementById('addPedidoModal'));
                    addPedidoModal.show();
                    // Optionally, remove the parameter from the URL to prevent it from reopening on refresh
                    urlParams.delete('open_modal');
                    const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                    window.history.replaceState(null, '', newUrl);
                }

                // Initial call to apply filters and set button visibility on page load
                applyFilters();
                updateButtonVisibility();

                // MODIFICACIÓN: Cargar direcciones de envío al abrir el modal de edición si ya hay un cliente seleccionado
                const editPedidoModal = document.getElementById('editPedidoModal');
                if (editPedidoModal) {
                    editPedidoModal.addEventListener('shown.bs.modal', () => {
                        const currentClientId = editIdClienteSelectedInput.value;
                        const currentShippingAddressId = '<?php echo $pedido_actual['id_direccion_envio'] ?? ''; ?>';
                        if (currentClientId) {
                            loadShippingAddresses(currentClientId, editShippingAddressSearchInput, editIdDireccionEnvioSelectedInput, editShippingAddressSearchResultsDiv, editShippingAddressGroup, editShippingAddressError, editNoShippingAddressesInfo, currentShippingAddressId);
                        } else {
                            // Si no hay cliente seleccionado, ocultar el campo de dirección de envío
                            editShippingAddressGroup.style.display = 'none';
                        }
                    });
                }

                // MODIFICACIÓN: Listener para el cambio de cliente en el modal de edición
                if (editClientSearchInput) {
                    // Cuando el cliente cambia en el modal de edición, recargar las direcciones de envío
                    editIdClienteSelectedInput.addEventListener('change', () => {
                        const newClientId = editIdClienteSelectedInput.value;
                        if (newClientId) {
                            loadShippingAddresses(newClientId, editShippingAddressSearchInput, editIdDireccionEnvioSelectedInput, editShippingAddressSearchResultsDiv, editShippingAddressGroup, editShippingAddressError, editNoShippingAddressesInfo);
                        } else {
                            editShippingAddressGroup.style.display = 'none';
                        }
                    });
                }
            });
        })(); // End of IIFE
    </script>
</body>
</html>
