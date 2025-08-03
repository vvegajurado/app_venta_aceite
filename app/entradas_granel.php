<?php
// Incluir el archivo de verificación de autenticación AL PRINCIPIO para asegurar que la sesión se inicie primero.
include 'auth_check.php';

// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Constante para la densidad del aceite de oliva (ej. 0.916 kg/L)
const OLIVE_OIL_DENSITY_KG_PER_LITER = 0.916; 

// Directorio donde se guardarán los albaranes PDF
const UPLOAD_DIR = 'uploads/albaranes/'; 

// Inicializar variables
$mensaje = '';
$tipo_mensaje = '';
$depositos_disponibles = [];
$proveedores = [];
$materias_primas_disponibles = [];
$entradas_granel_map = [];
$entrada_a_mostrar = null;

// --- OBTENER DATOS PARA LOS SELECTS ---
try {
    // Asegurarse de seleccionar todos los campos necesarios para la lógica de stock y mezcla
    $stmt_depositos = $pdo->query("SELECT id_deposito, nombre_deposito, capacidad, stock_actual, id_materia_prima, id_entrada_granel_origen, estado FROM depositos ORDER BY nombre_deposito ASC");
    $depositos_disponibles = $stmt_depositos->fetchAll(PDO::FETCH_ASSOC);

    $stmt_prov = $pdo->query("SELECT id_proveedor, nombre_proveedor FROM proveedores ORDER BY nombre_proveedor ASC");
    $proveedores = $stmt_prov->fetchAll(PDO::FETCH_ASSOC);

    $stmt_mp = $pdo->query("SELECT id_materia_prima, nombre_materia_prima, unidad_medida FROM materias_primas ORDER BY nombre_materia_prima ASC");
    $materias_primas_disponibles = $stmt_mp->fetchAll(PDO::FETCH_ASSOC);

    $stmt_entradas_map = $pdo->query("SELECT id_entrada_granel, numero_lote_proveedor FROM entradas_granel");
    while ($row = $stmt_entradas_map->fetch(PDO::FETCH_ASSOC)) {
        $entradas_granel_map[$row['id_entrada_granel']] = $row['numero_lote_proveedor'];
    }

} catch (PDOException $e) {
    $mensaje = "Error al cargar datos para los formularios: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// --- Lógica para PROCESAR el formulario de Entrada a Granel ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'agregar_entrada') {
    $fecha_entrada = $_POST['fecha_entrada'] ?? '';
    $id_proveedor = $_POST['id_proveedor'] ?? null;
    $numero_lote_proveedor = $_POST['numero_lote_proveedor'] ?? '';
    $id_materia_prima = $_POST['id_materia_prima'] ?? null;
    $kg_recibidos = $_POST['kg_recibidos'] ?? 0;
    $observaciones = $_POST['observaciones'] ?? '';
    $ruta_albaran_pdf = null;

    $depositos_destino = $_POST['deposito_destino'] ?? [];
    $litros_descargados = $_POST['litros_descargados'] ?? [];

    if (empty($fecha_entrada) || empty($numero_lote_proveedor) || empty($id_materia_prima) || !is_numeric($kg_recibidos) || $kg_recibidos <= 0) {
        $mensaje = "Error: Por favor, complete todos los campos obligatorios de la entrada y asegúrese de que los valores numéricos sean válidos.";
        $tipo_mensaje = 'danger';
    } elseif (empty($depositos_destino) || !is_array($depositos_destino) || count($depositos_destino) == 0) {
        $mensaje = "Error: Debe seleccionar al menos un depósito de destino para la entrada.";
        $tipo_mensaje = 'danger';
    } else {
        try {
            $pdo->beginTransaction();

            if (isset($_FILES['albaran_pdf']) && $_FILES['albaran_pdf']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_path = $_FILES['albaran_pdf']['tmp_name'];
                $file_name = $_FILES['albaran_pdf']['name'];
                $file_size = $_FILES['albaran_pdf']['size'];
                $file_type = $_FILES['albaran_pdf']['type'];
                $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if ($file_extension !== 'pdf' || $file_type !== 'application/pdf') {
                    throw new Exception("Error: El archivo debe ser un documento PDF.");
                }
                if ($file_size > 5 * 1024 * 1024) { 
                    throw new Exception("Error: El tamaño del archivo no debe exceder los 5MB.");
                }

                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0777, true);
                }

                $new_file_name = uniqid('albaran_', true) . '.' . $file_extension;
                $upload_path = UPLOAD_DIR . $new_file_name;

                if (move_uploaded_file($file_tmp_path, $upload_path)) {
                    $ruta_albaran_pdf = $upload_path;
                } else {
                    throw new Exception("Error al mover el archivo subido. Verifique los permisos del servidor.");
                }
            }

            $litros_total_entrada = $kg_recibidos / OLIVE_OIL_DENSITY_KG_PER_LITER;
            $litros_distribuidos = 0;

            $stmt_entrada = $pdo->prepare("INSERT INTO entradas_granel (fecha_entrada, id_proveedor, numero_lote_proveedor, id_materia_prima, kg_recibidos, litros_equivalentes, observaciones, ruta_albaran_pdf) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_entrada->execute([$fecha_entrada, $id_proveedor, $numero_lote_proveedor, $id_materia_prima, $kg_recibidos, $litros_total_entrada, $observaciones, $ruta_albaran_pdf]);
            $id_nueva_entrada = $pdo->lastInsertId();

            foreach ($depositos_destino as $index => $id_deposito) {
                $litros_a_descargar = (float)($litros_descargados[$index] ?? 0);

                if ($litros_a_descargar <= 0) {
                    continue;
                }

                $litros_distribuidos += $litros_a_descargar;

                $stmt_deposito = $pdo->prepare("SELECT nombre_deposito, capacidad, stock_actual, id_materia_prima, id_entrada_granel_origen FROM depositos WHERE id_deposito = ? FOR UPDATE");
                $stmt_deposito->execute([$id_deposito]);
                $deposito_seleccionado = $stmt_deposito->fetch(PDO::FETCH_ASSOC);

                if (!$deposito_seleccionado) {
                    throw new Exception("El depósito con ID {$id_deposito} no existe.");
                }

                $nuevo_stock_deposito = $deposito_seleccionado['stock_actual'] + $litros_a_descargar;

                if ($nuevo_stock_deposito > $deposito_seleccionado['capacidad']) {
                    throw new Exception("Error: La cantidad de {$litros_a_descargar} litros excede la capacidad del depósito '{$deposito_seleccionado['nombre_deposito']}'.");
                }

                if ($deposito_seleccionado['stock_actual'] > 0) {
                    if ($deposito_seleccionado['id_materia_prima'] !== null && (int)$deposito_seleccionado['id_materia_prima'] !== (int)$id_materia_prima) {
                         throw new Exception("Error: El depósito '{$deposito_seleccionado['nombre_deposito']}' ya contiene otra materia prima.");
                    }
                    
                    if ($deposito_seleccionado['id_entrada_granel_origen'] !== null) {
                        $stmt_lote_actual_id = $pdo->prepare("SELECT numero_lote_proveedor FROM entradas_granel WHERE id_entrada_granel = ?");
                        $stmt_lote_actual_id->execute([$deposito_seleccionado['id_entrada_granel_origen']]);
                        $lote_actual_deposito = $stmt_lote_actual_id->fetchColumn();

                        if ($lote_actual_deposito !== $numero_lote_proveedor) {
                             throw new Exception("Error: El depósito '{$deposito_seleccionado['nombre_deposito']}' ya contiene aceite del lote '{$lote_actual_deposito}'. No se puede mezclar.");
                        }
                    }
                }

                $nuevo_estado_deposito = ($nuevo_stock_deposito >= $deposito_seleccionado['capacidad']) ? 'lleno' : 'ocupado';
                
                $stmt_update_deposito = $pdo->prepare("UPDATE depositos SET stock_actual = ?, id_materia_prima = ?, id_entrada_granel_origen = ?, estado = ? WHERE id_deposito = ?");
                $stmt_update_deposito->execute([$nuevo_stock_deposito, $id_materia_prima, $id_nueva_entrada, $nuevo_estado_deposito, $id_deposito]);

                $stmt_detalle = $pdo->prepare("INSERT INTO detalle_entrada_granel (id_entrada_granel, id_deposito, litros_descargados) VALUES (?, ?, ?)");
                $stmt_detalle->execute([$id_nueva_entrada, $id_deposito, $litros_a_descargar]);
            }

            if (abs($litros_total_entrada - $litros_distribuidos) > 0.01) {
                throw new Exception("Error: La cantidad total de litros recibidos ({$litros_total_entrada} L) no coincide con los litros distribuidos ({$litros_distribuidos} L).");
            }

            $stmt_update_mp_stock = $pdo->prepare("UPDATE materias_primas SET stock_actual = stock_actual + ? WHERE id_materia_prima = ?");
            $stmt_update_mp_stock->execute([$kg_recibidos, $id_materia_prima]);

            $pdo->commit();
            $mensaje = "Entrada a granel registrada y depósitos actualizados correctamente.";
            $tipo_mensaje = 'success';
            header("Location: entradas_granel.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "Error al procesar la entrada: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        } catch (PDOException $e) {
             $pdo->rollBack();
             $mensaje = "Error de base de datos al procesar la entrada: " . $e->getMessage();
             $tipo_mensaje = 'danger';
        }
    }
}

