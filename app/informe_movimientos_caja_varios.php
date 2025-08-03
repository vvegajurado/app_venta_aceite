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
$movimientos_caja = [];
$total_ingresos_filtrados = 0;
$total_pagos_filtrados = 0;

// Obtener parámetros de filtro de la URL
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$tipo_movimiento_filtro = isset($_GET['tipo_movimiento_filtro']) ? $_GET['tipo_movimiento_filtro'] : '';
$descripcion_filtro = isset($_GET['descripcion_filtro']) ? $_GET['descripcion_filtro'] : '';

try {
    // Construir la consulta SQL base
    $sql = "SELECT * FROM movimientos_caja_varios WHERE 1=1";
    $params = [];

    // Aplicar filtros
    if (!empty($fecha_inicio)) {
        $sql .= " AND fecha_movimiento >= ?";
        $params[] = $fecha_inicio;
    }
    if (!empty($fecha_fin)) {
        $sql .= " AND fecha_movimiento <= ?";
        $params[] = $fecha_fin;
    }
    if (!empty($tipo_movimiento_filtro) && ($tipo_movimiento_filtro == 'ingreso' || $tipo_movimiento_filtro == 'pago')) {
        $sql .= " AND tipo_movimiento = ?";
        $params[] = $tipo_movimiento_filtro;
    }
    if (!empty($descripcion_filtro)) {
        $sql .= " AND descripcion LIKE ?";
        $params[] = '%' . $descripcion_filtro . '%';
    }

    // Ordenar resultados
    $sql .= " ORDER BY fecha_movimiento DESC, fecha_registro DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $movimientos_caja = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular totales filtrados
    foreach ($movimientos_caja as $movimiento) {
        if ($movimiento['tipo_movimiento'] == 'ingreso') {
            $total_ingresos_filtrados += (float)$movimiento['cantidad'];
        } else {
            $total_pagos_filtrados += (float)$movimiento['cantidad'];
        }
    }

} catch (PDOException $e) {
    $mensaje = "Error al cargar los movimientos de caja: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Movimientos de Caja Varios</title>
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
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            border-radius: 8px;
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #bd2130;
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
        .table .text-end {
            text-align: right !important;
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
        .table tfoot tr {
            background-color: #cceeff;
            font-weight: bold;
            border-top: 3px solid #004494;
        }
        .form-control-date {
            width: auto;
            display: inline-block;
        }

        /* No se incluyen los estilos del sidebar aquí, ya que se cargarán desde sidebar.php */
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluir la barra lateral externa ?>

        <div class="main-content">
            <div class="container">
                <div class="header">
                    <h1>Informe de Movimientos de Caja Varios</h1>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Filtros de Movimientos</h5>
                    </div>
                    <div class="card-body">
                        <form action="informe_movimientos_caja_varios.php" method="GET" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio:</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_fin" class="form-label">Fecha Fin:</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="tipo_movimiento_filtro" class="form-label">Tipo de Movimiento:</label>
                                <select class="form-select" id="tipo_movimiento_filtro" name="tipo_movimiento_filtro">
                                    <option value="">Todos</option>
                                    <option value="ingreso" <?php echo ($tipo_movimiento_filtro == 'ingreso') ? 'selected' : ''; ?>>Ingreso</option>
                                    <option value="pago" <?php echo ($tipo_movimiento_filtro == 'pago') ? 'selected' : ''; ?>>Pago</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="descripcion_filtro" class="form-label">Descripción:</label>
                                <input type="text" class="form-control" id="descripcion_filtro" name="descripcion_filtro" value="<?php echo htmlspecialchars($descripcion_filtro); ?>" placeholder="Buscar en descripción">
                            </div>
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Aplicar Filtros</button>
                                <a href="informe_movimientos_caja_varios.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Limpiar Filtros</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card table-section-card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Resultados de Movimientos de Caja Varios</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($movimientos_caja)): ?>
                            <p class="text-center">No se encontraron movimientos de caja varios con los filtros aplicados.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Tipo</th>
                                            <th>Descripción</th>
                                            <th class="text-end">Cantidad (€)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($movimientos_caja as $movimiento): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($movimiento['fecha_movimiento']); ?></td>
                                                <td>
                                                    <?php if ($movimiento['tipo_movimiento'] == 'ingreso'): ?>
                                                        <span class="badge bg-success">Ingreso</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Pago</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($movimiento['descripcion'] ?: 'N/A'); ?></td>
                                                <td class="text-end"><?php echo number_format($movimiento['cantidad'], 2, ',', '.'); ?> €</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3"><strong>Total Ingresos Filtrados:</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_ingresos_filtrados, 2, ',', '.'); ?> €</strong></td>
                                        </tr>
                                        <tr>
                                            <td colspan="3"><strong>Total Pagos Filtrados:</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_pagos_filtrados, 2, ',', '.'); ?> €</strong></td>
                                        </tr>
                                        <tr>
                                            <td colspan="3"><strong>Saldo Neto Filtrado:</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_ingresos_filtrados - $total_pagos_filtrados, 2, ',', '.'); ?> €</strong></td>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
