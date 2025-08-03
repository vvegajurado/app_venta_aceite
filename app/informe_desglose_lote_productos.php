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
$desglose_productos_lote = [];
$nombre_lote_desglose = 'Lote Desconocido';
$id_lote_para_desglose = null;

if (isset($_GET['lote_id']) && is_numeric($_GET['lote_id'])) {
    $id_lote_para_desglose = $_GET['lote_id'];

    try {
        // Obtener el nombre del lote para el título
        $stmt_lote_name = $pdo->prepare("SELECT nombre_lote FROM lotes_envasado WHERE id_lote_envasado = ?");
        $stmt_lote_name->execute([$id_lote_para_desglose]);
        $lote_info = $stmt_lote_name->fetch(PDO::FETCH_ASSOC);
        $nombre_lote_desglose = $lote_info ? $lote_info['nombre_lote'] : 'Lote Desconocido';

        // Consulta para el desglose por producto dentro del lote
        // Se selecciona id_detalle_actividad para pasarlo a los nuevos formularios
        $sql_desglose = "SELECT
                            dae.id_detalle_actividad,
                            p.nombre_producto,
                            dae.unidades_envasadas,
                            dae.litros_envasados,
                            COALESCE(SUM(alv.unidades_asignadas), 0) AS unidades_vendidas_detalle
                        FROM
                            detalle_actividad_envasado dae
                        JOIN
                            productos p ON dae.id_producto = p.id_producto
                        LEFT JOIN
                            asignacion_lotes_ventas alv ON dae.id_detalle_actividad = alv.id_detalle_actividad
                        WHERE
                            dae.id_lote_envasado = ?
                        GROUP BY
                            dae.id_detalle_actividad, p.nombre_producto, dae.unidades_envasadas, dae.litros_envasados
                        ORDER BY
                            p.nombre_producto ASC";

        $stmt_desglose = $pdo->prepare($sql_desglose);
        $stmt_desglose->execute([$id_lote_para_desglose]);
        $desglose_productos_lote = $stmt_desglose->fetchAll(PDO::FETCH_ASSOC);

        // Calcular existencias para cada detalle
        foreach ($desglose_productos_lote as &$detalle) {
            $litros_por_unidad_detalle = ($detalle['unidades_envasadas'] > 0) ? (float)$detalle['litros_envasados'] / (float)$detalle['unidades_envasadas'] : 0;

            $detalle['litros_vendidos_detalle'] = (float)$detalle['unidades_vendidas_detalle'] * $litros_por_unidad_detalle;
            $detalle['existencias_litros_detalle'] = (float)$detalle['litros_envasados'] - (float)$detalle['litros_vendidos_detalle'];
        }
        unset($detalle); // Romper la referencia

    } catch (PDOException $e) {
        $mensaje = "Error de base de datos al obtener el desglose: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    } catch (Exception $e) {
        $mensaje = "Error general al obtener el desglose: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
} else {
    $mensaje = "ID de lote no proporcionado o inválido.";
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
    <title>Desglose de Lote: <?php echo htmlspecialchars($nombre_lote_desglose); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Estilos generales (copia de informe_movimientos_lote.php para consistencia) */
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
            background-color: #17a2b8; /* Color info de Bootstrap para el modal/desglose */
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
            text-align: right; /* Alineación por defecto para números */
        }
        .table th:first-child, .table td:first-child {
            text-align: left; /* Alinear a la izquierda la primera columna (Producto) */
        }
        .table tfoot tr {
            background-color: #e0f7fa; /* Color para totales del desglose */
            font-weight: bold;
            border-top: 2px solid #17a2b8;
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
                    <h1>Desglose de Lote: <?php echo htmlspecialchars($nombre_lote_desglose); ?></h1>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card table-section-card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Detalle de Productos Envasados en este Lote</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($desglose_productos_lote)): ?>
                            <p class="text-center">No hay desglose disponible para este lote.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th class="text-end">Envasado (L)</th>
                                            <th class="text-end">Vendido (L)</th>
                                            <th class="text-end">Existencias (L)</th>
                                            <th class="text-center">Acciones</th> <!-- Nueva columna de acciones -->
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $total_litros_envasados = 0;
                                        $total_litros_vendidos = 0;
                                        $total_existencias_litros = 0;
                                        ?>
                                        <?php foreach ($desglose_productos_lote as $detalle): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($detalle['nombre_producto']); ?></td>
                                                <td class="text-end"><?php echo number_format($detalle['litros_envasados'], 2, ',', '.'); ?> L</td>
                                                <td class="text-end"><?php echo number_format($detalle['litros_vendidos_detalle'], 2, ',', '.'); ?> L</td>
                                                <td class="text-end"><?php echo number_format($detalle['existencias_litros_detalle'], 2, ',', '.'); ?> L</td>
                                                <td class="text-center">
                                                    <a href="informe_detalle_envasado.php?id_detalle_actividad=<?php echo htmlspecialchars($detalle['id_detalle_actividad']); ?>&lote_id=<?php echo htmlspecialchars($id_lote_para_desglose); ?>" class="btn btn-sm btn-primary mb-1">
                                                        <i class="bi bi-box-seam"></i> Detalle Envasado
                                                    </a>
                                                    <a href="informe_ventas_producto_lote.php?id_detalle_actividad=<?php echo htmlspecialchars($detalle['id_detalle_actividad']); ?>&lote_id=<?php echo htmlspecialchars($id_lote_para_desglose); ?>" class="btn btn-sm btn-success mb-1">
                                                        <i class="bi bi-receipt"></i> Ver Ventas
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                            $total_litros_envasados += $detalle['litros_envasados'];
                                            $total_litros_vendidos += $detalle['litros_vendidos_detalle'];
                                            $total_existencias_litros += $detalle['existencias_litros_detalle'];
                                            ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td><strong>TOTAL LOTE:</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_litros_envasados, 2, ',', '.'); ?> L</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_litros_vendidos, 2, ',', '.'); ?> L</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_existencias_litros, 2, ',', '.'); ?> L</strong></td>
                                            <td></td> <!-- Columna vacía para las acciones en el total -->
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="informe_movimientos_lote.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle"></i> Volver al Informe de Lotes</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
