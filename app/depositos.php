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

// Mapping for abbreviations
$abbreviation_map = [
    'Aceite de Oliva Virgen' => 'AOV',
    'Aceite de Oliva Virgen Extra' => 'AOVE',
    'Aceite de Oliva Refinado' => 'AOR',
    'Aceite de Orujo de Oliva Refinado' => 'AOOR',
    'Aceite de Oliva' => 'AO', // This is an Article name, often a blend of AOVE and AOR
    'Aceite de Orujo de Oliva' => 'AOO' // This is an Article name
];

/**
 * Function to get the abbreviation for a given oil name (Materia Prima or Articulo).
 * @param string $name The full name of the oil.
 * @param array $map The abbreviation map.
 * @return string The abbreviation if found, otherwise the original name.
 */
function getAbbreviation($name, $map) {
    return $map[$name] ?? $name;
}

// --- Lógica para AGREGAR o EDITAR un depósito ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['accion']) && $_POST['accion'] == 'eliminar_deposito') {
        // Lógica para eliminar depósito (usada por el fetch en JS)
        $id_deposito_a_eliminar = $_POST['id_deposito'] ?? null;
        if ($id_deposito_a_eliminar) {
            try {
                // Verificar si el depósito tiene stock actual antes de eliminar
                $stmt_check_stock = $pdo->prepare("SELECT stock_actual FROM depositos WHERE id_deposito = ?");
                $stmt_check_stock->execute([$id_deposito_a_eliminar]);
                $stock_actual_deposito = $stmt_check_stock->fetchColumn();

                if ($stock_actual_deposito > 0) {
                    echo "Error: No se puede eliminar un depósito que tiene stock actual. Vacíelo primero.";
                    http_response_code(400); // Bad Request
                } else {
                    $stmt = $pdo->prepare("DELETE FROM depositos WHERE id_deposito = ?");
                    $stmt->execute([$id_deposito_a_eliminar]);
                    echo "Depósito eliminado correctamente.";
                }
            } catch (PDOException $e) {
                if ($e->getCode() == '23000') { // Foreign key constraint violation
                    echo "Error: No se puede eliminar este depósito porque tiene registros asociados (ej. en entradas a granel o lotes envasados).";
                    http_response_code(409); // Conflict
                } else {
                    echo "Error de base de datos al eliminar: " . $e->getMessage();
                    http_response_code(500); // Internal Server Error
                }
            }
        } else {
            echo "Error: ID de depósito no proporcionado para eliminar.";
            http_response_code(400); // Bad Request
        }
        exit; // Terminar el script después de la respuesta AJAX
    } else {
        // Lógica para agregar/editar
        $id_deposito = $_POST['id_deposito'] ?? null;
        $nombre_deposito = trim($_POST['nombre_deposito'] ?? '');
        $capacidad = $_POST['capacidad'] ?? 0;
        $stock_actual = $_POST['stock_actual'] ?? 0;
        $estado = $_POST['estado'] ?? 'vacio'; // 'vacio', 'ocupado', 'lleno'

        // Validación básica de los datos
        if (empty($nombre_deposito) || !is_numeric($capacidad) || $capacidad <= 0) {
            $mensaje = "Error: El nombre del depósito y la capacidad son obligatorios y la capacidad debe ser un número positivo.";
            $tipo_mensaje = 'danger';
        } elseif (!is_numeric($stock_actual) || $stock_actual < 0) {
            $mensaje = "Error: El stock actual debe ser un número válido y no puede ser negativo.";
            $tipo_mensaje = 'danger';
        } elseif ($stock_actual > $capacidad) {
            $mensaje = "Error: El stock actual no puede ser mayor que la capacidad del depósito.";
            $tipo_mensaje = 'danger';
        } else {
            try {
                if ($id_deposito) {
                    // Editar depósito existente
                    $stmt = $pdo->prepare("UPDATE depositos SET nombre_deposito = ?, capacidad = ?, stock_actual = ?, estado = ? WHERE id_deposito = ?");
                    $stmt->execute([$nombre_deposito, $capacidad, $stock_actual, $estado, $id_deposito]);
                    $mensaje = "Depósito actualizado correctamente.";
                    $tipo_mensaje = 'success';
                } else {
                    // Agregar nuevo depósito
                    $stmt = $pdo->prepare("INSERT INTO depositos (nombre_deposito, capacidad, stock_actual, estado) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nombre_deposito, $capacidad, $stock_actual, $estado]);
                    $mensaje = "Depósito agregado correctamente.";
                    $tipo_mensaje = 'success';
                }
            } catch (PDOException $e) {
                // Manejar error de duplicado o cualquier otro error de PDO
                if ($e->getCode() == '23000' && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $mensaje = "Error: El nombre del depósito '{$nombre_deposito}' ya existe. Por favor, elija un nombre único.";
                    $tipo_mensaje = 'danger';
                } else {
                    $mensaje = "Error de base de datos: " . $e->getMessage();
                    $tipo_mensaje = 'danger';
                }
            }
        }
    }
}


