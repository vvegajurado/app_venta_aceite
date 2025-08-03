<?php
// Incluir el archivo de verificación de autenticación AL PRINCIPIO para asegurar que la sesión se inicie primero.
include 'auth_check.php';

// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Constante para la densidad del aceite de oliva (ej. 0.916 kg/L)
// Este valor puede necesitar ser ajustado según el tipo específico de aceite y la temperatura.
const OLIVE_OIL_DENSITY_KG_PER_LITER = 0.916; 

// Inicializar variables para mensajes y datos de los selectores
$mensaje = '';
$tipo_mensaje = '';
$depositos_disponibles = []; // Para los selectores de origen y mezcla
$articulos_disponibles = []; // Para el selector de artículos

// --- Cargar datos de apoyo (Depósitos, Materias Primas, Entradas a Granel, Artículos) ---
$materias_primas_map = [];
$entradas_granel_map = [];
$articulos_map = []; // Para mapear IDs de artículos a sus nombres

try {
    // Cargar Materias Primas en un mapa para acceso rápido por ID y también su stock actual
    $stmt_mp_map = $pdo->query("SELECT id_materia_prima, nombre_materia_prima, stock_actual FROM materias_primas");
    while ($row = $stmt_mp_map->fetch(PDO::FETCH_ASSOC)) {
        $materias_primas_map[$row['id_materia_prima']] = [
            'nombre' => $row['nombre_materia_prima'],
            'stock_actual' => $row['stock_actual']
        ];
    }

    // Cargar Números de Lote de Entradas a Granel en un mapa para acceso rápido por ID
    $stmt_eg_map = $pdo->query("SELECT id_entrada_granel, numero_lote_proveedor FROM entradas_granel");
    while ($row = $stmt_eg_map->fetch(PDO::FETCH_ASSOC)) {
        $entradas_granel_map[$row['id_entrada_granel']] = $row['numero_lote_proveedor'];
    }

    // Obtener depósitos disponibles con su info actual
    $stmt_depositos = $pdo->query("SELECT id_deposito, nombre_deposito, capacidad, stock_actual, id_materia_prima, id_entrada_granel_origen, estado FROM depositos ORDER BY nombre_deposito ASC");
    $depositos_disponibles = $stmt_depositos->fetchAll(PDO::FETCH_ASSOC);

    // Obtener Artículos disponibles (productos finales)
    $stmt_articulos = $pdo->query("SELECT id_articulo, nombre_articulo FROM articulos ORDER BY nombre_articulo ASC");
    $articulos_disponibles = $stmt_articulos->fetchAll(PDO::FETCH_ASSOC);
    foreach ($articulos_disponibles as $articulo) {
        $articulos_map[$articulo['id_articulo']] = $articulo['nombre_articulo'];
    }

} catch (PDOException $e) {
    $mensaje = "Error al cargar datos para los formularios: " . $e->getMessage();
    $tipo_mensaje = 'danger';
}

