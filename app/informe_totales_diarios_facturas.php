<?php
// Habilitar la visualización de errores para depuración (¡DESACTIVAR EN PRODUCCIÓN!)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir el archivo de verificación de autenticación al inicio de cada página protegida
include 'auth_check.php';

// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Iniciar sesión para gestionar mensajes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Inicializar variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// Cargar mensajes de la sesión si existen
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    $tipo_mensaje = $_SESSION['tipo_mensaje'];
    unset($_SESSION['mensaje']);
    unset($_SESSION['tipo_mensaje']);
}

// Variables para el filtro de fechas
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01'); // Por defecto, inicio del mes actual
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');     // Por defecto, hoy

$report_data = [];
$total_base_imponible_general = 0;
$total_iva_general = 0;
$total_factura_general = 0;

try {
    // Consulta para obtener los totales agrupados por fecha
    $stmt_daily_totals = $pdo->prepare("
        SELECT
            fecha_factura,
            SUM(total_factura_iva_incluido - total_iva_factura) AS total_base_imponible,
            SUM(total_iva_factura) AS total_iva,
            SUM(total_factura_iva_incluido) AS total_factura
        FROM
            facturas_ventas
        WHERE
            fecha_factura BETWEEN ? AND ?
        GROUP BY
            fecha_factura
        ORDER BY
            fecha_factura ASC
    ");
    $stmt_daily_totals->execute([$fecha_inicio, $fecha_fin]);
    $raw_daily_data = $stmt_daily_totals->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_daily_data as $row) {
        $report_data[] = $row;
        $total_base_imponible_general += (float)$row['total_base_imponible'];
        $total_iva_general += (float)$row['total_iva'];
        $total_factura_general += (float)$row['total_factura'];
    }

} catch (PDOException $e) {
    mostrarMensaje("Error de base de datos al generar el informe: " . $e->getMessage(), "danger");
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Totales Diarios de Facturas</title>
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
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            border-radius: 8px;
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #c82333;
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

        .table thead {
            background-color: #e9ecef;
        }
        .table th, .table td {
            vertical-align: middle;
            text-align: center;
        }
        .table th:first-child, .table td:first-child {
            text-align: left;
        }
        .table tfoot th {
            text-align: right;
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

        /* Custom style for scrollable table */
        .scrollable-table-container {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
        }
        .scrollable-table-container table {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; ?>
        <div class="content">
            <div class="container-fluid">
                <h1 class="mb-4">Informe de Totales Diarios de Facturas</h1>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header">
                        Filtro por Fechas
                    </div>
                    <div class="card-body">
                        <form id="reportFilterForm" action="informe_totales_diarios_facturas.php" method="GET" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio:</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_fin" class="form-label">Fecha Fin:</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>" required>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2"><i class="bi bi-funnel"></i> Filtrar</button>
                                <button type="button" class="btn btn-danger me-2" id="generatePdfBtn"><i class="bi bi-file-earmark-pdf"></i> Generar PDF</button>
                                <button type="button" class="btn btn-success" id="exportExcelBtn"><i class="bi bi-file-earmark-excel"></i> Exportar a Excel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        Totales Diarios de Facturas (del <?php echo htmlspecialchars(date('d/m/Y', strtotime($fecha_inicio))); ?> al <?php echo htmlspecialchars(date('d/m/Y', strtotime($fecha_fin))); ?>)
                    </div>
                    <div class="card-body">
                        <div class="table-responsive scrollable-table-container">
                            <table class="table table-striped table-hover table-sm">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th class="text-end">Total Base Imponible</th>
                                        <th class="text-end">Total IVA</th>
                                        <th class="text-end">Total Factura</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($report_data)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center">No hay datos de facturas en el rango de fechas seleccionado.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($row['fecha_factura']))); ?></td>
                                                <td class="text-end"><?php echo number_format($row['total_base_imponible'], 2, ',', '.'); ?> €</td>
                                                <td class="text-end"><?php echo number_format($row['total_iva'], 2, ',', '.'); ?> €</td>
                                                <td class="text-end"><?php echo number_format($row['total_factura'], 2, ',', '.'); ?> €</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-primary">
                                        <th class="text-end">TOTAL GENERAL:</th>
                                        <th class="text-end"><?php echo number_format($total_base_imponible_general, 2, ',', '.'); ?> €</th>
                                        <th class="text-end"><?php echo number_format($total_iva_general, 2, ',', '.'); ?> €</th>
                                        <th class="text-end"><?php echo number_format($total_factura_general, 2, ',', '.'); ?> €</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        document.getElementById('generatePdfBtn').addEventListener('click', function() {
            const form = document.getElementById('reportFilterForm');
            form.action = 'generate_informe_totales_diarios_pdf.php';
            form.target = '_blank';
            form.submit();
            form.action = 'informe_totales_diarios_facturas.php';
            form.target = '_self';
        });

        document.getElementById('exportExcelBtn').addEventListener('click', function() {
            const form = document.getElementById('reportFilterForm');
            form.action = 'export_informe_totales_diarios_excel.php';
            form.target = '_blank';
            form.submit();
            form.action = 'informe_totales_diarios_facturas.php';
            form.target = '_self';
        });
    </script>
</body>
</html>
