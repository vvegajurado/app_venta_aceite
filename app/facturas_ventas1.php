<?php
// Habilitar la visualización de errores para depuración (¡DESACTIVAR EN PRODUCCIÓN!)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Iniciar el búfer de salida al principio del script para capturar cualquier salida no deseada
ob_start();

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
    // Clientes
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
            'codigo_postal' => $cli['codigo_postal']
        ];
    }

    // Productos (asumiendo porcentaje_iva_actual y litros_por_unidad están en la tabla 'productos')
    $stmt_productos = $pdo->query("
        SELECT
            p.id_producto,
            p.nombre_producto,
            p.precio_venta,
            p.stock_actual_unidades,
            COALESCE(p.porcentaje_iva_actual, 0.00) AS porcentaje_iva_actual,
            COALESCE(p.litros_por_unidad, 1.00) AS litros_por_unidad
        FROM productos p
        ORDER BY p.nombre_producto ASC
    ");
    $productos_disponibles = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);
    foreach ($productos_disponibles as $prod) {
        $productos_map[$prod['id_producto']] = [
            'nombre_producto' => $prod['nombre_producto'],
            'precio_venta' => $prod['precio_venta'],
            'stock_actual_unidades' => $prod['stock_actual_unidades'],
            'porcentaje_iva' => $prod['porcentaje_iva_actual'],
            'litros_por_unidad' => $prod['litros_por_unidad']
        ];
    }

    // Rutas
    $stmt_rutas = $pdo->query("SELECT id_ruta, nombre_ruta FROM rutas ORDER BY nombre_ruta ASC");
    $rutas_disponibles = $stmt_rutas->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensaje = "Error de base de datos al cargar datos de apoyo: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

// Detectar si la petición es AJAX
$is_ajax_request = (isset($_POST['accion']) && in_array($_POST['accion'], [
    'get_client_details', 'search_clients', 'get_product_details', 'agregar_ajax',
    'agregar_factura', 'editar_factura', 'eliminar_factura',
    'agregar_detalle_linea', 'eliminar_detalle_linea',
    'update_global_discount_factura', 'update_global_surcharge_factura',
    'get_shipping_addresses_by_client' // NUEVA ACCIÓN AJAX
])) || (isset($_GET['accion']) && in_array($_GET['accion'], ['buscar_ajax', 'get_shipping_addresses_by_client']));


