<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Iniciar sesión para gestionar mensajes
session_start();

// Constante para la densidad del aceite de oliva (ej. 0.916 kg/L)
// Este valor puede necesitar ser ajustado según el tipo específico de aceite y la temperatura.
const OLIVE_OIL_DENSITY_KG_PER_LITER = 0.916; 

// Inicializar variables para mensajes (similares a depositos.php)
$mensaje = '';
$tipo_mensaje = '';

// --- Lógica para procesar el formulario de añadir/editar materia prima ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recogemos los datos del formulario.
    $id_materia_prima_form = $_POST['id_materia_prima'] ?? null; // Usamos id_materia_prima para edición
    $nombre_materia_prima = trim($_POST['nombre_materia_prima'] ?? ''); // El campo en el form será "nombre_materia_prima"
    $unidad_medida = $_POST['unidad_medida'] ?? '';
    $stock_actual = $_POST['stock_actual'] ?? 0;
    $stock_minimo = $_POST['stock_minimo'] ?? 0;
    $precio_unitario = $_POST['precio_unitario'] ?? 0;

    // Validación básica de los datos (adaptada de depositos.php)
    if (empty($nombre_materia_prima) || empty($unidad_medida) || !is_numeric($stock_actual) || !is_numeric($stock_minimo) || !is_numeric($precio_unitario)) {
        $mensaje = "Error: Todos los campos son obligatorios y deben ser numéricos donde corresponda.";
        $tipo_mensaje = 'danger';
    } elseif ($stock_actual < 0 || $stock_minimo < 0 || $precio_unitario < 0) {
        $mensaje = "Error: Los valores de stock y precio no pueden ser negativos.";
        $tipo_mensaje = 'danger';
    } else {
        try {
            if ($id_materia_prima_form) {
                // Modo EDICIÓN
                $stmt = $pdo->prepare("UPDATE materias_primas SET nombre_materia_prima = ?, unidad_medida = ?, stock_actual = ?, stock_minimo = ?, precio_unitario = ? WHERE id_materia_prima = ?");
                $stmt->execute([$nombre_materia_prima, $unidad_medida, $stock_actual, $stock_minimo, $precio_unitario, $id_materia_prima_form]);
                $mensaje = "Materia prima actualizada correctamente.";
                $tipo_mensaje = 'success';
            } else {
                // Modo AGREGAR
                $stmt = $pdo->prepare("INSERT INTO materias_primas (nombre_materia_prima, unidad_medida, stock_actual, stock_minimo, precio_unitario) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$nombre_materia_prima, $unidad_medida, $stock_actual, $stock_minimo, $precio_unitario]);
                $mensaje = "Materia prima añadida correctamente.";
                $tipo_mensaje = 'success';
            }
            // Redirigir para limpiar el formulario y mostrar el mensaje
            header("Location: materias_primas.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
            exit();

        } catch (PDOException $e) {
            $mensaje = "Error al guardar la materia prima: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// --- Lógica para ELIMINAR materia prima ---
// Esta sección se mantiene en el PHP por si se quisiera reactivar la funcionalidad
// o si hay alguna otra parte del sistema que la invoca.
// El botón se ha eliminado del HTML.
if (isset($_GET['action']) && $_GET['action'] == 'eliminar' && isset($_GET['id'])) {
    $id_materia_prima_eliminar = $_GET['id'];
    try {
        // Podrías añadir una lógica aquí para verificar si la materia prima está en uso
        // antes de eliminarla (por ejemplo, en un depósito o producto).
        // Por simplicidad, por ahora solo elimina.

        $stmt = $pdo->prepare("DELETE FROM materias_primas WHERE id_materia_prima = ?");
        $stmt->execute([$id_materia_prima_eliminar]);
        $mensaje = "Materia prima eliminada correctamente.";
        $tipo_mensaje = 'success';

        // Redirigir para limpiar URL y mostrar mensaje
        header("Location: materias_primas.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
        exit();

    } catch (PDOException $e) {
        $mensaje = "Error al eliminar la materia prima: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// Cargar mensajes de la redirección
if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $tipo_mensaje = $_GET['tipo_mensaje'];
}

// --- Lógica para Cargar la lista de Materias Primas y calcular totales ---
$materias_primas = [];
$total_stock_actual_kg = 0;
$total_stock_actual_litros = 0; // Nuevo total en litros

try {
    $stmt_materias = $pdo->query("SELECT id_materia_prima, nombre_materia_prima, unidad_medida, stock_actual, stock_minimo, precio_unitario FROM materias_primas ORDER BY id_materia_prima DESC");
    $materias_primas = $stmt_materias->fetchAll(PDO::FETCH_ASSOC);

    // Calcular totales de stock
    foreach ($materias_primas as $mp) {
        $total_stock_actual_kg += (float)$mp['stock_actual'];
    }
    // Convertir el total de KG a litros usando la densidad
    // Asegurarse de que OLIVE_OIL_DENSITY_KG_PER_LITER no sea cero para evitar división por cero
    if (OLIVE_OIL_DENSITY_KG_PER_LITER > 0) {
        $total_stock_actual_litros = $total_stock_actual_kg / OLIVE_OIL_DENSITY_KG_PER_LITER;
    } else {
        $total_stock_actual_litros = 0; // O manejar el error de otra forma
    }


} catch (PDOException $e) {
    $mensaje = "Error al cargar el listado de materias primas: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// Pre-llenar el formulario para edición si se pasó un ID por GET
$mp_a_editar = [
    'id_materia_prima' => '',
    'nombre_materia_prima' => '',
    'unidad_medida' => '',
    'stock_actual' => '',
    'stock_minimo' => '',
    'precio_unitario' => ''
];

if (isset($_GET['action']) && $_GET['action'] === 'editar_form' && isset($_GET['id'])) {
    $id_editar = $_GET['id'];
    $stmt_edit = $pdo->prepare("SELECT id_materia_prima, nombre_materia_prima, unidad_medida, stock_actual, stock_minimo, precio_unitario FROM materias_primas WHERE id_materia_prima = ?");
    $stmt_edit->execute([$id_editar]);
    $mp_a_editar = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if (!$mp_a_editar) {
        // Si no se encuentra, inicializar de nuevo y mostrar error
        $mp_a_editar = [
            'id_materia_prima' => '',
            'nombre_materia_prima' => '',
            'unidad_medida' => '',
            'stock_actual' => '',
            'stock_minimo' => '',
            'precio_unitario' => ''
        ];
        $mensaje = "Error: Materia prima no encontrada para edición.";
        $tipo_mensaje = 'danger';
    }
}

// Cierra la conexión PDO al final del script
$pdo = null; // Se cierra aquí para asegurar que las operaciones de arriba tengan conexión.
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Materias Primas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Variables globales para la consistencia del diseño */
        :root {
            --primary-color: #0056b3; /* Azul primario, consistente con otros archivos */
            --primary-dark: #004494;
            --secondary-color: #28a745; /* Verde para énfasis */
            --text-dark: #333;
            --text-light: #666;
            --bg-light: #f4f7f6; /* Fondo claro */
            --sidebar-bg: #343a40; /* Gris oscuro para el sidebar */
            --sidebar-link: #adb5bd; /* Gris claro para enlaces del sidebar */
            --sidebar-hover: #495057; /* Color de fondo hover para sidebar */
            --sidebar-active: #004494; /* Azul más oscuro para el elemento activo */
            --header-bg: #ffffff; /* Fondo blanco para el header */
            --shadow-light: rgba(0, 0, 0, 0.05);
            --shadow-medium: rgba(0, 0, 0, 0.1);

            /* Variables para el diseño del sidebar y contenido principal */
            --sidebar-width: 250px; /* Ancho del sidebar con texto */
            /* Eliminado --content-max-width para permitir que el contenedor principal ocupe el 100% */
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

        /* Estilos del Sidebar (adaptados de sidebar.php) */
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

        /* Contenido Principal */
        .main-content {
            margin-left: var(--sidebar-width); /* Espacio para el sidebar fijo y ancho */
            flex-grow: 1; /* Ocupa el espacio restante */
            padding: 30px;
            transition: margin-left 0.3s ease;
            background-color: var(--bg-light);
            width: calc(100% - var(--sidebar-width)); /* Ancho restante del contenido */
            min-height: 100vh; /* Asegura que el contenido principal también ocupe toda la altura */
        }

        /* Estilos del contenedor principal dentro del main-content */
        .container {
            background-color: var(--header-bg); /* Fondo blanco para el contenedor */
            padding: 40px;
            border-radius: 15px; /* Bordes más redondeados */
            box-shadow: 0 4px 8px var(--shadow-light); /* Sombra suave */
            margin-bottom: 40px;
            max-width: 100%; /* El contenedor ahora ocupa el 100% del ancho disponible de su padre */
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
            background-color: #0056b3; /* Ajustado a color primario de informe_cobros_diarios.php */
            border-color: #0056b3; /* Ajustado a color primario de informe_cobros_diarios.php */
            font-weight: 600;
            padding: 12px 25px;
            border-radius: 8px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #004494; /* Ajustado a color primario de informe_cobros_diarios.php */
            border-color: #004494; /* Ajustado a color primario de informe_cobros_diarios.php */
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

        .card {
            margin-bottom: 20px;
            border-radius: 15px; /* Ajustado a 15px para consistencia */
            box-shadow: 0 4px 8px rgba(0,0,0,0.05); /* Sombra más suave */
            margin-left: auto;
            margin-right: auto;
        }
        /* Las tarjetas de sección ahora también usarán el ancho máximo del contenedor */
        .form-section-card {
            max-width: 100%; /* Ocupa el 100% del ancho del contenedor padre */
        }
        .table-section-card {
            max-width: 100%; /* Ocupa el 100% del ancho del contenedor padre */
        }

        .card-header {
            border-top-left-radius: 15px; /* Ajustado a 15px */
            border-top-right-radius: 15px; /* Ajustado a 15px */
            padding: 15px 20px;
            font-size: 1.25rem;
            font-weight: 600;
        }
        .card-header.bg-success {
             background-color: var(--primary-color) !important;
        }
        .card-header.bg-info {
            background-color: #17a2b8 !important; /* Color info de informe_cobros_diarios.php */
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
        }
        /* Alineación a la derecha para columnas numéricas en Materias Primas */
        .table th:nth-child(4), /* Stock Actual */
        .table td:nth-child(4),
        .table th:nth-child(5), /* Stock Mínimo */
        .table td:nth-child(5),
        .table th:nth-child(6), /* Precio Unitario */
        .table td:nth-child(6) {
            text-align: right;
        }

        /* Estilo para la fila de totales */
        .table tfoot tr {
            font-weight: bold;
            background-color: #cceeff; /* Ajustado a color de informe_cobros_diarios.php */
            border-top: 3px solid #004494; /* Ajustado a color de informe_cobros_diarios.php */
        }
        .table tfoot td {
            border-top: 2px solid var(--primary-dark); /* Borde superior más grueso */
        }

        .form-label-required::after {
            content: " *";
            color: red;
            margin-left: 3px;
        }

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
            .container, .card, .form-section-card, .table-section-card {
                padding: 20px;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php
    // Incluir el sidebar para la navegación (ruta relativa)
    // Asume que 'sidebar.php' está en la misma carpeta que 'materias_primas.php'
    include 'sidebar.php';
    ?>

    <div class="main-content">
        <div class="container">
            <h2 class="mb-4 text-center"><i class="bi bi-box-seam me-3"></i> Gestión de Materias Primas</h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <h3 class="mt-5 mb-3">Listado de Materias Primas</h3>
            <div class="card table-section-card"> <!-- Se añadió la clase table-section-card aquí -->
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Materias Primas Registradas</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($materias_primas)): ?>
                        <p class="text-center">No hay materias primas registradas todavía.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre</th>
                                        <th>Unidad de Medida</th>
                                        <th>Stock Actual (KG)</th>
                                        <th>Stock Mínimo</th>
                                        <th>Precio Unitario (€)</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($materias_primas as $mp): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($mp['id_materia_prima']); ?></td>
                                            <td><?php echo htmlspecialchars($mp['nombre_materia_prima']); ?></td>
                                            <td><?php echo htmlspecialchars($mp['unidad_medida']); ?></td>
                                            <td><?php echo number_format($mp['stock_actual'], 2, ',', '.'); ?></td>
                                            <td><?php echo number_format($mp['stock_minimo'], 2, ',', '.'); ?></td>
                                            <td><?php echo number_format($mp['precio_unitario'], 2, ',', '.'); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-warning btn-sm" onclick="editMateriaPrima(<?php echo htmlspecialchars(json_encode($mp)); ?>)"><i class="bi bi-pencil-square"></i> Editar</button>
                                                <!-- Botón de eliminar eliminado según la solicitud -->
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" class="text-end"><strong>Total Stock Actual:</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_stock_actual_kg, 2, ',', '.'); ?> KG</strong></td>
                                        <td colspan="1"></td>
                                        <td class="text-end"><strong><?php echo number_format($total_stock_actual_litros, 2, ',', '.'); ?> L</strong></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <h3 class="mt-5 mb-3">Añadir/Editar Materia Prima</h3>
            <div class="card form-section-card"> <!-- Se añadió la clase form-section-card aquí -->
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Formulario de Materia Prima</h5>
                </div>
                <div class="card-body">
                    <form action="materias_primas.php" method="POST">
                        <input type="hidden" name="id_materia_prima" id="id_materia_prima_form" value="<?php echo htmlspecialchars($mp_a_editar['id_materia_prima']); ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nombre_materia_prima" class="form-label form-label-required">Nombre de la Materia Prima</label>
                                <input type="text" class="form-control" id="nombre_materia_prima" name="nombre_materia_prima" value="<?php echo htmlspecialchars($mp_a_editar['nombre_materia_prima']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="unidad_medida" class="form-label form-label-required">Unidad de Medida</label>
                                <input type="text" class="form-control" id="unidad_medida" name="unidad_medida" value="<?php echo htmlspecialchars($mp_a_editar['unidad_medida']); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="stock_actual" class="form-label form-label-required">Stock Actual</label>
                                <input type="number" step="0.01" class="form-control" id="stock_actual" name="stock_actual" value="<?php echo htmlspecialchars($mp_a_editar['stock_actual']); ?>" min="0" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="stock_minimo" class="form-label form-label-required">Stock Mínimo</label>
                                <input type="number" step="0.01" class="form-control" id="stock_minimo" name="stock_minimo" value="<?php echo htmlspecialchars($mp_a_editar['stock_minimo']); ?>" min="0" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="precio_unitario" class="form-label form-label-required">Precio Unitario (€)</label>
                                <input type="number" step="0.01" class="form-control" id="precio_unitario" name="precio_unitario" value="<?php echo htmlspecialchars($mp_a_editar['precio_unitario']); ?>" min="0" required>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary me-2"><i class="bi bi-save"></i> Guardar Materia Prima</button>
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
        // Función para cargar los datos de una materia prima en el formulario para edición
        function editMateriaPrima(mp) {
            document.getElementById('id_materia_prima_form').value = mp.id_materia_prima;
            document.getElementById('nombre_materia_prima').value = mp.nombre_materia_prima;
            document.getElementById('unidad_medida').value = mp.unidad_medida;
            document.getElementById('stock_actual').value = parseFloat(mp.stock_actual).toFixed(2);
            document.getElementById('stock_minimo').value = parseFloat(mp.stock_minimo).toFixed(2);
            document.getElementById('precio_unitario').value = parseFloat(mp.precio_unitario).toFixed(2);

            window.scrollTo(0, 0); // Desplazarse al principio de la página para ver el formulario
        }

        // Función para limpiar el formulario
        function resetForm() {
            document.getElementById('id_materia_prima_form').value = '';
            document.getElementById('nombre_materia_prima').value = '';
            document.getElementById('unidad_medida').value = '';
            document.getElementById('stock_actual').value = '';
            document.getElementById('stock_minimo').value = '';
            document.getElementById('precio_unitario').value = '';
        }
    </script>
</body>
</html>
