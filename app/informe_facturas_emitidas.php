<?php
// Habilitar la visualización de errores para depuración (¡DESACTIVAR EN PRODUCCIÓN!)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// IMPORANTE: Se ha añadido esta línea para asegurar la codificación UTF-8
header('Content-Type: text/html; charset=UTF-8');

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

$facturas_informe = [];
$total_litros_facturados_general = 0;
$total_base_imponible_general = 0;
$total_iva_general = 0;
$total_factura_general = 0;

try {
    // Consulta para obtener las facturas emitidas en el rango de fechas
    // Seleccionar directamente los campos de la cabecera de factura
    $stmt_facturas = $pdo->prepare("
        SELECT
            f.id_factura,
            f.fecha_factura,
            c.nombre_cliente,
            c.nif,
            f.total_factura_iva_incluido,
            f.total_iva_factura,
            f.descuento_global_aplicado,
            f.recargo_global_aplicado
        FROM
            facturas_ventas f
        JOIN
            clientes c ON f.id_cliente = c.id_cliente
        WHERE
            f.fecha_factura BETWEEN ? AND ?
        ORDER BY
            f.fecha_factura ASC, f.id_factura ASC
    ");
    $stmt_facturas->execute([$fecha_inicio, $fecha_fin]);
    $facturas_cabecera = $stmt_facturas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($facturas_cabecera as $factura) {
        $id_factura = $factura['id_factura'];
        $current_total_litros = 0;
        $unique_ivas = [];

        // Obtener detalles de línea para calcular litros y %IVA
        $stmt_detalles = $pdo->prepare("
            SELECT
                df.cantidad,
                p.litros_por_unidad,
                df.porcentaje_iva_aplicado_en_factura
            FROM
                detalle_factura_ventas df
            JOIN
                productos p ON df.id_producto = p.id_producto
            WHERE
                df.id_factura = ?
        ");
        $stmt_detalles->execute([$id_factura]);
        $detalles_linea = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

        foreach ($detalles_linea as $detalle) {
            $current_total_litros += $detalle['cantidad'] * $detalle['litros_por_unidad'];
            // Recopilar porcentajes de IVA únicos
            if (!in_array($detalle['porcentaje_iva_aplicado_en_factura'], $unique_ivas)) {
                $unique_ivas[] = $detalle['porcentaje_iva_aplicado_en_factura'];
            }
        }
        sort($unique_ivas); // Ordenar los porcentajes de IVA para una visualización consistente

        // Calcular la base imponible de la factura (Total Factura con IVA - Total IVA)
        $base_imponible_factura = $factura['total_factura_iva_incluido'] - $factura['total_iva_factura'];

        $facturas_informe[] = [
            'id_factura' => $factura['id_factura'],
            'fecha_factura' => $factura['fecha_factura'],
            'nombre_cliente' => $factura['nombre_cliente'],
            'nif' => $factura['nif'],
            'base_imponible' => $base_imponible_factura,
            'porcentajes_iva' => empty($unique_ivas) ? 'N/A' : implode(', ', $unique_ivas) . '%',
            'total_iva_factura' => $factura['total_iva_factura'],
            'total_factura_iva_incluido' => $factura['total_factura_iva_incluido'],
            'litros_totales_factura' => $current_total_litros
        ];

        // Sumar para los totales generales
        $total_litros_facturados_general += $current_total_litros;
        $total_base_imponible_general += $base_imponible_factura;
        $total_iva_general += $factura['total_iva_factura'];
        $total_factura_general += $factura['total_factura_iva_incluido'];
    }

} catch (PDOException $e) {
    // Si la función mostrarMensaje no existe, se usa una alerta de Bootstrap
    $mensaje = "Error de base de datos al generar el informe: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <!-- IMPORANTE: Se ha añadido esta línea para asegurar la codificación UTF-8 -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Facturas Emitidas</title>
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
            max-height: 600px; /* Adjust as needed */
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
        <?php include 'sidebar.php'; // Incluir la barra lateral ?>
        <div class="content">
            <div class="container-fluid">
                <h1 class="mb-4">Informe de Facturas Emitidas</h1>

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
                        <form id="reportFilterForm" action="informe_facturas_emitidas.php" method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio:</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="fecha_fin" class="form-label">Fecha Fin:</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>" required>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2"><i class="bi bi-funnel"></i> Filtrar</button>
                                <button type="button" class="btn btn-danger me-2" id="generatePdfBtn"><i class="bi bi-file-earmark-pdf"></i> Generar PDF</button>
                                <button type="button" class="btn btn-success" id="exportExcelBtn"><i class="bi bi-file-earmark-excel"></i> Exportar a Excel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        Facturas Emitidas (del <?php echo htmlspecialchars(date('d/m/Y', strtotime($fecha_inicio))); ?> al <?php echo htmlspecialchars(date('d/m/Y', strtotime($fecha_fin))); ?>)
                    </div>
                    <div class="card-body">
                        <div class="table-responsive scrollable-table-container">
                            <table class="table table-striped table-hover table-sm">
                                <thead>
                                    <tr>
                                        <th>Nº Factura</th>
                                        <th>Fecha</th>
                                        <th>Cliente</th>
                                        <th>NIF</th>
                                        <th class="text-end">Base Imponible</th>
                                        <th>% IVA</th>
                                        <th class="text-end">Total IVA</th>
                                        <th class="text-end">Total Factura (IVA Inc.)</th>
                                        <th class="text-end">Total Litros Facturados</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($facturas_informe)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No hay facturas emitidas en el rango de fechas seleccionado.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($facturas_informe as $factura): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($factura['id_factura']); ?></td>
                                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($factura['fecha_factura']))); ?></td>
                                                <td><?php echo htmlspecialchars($factura['nombre_cliente']); ?></td>
                                                <td><?php echo htmlspecialchars($factura['nif'] ?: 'N/A'); ?></td>
                                                <td class="text-end"><?php echo number_format($factura['base_imponible'], 2, ',', '.'); ?> €</td>
                                                <td><?php echo htmlspecialchars($factura['porcentajes_iva']); ?></td>
                                                <td class="text-end"><?php echo number_format($factura['total_iva_factura'], 2, ',', '.'); ?> €</td>
                                                <td class="text-end"><?php echo number_format($factura['total_factura_iva_incluido'], 2, ',', '.'); ?> €</td>
                                                <td class="text-end"><?php echo number_format($factura['litros_totales_factura'], 2, ',', '.'); ?> L</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-primary">
                                        <th colspan="4" class="text-end">TOTALES DEL PERIODO:</th>
                                        <th class="text-end"><?php echo number_format($total_base_imponible_general, 2, ',', '.'); ?> €</th>
                                        <th></th> <!-- Columna vacía para %IVA -->
                                        <th class="text-end"><?php echo number_format($total_iva_general, 2, ',', '.'); ?> €</th>
                                        <th class="text-end"><?php echo number_format($total_factura_general, 2, ',', '.'); ?> €</th>
                                        <th class="text-end"><?php echo number_format($total_litros_facturados_general, 2, ',', '.'); ?> L</th>
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
            // Change the form action to the PDF generation script
            form.action = 'generate_informe_facturas_pdf.php';
            // Set target to _blank to open in a new tab
            form.target = '_blank';
            // Submit the form
            form.submit();
            // Revert form action and target for normal filtering
            form.action = 'informe_facturas_emitidas.php';
            form.target = '_self';
        });

        document.getElementById('exportExcelBtn').addEventListener('click', function() {
            const form = document.getElementById('reportFilterForm');
            // Change the form action to the Excel export script
            form.action = 'export_informe_facturas_excel.php';
            // Set target to _blank to open in a new tab
            form.target = '_blank';
            // Submit the form
            form.submit();
            // Revert form action and target for normal filtering
            form.action = 'informe_facturas_emitidas.php';
            form.target = '_self';
        });
    </script>
</body>
</html>
