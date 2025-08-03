<?php
// Include authentication check file at the beginning of each protected page
include 'auth_check.php';

// Include database connection file
include 'conexion.php';

// Start session to manage messages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Initialize variables for messages
$mensaje = '';
$tipo_mensaje = '';
$resultados_trazabilidad = [];
$tipo_trazabilidad_activa = ''; // To know which tab to keep active

// Function to display messages
function mostrarMensaje($msg, $type) {
    global $mensaje, $tipo_mensaje;
    $mensaje = $msg;
    $tipo_mensaje = $type;
}

// Function to get the abbreviation for the oil type (not directly used for totals but kept for context)
function getAbbreviation($name) {
    $map = [
        'Aceite de Oliva Virgen' => 'AOV',
        'Aceite de Oliva Virgen Extra' => 'AOVE',
        'Aceite de Oliva Refinado' => 'AOR',
        'Aceite de Orujo de Oliva Refinado' => 'AOOR',
        'Aceite de Oliva' => 'AO',
        'Aceite de Orujo de Oliva' => 'AOO'
    ];
    return $map[$name] ?? $name;
}

// --- Logic to PROCESS Traceability Queries ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion_trazabilidad'])) {
    $accion = $_POST['accion_trazabilidad'];
    $tipo_trazabilidad_activa = $_POST['active_tab'] ?? ''; // Keep the active tab

    try {
        if ($accion == 'trazabilidad_adelante') {
            $input_id_entrada = filter_var($_POST['id_entrada_granel'] ?? '', FILTER_SANITIZE_NUMBER_INT);
            $input_lote_proveedor = filter_var($_POST['numero_lote_proveedor'] ?? '', FILTER_SANITIZE_STRING);

            if (empty($input_id_entrada) && empty($input_lote_proveedor)) {
                mostrarMensaje("Por favor, ingrese un ID de Entrada a Granel o un Número de Lote de Proveedor.", "warning");
            } else {
                $resultados_trazabilidad['tipo'] = 'adelante';
                $resultados_trazabilidad['criterio'] = [
                    'id_entrada_granel' => $input_id_entrada,
                    'numero_lote_proveedor' => $input_lote_proveedor
                ];

                // STEP 1: Get bulk entry details, now joining with `materias_primas`
                $stmt_entrada = null;
                if (!empty($input_id_entrada)) {
                    $stmt_entrada = $pdo->prepare("SELECT eg.*, p.nombre_proveedor, mp.nombre_materia_prima FROM entradas_granel eg JOIN proveedores p ON eg.id_proveedor = p.id_proveedor JOIN materias_primas mp ON eg.id_materia_prima = mp.id_materia_prima WHERE eg.id_entrada_granel = ?");
                    $stmt_entrada->execute([$input_id_entrada]);
                } elseif (!empty($input_lote_proveedor)) {
                    $stmt_entrada = $pdo->prepare("SELECT eg.*, p.nombre_proveedor, mp.nombre_materia_prima FROM entradas_granel eg JOIN proveedores p ON eg.id_proveedor = p.id_proveedor JOIN materias_primas mp ON eg.id_materia_prima = mp.id_materia_prima WHERE eg.numero_lote_proveedor = ?");
                    $stmt_entrada->execute([$input_lote_proveedor]);
                }

                $resultados_trazabilidad['entrada'] = $stmt_entrada ? $stmt_entrada->fetch(PDO::FETCH_ASSOC) : null;

                if (!$resultados_trazabilidad['entrada']) {
                    mostrarMensaje("No se encontró ninguna entrada a granel con los criterios proporcionados.", "info");
                } else {
                    $id_entrada_base = $resultados_trazabilidad['entrada']['id_entrada_granel'];
                    $num_lote_proveedor_base = $resultados_trazabilidad['entrada']['numero_lote_proveedor'];

                    // STEP 2: Deposits where it was unloaded, including current stock and capacity
                    $stmt_depositos = $pdo->prepare("SELECT deg.id_deposito, d.nombre_deposito, deg.litros_descargados, d.stock_actual, d.capacidad, d.id_entrada_granel_origen FROM detalle_entrada_granel deg JOIN depositos d ON deg.id_deposito = d.id_deposito WHERE deg.id_entrada_granel = ?");
                    $stmt_depositos->execute([$id_entrada_base]);
                    $resultados_trazabilidad['depositos_descargados'] = $stmt_depositos->fetchAll(PDO::FETCH_ASSOC);

                    // Calculate total liters discharged into deposits and remaining from this specific origin
                    $total_litros_descargados = array_sum(array_column($resultados_trazabilidad['depositos_descargados'], 'litros_descargados'));
                    $resultados_trazabilidad['totales']['total_litros_descargados'] = $total_litros_descargados;


                    $total_litros_restantes_en_depositos_origen_trazado = 0;
                    foreach ($resultados_trazabilidad['depositos_descargados'] as $deposito) {
                        // Only consider stock_actual for deposits whose id_entrada_granel_origen matches the traced entry
                        if ($deposito['id_entrada_granel_origen'] == $id_entrada_base) {
                            $total_litros_restantes_en_depositos_origen_trazado += $deposito['stock_actual'];
                        }
                    }
                    $resultados_trazabilidad['totales']['total_litros_restantes_en_depositos_origen_trazado'] = $total_litros_restantes_en_depositos_origen_trazado;


                    // IDs of affected deposits
                    $ids_depositos_afectados = array_column($resultados_trazabilidad['depositos_descargados'], 'id_deposito');
                    
                    // STEP 3: Bottled Lots that used oil from those deposits or from the supplier lot
                    $sql_lotes_envasado = "
                        SELECT DISTINCT
                            le.id_lote_envasado,
                            le.nombre_lote,
                            le.litros_preparados,
                            le.stock_actual_litros,
                            a.nombre_articulo AS articulo_envasado,
                            le.fecha_creacion,
                            GROUP_CONCAT(DISTINCT d.nombre_deposito ORDER BY d.nombre_deposito SEPARATOR ', ') AS depositos_origen_nombres,
                            SUM(dle.litros_extraidos) AS litros_utilizados_de_origen_trazado
                        FROM
                            lotes_envasado le
                        JOIN
                            detalle_lotes_envasado dle ON le.id_lote_envasado = dle.id_lote_envasado
                        JOIN
                            articulos a ON le.id_articulo = a.id_articulo
                        LEFT JOIN
                            depositos d ON dle.id_deposito_origen = d.id_deposito
                        WHERE
                            dle.numero_lote_proveedor_capturado = ?
                    ";
                    $params_lotes_envasado = [$num_lote_proveedor_base];

                    if (!empty($ids_depositos_afectados)) {
                        $placeholders = implode(',', array_fill(0, count($ids_depositos_afectados), '?'));
                        $sql_lotes_envasado .= " AND dle.id_deposito_origen IN ($placeholders)";
                        $params_lotes_envasado = array_merge($params_lotes_envasado, $ids_depositos_afectados);
                    } else {
                        $sql_lotes_envasado .= " AND 1 = 0"; // Ensure no results if no deposits
                    }

                    $sql_lotes_envasado .= "
                        GROUP BY
                            le.id_lote_envasado, le.nombre_lote, le.litros_preparados, le.stock_actual_litros, articulo_envasado, le.fecha_creacion
                        ORDER BY
                            le.fecha_creacion DESC;
                    ";

                    $stmt_lotes_envasado = $pdo->prepare($sql_lotes_envasado);
                    $stmt_lotes_envasado->execute($params_lotes_envasado);
                    $resultados_trazabilidad['lotes_envasados'] = $stmt_lotes_envasado->fetchAll(PDO::FETCH_ASSOC);

                    // Calculate total liters prepared in bottled lots (overall for the lot)
                    $total_litros_envasados = array_sum(array_column($resultados_trazabilidad['lotes_envasados'], 'litros_preparados'));
                    $resultados_trazabilidad['totales']['total_litros_envasados'] = $total_litros_envasados;

                    // Calculate total liters *from this specific origin* used in bottled lots
                    $total_litros_utilizados_de_origen = array_sum(array_column($resultados_trazabilidad['lotes_envasados'], 'litros_utilizados_de_origen_trazado'));
                    $resultados_trazabilidad['totales']['total_litros_utilizados_de_origen'] = $total_litros_utilizados_de_origen;

                    // Calculate total remaining stock in identified bottled lots
                    $total_stock_actual_lotes_envasados = array_sum(array_column($resultados_trazabilidad['lotes_envasados'], 'stock_actual_litros'));
                    $resultados_trazabilidad['totales']['total_stock_actual_lotes_envasados'] = $total_stock_actual_lotes_envasados;


                    // IDs of affected bottled lots
                    $ids_lotes_envasados_afectados = array_column($resultados_trazabilidad['lotes_envasados'], 'id_lote_envasado');
                    
                    // STEP 4: Sales (invoices and clients) associated with these Bottled Lots
                    $sql_ventas = "
                        SELECT
                            fv.id_factura,
                            fv.fecha_factura,
                            fv.total_factura,
                            fv.estado_pago,
                            c.nombre_cliente,
                            pr.nombre_producto AS producto_vendido,
                            alv.unidades_asignadas AS unidades_vendidas_de_lote,
                            dfv.cantidad AS cantidad_total_linea_factura,
                            dfv.unidades_retiradas,
                            dfv.precio_unitario,
                            le.nombre_lote AS lote_envasado_vendido,
                            pr.litros_por_unidad,
                            dae.id_detalle_actividad,
                            dae.unidades_envasadas,
                            dae.unidades_vendidas AS unidades_vendidas_en_dae
                        FROM
                            asignacion_lotes_ventas alv
                        JOIN
                            detalle_factura_ventas dfv ON alv.id_detalle_factura = dfv.id_detalle_factura
                        JOIN
                            facturas_ventas fv ON dfv.id_factura = fv.id_factura
                        JOIN
                            clientes c ON fv.id_cliente = c.id_cliente
                        JOIN
                            productos pr ON dfv.id_producto = pr.id_producto
                        JOIN
                            detalle_actividad_envasado dae ON alv.id_detalle_actividad = dae.id_detalle_actividad
                        JOIN
                            lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
                        WHERE 1=1
                    ";
                    $params_ventas = [];

                    if (!empty($ids_lotes_envasados_afectados)) {
                        $placeholders_lotes = implode(',', array_fill(0, count($ids_lotes_envasados_afectados), '?'));
                        $sql_ventas .= " AND le.id_lote_envasado IN ($placeholders_lotes)";
                        $params_ventas = array_merge($params_ventas, $ids_lotes_envasados_afectados);
                    } else {
                        $sql_ventas .= " AND 1 = 0"; // Ensure no results if no bottled lots
                    }

                    $sql_ventas .= " ORDER BY fv.fecha_factura DESC;";
                    
                    $stmt_ventas = $pdo->prepare($sql_ventas);
                    $stmt_ventas->execute($params_ventas);
                    $resultados_trazabilidad['ventas'] = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);

                    // Calculate total units sold, total sales value, and total liters sold
                    $total_unidades_vendidas = 0;
                    $total_valor_ventas = 0;
                    $total_litros_vendidos = 0;
                    $total_litros_vendidos_por_producto = [];

                    foreach ($resultados_trazabilidad['ventas'] as &$venta) {
                        $venta['litros_vendidos'] = ($venta['unidades_vendidas_de_lote'] * ($venta['litros_por_unidad'] ?? 0));
                        $total_unidades_vendidas += $venta['unidades_vendidas_de_lote'];
                        $total_litros_vendidos += $venta['litros_vendidos'];

                        $producto_nombre = $venta['producto_vendido'] ?: 'Desconocido';
                        if (!isset($total_litros_vendidos_por_producto[$producto_nombre])) {
                            $total_litros_vendidos_por_producto[$producto_nombre] = 0;
                        }
                        $total_litros_vendidos_por_producto[$producto_nombre] += $venta['litros_vendidos'];
                    }
                    unset($venta);

                    $total_valor_ventas_unique_invoices = 0;
                    $processed_invoices = [];
                    foreach ($resultados_trazabilidad['ventas'] as $venta) {
                        if (!isset($processed_invoices[$venta['id_factura']])) {
                            $total_valor_ventas_unique_invoices += $venta['total_factura'];
                            $processed_invoices[$venta['id_factura']] = true;
                        }
                    }
                    $resultados_trazabilidad['totales']['total_valor_ventas'] = $total_valor_ventas_unique_invoices;


                    $resultados_trazabilidad['totales']['total_unidades_vendidas'] = $total_unidades_vendidas;
                    $resultados_trazabilidad['totales']['total_litros_vendidos'] = $total_litros_vendidos;
                    $resultados_trazabilidad['totales']['total_litros_vendidos_por_producto'] = $total_litros_vendidos_por_producto;


                    if (empty($resultados_trazabilidad['depositos_descargados']) && empty($resultados_trazabilidad['lotes_envasados']) && empty($resultados_trazabilidad['ventas'])) {
                        mostrarMensaje("No se encontraron resultados de trazabilidad para esta entrada.", "info");
                    } else {
                        mostrarMensaje("Resultados de trazabilidad hacia adelante encontrados.", "success");
                    }
                }
            }

        } elseif ($accion == 'trazabilidad_atras') {
            $input_id_factura = filter_var($_POST['id_factura'] ?? '', FILTER_SANITIZE_NUMBER_INT);
            $input_id_lote_envasado = filter_var($_POST['id_lote_envasado_atras'] ?? '', FILTER_SANITIZE_NUMBER_INT);

            if (empty($input_id_factura) && empty($input_id_lote_envasado)) {
                mostrarMensaje("Por favor, ingrese un ID de Factura o un ID de Lote Envasado.", "warning");
            } else {
                $resultados_trazabilidad['tipo'] = 'atras';
                $resultados_trazabilidad['criterio'] = [
                    'id_factura' => $input_id_factura,
                    'id_lote_envasado' => $input_id_lote_envasado
                ];

                $ids_lotes_envasado_a_buscar = [];
                $total_litros_envasados_atras_calc = 0;

                if (!empty($input_id_factura)) {
                    // STEP 1: Identify Bottled Lots from the invoice via asignacion_lotes_ventas
                    $stmt_lotes_factura = $pdo->prepare("
                        SELECT DISTINCT
                            le.id_lote_envasado,
                            le.nombre_lote,
                            le.litros_preparados,
                            p.nombre_producto,
                            p.litros_por_unidad,
                            SUM(alv.unidades_asignadas) AS unidades_vendidas_de_este_lote
                        FROM
                            asignacion_lotes_ventas alv
                        JOIN
                            detalle_factura_ventas dfv ON alv.id_detalle_factura = dfv.id_detalle_factura
                        JOIN
                            detalle_actividad_envasado dae ON alv.id_detalle_actividad = dae.id_detalle_actividad
                        JOIN
                            lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
                        JOIN
                            productos p ON dfv.id_producto = p.id_producto
                        WHERE
                            dfv.id_factura = ?
                        GROUP BY
                            le.id_lote_envasado, le.nombre_lote, le.litros_preparados, p.nombre_producto, p.litros_por_unidad
                    ");
                    $stmt_lotes_factura->execute([$input_id_factura]);
                    $resultados_trazabilidad['lotes_envasados_factura'] = $stmt_lotes_factura->fetchAll(PDO::FETCH_ASSOC);
                    $ids_lotes_envasado_a_buscar = array_column($resultados_trazabilidad['lotes_envasados_factura'], 'id_lote_envasado');

                    foreach ($resultados_trazabilidad['lotes_envasados_factura'] as $lote_info) {
                        $total_litros_envasados_atras_calc += ($lote_info['unidades_vendidas_de_este_lote'] * $lote_info['litros_por_unidad']);
                    }
                    $resultados_trazabilidad['totales']['total_litros_envasados_atras'] = $total_litros_envasados_atras_calc;


                    // Get invoice details
                    $stmt_factura = $pdo->prepare("SELECT fv.*, c.nombre_cliente FROM facturas_ventas fv JOIN clientes c ON fv.id_cliente = c.id_cliente WHERE fv.id_factura = ?");
                    $stmt_factura->execute([$input_id_factura]);
                    $resultados_trazabilidad['factura_info'] = $stmt_factura->fetch(PDO::FETCH_ASSOC);

                    if (empty($ids_lotes_envasado_a_buscar)) {
                         mostrarMensaje("No se encontraron productos o lotes envasados para la factura ID: $input_id_factura.", "info");
                    }

                } elseif (!empty($input_id_lote_envasado)) {
                    $ids_lotes_envasado_a_buscar[] = $input_id_lote_envasado;
                    $stmt_lote_info = $pdo->prepare("SELECT id_lote_envasado, nombre_lote, litros_preparados, id_deposito_mezcla FROM lotes_envasado WHERE id_lote_envasado = ?");
                    $stmt_lote_info->execute([$input_id_lote_envasado]);
                    $lote_info = $stmt_lote_info->fetch(PDO::FETCH_ASSOC);
                    if ($lote_info) {
                         $resultados_trazabilidad['lotes_envasados_factura'][] = $lote_info;
                         $resultados_trazabilidad['totales']['total_litros_envasados_atras'] = $lote_info['litros_preparados'];
                    } else {
                        mostrarMensaje("No se encontró el lote envasado con ID: $input_id_lote_envasado.", "info");
                    }
                }

                if (!empty($ids_lotes_envasado_a_buscar)) {
                    // STEP 2: Origin deposits and supplier lots used for these Bottled Lots
                    $sql_detalle_lotes = "
                        SELECT DISTINCT
                            dle.id_lote_envasado,
                            le.nombre_lote,
                            dle.id_deposito_origen,
                            d.nombre_deposito,
                            dle.litros_extraidos,
                            dle.numero_lote_proveedor_capturado
                        FROM
                            detalle_lotes_envasado dle
                        JOIN
                            lotes_envasado le ON dle.id_lote_envasado = le.id_lote_envasado
                        JOIN
                            depositos d ON dle.id_deposito_origen = d.id_deposito
                        WHERE 1=1
                    ";
                    $params_detalle_lotes = [];

                    if (!empty($ids_lotes_envasado_a_buscar)) {
                        $placeholders_lotes = implode(',', array_fill(0, count($ids_lotes_envasado_a_buscar), '?'));
                        $sql_detalle_lotes .= " AND dle.id_lote_envasado IN ($placeholders_lotes)";
                        $params_detalle_lotes = array_merge($params_detalle_lotes, $ids_lotes_envasado_a_buscar);
                    } else {
                        $sql_detalle_lotes .= " AND 1 = 0"; // Fallback to ensure no results
                    }
                    $sql_detalle_lotes .= ";"; // Ensure the semicolon is there

                    $stmt_detalle_lotes = $pdo->prepare($sql_detalle_lotes);
                    $stmt_detalle_lotes->execute($params_detalle_lotes);
                    $resultados_trazabilidad['origenes_lotes_envasados'] = $stmt_detalle_lotes->fetchAll(PDO::FETCH_ASSOC);

                    // Calculate total liters extracted from deposits
                    $total_litros_extraidos_depositos = array_sum(array_column($resultados_trazabilidad['origenes_lotes_envasados'], 'litros_extraidos'));
                    $resultados_trazabilidad['totales']['total_litros_extraidos_depositos'] = $total_litros_extraidos_depositos;


                    // Collect all unique numero_lote_proveedor_capturado for the next step
                    $numeros_lote_proveedor_unicos = [];
                    foreach ($resultados_trazabilidad['origenes_lotes_envasados'] as $origen) {
                        if (!empty($origen['numero_lote_proveedor_capturado'])) {
                            $numeros_lote_proveedor_unicos[] = $origen['numero_lote_proveedor_capturado'];
                        }
                    }
                    $numeros_lote_proveedor_unicos = array_unique($numeros_lote_proveedor_unicos);

                    // STEP 3: Original Bulk Entries, now joining with `materias_primas`
                    $sql_entradas_granel_origen = "
                        SELECT
                            eg.id_entrada_granel,
                            eg.fecha_entrada,
                            eg.numero_lote_proveedor,
                            eg.kg_recibidos,
                            eg.litros_equivalentes,
                            p.nombre_proveedor,
                            mp.nombre_materia_prima
                        FROM
                            entradas_granel eg
                        JOIN
                            proveedores p ON eg.id_proveedor = p.id_proveedor
                        JOIN
                            materias_primas mp ON eg.id_materia_prima = mp.id_materia_prima
                        WHERE 1=1
                    ";
                    $params_entradas_granel = [];

                    if (!empty($numeros_lote_proveedor_unicos)) {
                        $placeholders_lotes_proveedor = implode(',', array_fill(0, count($numeros_lote_proveedor_unicos), '?'));
                        $sql_entradas_granel_origen .= " AND eg.numero_lote_proveedor IN ($placeholders_lotes_proveedor)";
                        $params_entradas_granel = array_merge($params_entradas_granel, $numeros_lote_proveedor_unicos);
                    } else {
                        $sql_entradas_granel_origen .= " AND 1 = 0"; // Fallback to ensure no results
                    }
                    $sql_entradas_granel_origen .= ";"; // Ensure the semicolon is there

                    $stmt_entradas_granel = $pdo->prepare($sql_entradas_granel_origen);
                    $stmt_entradas_granel->execute($params_entradas_granel);
                    $resultados_trazabilidad['entradas_origen'] = $stmt_entradas_granel->fetchAll(PDO::FETCH_ASSOC);

                    // Calculate total received kg and equivalent liters from original bulk entries
                    $total_kg_recibidos_origen = array_sum(array_column($resultados_trazabilidad['entradas_origen'], 'kg_recibidos'));
                    $total_litros_equivalentes_origen = array_sum(array_column($resultados_trazabilidad['entradas_origen'], 'litros_equivalentes'));
                    $resultados_trazabilidad['totales']['total_kg_recibidos_origen'] = $total_kg_recibidos_origen;
                    $resultados_trazabilidad['totales']['total_litros_equivalentes_origen'] = $total_litros_equivalentes_origen;

                    if (empty($resultados_trazabilidad['origenes_lotes_envasados'])) {
                        mostrarMensaje("No se encontraron detalles de origen para los lotes envasados.", "info");
                    } else {
                        mostrarMensaje("Resultados de trazabilidad hacia atrás encontrados.", "success");
                    }
                } else {
                    mostrarMensaje("No se encontró el lote envasado o factura para realizar la trazabilidad.", "info");
                }
            }
        }
    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos: " . $e->getMessage(), "danger");
        error_log("Error en trazabilidad.php: " . $e->getMessage());
    } catch (Exception $e) {
        mostrarMensaje("Error inesperado: " . $e->getMessage(), "danger");
        error_log("Error en trazabilidad.php: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Trazabilidad - Aceite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Estilos generales (copia de facturas_ventas.php) */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
        }
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        .main-content { /* Renombrado de .content a .main-content para evitar conflictos con estilos de sidebar */
            flex-grow: 1;
            padding: 20px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #0056b3; /* Color primario de facturas_ventas.php */
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
            background-color: #0056b3; /* Color primario de facturas_ventas.php */
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

        /* Estilos específicos de Trazabilidad (ajustados para el nuevo tema) */
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

        .trace-item {
            border: 1px solid #dee2e6; /* Color de borde general */
            border-radius: 8px;
            margin-bottom: 15px;
            padding: 15px;
            background-color: #fefefe;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .trace-item h5 {
            color: #0056b3; /* Usar el color primario del tema */
            margin-bottom: 10px;
            font-weight: 600;
        }

        .trace-sub-item {
            border-left: 3px solid #17a2b8; /* Color info de Bootstrap */
            padding-left: 15px;
            margin-left: 15px;
            margin-top: 10px;
            background-color: #e0f7fa; /* Un azul claro para el sub-item */
            border-radius: 5px;
        }
        .trace-sub-item-secondary {
            border-left: 3px solid #6c757d; /* Gris para el siguiente nivel */
            padding-left: 15px;
            margin-left: 15px;
            margin-top: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .trace-sub-item-tertiary {
            border-left: 3px solid #dc3545; /* Rojo para el tercer nivel */
            padding-left: 15px;
            margin-left: 15px;
            margin-top: 10px;
            background-color: #fff5f5;
            border-radius: 5px;
        }

        .total-summary {
            background-color: #e6ffe6; /* Verde claro para totales */
            border: 1px solid #28a745; /* Borde verde de éxito */
            border-radius: 5px;
            padding: 10px;
            margin-top: 15px;
            font-weight: bold;
            color: #218838; /* Texto verde oscuro */
        }

        /* Custom Modal (ajustado para el nuevo tema) */
        .custom-modal-header {
            background-color: #0056b3; /* Color primario de facturas_ventas.php */
            color: white;
            border-top-left-radius: 0.3rem;
            border-top-right-radius: 0.3rem;
        }
        .custom-modal-footer .btn-primary {
            background-color: #0056b3; /* Color primario de facturas_ventas.php */
            border-color: #0056b3;
        }

        /* Styles for printing */
        @media print {
            body > *:not(#adelante-results):not(#atras-results) {
                display: none !important;
            }
            body {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
                background-color: #fff !important;
                color: #000 !important;
            }
            .main-content, .card, .card-body, .table-responsive, .trace-item, .trace-sub-item, .trace-sub-item-secondary, .trace-sub-item-tertiary, .total-summary {
                box-shadow: none !important;
                border: none !important;
                background-color: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .table thead th {
                background-color: #f0f0f0 !important;
                color: #333 !important;
                -webkit-print-color-adjust: exact; /* For better print appearance */
                color-adjust: exact;
                border: 1px solid #ddd !important;
            }
            .table-striped tbody tr:nth-of-type(odd) {
                background-color: #f8f8f8 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
            }
            h1, h4, h5, h6 {
                color: #000 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
            }
            .badge {
                border: 1px solid #ccc !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
            }
            .total-summary {
                background-color: #e0e0e0 !important;
                border: 1px solid #999 !important;
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
            }
            /* Remove margins from trace items to avoid page breaks in unwanted places */
            .trace-item, .trace-sub-item, .trace-sub-item-secondary, .trace-sub-item-tertiary {
                margin-bottom: 10px !important;
                margin-left: 0 !important;
                padding-left: 0 !important;
            }
        </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; ?> <!-- Asumiendo que sidebar.php contiene la función is_current_page -->

        <!-- Contenido de la página -->
        <div id="content" class="main-content">
            <div class="header">
                <h1>Trazabilidad de Aceite</h1>
                <div class="d-flex">
                    <span class="me-3">Bienvenido, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Usuario'); ?></span>
                    <a href="logout.php" class="btn btn-danger btn-sm">Cerrar Sesión</a>
                </div>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($mensaje); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    Consultar Trazabilidad
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-3" id="trazabilidadTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo ($tipo_trazabilidad_activa == 'adelante' || empty($tipo_trazabilidad_activa)) ? 'active' : ''; ?>" id="adelante-tab" data-bs-toggle="tab" data-bs-target="#trazabilidad-adelante" type="button" role="tab" aria-controls="trazabilidad-adelante" aria-selected="<?php echo ($tipo_trazabilidad_activa == 'adelante' || empty($tipo_trazabilidad_activa)) ? 'true' : 'false'; ?>">
                                Trazabilidad Hacia Adelante (Origen a Cliente)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo ($tipo_trazabilidad_activa == 'atras') ? 'active' : ''; ?>" id="atras-tab" data-bs-toggle="tab" data-bs-target="#trazabilidad-atras" type="button" role="tab" aria-controls="trazabilidad-atras" aria-selected="<?php echo ($tipo_trazabilidad_activa == 'atras') ? 'true' : 'false'; ?>">
                                Trazabilidad Hacia Atrás (Cliente a Origen)
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content" id="trazabilidadTabsContent">
                        <!-- Trazabilidad Hacia Adelante -->
                        <div class="tab-pane fade <?php echo ($tipo_trazabilidad_activa == 'adelante' || empty($tipo_trazabilidad_activa)) ? 'show active' : ''; ?>" id="trazabilidad-adelante" role="tabpanel" aria-labelledby="adelante-tab">
                            <form action="trazabilidad.php" method="POST">
                                <input type="hidden" name="accion_trazabilidad" value="trazabilidad_adelante">
                                <input type="hidden" name="active_tab" value="adelante">
                                <div class="mb-3">
                                    <label for="id_entrada_granel" class="form-label">ID de Entrada a Granel (o uno de los dos)</label>
                                    <input type="number" class="form-control" id="id_entrada_granel" name="id_entrada_granel" value="<?php echo htmlspecialchars($resultados_trazabilidad['criterio']['id_entrada_granel'] ?? ''); ?>" placeholder="Ej: 42">
                                </div>
                                <div class="mb-3">
                                    <label for="numero_lote_proveedor" class="form-label">Número de Lote de Proveedor (o uno de los dos)</label>
                                    <input type="text" class="form-control" id="numero_lote_proveedor" name="numero_lote_proveedor" value="<?php echo htmlspecialchars($resultados_trazabilidad['criterio']['numero_lote_proveedor'] ?? ''); ?>" placeholder="Ej: AS-2024-001">
                                    <div class="form-text">Se usará primero el ID de Entrada a Granel si ambos se rellenan.</div>
                                </div>
                                <button type="submit" class="btn btn-primary">Buscar Trazabilidad Hacia Adelante</button>
                            </form>

                            <?php if ($tipo_trazabilidad_activa == 'adelante' && !empty($resultados_trazabilidad['entrada'])): ?>
                                <hr class="my-4">
                                <button class="btn btn-outline-secondary mb-3" onclick="printResults('adelante-results')"><i class="bi bi-printer"></i> Imprimir Resultados</button>

                                <div id="adelante-results">
                                    <h4 class="mt-4 text-primary">Resultados de Trazabilidad Hacia Adelante</h4>

                                    <!-- Entrada a Granel Origen -->
                                    <div class="trace-item">
                                        <h5><i class="bi bi-box-arrow-in-down-right"></i> Entrada a Granel Origen</h5>
                                        <p><strong>ID Entrada:</strong> <?php echo htmlspecialchars($resultados_trazabilidad['entrada']['id_entrada_granel']); ?></p>
                                        <p><strong>Fecha Entrada:</strong> <?php echo htmlspecialchars($resultados_trazabilidad['entrada']['fecha_entrada']); ?></p>
                                        <p><strong>Proveedor:</strong> <?php echo htmlspecialchars($resultados_trazabilidad['entrada']['nombre_proveedor']); ?></p>
                                        <p><strong>Materia Prima:</strong> <?php echo htmlspecialchars($resultados_trazabilidad['entrada']['nombre_materia_prima']); ?></p>
                                        <p><strong>Lote Proveedor:</strong> <?php echo htmlspecialchars($resultados_trazabilidad['entrada']['numero_lote_proveedor']); ?></p>
                                        <p><strong>Kilos Recibidos:</strong> <?php echo htmlspecialchars(number_format($resultados_trazabilidad['entrada']['kg_recibidos'], 2)); ?> kg</p>
                                        <p><strong>Litros Equivalentes:</strong> <?php echo htmlspecialchars(number_format($resultados_trazabilidad['entrada']['litros_equivalentes'], 2)); ?> L</p>

                                        <?php if (!empty($resultados_trazabilidad['depositos_descargados'])): ?>
                                            <h6 class="mt-3 text-secondary">Depósitos de Descarga:</h6>
                                            <ul class="list-group list-group-flush">
                                                <?php foreach ($resultados_trazabilidad['depositos_descargados'] as $deposito_descargado): ?>
                                                    <li class="list-group-item trace-sub-item">
                                                        <strong>Depósito:</strong> <?php echo htmlspecialchars($deposito_descargado['nombre_deposito']); ?> (ID: <?php echo htmlspecialchars($deposito_descargado['id_deposito']); ?>) - <strong>Litros Descargados:</strong> <?php echo htmlspecialchars(number_format($deposito_descargado['litros_descargados'], 2)); ?> L<br>
                                                        <strong>Stock Actual del Depósito:</strong> <?php echo htmlspecialchars(number_format($deposito_descargado['stock_actual'], 2)); ?> L (Capacidad: <?php echo htmlspecialchars(number_format($deposito_descargado['capacidad'], 2)); ?> L)
                                                        <?php if ($deposito_descargado['id_entrada_granel_origen'] == $id_entrada_base): ?>
                                                            <span class="text-success ms-2">(Este depósito está vinculado a la entrada trazada)</span>
                                                        <?php else: ?>
                                                            <span class="text-muted ms-2">(Este depósito contiene aceite de otros orígenes o mezclas)</span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <div class="total-summary mt-3">
                                                Total Litros Descargados en Depósitos: <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_litros_descargados'], 2)); ?> L<br>
                                                Total Litros Restantes en Depósitos (Vinculados a esta Entrada): <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_litros_restantes_en_depositos_origen_trazado'], 2)); ?> L
                                                <small class="d-block text-muted"> (Sólo incluye depósitos cuyo "ID de Entrada a Granel de Origen" coincide con el trazado. Para mezclas o entradas múltiples, el stock se ve a nivel de depósito total).</small>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted mt-3">No se encontraron depósitos de descarga para esta entrada.</p>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($resultados_trazabilidad['lotes_envasados'])): ?>
                                        <div class="trace-item">
                                            <h5><i class="bi bi-boxes"></i> Lotes Envasados que Contienen este Aceite</h5>
                                            <?php foreach ($resultados_trazabilidad['lotes_envasados'] as $lote_envasado): ?>
                                                <div class="trace-sub-item">
                                                    <h6>Lote Envasado: <?php echo htmlspecialchars($lote_envasado['nombre_lote']); ?> (ID: <?php echo htmlspecialchars($lote_envasado['id_lote_envasado']); ?>)</h6>
                                                    <p><strong>Fecha Creación:</strong> <?php echo htmlspecialchars($lote_envasado['fecha_creacion']); ?></p>
                                                    <p><strong>Artículo Envasado:</strong> <?php echo htmlspecialchars($lote_envasado['articulo_envasado']); ?></p>
                                                    <p><strong>Litros Preparados (Total del Lote):</strong> <?php echo htmlspecialchars(number_format($lote_envasado['litros_preparados'], 2)); ?> L</p>
                                                    <p><strong>Litros Utilizados de este Origen:</strong> <?php echo htmlspecialchars(number_format($lote_envasado['litros_utilizados_de_origen_trazado'], 2)); ?> L</p>
                                                    <p><strong>Stock Actual de Lote:</strong> <?php echo htmlspecialchars(number_format($lote_envasado['stock_actual_litros'], 2)); ?> L</p>
                                                    <p><strong>Depósitos Origen:</strong> <?php echo htmlspecialchars($lote_envasado['depositos_origen_nombres'] ?: 'N/A'); ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="total-summary mt-3">
                                                Total Litros Preparados en Lotes Envasados (Total General): <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_litros_envasados'], 2)); ?> L<br>
                                                Total Litros de *este Origen* Utilizados en Lotes Envasados: <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_litros_utilizados_de_origen'], 2)); ?> L<br>
                                                Total Litros Restantes en Lotes Envasados (Stock Actual): <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_stock_actual_lotes_envasados'], 2)); ?> L
                                                <small class="d-block text-muted"> (Para calcular unidades restantes, necesitaría conocer los formatos de envase específicos de los productos asociados a estos lotes. El stock se muestra en litros).</small>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info mt-3">No se encontraron lotes envasados que contengan aceite de esta entrada.</div>
                                    <?php endif; ?>

                                    <?php if (!empty($resultados_trazabilidad['ventas'])): ?>
                                        <div class="trace-item">
                                            <h5><i class="bi bi-receipt"></i> Ventas Asociadas a Estos Lotes Envasados</h5>
                                            <div class="table-responsive">
                                                <table class="table table-striped table-bordered">
                                                    <thead>
                                                        <tr>
                                                            <th>ID Factura</th>
                                                            <th>Fecha</th>
                                                            <th>Cliente</th>
                                                            <th class="text-center">Lote Envasado</th>
                                                            <th>Producto Vendido</th>
                                                            <th class="text-end">Unidades Vendidas (del lote)</th>
                                                            <th class="text-end">Litros Vendidos (del lote)</th>
                                                            <th class="text-end">Cantidad Total Línea Factura</th>
                                                            <th class="text-end">Unidades Retiradas Línea Factura</th>
                                                            <th class="text-end">Precio Unitario Línea Factura</th>
                                                            <th class="text-end">Total Factura</th>
                                                            <th>Estado Pago</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($resultados_trazabilidad['ventas'] as $venta): ?>
                                                            <tr class="trace-sub-item-secondary">
                                                                <td><?php echo htmlspecialchars($venta['id_factura']); ?></td>
                                                                <td><?php echo htmlspecialchars($venta['fecha_factura']); ?></td>
                                                                <td><?php echo htmlspecialchars($venta['nombre_cliente']); ?></td>
                                                                <td class="text-center"><?php echo htmlspecialchars($venta['lote_envasado_vendido']); ?></td>
                                                                <td><?php echo htmlspecialchars($venta['producto_vendido']); ?></td>
                                                                <td class="text-end"><?php echo htmlspecialchars(number_format($venta['unidades_vendidas_de_lote'], 0)); ?></td>
                                                                <td class="text-end"><?php echo htmlspecialchars(number_format($venta['litros_vendidos'], 2)); ?> L</td>
                                                                <td class="text-end"><?php echo htmlspecialchars(number_format($venta['cantidad_total_linea_factura'], 0)); ?></td>
                                                                <td class="text-end"><?php echo htmlspecialchars(number_format($venta['unidades_retiradas'], 0)); ?></td>
                                                                <td class="text-end"><?php echo htmlspecialchars(number_format($venta['precio_unitario'], 2)); ?> €</td>
                                                                <td class="text-end"><?php echo htmlspecialchars(number_format($venta['total_factura'], 2)); ?> €</td>
                                                                <td><span class="badge bg-<?php echo ($venta['estado_pago'] == 'pagada' ? 'success' : ($venta['estado_pago'] == 'pendiente' ? 'warning' : 'info')); ?>"><?php echo htmlspecialchars(ucfirst($venta['estado_pago'])); ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="total-summary mt-3">
                                                Total Unidades Vendidas (de lotes trazados): <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_unidades_vendidas'], 0)); ?> unidades<br>
                                                Total Litros Vendidos (de lotes trazados): <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_litros_vendidos'], 2)); ?> L<br>
                                                Total Valor de Ventas (de facturas con lotes trazados): <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_valor_ventas'], 2)); ?> €
                                            </div>

                                            <?php if (!empty($resultados_trazabilidad['totales']['total_litros_vendidos_por_producto'])): ?>
                                                <div class="total-summary mt-3">
                                                    <h6>Total de Litros Vendidos por Producto (de lotes trazados):</h6>
                                                    <ul class="list-group list-group-flush">
                                                        <?php foreach ($resultados_trazabilidad['totales']['total_litros_vendidos_por_producto'] as $producto => $litros_total): ?>
                                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                                <?php echo htmlspecialchars($producto); ?>
                                                                <span class="badge bg-primary rounded-pill"><?php echo htmlspecialchars(number_format($litros_total, 2)); ?> L</span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>

                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info mt-3">No se encontraron ventas asociadas a los lotes envasados de esta entrada.</div>
                                    <?php endif; ?>
                                </div> <!-- end adelante-results -->

                            <?php elseif ($tipo_trazabilidad_activa == 'adelante' && $mensaje == "No se encontró ninguna entrada a granel con los criterios proporcionados."): ?>
                                <!-- Message for no results displayed by the alert system -->
                            <?php endif; ?>
                        </div>

                        <!-- Trazabilidad Hacia Atrás -->
                        <div class="tab-pane fade <?php echo ($tipo_trazabilidad_activa == 'atras') ? 'show active' : ''; ?>" id="trazabilidad-atras" role="tabpanel" aria-labelledby="atras-tab">
                            <form action="trazabilidad.php" method="POST">
                                <input type="hidden" name="accion_trazabilidad" value="trazabilidad_atras">
                                <input type="hidden" name="active_tab" value="atras">
                                <div class="mb-3">
                                    <label for="id_factura" class="form-label">ID de Factura (o uno de los dos)</label>
                                    <input type="number" class="form-control" id="id_factura" name="id_factura" value="<?php echo htmlspecialchars($resultados_trazabilidad['criterio']['id_factura'] ?? ''); ?>" placeholder="Ej: 12">
                                </div>
                                <div class="mb-3">
                                    <label for="id_lote_envasado_atras" class="form-label">ID de Lote Envasado (o uno de los dos)</label>
                                    <input type="number" class="form-control" id="id_lote_envasado_atras" name="id_lote_envasado_atras" value="<?php echo htmlspecialchars($resultados_trazabilidad['criterio']['id_lote_envasado'] ?? ''); ?>" placeholder="Ej: 15">
                                    <div class="form-text">Se usará primero el ID de Factura si ambos se rellenan. Si se usa ID de lote envasado, se buscará directamente el origen de ese lote.</div>
                                </div>
                                <button type="submit" class="btn btn-primary">Buscar Trazabilidad Hacia Atrás</button>
                            </form>

                            <?php if ($tipo_trazabilidad_activa == 'atras' && (!empty($resultados_trazabilidad['lotes_envasados_factura']) || !empty($resultados_trazabilidad['origenes_lotes_envasados']))): ?>
                                <hr class="my-4">
                                <button class="btn btn-outline-secondary mb-3" onclick="printResults('atras-results')"><i class="bi bi-printer"></i> Imprimir Resultados</button>

                                <div id="atras-results">
                                    <h4 class="mt-4 text-primary">Resultados de Trazabilidad Hacia Atrás</h4>

                                    <?php if (!empty($resultados_trazabilidad['factura_info'])): ?>
                                        <div class="trace-item">
                                            <h5><i class="bi bi-receipt"></i> Factura de Venta Origen</h5>
                                            <p><strong>ID Factura:</strong> <?php echo htmlspecialchars($resultados_trazabilidad['factura_info']['id_factura']); ?></p>
                                            <p><strong>Fecha Factura:</strong> <?php echo htmlspecialchars($resultados_trazabilidad['factura_info']['fecha_factura']); ?></p>
                                            <p><strong>Cliente:</strong> <?php echo htmlspecialchars($resultados_trazabilidad['factura_info']['nombre_cliente']); ?></p>
                                            <p><strong>Total Factura:</strong> <?php echo htmlspecialchars(number_format($resultados_trazabilidad['factura_info']['total_factura'], 2)); ?> €</p>
                                            <p><strong>Estado Pago:</strong> <span class="badge bg-<?php echo ($resultados_trazabilidad['factura_info']['estado_pago'] == 'pagada' ? 'success' : ($resultados_trazabilidad['factura_info']['estado_pago'] == 'pendiente' ? 'warning' : 'info')); ?>"><?php echo htmlspecialchars(ucfirst($resultados_trazabilidad['factura_info']['estado_pago'])); ?></span></p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($resultados_trazabilidad['lotes_envasados_factura'])): ?>
                                        <div class="trace-item">
                                            <h5><i class="bi bi-boxes"></i> Lotes Envasados Encontrados</h5>
                                            <?php foreach ($resultados_trazabilidad['lotes_envasados_factura'] as $lote_envasado_vendido): ?>
                                                <div class="trace-sub-item-secondary">
                                                    <h6>Lote Envasado: <?php echo htmlspecialchars($lote_envasado_vendido['nombre_lote'] ?? 'N/A'); ?> (ID: <?php echo htmlspecialchars($lote_envasado_vendido['id_lote_envasado']); ?>)</h6>
                                                    <p><strong>Litros Preparados (Total del Lote):</strong> <?php echo htmlspecialchars(number_format($lote_envasado_vendido['litros_preparados'] ?? 0, 2)); ?> L</p>
                                                    <?php if (!empty($lote_envasado_vendido['nombre_producto'])): ?>
                                                        <p><strong>Producto Vendido:</strong> <?php echo htmlspecialchars($lote_envasado_vendido['nombre_producto']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (isset($lote_envasado_vendido['unidades_vendidas_de_este_lote'])): ?>
                                                        <p><strong>Unidades Vendidas de este Lote (en esta factura/búsqueda):</strong> <?php echo htmlspecialchars(number_format($lote_envasado_vendido['unidades_vendidas_de_este_lote'], 0)); ?></p>
                                                        <p><strong>Litros Vendidos de este Lote (en esta factura/búsqueda):</strong> <?php echo htmlspecialchars(number_format($lote_envasado_vendido['unidades_vendidas_de_este_lote'] * $lote_envasado_vendido['litros_por_unidad'], 2)); ?> L</p>
                                                    <?php endif; ?>

                                                    <?php
                                                    // Find the origins of this specific bottled lot
                                                    $origenes_este_lote = array_filter($resultados_trazabilidad['origenes_lotes_envasados'], function($o) use ($lote_envasado_vendido) {
                                                        return $o['id_lote_envasado'] == $lote_envasado_vendido['id_lote_envasado'];
                                                    });
                                                    ?>
                                                    <?php if (!empty($origenes_este_lote)): ?>
                                                        <h6 class="mt-3 text-info">Origen en Depósitos (para Lote: <?php echo htmlspecialchars($lote_envasado_vendido['nombre_lote'] ?? 'N/A'); ?>):</h6>
                                                        <ul class="list-group list-group-flush">
                                                            <?php foreach ($origenes_este_lote as $origen_deposito): ?>
                                                                <li class="list-group-item trace-sub-item-tertiary">
                                                                    <strong>Depósito:</strong> <?php echo htmlspecialchars($origen_deposito['nombre_deposito']); ?> (ID: <?php echo htmlspecialchars($origen_deposito['id_deposito_origen']); ?>)<br>
                                                                    <strong>Litros Extraídos:</strong> <?php echo htmlspecialchars(number_format($origen_deposito['litros_extraidos'], 2)); ?> L<br>
                                                                    <strong>Lote Proveedor Capturado:</strong> <?php echo htmlspecialchars($origen_deposito['numero_lote_proveedor_capturado']); ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <p class="text-muted mt-3">No se encontraron detalles de origen en depósitos para este lote envasado.</p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="total-summary mt-3">
                                                Total Litros Vendidos de Lotes Envasados (en esta factura/búsqueda): <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_litros_envasados_atras'] ?? 0, 2)); ?> L
                                            </div>
                                            <?php if (!empty($resultados_trazabilidad['totales']['total_litros_extraidos_depositos'])): ?>
                                                <div class="total-summary mt-3">
                                                    Total Litros Extraídos de Depósitos para estos lotes: <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_litros_extraidos_depositos'], 2)); ?> L
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <?php if ($mensaje != "No se encontró el lote envasado con ID: " . ($resultados_trazabilidad['criterio']['id_lote_envasado'] ?? '')): ?>
                                            <div class="alert alert-info mt-3">No se encontraron lotes envasados asociados a los criterios de búsqueda.</div>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (!empty($resultados_trazabilidad['entradas_origen'])): ?>
                                        <div class="trace-item">
                                            <h5><i class="bi bi-truck"></i> Entradas a Granel Originales (Proveedores)</h5>
                                            <div class="table-responsive">
                                                <table class="table table-striped table-bordered">
                                                    <thead>
                                                        <tr>
                                                            <th>ID Entrada</th>
                                                            <th>Fecha</th>
                                                            <th>Proveedor</th>
                                                            <th>Materia Prima</th>
                                                            <th>Lote Proveedor</th>
                                                            <th class="text-end">Kilos Recibidos</th>
                                                            <th class="text-end">Litros Equivalentes</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($resultados_trazabilidad['entradas_origen'] as $entrada_origen): ?>
                                                            <tr class="trace-sub-item">
                                                                <td><?php echo htmlspecialchars($entrada_origen['id_entrada_granel']); ?></td>
                                                                <td><?php echo htmlspecialchars($entrada_origen['fecha_entrada']); ?></td>
                                                                <td><?php echo htmlspecialchars($entrada_origen['nombre_proveedor']); ?></td>
                                                                <td><?php echo htmlspecialchars($entrada_origen['nombre_materia_prima']); ?></td>
                                                                <td><?php echo htmlspecialchars($entrada_origen['numero_lote_proveedor']); ?></td>
                                                                <td class="text-end"><?php echo htmlspecialchars(number_format($entrada_origen['kg_recibidos'], 2)); ?> kg</td>
                                                                <td class="text-end"><?php echo htmlspecialchars(number_format($entrada_origen['litros_equivalentes'], 2)); ?> L</td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="total-summary mt-3">
                                                Total Kilos Recibidos de Origen: <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_kg_recibidos_origen'] ?? 0, 2)); ?> kg<br>
                                                Total Litros Equivalentes de Origen: <?php echo htmlspecialchars(number_format($resultados_trazabilidad['totales']['total_litros_equivalentes_origen'] ?? 0, 2)); ?> L
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info mt-3">No se encontraron entradas a granel originales para los lotes envasados.</div>
                                    <?php endif; ?>
                                </div> <!-- end atras-results -->

                            <?php elseif ($tipo_trazabilidad_activa == 'atras' && $mensaje == "No se encontró el lote envasado con ID: " . ($resultados_trazabilidad['criterio']['id_lote_envasado'] ?? '')): ?>
                                <!-- Message for no results displayed by the alert system -->
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Modal Structure -->
    <div class="modal fade" id="customModal" tabindex="-1" aria-labelledby="customModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header custom-modal-header">
                    <h5 class="modal-title" id="customModalLabel"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="customModalBody">
                    </div>
                <div class="modal-footer custom-modal-footer" id="customModalFooter">
                    <!-- Buttons will be injected here -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        // Function to show the custom modal
        function showCustomModal(title, message, type, callback = null) {
            const modal = new bootstrap.Modal(document.getElementById('customModal'));
            document.getElementById('customModalLabel').innerText = title;
            document.getElementById('customModalBody').innerText = message;
            const modalFooter = document.getElementById('customModalFooter');
            modalFooter.innerHTML = ''; // Clear previous buttons

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
                cancelButton.addEventListener('click', () => {
                    if (callback) callback(false);
                });
                modalFooter.appendChild(cancelButton);

                const confirmButton = document.createElement('button');
                confirmButton.type = 'button';
                confirmButton.classList.add('btn', 'btn-primary');
                confirmButton.innerText = 'Confirmar';
                confirmButton.addEventListener('click', () => {
                    if (callback) callback(true);
                    modal.hide();
                });
                modalFooter.appendChild(confirmButton);
            }
            modal.show();
        }

        // Function to print the specific results section
        function printResults(sectionId) {
            const printContent = document.getElementById(sectionId);
            if (!printContent) {
                console.error("Section to print not found:", sectionId);
                return;
            }

            const originalBodyContent = document.body.innerHTML;

            // Create a new div to hold the content to be printed
            const printContainer = document.createElement('div');
            printContainer.innerHTML = printContent.outerHTML;

            // Add a title for the printout
            const printTitle = document.createElement('h1');
            printTitle.innerText = "Informe de Trazabilidad - " + (sectionId === 'adelante-results' ? 'Hacia Adelante' : 'Hacia Atrás');
            printTitle.style.textAlign = 'center';
            printTitle.style.marginBottom = '20px';
            printTitle.style.color = '#000'; // Ensure black color for print

            // Prepend the title to the print container
            printContainer.prepend(printTitle);

            // Replace the body content with only the print container
            document.body.innerHTML = printContainer.outerHTML;

            window.print();

            // Restore original content after printing (with a small delay to ensure print dialog appears)
            setTimeout(() => {
                document.body.innerHTML = originalBodyContent;
                // Re-initialize Bootstrap tabs after restoring content
                const activeTab = document.querySelector('.nav-link.active');
                if (activeTab) {
                    new bootstrap.Tab(activeTab).show();
                }
            }, 500); // 500ms delay
        }


        // Script to activate the correct tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = document.querySelector('.nav-link.active');
            if (activeTab) {
                new bootstrap.Tab(activeTab).show();
            }

            // Handle alert message if it exists in PHP
            const mensajeDiv = document.querySelector('.alert');
            if (mensajeDiv) {
                // If the message comes from PHP, it will be displayed as a normal Bootstrap alert.
                // If all messages are desired to pass through the modal, PHP should be adapted
                // not to generate the alert div and call showCustomModal directly after a successful
                // submit or error.
            }
        });
    </script>
</body>
</html>
