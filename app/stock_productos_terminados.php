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

// Cargar mensajes de la redirección
if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $tipo_mensaje = $_GET['tipo_mensaje'];
}

$productos_con_stock = [];

try {
    // 1. Obtener todos los productos terminados con su stock actual
    $stmt_productos = $pdo->query("SELECT id_producto, nombre_producto, stock_actual_unidades FROM productos ORDER BY nombre_producto ASC");
    $productos = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($productos as $producto) {
        $id_producto = $producto['id_producto'];
        $nombre_producto = $producto['nombre_producto'];
        $stock_actual_db = $producto['stock_actual_unidades']; // Stock actual definitivo de la DB

        // 2. Obtener entradas por actividad de envasado para este producto
        $sql_entradas = "
            SELECT
                ae.fecha_envasado AS fecha,
                'Entrada' AS tipo_movimiento,
                dae.unidades_envasadas AS cantidad, -- Usar unidades_envasadas
                le.nombre_lote AS referencia_lote,
                NULL AS referencia_factura,
                'Lote Envasado' AS origen_movimiento
            FROM
                detalle_actividad_envasado dae
            JOIN
                actividad_envasado ae ON dae.id_actividad_envasado = ae.id_actividad_envasado
            JOIN
                lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
            WHERE
                dae.id_producto = ?
        ";
        $stmt_entradas = $pdo->prepare($sql_entradas);
        $stmt_entradas->execute([$id_producto]);
        $entradas = $stmt_entradas->fetchAll(PDO::FETCH_ASSOC);

        // 3. Obtener salidas por facturas de ventas para este producto, incluyendo el nombre del lote
        // La unión ahora pasa por `detalle_actividad_envasado`
        $sql_salidas = "
            SELECT
                fv.fecha_factura AS fecha,
                'Salida' AS tipo_movimiento,
                dfv.cantidad AS cantidad,
                le.nombre_lote AS referencia_lote, -- Obtenemos el nombre del lote a través de dae
                fv.id_factura AS referencia_factura,
                'Factura de Venta' AS origen_movimiento
            FROM
                detalle_factura_ventas dfv
            JOIN
                facturas_ventas fv ON dfv.id_factura = fv.id_factura
            JOIN
                detalle_actividad_envasado dae ON dfv.id_detalle_actividad = dae.id_detalle_actividad -- Nueva unión
            JOIN
                lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado -- Unimos con lotes_envasado a través de dae
            WHERE
                dfv.id_producto = ?
        ";
        $stmt_salidas = $pdo->prepare($sql_salidas);
        $stmt_salidas->execute([$id_producto]);
        $salidas = $stmt_salidas->fetchAll(PDO::FETCH_ASSOC);

        // Combinar todas las entradas y salidas para este producto
        $movimientos = array_merge($entradas, $salidas);

        // Ordenar movimientos por fecha en orden ASCENDENTE (más antiguo primero)
        usort($movimientos, function($a, $b) {
            return strtotime($a['fecha']) - strtotime($b['fecha']);
        });

        // Calcular el total de entradas y salidas en el historial recuperado
        $total_entradas_historial = 0;
        $total_salidas_historial = 0;
        foreach ($movimientos as $mov) {
            if ($mov['tipo_movimiento'] == 'Entrada') {
                $total_entradas_historial += $mov['cantidad'];
            } else { // Salida
                $total_salidas_historial += $mov['cantidad'];
            }
        }

        // Calcular el stock inicial antes del primer movimiento en el historial visible.
        // Este es el punto de partida para que el último 'stock_despues' coincida con el stock actual de la DB.
        $initial_stock_before_history = $stock_actual_db - $total_entradas_historial + $total_salidas_historial;

        // Preparar el array de movimientos para mostrar, incluyendo la entrada de stock inicial
        $movimientos_para_mostrar = [];

        // Añadir la entrada de stock inicial al principio de la lista de movimientos
        // La fecha será la del primer movimiento real si existe, o 'N/A' si no hay movimientos.
        $earliest_date_str = !empty($movimientos) ? date("d/m/Y", strtotime($movimientos[0]['fecha'])) : 'N/A';

        $movimientos_para_mostrar[] = [
            'fecha' => $earliest_date_str,
            'tipo_movimiento' => 'Stock Inicial',
            'cantidad' => 0, // No hay cantidad para este "movimiento" en sí
            'referencia_lote' => 'General', // Categoría especial para el stock inicial
            'referencia_factura' => 'N/A',
            'origen_movimiento' => 'Sistema',
            'stock_despues' => $initial_stock_before_history // Este es el stock antes del primer movimiento real
        ];

        // Calcular el balance de stock acumulado, empezando por el stock inicial calculado
        $running_stock_balance = $initial_stock_before_history;

        // Procesar los movimientos reales y añadirlos al array para mostrar
        foreach ($movimientos as $mov) {
            if ($mov['tipo_movimiento'] == 'Entrada') {
                $running_stock_balance += $mov['cantidad'];
            } else { // Salida
                $running_stock_balance -= $mov['cantidad'];
            }
            $mov['stock_despues'] = $running_stock_balance; // Actualizar el array de movimiento
            $movimientos_para_mostrar[] = $mov;
        }

        // Agrupar movimientos por lote para la visualización
        $movimientos_agrupados_por_lote = [];
        foreach ($movimientos_para_mostrar as $mov) {
            $lote_key = $mov['referencia_lote'] ?: 'Sin Lote Asignado';
            $movimientos_agrupados_por_lote[$lote_key][] = $mov;
        }

        // Ordenar los movimientos dentro de cada lote (incluyendo 'General') por fecha DESCENDENTE para la visualización
        foreach ($movimientos_agrupados_por_lote as $lote => $movs) {
            usort($movimientos_agrupados_por_lote[$lote], function($a, $b) {
                // 'Stock Inicial' debe ser siempre la primera entrada dentro de su grupo 'General'
                if ($a['tipo_movimiento'] == 'Stock Inicial' && $lote == 'General') return -1;
                if ($b['tipo_movimiento'] == 'Stock Inicial' && $lote == 'General') return 1;

                // Para otros movimientos, ordenar por fecha descendente
                $dateA = ($a['fecha'] === 'N/A') ? 0 : strtotime($a['fecha']);
                $dateB = ($b['fecha'] === 'N/A') ? 0 : strtotime($b['fecha']);
                return $dateB - $dateA;
            });
        }

        // Ordenar los grupos de lotes alfabéticamente por nombre de lote, con 'General' primero
        uksort($movimientos_agrupados_por_lote, function($a, $b) {
            if ($a == 'General') return -1;
            if ($b == 'General') return 1;
            return strcmp($a, $b);
        });

        $productos_con_stock[] = [
            'id_producto' => $id_producto,
            'nombre_producto' => $nombre_producto,
            'stock_actual_unidades' => $stock_actual_db,
            'movimientos_agrupados' => $movimientos_agrupados_por_lote
        ];
    }

} catch (PDOException $e) {
    $mensaje = "Error al cargar el stock de productos terminados: " . $e->getMessage();
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
    <title>Stock de Productos Terminados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Global variables for design consistency */
        :root {
            --primary-color: #4CAF50; /* Olive/oil green */
            --primary-dark: #388E3C;
            --secondary-color: #2196F3; /* Blue for emphasis */
            --text-dark: #333;
            --text-light: #666;
            --bg-light: #f8f9fa;
            --bg-sidebar: #34495e; /* Dark gray for sidebar */
            --sidebar-link: #ecf0f1; /* Light text color for sidebar links */
            --sidebar-hover: #2c3e50; /* Hover background color for sidebar */
            --header-bg: #ffffff; /* White background for header */
            --shadow-light: rgba(0, 0, 0, 0.1);
            --border-color: #dee2e6;

            /* Ajuste de variables para que el sidebar esté siempre expandido o ancho por defecto */
            --sidebar-width-expanded: 220px; /* Ancho deseado para el sidebar con texto */
            --content-margin-left: var(--sidebar-width-expanded); /* Margen del contenido principal */
            --content-max-width: 1400px; /* Ancho máximo para el contenido principal */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            margin: 0;
            display: flex;
            min-height: 100vh;
        }

        /* Main container for two-column layout */
        .wrapper {
            display: flex;
            width: 100%;
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width-expanded); /* Sidebar siempre expandido por defecto en este setup */
            background-color: var(--bg-sidebar);
            color: var(--sidebar-link);
            padding-top: 20px;
            transition: width 0.3s ease;
            position: fixed; /* Fijo en la pantalla */
            height: 100%; /* Ocupa toda la altura */
            left: 0;
            top: 0;
            z-index: 1030; /* Para que esté por encima de otros elementos */
            overflow-y: auto; /* Scroll si el contenido es largo */
            box-shadow: 2px 0 10px var(--shadow-light); /* Añadida sombra para el sidebar */
        }

        .sidebar-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 0 10px;
        }

        .sidebar-header .app-icon {
            font-size: 2.5rem; /* Más grande para el icono del header */
            color: var(--primary-color);
        }
        .sidebar-header .app-logo {
            max-width: 120px; /* Tamaño del logo en el sidebar */
            height: auto;
            display: block;
            margin: 0 auto 15px auto; /* Centrar y añadir margen inferior */
            border-radius: 50%; /* Si quieres que sea circular */
            border: 2px solid var(--primary-color); /* Borde opcional */
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }


        .sidebar-header .app-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: white;
            white-space: nowrap; /* Evita que el texto se rompa */
            overflow: hidden;
            text-overflow: ellipsis;
            display: block; /* Siempre visible */
            margin-top: 10px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu-item {
            margin-bottom: 5px;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: var(--sidebar-link);
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
            border-left: 5px solid transparent;
            font-weight: 500;
            white-space: nowrap;
        }

        .sidebar-menu-link:hover, .sidebar-menu-link.active {
            background-color: var(--sidebar-hover);
            color: white;
            border-left-color: var(--primary-color);
        }

        .sidebar-menu-link i {
            font-size: 1.5rem; /* Tamaño de los íconos del menú */
            margin-right: 15px; /* Espacio entre ícono y texto */
            width: 25px; /* Para alinear íconos si los textos varían */
            text-align: center;
        }
        .sidebar-menu-link span {
            display: inline-block; /* El texto de los enlaces siempre será visible */
        }

        /* Contenido Principal */
        .main-content {
            flex-grow: 1;
            padding: 20px;
            background-color: var(--bg-light);
            margin-left: var(--content-margin-left); /* Espacio para el sidebar fijo y ancho */
            width: calc(100% - var(--content-margin-left)); /* Ancho restante del contenido */
            min-height: 100vh; /* Asegura que el contenido principal también ocupe toda la altura */
        }

        /* Styles for the header within main-content */
        .header {
            background-color: var(--header-bg);
            padding: 20px;
            border-radius: 8px;
            box-shadow: var(--shadow-light);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin: 0;
        }

        /* Card Content */
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: var(--shadow-light);
            margin-bottom: 20px;
        }

        .card-header {
            background-color: var(--primary-color);
            color: #fff !important; /* Force white text for card headers */
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            padding: 15px 20px;
            font-weight: bold;
        }

        .card-body {
            padding: 20px;
            background-color: #fff;
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        /* Button Styles */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            transition: background-color 0.3s, border-color 0.3s;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-outline-secondary {
            color: var(--text-light);
            border-color: var(--border-color);
        }

        /* Form Styles */
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(76, 175, 80, 0.25);
        }

        /* Alert Messages */
        .alert {
            border-radius: 5px;
            margin-top: 15px;
        }

        /* Tables */
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: var(--bg-light);
        }

        .table thead th {
            background-color: var(--primary-color);
            color: #fff;
            border-color: var(--primary-dark);
        }

        .table-bordered th, .table-bordered td {
            border-color: var(--border-color);
        }

        /* Specific Traceability (repurposed for stock) */
        .trace-item { /* Used for card body sections */
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 15px;
            padding: 15px;
            background-color: #fefefe;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .trace-item h5 {
            color: var(--primary-color);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .trace-item p {
            margin-bottom: 5px;
        }

        .trace-sub-item { /* Used for table rows within lot groups */
            border-left: 3px solid var(--secondary-color);
            padding-left: 15px;
            margin-left: 15px;
            margin-top: 10px;
            background-color: #f0f8ff;
            border-radius: 5px;
        }
        .trace-sub-item-secondary { /* Used for table rows within lot groups */
            border-left: 3px solid #6c757d; /* Dark gray for the next level */
            padding-left: 15px;
            margin-left: 15px;
            margin-top: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .trace-sub-item-tertiary { /* Used for table rows within lot groups */
            border-left: 3px solid #dc3545; /* Red for the third level */
            padding-left: 15px;
            margin-left: 15px;
            margin-top: 10px;
            background-color: #fff5f5;
            border-radius: 5px;
        }

        .form-check-inline {
            margin-right: 1.5rem;
        }

        .total-summary {
            background-color: #e6ffe6; /* Light green for totals */
            border: 1px solid #4CAF50;
            border-radius: 5px;
            padding: 10px;
            margin-top: 15px;
            font-weight: bold;
            color: var(--primary-dark);
        }

        /* Estilo para el encabezado del lote */
        .lote-header {
            background-color: #e9ecef; /* Un gris claro para diferenciar */
            font-weight: bold;
            text-align: left;
            padding: 8px 15px;
            border-bottom: 1px solid #dee2e6;
            color: var(--text-dark);
        }

        /* Media Queries para responsividad */
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                left: -var(--sidebar-width-expanded);
            }
            .sidebar.expanded {
                width: var(--sidebar-width-expanded);
                left: 0;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 15px;
            }
            .sidebar.expanded + .main-content {
                margin-left: var(--sidebar-width-expanded);
            }
            .container, .card {
                padding: 20px;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; ?>

        <div id="content" class="main-content">
            <div class="header">
                <h1>Stock de Productos Terminados</h1>
                <div class="d-flex">
                    <span class="me-3">Bienvenido, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Usuario'); ?></span>
                    <a href="logout.php" class="btn btn-danger btn-sm">Cerrar Sesión</a>
                </div>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($productos_con_stock)): ?>
                <p class="text-center">No hay productos terminados registrados o no se pudo cargar el stock.</p>
            <?php else: ?>
                <?php foreach ($productos_con_stock as $producto): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5><?php echo htmlspecialchars($producto['nombre_producto']); ?></h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-3"><strong>Stock Actual:</strong> <span class="badge bg-primary fs-5"><?php echo number_format($producto['stock_actual_unidades'], 0, ',', '.'); ?> Unidades</span></p>

                            <?php if (!empty($producto['movimientos_agrupados'])): ?>
                                <h6 class="mt-4 mb-3">Historial de Movimientos por Lote</h6>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped">
                                        <thead>
                                            <tr>
                                                <th>Fecha</th>
                                                <th>Tipo</th>
                                                <th class="text-end">Cantidad (Unidades)</th>
                                                <th>Referencia</th>
                                                <th>Origen</th>
                                                <th class="text-end">Stock Después</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($producto['movimientos_agrupados'] as $lote_nombre => $movimientos_lote): ?>
                                                <tr>
                                                    <td colspan="6" class="lote-header">Lote: <?php echo htmlspecialchars($lote_nombre); ?></td>
                                                </tr>
                                                <?php foreach ($movimientos_lote as $movimiento): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($movimiento['fecha']); ?></td>
                                                        <td class="<?php echo ($movimiento['tipo_movimiento'] == 'Entrada') ? 'movement-entry' : (($movimiento['tipo_movimiento'] == 'Salida') ? 'movement-exit' : ''); ?>">
                                                            <?php echo htmlspecialchars($movimiento['tipo_movimiento']); ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <?php
                                                                $display_cantidad = $movimiento['cantidad'];
                                                                if ($movimiento['tipo_movimiento'] == 'Salida') {
                                                                    $display_cantidad = -abs($movimiento['cantidad']); // Mostrar salidas como negativas
                                                                }
                                                                // No mostrar cantidad para el 'Stock Inicial'
                                                                echo ($movimiento['tipo_movimiento'] == 'Stock Inicial') ? 'N/A' : number_format($display_cantidad, 0, ',', '.');
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                                if ($movimiento['tipo_movimiento'] == 'Stock Inicial') {
                                                                    echo 'N/A';
                                                                } elseif ($movimiento['referencia_factura']) {
                                                                    echo 'Factura: ' . htmlspecialchars($movimiento['referencia_factura']);
                                                                } else {
                                                                    echo 'N/A';
                                                                }
                                                            ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($movimiento['origen_movimiento']); ?></td>
                                                        <td class="text-end">
                                                            <span class="badge bg-info fs-6"><?php echo number_format($movimiento['stock_despues'], 0, ',', '.'); ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-center">No hay movimientos registrados para este producto.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="index.php" class="btn btn-secondary"><i class="bi bi-house"></i> Volver al Inicio</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
