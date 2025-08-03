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
$ventas_data = [];
$lote_id_origen = null; // Para el botón de volver
$nombre_producto_envasado = 'Producto';
$nombre_lote_envasado = 'Lote';

if (isset($_GET['id_detalle_actividad']) && is_numeric($_GET['id_detalle_actividad'])) {
    $id_detalle_actividad = $_GET['id_detalle_actividad'];
    $lote_id_origen = isset($_GET['lote_id']) ? htmlspecialchars($_GET['lote_id']) : null;

    try {
        // Obtener información del producto y lote del detalle de actividad de envasado
        $stmt_info_envasado = $pdo->prepare("SELECT
                                                p.nombre_producto,
                                                le.nombre_lote
                                            FROM
                                                detalle_actividad_envasado dae
                                            JOIN
                                                productos p ON dae.id_producto = p.id_producto
                                            JOIN
                                                lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
                                            WHERE
                                                dae.id_detalle_actividad = ?");
        $stmt_info_envasado->execute([$id_detalle_actividad]);
        $info_envasado = $stmt_info_envasado->fetch(PDO::FETCH_ASSOC);

        if ($info_envasado) {
            $nombre_producto_envasado = $info_envasado['nombre_producto'];
            $nombre_lote_envasado = $info_envasado['nombre_lote'];
        }

        // Consulta para obtener las ventas asociadas a este detalle de actividad de envasado
        $sql = "SELECT
                    fv.id_factura,
                    fv.fecha_factura,
                    fv.total_factura,
                    c.nombre_cliente,
                    c.nif_cif,
                    dfv.unidades_retiradas,
                    (dfv.unidades_retiradas * (CASE WHEN dae.unidades_envasadas > 0 THEN dae.litros_envasados / dae.unidades_envasadas ELSE 0 END)) AS litros_vendidos_linea,
                    dfv.precio_unitario,
                    dfv.subtotal AS subtotal_linea
                FROM
                    asignacion_lotes_ventas alv
                JOIN
                    detalle_factura_ventas dfv ON alv.id_detalle_factura = dfv.id_detalle_factura -- Corregido de alv.id_detalle_factura_venta
                JOIN
                    facturas_ventas fv ON dfv.id_factura = fv.id_factura
                JOIN
                    clientes c ON fv.id_cliente = c.id_cliente
                JOIN
                    detalle_actividad_envasado dae ON alv.id_detalle_actividad = dae.id_detalle_actividad
                WHERE
                    alv.id_detalle_actividad = ?
                ORDER BY
                    fv.fecha_factura DESC, fv.id_factura DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_detalle_actividad]);
        $ventas_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($ventas_data)) {
            $mensaje = "No se encontraron ventas para este detalle de envasado.";
            $tipo_mensaje = 'info';
        }

    } catch (PDOException $e) {
        $mensaje = "Error de base de datos al cargar las ventas: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    } catch (Exception $e) {
        $mensaje = "Error general al cargar las ventas: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
} else {
    $mensaje = "ID de detalle de actividad de envasado no proporcionado o inválido.";
    $tipo_mensaje = 'warning';
}

// Cierra la conexión PDO al final del script
$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas de <?php echo htmlspecialchars($nombre_producto_envasado); ?> (Lote: <?php echo htmlspecialchars($nombre_lote_envasado); ?>)</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Estilos generales (copia de formularios anteriores) */
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
                    <h1>Ventas de <?php echo htmlspecialchars($nombre_producto_envasado); ?> (Lote: <?php echo htmlspecialchars($nombre_lote_envasado); ?>)</h1>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card table-section-card mt-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Detalle de Ventas Asociadas</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ventas_data)): ?>
                            <p class="text-center">No se encontraron ventas asociadas a este detalle de envasado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th>ID Factura</th>
                                            <th>Fecha Factura</th>
                                            <th>Cliente</th>
                                            <th>NIF/CIF</th>
                                            <th class="text-end">Unidades Vendidas</th>
                                            <th class="text-end">Litros Vendidos</th>
                                            <th class="text-end">Precio Unitario</th>
                                            <th class="text-end">Subtotal Línea</th>
                                            <th class="text-end">Total Factura</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $total_unidades_vendidas = 0;
                                        $total_litros_vendidos = 0;
                                        $total_subtotal_linea = 0;
                                        ?>
                                        <?php foreach ($ventas_data as $venta): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($venta['id_factura']); ?></td>
                                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($venta['fecha_factura']))); ?></td>
                                                <td><?php echo htmlspecialchars($venta['nombre_cliente']); ?></td>
                                                <td><?php echo htmlspecialchars($venta['nif_cif']); ?></td>
                                                <td class="text-end"><?php echo number_format($venta['unidades_retiradas']); ?> ud.</td>
                                                <td class="text-end"><?php echo number_format($venta['litros_vendidos_linea'], 2, ',', '.'); ?> L</td>
                                                <td class="text-end"><?php echo number_format($venta['precio_unitario'], 2, ',', '.'); ?> €</td>
                                                <td class="text-end"><?php echo number_format($venta['subtotal_linea'], 2, ',', '.'); ?> €</td>
                                                <td class="text-end"><?php echo number_format($venta['total_factura'], 2, ',', '.'); ?> €</td>
                                            </tr>
                                            <?php
                                            $total_unidades_vendidas += $venta['unidades_retiradas'];
                                            $total_litros_vendidos += $venta['litros_vendidos_linea'];
                                            $total_subtotal_linea += $venta['subtotal_linea'];
                                            ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="4"><strong>TOTALES PARA ESTE DETALLE DE ENVASADO:</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_unidades_vendidas); ?> ud.</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_litros_vendidos, 2, ',', '.'); ?> L</strong></td>
                                            <td></td>
                                            <td class="text-end"><strong><?php echo number_format($total_subtotal_linea, 2, ',', '.'); ?> €</strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <?php if ($lote_id_origen): ?>
                        <a href="informe_desglose_lote_productos.php?lote_id=<?php echo htmlspecialchars($lote_id_origen); ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-left-circle"></i> Volver al Desglose del Lote
                        </a>
                    <?php else: ?>
                        <a href="informe_movimientos_lote.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left-circle"></i> Volver al Informe de Lotes
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
