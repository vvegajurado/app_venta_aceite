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

// Si hay un error de conexión a la base de datos, mostrar un mensaje y salir
if (isset($pdo_error) && $pdo_error) {
    $mensaje = $pdo_error;
    $tipo_mensaje = 'danger';
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos: ' . $pdo_error]);
        exit();
    }
}

// --- Lógica para cargar pedidos pendientes (para el modal "Añadir Pedidos") ---
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_orders') {
    ob_clean(); // Limpiar cualquier salida anterior
    header('Content-Type: application/json');

    $fecha_filtro = $_GET['fecha'] ?? null;
    $search_term = $_GET['search'] ?? null;

    try {
        $sql = "
            SELECT
                p.id_pedido,
                p.fecha_pedido,
                p.id_cliente,
                c.nombre_cliente,
                COALESCE(SUM(dp.cantidad_solicitada), 0) AS total_unidades,
                COALESCE(SUM(dp.subtotal_linea_total), 0) AS total_importe,
                p.id_factura_asociada,
                fv.estado_pago,
                COALESCE(SUM(dfv.cantidad), 0) AS total_cantidad_facturada,
                COALESCE(SUM(dfv.unidades_retiradas), 0) AS total_unidades_servidas
            FROM
                pedidos p
            JOIN
                clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN
                detalle_pedidos dp ON p.id_pedido = dp.id_pedido
            LEFT JOIN
                facturas_ventas fv ON p.id_factura_asociada = fv.id_factura
            LEFT JOIN
                detalle_factura_ventas dfv ON fv.id_factura = dfv.id_factura AND dp.id_producto = dfv.id_producto
            WHERE
                p.estado_pedido = 'pendiente'
                AND p.tipo_entrega = 'reparto_propio'
                AND p.id_pedido NOT IN (SELECT id_pedido FROM partes_ruta_pedidos)
        ";

        $params = [];

        if ($fecha_filtro) {
            $sql .= " AND p.fecha_pedido = ?";
            $params[] = $fecha_filtro;
        }

        if ($search_term) {
            $sql .= " AND (c.nombre_cliente LIKE ? OR p.id_pedido LIKE ?)";
            $params[] = '%' . $search_term . '%';
            $params[] = '%' . $search_term . '%';
        }

        $sql .= "
            GROUP BY
                p.id_pedido, p.fecha_pedido, p.id_cliente, c.nombre_cliente, p.id_factura_asociada, fv.estado_pago
            HAVING
                (total_unidades_servidas < total_cantidad_facturada OR p.id_factura_asociada IS NULL)
            ORDER BY
                p.fecha_pedido ASC;
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pedidos_pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'pedidos' => $pedidos_pendientes]);
    } catch (PDOException $e) {
        error_log("Error fetching pending orders: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al cargar pedidos pendientes: ' . $e->getMessage()]);
    }
    exit();
}

// --- Lógica para PROCESAR el formulario de añadir nuevo parte de ruta ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
    }

    $id_ruta = $_POST['id_ruta'] ?? null;
    $fecha_parte = $_POST['fecha_parte'] ?? null;
    $observaciones = trim($_POST['observaciones'] ?? '');
    $pedidos_seleccionados_json = $_POST['pedidos_seleccionados'] ?? '[]';
    $pedidos_seleccionados = json_decode($pedidos_seleccionados_json, true);

    error_log("Recibido POST para crear nuevo parte de ruta. Pedidos JSON String recibido: " . $pedidos_seleccionados_json);
    error_log("Recibido POST para crear nuevo parte de ruta. Pedidos (decoded): " . print_r($pedidos_seleccionados, true));

    if (empty($id_ruta) || empty($fecha_parte)) {
        $mensaje = "Error: La ruta y la fecha del parte son obligatorias.";
        $tipo_mensaje = 'danger';
        error_log("Error de validación: Ruta o fecha vacías.");
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => false, 'message' => $mensaje]);
            exit();
        }
    } else {
        try {
            $pdo->beginTransaction();

            // Insertar nuevo parte de ruta
            $stmt = $pdo->prepare("INSERT INTO partes_ruta (id_ruta, fecha_parte, observaciones, estado) VALUES (?, ?, ?, 'Pendiente')");
            $stmt->execute([$id_ruta, $fecha_parte, $observaciones]);
            $id_parte_ruta = $pdo->lastInsertId();
            error_log("Nuevo parte de ruta creado con ID: " . $id_parte_ruta);

            // Insertar nuevas asociaciones de pedidos en partes_ruta_pedidos
            if (!empty($pedidos_seleccionados)) {
                $stmt_asociacion = $pdo->prepare("INSERT INTO partes_ruta_pedidos (id_parte_ruta, id_pedido, estado_pedido_ruta) VALUES (?, ?, 'Pendiente')");
                foreach ($pedidos_seleccionados as $pedido_id) {
                    $stmt_asociacion->execute([$id_parte_ruta, $pedido_id]);
                    error_log("Pedido " . $pedido_id . " asociado al parte " . $id_parte_ruta);
                }
            } else {
                error_log("No se seleccionaron pedidos para asociar al parte " . $id_parte_ruta);
            }

            $pdo->commit();
            error_log("Transacción completada exitosamente para parte de ruta " . $id_parte_ruta);

            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => true, 'message' => 'Parte de ruta creado exitosamente.', 'id_parte_ruta' => $id_parte_ruta]);
                exit();
            } else {
                mostrarMensaje("Parte de ruta creado exitosamente.", "success");
                header("Location: partes_ruta.php?view_id=" . $id_parte_ruta);
                exit();
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = "Error al guardar el parte de ruta: " . $e->getMessage();
            $tipo_mensaje = 'danger';
            error_log("Error de base de datos al guardar parte de ruta: " . $e->getMessage());
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => false, 'message' => $mensaje]);
                exit();
            } else {
                // Si no es AJAX, se mostrará el mensaje en la página actual
            }
        }
    }
}

