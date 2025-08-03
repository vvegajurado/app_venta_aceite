<?php
// Incluir el archivo de verificación de autenticación AL PRINCIPIO para asegurar que la sesión se inicie primero.
include 'auth_check.php';

// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Inicializar variables para mensajes y datos de los selectores
$mensaje = '';
$tipo_mensaje = '';
$depositos_disponibles_origen = []; // Para el select de origen
$depositos_disponibles_destino = []; // Para el select de destino

// Tipos de trasvase disponibles (según la definición ENUM de tu tabla trasvases)
$tipos_trasvase_disponibles = ['filtrado', 'trasiego', 'preparacion_lote'];

// --- Cargar datos de apoyo para los selectores (Materias Primas y Entradas a Granel) ---
$materias_primas_map = [];
$entradas_granel_map = [];

try {
    // Cargar Materias Primas en un mapa para acceso rápido por ID
    $stmt_mp_map = $pdo->query("SELECT id_materia_prima, nombre_materia_prima FROM materias_primas");
    while ($row = $stmt_mp_map->fetch(PDO::FETCH_ASSOC)) {
        $materias_primas_map[$row['id_materia_prima']] = $row['nombre_materia_prima'];
    }

    // Cargar Números de Lote de Entradas a Granel en un mapa para acceso rápido por ID
    // Es importante que la tabla 'entradas_granel' exista y tenga datos para que esto funcione.
    $stmt_eg_map = $pdo->query("SELECT id_entrada_granel, numero_lote_proveedor FROM entradas_granel");
    while ($row = $stmt_eg_map->fetch(PDO::FETCH_ASSOC)) {
        $entradas_granel_map[$row['id_entrada_granel']] = $row['numero_lote_proveedor'];
    }

    // Obtener depósitos disponibles con su info actual para origen y destino
    // Ya no necesitamos JOIN con materias_primas aquí, ya que tenemos el mapa cargado previamente
    $stmt_depositos = $pdo->query("SELECT id_deposito, nombre_deposito, capacidad, stock_actual, id_materia_prima, id_entrada_granel_origen, estado FROM depositos ORDER BY nombre_deposito ASC");
    $depositos_disponibles_origen = $stmt_depositos->fetchAll(PDO::FETCH_ASSOC);
    $depositos_disponibles_destino = $depositos_disponibles_origen; // Los mismos depósitos para origen y destino inicialmente

} catch (PDOException $e) {
    $mensaje = "Error al cargar datos para los formularios: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// --- Lógica para PROCESAR el formulario de Trasvase ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'realizar_trasvase') {
    $deposito_origen_id = $_POST['deposito_origen'] ?? null;
    $deposito_destino_id = $_POST['deposito_destino'] ?? null;
    $litros_trasvase = $_POST['litros_trasvase'] ?? 0;
    $fecha_trasvase = $_POST['fecha_trasvase'] ?? date('Y-m-d');
    $tipo_trasvase = $_POST['tipo_trasvase'] ?? '';
    $observaciones = $_POST['observaciones'] ?? '';

    // Convertir a float
    $litros_trasvase = (float)$litros_trasvase;

    // Validación inicial
    if (empty($deposito_origen_id) || empty($deposito_destino_id) || !is_numeric($litros_trasvase) || $litros_trasvase <= 0 || empty($tipo_trasvase)) {
        $mensaje = "Error: Todos los campos obligatorios (incluido el tipo de trasvase) deben ser completados y los litros a trasvasar deben ser un número positivo.";
        $tipo_mensaje = 'danger';
    } elseif ($deposito_origen_id == $deposito_destino_id) {
        $mensaje = "Error: El depósito de origen y el de destino no pueden ser el mismo.";
        $tipo_mensaje = 'danger';
    } elseif (!in_array($tipo_trasvase, $tipos_trasvase_disponibles)) {
        $mensaje = "Error: Tipo de trasvase no válido.";
        $tipo_mensaje = 'danger';
    }
    else {
        try {
            $pdo->beginTransaction(); // Iniciar transacción

            // Bloquear depósitos para asegurar atomicidad
            $stmt_lock_depositos = $pdo->prepare("SELECT id_deposito, nombre_deposito, capacidad, stock_actual, id_materia_prima, id_entrada_granel_origen, estado FROM depositos WHERE id_deposito IN (?, ?) FOR UPDATE");
            $stmt_lock_depositos->execute([$deposito_origen_id, $deposito_destino_id]);
            $locked_depositos = $stmt_lock_depositos->fetchAll(PDO::FETCH_ASSOC);

            // Asegurarse de que ambos depósitos existen
            if (count($locked_depositos) !== 2) {
                throw new Exception("Uno o ambos depósitos seleccionados no existen.");
            }

            $deposito_origen_info = null;
            $deposito_destino_info = null;

            foreach ($locked_depositos as $dep) {
                if ($dep['id_deposito'] == $deposito_origen_id) {
                    $deposito_origen_info = $dep;
                } elseif ($dep['id_deposito'] == $deposito_destino_id) {
                    $deposito_destino_info = $dep;
                }
            }

            if (!$deposito_origen_info || !$deposito_destino_info) {
                throw new Exception("Error al obtener información de los depósitos.");
            }

            // Validar stock en origen
            if ($deposito_origen_info['stock_actual'] < $litros_trasvase) {
                throw new Exception("Error: No hay suficiente stock en el depósito origen ('{$deposito_origen_info['nombre_deposito']}'). Stock actual: " . number_format($deposito_origen_info['stock_actual'], 2, ',', '.') . " L.");
            }
            if ($deposito_origen_info['stock_actual'] == 0) {
                 throw new Exception("Error: El depósito origen ('{$deposito_origen_info['nombre_deposito']}') está vacío. No se puede trasvasar.");
            }


            // Validar capacidad en destino
            $nuevo_stock_destino = $deposito_destino_info['stock_actual'] + $litros_trasvase;
            if ($nuevo_stock_destino > $deposito_destino_info['capacidad']) {
                throw new Exception("Error: El depósito destino ('{$deposito_destino_info['nombre_deposito']}') no tiene suficiente capacidad. Capacidad restante: " . number_format($deposito_destino_info['capacidad'] - $deposito_destino_info['stock_actual'], 2, ',', '.') . " L.");
            }

            // Validar materia prima y lote (no mezclar tipos de aceite o lotes diferentes en depósitos con stock)
            if ($deposito_destino_info['stock_actual'] > 0) { // Si el destino tiene stock
                if ($deposito_origen_info['id_materia_prima'] !== (int)$deposito_destino_info['id_materia_prima']) {
                    // Usar los mapas para obtener nombres de MP
                    $mp_origen_name = $materias_primas_map[$deposito_origen_info['id_materia_prima']] ?? 'Desconocida';
                    $mp_destino_name = $materias_primas_map[$deposito_destino_info['id_materia_prima']] ?? 'Desconocida';
                    throw new Exception("Error: No se puede trasvasar de '{$mp_origen_name}' a '{$mp_destino_name}'. El depósito destino ('{$deposito_destino_info['nombre_deposito']}') ya contiene una materia prima diferente.");
                }

                if ($deposito_origen_info['id_entrada_granel_origen'] !== (int)$deposito_destino_info['id_entrada_granel_origen']) {
                    $lote_origen = $entradas_granel_map[$deposito_origen_info['id_entrada_granel_origen']] ?? 'Desconocido';
                    $lote_destino = $entradas_granel_map[$deposito_destino_info['id_entrada_granel_origen']] ?? 'Desconocido';
                    throw new Exception("Error: El lote del depósito origen ('{$lote_origen}') no coincide con el lote del depósito destino ('{$lote_destino}'). No se puede mezclar.");
                }

            } else { // Si el depósito destino está vacío, asignamos la MP y el lote del origen
                $deposito_destino_info['id_materia_prima'] = $deposito_origen_info['id_materia_prima'];
                $deposito_destino_info['id_entrada_granel_origen'] = $deposito_origen_info['id_entrada_granel_origen'];
            }


            // Actualizar stock del depósito origen
            $nuevo_stock_origen = $deposito_origen_info['stock_actual'] - $litros_trasvase;
            $estado_origen = ($nuevo_stock_origen == 0) ? 'vacio' : 'ocupado';
            // Si el depósito queda vacío, también se limpia su materia prima y lote
            $id_mp_origen_update = ($nuevo_stock_origen == 0) ? NULL : $deposito_origen_info['id_materia_prima'];
            $id_lote_origen_update = ($nuevo_stock_origen == 0) ? NULL : $deposito_origen_info['id_entrada_granel_origen'];


            $stmt_update_origen = $pdo->prepare("UPDATE depositos SET stock_actual = ?, estado = ?, id_materia_prima = ?, id_entrada_granel_origen = ? WHERE id_deposito = ?");
            $stmt_update_origen->execute([$nuevo_stock_origen, $estado_origen, $id_mp_origen_update, $id_lote_origen_update, $deposito_origen_id]);

            // Actualizar stock del depósito destino
            $estado_destino = ($nuevo_stock_destino == $deposito_destino_info['capacidad']) ? 'lleno' : 'ocupado';

            $stmt_update_destino = $pdo->prepare("UPDATE depositos SET stock_actual = ?, estado = ?, id_materia_prima = ?, id_entrada_granel_origen = ? WHERE id_deposito = ?");
            $stmt_update_destino->execute([$nuevo_stock_destino, $estado_destino, $deposito_destino_info['id_materia_prima'], $deposito_destino_info['id_entrada_granel_origen'], $deposito_destino_id]);

            // Registrar el trasvase en la tabla 'trasvases'
            $stmt_trasvase_log = $pdo->prepare("INSERT INTO trasvases (fecha_trasvase, deposito_origen, deposito_destino, litros_trasvasados, tipo_trasvase, observaciones) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_trasvase_log->execute([$fecha_trasvase, $deposito_origen_id, $deposito_destino_id, $litros_trasvase, $tipo_trasvase, $observaciones]);

            $pdo->commit(); // Confirmar la transacción
            $mensaje = "Trasvase de " . number_format($litros_trasvase, 2, ',', '.') . " L realizado correctamente desde '{$deposito_origen_info['nombre_deposito']}' a '{$deposito_destino_info['nombre_deposito']}'.";
            $tipo_mensaje = 'success';
            header("Location: trasvases.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "Error al procesar el trasvase: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        } catch (PDOException $e) {
            $pdo->rollBack();
            $mensaje = "Error de base de datos al procesar el trasvase: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// Cargar mensajes de la redirección
if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $tipo_mensaje = $_GET['tipo_mensaje'];
}

// Lógica para listar Trasvases (para mostrar en la tabla inferior)
$trasvases_registrados = [];
try {
    $sql_trasvases = "SELECT
                        t.id_trasvase,
                        t.fecha_trasvase,
                        do.nombre_deposito AS nombre_deposito_origen,
                        dd.nombre_deposito AS nombre_deposito_destino,
                        t.litros_trasvasados,
                        t.tipo_trasvase,
                        t.observaciones
                    FROM
                        trasvases t
                    JOIN
                        depositos do ON t.deposito_origen = do.id_deposito
                    JOIN
                        depositos dd ON t.deposito_destino = dd.id_deposito
                    ORDER BY
                        t.fecha_trasvase DESC, t.id_trasvase DESC";
    $stmt_trasvases = $pdo->query($sql_trasvases);
    $trasvases_registrados = $stmt_trasvases->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensaje_listado = "Error al cargar el listado de trasvases: " . $e->getMessage();
    $tipo_mensaje_listado = 'danger';
}

// Cierra la conexión PDO al final del script
$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Trasvases</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Variables globales */
        :root {
            --primary-color: #0056b3; /* Azul primario, consistente con otros archivos */
            --primary-dark: #004494;
            --secondary-color: #28a745; /* Verde para énfasis (usado para bg-info) */
            --text-dark: #333;
            --text-light: #666;
            --bg-light: #f4f7f6; /* Fondo claro */
            --header-bg: #ffffff; /* Fondo blanco para el header */
            --shadow-light: rgba(0, 0, 0, 0.05);
            --shadow-medium: rgba(0, 0, 0, 0.1);

            /* Variables para el diseño del sidebar y contenido principal */
            --sidebar-width: 250px; /* Ancho del sidebar con texto */
        }

        /* Estilos generales del body y layout principal */
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
            font-size: 1rem;
            display: flex; /* Para el layout de sidebar y contenido principal */
            min-height: 100vh; /* Ocupa toda la altura de la ventana */
            margin: 0;
            padding: 0;
            overflow-x: hidden; /* Evitar scroll horizontal */
        }

        /* Contenido Principal */
        .main-content {
            flex-grow: 1; /* Ocupa el espacio restante */
            padding: 30px;
            transition: margin-left 0.3s ease;
            background-color: var(--bg-light);
            min-height: 100vh; /* Asegura que el contenido principal también ocupe toda la altura */
        }

        /* Estilos del contenedor principal dentro del main-content */
        .container {
            background-color: var(--header-bg); /* Fondo blanco para el contenedor */
            padding: 40px;
            border-radius: 15px; /* Bordes más redondeados */
            box-shadow: 0 4px 8px var(--shadow-light); /* Sombra suave */
            margin-bottom: 40px;
            max-width: 100%; /* Ancho máximo para el contenedor */
            margin-left: auto; /* Centrar el contenedor */
            margin-right: auto; /* Centrar el contenedor */
        }

        h1, h2, h3, h4, h5, h6 {
            color: var(--primary-dark);
            font-weight: 600;
        }
        h2 {
            font-size: 1.8rem;
            margin-bottom: 25px;
            text-align: center;
        }
        .form-label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 1px solid var(--border-color, #e0e0e0);
            border-radius: 8px;
            padding: 10px 15px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(0, 86, 179, 0.25); /* Color primario de Bootstrap */
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            font-weight: 600;
            padding: 12px 25px;
            border-radius: 8px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        /* Ajuste para que los botones de acción en tabla tengan el mismo estilo y tamaño */
        .btn-sm {
            padding: 6px 10px; /* Reducido para hacerlos más compactos */
            border-radius: 8px;
            font-size: 0.875rem;
            line-height: 1.2; /* Para asegurar que el texto y el icono se alineen bien */
            display: inline-flex; /* Permite alinear el ícono y el texto */
            align-items: center; /* Centra verticalmente el contenido */
            justify-content: center; /* Centra horizontalmente el contenido */
        }
        .btn-sm i { /* Ajuste para el ícono dentro del botón pequeño */
            margin-right: 5px; /* Espacio entre el ícono y el texto */
            font-size: 0.9rem; /* Puede que necesitemos ajustar el tamaño del ícono ligeramente */
        }

        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #bd2130;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
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
        .btn-info {
            background-color: var(--secondary-color); /* Usar la variable para consistencia */
            border-color: var(--secondary-color);
            color: #fff;
        }
        .btn-info:hover {
            background-color: var(--secondary-dark); /* Usar la variable para consistencia */
            border-color: var(--secondary-dark);
        }

        .card {
            margin-bottom: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 8px var(--shadow-light);
        }
        .card-header {
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            padding: 15px 20px;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
        }
        .card-header.bg-success {
             background-color: var(--primary-color) !important;
        }
        .card-header.bg-info {
            background-color: #17a2b8 !important; /* Usar color info de Bootstrap para consistencia */
        }

        .table-responsive {
            margin-top: 20px;
        }
        .table thead th {
            background-color: var(--primary-dark);
            color: white;
            border-color: var(--primary-dark);
            padding: 12px 15px;
        }
        .table tbody tr:hover {
            background-color: #e6f3e6;
        }
        .table-bordered {
            border-color: var(--border-color, #e0e0e0);
        }
        .table td, .table th {
            vertical-align: middle;
            text-align: center; /* Centrar todo por defecto */
        }
        .table th:nth-child(5), .table td:nth-child(5) { /* Litros Trasvasados */
            text-align: right;
        }

        .form-label-required::after {
            content: " *";
            color: red;
            margin-left: 3px;
        }
        /* Estilos específicos para el formulario de trasvases */
        .trasvase-section {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            background-color: #fcfdfe;
            box-shadow: 0 1px 5px rgba(0,0,0,0.05);
        }
        .trasvase-section h5 {
            color: var(--primary-dark); /* Changed to primary-dark for consistency */
            margin-bottom: 15px;
            border-bottom: 1px dashed #e0e0e0;
            padding-bottom: 10px;
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
            .container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <h2 class="mb-4 text-center"><i class="bi bi-arrow-left-right me-3"></i> Gestión de Trasvases</h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($mensaje_listado) && $mensaje_listado): ?>
                <div class="alert alert-<?php echo $tipo_mensaje_listado; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje_listado; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Formulario de Trasvase -->
            <div class="card">
                <div class="card-header bg-success">
                    <h5 class="mb-0">Realizar Nuevo Trasvase</h5>
                </div>
                <div class="card-body">
                    <form action="trasvases.php" method="POST">
                        <input type="hidden" name="accion" value="realizar_trasvase">

                        <div class="row mb-3 trasvase-section">
                            <h5>Depósito de Origen</h5>
                            <div class="col-md-6 mb-3">
                                <label for="deposito_origen" class="form-label form-label-required">Seleccione Depósito Origen</label>
                                <select class="form-select" id="deposito_origen" name="deposito_origen" required>
                                    <option value="">Seleccione...</option>
                                    <?php foreach ($depositos_disponibles_origen as $deposito): ?>
                                        <?php
                                            $info_origen = " [Stock: " . number_format($deposito['stock_actual'], 2, ',', '.') . " L";
                                            // Usar el mapa de materias primas para obtener el nombre
                                            $mp_nombre = $materias_primas_map[$deposito['id_materia_prima']] ?? 'Desconocida';
                                            if (!empty($deposito['id_materia_prima'])) {
                                                $info_origen .= ", MP: " . htmlspecialchars($mp_nombre);
                                            }
                                            // Usar el mapa de entradas a granel para obtener el lote
                                            $lote_display = $entradas_granel_map[$deposito['id_entrada_granel_origen']] ?? 'Desconocido';
                                            if ($deposito['id_entrada_granel_origen']) {
                                                $info_origen .= ", Lote: " . htmlspecialchars($lote_display);
                                            }
                                            $info_origen .= "]";
                                        ?>
                                        <option value="<?php echo htmlspecialchars($deposito['id_deposito']); ?>"
                                            data-stock="<?php echo htmlspecialchars($deposito['stock_actual']); ?>"
                                            data-materia-prima-id="<?php echo htmlspecialchars($deposito['id_materia_prima']); ?>"
                                            data-lote-origen-id="<?php echo htmlspecialchars($deposito['id_entrada_granel_origen']); ?>"
                                            <?php echo ($deposito['stock_actual'] == 0) ? 'disabled title="Depósito vacío"' : ''; ?>>
                                            <?php echo htmlspecialchars($deposito['nombre_deposito']) . $info_origen; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="litros_trasvase" class="form-label form-label-required">Litros a Trasvasar</label>
                                <input type="number" step="0.01" class="form-control" id="litros_trasvase" name="litros_trasvase" min="0.01" required>
                            </div>
                        </div>

                        <div class="row mb-3 trasvase-section">
                            <h5>Depósito de Destino</h5>
                            <div class="col-md-6 mb-3">
                                <label for="deposito_destino" class="form-label form-label-required">Seleccione Depósito Destino</label>
                                <select class="form-select" id="deposito_destino" name="deposito_destino" required>
                                    <option value="">Seleccione...</option>
                                    <!-- Options will be populated by JavaScript -->
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="tipo_trasvase" class="form-label form-label-required">Tipo de Trasvase</label>
                                <select class="form-select" id="tipo_trasvase" name="tipo_trasvase" required>
                                    <option value="">Seleccione...</option>
                                    <?php foreach ($tipos_trasvase_disponibles as $tipo): ?>
                                        <option value="<?php echo htmlspecialchars($tipo); ?>"><?php echo htmlspecialchars(ucfirst($tipo)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6 mb-3">
                                <label for="fecha_trasvase" class="form-label">Fecha de Trasvase</label>
                                <input type="date" class="form-control" id="fecha_trasvase" name="fecha_trasvase" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="observaciones" class="form-label">Observaciones</label>
                                <textarea class="form-control" id="observaciones" name="observaciones" rows="2"></textarea>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary me-2"><i class="bi bi-arrow-left-right"></i> Realizar Trasvase</button>
                                <button type="button" class="btn btn-secondary" onclick="resetForm()"><i class="bi bi-arrow-counterclockwise"></i> Limpiar Formulario</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <h3 class="mt-5 mb-3 text-center">Historial de Trasvases</h3>
            <div class="card">
                <div class="card-header bg-info">
                    <h5 class="mb-0">Trasvases Registrados</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($trasvases_registrados)): ?>
                        <p class="text-center">No hay trasvases registrados todavía.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID Trasvase</th>
                                        <th>Fecha</th>
                                        <th>Depósito Origen</th>
                                        <th>Depósito Destino</th>
                                        <th>Litros Trasvasados (L)</th>
                                        <th>Tipo Trasvase</th>
                                        <th>Observaciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trasvases_registrados as $trasvase): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trasvase['id_trasvase']); ?></td>
                                            <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($trasvase['fecha_trasvase']))); ?></td>
                                            <td><?php echo htmlspecialchars($trasvase['nombre_deposito_origen']); ?></td>
                                            <td><?php echo htmlspecialchars($trasvase['nombre_deposito_destino']); ?></td>
                                            <td><?php echo number_format($trasvase['litros_trasvasados'], 2, ',', '.'); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst($trasvase['tipo_trasvase'])); ?></td>
                                            <td><?php echo htmlspecialchars($trasvase['observaciones']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
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

    <!-- Bootstrap JS Bundle (incluye Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        // Data de depósitos disponible desde PHP
        const depositosData = <?php echo json_encode($depositos_disponibles_origen); ?>;
        const materiasPrimasMap = <?php echo json_encode($materias_primas_map); ?>;
        const entradasGranelMap = <?php echo json_encode($entradas_granel_map); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            const depositoOrigenSelect = document.getElementById('deposito_origen');
            const depositoDestinoSelect = document.getElementById('deposito_destino');
            const litrosTrasvaseInput = document.getElementById('litros_trasvase');

            // Helper para obtener nombre de materia prima
            function getMateriaPrimaName(id) {
                return materiasPrimasMap[id] || 'Desconocida';
            }

            // Helper para obtener nombre de lote
            function getLoteName(id) {
                return entradasGranelMap[id] || 'Desconocido';
            }

            // Función para actualizar las opciones del desplegable de depósito de destino
            function updateDepositoDestinoOptions() {
                const selectedOrigenId = depositoOrigenSelect.value;
                const litrosTrasvase = parseFloat(litrosTrasvaseInput.value) || 0;

                // Limpiar opciones actuales
                depositoDestinoSelect.innerHTML = '<option value="">Seleccione...</option>';

                if (!selectedOrigenId || litrosTrasvase <= 0) {
                    return; // No hay depósito de origen seleccionado o litros no válidos
                }

                const origenDep = depositosData.find(dep => dep.id_deposito == selectedOrigenId);

                if (!origenDep) {
                    return; // Depósito de origen no encontrado
                }

                // Obtener la materia prima y el lote del depósito de origen
                const origenMateriaPrimaId = origenDep.id_materia_prima;
                const origenLoteId = origenDep.id_entrada_granel_origen;

                depositosData.forEach(deposito => {
                    // No permitir trasvasar al mismo depósito de origen
                    if (deposito.id_deposito == selectedOrigenId) {
                        return;
                    }

                    const capacidadRestante = deposito.capacidad - deposito.stock_actual;
                    let canSelect = true;
                    let infoDestino = ` [Stock: ${parseFloat(deposito.stock_actual).toFixed(2).replace('.',',')} L, Cap. Restante: ${parseFloat(capacidadRestante).toFixed(2).replace('.',',')} L]`;

                    // 1. Filtrar por capacidad: solo si tienen capacidad suficiente
                    if (capacidadRestante < litrosTrasvase) {
                        canSelect = false;
                        infoDestino += " (Capacidad insuficiente)";
                    }

                    // 2. Filtrar por tipo de materia prima y lote (si el destino no está vacío)
                    if (deposito.stock_actual > 0) {
                        // Si el depósito de destino ya tiene materia prima y es diferente
                        if (deposito.id_materia_prima !== null && origenMateriaPrimaId !== null && (parseInt(deposito.id_materia_prima) !== parseInt(origenMateriaPrimaId))) {
                            canSelect = false;
                            infoDestino += ` (Contiene MP: ${getMateriaPrimaName(deposito.id_materia_prima)}, no compatible)`;
                        }
                        // Si el depósito de destino ya tiene lote de origen y es diferente
                        if (deposito.id_entrada_granel_origen !== null && origenLoteId !== null && (parseInt(deposito.id_entrada_granel_origen) !== parseInt(origenLoteId))) {
                            canSelect = false;
                            infoDestino += ` (Contiene Lote: ${getLoteName(deposito.id_entrada_granel_origen)}, no compatible)`;
                        }
                    }

                    if (canSelect) {
                        const option = document.createElement('option');
                        option.value = deposito.id_deposito;
                        option.textContent = `${deposito.nombre_deposito}${infoDestino}`;
                        depositoDestinoSelect.appendChild(option);
                    }
                });
            }

            // Event listeners para actualizar las opciones de destino
            depositoOrigenSelect.addEventListener('change', updateDepositoDestinoOptions);
            litrosTrasvaseInput.addEventListener('input', updateDepositoDestinoOptions);

            // Llamada inicial para poblar el select de destino (estará vacío al principio si no hay origen/litros)
            updateDepositoDestinoOptions();
        });

        function resetForm() {
            document.querySelector('form').reset();
            document.getElementById('fecha_trasvase').value = '<?php echo date('Y-m-d'); ?>';
            // Resetear manualmente los selects para que se repueblen correctamente
            const depositoOrigenSelect = document.getElementById('deposito_origen');
            const depositoDestinoSelect = document.getElementById('deposito_destino');
            depositoOrigenSelect.value = "";
            depositoDestinoSelect.innerHTML = '<option value="">Seleccione...</option>'; // Limpiar destino
            depositoOrigenSelect.dispatchEvent(new Event('change')); // Disparar el evento para repoblar destino
        }
    </script>
</body>
</html>