// --- Lógica para ACTUALIZAR el albarán ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'actualizar_albaran') {
    $id_entrada_a_actualizar = $_POST['id_entrada_granel_albaran'] ?? null;
    $ruta_albaran_pdf_actualizada = null;

    if (!$id_entrada_a_actualizar) {
        $mensaje = "Error: ID de entrada no proporcionado para actualizar el albarán.";
        $tipo_mensaje = 'danger';
    } else {
        try {
            $pdo->beginTransaction();

            if (isset($_FILES['albaran_pdf_actualizar']) && $_FILES['albaran_pdf_actualizar']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_path = $_FILES['albaran_pdf_actualizar']['tmp_name'];
                $file_name = $_FILES['albaran_pdf_actualizar']['name'];
                $file_size = $_FILES['albaran_pdf_actualizar']['size'];
                $file_type = $_FILES['albaran_pdf_actualizar']['type'];
                $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if ($file_extension !== 'pdf' || $file_type !== 'application/pdf') {
                    throw new Exception("Error: El archivo debe ser un documento PDF.");
                }
                if ($file_size > 5 * 1024 * 1024) { 
                    throw new Exception("Error: El tamaño del archivo no debe exceder los 5MB.");
                }

                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0777, true);
                }

                $new_file_name = uniqid('albaran_', true) . '.' . $file_extension;
                $upload_path = UPLOAD_DIR . $new_file_name;

                if (move_uploaded_file($file_tmp_path, $upload_path)) {
                    $ruta_albaran_pdf_actualizada = $upload_path;

                    $stmt_old_path = $pdo->prepare("SELECT ruta_albaran_pdf FROM entradas_granel WHERE id_entrada_granel = ?");
                    $stmt_old_path->execute([$id_entrada_a_actualizar]);
                    $old_path = $stmt_old_path->fetchColumn();

                    $stmt_update_albaran = $pdo->prepare("UPDATE entradas_granel SET ruta_albaran_pdf = ? WHERE id_entrada_granel = ?");
                    $stmt_update_albaran->execute([$ruta_albaran_pdf_actualizada, $id_entrada_a_actualizar]);

                    if ($old_path && file_exists($old_path) && $old_path != $ruta_albaran_pdf_actualizada) {
                        unlink($old_path);
                    }

                    $pdo->commit();
                    $mensaje = "Albarán PDF actualizado correctamente.";
                    $tipo_mensaje = 'success';
                } else {
                    throw new Exception("Error al mover el archivo subido.");
                }
            } else {
                throw new Exception("No se seleccionó ningún archivo PDF para actualizar.");
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "Error al actualizar el albarán: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        } catch (PDOException $e) {
            $pdo->rollBack();
            $mensaje = "Error de base de datos al actualizar el albarán: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
    header("Location: entradas_granel.php?view=details&id=" . urlencode($id_entrada_a_actualizar) . "&mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
    exit();
}

if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $tipo_mensaje = $_GET['tipo_mensaje'];
}

