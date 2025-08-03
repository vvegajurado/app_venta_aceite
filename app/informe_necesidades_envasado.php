<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Incluir el archivo de verificación de autenticación al inicio de cada página protegida
include 'auth_check.php';

// Iniciar sesión para gestionar mensajes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Inicializar variables
$mensaje = '';
$tipo_mensaje = '';
$necesidades_envasado = [];
$fecha_inicio_filtro = $_GET['fecha_inicio'] ?? '';
$fecha_fin_filtro = $_GET['fecha_fin'] ?? '';

try {
    // Consulta para obtener las necesidades de envasado
    // Suma las cantidades solicitadas de productos en pedidos que aún no han sido facturados
    // o que han sido facturados pero no completamente retirados.
    // También considera el stock actual de productos terminados.

    $sql = "
        SELECT
            p.id_producto,
            p.nombre_producto,
            p.litros_por_unidad,
            p.stock_actual_unidades,
            SUM(dp.cantidad_solicitada) AS cantidad_total_pedida,
            SUM(dp.cantidad_solicitada * p.litros_por_unidad) AS litros_total_pedidos
        FROM
            pedidos o
        JOIN
            detalle_pedidos dp ON o.id_pedido = dp.id_pedido
        JOIN
            productos p ON dp.id_producto = p.id_producto
        WHERE
            o.estado_pedido = 'pendiente' -- Considera solo pedidos pendientes
            AND (
                o.id_factura_asociada IS NULL -- Pedidos sin factura asociada
                OR (
                    o.id_factura_asociada IS NOT NULL AND
                    (SELECT COALESCE(SUM(dfv.cantidad), 0) FROM detalle_factura_ventas dfv WHERE dfv.id_factura = o.id_factura_asociada) >
                    (SELECT COALESCE(SUM(dfv.unidades_retiradas), 0) FROM detalle_factura_ventas dfv WHERE dfv.id_factura = o.id_factura_asociada)
                ) -- O pedidos facturados pero no completamente retirados
            )
    ";

    $params = [];

    if (!empty($fecha_inicio_filtro)) {
        $sql .= " AND o.fecha_pedido >= ?";
        $params[] = $fecha_inicio_filtro;
    }
    if (!empty($fecha_fin_filtro)) {
        $sql .= " AND o.fecha_pedido <= ?";
        $params[] = $fecha_fin_filtro;
    }

    $sql .= " GROUP BY p.id_producto, p.nombre_producto, p.litros_por_unidad, p.stock_actual_unidades ORDER BY p.nombre_producto ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $necesidades_envasado = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensaje = "Error de base de datos al cargar necesidades de envasado: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

// Cargar mensajes de la sesión si existen
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    $tipo_mensaje = $_SESSION['tipo_mensaje'];
    unset($_SESSION['mensaje']);
    unset($_SESSION['tipo_mensaje']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Necesidades de Envasado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
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
        .card-header {
            background-color: #6f42c1; /* Un color distintivo para informes */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #6f42c1;
            border-color: #6f42c1;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background-color: #5935a1;
            border-color: #5935a1;
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            border-radius: 8px;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
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
        .alert {
            border-radius: 8px;
        }

        /* Estilos del Sidebar (copia de sidebar.php) */
        :root {
            --sidebar-width: 250px;
            --primary-color: #007bff;
            --sidebar-bg: #343a40;
            --sidebar-link: #adb5bd;
            --sidebar-hover: #495057;
            --sidebar-active: #0056b3;
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

        .sidebar-menu-link .sidebar-collapse-icon {
            transition: transform 0.3s ease;
        }
        .sidebar-menu-link[aria-expanded="true"] .sidebar-collapse-icon {
            transform: rotate(180deg);
        }

        .sidebar-menu-item.mt-auto {
            margin-top: auto !important;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluir la barra lateral ?>
        <div class="content">
            <div class="container-fluid">
                <h1 class="mb-4">Informe de Necesidades de Envasado</h1>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header">
                        Filtros de Informe
                    </div>
                    <div class="card-body">
                        <form action="informe_necesidades_envasado.php" method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="fecha_inicio" class="form-label">Fecha de Pedido (Desde)</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio_filtro); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="fecha_fin" class="form-label">Fecha de Pedido (Hasta)</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin_filtro); ?>">
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Aplicar Filtros
                                </button>
                                <a href="informe_necesidades_envasado.php" class="btn btn-secondary ms-2">
                                    <i class="bi bi-x-circle"></i> Limpiar Filtros
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        Resumen de Necesidades de Envasado por Producto
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th class="text-end">Stock Actual (Unidades)</th>
                                        <th class="text-end">Total Pedido (Unidades)</th>
                                        <th class="text-end">Necesidad Neta (Unidades)</th>
                                        <th class="text-end">Litros por Unidad</th>
                                        <th class="text-end">Necesidad Neta (Litros)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($necesidades_envasado)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No hay necesidades de envasado pendientes para los filtros seleccionados.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php
                                        $total_necesidad_unidades_global = 0;
                                        $total_necesidad_litros_global = 0;
                                        ?>
                                        <?php foreach ($necesidades_envasado as $necesidad):
                                            $necesidad_neta_unidades = max(0, $necesidad['cantidad_total_pedida'] - $necesidad['stock_actual_unidades']);
                                            $necesidad_neta_litros = $necesidad_neta_unidades * $necesidad['litros_por_unidad'];

                                            $total_necesidad_unidades_global += $necesidad_neta_unidades;
                                            $total_necesidad_litros_global += $necesidad_neta_litros;
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($necesidad['nombre_producto']); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars($necesidad['stock_actual_unidades']); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars($necesidad['cantidad_total_pedida']); ?></td>
                                                <td class="text-end">
                                                    <span class="fw-bold <?php echo ($necesidad_neta_unidades > 0) ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo htmlspecialchars($necesidad_neta_unidades); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><?php echo number_format($necesidad['litros_por_unidad'], 2, ',', '.'); ?></td>
                                                <td class="text-end">
                                                    <span class="fw-bold <?php echo ($necesidad_neta_litros > 0) ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo number_format($necesidad_neta_litros, 2, ',', '.'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-dark">
                                            <th colspan="3" class="text-end">Total Necesidad Neta Global:</th>
                                            <th class="text-end"><?php echo htmlspecialchars($total_necesidad_unidades_global); ?> Unidades</th>
                                            <th></th>
                                            <th class="text-end"><?php echo number_format($total_necesidad_litros_global, 2, ',', '.'); ?> Litros</th>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="pedidos.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Volver a Pedidos</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