// --- Lógica para procesar peticiones AJAX ---
if ($is_ajax_request) {
    // Limpiar cualquier salida del búfer antes de enviar la cabecera JSON
    ob_clean();
    header('Content-Type: application/json');

    $in_transaction = false;
    // Iniciar transacción si la acción implica escritura
    if (isset($_POST['accion']) && in_array($_POST['accion'], [
        'agregar_factura', 'editar_factura', 'eliminar_factura',
        'agregar_detalle_linea', 'eliminar_detalle_linea',
        'update_global_discount_factura', 'update_global_surcharge_factura',
        'agregar_ajax' // para agregar cliente desde modal
    ])) {
        $pdo->beginTransaction();
        $in_transaction = true;
    }

    try {
        $accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

        switch ($accion) {
            case 'get_client_details':
                $client_id = $_POST['id_cliente'] ?? null;
                if ($client_id) {
                    $stmt = $pdo->prepare("SELECT id_cliente, nombre_cliente, nif, telefono, email FROM clientes WHERE id_cliente = ?");
                    $stmt->execute([$client_id]);
                    $client = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode($client ?: ['error' => 'Cliente no encontrado.']);
                } else {
                    echo json_encode(['error' => 'ID de cliente no proporcionado.']);
                }
                break;

            case 'search_clients':
                $search_term = $_POST['search_term'] ?? '';
                if (empty($search_term)) {
                    echo json_encode([]);
                    break;
                }
                $like_param = '%' . $search_term . '%';
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
                break;

            case 'get_product_details':
                $product_id = $_POST['id_producto'] ?? null;
                if ($product_id) {
                    $stmt = $pdo->prepare("
                        SELECT
                            p.id_producto,
                            p.nombre_producto,
                            p.precio_venta,
                            p.stock_actual_unidades,
                            COALESCE(p.porcentaje_iva_actual, 0.00) AS porcentaje_iva_actual,
                            COALESCE(p.litros_por_unidad, 1.00) AS litros_por_unidad
                        FROM productos p
                        WHERE p.id_producto = ?
                    ");
                    $stmt->execute([$product_id]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode($product ?: ['error' => 'Producto no encontrado.']);
                } else {
                    echo json_encode(['error' => 'ID de producto no proporcionado.']);
                }
                break;

            case 'get_shipping_addresses_by_client': // NUEVA ACCIÓN
                $id_cliente = $_GET['id_cliente'] ?? null;
                if (empty($id_cliente)) {
                    echo json_encode(['success' => false, 'message' => 'ID de cliente no proporcionado.']);
                    break;
                }
                $stmt = $pdo->prepare("SELECT id_direccion_envio, nombre_direccion, direccion, ciudad, provincia, codigo_postal, es_principal FROM direcciones_envio WHERE id_cliente = ? ORDER BY es_principal DESC, nombre_direccion ASC");
                $stmt->execute([$id_cliente]);
                $direcciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Añadir la dirección principal del cliente como opción si no hay direcciones de envío específicas o si se quiere como primera opción
                $cliente_info = $clientes_map[$id_cliente] ?? null;
                if ($cliente_info && !empty($cliente_info['direccion'])) {
                    array_unshift($direcciones, [
                        'id_direccion_envio' => 'main_address', // Identificador especial para la dirección principal del cliente
                        'nombre_direccion' => 'Dirección Principal del Cliente',
                        'direccion' => $cliente_info['direccion'],
                        'ciudad' => $cliente_info['ciudad'],
                        'provincia' => $cliente_info['provincia'],
                        'codigo_postal' => $cliente_info['codigo_postal'],
                        'es_principal' => 1 // Considerarla principal para la UI
                    ]);
                }

                echo json_encode(['success' => true, 'direcciones' => $direcciones]);
                break;

            case 'agregar_factura':
                $id_cliente = $_POST['id_cliente'] ?? null;
                $fecha_factura = $_POST['fecha_factura'] ?? date('Y-m-d');
                $estado_factura = $_POST['estado_factura'] ?? 'pendiente';
                $id_pedido_asociado = empty($_POST['id_pedido_asociado']) ? null : (int)$_POST['id_pedido_asociado'];
                $id_parte_ruta_asociada = empty($_POST['id_parte_ruta_asociada']) ? null : (int)$_POST['id_parte_ruta_asociada'];
                $id_direccion_envio = empty($_POST['id_direccion_envio']) || $_POST['id_direccion_envio'] === 'main_address' ? null : (int)$_POST['id_direccion_envio']; // NUEVO CAMPO

                if (empty($id_cliente)) {
                    throw new Exception("Debe seleccionar un cliente para la factura.");
                }

                // Inicializar totales y descuentos/recargos globales a 0 para la nueva factura
                $stmt = $pdo->prepare("INSERT INTO facturas_ventas (id_cliente, fecha_factura, estado_factura, id_pedido_asociado, id_parte_ruta_asociada, descuento_global_aplicado, recargo_global_aplicado, total_base_imponible_factura, total_iva_factura, total_factura_iva_incluido, id_direccion_envio) VALUES (?, ?, ?, ?, ?, 0.00, 0.00, 0.00, 0.00, 0.00, ?)");
                $stmt->execute([$id_cliente, $fecha_factura, $estado_factura, $id_pedido_asociado, $id_parte_ruta_asociada, $id_direccion_envio]);
                $id_factura_nueva = $pdo->lastInsertId();

                // Si se asoció un pedido, actualizar su estado a 'parcialmente_facturado' o 'completado'
                if ($id_pedido_asociado) {
                    // Lógica para actualizar el estado del pedido en base a si está completamente facturado
                    // Por simplicidad, aquí solo lo marcamos como 'parcialmente_facturado' o 'completado'
                    // Una lógica más robusta implicaría comparar cantidades facturadas vs. cantidades pedidas
                    $stmt_update_pedido = $pdo->prepare("UPDATE pedidos SET id_factura_asociada = ?, estado_pedido = 'parcialmente_facturado' WHERE id_pedido = ?");
                    $stmt_update_pedido->execute([$id_factura_nueva, $id_pedido_asociado]);
                }

                $response_data = ['success' => true, 'message' => 'Factura creada con éxito. ID: ' . $id_factura_nueva, 'id_factura' => $id_factura_nueva];
                break;

            case 'editar_factura':
                $id_factura = $_POST['id_factura'];
                $id_cliente = $_POST['id_cliente'] ?? null;
                $fecha_factura = $_POST['fecha_factura'] ?? date('Y-m-d');
                $estado_factura = $_POST['estado_factura'] ?? 'pendiente';
                $id_pedido_asociado = empty($_POST['id_pedido_asociado']) ? null : (int)$_POST['id_pedido_asociado'];
                $id_parte_ruta_asociada = empty($_POST['id_parte_ruta_asociada']) ? null : (int)$_POST['id_parte_ruta_asociada'];
                $id_direccion_envio = empty($_POST['id_direccion_envio']) || $_POST['id_direccion_envio'] === 'main_address' ? null : (int)$_POST['id_direccion_envio']; // NUEVO CAMPO

                if (empty($id_cliente)) {
                    throw new Exception("Debe seleccionar un cliente para la factura.");
                }

                $stmt = $pdo->prepare("UPDATE facturas_ventas SET id_cliente = ?, fecha_factura = ?, estado_factura = ?, id_pedido_asociado = ?, id_parte_ruta_asociada = ?, id_direccion_envio = ? WHERE id_factura = ?");
                $stmt->execute([$id_cliente, $fecha_factura, $estado_factura, $id_pedido_asociado, $id_parte_ruta_asociada, $id_direccion_envio, $id_factura]);

                // Recalcular los totales de la factura principal
                recalcularTotalFactura($pdo, $id_factura);

                // Actualizar el estado del pedido asociado si existe
                if ($id_pedido_asociado) {
                    $stmt_update_pedido = $pdo->prepare("UPDATE pedidos SET id_factura_asociada = ?, estado_pedido = 'parcialmente_facturado' WHERE id_pedido = ?");
                    $stmt_update_pedido->execute([$id_factura, $id_pedido_asociado]);
                }

                $response_data = ['success' => true, 'message' => 'Factura actualizada con éxito.'];
                break;

            case 'eliminar_factura':
                $id_factura = $_POST['id_factura'];

                // Obtener el ID del pedido asociado antes de eliminar la factura
                $stmt_get_pedido_id = $pdo->prepare("SELECT id_pedido_asociado FROM facturas_ventas WHERE id_factura = ?");
                $stmt_get_pedido_id->execute([$id_factura]);
                $pedido_asociado_id = $stmt_get_pedido_id->fetchColumn();

                // Eliminar detalles de la factura primero
                $stmt_delete_detalles = $pdo->prepare("DELETE FROM detalle_factura_ventas WHERE id_factura = ?");
                $stmt_delete_detalles->execute([$id_factura]);

                // Luego eliminar la factura principal
                $stmt_delete_factura = $pdo->prepare("DELETE FROM facturas_ventas WHERE id_factura = ?");
                $stmt_delete_factura->execute([$id_factura]);

                // Si había un pedido asociado, desvincular la factura y restablecer su estado
                if ($pedido_asociado_id) {
                    $stmt_update_pedido = $pdo->prepare("UPDATE pedidos SET id_factura_asociada = NULL, estado_pedido = 'pendiente' WHERE id_pedido = ?");
                    $stmt_update_pedido->execute([$pedido_asociado_id]);
                }

                $response_data = ['success' => true, 'message' => 'Factura eliminada con éxito.'];
                break;

            case 'agregar_detalle_linea':
                $id_factura = $_POST['id_factura'];
                $id_producto = $_POST['id_producto'];
                $cantidad = (int)$_POST['cantidad'];
                $precio_unitario_venta = $productos_map[$id_producto]['precio_venta'] ?? 0.00; // Precio con IVA
                $iva_porcentaje = $productos_map[$id_producto]['porcentaje_iva'] ?? 21.00;
                $unidades_retiradas = (int)$_POST['unidades_retiradas']; // Nuevo campo

                if ($cantidad <= 0) {
                    throw new Exception("La cantidad debe ser un número positivo.");
                }
                if ($precio_unitario_venta <= 0) {
                    throw new Exception("El precio unitario debe ser un número positivo.");
                }
                if ($unidades_retiradas < 0 || $unidades_retiradas > $cantidad) {
                    throw new Exception("Las unidades retiradas no pueden ser negativas ni mayores que la cantidad.");
                }

                // Calcular subtotal base (antes de IVA, con descuento/recargo de línea)
                // Si precio_unitario_venta es IVA incluido, la base imponible es: precio_unitario_venta / (1 + iva_porcentaje/100)
                bcscale(4); // Set precision for bcmath operations
                $precio_unitario_base_imponible_str = bcdiv((string)$precio_unitario_venta, bcadd("1", bcdiv((string)$iva_porcentaje, "100")));

                $subtotal_base_linea_str = bcmul((string)$cantidad, $precio_unitario_base_imponible_str);
                $subtotal_iva_linea_str = bcmul($subtotal_base_linea_str, bcdiv((string)$iva_porcentaje, "100"));
                $subtotal_total_linea_str = bcadd($subtotal_base_linea_str, $subtotal_iva_linea_str);

                $stmt = $pdo->prepare("INSERT INTO detalle_factura_ventas (id_factura, id_producto, cantidad, precio_unitario_venta, iva_porcentaje, subtotal_base_linea, subtotal_iva_linea, subtotal_total_linea, unidades_retiradas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $id_factura,
                    $id_producto,
                    $cantidad,
                    round((float)$precio_unitario_venta, 2),
                    round((float)$iva_porcentaje, 2),
                    round((float)$subtotal_base_linea_str, 2),
                    round((float)$subtotal_iva_linea_str, 2),
                    round((float)$subtotal_total_linea_str, 2),
                    $unidades_retiradas
                ]);

                // Actualizar stock del producto
                $stmt_update_stock = $pdo->prepare("UPDATE productos SET stock_actual_unidades = stock_actual_unidades - ? WHERE id_producto = ?");
                $stmt_update_stock->execute([$unidades_retiradas, $id_producto]);


                // Recalcular los totales de la factura principal
                recalcularTotalFactura($pdo, $id_factura);

                $response_data = ['success' => true, 'message' => 'Línea de detalle agregada con éxito.'];
                break;

            case 'eliminar_detalle_linea':
                $id_detalle_factura = $_POST['id_detalle_factura'];
                $id_factura_original = $_POST['id_factura_original'];

                // Obtener unidades retiradas y id_producto antes de eliminar
                $stmt_get_line_info = $pdo->prepare("SELECT id_producto, unidades_retiradas FROM detalle_factura_ventas WHERE id_detalle_factura = ?");
                $stmt_get_line_info->execute([$id_detalle_factura]);
                $line_info = $stmt_get_line_info->fetch(PDO::FETCH_ASSOC);

                $stmt_delete = $pdo->prepare("DELETE FROM detalle_factura_ventas WHERE id_detalle_factura = ?");
                $stmt_delete->execute([$id_detalle_factura]);

                // Revertir stock del producto si se eliminó una línea con unidades retiradas
                if ($line_info && $line_info['unidades_retiradas'] > 0) {
                    $stmt_update_stock = $pdo->prepare("UPDATE productos SET stock_actual_unidades = stock_actual_unidades + ? WHERE id_producto = ?");
                    $stmt_update_stock->execute([$line_info['unidades_retiradas'], $line_info['id_producto']]);
                }

                // Recalcular los totales de la factura principal después de eliminar la línea
                recalcularTotalFactura($pdo, $id_factura_original);

                $response_data = ['success' => true, 'message' => 'Línea de detalle eliminada con éxito.'];
                break;

            case 'update_global_discount_factura':
                $id_factura = $_POST['id_factura'];
                $new_descuento_global = (float)($_POST['descuento_global'] ?? 0.00);

                if ($new_descuento_global < 0) {
                    throw new Exception("El descuento global no puede ser negativo.");
                }

                $stmt_update_discount = $pdo->prepare("UPDATE facturas_ventas SET descuento_global_aplicado = ? WHERE id_factura = ?");
                $stmt_update_discount->execute([round($new_descuento_global, 2), $id_factura]);

                recalcularTotalFactura($pdo, $id_factura);
                $response_data = ['success' => true, 'message' => 'Descuento global de la factura actualizado con éxito y líneas recalculadas.'];
                break;

            case 'update_global_surcharge_factura':
                $id_factura = $_POST['id_factura'];
                $new_recargo_global = (float)($_POST['recargo_global'] ?? 0.00);

                if ($new_recargo_global < 0) {
                    throw new Exception("El recargo global no puede ser negativo.");
                }

                $stmt_update_surcharge = $pdo->prepare("UPDATE facturas_ventas SET recargo_global_aplicado = ? WHERE id_factura = ?");
                $stmt_update_surcharge->execute([round($new_recargo_global, 2), $id_factura]);

                recalcularTotalFactura($pdo, $id_factura);
                $response_data = ['success' => true, 'message' => 'Recargo global de la factura actualizado con éxito y líneas recalculadas.'];
                break;

            case 'agregar_ajax': // Para añadir cliente desde el modal de factura
                $nombre_cliente = trim($_POST['nombre_cliente'] ?? '');
                $direccion = trim($_POST['direccion'] ?? '');
                $ciudad = trim($_POST['ciudad'] ?? '');
                $provincia = trim($_POST['provincia'] ?? '');
                $codigo_postal = trim($_POST['codigo_postal'] ?? '');
                $telefono = trim($_POST['telefono'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $nif = trim($_POST['nif'] ?? '');
                $latitud = filter_var($_POST['latitud'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['latitud'] : null;
                $longitud = filter_var($_POST['longitud'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['longitud'] : null;
                $direccion_google_maps = trim($_POST['direccion_google_maps'] ?? '');

                if (empty($nombre_cliente)) {
                    throw new Exception("Error: El nombre del cliente es obligatorio.");
                }

                $nif_db = empty($nif) ? null : $nif;

                $stmt = $pdo->prepare("INSERT INTO clientes (nombre_cliente, direccion, ciudad, provincia, codigo_postal, telefono, email, nif, latitud, longitud, direccion_google_maps) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nombre_cliente, $direccion, $ciudad, $provincia, $codigo_postal, $telefono, $email, $nif_db, $latitud, $longitud, $direccion_google_maps]);
                $new_id = $pdo->lastInsertId();

                $response_data = ['success' => true, 'message' => 'Cliente agregado correctamente.', 'cliente' => [
                    'id_cliente' => $new_id,
                    'nombre_cliente' => $nombre_cliente,
                    'nif' => $nif_db,
                    'telefono' => $telefono,
                    'email' => $email,
                    'latitud' => $latitud,
                    'longitud' => $longitud,
                    'direccion_google_maps' => $direccion_google_maps
                ]];
                break;

            default:
                throw new Exception("Acción no reconocida.");
        }

        if ($in_transaction) $pdo->commit();
        echo json_encode($response_data);
        exit(); // Salir para asegurar que no se envíe más contenido

    } catch (Exception $e) {
        if ($in_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
        exit(); // Salir para asegurar que no se envíe más contenido
    }
}

// Limpiar el búfer de salida para el resto del script (HTML)
ob_end_clean();

/**
 * Recalcula y actualiza los totales de una factura, aplicando un descuento global y un recargo global
 * proporcionalmente a cada línea de detalle.
 *
 * @param PDO $pdo Objeto PDO de la conexión a la base de datos.
 * @param int $id_factura ID de la factura a actualizar.
 * @throws Exception Si ocurre un error al recalcular los totales.
 */
function recalcularTotalFactura(PDO $pdo, int $id_factura) {
    try {
        bcscale(4); // Set precision for bcmath operations

        // 1. Obtener el descuento global y el recargo global aplicados a esta factura
        $stmt_get_invoice_adjustments = $pdo->prepare("SELECT descuento_global_aplicado, recargo_global_aplicado FROM facturas_ventas WHERE id_factura = ?");
        $stmt_get_invoice_adjustments->execute([$id_factura]);
        $adjustments = $stmt_get_invoice_adjustments->fetch(PDO::FETCH_ASSOC);

        $descuento_global_aplicado_str = (string)($adjustments['descuento_global_aplicado'] ?? "0.00");
        $recargo_global_aplicado_str = (string)($adjustments['recargo_global_aplicado'] ?? "0.00");

        // 2. Obtener todas las líneas de detalle de esta factura
        // Necesitamos los valores originales de cantidad, precio_unitario_venta, iva_porcentaje
        $stmt_get_line_items = $pdo->prepare("
            SELECT
                dfv.id_detalle_factura,
                dfv.cantidad,
                dfv.precio_unitario_venta,
                dfv.iva_porcentaje
            FROM detalle_factura_ventas dfv
            WHERE dfv.id_factura = ? FOR UPDATE
        ");
        $stmt_get_line_items->execute([$id_factura]);
        $line_items = $stmt_get_line_items->fetchAll(PDO::FETCH_ASSOC);

        // Calcular la suma total de los precios (IVA incluido) de las líneas antes de aplicar descuento/recargo global
        $total_sum_of_line_totals_before_global_adjustment = "0";
        foreach ($line_items as $item) {
            $cantidad_str = (string)$item['cantidad'];
            $precio_unitario_venta_str = (string)$item['precio_unitario_venta']; // Este es el precio IVA incluido

            // Calcular el total de la línea (IVA incluido)
            $total_linea_before_global_adjustment = bcmul($cantidad_str, $precio_unitario_venta_str);
            $total_sum_of_line_totals_before_global_adjustment = bcadd($total_sum_of_line_totals_before_global_adjustment, $total_linea_before_global_adjustment);
        }

        // Calcular el ajuste neto global (recargo global - descuento global)
        $net_global_adjustment = bcsub($recargo_global_aplicado_str, $descuento_global_aplicado_str);

        $final_total_base_imponible_factura = "0";
        $final_total_iva_factura = "0";
        $final_total_factura_iva_incluido = "0";

        // 3. Recalcular cada línea de detalle aplicando el ajuste global proporcionalmente
        $stmt_update_line_item = $pdo->prepare("
            UPDATE detalle_factura_ventas
            SET
                subtotal_base_linea = ?,
                subtotal_iva_linea = ?,
                subtotal_total_linea = ?
            WHERE id_detalle_factura = ?
        ");

        foreach ($line_items as $item) {
            $cantidad_str = (string)$item['cantidad'];
            $precio_unitario_venta_str = (string)$item['precio_unitario_venta']; // Este es el precio IVA incluido
            $iva_linea_str = (string)$item['iva_porcentaje'];

            // Recalcular el total de la línea (IVA incluido)
            $total_linea_before_global_adjustment = bcmul($cantidad_str, $precio_unitario_venta_str);

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
            $subtotal_base_linea_final = bcdiv($total_linea_iva_incluido_final, bcadd("1", bcdiv($iva_linea_str, "100")));
            $subtotal_iva_linea_final = bcsub($total_linea_iva_incluido_final, $subtotal_base_linea_final);

            $stmt_update_line_item->execute([
                round((float)$subtotal_base_linea_final, 2),
                round((float)$subtotal_iva_linea_final, 2),
                round((float)$total_linea_iva_incluido_final, 2),
                $item['id_detalle_factura']
            ]);

            $final_total_base_imponible_factura = bcadd($final_total_base_imponible_factura, $subtotal_base_linea_final);
            $final_total_iva_factura = bcadd($final_total_iva_factura, $subtotal_iva_linea_final);
            $final_total_factura_iva_incluido = bcadd($final_total_factura_iva_incluido, $total_linea_iva_incluido_final);
        }

        // 4. Actualizar la cabecera de la factura con los nuevos totales
        $stmt_update_factura = $pdo->prepare("
            UPDATE facturas_ventas
            SET
                total_base_imponible_factura = ?,
                total_iva_factura = ?,
                total_factura_iva_incluido = ?,
                descuento_global_aplicado = ?,
                recargo_global_aplicado = ?
            WHERE id_factura = ?
        ");
        $stmt_update_factura->execute([
            round((float)$final_total_base_imponible_factura, 2),
            round((float)$final_total_iva_factura, 2),
            round((float)$final_total_factura_iva_incluido, 2),
            round((float)$descuento_global_aplicado_str, 2),
            round((float)$recargo_global_aplicado_str, 2),
            $id_factura
        ]);

    } catch (Exception $e) {
        error_log("Error al recalcular total de factura (ID: " . $id_factura . "): " . $e->getMessage());
        throw new Exception("Error al recalcular total de factura: " . $e->getMessage());
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
$facturas = [];
$factura_actual = null;
$detalles_factura = [];

if ($view == 'list') {
    try {
        $stmt_facturas = $pdo->query("
            SELECT
                fv.id_factura,
                fv.fecha_factura,
                fv.id_cliente,
                fv.estado_factura,
                fv.total_factura_iva_incluido,
                fv.id_pedido_asociado,
                fv.id_parte_ruta_asociada,
                fv.id_direccion_envio, -- NUEVO
                c.nombre_cliente,
                p.fecha_pedido,
                r.nombre_ruta,
                COALESCE(SUM(cf.cantidad_cobrada), 0) AS total_cobrado
            FROM facturas_ventas fv
            JOIN clientes c ON fv.id_cliente = c.id_cliente
            LEFT JOIN pedidos p ON fv.id_pedido_asociado = p.id_pedido
            LEFT JOIN rutas r ON fv.id_parte_ruta_asociada = r.id_ruta -- Esto es incorrecto, id_parte_ruta_asociada es un ID de parte de ruta, no de ruta directamente
            LEFT JOIN cobros_factura cf ON fv.id_factura = cf.id_factura
            GROUP BY fv.id_factura
            ORDER BY fv.fecha_factura DESC, fv.id_factura DESC
        ");
        $facturas = $stmt_facturas->fetchAll(PDO::FETCH_ASSOC);

        // Post-process to determine payment status
        foreach ($facturas as &$factura) {
            $factura['estado_pago'] = (bccomp((string)$factura['total_factura_iva_incluido'], (string)$factura['total_cobrado'], 2) > 0) ? 'Pendiente' : 'Pagado';
        }
        unset($factura); // Break the reference

    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos al cargar facturas: " . $e->getMessage(), "danger");
    }
} elseif ($view == 'details' && isset($_GET['id'])) {
    $id_factura = $_GET['id'];
    try {
        $stmt_factura_actual = $pdo->prepare("
            SELECT
                fv.*,
                c.nombre_cliente, c.nif, c.telefono, c.email, c.direccion AS cliente_direccion_principal, c.ciudad AS cliente_ciudad_principal, c.provincia AS cliente_provincia_principal, c.codigo_postal AS cliente_codigo_postal_principal,
                p.fecha_pedido, p.estado_pedido AS estado_pedido_asociado,
                r.nombre_ruta, -- Esto es incorrecto, id_parte_ruta_asociada es un ID de parte de ruta, no de ruta directamente
                de.nombre_direccion AS envio_nombre_direccion, -- NUEVO
                de.direccion AS envio_direccion, -- NUEVO
                de.ciudad AS envio_ciudad, -- NUEVO
                de.provincia AS envio_provincia, -- NUEVO
                de.codigo_postal AS envio_codigo_postal -- NUEVO
            FROM facturas_ventas fv
            JOIN clientes c ON fv.id_cliente = c.id_cliente
            LEFT JOIN pedidos p ON fv.id_pedido_asociado = p.id_pedido
            LEFT JOIN rutas r ON fv.id_parte_ruta_asociada = r.id_ruta -- Esto es incorrecto
            LEFT JOIN direcciones_envio de ON fv.id_direccion_envio = de.id_direccion_envio -- NUEVO JOIN
            WHERE fv.id_factura = ?
            GROUP BY fv.id_factura -- Agrupar para evitar duplicados si hay múltiples rutas asociadas (aunque aquí solo se usa una)
        ");
        $stmt_factura_actual->execute([$id_factura]);
        $factura_actual = $stmt_factura_actual->fetch(PDO::FETCH_ASSOC);

        if (!$factura_actual) {
            mostrarMensaje("Factura no encontrada.", "danger");
            header("Location: facturas_ventas.php");
            exit();
        }

        // Obtener cobros para esta factura
        $stmt_cobros = $pdo->prepare("SELECT SUM(cantidad_cobrada) AS total_cobrado FROM cobros_factura WHERE id_factura = ?");
        $stmt_cobros->execute([$id_factura]);
        $factura_actual['total_cobrado'] = $stmt_cobros->fetchColumn() ?? 0;
        $factura_actual['estado_pago'] = (bccomp((string)$factura_actual['total_factura_iva_incluido'], (string)$factura_actual['total_cobrado'], 2) > 0) ? 'Pendiente' : 'Pagado';

        // Determinar la dirección de envío a mostrar
        $direccion_envio_completa = '';
        if ($factura_actual['id_direccion_envio'] && $factura_actual['envio_direccion']) {
            // Usar la dirección de envío específica
            $direccion_envio_completa = htmlspecialchars($factura_actual['envio_direccion']);
            if (!empty($factura_actual['envio_ciudad'])) $direccion_envio_completa .= ', ' . htmlspecialchars($factura_actual['envio_ciudad']);
            if (!empty($factura_actual['envio_provincia'])) $direccion_envio_completa .= ', ' . htmlspecialchars($factura_actual['envio_provincia']);
            if (!empty($factura_actual['envio_codigo_postal'])) $direccion_envio_completa .= ' ' . htmlspecialchars($factura_actual['envio_codigo_postal']);
            $factura_actual['direccion_envio_display'] = htmlspecialchars($factura_actual['envio_nombre_direccion']) . ": " . $direccion_envio_completa;
        } else {
            // Usar la dirección principal del cliente
            $direccion_envio_completa = htmlspecialchars($factura_actual['cliente_direccion_principal'] ?? 'N/A');
            if (!empty($factura_actual['cliente_ciudad_principal'])) $direccion_envio_completa .= ', ' . htmlspecialchars($factura_actual['cliente_ciudad_principal']);
            if (!empty($factura_actual['cliente_provincia_principal'])) $direccion_envio_completa .= ', ' . htmlspecialchars($factura_actual['cliente_provincia_principal']);
            if (!empty($factura_actual['cliente_codigo_postal_principal'])) $direccion_envio_completa .= ' ' . htmlspecialchars($factura_actual['cliente_codigo_postal_principal']);
            $factura_actual['direccion_envio_display'] = "Dirección Principal del Cliente: " . $direccion_envio_completa;
        }


        $stmt_detalles = $pdo->prepare("
            SELECT dfv.*, prod.nombre_producto, COALESCE(prod.litros_por_unidad, 1.00) AS litros_por_unidad
            FROM detalle_factura_ventas dfv
            JOIN productos prod ON dfv.id_producto = prod.id_producto
            WHERE dfv.id_factura = ?
            ORDER BY dfv.id_detalle_factura ASC
        ");
        $stmt_detalles->execute([$id_factura]);
        $detalles_factura = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos al cargar detalles de la factura: " . $e->getMessage(), "danger");
        header("Location: facturas_ventas.php");
        exit();
    }
}

// Si se recibe un ID de cliente para una nueva factura (desde clientes.php o pedidos.php)
$new_invoice_client_id = $_GET['new_invoice_client_id'] ?? null;
$new_invoice_client_name = $_GET['new_invoice_client_name'] ?? '';
$new_invoice_order_id = $_GET['new_invoice_order_id'] ?? null;
$new_invoice_parte_ruta_id = $_GET['new_invoice_parte_ruta_id'] ?? null;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Facturas de Ventas</title>
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
        /* COLORES ESPECÍFICOS PARA FACTURAS */
        .card-header {
            background-color: #007bff; /* Azul para encabezados de card */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #007bff; /* Azul para botones primarios de facturas */
            border-color: #007bff;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background-color: #0056b3; /* Azul más oscuro al pasar el ratón */
            border-color: #0056b3;
        }
        .btn-success {
            background-color: #28a745; /* Verde para éxito/añadir línea */
            border-color: #28a745;
            border-radius: 8px;
        }
        .btn-success:hover {
            background-color: #218838;
            border-color: #218838;
        }
        .btn-info {
            background-color: #17a2b8; /* Azul claro para info/ver */
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
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #343a40; /* Texto oscuro para contraste */
            border-radius: 8px;
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #e0a800;
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
            background-color: #007bff; /* Azul para encabezados de modal de facturas */
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

        /* NEW: Styles for 3-column layout in invoice header details */
        .invoice-header-details p {
            margin-bottom: 0.5rem; /* Reduce space between paragraphs */
        }
        .invoice-header-financials .form-control {
            max-width: 120px; /* Adjust width of input fields for discount/surcharge */
        }
        /* Justify right for financial values in header */
        .invoice-header-financials .col-md-3 p {
            text-align: right;
        }
        .invoice-header-financials .col-md-3 p strong {
            float: left; /* Keep labels left-aligned */
        }
        .invoice-header-financials .col-md-3 p span {
            display: inline-block; /* Allow span to respect text-align: right */
        }
        /* Specific alignment for discount/surcharge input fields */
        .invoice-header-financials .d-flex .form-control {
            text-align: right; /* Justify input values to the right */
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluir la barra lateral ?>
        <div class="content">
            <div class="container-fluid">
                <h1 class="mb-4">Gestión de Facturas de Ventas</h1>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($view == 'list'): ?>
                    <div class="card">
                        <div class="card-header">
                            Lista de Facturas
                        </div>
                        <div class="card-body">
                            <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addFacturaModal">
                                <i class="bi bi-plus-circle"></i> Nueva Factura
                            </button>
                            <div class="table-responsive scrollable-table-container">
                                <table class="table table-striped table-hover" id="facturasListTable">
                                    <thead>
                                        <tr>
                                            <th>ID Factura</th>
                                            <th>Fecha</th>
                                            <th>Cliente</th>
                                            <th>Pedido Asociado</th>
                                            <th>Parte Ruta Asociada</th>
                                            <th>Estado Factura</th>
                                            <th>Estado Pago</th>
                                            <th class="text-end">Total (IVA Inc.)</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($facturas)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center">No hay facturas registradas.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($facturas as $factura):
                                                $badge_class_factura = '';
                                                switch ($factura['estado_factura']) {
                                                    case 'pendiente':
                                                        $badge_class_factura = 'bg-warning text-dark';
                                                        break;
                                                    case 'emitida':
                                                        $badge_class_factura = 'bg-info';
                                                        break;
                                                    case 'pagada':
                                                        $badge_class_factura = 'bg-success';
                                                        break;
                                                    case 'anulada':
                                                        $badge_class_factura = 'bg-danger';
                                                        break;
                                                }
                                                $badge_class_pago = ($factura['estado_pago'] == 'Pendiente') ? 'bg-danger' : 'bg-success';
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($factura['id_factura']); ?></td>
                                                    <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($factura['fecha_factura']))); ?></td>
                                                    <td><?php echo htmlspecialchars($factura['nombre_cliente']); ?></td>
                                                    <td>
                                                        <?php if ($factura['id_pedido_asociado']): ?>
                                                            <a href="pedidos.php?view=details&id=<?php echo htmlspecialchars($factura['id_pedido_asociado']); ?>" class="badge bg-primary">Pedido #<?php echo htmlspecialchars($factura['id_pedido_asociado']); ?></a>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($factura['id_parte_ruta_asociada']): ?>
                                                            <a href="partes_ruta.php?view=details&id=<?php echo htmlspecialchars($factura['id_parte_ruta_asociada']); ?>" class="badge bg-info">Parte #<?php echo htmlspecialchars($factura['id_parte_ruta_asociada']); ?></a>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><span class="badge <?php echo htmlspecialchars($badge_class_factura); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $factura['estado_factura']))); ?></span></td>
                                                    <td><span class="badge <?php echo htmlspecialchars($badge_class_pago); ?>"><?php echo htmlspecialchars($factura['estado_pago']); ?></span></td>
                                                    <td class="text-end"><?php echo number_format($factura['total_factura_iva_incluido'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-center">
                                                        <a href="facturas_ventas.php?view=details&id=<?php echo htmlspecialchars($factura['id_factura']); ?>" class="btn btn-info btn-sm me-1" title="Ver Detalles">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <button class="btn btn-danger btn-sm" onclick="confirmDeleteFactura(<?php echo htmlspecialchars($factura['id_factura']); ?>)" title="Eliminar Factura">
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

                    <!-- Modal para Añadir Nueva Factura -->
                    <div class="modal fade" id="addFacturaModal" tabindex="-1" aria-labelledby="addFacturaModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form action="facturas_ventas.php" method="POST" id="facturaForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addFacturaModalLabel">Nueva Factura</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="agregar_factura">
                                        <input type="hidden" name="id_pedido_asociado" id="new_id_pedido_asociado" value="<?php echo htmlspecialchars($new_invoice_order_id ?? ''); ?>">
                                        <input type="hidden" name="id_parte_ruta_asociada" id="new_id_parte_ruta_asociada" value="<?php echo htmlspecialchars($new_invoice_parte_ruta_id ?? ''); ?>">

                                        <div class="mb-3">
                                            <label for="fecha_factura" class="form-label">Fecha Factura</label>
                                            <input type="date" class="form-control" id="fecha_factura" name="fecha_factura" value="<?php echo date('Y-m-d'); ?>" required>
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
                                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="createNewClientFromInvoiceModal()">
                                                    <i class="bi bi-person-plus"></i> Crear Nuevo Cliente
                                                </button>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="id_direccion_envio" class="form-label">Dirección de Envío</label>
                                            <select class="form-select" id="id_direccion_envio" name="id_direccion_envio">
                                                <!-- Opciones se cargarán dinámicamente vía JS -->
                                                <option value="">Cargando direcciones...</option>
                                            </select>
                                            <small class="form-text text-muted">Si no se selecciona, se usará la dirección principal del cliente.</small>
                                        </div>

                                        <div class="mb-3">
                                            <label for="estado_factura" class="form-label">Estado Factura</label>
                                            <select class="form-select" id="estado_factura" name="estado_factura" required>
                                                <option value="pendiente">Pendiente</option>
                                                <option value="emitida">Emitida</option>
                                                <option value="pagada">Pagada</option>
                                                <option value="anulada">Anulada</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary" id="submitNewFacturaBtn">Crear Factura</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php elseif ($view == 'details' && $factura_actual): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            Detalles de la Factura #<?php echo htmlspecialchars($factura_actual['id_factura']); ?>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3 invoice-header-details">
                                <div class="col-md-6">
                                    <p><strong>Fecha Factura:</strong> <?php echo htmlspecialchars(date('d/m/Y', strtotime($factura_actual['fecha_factura']))); ?></p>
                                    <p>
                                        <strong>Cliente:</strong>
                                        <?php echo htmlspecialchars($factura_actual['nombre_cliente']); ?>
                                        <a href="clientes.php?id=<?php echo htmlspecialchars($factura_actual['id_cliente']); ?>" class="btn btn-sm btn-outline-secondary ms-2" title="Ver/Modificar Ficha Cliente">
                                            <i class="bi bi-person-lines-fill"></i> Ver Ficha
                                        </a>
                                    </p>
                                    <p><strong>NIF:</strong> <?php echo htmlspecialchars($factura_actual['nif'] ?? 'N/A'); ?></p>
                                    <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($factura_actual['telefono'] ?? 'N/A'); ?></p>
                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($factura_actual['email'] ?? 'N/A'); ?></p>
                                    <p><strong>Dirección de Envío:</strong> <?php echo $factura_actual['direccion_envio_display']; ?></p> <!-- NUEVO DISPLAY -->
                                </div>
                                <div class="col-md-3 invoice-header-financials">
                                    <!-- Formulario para Descuento Global -->
                                    <form id="updateDiscountFormFactura" action="facturas_ventas.php" method="POST" class="mb-2">
                                        <input type="hidden" name="accion" value="update_global_discount_factura">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <div class="d-flex justify-content-start align-items-center">
                                            <label for="descuento_global_edit_factura" class="form-label mb-0 me-2"><strong>Descuento Global:</strong></label>
                                            <input type="number" class="form-control w-auto text-end me-2" id="descuento_global_edit_factura" name="descuento_global" step="0.01" min="0" value="<?php echo number_format($factura_actual['descuento_global_aplicado'], 2, '.', ''); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Actualizar Descuento">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                    </form>
                                    <!-- Formulario para Recargo Global -->
                                    <form id="updateSurchargeFormFactura" action="facturas_ventas.php" method="POST" class="mb-2">
                                        <input type="hidden" name="accion" value="update_global_surcharge_factura">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <div class="d-flex justify-content-start align-items-center">
                                            <label for="recargo_global_edit_factura" class="form-label mb-0 me-2"><strong>Recargo Global:</strong></label>
                                            <input type="number" class="form-control w-auto text-end me-2" id="recargo_global_edit_factura" name="recargo_global" step="0.01" min="0" value="<?php echo number_format($factura_actual['recargo_global_aplicado'], 2, '.', ''); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Actualizar Recargo">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                    </form>
                                    <p><strong>Base Imponible Total:</strong> <span class="fs-5 text-dark"><?php echo number_format($factura_actual['total_base_imponible_factura'], 2, ',', '.'); ?> €</span></p>
                                    <p><strong>Total IVA:</strong> <span class="fs-5 text-dark"><?php echo number_format($factura_actual['total_iva_factura'], 2, ',', '.'); ?> €</span></p>
                                </div>
                                <div class="col-md-3">
                                    <p><strong>Total Factura (IVA Inc.):</strong> <span class="fs-4 text-primary"><?php echo number_format($factura_actual['total_factura_iva_incluido'], 2, ',', '.'); ?> €</span></p>
                                    <p><strong>Total Cobrado:</strong> <span class="fs-5 text-success"><?php echo number_format($factura_actual['total_cobrado'], 2, ',', '.'); ?> €</span></p>
                                    <?php
                                        $badge_class_factura_det = '';
                                        switch ($factura_actual['estado_factura']) {
                                            case 'pendiente': $badge_class_factura_det = 'bg-warning text-dark'; break;
                                            case 'emitida': $badge_class_factura_det = 'bg-info'; break;
                                            case 'pagada': $badge_class_factura_det = 'bg-success'; break;
                                            case 'anulada': $badge_class_factura_det = 'bg-danger'; break;
                                        }
                                        $badge_class_pago_det = ($factura_actual['estado_pago'] == 'Pendiente') ? 'bg-danger' : 'bg-success';
                                    ?>
                                    <p><strong>Estado Factura:</strong> <span class="badge <?php echo htmlspecialchars($badge_class_factura_det); ?> fs-6"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $factura_actual['estado_factura']))); ?></span></p>
                                    <p><strong>Estado Pago:</strong> <span class="badge <?php echo htmlspecialchars($badge_class_pago_det); ?> fs-6"><?php echo htmlspecialchars($factura_actual['estado_pago']); ?></span></p>
                                    <?php if ($factura_actual['id_pedido_asociado']): ?>
                                        <p><strong>Pedido Asociado:</strong> <a href="pedidos.php?view=details&id=<?php echo htmlspecialchars($factura_actual['id_pedido_asociado']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-cart"></i> Ver Pedido #<?php echo htmlspecialchars($factura_actual['id_pedido_asociado']); ?></a></p>
                                    <?php endif; ?>
                                    <?php if ($factura_actual['id_parte_ruta_asociada']): ?>
                                        <p><strong>Parte de Ruta Asociada:</strong> <a href="partes_ruta.php?view=details&id=<?php echo htmlspecialchars($factura_actual['id_parte_ruta_asociada']); ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-truck"></i> Ver Parte #<?php echo htmlspecialchars($factura_actual['id_parte_ruta_asociada']); ?></a></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="facturas_ventas.php" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Volver a la Lista</a>
                            <button class="btn btn-warning mb-3 ms-2" data-bs-toggle="modal" data-bs-target="#editFacturaModal">
                                <i class="bi bi-pencil"></i> Editar Factura
                            </button>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            Líneas de Detalle de la Factura
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
                                            <th class="text-end">Unidades Retiradas</th>
                                            <th class="text-end">Precio Unitario (IVA Inc.)</th>
                                            <th class="text-end">IVA (%)</th>
                                            <th class="text-end">Base Imponible</th>
                                            <th class="text-end">IVA Línea</th>
                                            <th class="text-end">Total Línea (IVA Inc.)</th>
                                            <th class="text-end">Litros Línea</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($detalles_factura)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center">No hay líneas de detalle para esta factura.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($detalles_factura as $detalle): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($detalle['nombre_producto']); ?></td>
                                                    <td class="text-end"><?php echo htmlspecialchars($detalle['cantidad']); ?></td>
                                                    <td class="text-end"><?php echo htmlspecialchars($detalle['unidades_retiradas']); ?></td>
                                                    <td class="text-end"><?php echo number_format($detalle['precio_unitario_venta'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['iva_porcentaje'], 2, ',', '.'); ?></td>
                                                    <td class="text-end"><?php echo number_format($detalle['subtotal_base_linea'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['subtotal_iva_linea'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['subtotal_total_linea'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['cantidad'] * $detalle['litros_por_unidad'], 2, ',', '.'); ?></td>
                                                    <td class="text-center">
                                                        <button class="btn btn-danger btn-sm" onclick="confirmDeleteDetalle(<?php echo htmlspecialchars($detalle['id_detalle_factura']); ?>, <?php echo htmlspecialchars($factura_actual['id_factura']); ?>)" title="Eliminar Línea">
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

                    <!-- Modal para Editar Factura -->
                    <div class="modal fade" id="editFacturaModal" tabindex="-1" aria-labelledby="editFacturaModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form action="facturas_ventas.php" method="POST" id="editFacturaForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="editFacturaModalLabel">Editar Factura #<?php echo htmlspecialchars($factura_actual['id_factura']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="editar_factura">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <div class="mb-3">
                                            <label for="edit_fecha_factura" class="form-label">Fecha Factura</label>
                                            <input type="date" class="form-control" id="edit_fecha_factura" name="fecha_factura" value="<?php echo htmlspecialchars($factura_actual['fecha_factura']); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="edit_client_search_input" class="form-label">Cliente</label>
                                            <div class="client-search-container">
                                                <input type="text" class="form-control" id="edit_client_search_input" placeholder="Buscar cliente por nombre, NIF, ciudad..." autocomplete="off" value="<?php echo htmlspecialchars($factura_actual['nombre_cliente']); ?>">
                                                <input type="hidden" id="edit_id_cliente_selected" name="id_cliente" value="<?php echo htmlspecialchars($factura_actual['id_cliente']); ?>">
                                                <div id="edit_client_search_results" class="client-search-results"></div>
                                            </div>
                                            <small class="text-danger" id="edit_client_selection_error" style="display:none;">Por favor, seleccione un cliente de la lista.</small>
                                        </div>

                                        <div class="mb-3">
                                            <label for="edit_id_direccion_envio" class="form-label">Dirección de Envío</label>
                                            <select class="form-select" id="edit_id_direccion_envio" name="id_direccion_envio">
                                                <!-- Opciones se cargarán dinámicamente vía JS -->
                                                <option value="">Cargando direcciones...</option>
                                            </select>
                                            <small class="form-text text-muted">Si no se selecciona, se usará la dirección principal del cliente.</small>
                                        </div>

                                        <div class="mb-3">
                                            <label for="edit_estado_factura" class="form-label">Estado Factura</label>
                                            <select class="form-select" id="edit_estado_factura" name="estado_factura" required>
                                                <option value="pendiente" <?php echo ($factura_actual['estado_factura'] == 'pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                                                <option value="emitida" <?php echo ($factura_actual['estado_factura'] == 'emitida') ? 'selected' : ''; ?>>Emitida</option>
                                                <option value="pagada" <?php echo ($factura_actual['estado_factura'] == 'pagada') ? 'selected' : ''; ?>>Pagada</option>
                                                <option value="anulada" <?php echo ($factura_actual['estado_factura'] == 'anulada') ? 'selected' : ''; ?>>Anulada</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="edit_id_pedido_asociado" class="form-label">Pedido Asociado</label>
                                            <input type="number" class="form-control" id="edit_id_pedido_asociado" name="id_pedido_asociado" value="<?php echo htmlspecialchars($factura_actual['id_pedido_asociado'] ?? ''); ?>" placeholder="ID del pedido (opcional)">
                                        </div>
                                        <div class="mb-3">
                                            <label for="edit_id_parte_ruta_asociada" class="form-label">Parte de Ruta Asociada</label>
                                            <input type="number" class="form-control" id="edit_id_parte_ruta_asociada" name="id_parte_ruta_asociada" value="<?php echo htmlspecialchars($factura_actual['id_parte_ruta_asociada'] ?? ''); ?>" placeholder="ID de la parte de ruta (opcional)">
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
                                <form action="facturas_ventas.php" method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addLineaModalLabel">Añadir Línea a Factura #<?php echo htmlspecialchars($factura_actual['id_factura']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="agregar_detalle_linea">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <div class="mb-3">
                                            <label for="id_producto" class="form-label">Producto</label>
                                            <select class="form-select" id="id_producto" name="id_producto" required onchange="updatePrecioUnitario()">
                                                <option value="">Seleccione un producto</option>
                                                <?php foreach ($productos_disponibles as $producto): ?>
                                                    <option
                                                        value="<?php echo htmlspecialchars($producto['id_producto']); ?>"
                                                        data-precio="<?php echo htmlspecialchars($producto['precio_venta'] ?? 0); ?>"
                                                        data-stock="<?php echo htmlspecialchars($producto['stock_actual_unidades'] ?? 0); ?>"
                                                        data-iva="<?php echo htmlspecialchars($producto['porcentaje_iva_actual'] ?? 0); ?>"
                                                        data-litros-por-unidad="<?php echo htmlspecialchars($producto['litros_por_unidad'] ?? 1); ?>"
                                                    >
                                                        <?php echo htmlspecialchars($producto['nombre_producto'] ?? 'N/A'); ?> (Stock actual: <?php echo htmlspecialchars($producto['stock_actual_unidades'] ?? 0); ?> unidades)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted" id="stockProductoInfo"></small>
                                        </div>
                                        <div class="mb-3">
                                            <label for="cantidad" class="form-label">Cantidad (unidades)</label>
                                            <input type="number" class="form-control" id="cantidad" name="cantidad" min="1" required oninput="updatePrecioUnitario()">
                                        </div>
                                        <div class="mb-3">
                                            <label for="unidades_retiradas" class="form-label">Unidades Retiradas (del stock)</label>
                                            <input type="number" class="form-control" id="unidades_retiradas" name="unidades_retiradas" min="0" value="0" required>
                                            <small class="form-text text-muted">Las unidades retiradas reducirán el stock del producto. No pueden ser mayores que la cantidad.</small>
                                        </div>
                                        <div class="mb-3">
                                            <label for="precio_unitario_venta" class="form-label">Precio Unitario Venta (IVA Inc.)</label>
                                            <input type="number" class="form-control" id="precio_unitario_venta" step="0.01" min="0.01" readonly required>
                                        </div>
                                        <div class="mb-3">
                                            <p><strong>Base Imponible Línea:</strong> <span id="linea_base_display">0.00 €</span></p>
                                            <p><strong>IVA Línea:</strong> <span id="linea_iva_display">0.00 €</span></p>
                                            <p><strong>Total Línea (IVA Incl.):</strong> <span id="linea_total_display">0.00 €</span></p>
                                            <p><strong>Litros Línea:</strong> <span id="linea_litros_display">0.00</span></p>
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
        // Mapeo de clientes y productos para acceso rápido por ID
        const clientesMapJs = <?php echo json_encode($clientes_map); ?>;
        const productosMapJs = <?php echo json_encode($productos_map); ?>;

        // Función para enviar formularios dinámicamente
        function createAndSubmitForm(action, inputs) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'facturas_ventas.php';

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

        // Función para mostrar modal de confirmación personalizado (reemplazo de confirm())
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

        function confirmDeleteFactura(id) {
            showCustomModal("Eliminar Factura", "¿Seguro que quieres eliminar esta factura? Se eliminarán todas sus líneas de detalle y se desvinculará de cualquier pedido asociado.", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('eliminar_factura', { id_factura: id });
            });
        }

        function confirmDeleteDetalle(id, idFactura) {
            showCustomModal("Eliminar Línea de Detalle", "¿Seguro que quieres eliminar esta línea de detalle de la factura? El stock del producto se revertirá.", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('eliminar_detalle_linea', { id_detalle_factura: id, id_factura_original: idFactura });
            });
        }

        // Función para actualizar el precio unitario y el stock al seleccionar un producto
        function updatePrecioUnitario() {
            const selectProducto = document.getElementById('id_producto');
            const precioUnitarioInput = document.getElementById('precio_unitario_venta');
            const cantidadInput = document.getElementById('cantidad');
            const unidadesRetiradasInput = document.getElementById('unidades_retiradas'); // Nuevo
            const stockProductoInfo = document.getElementById('stockProductoInfo');

            const lineaBaseDisplay = document.getElementById('linea_base_display');
            const lineaIvaDisplay = document.getElementById('linea_iva_display');
            const lineaTotalDisplay = document.getElementById('linea_total_display');
            const lineaLitrosDisplay = document.getElementById('linea_litros_display');

            const selectedOption = selectProducto.options[selectProducto.selectedIndex];
            const selectedProductId = selectedOption.value;

            let cantidad = parseFloat(cantidadInput.value) || 0;
            let precioUnitarioVentaIVAIncluido = 0;
            let ivaPorcentaje = 0;
            let litrosPorUnidad = 0;

            // Actualizar información de stock y establecer precio del producto seleccionado
            if (selectedProductId && productosMapJs[selectedProductId]) {
                const productData = productosMapJs[selectedProductId];
                precioUnitarioVentaIVAIncluido = parseFloat(productData.precio_venta);
                ivaPorcentaje = parseFloat(productData.porcentaje_iva);
                litrosPorUnidad = parseFloat(productData.litros_por_unidad);
                
                precioUnitarioInput.value = precioUnitarioVentaIVAIncluido.toFixed(2); // Establecer precio de solo lectura

                const stock = parseFloat(productData.stock_actual_unidades);
                stockProductoInfo.textContent = `Stock actual: ${stock} unidades`;

                // Validar unidades retiradas
                if (unidadesRetiradasInput.value > stock) {
                    unidadesRetiradasInput.value = stock;
                    showCustomAlert('Las unidades retiradas no pueden ser mayores que el stock disponible.', 'warning');
                }
                if (unidadesRetiradasInput.value > cantidad) {
                    unidadesRetiradasInput.value = cantidad;
                    showCustomAlert('Las unidades retiradas no pueden ser mayores que la cantidad solicitada.', 'warning');
                }

            } else {
                stockProductoInfo.textContent = '';
                precioUnitarioInput.value = '0.00';
            }

            // Calcular base imponible de la línea a partir del precio unitario con IVA incluido
            let precioUnitarioBaseImponible = precioUnitarioVentaIVAIncluido / (1 + (ivaPorcentaje / 100));

            let subtotalBaseLinea = cantidad * precioUnitarioBaseImponible;
            let subtotalIvaLinea = subtotalBaseLinea * (ivaPorcentaje / 100); 
            let subtotalTotalLinea = subtotalBaseLinea + subtotalIvaLinea;
            let totalLitrosLinea = cantidad * litrosPorUnidad;

            lineaBaseDisplay.textContent = subtotalBaseLinea.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            lineaIvaDisplay.textContent = subtotalIvaLinea.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            lineaTotalDisplay.textContent = subtotalTotalLinea.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            lineaLitrosDisplay.textContent = totalLitrosLinea.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }


        // NUEVA FUNCIÓN: Redirigir a clientes.php para crear un nuevo cliente desde el modal de factura
        function createNewClientFromInvoiceModal() {
            const clientName = document.getElementById('client_search_input').value.trim();
            let url = 'clientes.php?action=new';
            if (clientName) {
                url += '&new_client_name=' + encodeURIComponent(clientName);
            }
            window.location.href = url;
        }

        // Función para mostrar un mensaje personalizado (reemplazo de alert)
        function showCustomAlert(message, type = 'info') {
            const alertContainer = document.querySelector('.container-fluid');
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            // Insertar la alerta al principio del contenedor
            alertContainer.insertAdjacentHTML('afterbegin', alertHtml);
            // Opcional: Cerrar la alerta automáticamente después de unos segundos
            setTimeout(() => {
                const newAlert = alertContainer.querySelector('.alert');
                if (newAlert) {
                    const bsAlert = bootstrap.Alert.getInstance(newAlert) || new bootstrap.Alert(newAlert);
                    bsAlert.close();
                }
            }, 5000); // 5 segundos
        }


        // Wrap the entire script in an IIFE to prevent global variable conflicts
        (function() {
            let clientSearchTimeout;

            // Common function to load shipping addresses for a client
            async function loadShippingAddressesForSelect(clientId, selectElement, selectedAddressId = null) {
                selectElement.innerHTML = '<option value="">Cargando direcciones...</option>';
                if (!clientId) {
                    selectElement.innerHTML = '<option value="">Seleccione un cliente primero</option>';
                    return;
                }

                try {
                    const response = await fetch(`facturas_ventas.php?accion=get_shipping_addresses_by_client&id_cliente=${clientId}`);
                    const data = await response.json();

                    selectElement.innerHTML = ''; // Clear existing options
                    
                    if (data.success && data.direcciones.length > 0) {
                        data.direcciones.forEach(dir => {
                            const option = document.createElement('option');
                            option.value = dir.id_direccion_envio;
                            let fullAddress = `${dir.direccion}`;
                            if (dir.ciudad) fullAddress += `, ${dir.ciudad}`;
                            if (dir.provincia) fullAddress += `, ${dir.provincia}`;
                            if (dir.codigo_postal) fullAddress += ` ${dir.codigo_postal}`;
                            option.textContent = `${dir.nombre_direccion} (${fullAddress})`;
                            if (selectedAddressId !== null && dir.id_direccion_envio == selectedAddressId) {
                                option.selected = true;
                            }
                            selectElement.appendChild(option);
                        });
                    } else {
                        // If no specific shipping addresses, add the main client address as the only option
                        const clientInfo = clientesMapJs[clientId];
                        if (clientInfo && clientInfo.direccion) {
                            const option = document.createElement('option');
                            option.value = 'main_address'; // Special value for main client address
                            let fullAddress = `${clientInfo.direccion}`;
                            if (clientInfo.ciudad) fullAddress += `, ${clientInfo.ciudad}`;
                            if (clientInfo.provincia) fullAddress += `, ${clientInfo.provincia}`;
                            if (clientInfo.codigo_postal) fullAddress += ` ${clientInfo.codigo_postal}`;
                            option.textContent = `Dirección Principal del Cliente (${fullAddress})`;
                            option.selected = true; // Select it by default
                            selectElement.appendChild(option);
                        } else {
                            selectElement.innerHTML = '<option value="">No hay direcciones de envío disponibles</option>';
                        }
                    }
                } catch (error) {
                    console.error('Error loading shipping addresses:', error);
                    selectElement.innerHTML = '<option value="">Error al cargar direcciones</option>';
                }
            }


            // Consolidated DOMContentLoaded listener
            document.addEventListener('DOMContentLoaded', () => {
                // Client Search elements (New Invoice Modal)
                const clientSearchInput = document.getElementById('client_search_input');
                const idClienteSelectedInput = document.getElementById('id_cliente_selected');
                const clientSearchResultsDiv = document.getElementById('client_search_results');
                const clientSelectionError = document.getElementById('client_selection_error');
                const facturaForm = document.getElementById('facturaForm'); // Get the form for new invoice
                const newInvoiceShippingAddressSelect = document.getElementById('id_direccion_envio'); // NEW

                // Client Search elements (Edit Invoice Modal)
                const editClientSearchInput = document.getElementById('edit_client_search_input');
                const editIdClienteSelectedInput = document.getElementById('edit_id_cliente_selected');
                const editClientSearchResultsDiv = document.getElementById('edit_client_search_results');
                const editClientSelectionError = document.getElementById('edit_client_selection_error');
                const editFacturaForm = document.getElementById('editFacturaForm'); // Get the form for edit invoice
                const editInvoiceShippingAddressSelect = document.getElementById('edit_id_direccion_envio'); // NEW


                // Add Linea Modal elements
                const addLineaModalElement = document.getElementById('addLineaModal');
                if (addLineaModalElement) {
                    addLineaModalElement.addEventListener('shown.bs.modal', () => {
                        // Reset fields when modal is shown
                        document.getElementById('id_producto').value = '';
                        document.getElementById('cantidad').value = '';
                        document.getElementById('unidades_retiradas').value = '0'; // Reset units withdrawn
                        document.getElementById('precio_unitario_venta').value = '0.00';
                        document.getElementById('stockProductoInfo').textContent = '';
                        // Reiniciar los displays de totales de línea
                        document.getElementById('linea_base_display').textContent = '0.00 €';
                        document.getElementById('linea_iva_display').textContent = '0.00 €';
                        document.getElementById('linea_total_display').textContent = '0.00 €';
                        document.getElementById('linea_litros_display').textContent = '0.00';
                        updatePrecioUnitario(); // Call to reset displayed totals and stock info
                    });
                }

                // If a client ID was passed to pre-fill the new invoice modal
                const urlParams = new URLSearchParams(window.location.search);
                const newInvoiceClientId = urlParams.get('new_invoice_client_id');
                const newInvoiceClientName = urlParams.get('new_invoice_client_name');
                const newInvoiceOrderId = urlParams.get('new_invoice_order_id');
                const newInvoiceParteRutaId = urlParams.get('new_invoice_parte_ruta_id');

                if (newInvoiceClientId && newInvoiceClientName) {
                    if (clientesMapJs[newInvoiceClientId]) {
                        const clientInfo = clientesMapJs[newInvoiceClientId];
                        const clientDisplayValue = `${decodeURIComponent(newInvoiceClientName)} (${clientInfo.nif || 'N/A'})`;
                        
                        if (clientSearchInput) {
                            clientSearchInput.value = clientDisplayValue;
                        }
                        if (idClienteSelectedInput) {
                            idClienteSelectedInput.value = newInvoiceClientId;
                            // Load shipping addresses for the pre-selected client
                            loadShippingAddressesForSelect(newInvoiceClientId, newInvoiceShippingAddressSelect);
                        }
                        if (document.getElementById('new_id_pedido_asociado')) {
                            document.getElementById('new_id_pedido_asociado').value = newInvoiceOrderId || '';
                        }
                        if (document.getElementById('new_id_parte_ruta_asociada')) {
                            document.getElementById('new_id_parte_ruta_asociada').value = newInvoiceParteRutaId || '';
                        }

                        // Open the modal if a client is pre-selected
                        const addFacturaModal = new bootstrap.Modal(document.getElementById('addFacturaModal'));
                        addFacturaModal.show();
                    }
                } else if (newInvoiceShippingAddressSelect) {
                    // If no client pre-selected, but the select exists, load default message
                    newInvoiceShippingAddressSelect.innerHTML = '<option value="">Seleccione un cliente primero</option>';
                }


                // Add focus return for modals
                const addFacturaModalElement = document.getElementById('addFacturaModal');
                if (addFacturaModalElement) {
                    addFacturaModalElement.addEventListener('hidden.bs.modal', () => {
                        const newFacturaBtn = document.querySelector('[data-bs-target="#addFacturaModal"]');
                        if (newFacturaBtn) {
                            newFacturaBtn.focus();
                        }
                    });
                }

                const editFacturaModalElement = document.getElementById('editFacturaModal');
                if (editFacturaModalElement) {
                    editFacturaModalElement.addEventListener('shown.bs.modal', () => {
                        // When edit modal is shown, load shipping addresses for the current client
                        const currentClientId = editIdClienteSelectedInput.value;
                        const currentShippingAddressId = <?php echo json_encode($factura_actual['id_direccion_envio'] ?? null); ?>;
                        loadShippingAddressesForSelect(currentClientId, editInvoiceShippingAddressSelect, currentShippingAddressId);
                    });

                    editFacturaModalElement.addEventListener('hidden.bs.modal', () => {
                        const editButton = document.querySelector('.btn-warning[data-bs-target="#editFacturaModal"]');
                        if (editButton) {
                            editButton.focus();
                        } else {
                            const newFacturaBtn = document.querySelector('[data-bs-target="#addFacturaModal"]');
                            if (newFacturaBtn) {
                                newFacturaBtn.focus();
                            }
                        }
                    });
                }


                // --- Client Search Functionality for NEW INVOICE MODAL ---
                if (clientSearchInput) {
                    clientSearchInput.addEventListener('input', function() {
                        const searchTerm = this.value.trim();
                        idClienteSelectedInput.value = ''; // Clear selected client ID on new search
                        clientSelectionError.style.display = 'none'; // Hide error message
                        newInvoiceShippingAddressSelect.innerHTML = '<option value="">Seleccione un cliente primero</option>'; // Clear shipping addresses

                        clearTimeout(clientSearchTimeout);
                        if (searchTerm.length > 1) { // Start search after 2 characters
                            clientSearchTimeout = setTimeout(async () => {
                                try {
                                    const formData = new FormData();
                                    formData.append('accion', 'search_clients');
                                    formData.append('search_term', searchTerm);

                                    const response = await fetch('facturas_ventas.php', {
                                        method: 'POST',
                                        body: formData
                                    });
                                    const clients = await response.json();

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
                                                loadShippingAddressesForSelect(this.dataset.clientId, newInvoiceShippingAddressSelect); // Load addresses
                                            });
                                            clientSearchResultsDiv.appendChild(item);
                                        });
                                    } else {
                                        clientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-muted">No se encontraron clientes.</div>';
                                    }
                                } catch (error) {
                                    console.error('Error searching clients (New Invoice):', error);
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

                    // Validate client selection before submitting the form
                    if (facturaForm) {
                        facturaForm.addEventListener('submit', function(event) {
                            if (!idClienteSelectedInput.value) {
                                event.preventDefault(); // Prevent form submission
                                clientSelectionError.style.display = 'block'; // Show error message
                            } else {
                                clientSelectionError.style.display = 'none'; // Hide error message
                            }
                        });
                    }
                }

                // --- Client Search Functionality for EDIT INVOICE MODAL ---
                if (editClientSearchInput) {
                    editClientSearchInput.addEventListener('input', function() {
                        const searchTerm = this.value.trim();
                        if (editClientSelectionError) editClientSelectionError.style.display = 'none';
                        editInvoiceShippingAddressSelect.innerHTML = '<option value="">Cargando direcciones...</option>'; // Clear shipping addresses

                        clearTimeout(clientSearchTimeout);
                        if (searchTerm.length > 1) {
                            clientSearchTimeout = setTimeout(async () => {
                                try {
                                    const formData = new FormData();
                                    formData.append('accion', 'search_clients');
                                    formData.append('search_term', searchTerm);

                                    const response = await fetch('facturas_ventas.php', {
                                        method: 'POST',
                                        body: formData
                                    });
                                    const clients = await response.json();

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
                                                loadShippingAddressesForSelect(this.dataset.clientId, editInvoiceShippingAddressSelect); // Load addresses
                                            });
                                            if (editClientSearchResultsDiv) editClientSearchResultsDiv.appendChild(item);
                                        });
                                    } else {
                                        if (editClientSearchResultsDiv) editClientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-muted">No se encontraron clientes.</div>';
                                    }
                                } catch (error) {
                                    console.error('Error searching clients (Edit Invoice):', error);
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

                    // Validate client selection before submitting the edit form
                    if (editFacturaForm) {
                        editFacturaForm.addEventListener('submit', function(event) {
                            if (editIdClienteSelectedInput && !editIdClienteSelectedInput.value) {
                                event.preventDefault();
                                if (editClientSelectionError) editClientSelectionError.style.display = 'block';
                            } else {
                                if (editClientSelectionError) editClientSelectionError.style.display = 'none';
                            }
                        });
                    }
                }
            });
        })(); // End of IIFE
    </script>
</body>
</html>