// Cargar mensajes de la redirección
if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $tipo_mensaje = $_GET['tipo_mensaje'];
}

// --- Lógica para mostrar el historial de un depósito ---
$deposito_historial = null;
$movimientos_historial = [];

if (isset($_GET['view']) && $_GET['view'] == 'history' && isset($_GET['id'])) {
    $id_deposito_historial = $_GET['id'];

    try {
        // Obtener información del depósito actual
        $stmt_deposito_info = $pdo->prepare("SELECT id_deposito, nombre_deposito, stock_actual, capacidad FROM depositos WHERE id_deposito = ?");
        $stmt_deposito_info->execute([$id_deposito_historial]);
        $deposito_historial = $stmt_deposito_info->fetch(PDO::FETCH_ASSOC);

        if ($deposito_historial) {
            // 1. Consulta para Entradas a Granel (Aumento de stock en este depósito)
            $sql_entradas = "SELECT
                                eg.fecha_entrada AS fecha,
                                'Entrada a Granel' AS tipo_movimiento,
                                deg.litros_descargados AS cantidad,
                                CONCAT('Entrada de ', p.nombre_proveedor, ' - Lote: ', IFNULL(eg.numero_lote_proveedor, 'N/A')) AS descripcion
                            FROM detalle_entrada_granel deg
                            JOIN entradas_granel eg ON deg.id_entrada_granel = eg.id_entrada_granel
                            JOIN proveedores p ON eg.id_proveedor = p.id_proveedor
                            WHERE deg.id_deposito = ?";

            // 2. Consulta para Trasvases EN (trasvases donde deposito_destino es este depósito)
            $sql_trasvases_in = "SELECT
                                        t.fecha_trasvase AS fecha,
                                        'Trasvase (Entrada)' AS tipo_movimiento,
                                        t.litros_trasvasados AS cantidad,
                                        CONCAT('Trasvasado desde depósito ', d_origen.nombre_deposito) AS descripcion
                                    FROM trasvases t
                                    JOIN depositos d_origen ON t.deposito_origen = d_origen.id_deposito
                                    WHERE t.deposito_destino = ?";

            // 3. Consulta para Trasvases SALIDA (trasvases donde deposito_origen es este depósito)
            $sql_trasvases_out = "SELECT
                                        t.fecha_trasvase AS fecha,
                                        'Trasvase (Salida)' AS tipo_movimiento,
                                        -t.litros_trasvasados AS cantidad,
                                        CONCAT('Trasvasado a depósito ', d_destino.nombre_deposito) AS descripcion
                                    FROM trasvases t
                                    JOIN depositos d_destino ON t.deposito_destino = d_destino.id_deposito
                                    WHERE t.deposito_origen = ?";

            // 4. Extracciones para envasado (detalle_lotes_envasado)
            $sql_bottling_extractions = "SELECT
                                            le.fecha_creacion AS fecha,
                                            'Extracción Envasado' AS tipo_movimiento,
                                            -dle.litros_extraidos AS cantidad,
                                            CONCAT('Extracción de ', dle.litros_extraidos, 'L para lote envasado: ', le.nombre_lote, ' (', a.nombre_articulo, ')') AS descripcion
                                        FROM detalle_lotes_envasado dle
                                        JOIN lotes_envasado le ON dle.id_lote_envasado = le.id_lote_envasado
                                        JOIN articulos a ON le.id_articulo = a.id_articulo
                                        WHERE dle.id_deposito_origen = ?";

            // 5. Consulta para Recepción de Lote de Mezcla en este depósito
            $sql_recepcion_lote_mezcla = "SELECT
                                            le.fecha_creacion AS fecha,
                                            'Recepción Lote de Mezcla' AS tipo_movimiento,
                                            le.litros_preparados AS cantidad,
                                            CONCAT('Recepción de ', le.litros_preparados, 'L para lote envasado: ', le.nombre_lote, ' (', a.nombre_articulo, ')') AS descripcion
                                        FROM lotes_envasado le
                                        JOIN articulos a ON le.id_articulo = a.id_articulo
                                        WHERE le.id_deposito_mezcla = ?";

            // 6. Consulta para Actividad de Envasado (Salida del depósito de mezcla)
            $sql_actividad_envasado = "SELECT
                                            ae.fecha_envasado AS fecha,
                                            'Actividad de Envasado (Salida)' AS tipo_movimiento,
                                            -dae.litros_envasados AS cantidad,
                                            CONCAT('Envasado de ', dae.litros_envasados, 'L del lote ', le.nombre_lote, ' (', p.nombre_producto, ')') AS descripcion
                                        FROM detalle_actividad_envasado dae
                                        JOIN actividad_envasado ae ON dae.id_actividad_envasado = ae.id_actividad_envasado
                                        JOIN lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
                                        JOIN productos p ON dae.id_producto = p.id_producto
                                        WHERE le.id_deposito_mezcla = ?";


            // Combinar todas las consultas y ordenar por fecha ascendente
            $sql_union = "
                SELECT * FROM ({$sql_entradas}) AS entradas
                UNION ALL
                SELECT * FROM ({$sql_trasvases_in}) AS tras_in
                UNION ALL
                SELECT * FROM ({$sql_trasvases_out}) AS tras_out
                UNION ALL
                SELECT * FROM ({$sql_bottling_extractions}) AS extraccion_lote
                UNION ALL
                SELECT * FROM ({$sql_recepcion_lote_mezcla}) AS recepcion_lote_mezcla
                UNION ALL
                SELECT * FROM ({$sql_actividad_envasado}) AS actividad_envasado
                ORDER BY fecha ASC, tipo_movimiento ASC
            ";

            $stmt_movimientos = $pdo->prepare($sql_union);
            $stmt_movimientos->execute([
                $id_deposito_historial,
                $id_deposito_historial,
                $id_deposito_historial,
                $id_deposito_historial,
                $id_deposito_historial,
                $id_deposito_historial
            ]);
            $movimientos_historial_asc = $stmt_movimientos->fetchAll(PDO::FETCH_ASSOC);

            // Calcular stock inicial
            $sum_of_all_quantities = array_sum(array_column($movimientos_historial_asc, 'cantidad'));
            $running_stock = $deposito_historial['stock_actual'] - $sum_of_all_quantities;

            // Calcular stock después de cada movimiento
            foreach ($movimientos_historial_asc as $key => $movimiento) {
                $running_stock += $movimiento['cantidad'];
                $movimientos_historial_asc[$key]['stock_despues'] = $running_stock;
            }

            // Invertir para mostrar los más recientes primero
            $movimientos_historial = array_reverse($movimientos_historial_asc);

        } else {
            $mensaje = "Error: Depósito no encontrado para mostrar el historial.";
            $tipo_mensaje = 'danger';
        }

    } catch (PDOException $e) {
        $mensaje = "Error al cargar el historial del depósito: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}


// --- Lógica para LISTAR Depósitos ---
$depositos = [];
if (!$deposito_historial) {
    try {
        $sql = "SELECT
                    d.id_deposito,
                    d.nombre_deposito,
                    d.capacidad,
                    d.estado,
                    d.stock_actual,
                    d.id_materia_prima,
                    d.id_entrada_granel_origen,
                    mp.nombre_materia_prima AS nombre_materia_prima_deposito,
                    eg.numero_lote_proveedor AS lote_proveedor_granel,
                    CASE
                        WHEN d.id_materia_prima IS NULL AND d.stock_actual > 0 THEN
                            (SELECT a.nombre_articulo
                             FROM lotes_envasado le_sub
                             JOIN articulos a ON le_sub.id_articulo = a.id_articulo
                             WHERE le_sub.id_deposito_mezcla = d.id_deposito
                             ORDER BY le_sub.fecha_creacion DESC, le_sub.id_lote_envasado DESC
                             LIMIT 1)
                        ELSE NULL
                    END AS nombre_articulo_envasado,
                    CASE
                        WHEN d.id_materia_prima IS NULL AND d.stock_actual > 0 THEN
                            (SELECT le_sub.nombre_lote
                             FROM lotes_envasado le_sub
                             WHERE le_sub.id_deposito_mezcla = d.id_deposito
                             ORDER BY le_sub.fecha_creacion DESC, le_sub.id_lote_envasado DESC
                             LIMIT 1)
                        ELSE NULL
                    END AS nombre_lote_envasado
                FROM
                    depositos d
                LEFT JOIN
                    materias_primas mp ON d.id_materia_prima = mp.id_materia_prima
                LEFT JOIN
                    entradas_granel eg ON d.id_entrada_granel_origen = eg.id_entrada_granel
                ORDER BY
                    d.nombre_deposito ASC";

        $stmt = $pdo->query($sql);
        $depositos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $mensaje = "Error al cargar los depósitos: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}


// Cierra la conexión PDO
$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Depósitos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
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

        /* Estilos específicos de Depósitos (ajustados para el nuevo tema) */
        .header {
            background-color: #ffffff; /* White background for header */
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: #0056b3; /* Usar el color primario del tema */
            font-size: 1.8rem;
            margin: 0;
        }

        .table thead th {
            background-color: #e9ecef; /* Color de cabecera de tabla de facturas_ventas.php */
            color: #333; /* Texto oscuro para contraste */
            border-color: #dee2e6;
        }

        .table.history-table th:nth-child(4), .table.history-table td:nth-child(4),
        .table.history-table th:nth-child(5), .table.history-table td:nth-child(5) { text-align: right; }
        .table.history-table .positive-movement { color: #28a745; font-weight: bold; } /* Green for positive */
        .table.history-table .negative-movement { color: #dc3545; font-weight: bold; } /* Red for negative */
        .table.history-table th:nth-child(3) { text-align: center; }
        .table.history-table td:nth-child(3) { text-align: left; }

        .table tfoot tr { font-weight: bold; background-color: #e9ecef; }
        .table tfoot td { border-top: 2px solid #0056b3; } /* Primary color for footer border */
        .form-label-required::after { content: " *"; color: red; margin-left: 3px; }

        .row-supplier-batch { background-color: #e6ffe6 !important; } /* Light green for supplier batches */
        .row-own-prepared-batch { background-color: #e0f7fa !important; } /* Light blue for own prepared batches */
        
        .filter-buttons-container { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; justify-content: center; }
        .filter-buttons-container .btn { flex: 1 1 auto; min-width: 150px; max-width: 250px; font-weight: 500; }
        .filter-buttons-container .btn.active-filter { background-color: #0056b3; border-color: #0056b3; color: white; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15); }

        /* Media Queries */
        @media (max-width: 768px) {
            .sidebar { width: 0; left: -var(--sidebar-width); }
            .main-content { margin-left: 0; width: 100%; padding: 15px; }
            .card-body { padding: 1.5rem; }
            .filter-buttons-container { flex-direction: column; align-items: stretch; }
            .filter-buttons-container .btn { min-width: unset; max-width: unset; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="container">
                <div class="header">
                    <h1>Gestión de Depósitos</h1>
                    <!-- Removed the welcome message and logout button here -->
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($deposito_historial): ?>
                <!-- VISTA DE HISTORIAL DE DEPÓSITO (ANCHO COMPLETO) -->
                <div class="card detail-view-card mb-4">
                    <div class="card-header bg-info">
                        <h5>Historial de Movimientos: <?php echo htmlspecialchars($deposito_historial['nombre_deposito']); ?></h5>
                    </div>
                    <div class="card-body">
                        <p><strong>ID Depósito:</strong> <?php echo htmlspecialchars($deposito_historial['id_deposito']); ?></p>
                        <p><strong>Stock Actual:</strong> <?php echo number_format($deposito_historial['stock_actual'], 2, ',', '.'); ?> L</p>
                        <p><strong>Capacidad:</strong> <?php echo number_format($deposito_historial['capacidad'], 2, ',', '.'); ?> L</p>

                        <h4 class="mt-4 mb-3">Movimientos</h4>
                        <?php if (!empty($movimientos_historial)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped history-table">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Tipo de Movimiento</th>
                                            <th>Descripción</th>
                                            <th class="text-end">Cantidad (L)</th>
                                            <th class="text-end">Stock Después (L)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($movimientos_historial as $movimiento): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date("d/m/Y", strtotime($movimiento['fecha']))); ?></td>
                                                <td><?php echo htmlspecialchars($movimiento['tipo_movimiento']); ?></td>
                                                <td><?php echo htmlspecialchars($movimiento['descripcion']); ?></td>
                                                <td class="text-end <?php echo ($movimiento['cantidad'] > 0) ? 'positive-movement' : 'negative-movement'; ?>">
                                                    <?php echo number_format($movimiento['cantidad'], 2, ',', '.'); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo number_format($movimiento['stock_despues'], 2, ',', '.'); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center">No hay movimientos registrados para este depósito.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-center mt-4">
                    <a href="depositos.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Volver al Listado de Depósitos</a>
                </div>

            <?php else: ?>
                <!-- VISTA DE LISTADO Y FORMULARIO -->
                <div class="container">
                    <h3 class="mt-5 mb-3 text-center">Listado de Depósitos</h3>
                    <!-- Contenedor de botones de filtro -->
                    <div class="filter-buttons-container">
                        <button type="button" class="btn btn-outline-primary active-filter" id="filterAllBtn"><i class="bi bi-list-ul"></i> Mostrar Todos</button>
                        <button type="button" class="btn btn-outline-success" id="filterSupplierBtn"><i class="bi bi-truck"></i> Materias Primas</button>
                        <button type="button" class="btn btn-outline-info" id="filterOwnBatchBtn"><i class="bi bi-box-seam-fill"></i> Lotes Preparados</button>
                        <button type="button" class="btn btn-outline-secondary" id="filterEmptyBtn"><i class="bi bi-cup"></i> Vacíos</button>
                    </div>
                </div>

                <!-- Tabla de Depósitos (ANCHO COMPLETO) -->
                <div class="card table-section-card">
                    <div class="card-header bg-info">
                        <h5>Depósitos Registrados</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($depositos)): ?>
                            <p class="text-center">No hay depósitos registrados todavía.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nombre</th>
                                            <th>Capacidad (L)</th>
                                            <th>Estado</th>
                                            <th>Stock Actual (L)</th>
                                            <th>Artículo</th>
                                            <th>Lote</th>
                                            <th>Tipo de Aceite</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="depositosTableBody">
                                        <?php foreach ($depositos as $deposito):
                                            $row_class = '';
                                            if ($deposito['stock_actual'] > 0) {
                                                if ($deposito['id_entrada_granel_origen'] !== NULL) {
                                                    $row_class = 'row-supplier-batch';
                                                } elseif ($deposito['id_materia_prima'] === NULL && $deposito['nombre_articulo_envasado'] !== NULL) {
                                                    $row_class = 'row-own-prepared-batch';
                                                }
                                            }
                                        ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td><?php echo htmlspecialchars($deposito['id_deposito']); ?></td>
                                                <td><?php echo htmlspecialchars($deposito['nombre_deposito']); ?></td>
                                                <td class="text-end"><?php echo number_format($deposito['capacidad'], 2, ',', '.'); ?></td>
                                                <td><?php echo htmlspecialchars($deposito['estado']); ?></td>
                                                <td class="text-end"><?php echo number_format($deposito['stock_actual'], 2, ',', '.'); ?></td>
                                                <td>
                                                    <?php
                                                    if ($deposito['stock_actual'] > 0 && $deposito['id_materia_prima'] === NULL) {
                                                        echo htmlspecialchars(getAbbreviation($deposito['nombre_articulo_envasado'], $abbreviation_map) ?? 'N/A');
                                                    } else { echo 'N/A'; }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    if ($deposito['id_entrada_granel_origen'] !== NULL) {
                                                        echo htmlspecialchars($deposito['lote_proveedor_granel'] ?? 'N/A');
                                                    } elseif ($deposito['stock_actual'] > 0 && $deposito['id_materia_prima'] === NULL) {
                                                        echo htmlspecialchars($deposito['nombre_lote_envasado'] ?? 'N/A');
                                                    } else { echo 'N/A'; }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    if ($deposito['id_materia_prima'] !== NULL) {
                                                        echo htmlspecialchars(getAbbreviation($deposito['nombre_materia_prima_deposito'], $abbreviation_map) ?? 'N/A');
                                                    } elseif ($deposito['stock_actual'] > 0 && $deposito['id_materia_prima'] === NULL) {
                                                        echo htmlspecialchars(getAbbreviation($deposito['nombre_articulo_envasado'], $abbreviation_map) ?? 'N/A');
                                                    } else { echo 'N/A'; }
                                                    ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-warning btn-sm" onclick='editDeposito(<?php echo json_encode($deposito); ?>)'>
                                                        <i class="bi bi-pencil"></i> Editar
                                                    </button>
                                                    <a href="depositos.php?view=history&id=<?php echo $deposito['id_deposito']; ?>" class="btn btn-info btn-sm">
                                                        <i class="bi bi-clock-history"></i> Historial
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot id="depositosTableFooter">
                                        <tr>
                                            <td colspan="2"><strong>Totales:</strong></td>
                                            <td class="text-end" id="totalCapacidad">0.00</td>
                                            <td></td>
                                            <td class="text-end" id="totalStockActual">0.00</td>
                                            <td colspan="4"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Formulario de Agregar/Editar Depósito (ANCHO LIMITADO Y CENTRADO) -->
                <div class="card form-section-card mt-5 content-constrained">
                    <div class="card-header bg-success">
                        <h5>Agregar/Editar Depósito</h5>
                    </div>
                    <div class="card-body">
                        <form action="depositos.php" method="POST">
                            <input type="hidden" id="id_deposito" name="id_deposito">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nombre_deposito" class="form-label form-label-required">Nombre del Depósito</label>
                                    <input type="text" class="form-control" id="nombre_deposito" name="nombre_deposito" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="capacidad" class="form-label form-label-required">Capacidad (Litros)</label>
                                    <input type="number" step="0.01" class="form-control" id="capacidad" name="capacidad" min="0.01" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="stock_actual" class="form-label">Stock Actual (Litros)</label>
                                    <input type="number" step="0.01" class="form-control" id="stock_actual" name="stock_actual" min="0">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="estado" class="form-label">Estado</label>
                                    <select class="form-select" id="estado" name="estado">
                                        <option value="vacio">Vacío</option>
                                        <option value="ocupado">Ocupado</option>
                                        <option value="lleno">Lleno</option>
                                    </select>
                                </div>
                            </div>
                            <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar Depósito</button>
                                <button type="button" class="btn btn-warning" onclick="resetForm()"><i class="bi bi-arrow-counterclockwise"></i> Limpiar Formulario</button>
                                <button type="button" class="btn btn-danger" id="deleteDepositoBtn" style="display: none;"><i class="bi bi-trash"></i> Eliminar Depósito</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="index.php" class="btn btn-secondary"><i class="bi bi-house"></i> Volver al Inicio</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="customModal" tabindex="-1" aria-labelledby="customModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customModalLabel"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="customModalBody"></div>
                <div class="modal-footer" id="customModalFooter"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        function editDeposito(deposito) {
            document.getElementById('id_deposito').value = deposito.id_deposito;
            document.getElementById('nombre_deposito').value = deposito.nombre_deposito;
            document.getElementById('capacidad').value = parseFloat(deposito.capacidad).toFixed(2);
            document.getElementById('stock_actual').value = parseFloat(deposito.stock_actual).toFixed(2);
            document.getElementById('estado').value = deposito.estado;
            document.getElementById('deleteDepositoBtn').style.display = 'inline-block';
            document.getElementById('deleteDepositoBtn').onclick = () => confirmDelete(deposito.id_deposito);
            
            const formCard = document.querySelector('.form-section-card');
            formCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function resetForm() {
            document.getElementById('id_deposito').value = '';
            document.querySelector('form').reset();
            document.getElementById('estado').value = 'vacio';
            document.getElementById('deleteDepositoBtn').style.display = 'none';
        }

        function showCustomModal(title, message, type, callback = null) {
            const modal = new bootstrap.Modal(document.getElementById('customModal'));
            document.getElementById('customModalLabel').innerText = title;
            document.getElementById('customModalBody').innerText = message;
            const modalFooter = document.getElementById('customModalFooter');
            modalFooter.innerHTML = ''; 

            if (type === 'alert') {
                const closeButton = document.createElement('button');
                closeButton.type = 'button';
                closeButton.classList.add('btn', 'btn-primary');
                closeButton.setAttribute('data-bs-dismiss', 'modal');
                closeButton.innerText = 'Aceptar';
                modalFooter.appendChild(closeButton);
            } else if (type === 'confirm') {
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.classList.add('btn', 'btn-outline-secondary');
                cancelButton.setAttribute('data-bs-dismiss', 'modal');
                cancelButton.innerText = 'Cancelar';
                cancelButton.addEventListener('click', () => { if (callback) callback(false); });
                modalFooter.appendChild(cancelButton);

                const confirmButton = document.createElement('button');
                confirmButton.type = 'button';
                confirmButton.classList.add('btn', 'btn-danger');
                confirmButton.innerText = 'Confirmar Eliminación';
                confirmButton.addEventListener('click', () => {
                    if (callback) callback(true);
                    modal.hide();
                });
                modalFooter.appendChild(confirmButton);
            }
            modal.show();
        }

        function confirmDelete(id_deposito) {
            showCustomModal(
                "Confirmar Eliminación",
                "¿Está seguro de que desea eliminar este depósito? Esta acción es irreversible y solo se puede realizar si el depósito no tiene stock y no está referenciado.",
                "confirm",
                (confirmed) => {
                    if (confirmed) {
                        fetch('depositos.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'accion=eliminar_deposito&id_deposito=' + id_deposito
                        })
                        .then(response => {
                            if (!response.ok) {
                                return response.text().then(text => { throw new Error(text) });
                            }
                            return response.text();
                        })
                        .then(data => {
                            showCustomModal("Éxito", data, "alert", () => {
                                window.location.href = 'depositos.php';
                            });
                        })
                        .catch(error => {
                            showCustomModal("Error", error.message || "Error al eliminar el depósito.", "alert");
                        });
                    }
                }
            );
        }

        document.addEventListener('DOMContentLoaded', function() {
            const depositosTableBody = document.getElementById('depositosTableBody');
            if (!depositosTableBody) return;

            const filterButtons = {
                All: document.getElementById('filterAllBtn'),
                Supplier: document.getElementById('filterSupplierBtn'),
                OwnBatch: document.getElementById('filterOwnBatchBtn'),
                Empty: document.getElementById('filterEmptyBtn')
            };
            const totalCapacidadSpan = document.getElementById('totalCapacidad');
            const totalStockActualSpan = document.getElementById('totalStockActual');
            
            const allDepositosData = <?php echo json_encode($depositos); ?>.map(d => ({
                ...d,
                capacidad: parseFloat(d.capacidad),
                stock_actual: parseFloat(d.stock_actual)
            }));

            const abbreviationMapJs = {
                'Aceite de Oliva Virgen': 'AOV', 'Aceite de Oliva Virgen Extra': 'AOVE',
                'Aceite de Oliva Refinado': 'AOR', 'Aceite de Orujo de Oliva Refinado': 'AOOR',
                'Aceite de Oliva': 'AO', 'Aceite de Orujo de Oliva': 'AOO'
            };
            const getAbbreviationJs = (name) => abbreviationMapJs[name] || name || 'N/A';

            function applyFilter(filterType) {
                Object.values(filterButtons).forEach(btn => btn.classList.remove('active-filter'));
                filterButtons[filterType].classList.add('active-filter');

                let totalCapacity = 0, totalStock = 0;
                depositosTableBody.innerHTML = ''; 

                allDepositosData.forEach(deposito => {
                    const isSupplierBatch = !!deposito.id_entrada_granel_origen && deposito.stock_actual > 0;
                    const isOwnPreparedBatch = !deposito.id_materia_prima && !!deposito.nombre_articulo_envasado && deposito.stock_actual > 0;
                    const isEmpty = deposito.stock_actual === 0;

                    let shouldShow = false;
                    if (filterType === 'All') shouldShow = true;
                    else if (filterType === 'Supplier') shouldShow = isSupplierBatch;
                    else if (filterType === 'OwnBatch') shouldShow = isOwnPreparedBatch;
                    else if (filterType === 'Empty') shouldShow = isEmpty;

                    if (shouldShow) {
                        totalCapacity += deposito.capacidad;
                        totalStock += deposito.stock_actual;

                        const row = depositosTableBody.insertRow();
                        row.className = isSupplierBatch ? 'row-supplier-batch' : (isOwnPreparedBatch ? 'row-own-prepared-batch' : '');
                        
                        const articulo = isOwnPreparedBatch ? getAbbreviationJs(deposito.nombre_articulo_envasado) : 'N/A';
                        const lote = isSupplierBatch ? (deposito.lote_proveedor_granel || 'N/A') : (isOwnPreparedBatch ? (deposito.nombre_lote_envasado || 'N/A') : 'N/A');
                        const tipoAceite = isSupplierBatch ? getAbbreviationJs(deposito.nombre_materia_prima_deposito) : articulo;

                        row.innerHTML = `
                            <td>${deposito.id_deposito}</td>
                            <td>${deposito.nombre_deposito}</td>
                            <td class="text-end">${deposito.capacidad.toFixed(2).replace('.', ',')}</td>
                            <td>${deposito.estado}</td>
                            <td class="text-end">${deposito.stock_actual.toFixed(2).replace('.', ',')}</td>
                            <td>${articulo}</td>
                            <td>${lote}</td>
                            <td>${tipoAceite}</td>
                            <td>
                                <button type="button" class="btn btn-warning btn-sm" onclick='editDeposito(<?php echo json_encode($deposito); ?>)'>
                                    <i class="bi bi-pencil"></i> Editar
                                </button>
                                <a href="depositos.php?view=history&id=${deposito.id_deposito}" class="btn btn-info btn-sm"><i class="bi bi-clock-history"></i> Historial</a>
                            </td>
                        `;
                        row.querySelector('.btn-warning').onclick = () => editDeposito(deposito);
                    }
                });

                totalCapacidadSpan.textContent = totalCapacity.toFixed(2).replace('.', ',');
                totalStockActualSpan.textContent = totalStock.toFixed(2).replace('.', ',');
            }

            Object.entries(filterButtons).forEach(([key, btn]) => {
                btn.addEventListener('click', () => applyFilter(key));
            });

            applyFilter('All');
        });
    </script>
</body>
</html>
