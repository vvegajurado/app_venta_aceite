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
$cobros_por_forma_pago = [];
$fecha_seleccionada = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
$desglose_cobros = []; // Para almacenar el desglose de cobros por forma de pago
$total_cobrado_contado = 0; // Para almacenar el total cobrado en efectivo
$cuadre_existente = null; // Para almacenar los datos del cuadre de caja si existe

// Nuevas variables para movimientos de caja varios
$ingresos_varios = [];
$pagos_varios = [];
$total_ingresos_varios = 0;
$total_pagos_varios = 0;

try {
    // --- Lógica para guardar/actualizar el cuadre de caja (si es una petición POST para cuadre) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cuadre') {
        header('Content-Type: application/json'); // Indicar que la respuesta será JSON

        $fecha_cuadre_post = $_POST['fecha_cuadre'] ?? '';
        $initial_denominations_json = $_POST['initial_denominations_json'] ?? '{}';
        $final_denominations_json = $_POST['final_denominations_json'] ?? '{}';
        $total_inicial_efectivo = (float)($_POST['total_inicial_efectivo'] ?? 0);
        $total_final_efectivo = (float)($_POST['total_final_efectivo'] ?? 0);
        $total_cobrado_contado_db = (float)($_POST['total_cobrado_contado_db'] ?? 0);
        $diferencia = (float)($_POST['diferencia'] ?? 0);
        $observaciones = $_POST['observaciones'] ?? null;

        if (empty($fecha_cuadre_post)) {
            echo json_encode(['success' => false, 'message' => 'Fecha de cuadre no proporcionada.']);
            exit;
        }

        // Verificar si ya existe un cuadre para esta fecha
        $stmt_check = $pdo->prepare("SELECT id_cuadre FROM cuadres_caja WHERE fecha_cuadre = ?");
        $stmt_check->execute([$fecha_cuadre_post]);
        $existing_cuadre = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($existing_cuadre) {
            // Actualizar el cuadre existente
            $stmt_update = $pdo->prepare("
                UPDATE cuadres_caja
                SET
                    initial_denominations_json = ?,
                    final_denominations_json = ?,
                    total_inicial_efectivo = ?,
                    total_final_efectivo = ?,
                    total_cobrado_contado_db = ?,
                    diferencia = ?,
                    observaciones = ?,
                    fecha_ultima_actualizacion = NOW()
                WHERE id_cuadre = ?
            ");
            $success = $stmt_update->execute([
                $initial_denominations_json,
                $final_denominations_json,
                $total_inicial_efectivo,
                $total_final_efectivo,
                $total_cobrado_contado_db,
                $diferencia,
                $observaciones,
                $existing_cuadre['id_cuadre']
            ]);
            $action_message = "actualizado";
        } else {
            // Insertar un nuevo cuadre
            $stmt_insert = $pdo->prepare("
                INSERT INTO cuadres_caja (
                    fecha_cuadre,
                    initial_denominations_json,
                    final_denominations_json,
                    total_inicial_efectivo,
                    total_final_efectivo,
                    total_cobrado_contado_db,
                    diferencia,
                    observaciones
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $success = $stmt_insert->execute([
                $fecha_cuadre_post,
                $initial_denominations_json,
                $final_denominations_json,
                $total_inicial_efectivo,
                $total_final_efectivo,
                $total_cobrado_contado_db,
                $diferencia,
                $observaciones
            ]);
            $action_message = "guardado";
        }

        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Cuadre de caja ' . $action_message . ' correctamente.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al ' . $action_message . ' el cuadre de caja.']);
        }
        exit; // Terminar la ejecución del script después de la respuesta AJAX
    }

    // --- Lógica para guardar movimientos de caja varios (POST para ingresos/pagos) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_movimiento_caja') {
        header('Content-Type: application/json');

        $fecha_movimiento = $_POST['fecha_movimiento'] ?? '';
        $tipo_movimiento = $_POST['tipo_movimiento'] ?? '';
        $cantidad = (float)($_POST['cantidad'] ?? 0);
        $descripcion = $_POST['descripcion'] ?? null;

        if (empty($fecha_movimiento) || empty($tipo_movimiento) || $cantidad <= 0) {
            echo json_encode(['success' => false, 'message' => 'Datos de movimiento incompletos o inválidos.']);
            exit;
        }

        $stmt_insert_movimiento = $pdo->prepare("
            INSERT INTO movimientos_caja_varios (fecha_movimiento, tipo_movimiento, cantidad, descripcion)
            VALUES (?, ?, ?, ?)
        ");
        $success = $stmt_insert_movimiento->execute([$fecha_movimiento, $tipo_movimiento, $cantidad, $descripcion]);

        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Movimiento guardado correctamente.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al guardar el movimiento.']);
        }
        exit;
    }

    // --- Lógica para cargar datos al cargar la página (GET request) ---

    // Consulta para obtener el total de cobros por forma de pago para la fecha seleccionada
    $sql_cobros_totales = "
        SELECT
            fp.id_forma_pago,
            fp.nombre_forma_pago,
            SUM(cf.cantidad_cobrada) AS total_cobrado
        FROM
            cobros_factura cf
        JOIN
            formas_pago fp ON cf.id_forma_pago = fp.id_forma_pago
        WHERE
            cf.fecha_cobro = ?
        GROUP BY
            fp.id_forma_pago, fp.nombre_forma_pago
        ORDER BY
            fp.nombre_forma_pago ASC
    ";
    $stmt_cobros_totales = $pdo->prepare($sql_cobros_totales);
    $stmt_cobros_totales->execute([$fecha_seleccionada]);
    $cobros_por_forma_pago = $stmt_cobros_totales->fetchAll(PDO::FETCH_ASSOC);

    // Buscar el total cobrado en "Contado"
    foreach ($cobros_por_forma_pago as $cobro) {
        if ($cobro['nombre_forma_pago'] === 'Contado') {
            $total_cobrado_contado = (float)$cobro['total_cobrado'];
            break;
        }
    }

    // Consulta para obtener el desglose detallado de cobros para el modal
    $sql_desglose_completo = "
        SELECT
            cf.id_cobro,
            cf.id_factura,
            cf.fecha_cobro,
            cf.cantidad_cobrada,
            fp.id_forma_pago,
            fp.nombre_forma_pago,
            fv.id_cliente,
            c.nombre_cliente,
            fv.total_factura
        FROM
            cobros_factura cf
        JOIN
            formas_pago fp ON cf.id_forma_pago = fp.id_forma_pago
        JOIN
            facturas_ventas fv ON cf.id_factura = fv.id_factura
        JOIN
            clientes c ON fv.id_cliente = c.id_cliente
        WHERE
            cf.fecha_cobro = ?
        ORDER BY
            fp.nombre_forma_pago, c.nombre_cliente ASC
    ";
    $stmt_desglose_completo = $pdo->prepare($sql_desglose_completo);
    $stmt_desglose_completo->execute([$fecha_seleccionada]);
    $desglose_cobros_raw = $stmt_desglose_completo->fetchAll(PDO::FETCH_ASSOC);

    // Reorganizar el desglose para facilitar el acceso en JavaScript
    foreach ($desglose_cobros_raw as $cobro) {
        $id_forma_pago = $cobro['id_forma_pago'];
        if (!isset($desglose_cobros[$id_forma_pago])) {
            $desglose_cobros[$id_forma_pago] = [];
        }
        $desglose_cobros[$id_forma_pago][] = $cobro;
    }

    // Cargar datos de cuadre de caja si existen para la fecha seleccionada
    $stmt_load_cuadre = $pdo->prepare("SELECT * FROM cuadres_caja WHERE fecha_cuadre = ?");
    $stmt_load_cuadre->execute([$fecha_seleccionada]);
    $cuadre_existente = $stmt_load_cuadre->fetch(PDO::FETCH_ASSOC);

    // Cargar movimientos de caja varios para la fecha seleccionada
    $stmt_movimientos_varios = $pdo->prepare("SELECT * FROM movimientos_caja_varios WHERE fecha_movimiento = ? ORDER BY fecha_registro ASC");
    $stmt_movimientos_varios->execute([$fecha_seleccionada]);
    $movimientos_varios_raw = $stmt_movimientos_varios->fetchAll(PDO::FETCH_ASSOC);

    foreach ($movimientos_varios_raw as $mov) {
        if ($mov['tipo_movimiento'] === 'ingreso') {
            $ingresos_varios[] = $mov;
            $total_ingresos_varios += (float)$mov['cantidad'];
        } else if ($mov['tipo_movimiento'] === 'pago') {
            $pagos_varios[] = $mov;
            $total_pagos_varios += (float)$mov['cantidad'];
        }
    }

} catch (PDOException $e) {
    $mensaje = "Error al cargar el informe de cobros o movimientos de caja: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Cobros Diarios y Parte de Caja</title>
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
        .table tfoot tr {
            background-color: #cceeff;
            font-weight: bold;
            border-top: 3px solid #004494;
        }
        .form-control-date {
            width: auto;
            display: inline-block;
        }

        /* Estilos del Sidebar (tomados de sidebar.php) */
        :root {
            --sidebar-width: 250px;
            --primary-color: #007bff;
            --sidebar-bg: #343a40;
            --sidebar-link: #adb5bd;
            --sidebar-hover: #495057;
            --sidebar-active: #0056b3;
        }

        /* No se incluyen los estilos del sidebar aquí, ya que se cargarán desde sidebar.php */

        /* Estilos específicos para el Parte de Caja */
        .cash-denomination-row {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        .cash-denomination-row label {
            flex: 0 0 100px; /* Ancho fijo para la denominación */
            text-align: right;
            margin-right: 10px;
            font-weight: 500;
        }
        .cash-denomination-row input {
            flex-grow: 1;
            max-width: 120px;
            text-align: center;
        }
        .cash-denomination-row .total-denomination {
            flex: 0 0 120px; /* Ancho fijo para el total */
            margin-left: 10px;
            font-weight: bold;
            text-align: right;
        }
        .cash-summary-table td {
            text-align: right;
        }
        .cash-summary-table th {
            text-align: left;
        }
        #reconciliationResult {
            font-size: 1.2rem;
            font-weight: bold;
            margin-top: 15px;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }
        .result-ok {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .result-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        .result-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluir la barra lateral externa ?>

        <div class="main-content">
            <div class="container">
                <div class="header">
                    <h1>Informe de Cobros Diarios y Parte de Caja</h1>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Seleccionar Fecha</h5>
                    </div>
                    <div class="card-body">
                        <form action="informe_cobros_diarios.php" method="GET" class="row g-3 align-items-center">
                            <div class="col-auto">
                                <label for="fecha" class="col-form-label">Fecha:</label>
                            </div>
                            <div class="col-auto">
                                <input type="date" class="form-control form-control-date" id="fecha" name="fecha" value="<?php echo htmlspecialchars($fecha_seleccionada); ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Mostrar Cobros</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card table-section-card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Total de Cobros por Forma de Pago (<?php echo htmlspecialchars($fecha_seleccionada); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($cobros_por_forma_pago)): ?>
                            <p class="text-center">No hay cobros registrados para la fecha seleccionada.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th>Forma de Pago</th>
                                            <th class="text-end">Total Cobrado (€)</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $gran_total_cobrado = 0;
                                        foreach ($cobros_por_forma_pago as $cobro):
                                            $gran_total_cobrado += $cobro['total_cobrado'];
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($cobro['nombre_forma_pago']); ?></td>
                                                <td class="text-end"><?php echo number_format($cobro['total_cobrado'], 2, ',', '.'); ?> €</td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info"
                                                            data-bs-toggle="modal" data-bs-target="#desgloseCobrosModal"
                                                            data-id-forma-pago="<?php echo htmlspecialchars($cobro['id_forma_pago']); ?>"
                                                            data-nombre-forma-pago="<?php echo htmlspecialchars($cobro['nombre_forma_pago']); ?>">
                                                        <i class="bi bi-eye"></i> Ver Desglose
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td><strong>GRAN TOTAL DIARIO:</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($gran_total_cobrado, 2, ',', '.'); ?> €</strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sección de Ingresos Varios -->
                <div class="card mt-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Ingresos Varios (<?php echo htmlspecialchars($fecha_seleccionada); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <form id="formIngresoVario" class="row g-3 align-items-end mb-4">
                            <input type="hidden" name="fecha_movimiento" value="<?php echo htmlspecialchars($fecha_seleccionada); ?>">
                            <input type="hidden" name="tipo_movimiento" value="ingreso">
                            <div class="col-md-4">
                                <label for="ingresoCantidad" class="form-label">Cantidad (€)</label>
                                <input type="number" class="form-control" id="ingresoCantidad" name="cantidad" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="ingresoDescripcion" class="form-label">Descripción</label>
                                <input type="text" class="form-control" id="ingresoDescripcion" name="descripcion" maxlength="255">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-circle"></i> Añadir Ingreso</button>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>Descripción</th>
                                        <th class="text-end">Cantidad (€)</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBodyIngresosVarios">
                                    <?php if (empty($ingresos_varios)): ?>
                                        <tr><td colspan="2" class="text-center">No hay ingresos varios registrados para esta fecha.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($ingresos_varios as $ingreso): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ingreso['descripcion']); ?></td>
                                                <td class="text-end"><?php echo number_format($ingreso['cantidad'], 2, ',', '.'); ?> €</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td><strong>Total Ingresos Varios:</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_ingresos_varios, 2, ',', '.'); ?> €</strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Sección de Pagos Varios -->
                <div class="card mt-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">Pagos Varios (<?php echo htmlspecialchars($fecha_seleccionada); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <form id="formPagoVario" class="row g-3 align-items-end mb-4">
                            <input type="hidden" name="fecha_movimiento" value="<?php echo htmlspecialchars($fecha_seleccionada); ?>">
                            <input type="hidden" name="tipo_movimiento" value="pago">
                            <div class="col-md-4">
                                <label for="pagoCantidad" class="form-label">Cantidad (€)</label>
                                <input type="number" class="form-control" id="pagoCantidad" name="cantidad" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="pagoDescripcion" class="form-label">Descripción</label>
                                <input type="text" class="form-control" id="pagoDescripcion" name="descripcion" maxlength="255">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-danger w-100"><i class="bi bi-dash-circle"></i> Añadir Pago</button>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>Descripción</th>
                                        <th class="text-end">Cantidad (€)</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBodyPagosVarios">
                                    <?php if (empty($pagos_varios)): ?>
                                        <tr><td colspan="2" class="text-center">No hay pagos varios registrados para esta fecha.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($pagos_varios as $pago): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($pago['descripcion']); ?></td>
                                                <td class="text-end"><?php echo number_format($pago['cantidad'], 2, ',', '.'); ?> €</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td><strong>Total Pagos Varios:</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_pagos_varios, 2, ',', '.'); ?> €</strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Sección de Parte de Caja -->
                <div class="card mt-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Parte de Caja (Efectivo)</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Recuento Inicial del Día</h6>
                                <div id="initialCashInputs">
                                    <div class="cash-denomination-row">
                                        <label>500 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-bill" data-value="500" value="0" min="0">
                                        <span class="total-denomination" id="initial-total-500">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>200 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-bill" data-value="200" value="0" min="0">
                                        <span class="total-denomination" id="initial-total-200">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>100 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-bill" data-value="100" value="0" min="0">
                                        <span class="total-denomination" id="initial-total-100">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>50 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-bill" data-value="50" value="0" min="0">
                                        <span class="total-denomination" id="initial-total-50">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>20 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-bill" data-value="20" value="0" min="0">
                                        <span class="total-denomination" id="initial-total-20">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>10 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-bill" data-value="10" value="0" min="0">
                                        <span class="total-denomination" id="initial-total-10">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>5 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-bill" data-value="5" value="0" min="0">
                                        <span class="total-denomination" id="initial-total-5">0.00 €</span>
                                    </div>
                                    <hr>
                                    <div class="cash-denomination-row">
                                        <label>2 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-coin" data-value="2" value="0" min="0">
                                        <span class="total-denomination" id="initial-total-2">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>1 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-coin" data-value="1" value="0" min="0">
                                        <span class="total-denomination" id="initial-total-1">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.50 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-coin" data-value="0.50" value="0" min="0" step="1">
                                        <span class="total-denomination" id="initial-total-0-50">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.20 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-coin" data-value="0.20" value="0" min="0" step="1">
                                        <span class="total-denomination" id="initial-total-0-20">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.10 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-coin" data-value="0.10" value="0" min="0" step="1">
                                        <span class="total-denomination" id="initial-total-0-10">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.05 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-coin" data-value="0.05" value="0" min="0" step="1">
                                        <span class="total-denomination" id="initial-total-0-05">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.02 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-coin" data-value="0.02" value="0" min="0" step="1">
                                        <span class="total-denomination" id="initial-total-0-02">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.01 €:</label>
                                        <input type="number" class="form-control form-control-sm initial-coin" data-value="0.01" value="0" min="0" step="1">
                                        <span class="total-denomination" id="initial-total-0-01">0.00 €</span>
                                    </div>
                                </div>
                                <table class="table table-sm cash-summary-table mt-3">
                                    <tbody>
                                        <tr>
                                            <th>Total Inicial Efectivo:</th>
                                            <td id="totalInitialCash">0.00 €</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Recuento Final del Día</h6>
                                <div id="finalCashInputs">
                                    <div class="cash-denomination-row">
                                        <label>500 €:</label>
                                        <input type="number" class="form-control form-control-sm final-bill" data-value="500" value="0" min="0">
                                        <span class="total-denomination" id="final-total-500">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>200 €:</label>
                                        <input type="number" class="form-control form-control-sm final-bill" data-value="200" value="0" min="0">
                                        <span class="total-denomination" id="final-total-200">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>100 €:</label>
                                        <input type="number" class="form-control form-control-sm final-bill" data-value="100" value="0" min="0">
                                        <span class="total-denomination" id="final-total-100">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>50 €:</label>
                                        <input type="number" class="form-control form-control-sm final-bill" data-value="50" value="0" min="0">
                                        <span class="total-denomination" id="final-total-50">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>20 €:</label>
                                        <input type="number" class="form-control form-control-sm final-bill" data-value="20" value="0" min="0">
                                        <span class="total-denomination" id="final-total-20">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>10 €:</label>
                                        <input type="number" class="form-control form-control-sm final-bill" data-value="10" value="0" min="0">
                                        <span class="total-denomination" id="final-total-10">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>5 €:</label>
                                        <input type="number" class="form-control form-control-sm final-bill" data-value="5" value="0" min="0">
                                        <span class="total-denomination" id="final-total-5">0.00 €</span>
                                    </div>
                                    <hr>
                                    <div class="cash-denomination-row">
                                        <label>2 €:</label>
                                        <input type="number" class="form-control form-control-sm final-coin" data-value="2" value="0" min="0">
                                        <span class="total-denomination" id="final-total-2">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>1 €:</label>
                                        <input type="number" class="form-control form-control-sm final-coin" data-value="1" value="0" min="0">
                                        <span class="total-denomination" id="final-total-1">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.50 €:</label>
                                        <input type="number" class="form-control form-control-sm final-coin" data-value="0.50" value="0" min="0" step="1">
                                        <span class="total-denomination" id="final-total-0-50">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.20 €:</label>
                                        <input type="number" class="form-control form-control-sm final-coin" data-value="0.20" value="0" min="0" step="1">
                                        <span class="total-denomination" id="final-total-0-20">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.10 €:</label>
                                        <input type="number" class="form-control form-control-sm final-coin" data-value="0.10" value="0" min="0" step="1">
                                        <span class="total-denomination" id="final-total-0-10">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.05 €:</label>
                                        <input type="number" class="form-control form-control-sm final-coin" data-value="0.05" value="0" min="0" step="1">
                                        <span class="total-denomination" id="final-total-0-05">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.02 €:</label>
                                        <input type="number" class="form-control form-control-sm final-coin" data-value="0.02" value="0" min="0" step="1">
                                        <span class="total-denomination" id="final-total-0-02">0.00 €</span>
                                    </div>
                                    <div class="cash-denomination-row">
                                        <label>0.01 €:</label>
                                        <input type="number" class="form-control form-control-sm final-coin" data-value="0.01" value="0" min="0" step="1">
                                        <span class="total-denomination" id="final-total-0-01">0.00 €</span>
                                    </div>
                                </div>
                                <table class="table table-sm cash-summary-table mt-3">
                                    <tbody>
                                        <tr>
                                            <th>Total Final Efectivo:</th>
                                            <td id="totalFinalCash">0.00 €</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="observacionesCuadre" class="form-label">Observaciones (opcional):</label>
                            <textarea class="form-control" id="observacionesCuadre" rows="3"><?php echo htmlspecialchars($cuadre_existente['observaciones'] ?? ''); ?></textarea>
                        </div>

                        <!-- Nuevos campos de resumen -->
                        <div class="row mt-3">
                            <div class="col-md-6 offset-md-6">
                                <table class="table table-sm cash-summary-table">
                                    <tbody>
                                        <tr>
                                            <th>Total Contado según Recuento:</th>
                                            <td id="totalRecuentoContado">0.00 €</td>
                                        </tr>
                                        <tr>
                                            <th>Total Cobrado (Sistema):</th>
                                            <td id="totalCobradoSistema"><?php echo number_format($total_cobrado_contado, 2, ',', '.'); ?> €</td>
                                        </tr>
                                        <tr>
                                            <th>Total Ingresos Varios:</th>
                                            <td id="totalIngresosVariosDisplay"><?php echo number_format($total_ingresos_varios, 2, ',', '.'); ?> €</td>
                                        </tr>
                                        <tr>
                                            <th>Total Pagos Varios:</th>
                                            <td id="totalPagosVariosDisplay"><?php echo number_format($total_pagos_varios, 2, ',', '.'); ?> €</td>
                                        </tr>
                                        <tr>
                                            <th>Diferencia con Cobros:</th>
                                            <td id="diferenciaCobros">0.00 €</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <!-- Fin de nuevos campos de resumen -->

                        <div class="text-center mt-4">
                            <button type="button" class="btn btn-success" id="reconcileCashBtn">Cuadrar Caja</button>
                        </div>
                        <div id="reconciliationResult" class="mt-3 d-none"></div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="index.php" class="btn btn-secondary"><i class="bi bi-house"></i> Volver al Inicio</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para el Desglose de Cobros -->
    <div class="modal fade" id="desgloseCobrosModal" tabindex="-1" aria-labelledby="desgloseCobrosModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="desgloseCobrosModalLabel">Desglose de Cobros por Forma de Pago</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Detalle de cobros para: <strong id="modalFormaPagoNombre"></strong></p>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>ID Factura</th>
                                    <th>Cliente</th>
                                    <th class="text-end">Cantidad Cobrada (€)</th>
                                </tr>
                            </thead>
                            <tbody id="modalTableBodyDesglose">
                                <!-- Contenido dinámico aquí -->
                            </tbody>
                            <tfoot id="modalTableFooterDesglose">
                                <!-- Total dinámico aquí -->
                            </tfoot>
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
        const desgloseCobrosData = <?php echo json_encode($desglose_cobros); ?>;
        let totalCobradoContado = <?php echo json_encode($total_cobrado_contado); ?>; // Cambiado a let
        const fechaSeleccionada = '<?php echo htmlspecialchars($fecha_seleccionada); ?>';
        const cuadreExistente = <?php echo json_encode($cuadre_existente); ?>;
        let ingresosVariosData = <?php echo json_encode($ingresos_varios); ?>; // Cambiado a let
        let pagosVariosData = <?php echo json_encode($pagos_varios); ?>; // Cambiado a let
        let totalIngresosVarios = <?php echo json_encode($total_ingresos_varios); ?>; // Cambiado a let
        let totalPagosVarios = <?php echo json_encode($total_pagos_varios); ?>; // Cambiado a let


        // Lógica para el modal de desglose de cobros
        const desgloseModal = document.getElementById('desgloseCobrosModal');
        desgloseModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const idFormaPago = button.getAttribute('data-id-forma-pago');
            const nombreFormaPago = button.getAttribute('data-nombre-forma-pago');

            const modalTitle = desgloseModal.querySelector('#desgloseCobrosModalLabel');
            const modalFormaPagoNombre = desgloseModal.querySelector('#modalFormaPagoNombre');
            const modalTableBody = desgloseModal.querySelector('#modalTableBodyDesglose');
            const modalTableFooter = desgloseModal.querySelector('#modalTableFooterDesglose');

            modalFormaPagoNombre.textContent = nombreFormaPago;
            modalTableBody.innerHTML = '';
            modalTableFooter.innerHTML = '';

            const cobrosDetalle = desgloseCobrosData[idFormaPago];
            let totalModalCobrado = 0;

            if (cobrosDetalle && cobrosDetalle.length > 0) {
                cobrosDetalle.forEach(item => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${item.id_factura}</td>
                        <td>${item.nombre_cliente}</td>
                        <td class="text-end">${parseFloat(item.cantidad_cobrada).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €</td>
                    `;
                    modalTableBody.appendChild(row);
                    totalModalCobrado += parseFloat(item.cantidad_cobrada);
                });
                modalTableFooter.innerHTML = `
                    <tr>
                        <td colspan="2"><strong>TOTAL PARA ${nombreFormaPago.toUpperCase()}:</strong></td>
                        <td class="text-end"><strong>${totalModalCobrado.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €</strong></td>
                    </tr>
                `;
            } else {
                modalTableBody.innerHTML = '<tr><td colspan="3" class="text-center">No se encontraron detalles de cobro para esta forma de pago.</td></tr>';
            }
        });

        // Lógica para el Parte de Caja
        const denominations = [500, 200, 100, 50, 20, 10, 5, 2, 1, 0.50, 0.20, 0.10, 0.05, 0.02, 0.01];

        function calculateTotal(prefix) {
            let total = 0;
            const inputs = document.querySelectorAll(`input.${prefix}-bill, input.${prefix}-coin`);

            inputs.forEach(input => {
                const value = parseFloat(input.dataset.value);
                const count = parseFloat(input.value) || 0;
                const denominationTotal = count * value;
                
                // Usar input.dataset.value directamente para construir el ID del span
                const spanId = `${prefix}-total-${input.dataset.value.replace('.', '-')}`;
                const spanElement = document.getElementById(spanId);
                if (spanElement) {
                    spanElement.textContent = denominationTotal.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
                }
                total += denominationTotal;
            });
            return total;
        }

        function updateCashTotals() {
            const initialTotal = calculateTotal('initial');
            document.getElementById('totalInitialCash').textContent = initialTotal.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';

            const finalTotal = calculateTotal('final');
            document.getElementById('totalFinalCash').textContent = finalTotal.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';

            // Calcular el Total Contado según Recuento (el valor esperado en caja)
            const expectedFinalCash = initialTotal + totalCobradoContado + totalIngresosVarios - totalPagosVarios;
            document.getElementById('totalRecuentoContado').textContent = expectedFinalCash.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';

            // Calcular la Diferencia con Cobros (Recuento Final - Esperado)
            const difference = finalTotal - expectedFinalCash;
            document.getElementById('diferenciaCobros').textContent = difference.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            
            // Actualizar el color de la diferencia
            const diferenciaCobrosElement = document.getElementById('diferenciaCobros');
            diferenciaCobrosElement.classList.remove('text-success', 'text-danger');
            if (Math.abs(difference) < 0.01) {
                diferenciaCobrosElement.classList.add('text-success');
            } else {
                diferenciaCobrosElement.classList.add('text-danger');
            }
        }

        // Función para cargar los datos del cuadre existente
        function loadExistingCuadre() {
            if (cuadreExistente) {
                try {
                    const initialDenominations = JSON.parse(cuadreExistente.initial_denominations_json);
                    const finalDenominations = JSON.parse(cuadreExistente.final_denominations_json);

                    // Iterar directamente sobre todos los elementos de entrada de efectivo
                    document.querySelectorAll('input[data-value]').forEach(input => {
                        const dataValue = input.dataset.value; // Por ejemplo, "0.50", "500"
                        const prefix = input.classList.contains('initial-bill') || input.classList.contains('initial-coin') ? 'initial' : 'final';

                        if (prefix === 'initial' && initialDenominations[dataValue] !== undefined) {
                            input.value = initialDenominations[dataValue];
                        } else if (prefix === 'final' && finalDenominations[dataValue] !== undefined) {
                            input.value = finalDenominations[dataValue];
                        }
                    });

                    document.getElementById('observacionesCuadre').value = cuadreExistente.observaciones || '';
                    updateCashTotals(); // Recalcular y mostrar los totales
                } catch (e) {
                    console.error("Error parsing existing cuadre JSON:", e);
                }
            }
        }

        // Función para renderizar la tabla de ingresos varios
        function renderIngresosVariosTable() {
            const tableBody = document.getElementById('tableBodyIngresosVarios');
            tableBody.innerHTML = '';
            if (ingresosVariosData.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="2" class="text-center">No hay ingresos varios registrados para esta fecha.</td></tr>';
            } else {
                ingresosVariosData.forEach(item => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${item.descripcion || 'Sin descripción'}</td>
                        <td class="text-end">${parseFloat(item.cantidad).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €</td>
                    `;
                    tableBody.appendChild(row);
                });
            }
            document.getElementById('totalIngresosVariosDisplay').textContent = totalIngresosVarios.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        }

        // Función para renderizar la tabla de pagos varios
        function renderPagosVariosTable() {
            const tableBody = document.getElementById('tableBodyPagosVarios');
            tableBody.innerHTML = '';
            if (pagosVariosData.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="2" class="text-center">No hay pagos varios registrados para esta fecha.</td></tr>';
            } else {
                pagosVariosData.forEach(item => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${item.descripcion || 'Sin descripción'}</td>
                        <td class="text-end">${parseFloat(item.cantidad).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €</td>
                    `;
                    tableBody.appendChild(row);
                });
            }
            document.getElementById('totalPagosVariosDisplay').textContent = totalPagosVarios.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        }


        // Event listeners para los campos de entrada de efectivo
        document.querySelectorAll('.initial-bill, .initial-coin, .final-bill, .final-coin').forEach(input => {
            input.addEventListener('input', updateCashTotals);
        });

        // Manejo del formulario de Ingresos Varios
        document.getElementById('formIngresoVario').addEventListener('submit', async function(event) {
            event.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'save_movimiento_caja');

            try {
                const response = await fetch('informe_cobros_diarios.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    // Recargar la página para obtener los datos actualizados, incluyendo los nuevos movimientos
                    window.location.href = `informe_cobros_diarios.php?fecha=${fechaSeleccionada}`;
                } else {
                    alert('Error al añadir ingreso: ' + result.message);
                }
            } catch (error) {
                console.error('Error de conexión al añadir ingreso:', error);
                alert('Error de conexión al añadir ingreso.');
            }
        });

        // Manejo del formulario de Pagos Varios
        document.getElementById('formPagoVario').addEventListener('submit', async function(event) {
            event.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'save_movimiento_caja');

            try {
                const response = await fetch('informe_cobros_diarios.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    // Recargar la página para obtener los datos actualizados, incluyendo los nuevos movimientos
                    window.location.href = `informe_cobros_diarios.php?fecha=${fechaSeleccionada}`;
                } else {
                    alert('Error al añadir pago: ' + result.message);
                }
            } catch (error) {
                console.error('Error de conexión al añadir pago:', error);
                alert('Error de conexión al añadir pago.');
            }
        });

        // Botón para cuadrar la caja
        document.getElementById('reconcileCashBtn').addEventListener('click', async function() {
            const totalInitialCash = parseFloat(document.getElementById('totalInitialCash').textContent.replace(' €', '').replace('.', '').replace(',', '.'));
            const totalFinalCash = parseFloat(document.getElementById('totalFinalCash').textContent.replace(' €', '').replace('.', '').replace(',', '.'));
            const observaciones = document.getElementById('observacionesCuadre').value;

            // Recalcular el total esperado en caja
            const expectedFinalCash = totalInitialCash + totalCobradoContado + totalIngresosVarios - totalPagosVarios;
            // Calcular la diferencia entre el recuento final y el esperado
            const difference = totalFinalCash - expectedFinalCash;

            const reconciliationResultDiv = document.getElementById('reconciliationResult');
            reconciliationResultDiv.classList.remove('d-none', 'result-ok', 'result-warning', 'result-danger');
            reconciliationResultDiv.textContent = 'Guardando cuadre...'; // Mensaje de carga

            // Recoger las unidades de billetes y monedas para guardar
            const initialDenominations = {};
            document.querySelectorAll('input.initial-bill, input.initial-coin').forEach(input => {
                // Usar el data-value exacto como clave para almacenar
                initialDenominations[input.dataset.value] = parseFloat(input.value) || 0;
            });

            const finalDenominations = {};
            document.querySelectorAll('input.final-bill, input.final-coin').forEach(input => {
                // Usar el data-value exacto como clave para almacenar
                finalDenominations[input.dataset.value] = parseFloat(input.value) || 0;
            });

            const formData = new FormData();
            formData.append('action', 'save_cuadre');
            formData.append('fecha_cuadre', fechaSeleccionada);
            formData.append('initial_denominations_json', JSON.stringify(initialDenominations));
            formData.append('final_denominations_json', JSON.stringify(finalDenominations));
            formData.append('total_inicial_efectivo', totalInitialCash);
            formData.append('total_final_efectivo', totalFinalCash);
            formData.append('total_cobrado_contado_db', totalCobradoContado);
            formData.append('diferencia', difference);
            formData.append('observaciones', observaciones);

            try {
                const response = await fetch('informe_cobros_diarios.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    reconciliationResultDiv.classList.add('result-ok');
                    reconciliationResultDiv.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i><strong>¡Cuadre guardado!</strong> ${result.message}`;
                } else {
                    reconciliationResultDiv.classList.add('result-danger');
                    reconciliationResultDiv.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Error al guardar el cuadre:</strong> ${result.message}`;
                }
            } catch (error) {
                console.error('Error al guardar el cuadre:', error);
                reconciliationResultDiv.classList.add('result-danger');
                reconciliationResultDiv.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>Error de conexión al guardar el cuadre.`;
            }

            // Mostrar el resultado del cuadre en la UI (esto ya se hace en updateCashTotals)
            // No es necesario duplicar la lógica aquí, ya que updateCashTotals se llama al final.
            // Solo se asegura que el mensaje de "Guardando cuadre..." se reemplace.
            updateCashTotals(); // Para asegurar que los totales y la diferencia se actualicen visualmente.
        });

        // Inicializar los totales y cargar el cuadre existente al cargar la página
        loadExistingCuadre();
        renderIngresosVariosTable(); // Renderizar tabla de ingresos al cargar
        renderPagosVariosTable();   // Renderizar tabla de pagos al cargar
        updateCashTotals(); // Llamar al final para asegurar que todos los valores estén inicializados
    });
    </script>
</body>
</html>