// --- Lógica para PROCESAR el formulario de Lote Envasado ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'crear_lote') {
    $fecha_creacion = $_POST['fecha_creacion'] ?? date('Y-m-d');
    $nombre_lote = trim($_POST['nombre_lote'] ?? '');
    $id_deposito_mezcla = $_POST['id_deposito_mezcla'] ?? null;
    $litros_preparados = (float)($_POST['litros_preparados'] ?? 0);
    $id_articulo = $_POST['id_articulo'] ?? null;
    $observaciones = $_POST['observaciones'] ?? '';

    // Datos de los depósitos de origen y litros extraídos
    $depositos_origen_ids = $_POST['deposito_origen'] ?? [];
    $litros_extraidos_array = $_POST['litros_extraidos'] ?? [];

    // Validación básica de los datos del lote principal
    if (empty($nombre_lote) || empty($id_deposito_mezcla) || !is_numeric($litros_preparados) || $litros_preparados <= 0 || empty($id_articulo) || empty($depositos_origen_ids)) {
        $mensaje = "Error: Por favor, complete todos los campos obligatorios del lote y especifique al menos un depósito de origen.";
        $tipo_mensaje = 'danger';
    } else {
        try {
            $pdo->beginTransaction(); // Iniciar transacción

            // Calcular el total de litros extraídos de los depósitos de origen
            $total_litros_extraidos_calculado = 0;
            // Para acumular los KG extraídos por ID de materia prima
            $kg_extraidos_por_materia_prima = []; 
            
            // Definir las reglas de mezcla de materias primas por artículo (PHP side)
            $reglas_mp_articulo = [
                1 => [1], // Articulo 1 solo con MP 1
                2 => [2], // Articulo ID 2 (Aceite de Oliva Virgen Extra) solo con MP ID 2
                3 => [2, 3], // Articulo ID 3 (Aceite de Oliva) con MP ID 2 o MP ID 3
                4 => [2, 4]  // Articulo ID 4 (Aceite de Orujo de Oliva) con MP ID 2 o MP ID 4
            ];

            // Obtener las MP permitidas para el artículo seleccionado (PHP side)
            $mp_permitidas_php = $reglas_mp_articulo[$id_articulo] ?? [];
            if (empty($mp_permitidas_php)) {
                throw new Exception("Artículo seleccionado no tiene materias primas permitidas definidas.");
            }

            // Procesar y validar cada depósito de origen
            $depositos_origen_info = [];
            foreach ($depositos_origen_ids as $index => $dep_origen_id) {
                $litros_a_extraer = (float)($litros_extraidos_array[$index] ?? 0);

                if ($litros_a_extraer <= 0) {
                    continue; 
                }

                // Obtener información completa del depósito de origen para actualizarlo correctamente
                $stmt_origen = $pdo->prepare("SELECT id_deposito, nombre_deposito, stock_actual, id_materia_prima, id_entrada_granel_origen FROM depositos WHERE id_deposito = ? FOR UPDATE");
                $stmt_origen->execute([$dep_origen_id]);
                $origen_info = $stmt_origen->fetch(PDO::FETCH_ASSOC);

                if (!$origen_info) {
                    throw new Exception("El depósito de origen con ID {$dep_origen_id} no existe.");
                }

                if ($origen_info['stock_actual'] < $litros_a_extraer) {
                    throw new Exception("Error: No hay suficiente stock en el depósito origen '{$origen_info['nombre_deposito']}'. Stock actual: " . number_format($origen_info['stock_actual'], 2, ',', '.') . " L, solicitado: " . number_format($litros_a_extraer, 2, ',', '.') . " L.");
                }

                // Validar materia prima del depósito de origen con las reglas del artículo (PHP side)
                if (!in_array((int)$origen_info['id_materia_prima'], $mp_permitidas_php)) {
                    $nombre_mp_deposito = $materias_primas_map[$origen_info['id_materia_prima']]['nombre'] ?? 'Desconocida';
                    $nombre_articulo_sel = $articulos_map[$id_articulo] ?? 'Desconocido';
                    throw new Exception("Error: El depósito '{$origen_info['nombre_deposito']}' contiene '{$nombre_mp_deposito}'. Este tipo de Materia Prima no es permitida para el Artículo '{$nombre_articulo_sel}'.");
                }


                $total_litros_extraidos_calculado += $litros_a_extraer;
                $depositos_origen_info[] = [
                    'id' => $dep_origen_id, 
                    'litros' => $litros_a_extraer, 
                    'current_stock' => $origen_info['stock_actual'], 
                    'id_materia_prima' => $origen_info['id_materia_prima'],
                    'id_entrada_granel_origen' => $origen_info['id_entrada_granel_origen'] // Capturar el lote de origen
                ];

                // Acumular kilogramos por materia prima
                $kg_extraidos = $litros_a_extraer * OLIVE_OIL_DENSITY_KG_PER_LITER;
                if (!isset($kg_extraidos_por_materia_prima[$origen_info['id_materia_prima']])) {
                    $kg_extraidos_por_materia_prima[$origen_info['id_materia_prima']] = 0;
                }
                $kg_extraidos_por_materia_prima[$origen_info['id_materia_prima']] += $kg_extraidos;
            }

            // Validar que el total de litros extraídos coincide con el total del lote
            if (abs($litros_preparados - $total_litros_extraidos_calculado) > 0.01) {
                throw new Exception("Error: El total de litros del lote (" . number_format($litros_preparados, 2, ',', '.') . " L) no coincide con la suma de litros extraídos de los depósitos de origen (" . number_format($total_litros_extraidos_calculado, 2, ',', '.') . " L).");
            }

            // Obtener información del depósito de mezcla y bloquearlo
            $stmt_mezcla = $pdo->prepare("SELECT id_deposito, nombre_deposito, capacidad, stock_actual, id_materia_prima, id_entrada_granel_origen FROM depositos WHERE id_deposito = ? FOR UPDATE");
            $stmt_mezcla->execute([$id_deposito_mezcla]);
            $deposito_mezcla_info = $stmt_mezcla->fetch(PDO::FETCH_ASSOC);

            if (!$deposito_mezcla_info) {
                throw new Exception("El depósito de mezcla seleccionado no existe.");
            }

            // Validar capacidad del depósito de mezcla
            if ($deposito_mezcla_info['stock_actual'] > 0) {
                 throw new Exception("Error: El depósito de mezcla ('{$deposito_mezcla_info['nombre_deposito']}') ya contiene stock. Debe estar vacío para iniciar un nuevo lote de envasado.");
            }
            if ($litros_preparados > $deposito_mezcla_info['capacidad']) {
                throw new Exception("Error: El tamaño del lote (" . number_format($litros_preparados, 2, ',', '.') . " L) excede la capacidad del depósito de mezcla ('{$deposito_mezcla_info['nombre_deposito']}').");
            }

            // 1. Insertar el Lote Envasado principal
            $stmt_lote_envasado = $pdo->prepare("INSERT INTO lotes_envasado (fecha_creacion, nombre_lote, id_deposito_mezcla, litros_preparados, id_articulo, observaciones) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_lote_envasado->execute([$fecha_creacion, $nombre_lote, $id_deposito_mezcla, $litros_preparados, $id_articulo, $observaciones]);
            $id_nuevo_lote = $pdo->lastInsertId();

            // 2. Actualizar stock de los depósitos de origen y registrar detalles
            foreach ($depositos_origen_info as $origin_dep) {
                $new_stock_origen = $origin_dep['current_stock'] - $origin_dep['litros'];
                
                // Conservar la materia prima y el lote de origen si el depósito no se vacía
                $updated_mp_id_origen = $origin_dep['id_materia_prima'];
                $updated_lote_id_origen = $origin_dep['id_entrada_granel_origen'];

                if ($new_stock_origen == 0) {
                    $estado_origen = 'vacio';
                    $updated_mp_id_origen = NULL;
                    $updated_lote_id_origen = NULL;
                } else {
                    $estado_origen = 'ocupado';
                }

                $stmt_update_origen = $pdo->prepare("UPDATE depositos SET stock_actual = ?, estado = ?, id_materia_prima = ?, id_entrada_granel_origen = ? WHERE id_deposito = ?");
                $stmt_update_origen->execute([$new_stock_origen, $estado_origen, $updated_mp_id_origen, $updated_lote_id_origen, $origin_dep['id']]);

                // Insertar detalle del lote envasado
                // Capturamos el numero_lote_proveedor directamente en la tabla de detalle
                $captura_lote_proveedor = $entradas_granel_map[$origin_dep['id_entrada_granel_origen']] ?? NULL; 
                $stmt_detalle_lote = $pdo->prepare("INSERT INTO detalle_lotes_envasado (id_lote_envasado, id_deposito_origen, litros_extraidos, numero_lote_proveedor_capturado) VALUES (?, ?, ?, ?)");
                $stmt_detalle_lote->execute([$id_nuevo_lote, $origin_dep['id'], $origin_dep['litros'], $captura_lote_proveedor]);
            }

            // 3. Actualizar el stock actual de las Materias Primas
            foreach ($kg_extraidos_por_materia_prima as $mp_id => $kg_extraidos) {
                $new_mp_stock = $materias_primas_map[$mp_id]['stock_actual'] - $kg_extraidos;
                $stmt_update_mp = $pdo->prepare("UPDATE materias_primas SET stock_actual = ? WHERE id_materia_prima = ?");
                $stmt_update_mp->execute([$new_mp_stock, $mp_id]);
            }

            // 4. Actualizar el depósito de mezcla (stock_actual y asignación de MP/Lote a NULL)
            $estado_mezcla = ($litros_preparados == $deposito_mezcla_info['capacidad']) ? 'lleno' : 'ocupado';

            $stmt_update_mezcla = $pdo->prepare("UPDATE depositos SET stock_actual = ?, estado = ?, id_materia_prima = ?, id_entrada_granel_origen = ? WHERE id_deposito = ?");
            // Para el depósito de mezcla, la materia prima y el lote de origen se establecen en NULL
            // para indicar que ahora contiene un producto mezclado/final, no una MP o lote específico.
            $stmt_update_mezcla->execute([$litros_preparados, $estado_mezcla, NULL, NULL, $id_deposito_mezcla]);


            $pdo->commit(); // Confirmar la transacción
            $mensaje = "Lote envasado '{$nombre_lote}' (ID {$id_nuevo_lote}) creado correctamente. Depósitos y Stock de Materias Primas actualizados.";
            $tipo_mensaje = 'success';
            header("Location: lotes_envasado.php?mensaje=" . urlencode($mensaje) . "&tipo_mensaje=" . $tipo_mensaje);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "Error al procesar el lote: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        } catch (PDOException $e) {
            $pdo->rollBack();
            // Específicamente manejar el error de entrada duplicada
            if ($e->getCode() == '23000' && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                 $mensaje = "Error: El nombre de lote '" . htmlspecialchars($nombre_lote) . "' ya existe. Por favor, elija un nombre de lote único.";
                 $tipo_mensaje = 'danger';
            } else {
                 $mensaje = "Error de base de datos al procesar el lote: " . $e->getMessage();
                 $tipo_mensaje = 'danger';
            }
        }
    }
}