if (isset($_GET['view']) && $_GET['view'] == 'details' && isset($_GET['id'])) {
    $id_entrada_a_mostrar = $_GET['id'];
    try {
        $sql_entrada_detalles = "SELECT eg.id_entrada_granel, eg.fecha_entrada, p.nombre_proveedor, eg.numero_lote_proveedor, mp.nombre_materia_prima, eg.kg_recibidos, eg.litros_equivalentes, eg.observaciones, eg.ruta_albaran_pdf FROM entradas_granel eg LEFT JOIN proveedores p ON eg.id_proveedor = p.id_proveedor LEFT JOIN materias_primas mp ON eg.id_materia_prima = mp.id_materia_prima WHERE eg.id_entrada_granel = ?";
        $stmt_entrada_detalles = $pdo->prepare($sql_entrada_detalles);
        $stmt_entrada_detalles->execute([$id_entrada_a_mostrar]);
        $entrada_a_mostrar = $stmt_entrada_detalles->fetch(PDO::FETCH_ASSOC);

        if ($entrada_a_mostrar) {
            $stmt_depositos_detalle = $pdo->prepare("SELECT d.nombre_deposito, deg.litros_descargados FROM detalle_entrada_granel deg JOIN depositos d ON deg.id_deposito = d.id_deposito WHERE deg.id_entrada_granel = ?");
            $stmt_depositos_detalle->execute([$id_entrada_a_mostrar]);
            $entrada_a_mostrar['depositos_destino'] = $stmt_depositos_detalle->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $mensaje = "Error: Entrada a granel no encontrada.";
            $tipo_mensaje = 'danger';
        }
    } catch (PDOException $e) {
        $mensaje = "Error al cargar los detalles de la entrada: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

$entradas_granel = [];
$total_kg_recibidos = 0;
$total_litros_equivalentes = 0;
$total_litros_descargados_general = 0;

if (!$entrada_a_mostrar) {
    try {
        $sql_entradas = "SELECT eg.id_entrada_granel, eg.fecha_entrada, p.nombre_proveedor, eg.numero_lote_proveedor, mp.nombre_materia_prima, eg.kg_recibidos, eg.litros_equivalentes, eg.observaciones, eg.ruta_albaran_pdf FROM entradas_granel eg LEFT JOIN proveedores p ON eg.id_proveedor = p.id_proveedor LEFT JOIN materias_primas mp ON eg.id_materia_prima = mp.id_materia_prima ORDER BY eg.id_entrada_granel DESC";
        $stmt_entradas = $pdo->query($sql_entradas);
        $entradas_granel = $stmt_entradas->fetchAll(PDO::FETCH_ASSOC);

        foreach ($entradas_granel as $key => $entrada) {
            $stmt_detalles_depositos = $pdo->prepare("SELECT d.nombre_deposito, deg.litros_descargados FROM detalle_entrada_granel deg JOIN depositos d ON deg.id_deposito = d.id_deposito WHERE deg.id_entrada_granel = ?");
            $stmt_detalles_depositos->execute([$entrada['id_entrada_granel']]);
            $entradas_granel[$key]['depositos_destino'] = $stmt_detalles_depositos->fetchAll(PDO::FETCH_ASSOC);

            $total_kg_recibidos += $entrada['kg_recibidos'];
            $total_litros_equivalentes += $entrada['litros_equivalentes'];
            foreach ($entradas_granel[$key]['depositos_destino'] as $dep_detalle) {
                $total_litros_descargados_general += $dep_detalle['litros_descargados'];
            }
        }
    } catch (PDOException $e) {
        $mensaje_listado = "Error al cargar el listado de entradas: " . $e->getMessage();
        $tipo_mensaje_listado = 'danger';
    }
}

$pdo = null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entradas a Granel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
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
            background-color: #17a2b8; /* Color info de Bootstrap */
            border-color: #17a2b8;
            color: #fff;
        }
        .btn-info:hover {
            background-color: #138496;
            border-color: #117a8b;
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
        .table th:nth-child(6), .table td:nth-child(6), /* Kilos */
        .table th:nth-child(7), .table td:nth-child(7) { /* Litros */
            text-align: right;
        }

        .table tfoot tr {
            font-weight: bold;
            background-color: #e9ecef;
        }
        .table tfoot td {
            border-top: 2px solid var(--primary-dark);
        }

        .form-label-required::after {
            content: " *";
            color: red;
            margin-left: 3px;
        }
        .deposito-row {
            display: flex;
            align-items: flex-end;
            margin-bottom: 15px;
            border: 1px solid #e0e0e0;
            padding: 15px;
            border-radius: 8px;
            background-color: #fcfdfe;
            box-shadow: 0 1px 5px rgba(0,0,0,0.05);
        }
        .deposito-row .col { padding-right: 15px; }
        .deposito-row .col:last-child { padding-right: 0; }
        .deposito-row:first-child .remove-deposito-row { display: none; } /* Ocultar botón de eliminar para la primera fila */
        
        .alert-info strong { font-size: 1.1em; }

        .detail-view-card .card-body div { margin-bottom: 15px; }
        .detail-view-card .card-body label { font-weight: 600; color: var(--primary-dark); display: block; margin-bottom: 5px; }
        .detail-view-card .card-body p { margin-bottom: 0; }
        .detail-deposit-list { list-style: none; padding-left: 0; margin-bottom: 0; }
        .detail-deposit-list li { background-color: #e9ecef; padding: 8px 12px; border-radius: 5px; margin-bottom: 5px; border: 1px solid #dee2e6; }

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
            .container, .card { /* Aplicar a todas las tarjetas en móvil */
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
            <h2 class="mb-4 text-center"><i class="bi bi-box-arrow-in-down-right me-3"></i> Registro de Entradas a Granel</h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($mensaje); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($mensaje_listado) && $mensaje_listado): ?>
                <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje_listado); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($mensaje_listado); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($entrada_a_mostrar): ?>
                <div class="card detail-view-card">
                    <div class="card-header bg-info">
                        <h5 class="mb-0">Detalles de la Entrada #<?php echo htmlspecialchars($entrada_a_mostrar['id_entrada_granel']); ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Fecha de Entrada:</label>
                                <p><?php echo htmlspecialchars(date('d/m/Y', strtotime($entrada_a_mostrar['fecha_entrada']))); ?></p>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Proveedor:</label>
                                <p><?php echo htmlspecialchars($entrada_a_mostrar['nombre_proveedor'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Lote de Proveedor:</label>
                                <p><?php echo htmlspecialchars($entrada_a_mostrar['numero_lote_proveedor']); ?></p>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Tipo de Materia Prima:</label>
                                <p><?php echo htmlspecialchars($entrada_a_mostrar['nombre_materia_prima'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Kilos Recibidos (kg):</label>
                                <p><?php echo number_format($entrada_a_mostrar['kg_recibidos'], 2, ',', '.'); ?> kg</p>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Litros Equivalentes (L):</label>
                                <p><?php echo number_format($entrada_a_mostrar['litros_equivalentes'], 2, ',', '.'); ?> L</p>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label>Observaciones:</label>
                                <p><?php echo htmlspecialchars($entrada_a_mostrar['observaciones'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label>Albarán:</label>
                                <?php if (!empty($entrada_a_mostrar['ruta_albaran_pdf'])): ?>
                                    <p><a href="<?php echo htmlspecialchars($entrada_a_mostrar['ruta_albaran_pdf']); ?>" target="_blank" class="btn btn-sm btn-info"><i class="bi bi-file-earmark-pdf-fill"></i> Ver Albarán PDF</a></p>
                                <?php else: ?>
                                    <p class="text-muted">No se adjuntó albarán.</p>
                                <?php endif; ?>
                                <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#albaranUploadModal">
                                    <i class="bi bi-upload"></i> Gestionar Albarán PDF
                                </button>
                            </div>
                        </div>
                        <hr>
                        <h4>Distribución en Depósitos</h4>
                        <?php if (!empty($entrada_a_mostrar['depositos_destino'])): ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($entrada_a_mostrar['depositos_destino'] as $dep_detalle): ?>
                                    <li><?php echo htmlspecialchars($dep_detalle['nombre_deposito']); ?>: <?php echo number_format($dep_detalle['litros_descargados'], 2, ',', '.') . " L"; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>No se registró distribución en depósitos para esta entrada.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-4 text-center">
                    <a href="entradas_granel.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle"></i> Volver al Listado</a>
                </div>

                <!-- Modal para subir/actualizar Albarán -->
                <div class="modal fade" id="albaranUploadModal" tabindex="-1" aria-labelledby="albaranUploadModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form action="entradas_granel.php" method="POST" enctype="multipart/form-data">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="albaranUploadModalLabel">Gestionar Albarán PDF</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="accion" value="actualizar_albaran">
                                    <input type="hidden" name="id_entrada_granel_albaran" value="<?php echo htmlspecialchars($entrada_a_mostrar['id_entrada_granel']); ?>">
                                    
                                    <div class="mb-3">
                                        <label for="albaran_pdf_actualizar" class="form-label">Seleccionar Albarán (PDF)</label>
                                        <input class="form-control" type="file" id="albaran_pdf_actualizar" name="albaran_pdf_actualizar" accept=".pdf" required>
                                        <div class="form-text">Suba un nuevo documento PDF del albarán (máx. 5MB).</div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Subir Albarán</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="card form-section-card">
                    <div class="card-header bg-success">
                        <h5 class="mb-0">Añadir Nueva Entrada</h5>
                    </div>
                    <div class="card-body">
                        <form action="entradas_granel.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="accion" value="agregar_entrada">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="fecha_entrada" class="form-label form-label-required">Fecha de Entrada</label>
                                    <input type="date" class="form-control" id="fecha_entrada" name="fecha_entrada" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="id_proveedor" class="form-label form-label-required">Proveedor</label>
                                    <select class="form-select" id="id_proveedor" name="id_proveedor" required>
                                        <option value="">Seleccione un Proveedor</option>
                                        <?php foreach ($proveedores as $proveedor): ?>
                                            <option value="<?php echo htmlspecialchars($proveedor['id_proveedor']); ?>"><?php echo htmlspecialchars($proveedor['nombre_proveedor']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="numero_lote_proveedor" class="form-label form-label-required">Lote de Proveedor</label>
                                    <input type="text" class="form-control" id="numero_lote_proveedor" name="numero_lote_proveedor" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="id_materia_prima" class="form-label form-label-required">Tipo de Materia Prima</label>
                                    <select class="form-select" id="id_materia_prima" name="id_materia_prima" required>
                                        <option value="">Seleccione Materia Prima</option>
                                        <?php foreach ($materias_primas_disponibles as $mp): ?>
                                            <option value="<?php echo htmlspecialchars($mp['id_materia_prima']); ?>"><?php echo htmlspecialchars($mp['nombre_materia_prima']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="kg_recibidos" class="form-label form-label-required">Kilos Recibidos (kg)</label>
                                    <input type="number" step="0.01" class="form-control" id="kg_recibidos" name="kg_recibidos" required min="0.01">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="observaciones" class="form-label">Observaciones</label>
                                    <textarea class="form-control" id="observaciones" name="observaciones" rows="1"></textarea>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="albaran_pdf" class="form-label">Albarán (PDF)</label>
                                    <input class="form-control" type="file" id="albaran_pdf" name="albaran_pdf" accept=".pdf">
                                    <div class="form-text">Suba el documento PDF del albarán (máx. 5MB).</div>
                                </div>
                            </div>

                            <hr>
                            <h4>Distribución en Depósitos</h4>
                            <div id="depositos-container">
                                <div class="deposito-row row align-items-end mb-3">
                                    <div class="col-md-7">
                                        <label for="deposito_destino_0" class="form-label">Depósito</label>
                                        <select class="form-select" id="deposito_destino_0" name="deposito_destino[]" required>
                                            <option value="">Seleccione un Depósito</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="litros_descargados_0" class="form-label">Litros a descargar</label>
                                        <input type="number" step="0.01" class="form-control litros-input" id="litros_descargados_0" name="litros_descargados[]" required min="0.01">
                                    </div>
                                    <div class="col-md-1 d-flex align-items-end">
                                        <button type="button" class="btn btn-danger remove-deposito-row w-100"><i class="bi bi-x-circle"></i></button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary mt-2" id="add-deposito-row"><i class="bi bi-plus-circle"></i> Añadir otro depósito</button>
                            
                            <div class="alert alert-info mt-3" role="alert">
                                Litros totales: <strong id="total-litros-entrada">0.00</strong> L | 
                                Distribuidos: <strong id="litros-distribuidos">0.00</strong> L | 
                                Restantes: <strong id="litros-restantes">0.00</strong> L
                            </div>

                            <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-plus-circle"></i> Registrar Entrada</button>
                        </form>
                    </div>
                </div>
                
                <h3 class="mt-5 mb-3 text-center">Listado de Entradas a Granel</h3>
                <div class="card table-section-card">
                    <div class="card-header bg-info">
                        <h5 class="mb-0">Entradas Recientes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($entradas_granel)): ?>
                            <p class="text-center">No hay entradas a granel registradas todavía.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Fecha</th>
                                            <th>Proveedor</th>
                                            <th>Lote</th>
                                            <th>Materia Prima</th>
                                            <th>Kilos (kg)</th>
                                            <th>Litros (L)</th>
                                            <th>Depósitos</th>
                                            <th>Observaciones</th>
                                            <th>Albarán</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($entradas_granel as $entrada): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($entrada['id_entrada_granel']); ?></td>
                                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($entrada['fecha_entrada']))); ?></td>
                                                <td><?php echo htmlspecialchars($entrada['nombre_proveedor'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($entrada['numero_lote_proveedor']); ?></td>
                                                <td><?php echo htmlspecialchars($entrada['nombre_materia_prima'] ?? 'N/A'); ?></td>
                                                <td class="text-end"><?php echo number_format($entrada['kg_recibidos'], 2, ',', '.'); ?></td>
                                                <td class="text-end"><?php echo number_format($entrada['litros_equivalentes'], 2, ',', '.'); ?></td>
                                                <td>
                                                    <?php if (!empty($entrada['depositos_destino'])): ?>
                                                        <ul class="list-unstyled mb-0">
                                                            <?php foreach ($entrada['depositos_destino'] as $dep_detalle): ?>
                                                                <li><?php echo htmlspecialchars($dep_detalle['nombre_deposito']); ?>: <?php echo number_format($dep_detalle['litros_descargados'], 2, ',', '.') . " L"; ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($entrada['observaciones']); ?></td>
                                                <td>
                                                    <?php if (!empty($entrada['ruta_albaran_pdf'])): ?>
                                                        <a href="<?php echo htmlspecialchars($entrada['ruta_albaran_pdf']); ?>" target="_blank" class="btn btn-sm btn-info"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="entradas_granel.php?view=details&id=<?php echo htmlspecialchars($entrada['id_entrada_granel']); ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="5"><strong>Total General</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_kg_recibidos, 2, ',', '.'); ?> kg</strong></td>
                                            <td class="text-end"><strong><?php echo number_format($total_litros_equivalentes, 2, ',', '.'); ?> L</strong></td>
                                            <td></td> <!-- Columna de depósitos, no se suma -->
                                            <td></td> <!-- Columna de observaciones, no se suma -->
                                            <td></td> <!-- Columna de albarán, no se suma -->
                                            <td></td> <!-- Columna de acciones, no se suma -->
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Solo inicializar la lógica del formulario si no estamos en la vista de detalles
            if (window.location.search.indexOf('view=details') === -1) {
                const depositosContainer = document.getElementById('depositos-container');
                const addDepositoRowBtn = document.getElementById('add-deposito-row');
                const kgRecibidosInput = document.getElementById('kg_recibidos');
                const totalLitrosEntradaSpan = document.getElementById('total-litros-entrada');
                const litrosDistribuidosSpan = document.getElementById('litros-distribuidos');
                const litrosRestantesSpan = document.getElementById('litros-restantes');
                const idMateriaPrimaSelect = document.getElementById('id_materia_prima');
                const numeroLoteProveedorInput = document.getElementById('numero_lote_proveedor');

                let depositoRowIndex = 0;

                // Store original immutable data from PHP
                const initialDepositosData = <?php echo json_encode($depositos_disponibles); ?>;
                const materiasPrimasData = <?php echo json_encode($materias_primas_disponibles); ?>;
                const entradasGranelMap = <?php echo json_encode($entradas_granel_map); ?>;
                const OLIVE_OIL_DENSITY_KG_PER_LITER = <?php echo OLIVE_OIL_DENSITY_KG_PER_LITER; ?>;

                // Helper functions
                function getMateriaPrimaName(id) {
                    const mp = materiasPrimasData.find(m => m.id_materia_prima == id);
                    return mp ? mp.nombre_materia_prima : 'N/A';
                }

                function getLoteName(id) {
                    return entradasGranelMap[id] || 'N/A';
                }

                // This function will hold the current effective state of deposits based on form inputs
                let currentDepositsEffectiveState = {};

                // Function to update the effective state of deposits and then refresh all dropdowns
                function updateDepositStatesAndDropdowns() {
                    // Reset effective state to initial state
                    currentDepositsEffectiveState = {};
                    initialDepositosData.forEach(dep => {
                        currentDepositsEffectiveState[dep.id_deposito] = { ...dep }; // Deep copy
                    });

                    // Apply liters from current form rows to the effective state
                    document.querySelectorAll('.deposito-row').forEach(row => {
                        const selectElement = row.querySelector('select[name="deposito_destino[]"]');
                        const litrosInput = row.querySelector('input[name="litros_descargados[]"]');
                        const selectedDepositoId = selectElement.value;
                        const litrosDescargados = parseFloat(litrosInput.value) || 0;

                        if (selectedDepositoId && currentDepositsEffectiveState[selectedDepositoId]) {
                            currentDepositsEffectiveState[selectedDepositoId].stock_actual += litrosDescargados;
                        }
                    });

                    // Update totals display
                    const kg = parseFloat(kgRecibidosInput.value) || 0;
                    const totalLitros = kg / OLIVE_OIL_DENSITY_KG_PER_LITER;
                    totalLitrosEntradaSpan.textContent = totalLitros.toFixed(2).replace('.', ',');

                    let distributedLitros = 0;
                    document.querySelectorAll('.litros-input').forEach(input => {
                        distributedLitros += parseFloat(input.value) || 0;
                    });
                    litrosDistribuidosSpan.textContent = distributedLitros.toFixed(2).replace('.', ',');

                    const remainingLitros = totalLitros - distributedLitros;
                    litrosRestantesSpan.textContent = remainingLitros.toFixed(2).replace('.', ',');
                    litrosRestantesSpan.parentElement.classList.toggle('text-danger', Math.abs(remainingLitros) > 0.01);
                    litrosRestantesSpan.parentElement.classList.toggle('text-success', Math.abs(remainingLitros) <= 0.01);

                    // Re-populate all deposit dropdowns based on the new effective state
                    document.querySelectorAll('select[name="deposito_destino[]"]').forEach(selectElement => {
                        populateDepositoSelect(selectElement);
                    });
                }

                // Function to populate a single deposit select dropdown
                function populateDepositoSelect(selectElement) {
                    const currentSelectedId = selectElement.value; // Store currently selected value
                    selectElement.innerHTML = '<option value="">Seleccione un Depósito</option>';

                    const selectedMateriaPrimaId = idMateriaPrimaSelect.value;
                    const currentLoteProveedor = numeroLoteProveedorInput.value;

                    initialDepositosData.forEach(dep => {
                        const effectiveDep = currentDepositsEffectiveState[dep.id_deposito] || dep; // Use effective state if available, else initial

                        const currentStock = effectiveDep.stock_actual;
                        const remainingCapacity = dep.capacidad - currentStock; // Calculate based on initial capacity and current effective stock

                        let canAdd = true;
                        let infoText = `[Cap: ${parseFloat(dep.capacidad).toFixed(2).replace('.',',')}L]`;

                        if (currentStock > 0) {
                            infoText += ` (Ocupado: ${parseFloat(currentStock).toFixed(2).replace('.',',')}L, MP: ${getMateriaPrimaName(dep.id_materia_prima)}, Lote: ${getLoteName(dep.id_entrada_granel_origen)})`;
                            
                            // Check for mixing rules (only if not the currently selected deposit in this dropdown)
                            // If the deposit is already selected in this specific dropdown, allow it
                            // as the user might be adjusting liters for it. Validation will happen on submit.
                            if (dep.id_deposito != currentSelectedId) {
                                // If deposit has existing material, check if it's the same type
                                if (dep.id_materia_prima !== null && selectedMateriaPrimaId && (parseInt(dep.id_materia_prima) !== parseInt(selectedMateriaPrimaId))) {
                                    canAdd = false; // Cannot mix different raw materials
                                    infoText += " (No compatible con MP actual)";
                                }
                                // If deposit has existing lot, check if it's the same lot
                                // Only compare if a lot is actually entered for the current entry
                                if (dep.id_entrada_granel_origen !== null && currentLoteProveedor && (getLoteName(dep.id_entrada_granel_origen) !== currentLoteProveedor)) {
                                    canAdd = false; // Cannot mix different lots
                                    infoText += " (No compatible con Lote actual)";
                                }
                            }
                        } else {
                            infoText += " (Vacío)";
                        }

                        // Filter 1: Only show deposits that are not full, unless it's the currently selected one
                        // This allows a selected deposit to remain visible even if it becomes "full" due to its own input
                        if (remainingCapacity <= 0 && dep.id_deposito != currentSelectedId) {
                            canAdd = false;
                            infoText += " (Lleno)";
                        }

                        if (canAdd) {
                            const option = document.createElement('option');
                            option.value = dep.id_deposito;
                            option.textContent = `${dep.nombre_deposito} ${infoText}`;
                            selectElement.appendChild(option);
                        }
                    });
                    // Restore the previously selected value if it's still a valid option
                    if (currentSelectedId && selectElement.querySelector(`option[value="${currentSelectedId}"]`)) {
                        selectElement.value = currentSelectedId;
                    } else {
                        selectElement.value = ""; // Reset if the previously selected value is no longer valid
                    }
                }

                // Event listeners
                addDepositoRowBtn.addEventListener('click', function() {
                    depositoRowIndex++;
                    const newRow = document.createElement('div');
                    newRow.className = 'deposito-row row align-items-end mb-3';
                    newRow.innerHTML = `
                        <div class="col-md-7">
                            <label for="deposito_destino_${depositoRowIndex}" class="form-label">Depósito</label>
                            <select class="form-select" id="deposito_destino_${depositoRowIndex}" name="deposito_destino[]" required></select>
                        </div>
                        <div class="col-md-4">
                            <label for="litros_descargados_${depositoRowIndex}" class="form-label">Litros a descargar</label>
                            <input type="number" step="0.01" class="form-control litros-input" id="litros_descargados_${depositoRowIndex}" name="litros_descargados[]" required min="0.01">
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="button" class="btn btn-danger remove-deposito-row w-100"><i class="bi bi-x-circle"></i></button>
                        </div>
                    `;
                    depositosContainer.appendChild(newRow);
                    
                    // Add event listeners to the new row elements
                    newRow.querySelector('.litros-input').addEventListener('input', updateDepositStatesAndDropdowns);
                    newRow.querySelector('select[name="deposito_destino[]"]').addEventListener('change', updateDepositStatesAndDropdowns);
                    newRow.querySelector('.remove-deposito-row').addEventListener('click', () => {
                        newRow.remove();
                        updateDepositStatesAndDropdowns();
                    });

                    updateDepositStatesAndDropdowns(); // Update all dropdowns and totals
                });

                // Event delegation for removing deposit rows
                document.body.addEventListener('click', function(e) {
                    if (e.target.closest('.remove-deposito-row')) {
                        const rowToRemove = e.target.closest('.deposito-row');
                        // Allow removing the last row. If the form requires at least one, PHP validation will catch it.
                        rowToRemove.remove();
                        updateDepositStatesAndDropdowns();
                    }
                });

                // Event listeners for main form inputs that affect deposit availability
                kgRecibidosInput.addEventListener('input', updateDepositStatesAndDropdowns);
                idMateriaPrimaSelect.addEventListener('change', updateDepositStatesAndDropdowns);
                numeroLoteProveedorInput.addEventListener('input', updateDepositStatesAndDropdowns);

                // Event listener for changes within existing deposit rows (liters or selected deposit)
                depositosContainer.addEventListener('input', e => {
                    if (e.target.classList.contains('litros-input')) {
                        updateDepositStatesAndDropdowns();
                    }
                });
                depositosContainer.addEventListener('change', e => {
                    if (e.target.tagName === 'SELECT' && e.target.name === 'deposito_destino[]') {
                        updateDepositStatesAndDropdowns();
                    }
                });


                // Initial setup on page load
                updateDepositStatesAndDropdowns(); // Perform initial calculations and update all dropdowns
            }
        });
    </script>
</body>
</html>
