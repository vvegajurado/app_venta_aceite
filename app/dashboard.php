<?php
// Incluir el archivo de conexiÃ³n a la base de datos
include 'conexion.php';

// Incluir el archivo de verificaciÃ³n de autenticaciÃ³n y roles
// Este dashboard serÃ¡ accesible para todos los roles logueados.
include 'auth_check.php';

// Iniciar sesiÃ³n para gestionar mensajes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Inicializar variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// --- LÃ³gica para obtener datos del dashboard ---
$total_litros_en_stock = 0;
$total_ventas_mes_actual = 0; // Euros
$total_clientes = 0;
$ultimas_facturas = [];

// Nuevas variables para el dashboard
$productos_envasados_stock = [];
$stock_materias_primas_depositos = [];
$stock_lotes_preparados = [];
$capacidad_depositos_vacios = 0;
$ventas_hoy_euros = 0;
$ventas_hoy_litros = 0;
$ventas_mes_litros = 0;

try {
    // 1. Obtener el total de litros de aceite en stock (de productos terminados) y desglose por producto
    $stmt_productos_stock_detail = $pdo->query("
        SELECT p.nombre_producto, SUM(p.stock_actual_unidades * p.litros_por_unidad) AS total_litros_producto
        FROM productos p
        GROUP BY p.id_producto, p.nombre_producto
        ORDER BY p.nombre_producto ASC
    ");
    $productos_envasados_stock = $stmt_productos_stock_detail->fetchAll(PDO::FETCH_ASSOC);
    // Calcular el total general de litros envasados en stock
    foreach ($productos_envasados_stock as $item) {
        $total_litros_en_stock += $item['total_litros_producto'];
    }

    // 2. Obtener el stock actual de materias primas en depÃ³sitos
    $stmt_mp_depositos = $pdo->query("
        SELECT mp.nombre_materia_prima, SUM(d.stock_actual) AS total_litros_mp
        FROM depositos d
        JOIN materias_primas mp ON d.id_materia_prima = mp.id_materia_prima
        WHERE d.stock_actual > 0 AND d.id_materia_prima IS NOT NULL
        GROUP BY mp.id_materia_prima, mp.nombre_materia_prima
        ORDER BY mp.nombre_materia_prima ASC
    ");
    $stock_materias_primas_depositos = $stmt_mp_depositos->fetchAll(PDO::FETCH_ASSOC);
    // Calcular el total de materias primas
    $total_stock_materias_primas = 0;
    foreach ($stock_materias_primas_depositos as $item) {
        $total_stock_materias_primas += $item['total_litros_mp'];
    }

    // 3. Obtener el stock actual de lotes preparados (depÃ³sitos de mezcla con stock)
    $stmt_lotes_preparados = $pdo->query("
        SELECT le.nombre_lote, dm.stock_actual AS litros_lote_preparado
        FROM lotes_envasado le
        JOIN depositos dm ON le.id_deposito_mezcla = dm.id_deposito
        WHERE dm.stock_actual > 0
        ORDER BY le.fecha_creacion DESC
    ");
    $stock_lotes_preparados = $stmt_lotes_preparados->fetchAll(PDO::FETCH_ASSOC);
    // Calcular el total de lotes preparados
    $total_stock_lotes_preparados = 0;
    foreach ($stock_lotes_preparados as $item) {
        $total_stock_lotes_preparados += $item['litros_lote_preparado'];
    }

    // 4. Obtener el total de capacidad disponible en depÃ³sitos vacÃ­os
    $stmt_cap_vacios = $pdo->query("SELECT SUM(capacidad) AS total_capacidad_vacia FROM depositos WHERE stock_actual = 0");
    $result_cap_vacios = $stmt_cap_vacios->fetch(PDO::FETCH_ASSOC);
    $capacidad_depositos_vacios = $result_cap_vacios['total_capacidad_vacia'] ?? 0;

    // Fechas para filtros de ventas
    $today = date('Y-m-d');
    $first_day_of_month = date('Y-m-01');

    // 5. Obtener el total de ventas del dÃ­a actual (Euros)
    $stmt_ventas_hoy_euros = $pdo->prepare("SELECT SUM(total_factura) AS total_ventas FROM facturas_ventas WHERE fecha_factura = ?");
    $stmt_ventas_hoy_euros->execute([$today]);
    $result_ventas_hoy_euros = $stmt_ventas_hoy_euros->fetch(PDO::FETCH_ASSOC);
    $ventas_hoy_euros = $result_ventas_hoy_euros['total_ventas'] ?? 0;

    // 6. Obtener el total de ventas del dÃ­a actual (Litros)
    $stmt_ventas_hoy_litros = $pdo->prepare("
        SELECT SUM(df.cantidad * p.litros_por_unidad) AS total_litros
        FROM facturas_ventas f
        JOIN detalle_factura_ventas df ON f.id_factura = df.id_factura
        JOIN productos p ON df.id_producto = p.id_producto
        WHERE f.fecha_factura = ?
    ");
    $stmt_ventas_hoy_litros->execute([$today]);
    $result_ventas_hoy_litros = $stmt_ventas_hoy_litros->fetch(PDO::FETCH_ASSOC);
    $ventas_hoy_litros = $result_ventas_hoy_litros['total_litros'] ?? 0;

    // 7. Obtener el total de ventas del mes actual (Euros)
    $stmt_ventas_mes = $pdo->prepare("SELECT SUM(total_factura) AS total_ventas FROM facturas_ventas WHERE fecha_factura >= ?");
    $stmt_ventas_mes->execute([$first_day_of_month]);
    $resultado_ventas = $stmt_ventas_mes->fetch(PDO::FETCH_ASSOC);
    $total_ventas_mes_actual = $resultado_ventas['total_ventas'] ?? 0;

    // 8. Obtener el total de ventas del mes actual (Litros)
    $stmt_ventas_mes_litros = $pdo->prepare("
        SELECT SUM(df.cantidad * p.litros_por_unidad) AS total_litros
        FROM facturas_ventas f
        JOIN detalle_factura_ventas df ON f.id_factura = df.id_factura
        JOIN productos p ON df.id_producto = p.id_producto
        WHERE f.fecha_factura >= ?
    ");
    $stmt_ventas_mes_litros->execute([$first_day_of_month]);
    $result_ventas_mes_litros = $stmt_ventas_mes_litros->fetch(PDO::FETCH_ASSOC);
    $ventas_mes_litros = $result_ventas_mes_litros['total_litros'] ?? 0;

    // 9. Obtener el nÃºmero total de clientes
    $stmt_clientes = $pdo->query("SELECT COUNT(id_cliente) AS total_clientes FROM clientes");
    $resultado_clientes = $stmt_clientes->fetch(PDO::FETCH_ASSOC);
    $total_clientes = $resultado_clientes['total_clientes'] ?? 0;

    // 10. Obtener las Ãºltimas 5 facturas
    $stmt_ultimas_facturas = $pdo->query("
        SELECT f.id_factura, f.fecha_factura, c.nombre_cliente, f.total_factura, f.estado_pago
        FROM facturas_ventas f
        JOIN clientes c ON f.id_cliente = c.id_cliente
        ORDER BY f.fecha_factura DESC, f.id_factura DESC
        LIMIT 5
    ");
    $ultimas_facturas = $stmt_ultimas_facturas->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensaje = "Error al cargar los datos del dashboard: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// Cierra la conexiÃ³n PDO al final del script
$pdo = null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - GestiÃ³n de Aceite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Reset de box-sizing para consistencia */
        * {
            box-sizing: border-box;
        }

        /* Variables globales */
        :root {
            --primary-color: #0056b3; /* Azul primario, consistente con otros archivos */
            --primary-dark: #004494;
            --secondary-color: #28a745; /* Verde para Ã©nfasis (usado para bg-info) */
            --text-dark: #333;
            --text-light: #666;
            --bg-light: #f4f7f6; /* Fondo claro */
            --sidebar-bg: #343a40; /* Gris oscuro para el sidebar */
            --sidebar-link: #adb5bd; /* Gris claro para enlaces del sidebar */
            --sidebar-hover: #495057; /* Color de fondo hover para sidebar */
            --sidebar-active: #004494; /* Azul mÃ¡s oscuro para el elemento activo */
            --header-bg: #ffffff; /* Fondo blanco para el header */
            --shadow-light: rgba(0, 0, 0, 0.05);
            --shadow-medium: rgba(0, 0, 0, 0.1);

            /* Ajuste de variables para que el sidebar estÃ© siempre expandido o ancho por defecto */
            --sidebar-width: 250px; /* Ancho deseado para el sidebar con texto */
            --content-max-width: 1300px; /* Nuevo: Ancho mÃ¡ximo para el contenido principal (ajustado para ambas tablas) */
        }

        html, body {
            height: 100%; /* Asegura que html y body tomen la altura completa */
            margin: 0;
            padding: 0;
            overflow-x: hidden; /* Evitar scroll horizontal en el body */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
            font-size: 1rem;
            /* display: flex; REMOVED from body */
        }

        /* Estilos del Wrapper (nuevo contenedor flex) */
        .wrapper {
            display: flex; /* Ahora el wrapper es el contenedor flex */
            min-height: 100vh; /* Asegura que el wrapper ocupe toda la altura de la ventana */
            width: 100%; /* Asegura que el wrapper ocupe todo el ancho */
        }


        /* Estilos del Sidebar */
        .sidebar {
            width: var(--sidebar-width); /* Sidebar siempre expandido por defecto en este setup */
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            flex-shrink: 0; /* Evita que el sidebar se encoja */
            min-height: 100vh; /* Ahora usa min-height para asegurar que se extiende */
            position: sticky; /* Keep sidebar fixed when scrolling */
            top: 0; /* Align to the top */
            overflow-y: auto; /* Scroll si el contenido es largo */
        }

        .sidebar-header {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header .app-icon {
            font-size: 3rem; /* MÃ¡s grande para el icono del header */
            color: #fff;
            margin-bottom: 10px;
        }
        .sidebar-header .app-logo {
            max-width: 100px; /* TamaÃ±o del logo en el sidebar */
            height: auto;
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

        /* Estilos adicionales para los menÃºs desplegables */
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
            padding: 8px 15px 8px 55px; /* Ajuste para la indentaciÃ³n */
            color: var(--sidebar-link);
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
            font-size: 0.95rem;
            border-left: 5px solid transparent; /* Para la lÃ­nea activa del submenÃº */
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

        /* Estilo para empujar el elemento de cerrar sesiÃ³n al final */
        .sidebar-menu-item.mt-auto {
            margin-top: auto !important; /* Empuja este elemento al final de la flexbox */
            border-top: 1px solid rgba(255, 255, 255, 0.1); /* Separador visual */
            padding-top: 10px;
        }

        /* Contenido Principal */
        .main-content { /* Renombrado de .content a .main-content */
            flex: 1; /* shorthand for flex-grow: 1, flex-shrink: 1, flex-basis: 0% */
            padding: 30px; /* Aumentado el padding para un mejor espaciado */
            background-color: var(--bg-light);
            min-height: 100vh; /* Asegura que el contenido se extienda si es corto */
            min-width: 0; /* Permite que el contenido se encoja */
            overflow-y: auto; /* Permite el scroll vertical dentro del contenido principal */
        }

        /* Estilos del contenedor principal dentro del main-content */
        .container-fluid {
            background-color: var(--header-bg);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 4px 8px var(--shadow-light);
            margin-bottom: 40px;
            width: 100%; /* Asegura que ocupe todo el ancho disponible dentro de main-content */
            margin-left: auto;
            margin-right: auto;
        }

        h1, h2, h3, h4, h5, h6 {
            color: var(--primary-dark);
            font-weight: 600;
        }
        h1 { /* Estilo para el tÃ­tulo principal del dashboard */
            font-size: 2.2rem;
            margin-bottom: 30px;
            text-align: center;
            color: var(--primary-color);
        }
        h2 {
            font-size: 1.8rem;
            margin-bottom: 25px;
            text-align: center;
        }
        .form-label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 1px solid var(--border-color, #e0e0e0);
            border-radius: 8px;
            padding: 10px 15px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(0, 86, 179, 0.25);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            font-weight: 600;
            padding: 12px 25px;
            border-radius: 8px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .btn-sm {
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 0.875rem;
            line-height: 1.2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-sm i {
            margin-right: 5px;
            font-size: 0.9rem;
        }

        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #c82333;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #333;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #d39e00;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            font-weight: 600;
            padding: 12px 25px;
            border-radius: 8px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #545b62;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .btn-info {
            background-color: var(--secondary-color); /* Usar la variable para consistencia */
            border-color: var(--secondary-color);
            color: #fff;
        }
        .btn-info:hover {
            background-color: var(--secondary-dark); /* Usar la variable para consistencia */
            border-color: var(--secondary-dark);
        }

        .card {
            margin-bottom: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 8px var(--shadow-light);
        }
        .card-header {
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            padding: 15px 20px;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
        }
        .card-header.bg-primary-custom { /* Custom class for primary color headers */
             background-color: var(--primary-color) !important;
        }
        .card-header.bg-info-custom { /* Custom class for info color headers */
            background-color: #17a2b8 !important; /* Usar color info de Bootstrap para consistencia */
        }
        .card-header.bg-success-custom {
            background-color: var(--secondary-color) !important;
        }


        .table-responsive {
            margin-top: 20px;
        }
        .table thead th {
            background-color: var(--primary-dark);
            color: white;
            border-color: var(--primary-dark);
            padding: 12px 15px;
        }
        .table tbody tr:hover {
            background-color: #e6f3e6;
        }
        .table-bordered {
            border-color: var(--border-color, #e0e0e0);
        }
        .table td, .table th {
            vertical-align: middle;
        }

        /* Dashboard specific styles */
        .dashboard-card-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 15px;
        }
        .dashboard-card-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--text-dark);
        }
        .dashboard-card-title {
            font-size: 1.1rem;
            color: var(--text-light);
            margin-top: 10px;
        }
        .dashboard-summary-cards .card-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 25px;
            min-height: 180px; /* Ensure consistent card height */
        }
        .dashboard-summary-cards .card {
            height: 100%; /* Make cards in the row take full height */
        }
        .product-stock-list {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 0.95rem;
            color: var(--text-dark);
            text-align: left; /* Align text left within the list */
            width: 100%; /* Take full width of the card body */
        }
        .product-stock-list li {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed #eee;
        }
        .product-stock-list li:last-child {
            border-bottom: none;
        }
        .product-stock-list li span:first-child {
            font-weight: 500;
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
            .container-fluid {
                padding: 20px;
            }
            .dashboard-card-value {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluye el sidebar ?>

        <div class="main-content"> <!-- Renombrado de .content a .main-content -->
            <?php include 'header.php'; ?>
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="mb-0">Dashboard Principal</h1>
                    <!-- Eliminado el texto de bienvenida y el botÃ³n de cerrar sesiÃ³n -->
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row dashboard-summary-cards">
                    <!-- Litros de Aceites Envasados en Stock -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="bi bi-box-seam-fill dashboard-card-icon"></i>
                                <div class="dashboard-card-value"><?php echo number_format($total_litros_en_stock, 2, ',', '.'); ?> L</div>
                                <div class="dashboard-card-title">Litros de Aceites Envasados en Stock</div>
                                <?php if (!empty($productos_envasados_stock)): ?>
                                    <hr class="w-75 my-3">
                                    <ul class="product-stock-list">
                                        <?php foreach ($productos_envasados_stock as $item): ?>
                                            <li>
                                                <span><?php echo htmlspecialchars($item['nombre_producto']); ?>:</span>
                                                <span><?php echo number_format($item['total_litros_producto'], 2, ',', '.'); ?> L</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Stock Actual de Materias Primas en DepÃ³sitos -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="bi bi-box-seam-fill dashboard-card-icon"></i>
                                <div class="dashboard-card-value"><?php echo number_format($total_stock_materias_primas, 2, ',', '.'); ?> L</div>
                                <div class="dashboard-card-title">Stock de Materias Primas</div>
                                <?php if (!empty($stock_materias_primas_depositos)): ?>
                                    <hr class="w-75 my-3">
                                    <ul class="product-stock-list">
                                        <?php foreach ($stock_materias_primas_depositos as $item): ?>
                                            <li>
                                                <span><?php echo htmlspecialchars($item['nombre_materia_prima']); ?>:</span>
                                                <span><?php echo number_format($item['total_litros_mp'], 2, ',', '.'); ?> L</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <hr class="w-75 my-3">
                                    <p class="text-muted">No hay materias primas en stock.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Stock Actual de Lotes Preparados -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="bi bi-box-seam-fill dashboard-card-icon"></i>
                                <div class="dashboard-card-value"><?php echo number_format($total_stock_lotes_preparados, 2, ',', '.'); ?> L</div>
                                <div class="dashboard-card-title">Stock de Lotes Preparados</div>
                                <?php if (!empty($stock_lotes_preparados)): ?>
                                    <hr class="w-75 my-3">
                                    <ul class="product-stock-list">
                                        <?php foreach ($stock_lotes_preparados as $item): ?>
                                            <li>
                                                <span><?php echo htmlspecialchars($item['nombre_lote']); ?>:</span>
                                                <span><?php echo number_format($item['litros_lote_preparado'], 2, ',', '.'); ?> L</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <hr class="w-75 my-3">
                                    <p class="text-muted">No hay lotes preparados.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Capacidad Disponible en DepÃ³sitos VacÃ­os -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="bi bi-funnel-fill dashboard-card-icon"></i>
                                <div class="dashboard-card-value"><?php echo number_format($capacidad_depositos_vacios, 2, ',', '.'); ?> L</div>
                                <div class="dashboard-card-title">Capacidad Disponible en DepÃ³sitos VacÃ­os</div>
                            </div>
                        </div>
                    </div>

                    <!-- Ventas del DÃ­a Actual -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="bi bi-cash-stack dashboard-card-icon"></i>
                                <div class="dashboard-card-value"><?php echo number_format($ventas_hoy_euros, 2, ',', '.'); ?> â‚¬</div>
                                <div class="dashboard-card-title">Ventas Hoy (Euros)</div>
                                <hr class="w-75 my-3">
                                <div class="dashboard-card-value" style="font-size: 1.8rem;"><?php echo number_format($ventas_hoy_litros, 2, ',', '.'); ?> L</div>
                                <div class="dashboard-card-title">Ventas Hoy (Litros)</div>
                            </div>
                        </div>
                    </div>

                    <!-- Ventas del Mes Actual -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="bi bi-graph-up dashboard-card-icon"></i>
                                <div class="dashboard-card-value"><?php echo number_format($total_ventas_mes_actual, 2, ',', '.'); ?> â‚¬</div>
                                <div class="dashboard-card-title">Ventas Mes Actual (Euros)</div>
                                <hr class="w-75 my-3">
                                <div class="dashboard-card-value" style="font-size: 1.8rem;"><?php echo number_format($ventas_mes_litros, 2, ',', '.'); ?> L</div>
                                <div class="dashboard-card-title">Ventas Mes Actual (Litros)</div>
                            </div>
                        </div>
                    </div>

                    <!-- Clientes Registrados -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="bi bi-people-fill dashboard-card-icon"></i>
                                <div class="dashboard-card-value"><?php echo number_format($total_clientes, 0, ',', '.'); ?></div>
                                <div class="dashboard-card-title">Clientes Registrados</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header bg-primary-custom">
                        Ãšltimas 5 Facturas
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID Factura</th>
                                        <th>Fecha</th>
                                        <th>Cliente</th>
                                        <th class="text-end">Total</th>
                                        <th>Estado Pago</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ultimas_facturas)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No hay facturas recientes.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($ultimas_facturas as $factura):
                                            $badge_class_pago = '';
                                            switch ($factura['estado_pago']) {
                                                case 'pendiente':
                                                    $badge_class_pago = 'bg-warning text-dark';
                                                    break;
                                                case 'pagada':
                                                    $badge_class_pago = 'bg-success';
                                                    break;
                                                case 'parcialmente_pagada':
                                                    $badge_class_pago = 'bg-info';
                                                    break;
                                                default:
                                                    $badge_class_pago = 'bg-secondary';
                                                    break;
                                            }
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($factura['id_factura']); ?></td>
                                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($factura['fecha_factura']))); ?></td>
                                                <td><?php echo htmlspecialchars($factura['nombre_cliente']); ?></td>
                                                <td class="text-end"><?php echo number_format($factura['total_factura'], 2, ',', '.'); ?> â‚¬</td>
                                                <td>
                                                    <span class="badge <?php echo htmlspecialchars($badge_class_pago); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $factura['estado_pago']))); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="facturas_ventas.php?view=details&id=<?php echo htmlspecialchars($factura['id_factura']); ?>" class="btn btn-info btn-sm" title="Ver Detalles">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="informe_movimientos_lote.php" class="btn btn-secondary me-2"><i class="bi bi-file-earmark-bar-graph"></i> Ver Informes Completos</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
