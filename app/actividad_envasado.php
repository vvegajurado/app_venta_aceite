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

// --- Cargar datos de apoyo (Lotes de Envasado, Productos Finales) ---
$lotes_envasado_disponibles = [];
$productos_finales_disponibles = [];
$productos_map = []; // Mapa para acceder fácilmente a los detalles del producto (litros por unidad, id_articulo)
$lotes_envasado_map = []; // Mapa para acceder fácilmente a los litros_preparados, id_articulo, stock_deposito de un lote

try {
    // Obtener lotes de envasado disponibles
    // Se incluye el id_articulo y stock_actual del depósito de mezcla
    $stmt_lotes = $pdo->query("SELECT
                                le.id_lote_envasado,
                                le.nombre_lote,
                                le.litros_preparados,
                                le.id_articulo,
                                a.nombre_articulo,
                                d.nombre_deposito AS nombre_deposito_mezcla,
                                d.stock_actual AS stock_deposito_mezcla
                                FROM lotes_envasado le
                                JOIN articulos a ON le.id_articulo = a.id_articulo
                                JOIN depositos d ON le.id_deposito_mezcla = d.id_deposito
                                WHERE d.stock_actual > 0 -- Solo lotes con stock en su depósito de mezcla
                                ORDER BY le.fecha_creacion DESC");
    $lotes_envasado_disponibles = $stmt_lotes->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lotes_envasado_disponibles as $lote) {
        $lotes_envasado_map[$lote['id_lote_envasado']] = [
            'nombre_lote' => $lote['nombre_lote'],
            'litros_preparados' => $lote['litros_preparados'],
            'id_articulo' => $lote['id_articulo'],
            'nombre_articulo' => $lote['nombre_articulo'],
            'nombre_deposito_mezcla' => $lote['nombre_deposito_mezcla'],
            'stock_deposito_mezcla' => $lote['stock_deposito_mezcla']
        ];
    }

    // Obtener productos finales disponibles (de la tabla `productos`)
    $stmt_productos = $pdo->query("SELECT
                                    p.id_producto,
                                    p.nombre_producto,
                                    p.litros_por_unidad,
                                    p.id_articulo,
                                    a.nombre_articulo
                                    FROM productos p
                                    JOIN articulos a ON p.id_articulo = a.id_articulo
                                    ORDER BY p.nombre_producto ASC");
    $productos_finales_disponibles = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);
    foreach ($productos_finales_disponibles as $producto) {
        $productos_map[$producto['id_producto']] = [
            'nombre_producto' => $producto['nombre_producto'],
            'litros_por_unidad' => $producto['litros_por_unidad'],
            'id_articulo' => $producto['id_articulo']
        ];
    }

} catch (PDOException $e) {
    $mensaje = "Error al cargar datos de apoyo: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// --- Lógica para PROCESAR el formulario de Actividad de Envasado ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'registrar_envasado') {
    $fecha_envasado = $_POST['fecha_envasado'] ?? date('Y-m-d');

    // Datos de los productos envasados (ahora cada línea tiene su id_lote_envasado)
    $ids_lote_envasado_detalle = $_POST['id_lote_envasado_detalle'] ?? [];
    $ids_producto = $_POST['id_producto'] ?? [];
    $unidades_envasadas_post = $_POST['unidades_envasadas'] ?? [];
    // 'consecutivo_sublote' ya no se recibe del formulario, id_detalle_actividad es autogenerado
    $observaciones_detalle = $_POST['observaciones_detalle'] ?? [];

    // Filter out rows where loteId or productId is empty or quantity is zero
    $filtered_details = [];
    foreach ($ids_producto as $index => $id_prod) {
        $id_lote = $ids_lote_envasado_detalle[$index] ?? null;
        $cantidad = (int)($unidades_envasadas_post[$index] ?? 0);
        $litros_envasados_hidden = (float)($_POST['litros_envasados_hidden'][$index] ?? 0); // Get calculated litres from JS

        if (!empty($id_lote) && !empty($id_prod) && $cantidad > 0 && $litros_envasados_hidden > 0) {
            $filtered_details[] = [
                'id_lote_envasado' => $id_lote,
                'id_producto' => $id_prod,
                'unidades_envasadas' => $cantidad,
                'litros_envasados' => $litros_envasados_hidden, // Use the calculated litres from JS
                // 'consecutivo_sublote' ya no se usa aquí
                'observaciones_detalle' => $observaciones_detalle[$index] ?? ''
            ];
        }
    }

    if (empty($filtered_details)) {
        $mensaje = "Error: Debe añadir al menos un producto válido a envasar.";
        $tipo_mensaje = 'danger';
    } else {
        try {
            $pdo->beginTransaction(); // Iniciar transacción

            // Arrays para acumular litros por lote y validar stocks
            $litros_envasados_por_lote = [];
            $lotes_impactados_info = []; // Para almacenar info del depósito de mezcla y stock inicial de cada lote impactado

            // 1. Validar y calcular litros a envasar por cada lote
            foreach ($filtered_details as $detail) {
                $id_lote = $detail['id_lote_envasado'];
                $id_prod = $detail['id_producto'];
                $litros_detalle = $detail['litros_envasados']; // Using the value from the hidden input (calculated by JS)

                $lote_info_detalle = $lotes_envasado_map[$id_lote] ?? null;
                $producto_info_detalle = $productos_map[$id_prod] ?? null;

                if (!$lote_info_detalle) {
                    throw new Exception("Lote de envasado con ID {$id_lote} no encontrado o inválido.");
                }
                if (!$producto_info_detalle) {
                    throw new Exception("Producto final con ID {$id_prod} no encontrado.");
                }

                // Validar que el artículo del producto coincide con el artículo del lote (CRÍTICO)
                if ((int)$producto_info_detalle['id_articulo'] !== (int)$lote_info_detalle['id_articulo']) {
                    $nombre_articulo_lote = $lote_info_detalle['nombre_articulo'] ?? 'Desconocido';
                    $nombre_producto_actual = $producto_info_detalle['nombre_producto'];
                    throw new Exception("Error: El producto '{$nombre_producto_actual}' no corresponde al artículo del lote ('{$nombre_articulo_lote}' - Lote: {$lote_info_detalle['nombre_lote']}).");
                }

                // Acumular litros por cada lote de envasado
                if (!isset($litros_envasados_por_lote[$id_lote])) {
                    $litros_envasados_por_lote[$id_lote] = 0;
                    // Obtener y almacenar info del depósito por primera vez que encontramos este lote
                    $stmt_deposito_lote = $pdo->prepare("SELECT id_deposito, stock_actual, nombre_deposito FROM depositos WHERE id_deposito = (SELECT id_deposito_mezcla FROM lotes_envasado WHERE id_lote_envasado = ?)");
                    $stmt_deposito_lote->execute([$id_lote]);
                    $deposito_info = $stmt_deposito_lote->fetch(PDO::FETCH_ASSOC);

                    if (!$deposito_info) {
                        throw new Exception("Depósito de mezcla para lote {$lote_info_detalle['nombre_lote']} no encontrado.");
                    }
                    $lotes_impactados_info[$id_lote] = [
                        'id_deposito_mezcla' => $deposito_info['id_deposito'],
                        'stock_actual_deposito' => $deposito_info['stock_actual'],
                        'nombre_deposito_mezcla' => $deposito_info['nombre_deposito']
                    ];
                }
                $litros_envasados_por_lote[$id_lote] += $litros_detalle;
            }

            // Validar que la cantidad total a envasar no excede el stock de CADA depósito de mezcla afectado
            foreach ($litros_envasados_por_lote as $id_lote => $total_litros_para_este_lote) {
                $deposito_stock_actual = $lotes_impactados_info[$id_lote]['stock_actual_deposito'];
                $nombre_deposito = $lotes_impactados_info[$id_lote]['nombre_deposito_mezcla'];

                if ($total_litros_para_este_lote > $deposito_stock_actual) {
                    throw new Exception("Error: Los litros totales a envasar del lote '" . $lotes_envasado_map[$id_lote]['nombre_lote'] . "' (" . number_format($total_litros_para_este_lote, 2, ',', '.') . " L) exceden el stock actual de su depósito de mezcla ('{$nombre_deposito}', Stock: " . number_format($deposito_stock_actual, 2, ',', '.') . " L).");
                }
            }

            // 2. Insertar la actividad de envasado principal (una por día de actividad)
            $stmt_actividad = $pdo->prepare("INSERT INTO actividad_envasado (fecha_envasado) VALUES (?)");
            $stmt_actividad->execute([$fecha_envasado]);
            $id_actividad_envasado = $pdo->lastInsertId();

            // 3. Insertar los detalles de la actividad de envasado y actualizar stock de productos finales
            foreach ($filtered_details as $detail) {
                // Se inserta 'unidades_envasadas' en lugar de 'cantidad_envases'
                // 'consecutivo_sublote' se elimina de la inserción ya que 'id_detalle_actividad' es autoincremental
                $stmt_detalle = $pdo->prepare("INSERT INTO detalle_actividad_envasado (id_actividad_envasado, id_lote_envasado, id_producto, unidades_envasadas, litros_envasados, observaciones) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_detalle->execute([
                    $id_actividad_envasado,
                    $detail['id_lote_envasado'],
                    $detail['id_producto'],
                    $detail['unidades_envasadas'],
                    $detail['litros_envasados'],
                    $detail['observaciones_detalle']
                ]);

                // Actualizar el stock de unidades del producto final en la tabla `productos`
                $stmt_update_producto_stock = $pdo->prepare("UPDATE productos SET stock_actual_unidades = stock_actual_unidades + ? WHERE id_producto = ?");
                $stmt_update_producto_stock->execute([$detail['unidades_envasadas'], $detail['id_producto']]);
            }

            // 4. Actualizar el stock de CADA depósito de mezcla impactado
            foreach ($litros_envasados_por_lote as $id_lote_actualizar => $litros_extraidos_de_lote) {
                $deposito_info_actualizar = $lotes_impactados_info[$id_lote_actualizar];
                $nuevo_stock_deposito_mezcla = $deposito_info_actualizar['stock_actual_deposito'] - $litros_extraidos_de_lote;
                $estado_deposito_mezcla = ($nuevo_stock_deposito_mezcla == 0) ? 'vacio' : 'ocupado';

                $id_mp_deposito = NULL;
                $id_lote_eg_deposito = NULL;

                if ($nuevo_stock_deposito_mezcla > 0) {
                    $stmt_current_deposito_info = $pdo->prepare("SELECT id_materia_prima, id_entrada_granel_origen FROM depositos WHERE id_deposito = ?");
                    $stmt_current_deposito_info->execute([$deposito_info_actualizar['id_deposito_mezcla']]);
                    $current_deposito_mp_eg = $stmt_current_deposito_info->fetch(PDO::FETCH_ASSOC);
                    
                    $id_mp_deposito = $current_deposito_mp_eg['id_materia_prima'];
                    $id_lote_eg_deposito = $current_deposito_mp_eg['id_entrada_granel_origen'];
                }

                $stmt_update_deposito = $pdo->prepare("UPDATE depositos SET stock_actual = ?, estado = ?, id_materia_prima = ?, id_entrada_granel_origen = ? WHERE id_deposito = ?");
                $stmt_update_deposito->execute([$nuevo_stock_deposito_mezcla, $estado_deposito_mezcla, $id_mp_deposito, $id_lote_eg_deposito, $deposito_info_actualizar['id_deposito_mezcla']]);
            }

            $pdo->commit(); // Confirmar la transacción
            $mensaje = "Actividad de envasado registrada correctamente. Stock de productos y depósitos actualizados.";
            $tipo_mensaje = 'success';
            header("Location: actividad_envasado.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "Error al procesar la actividad de envasado: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        } catch (PDOException $e) {
            $pdo->rollBack();
            $mensaje = "Error de base de datos al procesar la actividad de envasado: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// Cargar mensajes de la redirección
if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $tipo_mensaje = $_GET['tipo_mensaje'];
}

// Lógica para listar Actividades de Envasado y calcular Totales
$actividades_envasado_registradas = [];
$total_envases_general = 0;
$total_litros_envasados_general = 0;

try {
    // Las actividades se agrupan por fecha. Los detalles (lotes, productos) se cargarán después.
    $sql_actividades = "SELECT
                            ae.id_actividad_envasado,
                            ae.fecha_envasado
                        FROM
                            actividad_envasado ae
                        ORDER BY
                            ae.fecha_envasado DESC, ae.id_actividad_envasado DESC";
    $stmt_actividades = $pdo->query($sql_actividades);
    $actividades_envasado_registradas = $stmt_actividades->fetchAll(PDO::FETCH_ASSOC);

    // Para cada actividad, obtener los detalles de los productos envasados y los lotes asociados
    foreach ($actividades_envasado_registradas as $key => $actividad) {
        $stmt_detalles_productos = $pdo->prepare("SELECT
                                                        dae.id_detalle_actividad,
                                                        dae.id_lote_envasado,
                                                        le.nombre_lote,
                                                        le.litros_preparados AS litros_lote_origen,
                                                        a.nombre_articulo,
                                                        p.nombre_producto,
                                                        dae.unidades_envasadas,
                                                        dae.litros_envasados,
                                                        dae.observaciones
                                                    FROM
                                                        detalle_actividad_envasado dae
                                                    JOIN
                                                        productos p ON dae.id_producto = p.id_producto
                                                    JOIN
                                                        lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
                                                    JOIN
                                                        articulos a ON le.id_articulo = a.id_articulo
                                                    WHERE
                                                        dae.id_actividad_envasado = ?
                                                    ORDER BY
                                                        dae.id_detalle_actividad ASC");
        $stmt_detalles_productos->execute([$actividad['id_actividad_envasado']]);
        $actividades_envasado_registradas[$key]['productos_envasados'] = $stmt_detalles_productos->fetchAll(PDO::FETCH_ASSOC);
        
        // Sumar a los totales generales
        $sub_total_envases_actividad = 0;
        $sub_total_litros_actividad = 0;
        foreach ($actividades_envasado_registradas[$key]['productos_envasados'] as $prod_detalle) {
            $sub_total_envases_actividad += $prod_detalle['unidades_envasadas'];
            $sub_total_litros_actividad += $prod_detalle['litros_envasados'];
        }
        $actividades_envasado_registradas[$key]['total_envases_actividad'] = $sub_total_envases_actividad;
        $actividades_envasado_registradas[$key]['total_litros_actividad'] = $sub_total_litros_actividad;

        $total_envases_general += $sub_total_envases_actividad;
        $total_litros_envasados_general += $sub_total_litros_actividad;
    }

} catch (PDOException $e) {
    $mensaje_listado = "Error al cargar el listado de actividades de envasado: " . $e->getMessage();
    $tipo_mensaje_listado = 'danger';
}

// Cierra la conexión PDO al final del script
$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actividad de Envasado</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- jsPDF y autoTable -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <style>
        /* Estilos generales (copia de facturas_ventas.php y trazabilidad.php) */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
        }
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        .main-content {
            flex-grow: 1;
            padding: 20px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #0056b3; /* Color primario */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #0056b3;
            border-color: #0056b3;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background-color: #004494;
            border-color: #004494;
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
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #333;
            border-radius: 8px;
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #d39e00;
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            border-radius: 8px;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #545b62;
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
            background-color: #0056b3; /* Color primario */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .alert {
            border-radius: 8px;
        }

        /* Estilos del Sidebar (copia de sidebar.php y facturas_ventas.php) */
        :root {
            --sidebar-width: 250px;
            --primary-color: #0056b3; /* Actualizado para coincidir con facturas_ventas.php */
            --sidebar-bg: #343a40; /* Fondo oscuro */
            --sidebar-link: #adb5bd; /* Gris claro para enlaces */
            --sidebar-hover: #495057; /* Gris un poco más oscuro al pasar el ratón */
            --sidebar-active: #004494; /* Azul más oscuro para el elemento activo */
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            flex-shrink: 0; /* Evita que el sidebar se encoja */
            height: 100vh; /* Make sidebar take full viewport height */
            position: sticky; /* Keep sidebar fixed when scrolling */
            top: 0; /* Align to the top */
            overflow-y: auto; /* Enable scrolling if content overflows */
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

        /* Estilos específicos de Actividad de Envasado */
        .header {
            background-color: #ffffff; /* White background for header */
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: #0056b3; /* Usar el color primario del tema */
            font-size: 1.8rem;
            margin: 0;
        }

        .table thead th {
            background-color: #e9ecef; /* Color de cabecera de tabla de facturas_ventas.php */
            color: #333; /* Texto oscuro para contraste */
            border-color: #dee2e6;
        }

        .product-detail-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
            padding: 15px;
            border-radius: 8px;
            background-color: #fcfdfe;
            box-shadow: 0 1px 5px rgba(0,0,0,0.05);
        }
        .product-detail-row .col {
            padding-right: 15px;
        }
        .product-detail-row .col:last-child {
             padding-right: 0;
        }

        .product-detail-row:first-child .remove-product-row {
            display: none;
        }

        .lote-stock-info {
            font-size: 0.85em;
            color: #555;
            margin-top: 5px;
        }

        .alert-heading {
            color: #0056b3;
        }

        /* Estilos para la tabla anidada de detalles */
        .nested-table {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .nested-table th, .nested-table td {
            border: 1px solid #e9ecef;
            padding: 8px;
            text-align: left;
        }
        .nested-table thead th {
            background-color: #f8f9fa;
            color: #495057;
            font-weight: 600;
        }


        /* Media Queries para responsividad */
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                left: -var(--sidebar-width);
            }
            .sidebar.expanded {
                width: var(--sidebar-width);
                left: 0;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 15px;
            }
            .sidebar.expanded + .main-content {
                margin-left: var(--sidebar-width);
            }
            .container, .card, .form-section-card, .table-section-card {
                padding: 20px;
                max-width: 100%;
            }
            .product-detail-row .col {
                padding-right: 0;
                width: 100%;
                margin-bottom: 15px;
            }
            .product-detail-row .col:last-child {
                margin-bottom: 0;
            }
             .product-detail-row .d-flex.align-items-end.mb-3 {
                margin-bottom: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="container">
                <div class="header">
                    <h1>Actividad de Envasado</h1>
                    <!-- Removed the welcome message and logout button here -->
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($mensaje_listado) && $mensaje_listado): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje_listado; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje_listado; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Formulario de Actividad de Envasado -->
                <div class="card form-section-card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Registrar Nueva Actividad de Envasado</h5>
                    </div>
                    <div class="card-body">
                        <form action="actividad_envasado.php" method="POST">
                            <input type="hidden" name="accion" value="registrar_envasado">

                            <div class="row mb-4">
                                <div class="col-md-6 offset-md-3">
                                    <label for="fecha_envasado" class="form-label form-label-required">Fecha de Envasado</label>
                                    <input type="date" class="form-control" id="fecha_envasado" name="fecha_envasado" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>

                            <hr>
                            <h4>Productos Envasados <small class="text-muted">(Añade cada producto con su lote de origen)</small></h4>
                            <div id="productos-envasados-container">
                                <!-- Una fila de producto por defecto -->
                                <div class="product-detail-row row align-items-end mb-3" data-row-index="0">
                                    <div class="col-md-4 mb-3">
                                        <label for="id_lote_envasado_detalle_0" class="form-label form-label-required">Lote de Envasado Origen</label>
                                        <select class="form-select lote-envasado-detalle-select" id="id_lote_envasado_detalle_0" name="id_lote_envasado_detalle[]" required>
                                            <option value="">Seleccione un Lote</option>
                                            <?php foreach ($lotes_envasado_disponibles as $lote): ?>
                                                <option value="<?php echo htmlspecialchars($lote['id_lote_envasado']); ?>"
                                                        data-articulo-id="<?php echo htmlspecialchars($lote['id_articulo']); ?>"
                                                        data-litros-disponibles="<?php echo htmlspecialchars($lote['stock_deposito_mezcla']); ?>">
                                                    <?php echo htmlspecialchars($lote['nombre_lote'] . " (Artículo: " . $lote['nombre_articulo'] . ")"); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="lote-stock-info" id="lote-stock-info-0"></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="id_producto_0" class="form-label form-label-required">Producto Final</label>
                                        <select class="form-select producto-final-detalle-select" id="id_producto_0" name="id_producto[]" required>
                                            <option value="">Seleccione un Producto</option>
                                            <!-- Opciones cargadas por JavaScript según el Lote seleccionado en esta fila -->
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label for="unidades_envasadas_0" class="form-label form-label-required">Cantidad de Envases</label>
                                        <input type="number" class="form-control cantidad-envases-input" id="unidades_envasadas_0" name="unidades_envasadas[]" min="1" required>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label for="litros_envasados_0" class="form-label">Litros Envasados</label>
                                        <input type="hidden" class="calculated-litros-input" name="litros_envasados_hidden[]" value="0.00">
                                        <span class="form-control-plaintext text-muted calculated-liters-display">0.00 L</span>
                                    </div>
                                    <!-- Eliminado el campo Sublote del formulario de entrada -->
                                    <div class="col-md-3 mb-3">
                                        <label for="observaciones_detalle_0" class="form-label">Obs. Detalle</label>
                                        <textarea class="form-control" id="observaciones_detalle_0" name="observaciones_detalle[]" rows="1"></textarea>
                                    </div>
                                    <div class="col-md-1 d-flex align-items-end mb-3">
                                        <button type="button" class="btn btn-danger remove-product-row w-100"><i class="bi bi-x-circle"></i></button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary mt-2" id="add-product-row"><i class="bi bi-plus-circle"></i> Añadir otro producto</button>

                            <div class="alert alert-info mt-3" role="alert">
                                <h5 class="alert-heading">Resumen de Litros por Lote:</h5>
                                <ul id="litros-por-lote-summary">
                                    <!-- Se llenará con JS -->
                                </ul>
                                <strong>Total Litros Envasados (suma general): <span id="total-litros-envasados-general">0.00</span> L</strong>
                            </div>
                            
                            <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-circle"></i> Registrar Envasado</button>
                        </form>
                    </div>
                </div>
                
                <h3 class="mt-5 mb-3 text-center">Historial de Actividades de Envasado</h3>
                <div class="card table-section-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Actividades Recientes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($actividades_envasado_registradas)): ?>
                            <p class="text-center">No hay actividades de envasado registradas todavía.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle" id="envasado-history-table">
                                    <thead>
                                        <tr>
                                            <th>ID Actividad</th>
                                            <th>Fecha Envasado</th>
                                            <th>Detalle de Productos Envasados</th>
                                            <th>Total Envases (Actividad)</th>
                                            <th>Total Litros Envasados (Actividad)</th>
                                            <th>Acciones</th> <!-- Nueva columna para el botón PDF -->
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($actividades_envasado_registradas as $actividad): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($actividad['id_actividad_envasado']); ?></td>
                                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($actividad['fecha_envasado']))); ?></td>
                                                <td>
                                                    <?php if (!empty($actividad['productos_envasados'])): ?>
                                                        <table class="table table-sm nested-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>ID Detalle</th>
                                                                    <th>Lote Origen</th>
                                                                    <th>Producto</th>
                                                                    <th>Unidades</th>
                                                                    <th>Litros</th>
                                                                    <th>Obs.</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($actividad['productos_envasados'] as $prod_detalle): ?>
                                                                    <tr>
                                                                        <td><?php echo htmlspecialchars($prod_detalle['id_detalle_actividad']); ?></td>
                                                                        <td><?php echo htmlspecialchars($prod_detalle['nombre_lote']); ?> (Art: <?php echo htmlspecialchars($prod_detalle['nombre_articulo']); ?>)</td>
                                                                        <td><?php echo htmlspecialchars($prod_detalle['nombre_producto']); ?></td>
                                                                        <td class="text-center"><?php echo number_format($prod_detalle['unidades_envasadas']); ?> ud.</td>
                                                                        <td class="text-end"><?php echo number_format($prod_detalle['litros_envasados'], 2, ',', '.'); ?> L</td>
                                                                        <td><?php echo !empty($prod_detalle['observaciones']) ? htmlspecialchars($prod_detalle['observaciones']) : "N/A"; ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <strong><?php echo number_format($actividad['total_envases_actividad']); ?> ud.</strong>
                                                </td>
                                                <td class="text-end">
                                                    <strong><?php echo number_format($actividad['total_litros_actividad'], 2, ',', '.'); ?> L</strong>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info download-individual-pdf-btn"
                                                            data-activity-id="<?php echo htmlspecialchars($actividad['id_actividad_envasado']); ?>">
                                                        <i class="bi bi-file-earmark-pdf"></i> PDF
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3"><strong>Total General Envasado:</strong></td>
                                            <td class="text-center"><strong><?php echo number_format($total_envases_general); ?> ud.</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_litros_envasados_general, 2, ',', '.'); ?> L</strong></td>
                                            <td></td> <!-- Columna vacía para el pie de tabla -->
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-center mt-4">
                    <a href="index.php" class="btn btn-secondary"><i class="bi bi-house"></i> Volver al Inicio</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const productosEnvasadosContainer = document.getElementById('productos-envasados-container');
            const addProductRowBtn = document.getElementById('add-product-row');
            const litrosPorLoteSummary = document.getElementById('litros-por-lote-summary');
            const totalLitrosEnvasadosGeneralSpan = document.getElementById('total-litros-envasados-general');
            
            // Eliminar la referencia al botón global de PDF, ya no existe
            // const downloadPdfButton = document.getElementById('download-pdf-report');

            let productRowIndex = 0; // Para IDs únicos en filas dinámicas

            // Datos de productos y lotes pasados desde PHP
            const productosFinalesMapJs = <?php echo json_encode($productos_map); ?>;
            const lotesEnvasadoMapJs = <?php echo json_encode($lotes_envasado_map); ?>;
            // Pasar todas las actividades de envasado a JavaScript
            const actividadesEnvasadoData = <?php echo json_encode($actividades_envasado_registradas); ?>;


            // Función para actualizar los totales y el resumen por lote
            function updateTotals() {
                const litrosAcumuladosPorLote = {}; // {lote_id: total_litros_envasados}
                let totalLitrosGeneral = 0;

                document.querySelectorAll('.product-detail-row').forEach(row => {
                    const loteSelect = row.querySelector('.lote-envasado-detalle-select');
                    const productSelect = row.querySelector('.producto-final-detalle-select');
                    const cantidadInput = row.querySelector('.cantidad-envases-input');
                    
                    const loteId = loteSelect.value;
                    const productId = productSelect.value;
                    const cantidad = parseFloat(cantidadInput.value) || 0;
                    
                    const calculatedLitrosDisplay = row.querySelector('.calculated-liters-display');
                    const calculatedLitrosInput = row.querySelector('.calculated-litros-input');

                    let litrosCalculadosEnFila = 0;

                    if (loteId && productId && productosFinalesMapJs[productId]) {
                        // Crucial: check if the product's article ID matches the lot's article ID for this row
                        const loteArticuloId = lotesEnvasadoMapJs[loteId] ? parseInt(lotesEnvasadoMapJs[loteId].id_articulo) : null;
                        const productArticuloId = productosFinalesMapJs[productId] ? parseInt(productosFinalesMapJs[productId].id_articulo) : null;

                        if (loteArticuloId === productArticuloId) {
                            litrosCalculadosEnFila = cantidad * parseFloat(productosFinalesMapJs[productId].litros_por_unidad);
                            totalLitrosGeneral += litrosCalculadosEnFila;

                            if (!litrosAcumuladosPorLote[loteId]) {
                                litrosAcumuladosPorLote[loteId] = 0;
                            }
                            litrosAcumuladosPorLote[loteId] += litrosCalculadosEnFila;
                        } else {
                            litrosCalculadosEnFila = 0;
                        }
                    }
                    calculatedLitrosDisplay.textContent = litrosCalculadosEnFila.toFixed(2).replace('.', ',') + ' L';
                    calculatedLitrosInput.value = litrosCalculadosEnFila.toFixed(2);
                });

                // Actualizar el resumen por lote
                litrosPorLoteSummary.innerHTML = '';
                let isValidForSubmission = true; // Flag to enable/disable submit button

                if (Object.keys(litrosAcumuladosPorLote).length === 0) {
                     litrosPorLoteSummary.innerHTML = '<li>No hay productos seleccionados o cantidades válidas.</li>';
                     isValidForSubmission = false; // Cannot submit if no valid entries
                }

                for (const loteId in litrosAcumuladosPorLote) {
                    if (litrosAcumuladosPorLote.hasOwnProperty(loteId)) {
                        const totalLitrosEnvasarDeEsteLote = litrosAcumuladosPorLote[loteId];
                        const loteInfo = lotesEnvasadoMapJs[loteId];
                        const stockDisponible = loteInfo ? parseFloat(loteInfo.stock_deposito_mezcla) : 0;
                        const nombreLote = loteInfo ? loteInfo.nombre_lote : 'Desconocido';
                        const nombreDeposito = loteInfo ? loteInfo.nombre_deposito_mezcla : 'Desconocido';

                        const listItem = document.createElement('li');
                        let textColorClass = 'text-success'; // Por defecto verde
                        if (totalLitrosEnvasarDeEsteLote > stockDisponible) {
                            textColorClass = 'text-danger'; // Rojo si excede
                            isValidForSubmission = false; // Invalidate submission
                        } else if (totalLitrosEnvasarDeEsteLote === 0) {
                             textColorClass = 'text-muted'; // Gris si es 0
                        }

                        listItem.innerHTML = `<strong>Lote '${nombreLote}'</strong> (Depósito: ${nombreDeposito}): 
                                                <span class="${textColorClass}">${totalLitrosEnvasarDeEsteLote.toFixed(2).replace('.', ',')} L</span>
                                                / ${stockDisponible.toFixed(2).replace('.', ',')} L disponibles.`;
                        litrosPorLoteSummary.appendChild(listItem);
                    }
                }
                
                // Deshabilitar botón de enviar si la validación falla
                document.querySelector('button[type="submit"]').disabled = !isValidForSubmission;

                totalLitrosEnvasadosGeneralSpan.textContent = totalLitrosGeneral.toFixed(2).replace('.', ',');
            }

            // Función para poblar un select de productos finales basado en el artículo del lote seleccionado en esa misma fila
            function populateProductSelectOptions(productSelectElement, selectedArticuloId) {
                const currentSelectedValue = productSelectElement.value; // Guardar la selección actual
                productSelectElement.innerHTML = '<option value="">Seleccione un Producto</option>'; // Limpia las opciones existentes

                if (!selectedArticuloId || isNaN(parseInt(selectedArticuloId))) {
                    productSelectElement.value = '';
                    productSelectElement.dispatchEvent(new Event('change')); // Trigger change to update totals
                    return;
                }

                for (const id in productosFinalesMapJs) {
                    if (productosFinalesMapJs.hasOwnProperty(id)) {
                        const producto = productosFinalesMapJs[id];
                        // Compara el id_articulo del producto con el id_articulo del lote (asegurando que ambos son números)
                        if (parseInt(producto.id_articulo) === parseInt(selectedArticuloId)) {
                            const option = document.createElement('option');
                            option.value = id;
                            option.textContent = `${producto.nombre_producto} (${parseFloat(producto.litros_por_unidad).toFixed(2).replace('.', ',')} L/ud)`;
                            productSelectElement.appendChild(option);
                        }
                    }
                }

                // Restaurar la selección si es posible y si la opción aún existe
                if (currentSelectedValue && productSelectElement.querySelector(`option[value="${currentSelectedValue}"]`)) {
                    productSelectElement.value = currentSelectedValue;
                } else {
                     // Si la selección anterior no es válida o ya no existe (por cambio de lote/artículo), limpiar
                    productSelectElement.value = '';
                }
                // Siempre dispara el evento 'change' después de poblar/resetear para que se actualicen los totales
                productSelectElement.dispatchEvent(new Event('change'));
            }

            // Manejador de eventos para un row de producto/lote
            function setupProductRowEvents(rowElement) {
                const loteSelect = rowElement.querySelector('.lote-envasado-detalle-select');
                const productSelect = rowElement.querySelector('.producto-final-detalle-select');
                const cantidadInput = rowElement.querySelector('.cantidad-envases-input');
                const loteStockInfoDiv = rowElement.querySelector('.lote-stock-info');

                // Listener para el cambio de Lote de Envasado en la fila
                loteSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const selectedLoteId = this.value;
                    const selectedArticuloId = selectedOption ? selectedOption.dataset.articuloId : null;
                    const litrosDisponibles = selectedOption ? parseFloat(selectedOption.dataset.litrosDisponibles) : 0;

                    // Update stock info display for this specific lot
                    if (loteStockInfoDiv) {
                        if (selectedLoteId) {
                            loteStockInfoDiv.textContent = `Stock disponible: ${litrosDisponibles.toFixed(2).replace('.', ',')} L`;
                            loteStockInfoDiv.style.color = 'inherit'; // Reset color
                        } else {
                            loteStockInfoDiv.textContent = '';
                        }
                    }

                    // Populate the product select for THIS row based on the selected lot's article ID
                    populateProductSelectOptions(productSelect, selectedArticuloId);
                    updateTotals(); // Always update totals when a lot changes
                });

                // Listener para el cambio de Producto Final o Cantidad de Envases
                productSelect.addEventListener('change', updateTotals);
                cantidadInput.addEventListener('input', updateTotals);

                // Listener para eliminar fila
                const removeButton = rowElement.querySelector('.remove-product-row');
                if (removeButton) {
                    removeButton.addEventListener('click', function() {
                        if (productosEnvasadosContainer.children.length > 1) { // Asegurarse de que siempre haya al menos una fila
                            rowElement.remove();
                            updateTotals(); // Actualizar totales al eliminar fila
                        } else {
                            // If it's the last row, just clear it instead of removing
                            loteSelect.value = '';
                            productSelect.innerHTML = '<option value="">Seleccione un Producto</option>'; // Clear products
                            productSelect.value = '';
                            cantidadInput.value = '';
                            // No hay campo de sublote para limpiar
                            rowElement.querySelector('textarea[name="observaciones_detalle[]"]').value = '';
                            if (loteStockInfoDiv) loteStockInfoDiv.textContent = '';
                            updateTotals();
                        }
                    });
                }
            }

            // Función para generar el informe PDF de una actividad específica
            function generatePdfReport(activityId) {
                console.log(`Attempting to generate PDF report for Activity ID: ${activityId}`);
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF();

                const activity = actividadesEnvasadoData.find(act => act.id_actividad_envasado == activityId);

                if (!activity) {
                    console.error(`Error: Activity with ID ${activityId} not found.`);
                    alert(`No se encontraron datos para la actividad con ID ${activityId}.`);
                    return;
                }

                doc.setFontSize(18);
                doc.text(`Informe de Actividad de Envasado - ID: ${activity.id_actividad_envasado}`, 14, 22);
                doc.setFontSize(12);
                doc.text(`Fecha: ${new Date(activity.fecha_envasado).toLocaleDateString('es-ES')}`, 14, 30);
                doc.text(`Total Envases: ${activity.total_envases_actividad} ud.`, 14, 37);
                // Asegurarse de que total_litros_actividad es un número antes de toFixed
                doc.text(`Total Litros: ${parseFloat(activity.total_litros_actividad).toFixed(2).replace('.', ',')} L`, 14, 44);

                let y = 55; // Starting Y for the details table

                if (activity.productos_envasados && activity.productos_envasados.length > 0) {
                    const head = [['ID Detalle', 'Lote Origen', 'Producto', 'Unidades', 'Litros', 'Obs.']];
                    const body = activity.productos_envasados.map(detail => [
                        detail.id_detalle_actividad,
                        `${detail.nombre_lote} (Art: ${detail.nombre_articulo})`,
                        detail.nombre_producto,
                        `${detail.unidades_envasadas} ud.`,
                        // Asegurarse de que litros_envasados es un número antes de toFixed
                        `${parseFloat(detail.litros_envasados).toFixed(2).replace('.', ',')} L`,
                        detail.observaciones || 'N/A'
                    ]);

                    doc.autoTable({
                        startY: y,
                        head: head,
                        body: body,
                        styles: { fontSize: 8, cellPadding: 2, overflow: 'linebreak' },
                        headStyles: { fillColor: [248, 249, 250], textColor: [73, 80, 87], fontStyle: 'bold' },
                        margin: { left: 14 },
                        columnStyles: {
                            0: { cellWidth: 18 }, // ID Detalle
                            1: { cellWidth: 45 }, // Lote Origen
                            2: { cellWidth: 35 }, // Producto
                            3: { cellWidth: 20, halign: 'center' }, // Unidades
                            4: { cellWidth: 20, halign: 'right' }, // Litros
                            5: { cellWidth: 'auto' }  // Obs.
                        },
                        didDrawPage: function(data) {
                            // If content overflows, add a new page
                            if (data.cursor.y >= doc.internal.pageSize.height - 30) {
                                doc.addPage();
                                y = 20; // Reset Y for new page
                            }
                        }
                    });
                } else {
                    doc.setFontSize(10);
                    doc.text("No hay detalles de productos envasados para esta actividad.", 14, y);
                }

                doc.save(`informe_envasado_actividad_${activity.id_actividad_envasado}.pdf`);
                console.log(`PDF report for Activity ID ${activity.id_actividad_envasado} generated successfully.`);
            }

            // Inicializar la primera fila al cargar la página
            const firstRow = document.querySelector('.product-detail-row[data-row-index="0"]');
            setupProductRowEvents(firstRow);
            // Simulate change on the first lot select to populate its product dropdown initially if a lot is pre-selected
            const firstLoteSelect = firstRow.querySelector('.lote-envasado-detalle-select');
            if (firstLoteSelect && firstLoteSelect.value) {
                firstLoteSelect.dispatchEvent(new Event('change'));
            } else {
                // If no lot is pre-selected, ensure its product dropdown is empty
                populateProductSelectOptions(firstRow.querySelector('.producto-final-detalle-select'), null);
            }

            // Añadir fila de producto envasado
            addProductRowBtn.addEventListener('click', function() {
                productRowIndex++;
                const newRow = document.createElement('div');
                newRow.classList.add('product-detail-row', 'row', 'align-items-end', 'mb-3');
                newRow.setAttribute('data-row-index', productRowIndex);
                newRow.innerHTML = `
                    <div class="col-md-4 mb-3">
                        <label for="id_lote_envasado_detalle_${productRowIndex}" class="form-label form-label-required">Lote de Envasado Origen</label>
                        <select class="form-select lote-envasado-detalle-select" id="id_lote_envasado_detalle_${productRowIndex}" name="id_lote_envasado_detalle[]" required>
                            <option value="">Seleccione un Lote</option>
                            <?php foreach ($lotes_envasado_disponibles as $lote): ?>
                                <option value="<?php echo htmlspecialchars($lote['id_lote_envasado']); ?>"
                                        data-articulo-id="<?php echo htmlspecialchars($lote['id_articulo']); ?>"
                                        data-litros-disponibles="<?php echo htmlspecialchars($lote['stock_deposito_mezcla']); ?>">
                                    <?php echo htmlspecialchars($lote['nombre_lote'] . " (Artículo: " . $lote['nombre_articulo'] . ")"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="lote-stock-info" id="lote-stock-info-${productRowIndex}"></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="id_producto_${productRowIndex}" class="form-label form-label-required">Producto Final</label>
                        <select class="form-select producto-final-detalle-select" id="id_producto_${productRowIndex}" name="id_producto[]" required>
                            <option value="">Seleccione un Producto</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="unidades_envasadas_${productRowIndex}" class="form-label form-label-required">Cantidad de Envases</label>
                        <input type="number" class="form-control cantidad-envases-input" id="unidades_envasadas_${productRowIndex}" name="unidades_envasadas[]" min="1" required>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="litros_envasados_${productRowIndex}" class="form-label">Litros Envasados</label>
                        <input type="hidden" class="calculated-litros-input" name="litros_envasados_hidden[]" value="0.00">
                        <span class="form-control-plaintext text-muted calculated-liters-display">0.00 L</span>
                    </div>
                    <!-- Eliminado el campo Sublote del formulario de entrada para nuevas filas -->
                    <div class="col-md-3 mb-3">
                        <label for="observaciones_detalle_${productRowIndex}" class="form-label">Obs. Detalle</label>
                        <textarea class="form-control" id="observaciones_detalle_${productRowIndex}" name="observaciones_detalle[]" rows="1"></textarea>
                    </div>
                    <div class="col-md-1 d-flex align-items-end mb-3">
                        <button type="button" class="btn btn-danger remove-product-row w-100"><i class="bi bi-x-circle"></i></button>
                    </div>
                `;
                productosEnvasadosContainer.appendChild(newRow);
                
                setupProductRowEvents(newRow); // Configurar eventos para la nueva fila
                updateTotals(); // Actualizar totales al añadir fila
            });
            
            // Inicializar totales al cargar la página (para el resumen)
            updateTotals();

            // Event listener para los botones de descarga de PDF individuales
            document.querySelectorAll('.download-individual-pdf-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const activityId = this.dataset.activityId;
                    generatePdfReport(activityId);
                });
            });
        });
    </script>
</body>
</html>
