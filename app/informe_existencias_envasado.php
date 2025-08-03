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

// Array para almacenar los datos del informe
$report_data = [];
$total_general_envasado_unidades = 0;
$total_general_envasado_litros = 0;
$total_general_vendido_unidades = 0;
$total_general_vendido_litros = 0;
$total_general_existencias_unidades = 0;
$total_general_existencias_litros = 0;

try {
    // 1. Obtener el total de unidades y litros envasados por producto
    $sql_envasado = "SELECT
                        p.id_producto,
                        p.nombre_producto,
                        p.litros_por_unidad,
                        SUM(dae.unidades_envasadas) AS total_unidades_envasadas,
                        SUM(dae.litros_envasados) AS total_litros_envasados
                    FROM
                        detalle_actividad_envasado dae
                    JOIN
                        productos p ON dae.id_producto = p.id_producto
                    GROUP BY
                        p.id_producto, p.nombre_producto, p.litros_por_unidad";

    $stmt_envasado = $pdo->query($sql_envasado);
    $resultados_envasado = $stmt_envasado->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resultados_envasado as $row) {
        $id_producto = $row['id_producto'];
        $report_data[$id_producto] = [
            'nombre_producto' => $row['nombre_producto'],
            'litros_por_unidad' => $row['litros_por_unidad'],
            'envasado_unidades' => (float)$row['total_unidades_envasadas'],
            'envasado_litros' => (float)$row['total_litros_envasados'],
            'vendido_unidades' => 0, // Inicializar a 0
            'vendido_litros' => 0,   // Inicializar a 0
            'existencias_unidades' => 0, // Se calculará después
            'existencias_litros' => 0    // Se calculará después
        ];
    }

    // 2. Obtener el total de unidades vendidas (retiradas) por producto
    $sql_vendido = "SELECT
                        p.id_producto,
                        SUM(dfv.unidades_retiradas) AS total_unidades_vendidas
                    FROM
                        detalle_factura_ventas dfv
                    JOIN
                        productos p ON dfv.id_producto = p.id_producto
                    GROUP BY
                        p.id_producto";

    $stmt_vendido = $pdo->query($sql_vendido);
    $resultados_vendido = $stmt_vendido->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resultados_vendido as $row) {
        $id_producto = $row['id_producto'];
        if (isset($report_data[$id_producto])) {
            $report_data[$id_producto]['vendido_unidades'] = (float)$row['total_unidades_vendidas'];
            // Calcular litros vendidos basados en litros_por_unidad del producto
            $report_data[$id_producto]['vendido_litros'] = (float)$row['total_unidades_vendidas'] * $report_data[$id_producto]['litros_por_unidad'];
        } else {
            // Si un producto se vendió pero no se registró envasado, añadirlo (con envasado 0)
            // Esto es importante para que el informe sea completo
            $stmt_prod_info = $pdo->prepare("SELECT nombre_producto, litros_por_unidad FROM productos WHERE id_producto = ?");
            $stmt_prod_info->execute([$id_producto]);
            $prod_info = $stmt_prod_info->fetch(PDO::FETCH_ASSOC);

            if ($prod_info) {
                $report_data[$id_producto] = [
                    'nombre_producto' => $prod_info['nombre_producto'],
                    'litros_por_unidad' => $prod_info['litros_por_unidad'],
                    'envasado_unidades' => 0,
                    'envasado_litros' => 0,
                    'vendido_unidades' => (float)$row['total_unidades_vendidas'],
                    'vendido_litros' => (float)$row['total_unidades_vendidas'] * $prod_info['litros_por_unidad'],
                    'existencias_unidades' => 0,
                    'existencias_litros' => 0
                ];
            }
        }
    }

    // 3. Calcular existencias y totales generales
    foreach ($report_data as $id_producto => &$data) { // Usar & para modificar el array directamente
        $data['existencias_unidades'] = $data['envasado_unidades'] - $data['vendido_unidades'];
        $data['existencias_litros'] = $data['envasado_litros'] - $data['vendido_litros'];

        $total_general_envasado_unidades += $data['envasado_unidades'];
        $total_general_envasado_litros += $data['envasado_litros'];
        $total_general_vendido_unidades += $data['vendido_unidades'];
        $total_general_vendido_litros += $data['vendido_litros'];
        $total_general_existencias_unidades += $data['existencias_unidades'];
        $total_general_existencias_litros += $data['existencias_litros'];
    }
    unset($data); // Romper la referencia del último elemento

    // Ordenar los datos por nombre de producto
    usort($report_data, function($a, $b) {
        return strcmp($a['nombre_producto'], $b['nombre_producto']);
    });

} catch (PDOException $e) {
    $mensaje = "Error al cargar el informe de movimientos: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// Cierra la conexión PDO al final del script
$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Movimientos de Envasado</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        /* Estilos específicos del Informe de Movimientos */
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

        .table thead th {
            background-color: #e9ecef;
            color: #333;
            border-color: #dee2e6;
        }

        .table .product-row {
            background-color: #f0f8ff; /* Light blue for product rows */
            font-weight: bold;
            border-top: 2px solid #0056b3; /* Separador visual para cada producto */
        }
        .table .product-row:first-child {
            border-top: none; /* No border for the very first product row */
        }

        .table tfoot tr {
            background-color: #cceeff; /* Even darker blue for grand total */
            font-weight: bold;
            border-top: 3px solid #004494;
        }

        /* Alineación de texto en las columnas de la tabla */
        .table th, .table td {
            text-align: center; /* Centrar todas las celdas por defecto */
        }
        .table th:nth-child(1), .table td:nth-child(1) {
            text-align: left; /* Alinear a la izquierda la primera columna (Producto) */
        }
        .table th:nth-child(3), .table td:nth-child(3),
        .table th:nth-child(4), .table td:nth-child(4),
        .table th:nth-child(5), .table td:nth-child(5),
        .table th:nth-child(6), .table td:nth-child(6),
        .table th:nth-child(7), .table td:nth-child(7) {
            text-align: right; /* Alineación a la derecha para unidades y litros */
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
            .table th, .table td {
                font-size: 0.85em; /* Reducir tamaño de fuente en pantallas pequeñas */
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
                    <h1>Informe de Movimientos de Envasado</h1>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card table-section-card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Resumen de Movimientos por Producto</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($report_data)): ?>
                            <p class="text-center">No hay datos de envasado o ventas registrados para generar el informe.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th rowspan="2">Producto Final</th>
                                            <th colspan="2">Envasado</th>
                                            <th colspan="2">Vendido</th>
                                            <th colspan="2">Existencias Actuales</th>
                                        </tr>
                                        <tr>
                                            <th class="text-end">Unidades</th>
                                            <th class="text-end">Litros (L)</th>
                                            <th class="text-end">Unidades</th>
                                            <th class="text-end">Litros (L)</th>
                                            <th class="text-end">Unidades</th>
                                            <th class="text-end">Litros (L)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data as $producto): ?>
                                            <tr class="product-row">
                                                <td><?php echo htmlspecialchars($producto['nombre_producto']); ?></td>
                                                <td class="text-end"><?php echo number_format($producto['envasado_unidades']); ?> ud.</td>
                                                <td class="text-end"><?php echo number_format($producto['envasado_litros'], 2, ',', '.'); ?> L</td>
                                                <td class="text-end"><?php echo number_format($producto['vendido_unidades']); ?> ud.</td>
                                                <td class="text-end"><?php echo number_format($producto['vendido_litros'], 2, ',', '.'); ?> L</td>
                                                <td class="text-end"><?php echo number_format($producto['existencias_unidades']); ?> ud.</td>
                                                <td class="text-end"><?php echo number_format($producto['existencias_litros'], 2, ',', '.'); ?> L</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td><strong>TOTAL GENERAL:</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_general_envasado_unidades); ?> ud.</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_general_envasado_litros, 2, ',', '.'); ?> L</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_general_vendido_unidades); ?> ud.</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_general_vendido_litros, 2, ',', '.'); ?> L</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_general_existencias_unidades); ?> ud.</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_general_existencias_litros, 2, ',', '.'); ?> L</strong></td>
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
</body>
</html>