// --- Lógica para cargar datos de apoyo (Rutas) ---
$rutas_disponibles = [];
try {
    $stmt_rutas = $pdo->query("SELECT id_ruta, nombre_ruta FROM rutas ORDER BY nombre_ruta ASC");
    $rutas_disponibles = $stmt_rutas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    mostrarMensaje("Error al cargar rutas: " . $e->getMessage(), "danger");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Nuevo Parte de Ruta</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Estilos generales del sidebar (copia de sidebar.php) */
        :root {
            --sidebar-bg: #2c3e50; /* Azul oscuro */
            --sidebar-link: #ecf0f1; /* Gris claro */
            --sidebar-hover: #34495e; /* Azul más oscuro al pasar el ratón */
            --primary-color: #6f42c1; /* Púrpura para partes de paquetería */
            --primary-dark: #5935a1; /* Púrpura más oscuro */
            --secondary-color: #007bff; /* Azul estándar de Bootstrap */
            --secondary-dark: #0056b3; /* Azul más oscuro */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
            color: #333;
            margin: 0;
            padding: 0;
            overflow-x: hidden; /* Evitar el scroll horizontal */
        }
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-link);
            padding: 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
            position: fixed; /* Hacer el sidebar fijo */
            top: 0;
            left: 0;
            z-index: 1000; /* Asegurar que el sidebar esté encima */
            transition: all 0.3s; /* Transición suave para colapsar */
        }
        .sidebar.active { /* Para colapsar el sidebar */
            margin-left: -250px;
        }
        #content {
            width: 100%;
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s;
            margin-left: 250px; /* Ajustar esto para que coincida con el ancho del sidebar */
        }
        .sidebar.active + #content { /* Cuando el sidebar está colapsado */
            margin-left: 0;
        }
        .navbar {
            background-color: #fff;
            border-bottom: 1px solid #ddd;
            padding: 10px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .form-container, .table-container, .card { /* Unified styling for containers and cards */
            background-color: #fff;
            padding: 30px;
            border-radius: 15px; /* More rounded corners */
            box-shadow: 0 4px 8px rgba(0,0,0,0.05); /* Stronger shadow */
            margin-bottom: 30px;
        }
        .card-header { /* Specific for card headers */
            background-color: var(--primary-color); /* Purple for headers */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            font-weight: bold;
            padding: 15px 30px; /* Match padding of card-body */
            margin: -30px -30px 20px -30px; /* Adjust to cover padding of parent */
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px; /* Consistent rounded buttons */
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
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
        .btn-secondary { /* Ensure secondary button is styled */
            border-radius: 8px;
        }
        .btn-warning { /* Ensure warning button is styled */
            border-radius: 8px;
        }
        .table thead th {
            background-color: #e9ecef;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        .table tbody tr:hover {
            background-color: #f2f2f2;
        }
        .table-responsive {
            margin-top: 20px;
        }
        .modal-content { /* Consistent modal styling */
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .modal-header {
            background-color: var(--primary-color); /* Purple for modal headers */
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .modal-header .btn-close {
            filter: invert(1) brightness(2); /* White close button on dark background */
        }
        .form-control, .form-select {
            border-radius: 8px; /* Rounded form elements */
        }

        /* Insignias personalizadas para el estado del pedido en ruta */
        .badge.bg-pending-route {
            background-color: #ffc107 !important; /* Amarillo de advertencia */
            color: #212529 !important; /* Texto oscuro */
        }
        .badge.bg-en-ruta {
            background-color: #17a2b8 !important; /* Azul de información */
        }
        .badge.bg-completado-entregado {
            background-color: #28a745 !important; /* Verde de éxito */
        }
        .badge.bg-cancelado-route {
            background-color: #dc3545 !important; /* Rojo de peligro */
        }
        /* Insignias personalizadas para el estado del servicio (de la lógica de pedidos.php) */
        .badge.bg-served-complete {
            background-color: #28a745 !important; /* Verde */
        }
        .badge.bg-served-partial {
            background-color: #fd7e14 !important; /* Naranja */
        }
        .badge.bg-pending-invoice {
            background-color: #6c757d !important; /* Gris */
        }
        .badge.bg-pending-no-invoice {
            background-color: #ffc107 !important; /* Amarillo */
            color: #212529 !important; /* Texto oscuro para contraste */
        }

        /* Insignias personalizadas para el estado general del parte de ruta */
        .badge.bg-parte-pendiente {
            background-color: #ffc107 !important; /* Amarillo de advertencia, similar al pedido pendiente */
            color: #212529 !important;
        }
        .badge.bg-parte-en-curso {
            background-color: #17a2b8 !important; /* Azul de información, similar al pedido en ruta */
        }
        .badge.bg-parte-completado {
            background-color: #28a745 !important; /* Verde de éxito, similar al pedido completado */
        }
        .badge.bg-parte-cancelado {
            background-color: #dc3545 !important; /* Rojo de peligro, similar al pedido cancelado */
        }
        /* Insignias personalizadas para el estado de pago */
        .badge.bg-pago-pendiente {
            background-color: #ffc107 !important; /* Amarillo para pago pendiente */
            color: #212529 !important;
        }
        .badge.bg-pago-parcial {
            background-color: #fd7e14 !important; /* Naranja para pago parcial */
        }
        .badge.bg-pago-pagado {
            background-color: #28a745 !important; /* Verde para pagado */
        }
        .badge.bg-pago-anulado {
            background-color: #dc3545 !important; /* Rojo para anulado */
        }


        .message-container {
            margin-top: 20px;
        }
        .list-group-item-action:hover {
            background-color: #e9ecef;
        }
        /* Style for order selection in modal - copied from partes_paqueteria.php */
        #pendingOrdersList {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px;
        }
        .order-item {
            padding: 8px 5px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        .order-item:hover {
            background-color: #f8f9fa;
        }
        .order-item.selected {
            background-color: #e2f0ff; /* Light blue for selected items */
            font-weight: bold;
        }
        .order-item:last-child {
            border-bottom: none;
        }
        /* Adjusted selected-order-item for table display in main form */
        .selected-order-item {
            background-color: #e2f0ff; /* Azul claro para elementos seleccionados */
            border-left: 5px solid var(--primary-color); /* Use primary color */
            padding: 8px 15px;
            margin-bottom: 5px;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .selected-order-item .remove-btn {
            background: none;
            border: none;
            color: #dc3545;
            font-size: 1.2rem;
            cursor: pointer;
        }
        .selected-order-item .remove-btn:hover {
            color: #c82333;
        }

        /* Estilos del Sidebar (desde sidebar.php) */
        .sidebar-header {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header .app-logo {
            max-width: 80px !important; /* Reducido para que sea más pequeño y con prioridad */
            width: 100% !important; /* Asegura que se adapte al contenedor y con prioridad */
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
            margin: 0;
            flex-grow: 1; /* Permite que el menú ocupe el espacio disponible */
            display: flex;
            flex-direction: column;
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
            transition: background-color 0.3s ease, color 0.3s ease, border-left-color 0.3s ease;
            border-left: 5px solid transparent; /* Para el indicador activo */
        }

        .sidebar-menu-link i {
            font-size: 1.2rem;
            margin-right: 10px;
        }

        .sidebar-menu-link:hover, .sidebar-menu-link.active {
            background-color: var(--sidebar-hover);
            color: white;
            border-left-color: var(--secondary-color); /* Use secondary color for sidebar active */
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

        /* Submenú */
        .sidebar-submenu-item {
            list-style: none;
        }

        .sidebar-submenu-link {
            display: block;
            padding: 8px 15px 8px 45px; /* Indentación para submenú */
            color: var(--sidebar-link);
            text-decoration: none;
            border-radius: 8px;
            transition: background-color 0.3s ease, color 0.3s ease, border-left-color 0.3s ease;
            border-left: 5px solid transparent;
            font-size: 0.95rem;
        }

        .sidebar-submenu-link:hover, .sidebar-submenu-link.active {
            background-color: var(--sidebar-hover);
            color: white;
            border-left-color: var(--secondary-color); /* Use secondary color for sidebar submenu active */
        }

        /* Ajustes responsivos */
        @media (max-width: 768px) {
            .sidebar {
                margin-left: -250px;
            }
            .sidebar.active {
                margin-left: 0;
            }
            #content {
                margin-left: 0;
            }
            .sidebar.active + #content {
                margin-left: 250px;
            }
        }

        /* Estilos para el mapa (aunque no se usa directamente en esta página, se mantiene para consistencia) */
        #map {
            height: 400px;
            width: 100%;
            border-radius: 8px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .info-window-content {
            font-family: 'Inter', sans-serif;
            padding: 5px;
        }
        .info-window-content h6 {
            margin-bottom: 5px;
            color: #2c3e50;
        }
        .info-window-content p {
            margin-bottom: 3px;
            font-size: 0.9em;
        }
        .info-window-content a {
            color: #007bff;
            text-decoration: none;
        }
        .info-window-content a:hover {
            text-decoration: underline;
        }
        .form-check-label {
            cursor: pointer;
        }
        .form-check-input {
            margin-right: 5px;
        }
        .route-details-section {
            margin-top: 20px;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        .route-details-section h6 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        .route-details-section ol {
            padding-left: 20px;
        }
        .route-details-section li {
            margin-bottom: 5px;
            font-size: 0.95em;
        }
        .route-details-section li strong {
            color: #2c3e50;
        }
        /* Estilos para el input de URL */
        #googleMapsUrlInput {
            width: 100%;
            padding: 8px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 0.9em;
            background-color: #e9ecef;
            cursor: pointer;
        }
        .share-buttons-container {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Estilos para las nuevas secciones de detalles de pedidos */
        .order-details-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        .order-item-card {
            border: 1px solid #dcdcdc;
            border-radius: 10px;
            margin-bottom: 15px;
            padding: 15px;
            background-color: #fdfdfd;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }
        .order-item-card h5 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        .order-item-card .table {
            margin-top: 10px;
            font-size: 0.9em;
        }
        .order-item-card .table th, .order-item-card .table td {
            padding: 8px;
        }
        .order-item-card .table thead th {
            background-color: #f2f2f2;
        }
        .load-summary-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        .load-summary-table th, .load-summary-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .load-summary-table th {
            background-color: #f2f2f2;
        }

        /* Estilos de arrastrar y soltar */
        #delivery_order_list li {
            padding: 8px 12px;
            margin-bottom: 5px;
            background-color: #e9f5ff;
            border: 1px solid #cce5ff;
            border-radius: 5px;
            cursor: grab;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #delivery_order_list li.dragging {
            opacity: 0.5;
            border: 2px dashed var(--primary-color);
        }
        #delivery_order_list li.drag-over {
            background-color: #d0e9ff;
            border: 1px dashed var(--primary-color);
        }
        .recalculate-btn-container {
            text-align: center;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar (Unificado) -->
        <?php include 'sidebar.php'; ?>
        <!-- Contenido de la Página -->
        <div id="content">
            <nav class="navbar navbar-expand-lg navbar-light bg-light">
                <div class="container-fluid">
                    <!-- Contenido de la barra de navegación ajustado para ser más simple o vacío si no se necesita nada más aquí -->
                </div>
            </nav>

            <h2 class="mb-4">Crear Nuevo Parte de Ruta</h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Formulario para Crear Nuevo Parte de Ruta -->
            <div class="form-container">
                <div class="card-header">
                    <h3 class="mb-0">Formulario de Creación de Parte de Ruta</h3>
                </div>
                <div class="card-body">
                    <form id="parteRutaCreateForm">
                        <div class="mb-3">
                            <label for="create_id_ruta" class="form-label">Ruta <span class="text-danger">*</span></label>
                            <select class="form-select rounded-pill" id="create_id_ruta" name="id_ruta" required>
                                <option value="">Seleccione una ruta</option>
                                <?php foreach ($rutas_disponibles as $ruta): ?>
                                    <option value="<?php echo htmlspecialchars($ruta['id_ruta']); ?>">
                                        <?php echo htmlspecialchars($ruta['nombre_ruta']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="create_fecha_parte" class="form-label">Fecha del Parte (Fecha programada para el reparto) <span class="text-danger">*</span></label>
                            <input type="date" class="form-control rounded-pill" id="create_fecha_parte" name="fecha_parte" required value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="create_observaciones" class="form-label">Observaciones</label>
                            <textarea class="form-control rounded-pill" id="create_observaciones" name="observaciones" rows="3"></textarea>
                        </div>

                        <h4 class="mt-4">Pedidos Asignados</h4>
                        <div class="mb-3">
                            <button type="button" class="btn btn-success rounded-pill" data-bs-toggle="modal" data-bs-target="#addOrdersModal" id="addOrdersToCreateFormBtn">
                                <i class="bi bi-plus-circle"></i> Añadir Pedidos
                            </button>
                        </div>

                        <div id="createSelectedOrdersContainer" class="mb-4 border p-3 rounded">
                            <p id="createNoOrdersMessage" class="text-muted text-center">No hay pedidos seleccionados para este parte de ruta.</p>
                        </div>
                        <input type="hidden" id="create_pedidos_seleccionados" name="pedidos_seleccionados" value="[]">

                        <button type="submit" class="btn btn-primary rounded-pill">Guardar Parte de Ruta</button>
                        <a href="partes_ruta.php" class="btn btn-secondary rounded-pill">Cancelar</a>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal para Añadir Pedidos (existente) - Se mantiene aquí ya que es usado por el formulario de creación -->
    <div class="modal fade" id="addOrdersModal" tabindex="-1" aria-labelledby="addOrdersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content rounded-lg shadow-lg">
                <div class="modal-header">
                    <h5 class="modal-title" id="addOrdersModalLabel">Añadir Pedidos Pendientes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="filterFechaEntrega" class="form-label">Filtrar por Fecha de Pedido:</label>
                        <input type="date" class="form-control rounded-pill" id="filterFechaEntrega">
                    </div>
                    <div class="mb-3">
                        <label for="searchOrder" class="form-label">Buscar por Cliente o ID de Pedido:</label>
                        <input type="text" class="form-control rounded-pill" id="searchOrder" placeholder="Escriba para buscar pedidos...">
                    </div>
                    <div id="pendingOrdersList" class="list-group">
                        <!-- Pedidos pendientes se cargarán aquí -->
                        <p class="text-center text-muted mt-3">Cargando pedidos...</p>
                    </div>
                    <div id="loadingOrders" class="text-center mt-3 d-none">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary rounded-pill" id="addSelectedOrdersBtn">Añadir Seleccionados</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Confirmaciones / Alertas -->
    <div class="modal fade" id="customMessageModal" tabindex="-1" aria-labelledby="customMessageModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content rounded-lg shadow-lg">
                <div class="modal-header">
                    <h5 class="modal-title" id="customMessageModalLabel"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="customMessageModalBody"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>


    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        // --- Funciones Globales (accesibles desde atributos onclick de HTML) ---

        // Función para mostrar mensajes modales personalizados
        function showCustomModal(title, message, type = 'info', callback = null) {
            const customMessageModal = new bootstrap.Modal(document.getElementById('customMessageModal'));
            document.getElementById('customMessageModalLabel').innerText = title;
            document.getElementById('customMessageModalBody').innerText = message;
            const modalHeader = document.querySelector('#customMessageModal .modal-header');
            modalHeader.classList.remove('bg-danger', 'bg-success', 'bg-info', 'bg-warning', 'bg-primary'); // Remove all possible previous classes
            // Add specific class based on type
            switch (type) {
                case 'danger': modalHeader.classList.add('bg-danger'); break;
                case 'success': modalHeader.classList.add('bg-success'); break;
                case 'warning': modalHeader.classList.add('bg-warning'); break;
                case 'info': modalHeader.classList.add('bg-info'); break;
                default: modalHeader.classList.add('bg-primary'); break; // Default to primary color
            }
            // Ensure close button is white on dark backgrounds
            const closeButton = modalHeader.querySelector('.btn-close');
            if (type === 'danger' || type === 'success' || type === 'info' || type === 'primary') {
                closeButton.classList.add('btn-close-white');
            } else {
                closeButton.classList.remove('btn-close-white');
            }


            const modalFooter = document.querySelector('#customMessageModal .modal-footer');
            modalFooter.innerHTML = ''; // Limpiar botones anteriores

            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.classList.add('btn', 'btn-secondary', 'rounded-pill');
            closeBtn.setAttribute('data-bs-dismiss', 'modal');
            closeBtn.innerText = 'Cerrar';
            modalFooter.appendChild(closeBtn);

            if (callback) {
                // If it's a confirmation, add an "Accept" button
                if (type === 'confirm') {
                    const confirmBtn = document.createElement('button');
                    confirmBtn.type = 'button';
                    confirmBtn.classList.add('btn', 'btn-danger', 'rounded-pill'); // Danger for delete confirmation
                    confirmBtn.setAttribute('data-bs-dismiss', 'modal');
                    confirmBtn.innerText = 'Eliminar';
                    confirmBtn.addEventListener('click', () => callback(true), { once: true });
                    modalFooter.appendChild(confirmBtn);
                    closeBtn.innerText = 'Cancelar'; // Change text for cancel button
                } else {
                    // For simple alerts, the callback is triggered on close
                    closeBtn.addEventListener('click', callback, { once: true });
                }
            }
            customMessageModal.show();
        }

        // Función para manejar la redirección a facturas_ventas.php (solo si se usa en esta página)
        window.createInvoiceFromOrder = function(clientId, clientName, orderId = null, idParteRuta = null) {
            let url = `facturas_ventas.php?new_invoice_client_id=${clientId}&new_invoice_client_name=${encodeURIComponent(clientName)}`;
            if (orderId) {
                url += `&new_invoice_order_id=${orderId}`;
            }
            const parteRutaIdToSend = (idParteRuta !== null && idParteRuta !== undefined && idParteRuta !== 'null') ? parseInt(idParteRuta) : null;
            if (parteRutaIdToSend) {
                url += `&new_invoice_parte_ruta_id=${parteRutaIdToSend}`;
            }
            window.open(url, '_blank');
        };

        let selectedOrders = new Map(); // Global map for selected orders in this page (create context)
        let currentPendingOrders = [];

        // Función para eliminar un pedido seleccionado de la lista
        window.removeSelectedOrder = function(orderId) {
            selectedOrders.delete(orderId);
            // Always refer to the elements specific to this page's form
            const createSelectedOrdersContainer = document.getElementById('createSelectedOrdersContainer');
            const createNoOrdersMessage = document.getElementById('createNoOrdersMessage');
            const createPedidosSeleccionadosInput = document.getElementById('create_pedidos_seleccionados');
            updateSelectedOrdersDisplay(createSelectedOrdersContainer, createNoOrdersMessage, createPedidosSeleccionadosInput);
        };

        // Modified updateSelectedOrdersDisplay to accept container elements as arguments
        function updateSelectedOrdersDisplay(container, noOrdersMessage, pedidosInput) {
            if (!container || !noOrdersMessage || !pedidosInput) {
                console.error("Error: Elementos de contenedor de pedidos no encontrados. Asegúrate de que los IDs son correctos y los elementos existen en el DOM.");
                return;
            }

            container.innerHTML = ''; // Clear existing content

            if (selectedOrders.size === 0) {
                noOrdersMessage.classList.remove('d-none');
                container.appendChild(noOrdersMessage);
            } else {
                noOrdersMessage.classList.add('d-none');
                let tableHtml = `
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>ID Pedido</th>
                                    <th>Fecha</th>
                                    <th>Cliente</th>
                                    <th class="text-end">Unidades Totales</th>
                                    <th class="text-end">Importe Total</th>
                                    <th class="text-center">Estado Factura</th>
                                    <th class="text-center">Estado Cobro</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                selectedOrders.forEach((order, id) => {
                    let estadoServicioTexto = '';
                    let badgeClassServicio = '';

                    const totalUnidadesServidas = order.total_unidades_servidas ?? 0;
                    const totalCantidadFacturada = order.total_cantidad_facturada ?? 0;
                    const totalUnidades = order.total_unidades ?? 0;
                    const totalImporte = order.total_importe ?? 0;


                    if (order.id_factura_asociada === null) {
                        estadoServicioTexto = 'Pendiente (sin factura)';
                        badgeClassServicio = 'bg-pending-no-invoice';
                    } else if (totalUnidadesServidas == 0 && totalCantidadFacturada > 0) {
                        estadoServicioTexto = 'Pendiente (vía factura)';
                        badgeClassServicio = 'bg-pending-invoice';
                    } else if (totalUnidadesServidas > 0 && totalUnidadesServidas < totalCantidadFacturada) {
                        estadoServicioTexto = 'Parcialmente Servido';
                        badgeClassServicio = 'bg-served-partial';
                    } else if (totalUnidadesServidas > 0 && totalUnidadesServidas == totalCantidadFacturada && totalCantidadFacturada > 0) {
                        estadoServicioTexto = 'Servido Completo';
                        badgeClassServicio = 'bg-served-complete';
                    } else {
                        estadoServicioTexto = 'Estado Desconocido';
                        badgeClassServicio = 'bg-dark';
                    }

                    const isFacturado = order.id_factura_asociada !== null;

                    let estadoCobroTexto = 'N/A';
                    let badgeClassCobro = 'bg-secondary';
                    if (isFacturado) {
                        estadoCobroTexto = order.estado_pago || 'Pendiente';
                        switch (estadoCobroTexto) {
                            case 'pagada': badgeClassCobro = 'bg-pago-pagado'; break;
                            case 'parcialmente_pagada': badgeClassCobro = 'bg-pago-parcial'; break;
                            case 'anulado': badgeClassCobro = 'bg-pago-anulado'; break;
                            case 'pendiente':
                            default: badgeClassCobro = 'bg-pago-pendiente'; break;
                        }
                    }

                    tableHtml += `
                        <tr>
                            <td>${order.id_pedido}</td>
                            <td>${new Date(order.fecha_pedido).toLocaleDateString('es-ES')}</td>
                            <td>${order.nombre_cliente}</td>
                            <td class="text-end">${parseFloat(totalUnidades).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                            <td class="text-end">${parseFloat(totalImporte).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}€</td>
                            <td class="text-center">
                                <span class="badge ${badgeClassServicio}">${estadoServicioTexto}</span>
                                ${isFacturado ? `<a href="facturas_ventas.php?view=details&id=${order.id_factura_asociada}" class="btn btn-sm btn-outline-primary ms-2" title="Ver Factura" target="_blank"><i class="bi bi-receipt-cutoff"></i></a>` : ''}
                            </td>
                            <td><span class="badge ${badgeClassCobro}">${estadoCobroTexto}</span></td>
                            <td class="text-center">
                                <button type="button" class="btn btn-success btn-sm me-1" title="Convertir a Factura"
                                    ${isFacturado ? 'disabled' : ''}
                                    onclick="window.createInvoiceFromOrder(${order.id_cliente}, '${order.nombre_cliente.replace(/'/g, "\\'")}', ${order.id_pedido}, null)">
                                    <i class="bi bi-receipt"></i> Facturar
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" title="Quitar de la lista" onclick="window.removeSelectedOrder(${order.id_pedido})">
                                    <i class="bi bi-x-circle"></i> Eliminar
                                </button>
                            </td>
                        </tr>
                    `;
                });
                tableHtml += `
                            </tbody>
                        </table>
                    </div>
                `;
                container.innerHTML = tableHtml;
            }
            pedidosInput.value = JSON.stringify(Array.from(selectedOrders.keys()));
            console.log('Selected Orders after updateDisplay:', Array.from(selectedOrders.keys()));
        }

        document.addEventListener('DOMContentLoaded', () => {
            const addOrdersModal = new bootstrap.Modal(document.getElementById('addOrdersModal'));
            const pendingOrdersList = document.getElementById('pendingOrdersList');
            const loadingOrders = document.getElementById('loadingOrders');
            const filterFechaEntrega = document.getElementById('filterFechaEntrega');
            const searchOrderInput = document.getElementById('searchOrder');
            const addSelectedOrdersBtn = document.getElementById('addSelectedOrdersBtn');
            const parteRutaCreateForm = document.getElementById('parteRutaCreateForm');
            const addOrdersToCreateFormBtn = document.getElementById('addOrdersToCreateFormBtn'); // Button to open addOrdersModal

            // Initial display update for the create form
            const createSelectedOrdersContainer = document.getElementById('createSelectedOrdersContainer');
            const createNoOrdersMessage = document.getElementById('createNoOrdersMessage');
            const createPedidosSeleccionadosInput = document.getElementById('create_pedidos_seleccionados');
            updateSelectedOrdersDisplay(createSelectedOrdersContainer, createNoOrdersMessage, createPedidosSeleccionadosInput);


            async function searchPendingOrders() {
                pendingOrdersList.innerHTML = '';
                loadingOrders.classList.remove('d-none');

                const fecha = filterFechaEntrega.value;
                const searchTerm = searchOrderInput.value;
                let queryString = '?action=get_pending_orders';
                if (fecha) {
                    queryString += `&fecha=${fecha}`;
                }
                if (searchTerm) {
                    queryString += `&search=${encodeURIComponent(searchTerm)}`;
                }

                try {
                    const response = await fetch(`crear_parte_ruta.php${queryString}`); // Fetch from this page
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const result = await response.json();

                    if (result.success) {
                        currentPendingOrders = result.pedidos;
                        displayFilteredOrders();
                    } else {
                        pendingOrdersList.innerHTML = `<p class="text-danger text-center">Error: ${result.message}</p>`;
                    }
                } catch (error) {
                    console.error('Error al buscar pedidos pendientes:', error);
                    pendingOrdersList.innerHTML = `<p class="text-danger text-center">Error al buscar pedidos pendientes: ${error.message}</p>`;
                } finally {
                    loadingOrders.classList.add('d-none');
                }
            }

            function displayFilteredOrders() {
                pendingOrdersList.innerHTML = '';
                const searchQuery = searchOrderInput.value.toLowerCase();
                const filteredOrders = currentPendingOrders.filter(order => {
                    const matchesSearch = order.nombre_cliente.toLowerCase().includes(searchQuery) ||
                                          order.id_pedido.toString().includes(searchQuery);
                    return matchesSearch;
                });


                if (filteredOrders.length === 0) {
                    pendingOrdersList.innerHTML = `<p class="text-center text-muted">No se encontraron pedidos pendientes con los criterios de búsqueda.</p>`;
                } else {
                    filteredOrders.forEach(order => {
                        const isSelected = selectedOrders.has(order.id_pedido);
                        let estadoServicioTexto = '';
                        let badgeClassServicio = '';

                        const totalUnidadesServidas = order.total_unidades_servidas ?? 0;
                        const totalCantidadFacturada = order.total_cantidad_facturada ?? 0;
                        const totalUnidades = order.total_unidades ?? 0;
                        const totalImporte = order.total_importe ?? 0;

                        if (order.id_factura_asociada === null) {
                            estadoServicioTexto = 'Pendiente (sin factura)';
                            badgeClassServicio = 'bg-pending-no-invoice';
                        } else if (totalUnidadesServidas == 0 && totalCantidadFacturada > 0) {
                            estadoServicioTexto = 'Pendiente (vía factura)';
                            badgeClassServicio = 'bg-pending-invoice';
                        } else if (totalUnidadesServidas > 0 && totalUnidadesServidas < totalCantidadFacturada) {
                            estadoServicioTexto = 'Parcialmente Servido';
                            badgeClassServicio = 'bg-served-partial';
                        } else if (totalUnidadesServidas > 0 && totalUnidadesServidas == totalCantidadFacturada && totalCantidadFacturada > 0) {
                            estadoServicioTexto = 'Servido Completo';
                            badgeClassServicio = 'bg-served-complete';
                        } else {
                            estadoServicioTexto = 'Estado Desconocido';
                            badgeClassServicio = 'bg-dark';
                        }

                        let estadoCobroTexto = 'N/A';
                        let badgeClassCobro = 'bg-secondary';
                        if (order.id_factura_asociada !== null) {
                            estadoCobroTexto = order.estado_pago || 'Pendiente';
                            switch (estadoCobroTexto) {
                                case 'pagada': badgeClassCobro = 'bg-pago-pagado'; break;
                                case 'parcialmente_pagada': badgeClassCobro = 'bg-pago-parcial'; break;
                                case 'anulado': badgeClassCobro = 'bg-pago-anulado'; break;
                                case 'pendiente':
                                default: badgeClassCobro = 'bg-pago-pendiente'; break;
                            }
                        }


                        const orderItem = document.createElement('label');
                        orderItem.classList.add('list-group-item', 'list-group-item-action', 'd-flex', 'justify-content-between', 'align-items-center', 'rounded', 'mb-1');
                        orderItem.style.cursor = 'pointer';
                        orderItem.innerHTML = `
                            <div class="form-check d-flex align-items-center w-100">
                                <input class="form-check-input me-3" type="checkbox" value="${order.id_pedido}" ${isSelected ? 'checked' : ''} data-order-details='${JSON.stringify(order)}'>
                                <div>
                                    <h6 class="mb-0">Pedido #${order.id_pedido} - ${order.nombre_cliente}</h6>
                                    <small class="text-muted">Fecha: ${new Date(order.fecha_pedido).toLocaleDateString('es-ES')}</small>
                                    <div class="d-flex mt-1">
                                        <span class="badge bg-info me-2"><i class="bi bi-box"></i> Unidades: ${parseFloat(totalUnidades).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-currency-euro"></i> Importe: ${parseFloat(totalImporte).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}€</span>
                                        <span class="badge ${badgeClassServicio} ms-2">${estadoServicioTexto}</span>
                                        <span class="badge ${badgeClassCobro} ms-2">Cobro: ${estadoCobroTexto}</span>
                                    </div>
                                </div>
                            </div>
                        `;
                        pendingOrdersList.appendChild(orderItem);
                    });
                }
            }

            // Manejo del formulario de creación
            if (parteRutaCreateForm) {
                parteRutaCreateForm.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    const formData = new FormData(parteRutaCreateForm);
                    formData.set('pedidos_seleccionados', JSON.stringify(Array.from(selectedOrders.keys())));

                    console.log('Submitting create form. Selected Orders:', Array.from(selectedOrders.keys()));

                    try {
                        const response = await fetch('crear_parte_ruta.php', { // Submit to this same page
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest' // Indicate AJAX request
                            }
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        const result = await response.json();

                        if (result.success) {
                            console.log("Form submission successful. ID Parte Ruta:", result.id_parte_ruta);
                            showCustomModal("Éxito", result.message, "success", () => {
                                window.location.href = `partes_ruta.php?view_id=${result.id_parte_ruta}`;
                            });
                        } else {
                            console.error("Form submission failed:", result.message);
                            showCustomModal("Error", result.message, "danger");
                        }
                    }
                    catch (error) {
                        console.error('Error al guardar el parte de ruta:', error);
                        showCustomModal("Error", "Error al guardar el parte de ruta: " + error.message, "danger");
                    }
                });
            }

            filterFechaEntrega.addEventListener('change', searchPendingOrders);
            searchOrderInput.addEventListener('input', searchPendingOrders);

            addOrdersModal._element.addEventListener('shown.bs.modal', () => {
                // Blur the element that triggered this modal to prevent aria-hidden issues
                if (document.activeElement === addOrdersToCreateFormBtn) {
                     document.activeElement.blur();
                }
                searchPendingOrders();
            });

            addSelectedOrdersBtn.addEventListener('click', () => {
                pendingOrdersList.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
                    const orderId = parseInt(checkbox.value);
                    const orderDetails = JSON.parse(checkbox.dataset.orderDetails);
                    if (!selectedOrders.has(orderId)) {
                        selectedOrders.set(orderId, orderDetails);
                    }
                });
                // Always refer to the elements specific to this page's form
                const createSelectedOrdersContainer = document.getElementById('createSelectedOrdersContainer');
                const createNoOrdersMessage = document.getElementById('createNoOrdersMessage');
                const createPedidosSeleccionadosInput = document.getElementById('create_pedidos_seleccionados');

                updateSelectedOrdersDisplay(createSelectedOrdersContainer, createNoOrdersMessage, createPedidosSeleccionadosInput);

                addSelectedOrdersBtn.blur(); // Blur the button to remove focus before hiding the modal
                addOrdersModal.hide();
            });
        });
    </script>
</body>
</html>