// Cargar mensajes de la redirección
if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
    $tipo_mensaje = $_GET['tipo_mensaje'];
}

// Lógica para listar Lotes Envasados y calcular totales
$lotes_envasados_registrados = [];
$total_litros_preparados_general = 0; // Nuevo total general
$total_litros_restantes_general = 0; // Nuevo total para litros restantes

// Determinar si hay un filtro de artículo activo
$filtro_id_articulo = $_GET['filter_articulo'] ?? null;
$where_clause = "";
$params = [];

if ($filtro_id_articulo && is_numeric($filtro_id_articulo)) {
    $where_clause = " WHERE le.id_articulo = :id_articulo";
    $params[':id_articulo'] = $filtro_id_articulo;
}

try {
    $sql_lotes = "SELECT
                        le.id_lote_envasado,
                        le.fecha_creacion,
                        le.nombre_lote,
                        dm.nombre_deposito AS nombre_deposito_mezcla,
                        dm.stock_actual, -- Añadido para obtener el stock actual del depósito de mezcla
                        le.litros_preparados,
                        a.nombre_articulo,
                        le.observaciones
                    FROM
                        lotes_envasado le
                    JOIN
                        depositos dm ON le.id_deposito_mezcla = dm.id_deposito
                    JOIN
                        articulos a ON le.id_articulo = a.id_articulo
                    " . $where_clause . "
                    ORDER BY
                        le.fecha_creacion DESC, le.id_lote_envasado DESC";
    
    $stmt_lotes = $pdo->prepare($sql_lotes);
    $stmt_lotes->execute($params);
    $lotes_envasados_registrados = $stmt_lotes->fetchAll(PDO::FETCH_ASSOC);

    // Para cada lote, obtener los detalles de los depósitos de origen y sus lotes de proveedor
    foreach ($lotes_envasados_registrados as $key => $lote) {
        $stmt_detalles_depositos_origen = $pdo->prepare("SELECT
                                                            d.nombre_deposito,
                                                            dle.litros_extraidos,
                                                            mp.nombre_materia_prima,
                                                            dle.numero_lote_proveedor_capturado AS numero_lote_proveedor
                                                        FROM
                                                            detalle_lotes_envasado dle
                                                        JOIN
                                                            depositos d ON dle.id_deposito_origen = d.id_deposito
                                                        LEFT JOIN
                                                            materias_primas mp ON d.id_materia_prima = mp.id_materia_prima
                                                        WHERE
                                                            dle.id_lote_envasado = ?");
        $stmt_detalles_depositos_origen->execute([$lote['id_lote_envasado']]);
        $lotes_envasados_registrados[$key]['depositos_origen'] = $stmt_detalles_depositos_origen->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular litros restantes por envasar para este lote
        $lotes_envasados_registrados[$key]['litros_restantes_envasar'] = $lote['stock_actual'];

        // Sumar a los totales generales
        $total_litros_preparados_general += $lote['litros_preparados'];
        $total_litros_restantes_general += $lote['stock_actual']; // Suma del stock_actual del depósito de mezcla
    }

} catch (PDOException $e) {
    $mensaje_listado = "Error al cargar el listado de lotes envasados: " . $e->getMessage();
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
    <title>Preparación de Lotes Envasados</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Variables globales y estilos comunes (copiar de tus otros archivos para consistencia) */
        :root {
            --primary-color: #0056b3; /* Azul primario, consistente con otros archivos */
            --primary-dark: #004494;
            --secondary-color: #28a745; /* Verde para énfasis (usado para bg-info) */
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

            /* Ajuste de variables para que el sidebar esté siempre expandido o ancho por defecto */
            --sidebar-width: 250px; /* Ancho deseado para el sidebar con texto */
            --content-max-width: 1300px; /* Nuevo: Ancho máximo para el contenido principal (ajustado para ambas tablas) */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
            font-size: 1rem;
            display: flex; /* Para el layout de sidebar y contenido principal */
            min-height: 100vh; /* Para que el cuerpo ocupe toda la altura de la ventana */
            margin: 0;
            padding: 0;
            overflow-x: hidden; /* Evitar scroll horizontal */
        }

        /* Contenedor principal para el sidebar y el contenido */
        /* .wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
        } */ /* Eliminado para usar flex en body */

        /* Estilos del Sidebar */
        .sidebar {
            width: var(--sidebar-width); /* Sidebar siempre expandido por defecto en este setup */
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            flex-shrink: 0; /* Evita que el sidebar se encoja */
            height: 100vh; /* Make sidebar take full viewport height */
            position: sticky; /* Keep sidebar fixed when scrolling */
            top: 0; /* Align to the top */
            overflow-y: auto; /* Scroll si el contenido es largo */
        }

        .sidebar-header {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header .app-icon {
            font-size: 3rem; /* Más grande para el icono del header */
            color: #fff;
            margin-bottom: 10px;
        }
        .sidebar-header .app-logo {
            max-width: 100px; /* Tamaño del logo en el sidebar */
            height: auto;
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
            flex-grow: 1; /* Ocupa el espacio restante */
            padding: 30px; /* Padding general para el contenido */
            background-color: var(--bg-light);
            min-height: 100vh; /* Asegura que el contenido principal también ocupe toda la altura */
        }

        /* Estilos del contenedor principal dentro del main-content (añadidos para consistencia) */
        .container {
            background-color: var(--header-bg); /* Fondo blanco para el contenedor */
            padding: 40px; /* Padding uniforme para el contenido del container */
            border-radius: 15px;
            box-shadow: 0 4px 8px var(--shadow-light);
            margin-bottom: 40px;
            max-width: var(--content-max-width); /* Usar la nueva variable para el ancho máximo */
            margin-left: auto; /* Center the container */
            margin-right: auto; /* Center the container */
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
            box-shadow: 0 0 0 0.25rem rgba(0, 86, 179, 0.25);
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

        .btn-sm {
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 0.875rem;
            line-height: 1.2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-sm i {
            margin-right: 5px;
            font-size: 0.9rem;
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

        .card {
            margin-bottom: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 8px var(--shadow-light);
        }
        /* Nuevo estilo para la tarjeta del formulario */
        .form-section-card {
            max-width: var(--content-max-width); /* Ajustado para ser igual a la tabla */
        }
        /* Nuevo estilo para la tarjeta de la tabla */
        .table-section-card {
            max-width: var(--content-max-width); /* Utiliza el ancho máximo del contenedor principal */
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
        }

        /* Alineación para la tabla de Lotes Envasados */
        .table th:nth-child(5), /* Litros Preparados (L) */
        .table td:nth-child(5),
        .table th:nth-child(6), /* Litros Restantes (L) */
        .table td:nth-child(6) {
            text-align: right;
        }
        .table th:nth-child(1), /* ID Lote */
        .table td:nth-child(1),
        .table th:nth-child(2), /* Fecha Creación */
        .table td:nth-child(2),
        .table th:nth-child(3), /* Nombre Lote */
        .table td:nth-child(3),
        .table th:nth-child(4), /* Depósito Mezcla */
        .table td:nth-child(4),
        .table th:nth-child(7), /* Artículo */
        .table td:nth-child(7),
        .table th:nth-child(9), /* Observaciones */
        .table td:nth-child(9) {
            text-align: center;
        }
        /* Ajuste para la columna de Depósitos Origen (MP / Lote / Litros) */
        .table th:nth-child(8),
        .table td:nth-child(8) {
            text-align: left; /* Alineación a la izquierda para listas */
        }


        /* Estilo para la fila de totales */
        .table tfoot tr {
            font-weight: bold;
            background-color: #e9ecef; /* Un fondo ligeramente diferente para los totales */
        }
        .table tfoot td {
            border-top: 2px solid var(--primary-dark); /* Borde superior más grueso */
        }

        .form-label-required::after {
            content: " *";
            color: red;
            margin-left: 3px;
        }
        /* Estilos específicos para las secciones de detalle múltiple */
        .detail-row {
            display: flex;
            flex-wrap: wrap; /* Added for responsiveness */
            align-items: flex-end;
            margin-bottom: 15px;
            border: 1px solid var(--border-color, #e0e0e0);
            padding: 15px;
            border-radius: 8px;
            background-color: #fcfdfe;
            box-shadow: 0 1px 5px rgba(0,0,0,0.05);
        }
        .detail-row .col {
            padding-right: 15px;
        }
        .detail-row .col:last-child {
             padding-right: 0;
        }
        .detail-row:first-child .remove-detail-row {
            display: none; /* Ocultar el botón de eliminar en la primera fila */
        }

        /* Estilos para los botones de filtro */
        .filter-buttons .btn {
            margin-right: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
            font-weight: 500;
        }
        .filter-buttons .btn-filter.active {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            color: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        /* Estilos para el indicador de carga */
        .loading-indicator {
            display: none; /* Oculto por defecto */
            align-items: center;
            justify-content: center;
            margin-top: 15px;
            color: var(--primary-dark);
            font-weight: 500;
        }
        .loading-indicator .spinner-border {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 10px;
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
            .container, .card, .form-section-card, .table-section-card { /* Aplicar a todas las tarjetas en móvil */
                padding: 20px;
                max-width: 100%; /* Ocupa todo el ancho en móvil */
            }
            .detail-row .col {
                padding-right: 0; /* Eliminar padding horizontal entre columnas en móvil */
                width: 100%; /* Cada columna ocupa su propia línea */
                margin-bottom: 15px; /* Espacio entre cada campo */
            }
            .detail-row .col:last-child {
                 margin-bottom: 0; /* Última columna sin margen inferior */
            }
        }
    </style>
</head>
<body>
    <?php
    // Incluir el sidebar
    include 'sidebar.php';
    ?>

    <div class="main-content">
        <div class="container">
            <h2 class="mb-4 text-center"><i class="bi bi-box-seam-fill me-3"></i> Preparación en Depósito de Lote para Envasar</h2>

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

            <!-- Formulario de Lote Envasado -->
            <div class="card form-section-card"> <!-- Añadida clase form-section-card -->
                <div class="card-header bg-success">
                    <h5 class="mb-0">Crear Nuevo Lote de Envasado</h5>
                </div>
                <div class="card-body">
                    <form id="loteEnvasadoForm" action="lotes_envasado.php" method="POST">
                        <input type="hidden" name="accion" value="crear_lote">

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="fecha_creacion" class="form-label form-label-required">Fecha de Creación</label>
                                <input type="date" class="form-control" id="fecha_creacion" name="fecha_creacion" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="nombre_lote" class="form-label form-label-required">Nombre del Lote</label>
                                <input type="text" class="form-control" id="nombre_lote" name="nombre_lote" required placeholder="Ej: Lote AOVE Invierno 2024">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="id_articulo" class="form-label form-label-required">Artículo (Producto Final)</label>
                                <select class="form-select" id="id_articulo" name="id_articulo" required>
                                    <option value="">Seleccione un Artículo</option>
                                    <?php foreach ($articulos_disponibles as $articulo): ?>
                                        <option value="<?php echo htmlspecialchars($articulo['id_articulo']); ?>"><?php echo htmlspecialchars($articulo['nombre_articulo']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6 mb-3">
                                <label for="litros_preparados" class="form-label form-label-required">Litros Preparados</label>
                                <input type="number" step="0.01" class="form-control" id="litros_preparados" name="litros_preparados" min="0.01" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="id_deposito_mezcla" class="form-label form-label-required">Depósito de Mezcla (Destino del Lote)</label>
                                <select class="form-select" id="id_deposito_mezcla" name="id_deposito_mezcla" required>
                                    <option value="">Seleccione un Depósito de Mezcla</option>
                                    <?php foreach ($depositos_disponibles as $deposito): ?>
                                        <?php
                                            // Solo mostrar depósitos VACÍOS para el depósito de mezcla
                                            if ($deposito['stock_actual'] > 0) {
                                                continue; // Saltar si el depósito no está vacío
                                            }
                                            $info_deposito_mezcla = " [Cap: " . number_format($deposito['capacidad'], 2, ',', '.') . " L";
                                            if ($deposito['stock_actual'] > 0) {
                                                $mp_nombre = $materias_primas_map[$deposito['id_materia_prima']]['nombre'] ?? 'Desconocida';
                                                $lote_display = $entradas_granel_map[$deposito['id_entrada_granel_origen']] ?? 'Desconocido';
                                                $info_deposito_mezcla .= ", Stock: " . number_format($deposito['stock_actual'], 2, ',', '.') . " L, MP: " . htmlspecialchars($mp_nombre) . ", Lote: " . htmlspecialchars($lote_display);
                                            } else {
                                                $info_deposito_mezcla .= ", Vacío";
                                            }
                                            $info_deposito_mezcla .= "]";
                                        ?>
                                        <option value="<?php echo htmlspecialchars($deposito['id_deposito']); ?>">
                                            <?php echo htmlspecialchars($deposito['nombre_deposito']) . $info_deposito_mezcla; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12 mb-3">
                                <label for="observaciones" class="form-label">Observaciones</label>
                                <textarea class="form-control" id="observaciones" name="observaciones" rows="1"></textarea>
                            </div>
                        </div>

                        <hr>
                        <h4>Depósitos de Origen <small class="text-muted">(¿De dónde se extrae el aceite?)</small></h4>
                        <div id="depositos-origen-container">
                            <!-- Un campo de depósito de origen por defecto -->
                            <div class="detail-row row align-items-end mb-3" data-row-index="0">
                                <div class="col-md-7">
                                    <label for="deposito_origen_0" class="form-label">Depósito Origen</label>
                                    <select class="form-select" id="deposito_origen_0" name="deposito_origen[]" required>
                                        <option value="">Seleccione un Depósito Origen</option>
                                        <!-- Opciones generadas por JavaScript -->
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="litros_extraidos_0" class="form-label">Litros a Extraer</label>
                                    <input type="number" step="0.01" class="form-control litros-extraidos-input" id="litros_extraidos_0" name="litros_extraidos[]" min="0.01" required>
                                </div>
                                <div class="col-md-1 d-flex align-items-end">
                                    <button type="button" class="btn btn-danger remove-detail-row w-100"><i class="bi bi-x-circle"></i></button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary mt-2" id="add-deposito-origen-row"><i class="bi bi-plus-circle"></i> Añadir otro depósito de origen</button>

                        <div class="alert alert-info mt-3" role="alert">
                            Total de Litros Preparados (esperado): <strong id="total-litros-preparados-expected">0.00</strong> L <br>
                            Litros extraídos sumados: <strong id="litros-extraidos-sum">0.00</strong> L <br>
                            Diferencia: <strong id="litros-diferencia">0.00</strong> L
                        </div>
                        
                        <button type="submit" class="btn btn-primary mt-3" id="submitLoteBtn"><i class="bi bi-plus-circle"></i> Crear Lote</button>
                        <div class="loading-indicator" id="loadingIndicator">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <span>Creando lote...</span>
                        </div>
                    </form>
                </div>
            </div>
            
            <h3 class="mt-5 mb-3 text-center">Historial de Lotes Envasados</h3>

            <!-- Botones de filtro por artículo -->
            <div class="mb-4 filter-buttons">
                <h5>Filtrar por Artículo:</h5>
                <a href="lotes_envasado.php" class="btn btn-outline-secondary <?php echo ($filtro_id_articulo === null) ? 'active' : ''; ?>">Mostrar Todos</a>
                <?php foreach ($articulos_disponibles as $articulo): ?>
                    <a href="lotes_envasado.php?filter_articulo=<?php echo htmlspecialchars($articulo['id_articulo']); ?>" 
                       class="btn btn-outline-success btn-filter <?php echo ($filtro_id_articulo == $articulo['id_articulo']) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($articulo['nombre_articulo']); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="card table-section-card"> <!-- Añadida clase table-section-card -->
                <div class="card-header bg-info">
                    <h5 class="mb-0">Lotes Recientes</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($lotes_envasados_registrados)): ?>
                        <p class="text-center">No hay lotes envasados registrados todavía<?php echo ($filtro_id_articulo ? ' para el artículo seleccionado' : ''); ?>.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID Lote</th>
                                        <th>Fecha Creación</th>
                                        <th>Nombre Lote</th>
                                        <th>Depósito Mezcla</th>
                                        <th>Litros Preparados (L)</th>
                                        <th>Litros Restantes (L)</th> <!-- Nueva columna -->
                                        <th>Artículo</th>
                                        <th>Depósitos Origen (MP / Lote / Litros)</th>
                                        <th>Observaciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lotes_envasados_registrados as $lote): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($lote['id_lote_envasado']); ?></td>
                                            <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($lote['fecha_creacion']))); ?></td>
                                            <td><?php echo htmlspecialchars($lote['nombre_lote'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($lote['nombre_deposito_mezcla']); ?></td>
                                            <td class="text-end"><?php echo number_format($lote['litros_preparados'], 2, ',', '.'); ?></td>
                                            <td class="text-end"><?php echo number_format($lote['litros_restantes_envasar'], 2, ',', '.'); ?></td> <!-- Mostrar litros restantes -->
                                            <td><?php echo htmlspecialchars($lote['nombre_articulo'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if (!empty($lote['depositos_origen'])): ?>
                                                    <ul>
                                                        <?php foreach ($lote['depositos_origen'] as $dep_detalle): ?>
                                                            <li>
                                                                <?php echo htmlspecialchars($dep_detalle['nombre_deposito']); ?>: 
                                                                <?php echo number_format($dep_detalle['litros_extraidos'], 2, ',', '.') . " L"; ?> 
                                                                (<?php echo htmlspecialchars($dep_detalle['nombre_materia_prima'] ?? 'N/A'); ?> - Lote Prov.: <span class="<?php echo ($dep_detalle['numero_lote_proveedor'] ?? 'N/A') === 'N/A' ? 'text-danger' : ''; ?>"><?php echo htmlspecialchars($dep_detalle['numero_lote_proveedor'] ?? 'N/A'); ?></span>)
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($lote['observaciones']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4"><strong>Total General<?php echo ($filtro_id_articulo ? ' (Artículo Filtrado)' : ''); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_litros_preparados_general, 2, ',', '.'); ?> L</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_litros_restantes_general, 2, ',', '.'); ?> L</strong></td> <!-- Total de litros restantes -->
                                        <td colspan="3"></td> <!-- Espacio para las columnas restantes -->
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

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loteEnvasadoForm = document.getElementById('loteEnvasadoForm');
            const submitLoteBtn = document.getElementById('submitLoteBtn');
            const loadingIndicator = document.getElementById('loadingIndicator');
            const depositosOrigenContainer = document.getElementById('depositos-origen-container');
            const addDepositoOrigenRowBtn = document.getElementById('add-deposito-origen-row');
            const idArticuloSelect = document.getElementById('id_articulo');
            const litrosPreparadosInput = document.getElementById('litros_preparados');
            const totalLitrosPreparadosExpectedSpan = document.getElementById('total-litros-preparados-expected');
            const litrosExtraidosSumSpan = document.getElementById('litros-extraidos-sum');
            const litrosDiferenciaSpan = document.getElementById('litros-diferencia');

            let depositoOrigenRowIndex = 0; // Para IDs únicos en filas dinámicas

            // Datos de depósitos y materias primas pasados desde PHP
            const depositosDisponiblesForJs = <?php echo json_encode($depositos_disponibles); ?>;
            const materiasPrimasMapJs = <?php echo json_encode($materias_primas_map); ?>;
            const entradasGranelMapJs = <?php echo json_encode($entradas_granel_map); ?>; // Asegurarse de tener esto para el display

            // Reglas de negocio para la mezcla de materias primas por artículo (JS side)
            const articuloRules = {
                '1': [1], // Articulo ID 1 solo con MP ID 1
                '2': [2], // Articulo ID 2 (Aceite de Oliva Virgen Extra) solo con MP ID 2
                '3': [2, 3], // Articulo ID 3 (Aceite de Oliva) con MP ID 2 o MP ID 3
                '4': [2, 4]  // Articulo ID 4 (Aceite de Orujo de Oliva) con MP ID 2 o MP ID 4
            };

            // Función para calcular y mostrar los totales y la diferencia
            function updateTotals() {
                const expectedTotalLitros = parseFloat(litrosPreparadosInput.value) || 0;
                totalLitrosPreparadosExpectedSpan.textContent = expectedTotalLitros.toFixed(2).replace('.', ',');

                let sumExtraidos = 0;
                document.querySelectorAll('.litros-extraidos-input').forEach(input => {
                    const val = parseFloat(input.value) || 0;
                    sumExtraidos += val;
                });
                litrosExtraidosSumSpan.textContent = sumExtraidos.toFixed(2).replace('.', ',');
                
                const difference = expectedTotalLitros - sumExtraidos;
                litrosDiferenciaSpan.textContent = difference.toFixed(2).replace('.', ',');

                // Cambiar color si hay discrepancia (tolerancia para flotantes)
                if (Math.abs(difference) > 0.01) {
                    litrosDiferenciaSpan.style.color = 'red';
                } else {
                    litrosDiferenciaSpan.style.color = 'green';
                }
            }

            // Función para poblar un select de depósito de origen basado en las reglas del artículo
            function populateSelectOptions(selectElementToPopulate) {
                const selectedArticuloId = idArticuloSelect.value;
                const allowedMpIds = articuloRules[selectedArticuloId] || [];

                // Crear un mapa para las asignaciones actuales de litros por depósito en otras filas
                const currentAllocations = new Map();
                document.querySelectorAll('.detail-row').forEach(row => {
                    const select = row.querySelector('select[name="deposito_origen[]"]');
                    const input = row.querySelector('.litros-extraidos-input');

                    // Solo considerar las asignaciones de OTRAS filas
                    if (select && input && select !== selectElementToPopulate && select.value) {
                        const depId = parseInt(select.value);
                        const liters = parseFloat(input.value) || 0;
                        currentAllocations.set(depId, (currentAllocations.get(depId) || 0) + liters);
                    }
                });

                // Guardar la opción actualmente seleccionada para intentar re-seleccionar
                const currentSelectedValue = selectElementToPopulate.value;

                selectElementToPopulate.innerHTML = '<option value="">Seleccione un Depósito Origen</option>';

                depositosDisponiblesForJs.forEach(deposito => {
                    const depositoMpId = parseInt(deposito.id_materia_prima);

                    // Calcular el stock disponible restando las asignaciones de otras filas
                    const allocated = currentAllocations.get(deposito.id_deposito) || 0;
                    const availableStock = deposito.stock_actual - allocated;

                    // Solo incluir la opción si tiene stock disponible (mayor que un mínimo para evitar flotantes negativos muy pequeños)
                    // O si es la opción actualmente seleccionada, incluso si su stock es 0 (para mantener la selección visible)
                    if ((availableStock > 0.01 && allowedMpIds.includes(depositoMpId)) || 
                        (currentSelectedValue == deposito.id_deposito && allowedMpIds.includes(depositoMpId)) ) {
                        
                        const option = document.createElement('option');
                        option.value = deposito.id_deposito;
                        
                        let infoDeposito = ` [Stock: ${availableStock.toFixed(2).replace('.', ',')}L`; // Mostrar stock ajustado
                        const mpNombre = materiasPrimasMapJs[deposito.id_materia_prima]?.nombre || 'Desconocida';
                        const loteDisplay = entradasGranelMapJs[deposito.id_entrada_granel_origen] || 'N/A';
                        
                        infoDeposito += `, MP: ${mpNombre}`;
                        infoDeposito += `, Lote: ${loteDisplay}`;
                        infoDeposito += `]`;

                        option.textContent = `${deposito.nombre_deposito}${infoDeposito}`;
                        
                        // Si el stock disponible es 0 o negativo, deshabilitar la opción
                        if (availableStock <= 0.01) {
                            option.disabled = true;
                            option.title = "Depósito vacío o sin stock disponible (ya asignado)";
                            // Si la opción ya estaba seleccionada y ahora su stock es 0, la mantenemos seleccionada pero deshabilitada
                        }

                        selectElementToPopulate.appendChild(option);
                    }
                });

                // Intentar re-seleccionar la opción que estaba antes
                if (currentSelectedValue) {
                    selectElementToPopulate.value = currentSelectedValue;
                }
            }

            // Event listener para el cambio del Artículo a Envasar
            idArticuloSelect.addEventListener('change', function() {
                // Actualizar todos los selectores de depósito de origen existentes
                document.querySelectorAll('select[name="deposito_origen[]"]').forEach(select => {
                    populateSelectOptions(select);
                });
            });

            // Añadir fila de depósito de origen
            addDepositoOrigenRowBtn.addEventListener('click', function() {
                depositoOrigenRowIndex++;
                const newRow = document.createElement('div');
                newRow.classList.add('detail-row', 'row', 'align-items-end', 'mb-3');
                newRow.dataset.rowIndex = depositoOrigenRowIndex; // Asignar el nuevo índice de fila
                newRow.innerHTML = `
                    <div class="col-md-7">
                        <label for="deposito_origen_${depositoOrigenRowIndex}" class="form-label">Depósito Origen</label>
                        <select class="form-select" id="deposito_origen_${depositoOrigenRowIndex}" name="deposito_origen[]" required>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="litros_extraidos_${depositoOrigenRowIndex}" class="form-label">Litros a Extraer</label>
                        <input type="number" step="0.01" class="form-control litros-extraidos-input" id="litros_extraidos_${depositoOrigenRowIndex}" name="litros_extraidos[]" min="0.01" required>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="button" class="btn btn-danger remove-detail-row w-100"><i class="bi bi-x-circle"></i></button>
                    </div>
                `;
                depositosOrigenContainer.appendChild(newRow);
                
                // Poblar el nuevo select con las opciones filtradas
                const newSelectElement = newRow.querySelector(`#deposito_origen_${depositoOrigenRowIndex}`);
                populateSelectOptions(newSelectElement);

                // Re-populate all existing selects to update their available stock
                document.querySelectorAll('select[name="deposito_origen[]"]').forEach(select => {
                    populateSelectOptions(select);
                });


                newRow.querySelector('.litros-extraidos-input').addEventListener('input', function() {
                    updateTotals();
                    // Re-populate all existing selects to update their available stock
                    document.querySelectorAll('select[name="deposito_origen[]"]').forEach(select => {
                        populateSelectOptions(select);
                    });
                });
                newRow.querySelector('select[name="deposito_origen[]"]').addEventListener('change', function() {
                    // Re-populate all existing selects to update their available stock
                    document.querySelectorAll('select[name="deposito_origen[]"]').forEach(select => {
                        populateSelectOptions(select);
                    });
                });
                
                newRow.querySelector('.remove-detail-row').addEventListener('click', function() {
                    if (depositosOrigenContainer.children.length > 1) { // Asegurarse de que siempre haya al menos una fila
                        newRow.remove();
                        updateTotals(); // Actualizar totales al eliminar fila
                        // Re-populate all remaining selects to update their available stock
                        document.querySelectorAll('select[name="deposito_origen[]"]').forEach(select => {
                            populateSelectOptions(select);
                        });
                    } else {
                        // If it's the last row, just clear it instead of removing
                        newRow.querySelector('select[name="deposito_origen[]"]').value = '';
                        newRow.querySelector('.litros-extraidos-input').value = '';
                        updateTotals();
                        populateSelectOptions(newRow.querySelector('select[name="deposito_origen[]"]'));
                    }
                });
                updateTotals(); // Actualizar totales al añadir fila
            });

            // Escuchar cambios en los litros totales del lote y en los litros extraídos
            litrosPreparadosInput.addEventListener('input', updateTotals);
            depositosOrigenContainer.addEventListener('input', function(e) {
                if (e.target.classList.contains('litros-extraidos-input')) {
                    updateTotals();
                    // Re-populate all existing selects to update their available stock when an input changes
                    document.querySelectorAll('select[name="deposito_origen[]"]').forEach(select => {
                        populateSelectOptions(select);
                    });
                }
            });

            // Escuchar cambios en los selectores de depósito para repoblar otros
            depositosOrigenContainer.addEventListener('change', function(e) {
                if (e.target.tagName === 'SELECT' && e.target.name === 'deposito_origen[]') {
                    document.querySelectorAll('select[name="deposito_origen[]"]').forEach(select => {
                        populateSelectOptions(select);
                    });
                }
            });

            // Manejar el envío del formulario con indicador de carga
            loteEnvasadoForm.addEventListener('submit', function(event) {
                submitLoteBtn.disabled = true; // Deshabilitar el botón de envío
                loadingIndicator.style.display = 'flex'; // Mostrar el indicador de carga
            });


            // Función para limpiar el formulario
            window.resetForm = function() {
                document.querySelector('form').reset();
                document.getElementById('fecha_creacion').value = '<?php echo date('Y-m-d'); ?>';
                document.getElementById('nombre_lote').value = ''; // Limpiar campo nombre_lote
                document.getElementById('id_articulo').value = ''; // Resetear el artículo seleccionado
                // Eliminar todas las filas de depósitos de origen excepto la primera
                while (depositosOrigenContainer.children.length > 1) {
                    depositosOrigenContainer.removeChild(depositosOrigenContainer.lastChild);
                }
                // Resetear el primer campo de litros extraídos y el selector de depósito de origen
                document.getElementById('litros_extraidos_0').value = '';
                // Repopular para resetearlo a la primera opción, ahora con el filtro aplicado
                populateSelectOptions(document.getElementById('deposito_origen_0')); 

                depositoOrigenRowIndex = 0; // Resetear el índice
                updateTotals();
            };

            // Inicializar el primer select de depósito de origen y los totales al cargar la página
            // Asegurarse de que el elemento inicial tenga el data-row-index
            document.getElementById('deposito_origen_0').closest('.detail-row').dataset.rowIndex = '0';
            populateSelectOptions(document.getElementById('deposito_origen_0'));
            updateTotals();
        });
    </script>
</body>
</html>
