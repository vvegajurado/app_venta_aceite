<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Iniciar sesión para gestionar mensajes
session_start();

// Inicializar variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// --- Cargar datos de apoyo (Artículos para el selector) ---
$articulos_disponibles = [];
try {
    $stmt_articulos_disponibles = $pdo->query("SELECT id_articulo, nombre_articulo FROM articulos ORDER BY nombre_articulo ASC");
    $articulos_disponibles = $stmt_articulos_disponibles->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = "Error al cargar los artículos disponibles: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// --- Lógica para procesar el formulario de añadir/editar producto ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recogemos los datos del formulario.
    $id_producto_form = $_POST['id_producto'] ?? null;
    $nombre_producto = trim($_POST['nombre_producto'] ?? '');
    $id_articulo = $_POST['id_articulo'] ?? null;
    $litros_por_unidad = $_POST['litros_por_unidad'] ?? 0;
    $precio_venta = $_POST['precio_venta'] ?? 0;
    $action = $_POST['action'] ?? ''; // Capturar la acción específica

    // Lógica para poner stock a cero
    if ($action === 'reset_stock' && $id_producto_form) {
        try {
            $stmt = $pdo->prepare("UPDATE productos SET stock_actual_unidades = 0 WHERE id_producto = ?");
            $stmt->execute([$id_producto_form]);
            $mensaje = "Stock del producto reseteado a cero correctamente.";
            $tipo_mensaje = 'success';
            header("Location: productos.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
            exit();
        } catch (PDOException $e) {
            $mensaje = "Error al resetear el stock: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    } else {
        // Validación básica de los datos para añadir/editar
        if (empty($nombre_producto) || empty($id_articulo) || !is_numeric($litros_por_unidad) || !is_numeric($precio_venta)) {
            $mensaje = "Error: Todos los campos son obligatorios y los valores numéricos deben ser válidos.";
            $tipo_mensaje = 'danger';
        } elseif ($litros_por_unidad <= 0 || $precio_venta < 0) {
            $mensaje = "Error: Los litros por unidad deben ser mayores que cero y el precio de venta no puede ser negativo.";
            $tipo_mensaje = 'danger';
        } else {
            try {
                if ($id_producto_form) {
                    // Modo EDICIÓN
                    $stmt = $pdo->prepare("UPDATE productos SET nombre_producto = ?, id_articulo = ?, litros_por_unidad = ?, precio_venta = ? WHERE id_producto = ?");
                    $stmt->execute([$nombre_producto, $id_articulo, $litros_por_unidad, $precio_venta, $id_producto_form]);
                    $mensaje = "Producto actualizado correctamente.";
                    $tipo_mensaje = 'success';
                } else {
                    // Modo AGREGAR
                    $stmt = $pdo->prepare("INSERT INTO productos (nombre_producto, id_articulo, litros_por_unidad, precio_venta) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nombre_producto, $id_articulo, $litros_por_unidad, $precio_venta]);
                    $mensaje = "Producto añadido correctamente.";
                    $tipo_mensaje = 'success';
                }
                // Redirigir para limpiar el formulario y mostrar el mensaje
                header("Location: productos.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
                exit();

            } catch (PDOException $e) {
                $mensaje = "Error al guardar el producto: " . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    }
}

// --- Lógica para ELIMINAR producto ---
// Esta sección se mantiene en el PHP por si se quisiera reactivar la funcionalidad
// o si hay alguna otra parte del sistema que la invoca.
// El botón se ha eliminado del HTML.
if (isset($_GET['action']) && $_GET['action'] == 'eliminar' && isset($_GET['id'])) {
    $id_producto_eliminar = $_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM productos WHERE id_producto = ?");
        $stmt->execute([$id_producto_eliminar]);
        $mensaje = "Producto eliminado correctamente.";
        $tipo_mensaje = 'success';

        header("Location: productos.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
        exit();

    } catch (PDOException $e) {
        if ($e->getCode() == '23000') { // SQLSTATE para violación de integridad
            $mensaje = "Error: No se puede eliminar el producto porque está asociado a una actividad de envasado. Elimine primero los registros asociados.";
        } else {
            $mensaje = "Error al eliminar el producto: " . $e->getMessage();
        }
        $tipo_mensaje = 'danger';
    }
}

// Cargar mensajes de la redirección
if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $tipo_mensaje = $_GET['tipo_mensaje'];
}

// --- Lógica para Cargar la lista de Productos y calcular totales ---
$productos = [];
$total_stock_unidades_general = 0; // Para el total general de unidades
$total_stock_litros_general = 0;   // Para el total general de litros

try {
    // Modificar la consulta para incluir stock_actual_unidades
    $stmt_productos = $pdo->query("SELECT p.id_producto, p.nombre_producto, p.id_articulo, a.nombre_articulo AS nombre_articulo_asociado, p.litros_por_unidad, p.precio_venta, p.stock_actual_unidades
                                 FROM productos p JOIN articulos a ON p.id_articulo = a.id_articulo
                                 ORDER BY p.id_producto DESC");
    $productos = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);

    // Calcular stock litros totales para cada producto y los totales generales
    foreach ($productos as &$producto) { // Usar & para modificar el array directamente
        $producto['stock_litros_totales'] = $producto['stock_actual_unidades'] * $producto['litros_por_unidad'];
        $total_stock_unidades_general += $producto['stock_actual_unidades'];
        $total_stock_litros_general += $producto['stock_litros_totales'];
    }
    unset($producto); // Romper la referencia del último elemento

} catch (PDOException $e) {
    $mensaje = "Error al cargar el listado de productos: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// Pre-llenar el formulario para edición si se pasó un ID por GET
$producto_a_editar = [
    'id_producto' => '',
    'nombre_producto' => '',
    'id_articulo' => '',
    'litros_por_unidad' => '',
    'precio_venta' => '',
    'stock_actual_unidades' => '' // Añadir stock_actual_unidades para la edición
];

if (isset($_GET['action']) && $_GET['action'] === 'editar_form' && isset($_GET['id'])) {
    $id_editar = $_GET['id'];
    // Modificar la consulta para incluir stock_actual_unidades
    $stmt_edit = $pdo->prepare("SELECT id_producto, nombre_producto, id_articulo, litros_por_unidad, precio_venta, stock_actual_unidades FROM productos WHERE id_producto = ?");
    $stmt_edit->execute([$id_editar]);
    $producto_a_editar = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if (!$producto_a_editar) {
        $producto_a_editar = [
            'id_producto' => '',
            'nombre_producto' => '',
            'id_articulo' => '',
            'litros_por_unidad' => '',
            'precio_venta' => '',
            'stock_actual_unidades' => ''
        ];
        $mensaje = "Error: Producto no encontrado para edición.";
        $tipo_mensaje = 'danger';
    }
}

// Cierra la conexión PDO al final del script
$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Productos</title>
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

        /* Alineación a la derecha para columnas numéricas en Productos */
        .table th:nth-child(4), /* Litros por Unidad */
        .table td:nth-child(4),
        .table th:nth-child(5), /* Precio Venta */
        .table td:nth-child(5),
        .table th:nth-child(6), /* Stock Unidades */
        .table td:nth-child(6),
        .table th:nth-child(7), /* Stock Litros */
        .table td:nth-child(7) {
            text-align: right;
        }

        /* Estilo para la fila de totales (eliminada del HTML, pero se mantiene el estilo si se añade de nuevo) */
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
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <h2 class="mb-4 text-center"><i class="bi bi-box-fill me-3"></i> Gestión de Productos</h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <h3 class="mt-5 mb-3">Listado de Productos</h3>
            <div class="card table-section-card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Productos Registrados</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($productos)): ?>
                        <p class="text-center">No hay productos registrados todavía.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre Producto</th>
                                        <th>Artículo Asociado</th>
                                        <th class="text-end">Litros por Unidad</th>
                                        <th class="text-end">Precio Venta (€)</th>
                                        <th class="text-end">Stock Actual (Unidades)</th> <!-- Nueva columna -->
                                        <th class="text-end">Stock Total (Litros)</th> <!-- Nueva columna -->
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($productos as $producto): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($producto['id_producto']); ?></td>
                                            <td><?php echo htmlspecialchars($producto['nombre_producto']); ?></td>
                                            <td><?php echo htmlspecialchars($producto['nombre_articulo_asociado']); ?></td>
                                            <td class="text-end"><?php echo number_format($producto['litros_por_unidad'], 2, ',', '.'); ?> L</td>
                                            <td class="text-end"><?php echo number_format($producto['precio_venta'], 2, ',', '.'); ?></td>
                                            <td class="text-end"><?php echo number_format($producto['stock_actual_unidades'], 0, ',', '.'); ?></td> <!-- Mostrar unidades sin decimales -->
                                            <td class="text-end"><?php echo number_format($producto['stock_litros_totales'], 2, ',', '.'); ?> L</td> <!-- Mostrar litros con 2 decimales -->
                                            <td>
                                                <button type="button" class="btn btn-warning btn-sm" onclick="editProducto(<?php echo htmlspecialchars(json_encode($producto)); ?>)"><i class="bi bi-pencil-square"></i> Editar</button>
                                                <!-- Botón de eliminar eliminado según la solicitud -->
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="5" class="text-end"><strong>Total General:</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_stock_unidades_general, 0, ',', '.'); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_stock_litros_general, 2, ',', '.'); ?> L</strong></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <h3 class="mt-5 mb-3">Añadir/Editar Producto</h3>
            <div class="card form-section-card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Formulario de Producto</h5>
                </div>
                <div class="card-body">
                    <form action="productos.php" method="POST">
                        <input type="hidden" name="id_producto" id="id_producto_form" value="<?php echo htmlspecialchars($producto_a_editar['id_producto']); ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nombre_producto" class="form-label form-label-required">Nombre del Producto</label>
                                <input type="text" class="form-control" id="nombre_producto" name="nombre_producto" value="<?php echo htmlspecialchars($producto_a_editar['nombre_producto']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="id_articulo" class="form-label form-label-required">Artículo Asociado</label>
                                <select class="form-select" id="id_articulo" name="id_articulo" required>
                                    <option value="">Seleccione un Artículo</option>
                                    <?php foreach ($articulos_disponibles as $articulo): ?>
                                        <option value="<?php echo htmlspecialchars($articulo['id_articulo']); ?>"
                                            <?php echo ($articulo['id_articulo'] == $producto_a_editar['id_articulo']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($articulo['nombre_articulo']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="litros_por_unidad" class="form-label form-label-required">Litros por Unidad</label>
                                <input type="number" step="0.01" class="form-control" id="litros_por_unidad" name="litros_por_unidad" value="<?php echo htmlspecialchars($producto_a_editar['litros_por_unidad']); ?>" min="0.01" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="precio_venta" class="form-label form-label-required">Precio de Venta (€)</label>
                                <input type="number" step="0.01" class="form-control" id="precio_venta" name="precio_venta" value="<?php echo htmlspecialchars($producto_a_editar['precio_venta']); ?>" min="0" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="stock_actual_unidades_form" class="form-label">Stock Actual (Unidades)</label>
                                <input type="text" class="form-control" id="stock_actual_unidades_form" value="<?php echo htmlspecialchars($producto_a_editar['stock_actual_unidades']); ?>" readonly disabled>
                                <small class="form-text text-muted">Este campo es informativo y no se edita directamente.</small>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary me-2"><i class="bi bi-save"></i> Guardar Producto</button>
                                <button type="button" class="btn btn-secondary" onclick="resetForm()"><i class="bi bi-arrow-counterclockwise"></i> Limpiar</button>
                                <!-- Nuevo botón para poner stock a cero -->
                                <button type="button" class="btn btn-danger ms-2" id="resetStockBtn" style="<?php echo empty($producto_a_editar['id_producto']) ? 'display: none;' : ''; ?>" onclick="confirmResetStock()"><i class="bi bi-x-circle"></i> Poner Stock a Cero</button>
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
        // Función para cargar los datos de un producto en el formulario para edición
        function editProducto(producto) {
            document.getElementById('id_producto_form').value = producto.id_producto;
            document.getElementById('nombre_producto').value = producto.nombre_producto;
            document.getElementById('id_articulo').value = producto.id_articulo;
            document.getElementById('litros_por_unidad').value = parseFloat(producto.litros_por_unidad).toFixed(2);
            document.getElementById('precio_venta').value = parseFloat(producto.precio_venta).toFixed(2);
            // Cargar stock_actual_unidades en el campo correspondiente
            document.getElementById('stock_actual_unidades_form').value = producto.stock_actual_unidades;

            // Mostrar el botón "Poner Stock a Cero" cuando se edita un producto
            document.getElementById('resetStockBtn').style.display = 'inline-block';

            window.scrollTo(0, 0); // Desplazarse al principio de la página para ver el formulario
        }

        // Función para limpiar el formulario
        function resetForm() {
            document.getElementById('id_producto_form').value = '';
            document.getElementById('nombre_producto').value = '';
            document.getElementById('id_articulo').value = ''; // Resetea el select
            document.getElementById('litros_por_unidad').value = '';
            document.getElementById('precio_venta').value = '';
            document.getElementById('stock_actual_unidades_form').value = ''; // Limpiar también el campo de stock

            // Ocultar el botón "Poner Stock a Cero" al limpiar el formulario
            document.getElementById('resetStockBtn').style.display = 'none';
        }

        // Función para confirmar y resetear el stock
        function confirmResetStock() {
            const productId = document.getElementById('id_producto_form').value;
            const productName = document.getElementById('nombre_producto').value;

            if (confirm(`¿Está seguro de que desea poner el stock de "${productName}" a cero? Esta acción es irreversible.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'productos.php';

                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'id_producto';
                inputId.value = productId;
                form.appendChild(inputId);

                const inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'action';
                inputAction.value = 'reset_stock';
                form.appendChild(inputAction);

                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
