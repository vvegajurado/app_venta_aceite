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
$reporte_por_lote = [];
$reporte_por_producto = [];
$desglose_stock_por_producto = [];

// --- Variables para filtros ---
// Obtener los valores de los filtros de la URL (GET)
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$id_producto_filtro = $_GET['id_producto_filtro'] ?? '';
$id_lote_filtro = $_GET['id_lote_filtro'] ?? '';

// --- Obtener listas para los filtros (productos y lotes) ---
$all_productos = [];
$all_lotes = [];
try {
    $stmt_all_productos = $pdo->query("SELECT id_producto, nombre_producto FROM productos ORDER BY nombre_producto ASC");
    $all_productos = $stmt_all_productos->fetchAll(PDO::FETCH_ASSOC);

    $stmt_all_lotes = $pdo->query("SELECT id_lote_envasado, nombre_lote FROM lotes_envasado ORDER BY nombre_lote ASC");
    $all_lotes = $stmt_all_lotes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = "Error al cargar opciones de filtro: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}


try {
    // --- CONSULTA PARA EL REPORTE POR LOTE ---
    // Subconsultas para envasado y vendido, ahora incluyen condiciones de fecha y producto/lote
    $subquery_envasado_lote = "
        SELECT dae.id_lote_envasado, SUM(dae.litros_envasados) AS total_litros_envasados_lote
        FROM detalle_actividad_envasado dae
        JOIN actividad_envasado ae ON dae.id_actividad_envasado = ae.id_actividad_envasado
        WHERE 1=1
    ";
    $subquery_vendido_lote = "
        SELECT dae.id_lote_envasado, SUM(alv.unidades_asignadas * (CASE WHEN dae.unidades_envasadas > 0 THEN dae.litros_envasados / dae.unidades_envasadas ELSE 0 END)) AS total_litros_vendidos_lote
        FROM asignacion_lotes_ventas alv
        JOIN detalle_factura_ventas dfv ON alv.id_detalle_factura = dfv.id_detalle_factura
        JOIN facturas_ventas fv ON dfv.id_factura = fv.id_factura
        JOIN detalle_actividad_envasado dae ON alv.id_detalle_actividad = dae.id_detalle_actividad
        WHERE 1=1
    ";

    $params_envasado_lote = [];
    $params_vendido_lote = [];

    // Añadir filtros a las subconsultas de envasado y vendido para lotes
    if (!empty($fecha_inicio)) {
        $subquery_envasado_lote .= " AND ae.fecha_envasado >= ?";
        $params_envasado_lote[] = $fecha_inicio;
        $subquery_vendido_lote .= " AND fv.fecha_factura >= ?";
        $params_vendido_lote[] = $fecha_inicio;
    }
    if (!empty($fecha_fin)) {
        $subquery_envasado_lote .= " AND ae.fecha_envasado <= ?";
        $params_envasado_lote[] = $fecha_fin;
        $subquery_vendido_lote .= " AND fv.fecha_factura <= ?";
        $params_vendido_lote[] = $fecha_fin;
    }
    if (!empty($id_producto_filtro)) {
        $subquery_envasado_lote .= " AND dae.id_producto = ?";
        $params_envasado_lote[] = $id_producto_filtro;
        $subquery_vendido_lote .= " AND dae.id_producto = ?";
        $params_vendido_lote[] = $id_producto_filtro;
    }

    $subquery_envasado_lote .= " GROUP BY dae.id_lote_envasado";
    $subquery_vendido_lote .= " GROUP BY dae.id_lote_envasado";

    // Consulta principal de lotes
    $sql_lotes = "
        SELECT le.id_lote_envasado, le.nombre_lote, le.litros_preparados, a.nombre_articulo,
            COALESCE(sq_env.total_litros_envasados_lote, 0) AS total_litros_envasados_lote,
            COALESCE(sq_ven.total_litros_vendidos_lote, 0) AS total_litros_vendidos_lote
        FROM lotes_envasado le
        JOIN articulos a ON le.id_articulo = a.id_articulo
        LEFT JOIN (" . $subquery_envasado_lote . ") sq_env ON le.id_lote_envasado = sq_env.id_lote_envasado
        LEFT JOIN (" . $subquery_vendido_lote . ") sq_ven ON le.id_lote_envasado = sq_ven.id_lote_envasado
        WHERE 1=1
    ";

    $params_lotes_main = [];
    if (!empty($id_lote_filtro)) {
        $sql_lotes .= " AND le.id_lote_envasado = ?";
        $params_lotes_main[] = $id_lote_filtro;
    }

    $sql_lotes .= " ORDER BY le.nombre_lote ASC";

    $stmt_lotes = $pdo->prepare($sql_lotes);
    $stmt_lotes->execute(array_merge($params_envasado_lote, $params_vendido_lote, $params_lotes_main));
    $resultados_lotes = $stmt_lotes->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resultados_lotes as $row) {
        $id_lote = $row['id_lote_envasado'];
        $envasado_litros = (float)$row['total_litros_envasados_lote'];
        $vendido_litros = (float)$row['total_litros_vendidos_lote'];
        $existencias_litros = $envasado_litros - $vendido_litros;
        $reporte_por_lote[$id_lote] = [
            'id_lote_envasado' => $id_lote,
            'nombre_lote' => $row['nombre_lote'],
            'nombre_articulo' => $row['nombre_articulo'],
            'litros_preparados_lote' => (float)$row['litros_preparados'],
            'envasado_litros' => $envasado_litros,
            'vendido_litros' => $vendido_litros,
            'existencias_litros' => $existencias_litros
        ];
    }
    
    // --- CONSULTA PARA EL REPORTE POR PRODUCTO ---
    $subquery_envasado_prod = "
        SELECT p.id_producto, SUM(dae.litros_envasados) as total_litros_envasados
        FROM detalle_actividad_envasado dae
        JOIN productos p ON dae.id_producto = p.id_producto
        JOIN actividad_envasado ae ON dae.id_actividad_envasado = ae.id_actividad_envasado
        WHERE 1=1
    ";
    $subquery_vendido_prod = "
        SELECT p.id_producto, SUM(alv.unidades_asignadas * (CASE WHEN dae.unidades_envasadas > 0 THEN dae.litros_envasados / dae.unidades_envasadas ELSE 0 END)) as total_litros_vendidos
        FROM asignacion_lotes_ventas alv
        JOIN detalle_factura_ventas dfv ON alv.id_detalle_factura = dfv.id_detalle_factura
        JOIN facturas_ventas fv ON dfv.id_factura = fv.id_factura
        JOIN detalle_actividad_envasado dae ON alv.id_detalle_actividad = dae.id_detalle_actividad
        JOIN productos p ON dae.id_producto = p.id_producto
        WHERE 1=1
    ";

    $params_envasado_prod = [];
    $params_vendido_prod = [];

    // Añadir filtros a las subconsultas de envasado y vendido para productos
    if (!empty($fecha_inicio)) {
        $subquery_envasado_prod .= " AND ae.fecha_envasado >= ?";
        $params_envasado_prod[] = $fecha_inicio;
        $subquery_vendido_prod .= " AND fv.fecha_factura >= ?";
        $params_vendido_prod[] = $fecha_inicio;
    }
    if (!empty($fecha_fin)) {
        $subquery_envasado_prod .= " AND ae.fecha_envasado <= ?";
        $params_envasado_prod[] = $fecha_fin;
        $subquery_vendido_prod .= " AND fv.fecha_factura <= ?";
        $params_vendido_prod[] = $fecha_fin;
    }
    if (!empty($id_lote_filtro)) {
        $subquery_envasado_prod .= " AND dae.id_lote_envasado = ?";
        $params_envasado_prod[] = $id_lote_filtro;
        $subquery_vendido_prod .= " AND dae.id_lote_envasado = ?";
        $params_vendido_prod[] = $id_lote_filtro;
    }

    $subquery_envasado_prod .= " GROUP BY p.id_producto";
    $subquery_vendido_prod .= " GROUP BY p.id_producto";

    $sql_productos = "
        SELECT p.id_producto, p.nombre_producto, p.litros_por_unidad, p.stock_actual_unidades,
            COALESCE(sq_env.total_litros_envasados, 0) as total_litros_envasados,
            COALESCE(sq_ven.total_litros_vendidos, 0) as total_litros_vendidos
        FROM productos p
        LEFT JOIN (" . $subquery_envasado_prod . ") sq_env ON p.id_producto = sq_env.id_producto
        LEFT JOIN (" . $subquery_vendido_prod . ") sq_ven ON p.id_producto = sq_ven.id_producto
        WHERE 1=1
    ";

    $params_productos_main = [];
    if (!empty($id_producto_filtro)) {
        $sql_productos .= " AND p.id_producto = ?";
        $params_productos_main[] = $id_producto_filtro;
    }

    $sql_productos .= " ORDER BY p.nombre_producto ASC";

    $stmt_productos = $pdo->prepare($sql_productos);
    $stmt_productos->execute(array_merge($params_envasado_prod, $params_vendido_prod, $params_productos_main));
    $resultados_productos = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resultados_productos as $row) {
        $id_producto = $row['id_producto'];
        $envasado_litros_prod = (float)$row['total_litros_envasados'];
        $vendido_litros_prod = (float)$row['total_litros_vendidos'];
        $existencias_litros_prod = $envasado_litros_prod - $vendido_litros_prod;
        $reporte_por_producto[$id_producto] = [
            'id_producto' => $id_producto,
            'nombre_producto' => $row['nombre_producto'],
            'litros_por_unidad' => (float)$row['litros_por_unidad'],
            'envasado_litros' => $envasado_litros_prod,
            'vendido_litros' => $vendido_litros_prod,
            'existencias_litros' => $existencias_litros_prod,
            'stock_actual_unidades' => (int)$row['stock_actual_unidades']
        ];
    }

    // --- CONSULTA PARA OBTENER EL DESGLOSE DE STOCK POR PRODUCTO Y LOTE ---
    // Esta consulta no necesita los filtros de fecha, ya que es el stock actual.
    $sql_desglose = "
        SELECT
            dae.id_producto,
            le.nombre_lote,
            dae.unidades_envasadas - COALESCE(v.unidades_vendidas, 0) AS existencias_unidades
        FROM
            detalle_actividad_envasado dae
        JOIN
            lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
        LEFT JOIN (
            SELECT
                id_detalle_actividad,
                SUM(unidades_asignadas) AS unidades_vendidas
            FROM
                asignacion_lotes_ventas
            GROUP BY
                id_detalle_actividad
        ) AS v ON dae.id_detalle_actividad = v.id_detalle_actividad
        WHERE
            (dae.unidades_envasadas - COALESCE(v.unidades_vendidas, 0)) > 0
    ";

    $params_desglose = [];
    if (!empty($id_producto_filtro)) {
        $sql_desglose .= " AND dae.id_producto = ?";
        $params_desglose[] = $id_producto_filtro;
    }
    if (!empty($id_lote_filtro)) {
        $sql_desglose .= " AND le.id_lote_envasado = ?";
        $params_desglose[] = $id_lote_filtro;
    }

    $sql_desglose .= " ORDER BY dae.id_producto, le.nombre_lote";

    $stmt_desglose = $pdo->prepare($sql_desglose);
    $stmt_desglose->execute($params_desglose);
    $resultados_desglose = $stmt_desglose->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resultados_desglose as $row) {
        $id_producto = $row['id_producto'];
        if (!isset($desglose_stock_por_producto[$id_producto])) {
            $desglose_stock_por_producto[$id_producto] = [];
        }
        // Acumular en caso de que un mismo lote aparezca varias veces para un producto
        $lote_existente = false;
        foreach ($desglose_stock_por_producto[$id_producto] as $key => $item) {
            if ($item['nombre_lote'] === $row['nombre_lote']) {
                $desglose_stock_por_producto[$id_producto][$key]['existencias_unidades'] += (int)$row['existencias_unidades'];
                $lote_existente = true;
                break;
            }
        }
        if (!$lote_existente) {
            $desglose_stock_por_producto[$id_producto][] = [
                'nombre_lote' => $row['nombre_lote'],
                'existencias_unidades' => (int)$row['existencias_unidades']
            ];
        }
    }

} catch (PDOException $e) {
    $mensaje = "Error al cargar el informe: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Movimientos por Lote y Producto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            background-color: #0056b3;
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
        .btn-info {
            background-color: #17a2b8;
            border-color: #17a2b8;
            border-radius: 8px;
        }
        .btn-info:hover {
            background-color: #138496;
            border-color: #138496;
        }
        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
            border-radius: 8px;
        }
        .table thead {
            background-color: #e9ecef;
        }
        .table th, .table td {
            vertical-align: middle;
            text-align: center;
        }
        .table th:nth-child(1), .table td:nth-child(1) {
            text-align: left;
        }
        .table th:nth-child(2), .table td:nth-child(2) {
            text-align: left;
        }
        .table .text-end {
            text-align: right !important;
        }
        .modal-content {
            border-radius: 15px;
        }
        .modal-header {
            background-color: #0056b3;
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .header {
            background-color: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            color: #0056b3;
            font-size: 1.8rem;
            margin: 0;
        }
        .table .lote-row {
            background-color: #f0f8ff;
            font-weight: bold;
            border-top: 2px solid #0056b3;
        }
        .table .lote-row:first-child {
            border-top: none;
        }
        .table tfoot tr {
            background-color: #cceeff;
            font-weight: bold;
            border-top: 3px solid #004494;
        }
        .filter-buttons-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: center;
        }
        .filter-buttons-container .btn {
            flex: 1 1 auto;
            min-width: 150px;
            max-width: 250px;
            font-weight: 500;
        }
        .filter-buttons-container .btn.active-filter {
            background-color: #0056b3;
            border-color: #0056b3;
            color: white;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        /* Estilos del Sidebar */
        :root {
            --sidebar-width: 250px;
            --primary-color: #0056b3;
            --sidebar-bg: #343a40;
            --sidebar-link: #adb5bd;
            --sidebar-hover: #495057;
            --sidebar-active: #004494;
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            flex-shrink: 0;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow-y: auto;
        }

        .sidebar-header {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header .app-logo {
            max-width: 100px;
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
            height: 100%;
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
            padding: 8px 15px 8px 55px;
            color: var(--sidebar-link);
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
            font-size: 0.95rem;
            border-left: 5px solid transparent;
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
            margin-top: auto !important;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 10px;
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
            .container, .card {
                padding: 20px;
                max-width: 100%;
            }
            .table th, .table td {
                font-size: 0.85em;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluye el sidebar ?>

        <div class="main-content">
            <div class="container">
                <div class="header">
                    <h1>Informe de Movimientos por Lote y Producto</h1>
                    <div>
                        <span class="me-3">Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre_completo'] ?? $_SESSION['username']); ?></span>
                        <a href="logout.php" class="btn btn-secondary btn-sm">Cerrar Sesión</a>
                    </div>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Formulario de Filtros -->
                <div class="card mb-4">
                    <div class="card-header">
                        Filtros de Informe
                    </div>
                    <div class="card-body">
                        <form method="GET" action="informe_movimientos_lote.php">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-3">
                                    <label for="fecha_inicio" class="form-label">Fecha Inicio:</label>
                                    <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="fecha_fin" class="form-label">Fecha Fin:</label>
                                    <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="id_producto_filtro" class="form-label">Producto:</label>
                                    <select class="form-select" id="id_producto_filtro" name="id_producto_filtro">
                                        <option value="">Todos los Productos</option>
                                        <?php foreach ($all_productos as $prod): ?>
                                            <option value="<?php echo htmlspecialchars($prod['id_producto']); ?>" <?php echo ($id_producto_filtro == $prod['id_producto']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($prod['nombre_producto']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="id_lote_filtro" class="form-label">Lote:</label>
                                    <select class="form-select" id="id_lote_filtro" name="id_lote_filtro">
                                        <option value="">Todos los Lotes</option>
                                        <?php foreach ($all_lotes as $lote): ?>
                                            <option value="<?php echo htmlspecialchars($lote['id_lote_envasado']); ?>" <?php echo ($id_lote_filtro == $lote['id_lote_envasado']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($lote['nombre_lote']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-2"></i>Aplicar Filtros</button>
                                    <a href="informe_movimientos_lote.php" class="btn btn-secondary ms-2"><i class="bi bi-arrow-counterclockwise me-2"></i>Limpiar Filtros</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Resumen por Lote -->
                <div class="card table-section-card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Resumen de Movimientos por Lote Envasado</h5>
                    </div>
                    <div class="card-body">
                        <div class="filter-buttons-container">
                            <button type="button" class="btn btn-outline-primary" data-filter="all">Todos los lotes</button>
                            <button type="button" class="btn btn-primary active-filter" data-filter="with_stock">Lotes con Stock</button>
                        </div>
                        <?php if (empty($reporte_por_lote)): ?>
                            <p class="text-center">No hay datos de lotes para los filtros aplicados.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th rowspan="2">Lote Envasado</th><th rowspan="2">Artículo</th>
                                            <th>Envasado</th><th>Vendido</th><th>Existencias Actuales</th>
                                            <th rowspan="2">Acciones</th>
                                        </tr>
                                        <tr>
                                            <th class="text-end">Litros (L)</th><th class="text-end">Litros (L)</th><th class="text-end">Litros (L)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="reportTableBody"></tbody>
                                    <tfoot id="reportTableFooter"></tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Resumen por Producto -->
                <div class="card table-section-card mt-5">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Resumen de Movimientos por Producto</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reporte_por_producto)): ?>
                            <p class="text-center">No hay datos de productos para los filtros aplicados.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th rowspan="2">Producto</th>
                                            <th>Envasado</th><th>Vendido</th><th colspan="2">Existencias Actuales</th>
                                            <th rowspan="2">Acciones</th>
                                        </tr>
                                        <tr>
                                            <th class="text-end">Litros (L)</th><th class="text-end">Litros (L)</th>
                                            <th class="text-end">Litros (L)</th><th class="text-end">Unidades</th>
                                        </tr>
                                    </thead>
                                    <tbody id="productReportTableBody"></tbody>
                                    <tfoot id="productReportTableFooter"></tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-speedometer"></i> Volver al Dashboard</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para el Desglose de Stock por Lote -->
    <div class="modal fade" id="desgloseStockModal" tabindex="-1" aria-labelledby="desgloseStockModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="desgloseStockModalLabel">Desglose de Stock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Mostrando existencias para el producto: <strong id="modalProductName"></strong></p>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Nombre del Lote</th>
                                    <th class="text-end">Unidades en Stock</th>
                                </tr>
                            </thead>
                            <tbody id="modalTableBody">
                                <!-- Contenido dinámico aquí -->
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


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- LÓGICA PARA LA TABLA DE LOTES ---
        const allLotesData = Object.values(<?php echo json_encode($reporte_por_lote); ?>);
        
        function renderTable(filterType) {
            const reportTableBody = document.getElementById('reportTableBody');
            const reportTableFooter = document.getElementById('reportTableFooter');
            reportTableBody.innerHTML = '';
            reportTableFooter.innerHTML = '';

            let filteredLotes = (filterType === 'with_stock')
                ? allLotesData.filter(lote => lote.existencias_litros > 0.001)
                : allLotesData;

            let totalEnvasado = 0, totalVendido = 0, totalExistencias = 0;

            if (filteredLotes.length === 0) {
                reportTableBody.innerHTML = '<tr><td colspan="6" class="text-center">No hay lotes para mostrar.</td></tr>';
            } else {
                filteredLotes.forEach(lote => {
                    const row = document.createElement('tr');
                    row.classList.add('lote-row');
                    row.innerHTML = `
                        <td>${lote.nombre_lote}</td>
                        <td>${lote.nombre_articulo}</td>
                        <td class="text-end">${lote.envasado_litros.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</td>
                        <td class="text-end">${lote.vendido_litros.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</td>
                        <td class="text-end">${lote.existencias_litros.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</td>
                        <td>
                            <a href="informe_desglose_lote_productos.php?lote_id=${lote.id_lote_envasado}" class="btn btn-info btn-sm">
                                <i class="bi bi-eye"></i> Ver Desglose
                            </a>
                        </td>
                    `;
                    reportTableBody.appendChild(row);
                    totalEnvasado += lote.envasado_litros;
                    totalVendido += lote.vendido_litros;
                    totalExistencias += lote.existencias_litros;
                });
                reportTableFooter.innerHTML = `
                    <tr>
                        <td colspan="2"><strong>TOTAL VISIBLE:</strong></td>
                        <td class="text-end"><strong>${totalEnvasado.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</strong></td>
                        <td class="text-end"><strong>${totalVendido.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</strong></td>
                        <td class="text-end"><strong>${totalExistencias.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</strong></td>
                        <td></td>
                    </tr>
                `;
            }
        }
        
        document.querySelectorAll('.filter-buttons-container .btn').forEach(button => {
            button.addEventListener('click', function() {
                document.querySelectorAll('.filter-buttons-container .btn').forEach(btn => {
                    btn.classList.remove('active-filter', 'btn-primary');
                    btn.classList.add('btn-outline-primary');
                });
                this.classList.add('active-filter', 'btn-primary');
                renderTable(this.dataset.filter);
            });
        });

        renderTable('with_stock');

        // --- LÓGICA PARA LA TABLA DE PRODUCTOS ---
        const allProductsData = Object.values(<?php echo json_encode($reporte_por_producto); ?>);
        const desgloseStockData = <?php echo json_encode($desglose_stock_por_producto); ?>;

        function renderProductTable() {
            const tableBody = document.getElementById('productReportTableBody');
            const tableFooter = document.getElementById('productReportTableFooter');
            tableBody.innerHTML = '';
            tableFooter.innerHTML = '';
            
            let totalEnvasado = 0, totalVendido = 0, totalExistencias = 0, totalUnidades = 0;

            if(allProductsData.length === 0) {
                 tableBody.innerHTML = '<tr><td colspan="6" class="text-center">No hay datos de productos.</td></tr>';
                 return;
            }

            allProductsData.forEach(prod => {
                const row = document.createElement('tr');
                const stockUnidadesCalculadas = (prod.litros_por_unidad > 0) 
                    ? (prod.existencias_litros / prod.litros_por_unidad)
                    : 0;
                
                const desgloseButton = (desgloseStockData[prod.id_producto] && desgloseStockData[prod.id_producto].length > 0)
                    ? `<button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#desgloseStockModal" data-product-id="${prod.id_producto}" data-product-name="${prod.nombre_producto}">
                           <i class="bi bi-diagram-3"></i> Ver Desglose
                       </button>`
                    : `<button type="button" class="btn btn-secondary btn-sm" disabled>
                           <i class="bi bi-diagram-3"></i> Sin Stock
                       </button>`;

                row.innerHTML = `
                    <td class="text-start">${prod.nombre_producto}</td>
                    <td class="text-end">${prod.envasado_litros.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</td>
                    <td class="text-end">${prod.vendido_litros.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</td>
                    <td class="text-end">${prod.existencias_litros.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</td>
                    <td class="text-end"><strong>${Math.floor(stockUnidadesCalculadas).toLocaleString('es-ES')} uds.</strong></td>
                    <td>${desgloseButton}</td>
                `;
                tableBody.appendChild(row);

                totalEnvasado += prod.envasado_litros;
                totalVendido += prod.vendido_litros;
                totalExistencias += prod.existencias_litros;
                totalUnidades += Math.floor(stockUnidadesCalculadas);
            });

            tableFooter.innerHTML = `
                <tr>
                    <td class="text-start"><strong>TOTALES:</strong></td>
                    <td class="text-end"><strong>${totalEnvasado.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</strong></td>
                    <td class="text-end"><strong>${totalVendido.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</strong></td>
                    <td class="text-end"><strong>${totalExistencias.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L</strong></td>
                    <td class="text-end"><strong>${totalUnidades.toLocaleString('es-ES')} uds.</strong></td>
                    <td></td>
                </tr>
            `;
        }

        renderProductTable();

        // Event listener para la ventana modal
        const desgloseModal = document.getElementById('desgloseStockModal');
        desgloseModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const productId = button.getAttribute('data-product-id');
            const productName = button.getAttribute('data-product-name');

            const modalTitle = desgloseModal.querySelector('#desgloseStockModalLabel');
            const modalProductName = desgloseModal.querySelector('#modalProductName');
            const modalTableBody = desgloseModal.querySelector('#modalTableBody');

            modalTitle.textContent = `Desglose de Stock: ${productName}`;
            modalProductName.textContent = productName;
            modalTableBody.innerHTML = ''; // Limpiar contenido anterior

            const desglose = desgloseStockData[productId];
            if (desglose && desglose.length > 0) {
                desglose.forEach(item => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${item.nombre_lote}</td>
                        <td class="text-end">${item.existencias_unidades.toLocaleString('es-ES')} uds.</td>
                    `;
                    modalTableBody.appendChild(row);
                });
            } else {
                modalTableBody.innerHTML = '<tr><td colspan="2" class="text-center">No se encontró desglose de stock para este producto.</td></tr>';
            }
        });
    });
    </script>
</body>
</html>
