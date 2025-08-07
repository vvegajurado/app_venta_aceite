<?php
// Habilitar la visualización de errores para depuración (¡DESACTIVAR EN PRODUCCIÓN! EN PRODUCCIÓN DEBE ESTAR EN 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
$clientes = []; // Array para almacenar los clientes
$cliente_a_editar = null; // Variable para almacenar los datos del cliente si se está editando uno específico

// Detectar si la petición es AJAX
$is_ajax_request = (isset($_POST['accion']) && in_array($_POST['accion'], ['get_client_activity', 'agregar_ajax', 'agregar_direccion_envio', 'editar_direccion_envio', 'eliminar_direccion_envio'])) || (isset($_GET['accion']) && in_array($_GET['accion'], ['buscar_ajax', 'get_direcciones_envio_ajax']));

// Inicializar variables para mensajes y datos de respuesta JSON
$response_data = ['success' => false, 'message' => '']; // Para respuestas JSON

// --- Lógica para procesar peticiones AJAX ---
if ($is_ajax_request) {
    header('Content-Type: application/json');

    try {
        if (isset($_POST['accion'])) {
            switch ($_POST['accion']) {
                case 'get_client_activity':
                    $client_id = $_POST['id_cliente'] ?? null;
                    $activity_data = ['success' => false, 'message' => ''];

                    if ($client_id) {
                        // Últimas 5 Facturas, incluyendo el total cobrado para determinar si está pendiente
                        $stmt_invoices = $pdo->prepare("
                            SELECT
                                fv.id_factura,
                                fv.fecha_factura,
                                fv.total_factura_iva_incluido,
                                COALESCE(SUM(cf.cantidad_cobrada), 0) AS total_cobrado_factura
                            FROM
                                facturas_ventas fv
                            LEFT JOIN
                                cobros_factura cf ON fv.id_factura = cf.id_factura
                            WHERE
                                fv.id_cliente = ?
                            GROUP BY
                                fv.id_factura, fv.fecha_factura, fv.total_factura_iva_incluido
                            ORDER BY
                                fv.fecha_factura DESC, fv.id_factura DESC
                            LIMIT 5
                        ");
                        $stmt_invoices->execute([$client_id]);
                        $last_invoices_raw = $stmt_invoices->fetchAll(PDO::FETCH_ASSOC);

                        // Calcular el estado de pago para cada factura
                        $last_invoices = [];
                        foreach ($last_invoices_raw as $invoice) {
                            $invoice['pendiente_pago'] = (bccomp((string)$invoice['total_factura_iva_incluido'], (string)$invoice['total_cobrado_factura'], 2) > 0) ? 'Sí' : 'No';
                            $last_invoices[] = $invoice;
                        }

                        // Últimos 5 Pedidos
                        $stmt_orders = $pdo->prepare("SELECT id_pedido, fecha_pedido, total_pedido_iva_incluido, estado_pedido FROM pedidos WHERE id_cliente = ? ORDER BY fecha_pedido DESC, id_pedido DESC LIMIT 5");
                        $stmt_orders->execute([$client_id]);
                        $last_orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);

                        $activity_data['success'] = true;
                        $activity_data['invoices'] = $last_invoices;
                        $activity_data['orders'] = $last_orders;

                    } else {
                        $activity_data['message'] = "ID de cliente no proporcionado para la actividad.";
                    }
                    echo json_encode($activity_data);
                    break;

                case 'agregar_direccion_envio':
                    $id_cliente = $_POST['id_cliente'] ?? null;
                    $nombre_direccion = trim($_POST['nombre_direccion'] ?? '');
                    $direccion = trim($_POST['direccion_envio'] ?? '');
                    $ciudad = trim($_POST['ciudad_envio'] ?? '');
                    $provincia = trim($_POST['provincia_envio'] ?? '');
                    $codigo_postal = trim($_POST['codigo_postal_envio'] ?? '');
                    $latitud = filter_var($_POST['latitud_envio'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['latitud_envio'] : null;
                    $longitud = filter_var($_POST['longitud_envio'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['longitud_envio'] : null;
                    $direccion_google_maps = trim($_POST['direccion_google_maps_envio'] ?? '');
                    $es_principal = isset($_POST['es_principal']) ? 1 : 0;
                    $telefono_envio = trim($_POST['telefono_envio'] ?? '');
                    $observaciones_envio = trim($_POST['observaciones_envio'] ?? '');

                    if (empty($id_cliente) || empty($nombre_direccion) || empty($direccion)) {
                        throw new Exception("Nombre de dirección, dirección y cliente son obligatorios.");
                    }

                    $pdo->beginTransaction();

                    // Si se marca como principal, desmarcar las demás
                    if ($es_principal) {
                        $stmt_reset_principal = $pdo->prepare("UPDATE direcciones_envio SET es_principal = FALSE WHERE id_cliente = ?");
                        $stmt_reset_principal->execute([$id_cliente]);
                    }

                    $stmt = $pdo->prepare("INSERT INTO direcciones_envio (id_cliente, nombre_direccion, direccion, ciudad, provincia, codigo_postal, latitud, longitud, direccion_google_maps, es_principal, telefono, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$id_cliente, $nombre_direccion, $direccion, $ciudad, $provincia, $codigo_postal, $latitud, $longitud, $direccion_google_maps, $es_principal, $telefono_envio, $observaciones_envio]);
                    $new_id = $pdo->lastInsertId();

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Dirección de envío agregada con éxito.', 'id_direccion_envio' => $new_id]);
                    break;

                case 'editar_direccion_envio':
                    $id_direccion_envio = $_POST['id_direccion_envio'] ?? null;
                    $id_cliente = $_POST['id_cliente'] ?? null;
                    $nombre_direccion = trim($_POST['nombre_direccion'] ?? '');
                    $direccion = trim($_POST['direccion_envio'] ?? '');
                    $ciudad = trim($_POST['ciudad_envio'] ?? '');
                    $provincia = trim($_POST['provincia_envio'] ?? '');
                    $codigo_postal = trim($_POST['codigo_postal_envio'] ?? '');
                    $latitud = filter_var($_POST['latitud_envio'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['latitud_envio'] : null;
                    $longitud = filter_var($_POST['longitud_envio'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['longitud_envio'] : null;
                    $direccion_google_maps = trim($_POST['direccion_google_maps_envio'] ?? '');
                    $es_principal = isset($_POST['es_principal']) ? 1 : 0;
                    $telefono_envio = trim($_POST['telefono_envio'] ?? '');
                    $observaciones_envio = trim($_POST['observaciones_envio'] ?? '');

                    if (empty($id_direccion_envio) || empty($id_cliente) || empty($nombre_direccion) || empty($direccion)) {
                        throw new Exception("ID de dirección, nombre de dirección, dirección y cliente son obligatorios.");
                    }

                    $pdo->beginTransaction();

                    // Si se marca como principal, desmarcar las demás
                    if ($es_principal) {
                        $stmt_reset_principal = $pdo->prepare("UPDATE direcciones_envio SET es_principal = FALSE WHERE id_cliente = ? AND id_direccion_envio != ?");
                        $stmt_reset_principal->execute([$id_cliente, $id_direccion_envio]);
                    }

                    $stmt = $pdo->prepare("UPDATE direcciones_envio SET nombre_direccion = ?, direccion = ?, ciudad = ?, provincia = ?, codigo_postal = ?, latitud = ?, longitud = ?, direccion_google_maps = ?, es_principal = ?, telefono = ?, observaciones = ? WHERE id_direccion_envio = ? AND id_cliente = ?");
                    $stmt->execute([$nombre_direccion, $direccion, $ciudad, $provincia, $codigo_postal, $latitud, $longitud, $direccion_google_maps, $es_principal, $telefono_envio, $observaciones_envio, $id_direccion_envio, $id_cliente]);

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Dirección de envío actualizada con éxito.']);
                    break;

                case 'eliminar_direccion_envio':
                    $id_direccion_envio = $_POST['id_direccion_envio'] ?? null;
                    $id_cliente = $_POST['id_cliente'] ?? null;

                    if (empty($id_direccion_envio) || empty($id_cliente)) {
                        throw new Exception("ID de dirección y cliente son obligatorios.");
                    }

                    $stmt = $pdo->prepare("DELETE FROM direcciones_envio WHERE id_direccion_envio = ? AND id_cliente = ?");
                    $stmt->execute([$id_direccion_envio, $id_cliente]);

                    echo json_encode(['success' => true, 'message' => 'Dirección de envío eliminada con éxito.']);
                    break;

                case 'agregar_ajax':
                    $nombre_cliente = trim($_POST['nombre_cliente'] ?? '');
                    $direccion = trim($_POST['direccion'] ?? '');
                    $ciudad = trim($_POST['ciudad'] ?? '');
                    $provincia = trim($_POST['provincia'] ?? '');
                    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
                    $telefono = trim($_POST['telefono'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $nif = trim($_POST['nif'] ?? '');
                    $latitud = filter_var($_POST['latitud'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['latitud'] : null;
                    $longitud = filter_var($_POST['longitud'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['longitud'] : null;
                    $direccion_google_maps = trim($_POST['direccion_google_maps'] ?? '');
                    $observaciones = trim($_POST['observaciones'] ?? '');

                    if (empty($nombre_cliente)) {
                        throw new Exception("Error: El nombre del cliente es obligatorio.");
                    }

                    $nif_db = empty($nif) ? null : $nif;

                    $stmt = $pdo->prepare("INSERT INTO clientes (nombre_cliente, direccion, ciudad, provincia, codigo_postal, telefono, email, nif, latitud, longitud, direccion_google_maps, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nombre_cliente, $direccion, $ciudad, $provincia, $codigo_postal, $telefono, $email, $nif_db, $latitud, $longitud, $direccion_google_maps, $observaciones]);
                    $new_id = $pdo->lastInsertId();

                    echo json_encode(['success' => true, 'message' => 'Cliente agregado correctamente.', 'cliente' => [
                        'id_cliente' => $new_id,
                        'nombre_cliente' => $nombre_cliente,
                        'nif' => $nif_db,
                        'telefono' => $telefono,
                        'email' => $email,
                        'latitud' => $latitud,
                        'longitud' => $longitud,
                        'direccion_google_maps' => $direccion_google_maps,
                        'observaciones' => $observaciones
                    ]]);
                    break;
            }
        } elseif (isset($_GET['accion'])) {
            switch ($_GET['accion']) {
                case 'buscar_ajax':
                    $search_query = $_GET['search'] ?? '';
                    $sql = "
                        SELECT
                            c.*,
                            COALESCE(SUM_FV.total_facturado_iva_incluido, 0) AS total_facturado_cliente,
                            COALESCE(SUM_CF.total_cobrado, 0) AS total_cobrado_cliente
                        FROM
                            clientes c
                        LEFT JOIN (
                            SELECT id_cliente, SUM(total_factura_iva_incluido) AS total_facturado_iva_incluido
                            FROM facturas_ventas
                            GROUP BY id_cliente
                        ) AS SUM_FV ON c.id_cliente = SUM_FV.id_cliente
                        LEFT JOIN (
                            SELECT fv.id_cliente, SUM(cf.cantidad_cobrada) AS total_cobrado
                            FROM cobros_factura cf
                            JOIN facturas_ventas fv ON cf.id_factura = fv.id_factura
                            GROUP BY fv.id_cliente
                        ) AS SUM_CF ON c.id_cliente = SUM_CF.id_cliente
                    ";
                    $params = [];
                    $where_clauses = [];

                    if (!empty($search_query)) {
                        $where_clauses[] = "(c.nombre_cliente LIKE ? OR c.nif LIKE ? OR c.ciudad LIKE ? OR c.provincia LIKE ? OR c.telefono LIKE ? OR c.email LIKE ? OR c.observaciones LIKE ?)";
                        $like_param = '%' . $search_query . '%';
                        $params = [$like_param, $like_param, $like_param, $like_param, $like_param, $like_param, $like_param];
                    }

                    if (!empty($where_clauses)) {
                        $sql .= " WHERE " . implode(" AND ", $where_clauses);
                    }

                    $sql .= " GROUP BY c.id_cliente ORDER BY c.nombre_cliente ASC";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $clientes_resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($clientes_resultado as &$cliente_res) {
                        $cliente_res['total_deuda_cliente'] = bcsub((string)$cliente_res['total_facturado_cliente'], (string)$cliente_res['total_cobrado_cliente'], 2);
                    }
                    unset($cliente_res);

                    echo json_encode(['success' => true, 'clientes' => $clientes_resultado]);
                    break;

                case 'get_direcciones_envio_ajax':
                    $id_cliente = $_GET['id_cliente'] ?? null;
                    if (empty($id_cliente)) {
                        throw new Exception("ID de cliente no proporcionado.");
                    }
                    $stmt = $pdo->prepare("SELECT id_direccion_envio, id_cliente, nombre_direccion, direccion, ciudad, provincia, codigo_postal, latitud, longitud, direccion_google_maps, es_principal, telefono, observaciones FROM direcciones_envio WHERE id_cliente = ? ORDER BY es_principal DESC, nombre_direccion ASC");
                    $stmt->execute([$id_cliente]);
                    $direcciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'direcciones' => $direcciones]);
                    break;
            }
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
    }
    exit();
}

// --- Lógica para procesar el formulario de añadir/editar cliente (NO AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_cliente_form = $_POST['id_cliente'] ?? null;
    $nombre_cliente = trim($_POST['nombre_cliente'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $ciudad = trim($_POST['ciudad'] ?? '');
    $provincia = trim($_POST['provincia'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $nif = trim($_POST['nif'] ?? '');
    $latitud = filter_var($_POST['latitud'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['latitud'] : null;
    $longitud = filter_var($_POST['longitud'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['longitud'] : null;
    $direccion_google_maps = trim($_POST['direccion_google_maps'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');

    $nif_db = empty($nif) ? null : $nif;

    if (empty($nombre_cliente)) {
        $mensaje = "Error: El nombre del cliente es obligatorio.";
        $tipo_mensaje = 'danger';
    } else {
        try {
            if ($id_cliente_form) {
                // Modo EDICIÓN
                $stmt = $pdo->prepare("UPDATE clientes SET nombre_cliente = ?, direccion = ?, ciudad = ?, provincia = ?, codigo_postal = ?, telefono = ?, email = ?, nif = ?, latitud = ?, longitud = ?, direccion_google_maps = ?, observaciones = ? WHERE id_cliente = ?");
                $stmt->execute([$nombre_cliente, $direccion, $ciudad, $provincia, $codigo_postal, $telefono, $email, $nif_db, $latitud, $longitud, $direccion_google_maps, $observaciones, $id_cliente_form]);
                $mensaje = "Cliente actualizado correctamente.";
                $tipo_mensaje = 'success';
            } else {
                // Modo AGREGAR
                $stmt = $pdo->prepare("INSERT INTO clientes (nombre_cliente, direccion, ciudad, provincia, codigo_postal, telefono, email, nif, latitud, longitud, direccion_google_maps, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nombre_cliente, $direccion, $ciudad, $provincia, $codigo_postal, $telefono, $email, $nif_db, $latitud, $longitud, $direccion_google_maps, $observaciones]);
                $mensaje = "Cliente agregado correctamente.";
                $tipo_mensaje = 'success';
            }
        } catch (PDOException $e) {
            $mensaje = "Error de base de datos: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }

    $_SESSION['mensaje'] = $mensaje;
    $_SESSION['tipo_mensaje'] = $tipo_mensaje;
    header("Location: clientes.php");
    exit();
}

// Función para mostrar mensajes (usada internamente y para guardar en sesión)
function mostrarMensaje($msg, $type) {
    $_SESSION['mensaje'] = $msg;
    $_SESSION['tipo_mensaje'] = $type;
}

// Cargar mensajes de la sesión si existen
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    $tipo_mensaje = $_SESSION['tipo_mensaje'];
    unset($_SESSION['mensaje']);
    unset($_SESSION['tipo_mensaje']);
}

// --- Lógica para cargar un cliente específico si se pasa un ID por GET ---
$id_cliente_param = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id_cliente_param = $_GET['id'];
    try {
        $stmt_single_client = $pdo->prepare("SELECT * FROM clientes WHERE id_cliente = ?");
        $stmt_single_client->execute([$id_cliente_param]);
        $cliente_a_editar = $stmt_single_client->fetch(PDO::FETCH_ASSOC);
        if (!$cliente_a_editar) {
            $mensaje = "Cliente con ID " . htmlspecialchars($id_cliente_param) . " no encontrado.";
            $tipo_mensaje = 'warning';
        }
    } catch (PDOException $e) {
        $mensaje = "Error al cargar el cliente para edición: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// --- Lógica para cargar clientes con filtro de búsqueda y/o por ID, y calcular deuda total ---
$search_query = $_GET['search'] ?? '';

$sql = "
    SELECT
        c.*,
        COALESCE(SUM_FV.total_facturado_iva_incluido, 0) AS total_facturado_cliente,
        COALESCE(SUM_CF.total_cobrado, 0) AS total_cobrado_cliente
    FROM
        clientes c
    LEFT JOIN (
        SELECT id_cliente, SUM(total_factura_iva_incluido) AS total_facturado_iva_incluido
        FROM facturas_ventas
        GROUP BY id_cliente
    ) AS SUM_FV ON c.id_cliente = SUM_FV.id_cliente
    LEFT JOIN (
        SELECT fv.id_cliente, SUM(cf.cantidad_cobrada) AS total_cobrado
        FROM cobros_factura cf
        JOIN facturas_ventas fv ON cf.id_factura = fv.id_factura
        GROUP BY fv.id_cliente
    ) AS SUM_CF ON c.id_cliente = SUM_CF.id_cliente
";
$params = [];
$where_clauses = [];

// Priorizar el filtro por ID si está presente
if ($id_cliente_param) {
    $where_clauses[] = "c.id_cliente = ?";
    $params[] = $id_cliente_param;
} elseif (!empty($search_query)) { // Si no hay ID, aplicar filtro de búsqueda
    $where_clauses[] = "(c.nombre_cliente LIKE ? OR c.nif LIKE ? OR c.ciudad LIKE ? OR c.provincia LIKE ? OR c.telefono LIKE ? OR c.email LIKE ? OR c.observaciones LIKE ?)";
    $like_param = '%' . $search_query . '%';
    $params = [$like_param, $like_param, $like_param, $like_param, $like_param, $like_param, $like_param];
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " GROUP BY c.id_cliente ORDER BY c.nombre_cliente ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular la deuda final para cada cliente después de la consulta
    foreach ($clientes as &$cliente) {
        $cliente['total_deuda_cliente'] = bcsub((string)$cliente['total_facturado_cliente'], (string)$cliente['total_cobrado_cliente'], 2);
    }
    unset($cliente);
} catch (PDOException $e) {
    $mensaje = "Error al cargar clientes: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Clientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* Estilos generales */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
            display: flex;
            min-height: 100vh;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        .main-content {
            flex-grow: 1;
            padding: 30px;
            background-color: #f4f7f6;
            min-height: 100vh;
        }
        .container-fluid {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 40px;
            max-width: 100%;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            max-width: 100%;
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
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #d39e00;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            font-weight: 600;
            padding: 12px 25px;
            border-radius: 8px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #545b62;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
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
            background-color: #0056b3;
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .alert {
            border-radius: 8px;
        }

        /* Estilos del Sidebar */
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

        /* Estilos para el mapa */
        #map_picker, #map_envio {
            height: 350px;
            width: 100%;
            border-radius: 8px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .pac-container {
            z-index: 1050;
        }

        /* Estilo para la columna de acciones en la tabla */
        .table td.actions-column {
            white-space: nowrap;
            width: 1%;
        }

        /* Nuevos estilos para la búsqueda de direcciones */
        .search-shipping-container {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .search-shipping-container input {
            transition: all 0.3s ease;
        }
        .search-shipping-container input:focus {
            width: 250px !important;
        }
        .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <h1 class="mb-4">Gestión de Clientes</h1>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($mensaje); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    Listado de Clientes
                </div>
                <div class="card-body">
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" placeholder="Buscar por nombre, NIF, ciudad, provincia, teléfono, email u observaciones..." id="search_input" value="<?php echo htmlspecialchars($search_query); ?>">
                        <button class="btn btn-outline-secondary" type="button" id="search_button"><i class="bi bi-search"></i> Buscar</button>
                        <?php if (!empty($search_query) || $id_cliente_param): ?>
                            <a href="clientes.php" class="btn btn-outline-danger"><i class="bi bi-x-circle"></i> Limpiar Filtros</a>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <div style="max-height: 250px; overflow-y: auto; border: 1px solid #ccc; margin-bottom: 1em;">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre</th>
                                        <th>NIF</th>
                                        <th>Ciudad</th>
                                        <th>Provincia</th>
                                        <th>Teléfono</th>
                                        <th>Dir. Google Maps</th>
                                        <th>Observaciones</th>
                                        <th class="text-end">Deuda Total</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="clients_table_body">
                                    <?php if (empty($clientes)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center">No hay clientes registrados o no se encontraron resultados.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($clientes as $cliente):
                                            $deuda_class = (bccomp((string)$cliente['total_deuda_cliente'], "0", 2) > 0) ? 'text-danger' : 'text-success';
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($cliente['id_cliente']); ?></td>
                                                <td><?php echo htmlspecialchars($cliente['nombre_cliente']); ?></td>
                                                <td><?php echo htmlspecialchars($cliente['nif'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($cliente['ciudad'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($cliente['provincia'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($cliente['direccion_google_maps'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($cliente['observaciones'] ?? 'N/A'); ?></td>
                                                <td class="text-end <?php echo $deuda_class; ?>">
                                                    <strong><?php echo number_format($cliente['total_deuda_cliente'], 2, ',', '.'); ?> €</strong>
                                                </td>
                                                <td class="text-center actions-column">
                                                    <div class="d-flex gap-2 justify-content-center">
                                                        <button type="button" class="btn btn-warning btn-sm" onclick='editClient(<?php echo json_encode($cliente); ?>)' title="Editar Cliente">
                                                            <i class="bi bi-pencil"></i> Editar
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nueva Sección de Acciones (Visible solo al editar un cliente) -->
            <div class="card mb-4" id="client_actions_card" style="display:none;">
                <div class="card-header">
                    Acciones del Cliente
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <button type="button" class="btn btn-info btn-lg" id="btn_view_facturas" title="Ver Detalles y Facturas">
                            <i class="bi bi-receipt"></i> Ver Facturas
                        </button>
                        <button type="button" class="btn btn-primary btn-lg" id="btn_new_factura" title="Crear Nueva Factura">
                            <i class="bi bi-file-earmark-plus"></i> Nueva Factura
                        </button>
                        <button type="button" class="btn btn-success btn-lg" id="btn_new_pedido" title="Crear Nuevo Pedido">
                            <i class="bi bi-cart-plus"></i> Nuevo Pedido
                        </button>
                    </div>
                </div>
            </div>

            <!-- Nueva Sección de Actividad Reciente (Visible solo al editar un cliente) -->
            <div class="card mb-4" id="client_recent_activity_card" style="display:none;">
                <div class="card-header">
                    Actividad Reciente del Cliente
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Últimas Facturas</h5>
                            <ul class="list-group list-group-flush" id="recent_invoices_list">
                                <li class="list-group-item text-muted">Cargando facturas...</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h5>Últimos Pedidos</h5>
                            <ul class="list-group list-group-flush" id="recent_orders_list">
                                <li class="list-group-item text-muted">Cargando pedidos...</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nueva Sección de Direcciones de Envío con campo de búsqueda -->
            <div class="card mb-4" id="client_shipping_addresses_card" style="display:none;">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Direcciones de Envío</span>
                    <div class="search-shipping-container">
                        <input type="text" class="form-control" id="search_shipping_address" placeholder="Buscar direcciones..." style="width: 200px;">
                        <button type="button" class="btn btn-success" onclick="openAddEditDireccionEnvioModal(null)">
                            <i class="bi bi-plus-circle"></i> Añadir Dirección
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <div style="max-height: 250px; overflow-y: auto; border: 1px solid #ccc; margin-bottom: 1em;">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Nombre Dirección</th>
                                        <th>Dirección Completa</th>
                                        <th>Teléfono</th>
                                        <th>Observaciones</th>
                                        <th>Principal</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="shipping_addresses_list">
                                    <tr><td colspan="6" class="text-center text-muted">Cargando direcciones de envío...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    Añadir/Editar Cliente
                </div>
                <div class="card-body">
                    <form action="clientes.php" method="POST">
                        <input type="hidden" name="action" value="save_cliente">
                        <input type="hidden" id="id_cliente_form" name="id_cliente" value="<?php echo htmlspecialchars($cliente_a_editar['id_cliente'] ?? ''); ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="nombre_cliente" class="form-label">Nombre Cliente <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nombre_cliente" name="nombre_cliente" value="<?php echo htmlspecialchars($cliente_a_editar['nombre_cliente'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="nif" class="form-label">NIF</label>
                                <input type="text" class="form-control" id="nif" name="nif" value="<?php echo htmlspecialchars($cliente_a_editar['nif'] ?? ''); ?>">
                                <small class="form-text text-muted">El NIF ya no es único y puede dejarse vacío.</small>
                            </div>
                            <div class="col-md-12">
                                <label for="direccion" class="form-label">Dirección (Introducida por el usuario)</label>
                                <input type="text" class="form-control" id="direccion" name="direccion" value="<?php echo htmlspecialchars($cliente_a_editar['direccion'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="ciudad" class="form-label">Ciudad</label>
                                <input type="text" class="form-control" id="ciudad" name="ciudad" value="<?php echo htmlspecialchars($cliente_a_editar['ciudad'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="provincia" class="form-label">Provincia</label>
                                <input type="text" class="form-control" id="provincia" name="provincia" value="<?php echo htmlspecialchars($cliente_a_editar['provincia'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="codigo_postal" class="form-label">Código Postal</label>
                                <input type="text" class="form-control" id="codigo_postal" name="codigo_postal" value="<?php echo htmlspecialchars($cliente_a_editar['codigo_postal'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="telefono" class="form-label">Teléfono</label>
                                <input type="text" class="form-control" id="telefono" name="telefono" value="<?php echo htmlspecialchars($cliente_a_editar['telefono'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($cliente_a_editar['email'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label for="observaciones" class="form-label">Observaciones del Cliente</label>
                                <textarea class="form-control" id="observaciones" name="observaciones" rows="3"><?php echo htmlspecialchars($cliente_a_editar['observaciones'] ?? ''); ?></textarea>
                                <small class="form-text text-muted">Notas internas sobre el cliente.</small>
                            </div>

                            <div class="col-12">
                                <hr class="my-4">
                                <h5>Ubicación en el Mapa (Google Maps)</h5>
                                <div class="mb-3">
                                    <label for="search_address_input" class="form-label">Buscar Dirección para el Mapa</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="search_address_input" placeholder="Escribe una dirección para buscar...">
                                        <button class="btn btn-outline-secondary" type="button" onclick="geocodeAddress()"><i class="bi bi-geo-alt"></i> Obtener Coordenadas desde Dirección Manual</button>
                                    </div>
                                </div>
                                <div id="map_picker"></div>
                                <div class="row mb-3 mt-3">
                                    <div class="col-md-6">
                                        <label for="latitud" class="form-label">Latitud</label>
                                        <input type="text" class="form-control" id="latitud" name="latitud" value="<?php echo htmlspecialchars($cliente_a_editar['latitud'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="longitud" class="form-label">Longitud</label>
                                        <input type="text" class="form-control" id="longitud" name="longitud" value="<?php echo htmlspecialchars($cliente_a_editar['longitud'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-12 mt-3">
                                        <label for="direccion_google_maps" class="form-label">Dirección Formateada por Google Maps</label>
                                        <input type="text" class="form-control" id="direccion_google_maps" name="direccion_google_maps" value="<?php echo htmlspecialchars($cliente_a_editar['direccion_google_maps'] ?? ''); ?>" readonly>
                                        <small class="form-text text-muted">Esta dirección es la que Google Maps ha reconocido y es la más precisa para la geolocalización.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar Cliente</button>
                                <button type="button" class="btn btn-secondary" onclick="resetForm()"><i class="bi bi-arrow-counterclockwise"></i> Limpiar</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="text-center mt-4">
                <a href="index.php" class="btn btn-secondary"><i class="bi bi-house"></i> Volver al Inicio</a>
            </div>
        </div>
    </div>

    <!-- Modal para Detalles y Edición de Cliente (Cargado dinámicamente) -->
    <div class="modal fade" id="clientDetailsModal" tabindex="-1" aria-labelledby="clientDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" id="clientDetailsModalBody">
                <!-- Contenido cargado dinámicamente desde cliente_details.php -->
                Cargando...
            </div>
        </div>
    </div>

    <!-- Modal para Añadir/Editar Dirección de Envío -->
    <div class="modal fade" id="addEditDireccionEnvioModal" tabindex="-1" aria-labelledby="addEditDireccionEnvioModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="direccionEnvioForm" action="clientes.php" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addEditDireccionEnvioModalLabel">Añadir/Editar Dirección de Envío</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_direccion_envio">
                        <input type="hidden" name="id_cliente" id="id_cliente_direccion_envio">
                        <input type="hidden" name="id_direccion_envio" id="id_direccion_envio">

                        <!-- Acciones para la dirección de envío -->
                        <div class="card mb-3" id="shipping_address_actions_card" style="display:none;">
                            <div class="card-header bg-secondary text-white">
                                Acciones para esta Dirección
                            </div>
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2 justify-content-center">
                                    <button type="button" class="btn btn-info" id="btn_view_invoices_address" title="Ver Facturas de esta Dirección">
                                        <i class="bi bi-receipt"></i> Ver Facturas
                                    </button>
                                    <button type="button" class="btn btn-primary" id="btn_new_invoice_address" title="Crear Factura para esta Dirección">
                                        <i class="bi bi-file-earmark-plus"></i> Nueva Factura
                                    </button>
                                    <button type="button" class="btn btn-success" id="btn_new_order_address" title="Crear Pedido para esta Dirección">
                                        <i class="bi bi-cart-plus"></i> Nuevo Pedido
                                    </button>
                                </div>
                            </div>
                        </div>
                        <hr id="shipping_address_actions_hr" style="display:none;" class="my-4">

                        <div class="mb-3">
                            <label for="nombre_direccion" class="form-label">Nombre de la Dirección <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nombre_direccion" name="nombre_direccion" required>
                            <small class="form-text text-muted">Ej: "Almacén Principal", "Oficina de Juan", "Casa de Verano"</small>
                        </div>
                        <div class="mb-3">
                            <label for="direccion_envio" class="form-label">Dirección <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="direccion_envio" name="direccion_envio" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="ciudad_envio" class="form-label">Ciudad</label>
                                <input type="text" class="form-control" id="ciudad_envio" name="ciudad_envio">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="provincia_envio" class="form-label">Provincia</label>
                                <input type="text" class="form-control" id="provincia_envio" name="provincia_envio">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="codigo_postal_envio" class="form-label">Código Postal</label>
                            <input type="text" class="form-control" id="codigo_postal_envio" name="codigo_postal_envio">
                        </div>
                        <div class="mb-3">
                            <label for="telefono_envio" class="form-label">Teléfono</label>
                            <input type="text" class="form-control" id="telefono_envio" name="telefono_envio">
                        </div>
                        <div class="mb-3">
                            <label for="observaciones_envio" class="form-label">Observaciones</label>
                            <textarea class="form-control" id="observaciones_envio" name="observaciones_envio" rows="3"></textarea>
                        </div>

                        <hr class="my-4">
                        <h5>Ubicación en el Mapa para Envío</h5>
                        <div class="mb-3">
                            <label for="search_address_input_envio" class="form-label">Buscar Dirección para el Mapa</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="search_address_input_envio" placeholder="Escribe una dirección para buscar...">
                                <button class="btn btn-outline-secondary" type="button" onclick="geocodeAddressEnvio()"><i class="bi bi-geo-alt"></i> Obtener Coordenadas</button>
                            </div>
                        </div>
                        <div id="map_envio"></div>
                        <div class="row mb-3 mt-3">
                            <div class="col-md-6">
                                <label for="latitud_envio" class="form-label">Latitud</label>
                                <input type="text" class="form-control" id="latitud_envio" name="latitud_envio" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="longitud_envio" class="form-label">Longitud</label>
                                <input type="text" class="form-control" id="longitud_envio" name="longitud_envio" readonly>
                            </div>
                            <div class="col-12 mt-3">
                                <label for="direccion_google_maps_envio" class="form-label">Dirección Formateada por Google Maps</label>
                                <input type="text" class="form-control" id="direccion_google_maps_envio" name="direccion_google_maps_envio" readonly>
                                <small class="form-text text-muted">Esta dirección es la que Google Maps ha reconocido y es la más precisa para la geolocalización.</small>
                            </div>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1" id="es_principal" name="es_principal">
                            <label class="form-check-label" for="es_principal">
                                Marcar como dirección principal de envío
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Dirección</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        // Variables globales para el mapa de selección de coordenadas del cliente principal
        let mapPicker;
        let markerPicker;
        let latInput;
        let lngInput;
        let searchAddressInput;
        let direccionGoogleMapsInput;
        let geocoder;

        // Variables globales para el mapa de selección de coordenadas de dirección de envío
        let mapEnvio;
        let markerEnvio;
        let latInputEnvio;
        let lngInputEnvio;
        let searchAddressInputEnvio;
        let direccionGoogleMapsInputEnvio;

        // Función de inicialización del mapa de selección (callback de Google Maps API)
        window.initMapPicker = function() {
            latInput = document.getElementById('latitud');
            lngInput = document.getElementById('longitud');
            searchAddressInput = document.getElementById('search_address_input');
            direccionGoogleMapsInput = document.getElementById('direccion_google_maps');

            const initialLat = parseFloat(latInput.value) || 37.3828300;
            const initialLng = parseFloat(lngInput.value) || -5.9731700;

            mapPicker = new google.maps.Map(document.getElementById('map_picker'), {
                center: { lat: initialLat, lng: initialLng },
                zoom: 12,
                mapId: 'DEMO_MAP_ID'
            });

            markerPicker = new google.maps.marker.AdvancedMarkerElement({
                map: mapPicker,
                position: { lat: initialLat, lng: initialLng },
                gmpDraggable: true
            });

            geocoder = new google.maps.Geocoder();

            google.maps.event.addListener(markerPicker, 'dragend', function() {
                const newPosition = markerPicker.position;
                latInput.value = newPosition.lat;
                lngInput.value = newPosition.lng;
                geocodeLatLng(newPosition.lat, newPosition.lng, direccionGoogleMapsInput);
            });

            if (latInput.value && lngInput.value) {
                const currentPosition = { lat: initialLat, lng: initialLng };
                markerPicker.position = currentPosition;
                mapPicker.setCenter(currentPosition);
                mapPicker.setZoom(15);
            }

            const searchBox = new google.maps.places.SearchBox(searchAddressInput);
            mapPicker.addListener('bounds_changed', () => {
                searchBox.setBounds(mapPicker.getBounds());
            });

            searchBox.addListener('places_changed', () => {
                const places = searchBox.getPlaces();
                if (places.length == 0) return;

                const bounds = new google.maps.LatLngBounds();
                places.forEach(place => {
                    if (!place.geometry || !place.geometry.location) return;
                    markerPicker.position = place.geometry.location;
                    latInput.value = place.geometry.location.lat();
                    lngInput.value = place.geometry.location.lng();
                    direccionGoogleMapsInput.value = place.formatted_address || place.name;
                    if (place.geometry.viewport) {
                        bounds.union(place.geometry.viewport);
                    } else {
                        bounds.extend(place.geometry.location);
                    }
                });
                mapPicker.fitBounds(bounds);
            });

            // Inicializar el mapa de direcciones de envío
            initMapEnvio();
        };

        // Función de inicialización del mapa para direcciones de envío
        function initMapEnvio() {
            latInputEnvio = document.getElementById('latitud_envio');
            lngInputEnvio = document.getElementById('longitud_envio');
            searchAddressInputEnvio = document.getElementById('search_address_input_envio');
            direccionGoogleMapsInputEnvio = document.getElementById('direccion_google_maps_envio');

            const initialLatEnvio = parseFloat(latInputEnvio.value) || 37.3828300;
            const initialLngEnvio = parseFloat(lngInputEnvio.value) || -5.9731700;

            mapEnvio = new google.maps.Map(document.getElementById('map_envio'), {
                center: { lat: initialLatEnvio, lng: initialLngEnvio },
                zoom: 12,
                mapId: 'DEMO_MAP_ID'
            });

            markerEnvio = new google.maps.marker.AdvancedMarkerElement({
                map: mapEnvio,
                position: { lat: initialLatEnvio, lng: initialLngEnvio },
                gmpDraggable: true
            });

            google.maps.event.addListener(markerEnvio, 'dragend', function() {
                const newPosition = markerEnvio.position;
                latInputEnvio.value = newPosition.lat;
                lngInputEnvio.value = newPosition.lng;
                geocodeLatLng(newPosition.lat, newPosition.lng, direccionGoogleMapsInputEnvio);
            });

            const searchBoxEnvio = new google.maps.places.SearchBox(searchAddressInputEnvio);
            mapEnvio.addListener('bounds_changed', () => {
                searchBoxEnvio.setBounds(mapEnvio.getBounds());
            });

            searchBoxEnvio.addListener('places_changed', () => {
                const places = searchBoxEnvio.getPlaces();
                if (places.length == 0) return;

                const bounds = new google.maps.LatLngBounds();
                places.forEach(place => {
                    if (!place.geometry || !place.geometry.location) return;
                    markerEnvio.position = place.geometry.location;
                    latInputEnvio.value = place.geometry.location.lat();
                    lngInputEnvio.value = place.geometry.location.lng();
                    direccionGoogleMapsInputEnvio.value = place.formatted_address || place.name;
                    if (place.geometry.viewport) {
                        bounds.union(place.geometry.viewport);
                    } else {
                        bounds.extend(place.geometry.location);
                    }
                });
                mapEnvio.fitBounds(bounds);
            });
        }

        // Función para geocodificar la dirección a partir de los campos del formulario principal
        function geocodeAddress() {
            const direccion = document.getElementById('direccion').value;
            const codigoPostal = document.getElementById('codigo_postal').value;
            const ciudad = document.getElementById('ciudad').value;
            const provincia = document.getElementById('provincia').value;

            const fullAddress = `${direccion}, ${codigoPostal}, ${ciudad}, ${provincia}, España`;

            if (!geocoder) {
                showCustomAlert('El servicio de mapas no está listo. Inténtelo de nuevo en unos segundos.');
                return;
            }

            geocoder.geocode({ 'address': fullAddress }, function(results, status) {
                if (status === 'OK') {
                    const location = results[0].geometry.location;
                    latInput.value = location.lat();
                    lngInput.value = location.lng();

                    markerPicker.position = location;
                    mapPicker.setCenter(location);
                    mapPicker.setZoom(15);

                    direccionGoogleMapsInput.value = results[0].formatted_address;
                    showCustomAlert('Coordenadas y dirección de Google Maps obtenidas correctamente.');
                } else {
                    showCustomAlert('Geocodificación fallida por la siguiente razón: ' + status + '. Por favor, revise la dirección o seleccione manualmente en el mapa.');
                    latInput.value = '';
                    lngInput.value = '';
                    direccionGoogleMapsInput.value = '';
                }
            });
        }

        // Función para geocodificar la dirección a partir de los campos del modal de dirección de envío
        function geocodeAddressEnvio() {
            const direccion = document.getElementById('direccion_envio').value;
            const codigoPostal = document.getElementById('codigo_postal_envio').value;
            const ciudad = document.getElementById('ciudad_envio').value;
            const provincia = document.getElementById('provincia_envio').value;

            const fullAddress = `${direccion}, ${codigoPostal}, ${ciudad}, ${provincia}, España`;

            if (!geocoder) {
                showCustomAlert('El servicio de mapas no está listo. Inténtelo de nuevo en unos segundos.');
                return;
            }

            geocoder.geocode({ 'address': fullAddress }, function(results, status) {
                if (status === 'OK') {
                    const location = results[0].geometry.location;
                    latInputEnvio.value = location.lat();
                    lngInputEnvio.value = location.lng();

                    markerEnvio.position = location;
                    mapEnvio.setCenter(location);
                    mapEnvio.setZoom(15);

                    direccionGoogleMapsInputEnvio.value = results[0].formatted_address;
                    showCustomAlert('Coordenadas y dirección de Google Maps obtenidas correctamente para la dirección de envío.');
                } else {
                    showCustomAlert('Geocodificación de dirección de envío fallida por la siguiente razón: ' + status + '. Por favor, revise la dirección o seleccione manualmente en el mapa.');
                    latInputEnvio.value = '';
                    lngInputEnvio.value = '';
                    direccionGoogleMapsInputEnvio.value = '';
                }
            });
        }

        // Función para geocodificación inversa (obtener dirección a partir de coordenadas)
        function geocodeLatLng(lat, lng, targetInput) {
            if (!geocoder) return;
            const latlng = { lat: parseFloat(lat), lng: parseFloat(lng) };
            geocoder.geocode({ 'location': latlng }, function(results, status) {
                if (status === 'OK' && results[0]) {
                    targetInput.value = results[0].formatted_address;
                } else {
                    targetInput.value = 'Dirección no encontrada';
                }
            });
        }

        // Función para cargar los datos de un cliente en el formulario para edición
        async function editClient(cliente) {
            document.getElementById('id_cliente_form').value = cliente.id_cliente;
            document.getElementById('nombre_cliente').value = cliente.nombre_cliente;
            document.getElementById('direccion').value = cliente.direccion || '';
            document.getElementById('ciudad').value = cliente.ciudad || '';
            document.getElementById('provincia').value = cliente.provincia || '';
            document.getElementById('codigo_postal').value = cliente.codigo_postal || '';
            document.getElementById('telefono').value = cliente.telefono || '';
            document.getElementById('email').value = cliente.email || '';
            document.getElementById('nif').value = cliente.nif || '';
            document.getElementById('observaciones').value = cliente.observaciones || '';

            const lat = parseFloat(cliente.latitud);
            const lng = parseFloat(cliente.longitud);

            if (!isNaN(lat) && !isNaN(lng)) {
                document.getElementById('latitud').value = lat;
                document.getElementById('longitud').value = lng;
                document.getElementById('direccion_google_maps').value = cliente.direccion_google_maps || '';

                if (mapPicker && markerPicker) {
                    const newPosition = { lat: lat, lng: lng };
                    markerPicker.position = newPosition;
                    mapPicker.setCenter(newPosition);
                    mapPicker.setZoom(15);
                }
            } else {
                document.getElementById('latitud').value = '';
                document.getElementById('longitud').value = '';
                document.getElementById('direccion_google_maps').value = '';

                if (mapPicker && markerPicker) {
                    const defaultLatLng = { lat: 37.3828300, lng: -5.9731700 };
                    markerPicker.position = defaultLatLng;
                    mapPicker.setCenter(defaultLatLng);
                    mapPicker.setZoom(12);
                }
            }
            document.getElementById('search_address_input').value = '';

            const clientActionsCard = document.getElementById('client_actions_card');
            if (clientActionsCard) {
                clientActionsCard.style.display = 'block';
                document.getElementById('btn_view_facturas').onclick = () => openClientDetailsModal(cliente.id_cliente);
                document.getElementById('btn_new_factura').onclick = () => createNewInvoice(cliente.id_cliente, cliente.nombre_cliente);
                document.getElementById('btn_new_pedido').onclick = () => createNewOrder(cliente.id_cliente, cliente.nombre_cliente);
            }

            const clientRecentActivityCard = document.getElementById('client_recent_activity_card');
            const recentInvoicesList = document.getElementById('recent_invoices_list');
            const recentOrdersList = document.getElementById('recent_orders_list');

            if (clientRecentActivityCard) {
                clientRecentActivityCard.style.display = 'block';
                recentInvoicesList.innerHTML = '<li class="list-group-item text-muted">Cargando facturas...</li>';
                recentOrdersList.innerHTML = '<li class="list-group-item text-muted">Cargando pedidos...</li>';

                try {
                    const formData = new FormData();
                    formData.append('accion', 'get_client_activity');
                    formData.append('id_cliente', cliente.id_cliente);

                    const response = await fetch('clientes.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();

                    if (data.success) {
                        if (data.invoices && data.invoices.length > 0) {
                            recentInvoicesList.innerHTML = '';
                            data.invoices.forEach(invoice => {
                                const listItem = document.createElement('li');
                                listItem.classList.add('list-group-item', 'd-flex', 'justify-content-between', 'align-items-center');
                                const paymentStatusClass = invoice.pendiente_pago === 'Sí' ? 'bg-danger' : 'bg-success';
                                listItem.innerHTML = `
                                    <span>Factura #${invoice.id_factura} (${new Date(invoice.fecha_factura).toLocaleDateString('es-ES')})</span>
                                    <div>
                                        <span class="badge ${paymentStatusClass} me-2">Pago: ${invoice.pendiente_pago}</span>
                                        <span class="badge bg-primary rounded-pill">${parseFloat(invoice.total_factura_iva_incluido).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €</span>
                                        <a href="facturas_ventas.php?view=details&id=${invoice.id_factura}" class="btn btn-sm btn-outline-info ms-2" title="Ver Factura"><i class="bi bi-eye"></i></a>
                                    </div>
                                `;
                                recentInvoicesList.appendChild(listItem);
                            });
                        } else {
                            recentInvoicesList.innerHTML = '<li class="list-group-item text-muted">No hay facturas recientes.</li>';
                        }

                        if (data.orders && data.orders.length > 0) {
                            recentOrdersList.innerHTML = '';
                            data.orders.forEach(order => {
                                const listItem = document.createElement('li');
                                listItem.classList.add('list-group-item', 'd-flex', 'justify-content-between', 'align-items-center');
                                const orderStatusClass = getOrderStatusBadgeClass(order.estado_pedido);
                                listItem.innerHTML = `
                                    <span>Pedido #${order.id_pedido} (${new Date(order.fecha_pedido).toLocaleDateString('es-ES')})</span>
                                    <div>
                                        <span class="badge ${orderStatusClass} me-2">${order.estado_pedido.replace(/_/g, ' ').charAt(0).toUpperCase() + order.estado_pedido.replace(/_/g, ' ').slice(1)}</span>
                                        <span class="badge bg-success rounded-pill">${parseFloat(order.total_pedido_iva_incluido).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €</span>
                                        <a href="pedidos.php?view=details&id=${order.id_pedido}" class="btn btn-sm btn-outline-info ms-2" title="Ver Pedido"><i class="bi bi-eye"></i></a>
                                    </div>
                                `;
                                recentOrdersList.appendChild(listItem);
                            });
                        } else {
                            recentOrdersList.innerHTML = '<li class="list-group-item text-muted">No hay pedidos recientes.</li>';
                        }

                    } else {
                        recentInvoicesList.innerHTML = `<li class="list-group-item text-danger">Error al cargar facturas: ${data.message}</li>`;
                        recentOrdersList.innerHTML = `<li class="list-group-item text-danger">Error al cargar pedidos: ${data.message}</li>`;
                    }
                } catch (error) {
                    console.error('Error fetching client activity:', error);
                    recentInvoicesList.innerHTML = '<li class="list-group-item text-danger">Error de red al cargar facturas.</li>';
                    recentOrdersList.innerHTML = '<li class="list-group-item text-danger">Error de red al cargar pedidos.</li>';
                }
            }

            // Mostrar y cargar direcciones de envío
            const clientShippingAddressesCard = document.getElementById('client_shipping_addresses_card');
            if (clientShippingAddressesCard) {
                clientShippingAddressesCard.style.display = 'block';
                loadShippingAddresses(cliente.id_cliente);
            }

            window.scrollTo(0, 0);
        }

        // Helper function for order status badge class
        function getOrderStatusBadgeClass(status) {
            switch (status) {
                case 'pendiente': return 'bg-warning text-dark';
                case 'completado': return 'bg-success';
                case 'cancelado': return 'bg-danger';
                case 'en_transito_paqueteria': return 'bg-info';
                case 'parcialmente_facturado': return 'bg-primary';
                default: return 'bg-secondary';
            }
        }

        // Función para limpiar el formulario
        function resetForm() {
            document.getElementById('id_cliente_form').value = '';
            document.getElementById('nombre_cliente').value = '';
            document.getElementById('direccion').value = '';
            document.getElementById('ciudad').value = '';
            document.getElementById('provincia').value = '';
            document.getElementById('codigo_postal').value = '';
            document.getElementById('telefono').value = '';
            document.getElementById('email').value = '';
            document.getElementById('nif').value = '';
            document.getElementById('observaciones').value = '';
            document.getElementById('latitud').value = '';
            document.getElementById('longitud').value = '';
            document.getElementById('direccion_google_maps').value = '';

            const clientActionsCard = document.getElementById('client_actions_card');
            if (clientActionsCard) {
                clientActionsCard.style.display = 'none';
                document.getElementById('btn_view_facturas').onclick = null;
                document.getElementById('btn_new_factura').onclick = null;
                document.getElementById('btn_new_pedido').onclick = null;
            }

            const clientRecentActivityCard = document.getElementById('client_recent_activity_card');
            const recentInvoicesList = document.getElementById('recent_invoices_list');
            const recentOrdersList = document.getElementById('recent_orders_list');
            if (clientRecentActivityCard) {
                clientRecentActivityCard.style.display = 'none';
                if (recentInvoicesList) recentInvoicesList.innerHTML = '';
                if (recentOrdersList) recentOrdersList.innerHTML = '';
            }

            const clientShippingAddressesCard = document.getElementById('client_shipping_addresses_card');
            if (clientShippingAddressesCard) {
                clientShippingAddressesCard.style.display = 'none';
                document.getElementById('shipping_addresses_list').innerHTML = '<tr><td colspan="6" class="text-center text-muted">Cargando direcciones de envío...</td></tr>';
            }

            if (markerPicker) {
                const defaultLatLng = { lat: 37.3828300, lng: -5.9731700 };
                markerPicker.position = defaultLatLng;
                mapPicker.setCenter(defaultLatLng);
                mapPicker.setZoom(12);
            }
            document.getElementById('search_address_input').value = '';
            window.location.href = 'clientes.php';
        }

        // Función para abrir el modal de detalles del cliente
        async function openClientDetailsModal(clientId) {
            const clientDetailsModal = new bootstrap.Modal(document.getElementById('clientDetailsModal'));
            const modalBody = document.getElementById('clientDetailsModalBody');
            modalBody.innerHTML = 'Cargando...';

            try {
                const response = await fetch('cliente_details.php?id=' + clientId);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const htmlContent = await response.text();
                modalBody.innerHTML = htmlContent;

                const alertElement = modalBody.querySelector('.alert');
                if (alertElement) {
                    new bootstrap.Alert(alertElement);
                }

                const editClientFormInModal = document.getElementById('editClientForm');
                if (editClientFormInModal) {
                    editClientFormInModal.addEventListener('submit', async function(event) {
                        event.preventDefault();

                        const formData = new FormData(this);
                        const currentClientId = formData.get('id_cliente');

                        try {
                            const updateResponse = await fetch('cliente_details.php?id=' + currentClientId, {
                                method: 'POST',
                                body: formData
                            });
                            const updateResponseText = await updateResponse.text();
                            document.getElementById('clientDetailsModalBody').innerHTML = updateResponseText;

                            const updatedAlertElement = document.querySelector('#clientDetailsModalBody .alert');
                            if (updatedAlertElement) {
                                new bootstrap.Alert(updatedAlertElement);
                            }

                        } catch (error) {
                            console.error('Error updating client from modal:', error);
                            const modalBodyCurrent = document.getElementById('clientDetailsModalBody');
                            modalBodyCurrent.innerHTML = `<div class="modal-header bg-danger text-white"><h5 class="modal-title">Error</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="text-danger">Error al guardar los cambios del cliente: ${error.message}</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>`;
                        }
                    });
                }

            } catch (error) {
                console.error('Error loading client details:', error);
                modalBody.innerHTML = `<div class="modal-header bg-danger text-white"><h5 class="modal-title">Error</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="text-danger">No se pudo cargar los detalles del cliente: ${error.message}</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>`;
            }
            clientDetailsModal.show();
        }

        // Función para redirigir a facturas_ventas.php con el ID y nombre del cliente
        function createNewInvoice(clientId, clientName) {
            window.location.href = 'facturas_ventas.php?new_invoice_client_id=' + clientId + '&new_invoice_client_name=' + encodeURIComponent(clientName);
        }

        // Función para redirigir a pedidos.php con el ID y nombre del cliente y abrir el modal
        function createNewOrder(clientId, clientName) {
            window.location.href = 'pedidos.php?new_invoice_client_id=' + clientId + '&new_invoice_client_name=' + encodeURIComponent(clientName) + '&open_modal=true';
        }

        // --- Nuevas funciones para acciones de dirección de envío ---
        function viewInvoicesForAddress(shippingAddressId) {
            window.location.href = `facturas_ventas.php?id_direccion_envio=${shippingAddressId}`;
        }

        function createNewInvoiceForAddress(clientId, shippingAddressId) {
            const clientName = document.getElementById('nombre_cliente').value;
            window.location.href = `facturas_ventas.php?new_invoice_client_id=${clientId}&new_invoice_client_name=${encodeURIComponent(clientName)}&id_direccion_envio=${shippingAddressId}`;
        }

        function createNewOrderForAddress(clientId, shippingAddressId) {
            const clientName = document.getElementById('nombre_cliente').value;
            window.location.href = `pedidos.php?new_invoice_client_id=${clientId}&new_invoice_client_name=${encodeURIComponent(clientName)}&id_direccion_envio=${shippingAddressId}&open_modal=true`;
        }

        // Función para mostrar un mensaje personalizado (reemplazo de alert y confirm)
        function showCustomAlert(message, type = 'info', callback = null) {
            const alertContainer = document.querySelector('.container-fluid');
            const alertId = 'customAlert-' + Date.now();
            const isConfirm = (type === 'confirm');

            let alertHtml = `
                <div id="${alertId}" class="alert alert-${isConfirm ? 'warning' : type} alert-dismissible fade show" role="alert">
                    ${message}
                    ${isConfirm ? `
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-success me-2" data-response="true">Sí</button>
                            <button type="button" class="btn btn-sm btn-danger" data-response="false">No</button>
                        </div>
                    ` : `
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    `}
                </div>
            `;
            alertContainer.insertAdjacentHTML('afterbegin', alertHtml);

            const newAlert = document.getElementById(alertId);

            if (isConfirm) {
                newAlert.querySelectorAll('button').forEach(button => {
                    button.addEventListener('click', () => {
                        const response = button.dataset.response === 'true';
                        const bsAlert = bootstrap.Alert.getInstance(newAlert) || new bootstrap.Alert(newAlert);
                        bsAlert.close();
                        if (callback) {
                            callback(response);
                        }
                    });
                });
            } else {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getInstance(newAlert) || new bootstrap.Alert(newAlert);
                    bsAlert.close();
                }, 5000);
            }
        }

        // --- Funcionalidad de búsqueda en tiempo real ---
        const searchInput = document.getElementById('search_input');
        const clientsTableBody = document.getElementById('clients_table_body');
        const searchButton = document.getElementById('search_button');

        let searchTimeout;

        function renderClientsTable(clients) {
            let html = '';
            if (clients.length === 0) {
                html = `<tr><td colspan="10" class="text-center">No hay clientes registrados o no se encontraron resultados.</td></tr>`;
            } else {
                clients.forEach(cliente => {
                    const deudaClass = (parseFloat(cliente.total_deuda_cliente) > 0) ? 'text-danger' : 'text-success';
                    html += `
                        <tr>
                            <td>${cliente.id_cliente}</td>
                            <td>${cliente.nombre_cliente}</td>
                            <td>${cliente.nif || 'N/A'}</td>
                            <td>${cliente.ciudad || 'N/A'}</td>
                            <td>${cliente.provincia || 'N/A'}</td>
                            <td>${cliente.telefono || 'N/A'}</td>
                            <td>${cliente.direccion_google_maps || 'N/A'}</td>
                            <td>${cliente.observaciones || 'N/A'}</td>
                            <td class="text-end ${deudaClass}">
                                <strong>${parseFloat(cliente.total_deuda_cliente).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €</strong>
                            </td>
                            <td class="text-center actions-column">
                                <div class="d-flex gap-2 justify-content-center">
                                    <button type="button" class="btn btn-warning btn-sm" onclick='editClient(${JSON.stringify(cliente)})' title="Editar Cliente">
                                        <i class="bi bi-pencil"></i> Editar
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                });
            }
            clientsTableBody.innerHTML = html;
        }

        async function performSearch() {
            const query = searchInput.value;
            try {
                const response = await fetch(`clientes.php?accion=buscar_ajax&search=${encodeURIComponent(query)}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();
                if (data.success) {
                    renderClientsTable(data.clientes);
                } else {
                    console.error('Error al buscar clientes:', data.message);
                    clientsTableBody.innerHTML = `<tr><td colspan="10" class="text-center text-danger">Error al cargar los clientes: ${data.message}</td></tr>`;
                }
            } catch (error) {
                console.error('Error en la petición de búsqueda:', error);
                clientsTableBody.innerHTML = `<tr><td colspan="10" class="text-center text-danger">Error de red o servidor al buscar clientes.</td></tr>`;
            }
        }

        searchInput.addEventListener('keyup', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(performSearch, 300);
        });

        searchButton.addEventListener('click', performSearch);

        // --- Funcionalidad de Direcciones de Envío ---
        let currentEditingDireccionEnvio = null;

        // Función para configurar la búsqueda de direcciones de envío
        function setupShippingAddressSearch() {
            const searchInput = document.getElementById('search_shipping_address');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('#shipping_addresses_list tr');
                    
                    rows.forEach(row => {
                        if (row.querySelector('td')) {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(searchTerm) ? '' : 'none';
                        }
                    });
                });
            }
        }

        async function loadShippingAddresses(clientId) {
            const shippingAddressesList = document.getElementById('shipping_addresses_list');
            shippingAddressesList.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Cargando direcciones de envío...</td></tr>';
            
            try {
                const response = await fetch(`clientes.php?accion=get_direcciones_envio_ajax&id_cliente=${clientId}`);
                const data = await response.json();

                if (data.success && data.direcciones.length > 0) {
                    let html = '';
                    data.direcciones.forEach(dir => {
                        const isPrincipal = dir.es_principal == 1 ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>';
                        html += `
                            <tr>
                                <td>${dir.nombre_direccion}</td>
                                <td>${dir.direccion}, ${dir.ciudad || ''}, ${dir.provincia || ''} ${dir.codigo_postal || ''}</td>
                                <td>${dir.telefono || 'N/A'}</td>
                                <td>${dir.observaciones || 'N/A'}</td>
                                <td>${isPrincipal}</td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-warning btn-sm me-1" onclick='openAddEditDireccionEnvioModal(${JSON.stringify(dir)})' title="Editar Dirección">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm" onclick='confirmDeleteDireccionEnvio(${dir.id_direccion_envio}, ${dir.id_cliente})' title="Eliminar Dirección">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    shippingAddressesList.innerHTML = html;
                    setupShippingAddressSearch(); // Configurar la búsqueda después de cargar
                } else {
                    shippingAddressesList.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No hay direcciones de envío registradas para este cliente.</td></tr>';
                }
            } catch (error) {
                console.error('Error loading shipping addresses:', error);
                shippingAddressesList.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error al cargar direcciones de envío.</td></tr>';
            }
        }

        function openAddEditDireccionEnvioModal(direccion = null) {
            const modal = new bootstrap.Modal(document.getElementById('addEditDireccionEnvioModal'));
            const form = document.getElementById('direccionEnvioForm');
            const modalLabel = document.getElementById('addEditDireccionEnvioModalLabel');
            const actionsCard = document.getElementById('shipping_address_actions_card');
            const actionsHr = document.getElementById('shipping_address_actions_hr');

            // Resetear el formulario
            form.reset();
            document.getElementById('id_direccion_envio').value = '';
            document.getElementById('accion_direccion_envio').value = 'agregar_direccion_envio';
            document.getElementById('id_cliente_direccion_envio').value = document.getElementById('id_cliente_form').value;

            // Resetear mapa de envío a la posición por defecto
            const defaultLatLng = { lat: 37.3828300, lng: -5.9731700 };
            if (markerEnvio) {
                markerEnvio.position = defaultLatLng;
                mapEnvio.setCenter(defaultLatLng);
                mapEnvio.setZoom(12);
            }
            document.getElementById('search_address_input_envio').value = '';
            document.getElementById('latitud_envio').value = '';
            document.getElementById('longitud_envio').value = '';
            document.getElementById('direccion_google_maps_envio').value = '';
            document.getElementById('telefono_envio').value = '';
            document.getElementById('observaciones_envio').value = '';

            currentEditingDireccionEnvio = direccion;

            if (direccion) {
                modalLabel.textContent = 'Editar Dirección de Envío';
                actionsCard.style.display = 'block';
                actionsHr.style.display = 'block';

                document.getElementById('accion_direccion_envio').value = 'editar_direccion_envio';
                document.getElementById('id_direccion_envio').value = direccion.id_direccion_envio;
                document.getElementById('nombre_direccion').value = direccion.nombre_direccion;
                document.getElementById('direccion_envio').value = direccion.direccion;
                document.getElementById('ciudad_envio').value = direccion.ciudad || '';
                document.getElementById('provincia_envio').value = direccion.provincia || '';
                document.getElementById('codigo_postal_envio').value = direccion.codigo_postal || '';
                document.getElementById('es_principal').checked = (direccion.es_principal == 1);
                document.getElementById('telefono_envio').value = direccion.telefono || '';
                document.getElementById('observaciones_envio').value = direccion.observaciones || '';

                const lat = parseFloat(direccion.latitud);
                const lng = parseFloat(direccion.longitud);

                if (!isNaN(lat) && !isNaN(lng)) {
                    document.getElementById('latitud_envio').value = lat;
                    document.getElementById('longitud_envio').value = lng;
                    document.getElementById('direccion_google_maps_envio').value = direccion.direccion_google_maps || '';

                    if (mapEnvio && markerEnvio) {
                        const newPosition = { lat: lat, lng: lng };
                        markerEnvio.position = newPosition;
                        mapEnvio.setCenter(newPosition);
                        mapEnvio.setZoom(15);
                    }
                }

                // Asignar acciones a los botones
                document.getElementById('btn_view_invoices_address').onclick = () => viewInvoicesForAddress(direccion.id_direccion_envio);
                document.getElementById('btn_new_invoice_address').onclick = () => createNewInvoiceForAddress(direccion.id_cliente, direccion.id_direccion_envio);
                document.getElementById('btn_new_order_address').onclick = () => createNewOrderForAddress(direccion.id_cliente, direccion.id_direccion_envio);

            } else {
                modalLabel.textContent = 'Añadir Nueva Dirección de Envío';
                actionsCard.style.display = 'none';
                actionsHr.style.display = 'none';
            }
            modal.show();
        }

        async function submitDireccionEnvioForm(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const currentClientId = document.getElementById('id_cliente_form').value;

            try {
                const response = await fetch('clientes.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    showCustomAlert(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('addEditDireccionEnvioModal')).hide();
                    loadShippingAddresses(currentClientId);
                } else {
                    showCustomAlert(data.message, 'danger');
                }
            } catch (error) {
                console.error('Error submitting shipping address form:', error);
                showCustomAlert('Error de red o servidor al guardar la dirección de envío.', 'danger');
            }
        }

        function confirmDeleteDireccionEnvio(idDireccion, idCliente) {
            showCustomAlert("¿Estás seguro de que quieres eliminar esta dirección de envío?", 'confirm', async (confirmed) => {
                if (confirmed) {
                    const formData = new FormData();
                    formData.append('accion', 'eliminar_direccion_envio');
                    formData.append('id_direccion_envio', idDireccion);
                    formData.append('id_cliente', idCliente);

                    try {
                        const response = await fetch('clientes.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();

                        if (data.success) {
                            showCustomAlert(data.message, 'success');
                            loadShippingAddresses(idCliente);
                        } else {
                            showCustomAlert(data.message, 'danger');
                        }
                    } catch (error) {
                        console.error('Error deleting shipping address:', error);
                        showCustomAlert('Error de red o servidor al eliminar la dirección de envío.', 'danger');
                    }
                }
            });
        }

        // Asignar el listener al formulario del modal de dirección de envío
        document.addEventListener('DOMContentLoaded', () => {
            const direccionEnvioForm = document.getElementById('direccionEnvioForm');
            if (direccionEnvioForm) {
                direccionEnvioForm.addEventListener('submit', submitDireccionEnvioForm);
            }

            // Lógica para pre-rellenar el formulario si hay un ID de cliente en la URL al cargar
            const urlParams = new URLSearchParams(window.location.search);
            const clientIdFromUrl = urlParams.get('id');

            const clientActionsCard = document.getElementById('client_actions_card');
            if (clientActionsCard) clientActionsCard.style.display = 'none';
            const clientRecentActivityCard = document.getElementById('client_recent_activity_card');
            if (clientRecentActivityCard) clientRecentActivityCard.style.display = 'none';
            const clientShippingAddressesCard = document.getElementById('client_shipping_addresses_card');
            if (clientShippingAddressesCard) clientShippingAddressesCard.style.display = 'none';

            if (clientIdFromUrl) {
                <?php if ($cliente_a_editar): ?>
                    editClient(<?php echo json_encode($cliente_a_editar); ?>);
                <?php endif; ?>
            }
        });
    </script>
    <!-- Carga asíncrona de la API de Google Maps con las librerías 'places', 'marker' y 'geocoding' -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAwQDkg4J9qi7toUQ6eSVjul8HKTYoRzz8&libraries=places,marker,geocoding&callback=initMapPicker" async defer></script>
</body>
</html>