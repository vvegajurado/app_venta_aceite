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
$detalle_envasado_data = null;
$lote_id_origen = null; // Para el botón de volver

if (isset($_GET['id_detalle_actividad']) && is_numeric($_GET['id_detalle_actividad'])) {
    $id_detalle_actividad = $_GET['id_detalle_actividad'];
    $lote_id_origen = isset($_GET['lote_id']) ? htmlspecialchars($_GET['lote_id']) : null;

    try {
        $sql = "SELECT
                    dae.id_detalle_actividad,
                    ae.fecha_envasado AS fecha_envasado, -- Corregido a ae.fecha_envasado
                    dae.unidades_envasadas,
                    dae.litros_envasados,
                    dae.consecutivo_sublote,
                    dae.observaciones,
                    p.nombre_producto,
                    p.litros_por_unidad AS litros_por_unidad_producto,
                    le.nombre_lote,
                    a.nombre_articulo,
                    d.nombre_deposito AS nombre_deposito_mezcla
                FROM
                    detalle_actividad_envasado dae
                JOIN
                    actividad_envasado ae ON dae.id_actividad_envasado = ae.id_actividad_envasado
                JOIN
                    productos p ON dae.id_producto = p.id_producto
                JOIN
                    lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
                JOIN
                    articulos a ON le.id_articulo = a.id_articulo
                LEFT JOIN
                    depositos d ON le.id_deposito_mezcla = d.id_deposito
                WHERE
                    dae.id_detalle_actividad = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_detalle_actividad]);
        $detalle_envasado_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$detalle_envasado_data) {
            $mensaje = "No se encontró el detalle de actividad de envasado.";
            $tipo_mensaje = 'warning';
        }

    } catch (PDOException $e) {
        $mensaje = "Error de base de datos al cargar el detalle: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    } catch (Exception $e) {
        $mensaje = "Error general al cargar el detalle: " . $e->getMessage();
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
    <title>Detalle de Actividad de Envasado</title>
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
                    <h1>Detalle de Actividad de Envasado</h1>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($detalle_envasado_data): ?>
                    <div class="card mt-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">Información del Detalle de Envasado (ID: <?php echo htmlspecialchars($detalle_envasado_data['id_detalle_actividad']); ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Fecha de Envasado:</strong> <?php echo htmlspecialchars(date('d/m/Y', strtotime($detalle_envasado_data['fecha_envasado']))); ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Producto Final:</strong> <?php echo htmlspecialchars($detalle_envasado_data['nombre_producto']); ?>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Lote de Envasado:</strong> <?php echo htmlspecialchars($detalle_envasado_data['nombre_lote']); ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Artículo Origen:</strong> <?php echo htmlspecialchars($detalle_envasado_data['nombre_articulo']); ?>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Depósito de Mezcla:</strong> <?php echo htmlspecialchars($detalle_envasado_data['nombre_deposito_mezcla']); ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Sublote:</strong> <?php echo htmlspecialchars($detalle_envasado_data['consecutivo_sublote']); ?>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Unidades Envasadas:</strong> <?php echo number_format($detalle_envasado_data['unidades_envasadas']); ?> ud.
                                </div>
                                <div class="col-md-6">
                                    <strong>Litros Envasados:</strong> <?php echo number_format($detalle_envasado_data['litros_envasados'], 2, ',', '.'); ?> L
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <strong>Litros por Unidad (Producto):</strong> <?php echo number_format($detalle_envasado_data['litros_por_unidad_producto'], 2, ',', '.'); ?> L/ud.
                                </div>
                            </div>
                            <?php if (!empty($detalle_envasado_data['observaciones'])): ?>
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <strong>Observaciones:</strong> <?php echo nl2br(htmlspecialchars($detalle_envasado_data['observaciones'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

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
