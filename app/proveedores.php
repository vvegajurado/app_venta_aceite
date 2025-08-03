<?php
// Incluir el archivo de verificación de autenticación al principio de cada página protegida
include 'auth_check.php';

// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Iniciar sesión para gestionar mensajes (aunque auth_check.php ya la inicia, es buena práctica)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Inicializar variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// --- Lógica para procesar el formulario de añadir/editar proveedor ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'upsert_proveedor') {
    // Recogemos los datos del formulario.
    $id_proveedor_form = $_POST['id_proveedor'] ?? null;
    $nombre_proveedor = trim($_POST['nombre_proveedor'] ?? '');
    $contacto_persona = trim($_POST['contacto_persona'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $cif_nif = trim($_POST['cif_nif'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    // Nuevos campos para latitud y longitud
    $latitud = filter_var($_POST['latitud'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['latitud'] : null;
    $longitud = filter_var($_POST['longitud'] ?? '', FILTER_VALIDATE_FLOAT) !== false ? (float)$_POST['longitud'] : null;
    $direccion_google_maps = trim($_POST['direccion_google_maps'] ?? '');


    // Validación básica de los datos
    if (empty($nombre_proveedor)) {
        $mensaje = "Error: El nombre del proveedor es obligatorio.";
        $tipo_mensaje = 'danger';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mensaje = "Error: El formato del email no es válido.";
        $tipo_mensaje = 'danger';
    } else {
        try {
            if ($id_proveedor_form) {
                // Modo EDICIÓN
                $stmt = $pdo->prepare("UPDATE proveedores SET nombre_proveedor = ?, contacto_persona = ?, telefono = ?, email = ?, direccion = ?, cif_nif = ?, observaciones = ?, latitud = ?, longitud = ?, direccion_google_maps = ? WHERE id_proveedor = ?");
                $stmt->execute([$nombre_proveedor, $contacto_persona, $telefono, $email, $direccion, $cif_nif, $observaciones, $latitud, $longitud, $direccion_google_maps, $id_proveedor_form]);
                $mensaje = "Proveedor actualizado correctamente.";
                $tipo_mensaje = 'success';
            } else {
                // Modo AGREGAR
                $stmt = $pdo->prepare("INSERT INTO proveedores (nombre_proveedor, contacto_persona, telefono, email, direccion, cif_nif, observaciones, latitud, longitud, direccion_google_maps) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nombre_proveedor, $contacto_persona, $telefono, $email, $direccion, $cif_nif, $observaciones, $latitud, $longitud, $direccion_google_maps]);
                $mensaje = "Proveedor añadido correctamente.";
                $tipo_mensaje = 'success';
            }
            // Redirigir para limpiar el formulario y mostrar el mensaje
            header("Location: proveedores.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
            exit();

        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                if (strpos($e->getMessage(), 'nombre_proveedor') !== false) {
                    $mensaje = "Error: El nombre de proveedor '{$nombre_proveedor}' ya existe. Por favor, elija un nombre único.";
                } elseif (strpos($e->getMessage(), 'cif_nif') !== false) {
                    $mensaje = "Error: El CIF/NIF '{$cif_nif}' ya existe. Por favor, asegúrese de que sea único.";
                } else {
                    $mensaje = "Error de base de datos: " . $e->getMessage();
                }
            } else {
                $mensaje = "Error al guardar el proveedor: " . $e->getMessage();
            }
            $tipo_mensaje = 'danger';
        }
    }
}

// --- Lógica para ELIMINAR un proveedor (botón eliminado del HTML) ---
if (isset($_GET['action']) && $_GET['action'] == 'eliminar' && isset($_GET['id'])) {
    $id_proveedor_eliminar = $_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM proveedores WHERE id_proveedor = ?");
        $stmt->execute([$id_proveedor_eliminar]);
        $mensaje = "Proveedor eliminado correctamente.";
        $tipo_mensaje = 'success';

        header("Location: proveedores.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
        exit();

    } catch (PDOException $e) {
        $mensaje = "Error al eliminar el proveedor: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// Cargar mensajes de la redirección
if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $tipo_mensaje = $_GET['tipo_mensaje'];
}

// --- Lógica para Cargar la lista de Proveedores ---
$proveedores = [];
$nombres_proveedor_para_filtro = [];
try {
    // Modificar la consulta para incluir los nuevos campos de geolocalización
    $stmt_proveedores = $pdo->query("SELECT id_proveedor, nombre_proveedor, contacto_persona, telefono, email, direccion, cif_nif, observaciones, fecha_alta, latitud, longitud, direccion_google_maps FROM proveedores ORDER BY nombre_proveedor ASC");
    $proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC);

    // Obtener nombres de proveedores únicos para el filtro
    $stmt_nombres_filtro = $pdo->query("SELECT DISTINCT id_proveedor, nombre_proveedor FROM proveedores ORDER BY nombre_proveedor ASC");
    $nombres_proveedor_para_filtro = $stmt_nombres_filtro->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensaje = "Error al cargar el listado de proveedores: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// Pre-llenar el formulario para edición si se pasó un ID por GET
$proveedor_a_editar = [
    'id_proveedor' => '',
    'nombre_proveedor' => '',
    'contacto_persona' => '',
    'telefono' => '',
    'email' => '',
    'direccion' => '',
    'cif_nif' => '',
    'observaciones' => '',
    'latitud' => '', // Nuevo campo
    'longitud' => '', // Nuevo campo
    'direccion_google_maps' => '' // Nuevo campo
];

if (isset($_GET['action']) && $_GET['action'] === 'editar_form' && isset($_GET['id'])) {
    $id_editar = $_GET['id'];
    // Modificar la consulta para incluir los nuevos campos
    $stmt_edit = $pdo->prepare("SELECT id_proveedor, nombre_proveedor, contacto_persona, telefono, email, direccion, cif_nif, observaciones, latitud, longitud, direccion_google_maps FROM proveedores WHERE id_proveedor = ?");
    $stmt_edit->execute([$id_editar]);
    $proveedor_a_editar = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if (!$proveedor_a_editar) {
        $proveedor_a_editar = [
            'id_proveedor' => '', 'nombre_proveedor' => '', 'contacto_persona' => '',
            'telefono' => '', 'email' => '', 'direccion' => '', 'cif_nif' => '', 'observaciones' => '',
            'latitud' => '', 'longitud' => '', 'direccion_google_maps' => ''
        ];
        $mensaje = "Error: Proveedor no encontrado para edición.";
        $tipo_mensaje = 'danger';
    }
}


// --- Cargar todas las entradas a granel y sus detalles para el filtrado JS ---
$entradas_granel_data = [];
try {
    $sql_entradas = "SELECT
                        eg.id_entrada_granel,
                        eg.fecha_entrada,
                        eg.id_proveedor,
                        p.nombre_proveedor,
                        eg.numero_lote_proveedor,
                        mp.nombre_materia_prima,
                        eg.kg_recibidos,
                        eg.litros_equivalentes,
                        eg.observaciones,
                        eg.ruta_albaran_pdf
                    FROM
                        entradas_granel eg
                    LEFT JOIN
                        proveedores p ON eg.id_proveedor = p.id_proveedor
                    LEFT JOIN
                        materias_primas mp ON eg.id_materia_prima = mp.id_materia_prima
                    ORDER BY
                        eg.fecha_entrada DESC, eg.id_entrada_granel DESC";

    $stmt_entradas = $pdo->query($sql_entradas);
    $all_entradas = $stmt_entradas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_entradas as $entrada) {
        $stmt_detalles_depositos = $pdo->prepare("SELECT
                                                    d.nombre_deposito,
                                                    deg.litros_descargados
                                                FROM
                                                    detalle_entrada_granel deg
                                                JOIN
                                                    depositos d ON deg.id_deposito = d.id_deposito
                                                WHERE
                                                    deg.id_entrada_granel = ?");
        $stmt_detalles_depositos->execute([$entrada['id_entrada_granel']]);
        $entrada['depositos_destino'] = $stmt_detalles_depositos->fetchAll(PDO::FETCH_ASSOC);
        $entradas_granel_data[] = $entrada;
    }

} catch (PDOException $e) {
    error_log("Error al cargar todas las entradas a granel: " . $e->getMessage());
}

// Cierra la conexión PDO al final del script
$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Proveedores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* Estilos generales */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
            display: flex; /* Para el layout de sidebar y contenido principal */
            min-height: 100vh; /* Ocupa toda la altura de la ventana */
            margin: 0;
            padding: 0;
            overflow-x: hidden; /* Evitar scroll horizontal */
        }
        .main-content {
            flex-grow: 1;
            padding: 30px;
            background-color: #f4f7f6;
            min-height: 100vh; /* Asegura que el contenido principal también ocupe toda la altura */
        }
        .container { /* Cambiado de .container-fluid a .container para consistencia con clientes.php */
            background-color: #ffffff;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 40px;
            max-width: 100%; /* Ocupa el 100% del ancho disponible */
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            max-width: 100%; /* Ocupa el 100% del ancho del contenedor padre */
        }
        .card-header {
            background-color: #0056b3; /* Color primario de clientes.php */
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
            color: #333; /* Darker text for visibility */
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

        /* Estilos del Sidebar (copia de sidebar.php) */
        :root {
            --sidebar-width: 250px;
            --primary-color: #007bff; /* Un azul más estándar de Bootstrap */
            --sidebar-bg: #343a40; /* Fondo oscuro */
            --sidebar-link: #adb5bd; /* Gris claro para enlaces */
            --sidebar-hover: #495057; /* Gris un poco más oscuro al pasar el ratón */
            --sidebar-active: #0056b3; /* Azul más oscuro para el elemento activo */
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            flex-shrink: 0; /* Evita que el sidebar se encoja */
            height: 100vh; /* Asegura que el sidebar ocupe toda la altura de la ventana */
            position: sticky; /* Hace que el sidebar se mantenga visible al hacer scroll */
            top: 0; /* Alinea el sidebar con la parte superior de la ventana */
            overflow-y: auto; /* Permite el scroll si el contenido del sidebar es demasiado largo */
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
            border-radius: 8px;
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

        /* Estilos para el mapa */
        #map_picker {
            height: 350px; /* Altura del mapa */
            width: 100%;
            border-radius: 8px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .pac-container {
            z-index: 1050; /* Asegura que el autocompletado esté sobre el modal si se usa en uno */
        }

        /* Estilo para la columna de acciones en la tabla */
        .table td.actions-column {
            white-space: nowrap; /* Evita que los botones de acción se envuelvan */
            width: 1%; /* Intenta que la columna sea lo más pequeña posible, dejando espacio para el contenido */
        }
    </style>
</head>
<body>
    <?php
    // Incluir el sidebar para la navegación
    include 'sidebar.php';
    ?>

    <div class="main-content">
        <div class="container">
            <h2 class="mb-4 text-center"><i class="bi bi-truck"></i> Gestión de Proveedores</h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <h3 class="mt-5 mb-3">Listado de Proveedores</h3>
            <div class="card table-section-card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Proveedores Registrados</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="filtro_proveedor" class="form-label">Filtrar por Proveedor:</label>
                        <select class="form-select" id="filtro_proveedor">
                            <option value="">Mostrar Todos</option>
                            <?php foreach ($nombres_proveedor_para_filtro as $prov_filter): ?>
                                <option value="<?php echo htmlspecialchars($prov_filter['id_proveedor']); ?>">
                                    <?php echo htmlspecialchars($prov_filter['nombre_proveedor']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (empty($proveedores)): ?>
                        <p class="text-center">No hay proveedores registrados todavía.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle table-proveedores">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre del Proveedor</th>
                                        <th>Persona de Contacto</th>
                                        <th>Teléfono</th>
                                        <th>Email</th>
                                        <th>Dirección</th>
                                        <th>CIF/NIF</th>
                                        <th>Dir. Google Maps</th> <!-- Nueva columna -->
                                        <th>Observaciones</th>
                                        <th>Fecha de Alta</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="proveedoresTableBody">
                                    <?php foreach ($proveedores as $prov): ?>
                                        <tr data-id-proveedor="<?php echo htmlspecialchars($prov['id_proveedor']); ?>">
                                            <td><?php echo htmlspecialchars($prov['id_proveedor']); ?></td>
                                            <td><?php echo htmlspecialchars($prov['nombre_proveedor']); ?></td>
                                            <td><?php echo htmlspecialchars($prov['contacto_persona'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($prov['telefono'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($prov['email'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($prov['direccion'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($prov['cif_nif'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($prov['direccion_google_maps'] ?? 'N/A'); ?></td> <!-- Mostrar el nuevo campo -->
                                            <td><?php echo htmlspecialchars($prov['observaciones'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($prov['fecha_alta']))); ?></td>
                                            <td class="text-center actions-column">
                                                <div class="d-flex gap-2 justify-content-center">
                                                    <button type="button" class="btn btn-warning btn-sm" onclick="editProveedor(<?php echo htmlspecialchars(json_encode($prov)); ?>)"><i class="bi bi-pencil-square"></i> Editar</button>
                                                    <!-- Botón de eliminar eliminado -->
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sección de Detalles de Compras del Proveedor Seleccionado -->
            <div id="purchasesDetailsSection" class="card purchases-section-card mt-5" style="display:none;">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0" id="purchasesDetailsTitle">Compras del Proveedor: <span></span></h5>
                </div>
                <div class="card-body">
                    <div id="purchasesDetailsContent">
                        <!-- Contenido de las compras se generará aquí por JavaScript -->
                    </div>
                </div>
            </div>

            <div class="card form-section-card mt-5">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Añadir/Editar Proveedor</h5>
                </div>
                <div class="card-body">
                    <form action="proveedores.php" method="POST">
                        <input type="hidden" name="form_action" value="upsert_proveedor">
                        <input type="hidden" name="id_proveedor" id="id_proveedor_form" value="<?php echo htmlspecialchars($proveedor_a_editar['id_proveedor']); ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nombre_proveedor" class="form-label form-label-required">Nombre del Proveedor</label>
                                <input type="text" class="form-control" id="nombre_proveedor" name="nombre_proveedor" value="<?php echo htmlspecialchars($proveedor_a_editar['nombre_proveedor']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="contacto_persona" class="form-label">Persona de Contacto</label>
                                <input type="text" class="form-control" id="contacto_persona" name="contacto_persona" value="<?php echo htmlspecialchars($proveedor_a_editar['contacto_persona']); ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="telefono" class="form-label">Teléfono</label>
                                <input type="tel" class="form-control" id="telefono" name="telefono" value="<?php echo htmlspecialchars($proveedor_a_editar['telefono']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($proveedor_a_editar['email']); ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="direccion" class="form-label">Dirección (Introducida por el usuario)</label>
                                <input type="text" class="form-control" id="direccion" name="direccion" value="<?php echo htmlspecialchars($proveedor_a_editar['direccion']); ?>">
                            </div>
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
                                    <input type="text" class="form-control" id="latitud" name="latitud" value="<?php echo htmlspecialchars($proveedor_a_editar['latitud'] ?? ''); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label for="longitud" class="form-label">Longitud</label>
                                    <input type="text" class="form-control" id="longitud" name="longitud" value="<?php echo htmlspecialchars($proveedor_a_editar['longitud'] ?? ''); ?>" readonly>
                                </div>
                                <div class="col-12 mt-3">
                                    <label for="direccion_google_maps" class="form-label">Dirección Formateada por Google Maps</label>
                                    <input type="text" class="form-control" id="direccion_google_maps" name="direccion_google_maps" value="<?php echo htmlspecialchars($proveedor_a_editar['direccion_google_maps'] ?? ''); ?>" readonly>
                                    <small class="form-text text-muted">Esta dirección es la que Google Maps ha reconocido y es la más precisa para la geolocalización.</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="cif_nif" class="form-label">CIF/NIF</label>
                                <input type="text" class="form-control" id="cif_nif" name="cif_nif" value="<?php echo htmlspecialchars($proveedor_a_editar['cif_nif']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="observaciones" class="form-label">Observaciones</label>
                                <textarea class="form-control" id="observaciones" name="observaciones" rows="2"><?php echo htmlspecialchars($proveedor_a_editar['observaciones']); ?></textarea>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary me-2"><i class="bi bi-save"></i> Guardar Proveedor</button>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        // Variables globales para el mapa de selección de coordenadas
        let mapPicker;
        let markerPicker;
        let latInput;
        let lngInput;
        let searchAddressInput;
        let direccionGoogleMapsInput;
        let geocoder;

        // Función de inicialización del mapa de selección (callback de Google Maps API)
        window.initMapPicker = function() {
            latInput = document.getElementById('latitud');
            lngInput = document.getElementById('longitud');
            searchAddressInput = document.getElementById('search_address_input');
            direccionGoogleMapsInput = document.getElementById('direccion_google_maps');

            const initialLat = parseFloat(latInput.value) || 37.3828300; // Default a Sevilla
            const initialLng = parseFloat(lngInput.value) || -5.9731700;

            mapPicker = new google.maps.Map(document.getElementById('map_picker'), {
                center: { lat: initialLat, lng: initialLng },
                zoom: 12,
                mapId: 'DEMO_MAP_ID' // Puedes usar un Map ID personalizado si lo configuras en Google Cloud
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
                geocodeLatLng(newPosition.lat, newPosition.lng);
            });

            if (latInput.value && lngInput.value) {
                const currentPosition = { lat: initialLat, lng: initialLng };
                markerPicker.position = currentPosition;
                mapPicker.setCenter(currentPosition);
            }

            const searchBox = new google.maps.places.SearchBox(searchAddressInput);

            mapPicker.addListener('bounds_changed', () => {
                searchBox.setBounds(mapPicker.getBounds());
            });

            searchBox.addListener('places_changed', () => {
                const places = searchBox.getPlaces();

                if (places.length == 0) {
                    return;
                }

                const bounds = new google.maps.LatLngBounds();
                places.forEach(place => {
                    if (!place.geometry || !place.geometry.location) {
                        console.log("Returned place contains no geometry");
                        return;
                    }

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
        };

        function geocodeAddress() {
            const direccion = document.getElementById('direccion').value;
            // No hay campos de ciudad, provincia, cp específicos para el proveedor en el formulario,
            // así que solo usamos la dirección y añadimos "España" para contexto.
            const fullAddress = `${direccion}, España`;

            if (!geocoder) {
                console.error('Geocoder no está inicializado.');
                alert('El servicio de mapas no está listo. Inténtelo de nuevo en unos segundos.');
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

                    alert('Coordenadas y dirección de Google Maps obtenidas correctamente.');
                } else {
                    alert('Geocodificación fallida por la siguiente razón: ' + status + '. Por favor, revise la dirección o seleccione manualmente en el mapa.');
                    console.error('Geocode was not successful for the following reason: ' + status);
                    latInput.value = '';
                    lngInput.value = '';
                    direccionGoogleMapsInput.value = '';
                }
            });
        }

        function geocodeLatLng(lat, lng) {
            if (!geocoder) {
                console.error('Geocoder no está inicializado para geocodificación inversa.');
                return;
            }
            const latlng = { lat: parseFloat(lat), lng: parseFloat(lng) };
            geocoder.geocode({ 'location': latlng }, function(results, status) {
                if (status === 'OK') {
                    if (results[0]) {
                        direccionGoogleMapsInput.value = results[0].formatted_address;
                    } else {
                        direccionGoogleMapsInput.value = 'Dirección no encontrada';
                    }
                } else {
                    console.error('Reverse geocode failed due to: ' + status);
                    direccionGoogleMapsInput.value = 'Error al obtener dirección';
                }
            });
        }


        // Data de todos los proveedores cargada desde PHP
        const allProveedores = <?php echo json_encode($proveedores); ?>;
        // Data de todas las entradas a granel cargada desde PHP
        const allEntradasGranel = <?php echo json_encode($entradas_granel_data); ?>;

        // Función para cargar los datos de un proveedor en el formulario para edición
        function editProveedor(prov) {
            document.getElementById('id_proveedor_form').value = prov.id_proveedor;
            document.getElementById('nombre_proveedor').value = prov.nombre_proveedor;
            document.getElementById('contacto_persona').value = prov.contacto_persona;
            document.getElementById('telefono').value = prov.telefono;
            document.getElementById('email').value = prov.email;
            document.getElementById('direccion').value = prov.direccion;
            document.getElementById('cif_nif').value = prov.cif_nif;
            document.getElementById('observaciones').value = prov.observaciones;

            // Cargar datos de geolocalización
            const lat = parseFloat(prov.latitud);
            const lng = parseFloat(prov.longitud);

            if (!isNaN(lat) && !isNaN(lng)) {
                document.getElementById('latitud').value = lat;
                document.getElementById('longitud').value = lng;
                document.getElementById('direccion_google_maps').value = prov.direccion_google_maps || '';

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
                    const defaultLatLng = { lat: 37.3828300, lng: -5.9731700 }; // Sevilla
                    markerPicker.position = defaultLatLng;
                    mapPicker.setCenter(defaultLatLng);
                    mapPicker.setZoom(12);
                }
            }
            document.getElementById('search_address_input').value = '';

            window.scrollTo(0, 0); // Desplazarse al principio de la página para ver el formulario
        }

        // Función para limpiar el formulario
        function resetForm() {
            document.getElementById('id_proveedor_form').value = '';
            document.getElementById('nombre_proveedor').value = '';
            document.getElementById('contacto_persona').value = '';
            document.getElementById('telefono').value = '';
            document.getElementById('email').value = '';
            document.getElementById('direccion').value = '';
            document.getElementById('cif_nif').value = '';
            document.getElementById('observaciones').value = '';
            // Limpiar campos de geolocalización
            document.getElementById('latitud').value = '';
            document.getElementById('longitud').value = '';
            document.getElementById('direccion_google_maps').value = '';
            document.getElementById('search_address_input').value = '';

            // Resetear el mapa a la posición por defecto
            if (mapPicker && markerPicker) {
                const defaultLatLng = { lat: 37.3828300, lng: -5.9731700 }; // Sevilla
                markerPicker.position = defaultLatLng;
                mapPicker.setCenter(defaultLatLng);
                mapPicker.setZoom(12);
            }

            // Resetear el filtro de búsqueda a "Mostrar Todos"
            document.getElementById('filtro_proveedor').value = '';
            applyFilter(); // Aplicar el filtro para mostrar todos los proveedores y ocultar detalles de compras
        }

        document.addEventListener('DOMContentLoaded', function() {
            const filtroProveedorSelect = document.getElementById('filtro_proveedor');
            const proveedoresTableBody = document.getElementById('proveedoresTableBody');
            const purchasesDetailsSection = document.getElementById('purchasesDetailsSection');
            const purchasesDetailsTitleSpan = document.querySelector('#purchasesDetailsTitle span');
            const purchasesDetailsContent = document.getElementById('purchasesDetailsContent');

            // Función para aplicar el filtro a la tabla de proveedores y mostrar/ocultar detalles de compras
            function applyFilter() {
                const selectedProveedorId = filtroProveedorSelect.value;
                const rows = proveedoresTableBody.getElementsByTagName('tr');

                // Filtrar tabla de proveedores
                for (let i = 0; i < rows.length; i++) {
                    const row = rows[i];
                    const idProveedor = row.getAttribute('data-id-proveedor');

                    if (selectedProveedorId === '' || idProveedor === selectedProveedorId) {
                        row.style.display = ''; // Mostrar la fila
                    } else {
                        row.style.display = 'none'; // Ocultar la fila
                    }
                }

                // Mostrar u ocultar la sección de detalles de compras
                if (selectedProveedorId === '') {
                    purchasesDetailsSection.style.display = 'none'; // Ocultar la sección
                    purchasesDetailsContent.innerHTML = ''; // Limpiar contenido
                } else {
                    const selectedProveedor = allProveedores.find(p => p.id_proveedor == selectedProveedorId);
                    if (selectedProveedor) {
                        purchasesDetailsTitleSpan.textContent = selectedProveedor.nombre_proveedor;
                        displayPurchasesForProveedor(selectedProveedorId);
                        purchasesDetailsSection.style.display = 'block'; // Mostrar la sección
                        // Desplazarse a la sección de detalles de compras
                        purchasesDetailsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            }

            // Función para mostrar las compras de un proveedor específico
            function displayPurchasesForProveedor(proveedorId) {
                const filteredPurchases = allEntradasGranel.filter(entrada => entrada.id_proveedor == proveedorId);

                let purchasesHtml = '';
                if (filteredPurchases.length === 0) {
                    purchasesHtml = '<p class="text-center">No hay compras registradas para este proveedor.</p>';
                } else {
                    let totalKg = 0;
                    let totalLitros = 0;

                    purchasesHtml += `
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle table-compras">
                                <thead>
                                    <tr>
                                        <th>ID Entrada</th>
                                        <th>Fecha</th>
                                        <th>Lote Proveedor</th>
                                        <th>Materia Prima</th>
                                        <th>Kilos (kg)</th>
                                        <th>Litros (L)</th>
                                        <th>Depósitos Destino (Litros)</th>
                                        <th>Observaciones</th>
                                        <th>Albarán</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                    filteredPurchases.forEach(entrada => {
                        totalKg += parseFloat(entrada.kg_recibidos);
                        totalLitros += parseFloat(entrada.litros_equivalentes);

                        let depositosListHtml = 'N/A';
                        if (entrada.depositos_destino && entrada.depositos_destino.length > 0) {
                            depositosListHtml = '<ul>';
                            entrada.depositos_destino.forEach(dep => {
                                depositosListHtml += `<li>${dep.nombre_deposito}: ${parseFloat(dep.litros_descargados).toFixed(2).replace('.', ',')} L</li>`;
                            });
                            depositosListHtml += '</ul>';
                        }

                        let albaranHtml = 'N/A';
                        if (entrada.ruta_albaran_pdf) {
                            albaranHtml = `<a href="${entrada.ruta_albaran_pdf}" target="_blank" class="btn btn-sm btn-info"><i class="bi bi-file-earmark-pdf-fill"></i> Ver PDF</a>`;
                        }

                        purchasesHtml += `
                            <tr>
                                <td>${entrada.id_entrada_granel}</td>
                                <td>${new Date(entrada.fecha_entrada).toLocaleDateString('es-ES')}</td>
                                <td>${entrada.numero_lote_proveedor}</td>
                                <td>${entrada.nombre_materia_prima || 'N/A'}</td>
                                <td>${parseFloat(entrada.kg_recibidos).toFixed(2).replace('.', ',')}</td>
                                <td>${parseFloat(entrada.litros_equivalentes).toFixed(2).replace('.', ',')}</td>
                                <td>${depositosListHtml}</td>
                                <td>${entrada.observaciones || 'N/A'}</td>
                                <td>${albaranHtml}</td>
                            </tr>
                        `;
                    });

                    purchasesHtml += `
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4"><strong>Total Compras de este Proveedor</strong></td>
                                        <td><strong>${totalKg.toFixed(2).replace('.', ',')} kg</strong></td>
                                        <td><strong>${totalLitros.toFixed(2).replace('.', ',')} L</strong></td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    `;
                }
                purchasesDetailsContent.innerHTML = purchasesHtml;
            }


            // Añadir event listener al cambio del selector de filtro
            filtroProveedorSelect.addEventListener('change', applyFilter);

            // Aplicar el filtro inicial al cargar la página (mostrar todos por defecto y ocultar detalles de compras)
            applyFilter();
        });
    </script>
    <!-- Carga asíncrona de la API de Google Maps con las librerías 'places', 'marker' y 'geocoding' -->
    <!-- Reemplaza 'YOUR_API_KEY' con tu clave real de la API de Google Maps -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAwQDkg4J9qi7toUQ6eSVjul8HKTYoRzz8&libraries=places,marker,geocoding&callback=initMapPicker" async defer></script>
</body>
</html>
