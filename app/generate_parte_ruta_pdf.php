<?php
// Habilitar la visualización de errores para depuración (¡DESACTIVAR EN PRODUCCIÓN!)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Incluir la librería FPDF
require('fpdf/fpdf.php'); // Asegúrate de que la ruta a fpdf.php sea correcta

// Función auxiliar para formatear duración (segundos a HH:MM:SS) en PHP
// Esta función se ha movido aquí para que esté disponible en este script.
function formatDurationPHP($seconds) {
    if (!is_numeric($seconds) || $seconds < 0) {
        return 'N/A';
    }
    $hours = floor($seconds / 3600);
    $seconds %= 3600;
    $minutes = floor($seconds / 60);
    $remainingSeconds = $seconds % 60;

    $result = '';
    if ($hours > 0) {
        $result .= "{$hours}h ";
    }
    if ($minutes > 0 || $hours > 0) {
        $result .= "{$minutes}m ";
    }
    if ($remainingSeconds > 0 || ($hours == 0 && $minutes == 0 && $seconds == 0)) {
        $result .= "{$remainingSeconds}s";
    }
    return trim($result);
}


// Clase PDF personalizada extendiendo FPDF
class PDF extends FPDF {
    // Cabecera de página
    function Header() {
        // Arial bold 15
        $this->SetFont('Arial', 'B', 15);
        // Movernos a la derecha
        $this->Cell(80);
        // Título
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell(30, 10, mb_convert_encoding('Parte de Ruta de Entrega', 'ISO-8859-1', 'UTF-8'), 0, 0, 'C');
        // Salto de línea
        $this->Ln(20);
    }

    // Pie de página
    function Footer() {
        // Posición: a 1.5 cm del final
        $this->SetY(-15);
        // Arial italic 8
        $this->SetFont('Arial', 'I', 8);
        // Número de página
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell(0, 10, mb_convert_encoding('Página ' . $this->PageNo() . '/{nb}', 'ISO-8859-1', 'UTF-8'), 0, 0, 'C');
    }

    // Función para formatear números con decimales y separadores
    function formatNumber($number, $decimals = 2, $dec_point = ',', $thousands_sep = '.') {
        return number_format($number, $decimals, $dec_point, $thousands_sep);
    }

    // Función para imprimir los detalles de un pedido completo
    function PrintPedidoDetails($index, $pedido, $parte_ruta_id) {
        $this->AddPage(); // Cada pedido en una nueva página para mayor claridad
        $this->SetFont('Arial', 'B', 12);
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell(0, 10, mb_convert_encoding("Pedido #" . $pedido['id_pedido'] . " (Punto " . ($index + 1) . ")", 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
        $this->Ln(5);

        // --- Datos del Cliente ---
        $this->SetFont('Arial', 'B', 10);
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell(0, 7, mb_convert_encoding('Datos del Cliente', 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
        $this->SetFont('Arial', '', 9);
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell(0, 6, mb_convert_encoding('Nombre: ' . $pedido['nombre_cliente'], 'ISO-8859-1', 'UTF-8'), 0, 1);
        $this->Cell(0, 6, 'NIF: ' . ($pedido['nif'] ?: 'N/A'), 0, 1);
        $this->Cell(0, 6, 'Teléfono: ' . ($pedido['telefono'] ?: 'N/A'), 0, 1);
        $this->Cell(0, 6, 'Email: ' . ($pedido['email'] ?: 'N/A'), 0, 1);
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->MultiCell(0, 6, mb_convert_encoding('Dirección Principal: ' . ($pedido['cliente_direccion'] ?: 'N/A') . ', ' . ($pedido['cliente_ciudad'] ?: 'N/A') . ' (' . ($pedido['cliente_provincia'] ?: 'N/A') . ') CP: ' . ($pedido['cliente_cp'] ?: 'N/A'), 'ISO-8859-1', 'UTF-8'), 0, 'L');
        $this->Ln(5);

        // --- Dirección de Envío (si existe) ---
        if ($pedido['id_direccion_envio']) {
            $this->SetFont('Arial', 'B', 10);
            // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
            $this->Cell(0, 7, mb_convert_encoding('Dirección de Envío', 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
            $this->SetFont('Arial', '', 9);
            // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
            $this->Cell(0, 6, mb_convert_encoding('Nombre Dirección: ' . ($pedido['nombre_direccion_envio'] ?: 'N/A'), 'ISO-8859-1', 'UTF-8'), 0, 1);
            // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
            $this->MultiCell(0, 6, mb_convert_encoding('Dirección: ' . ($pedido['direccion_envio'] ?: 'N/A') . ', ' . ($pedido['ciudad_envio'] ?: 'N/A') . ' (' . ($pedido['provincia_envio'] ?: 'N/A') . ') CP: ' . ($pedido['cp_envio'] ?: 'N/A'), 'ISO-8859-1', 'UTF-8'), 0, 'L');
            $this->Ln(5);
        }

        // --- Detalles del Pedido ---
        $this->SetFont('Arial', 'B', 10);
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell(0, 7, mb_convert_encoding('Detalles del Pedido', 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
        $this->SetFont('Arial', '', 9);
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell(0, 6, mb_convert_encoding('Fecha Pedido: ' . date('d/m/Y', strtotime($pedido['fecha_pedido'])), 'ISO-8859-1', 'UTF-8'), 0, 1);
        $this->Cell(0, 6, 'Estado Pedido: ' . $pedido['estado_pedido'], 0, 1);
        $invoice_info = $pedido['id_factura_asociada'] ? "#" . $pedido['id_factura_asociada'] . " (Estado Cobro: " . ($pedido['estado_pago'] ?: 'N/A') . ")" : "Ninguna";
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell(0, 6, mb_convert_encoding('Factura Asociada: ' . $invoice_info, 'ISO-8859-1', 'UTF-8'), 0, 1);
        $this->Ln(5);

        // --- Tabla de Productos ---
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(230, 230, 230);
        $this->SetTextColor(0);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(.3);

        $header_productos = ['Producto', 'Unidades', 'L/Unidad', 'L. Total', 'P. Unitario', 'IVA (%)', 'Subtotal'];
        $w_productos = [50, 20, 20, 20, 25, 15, 25]; // Anchos de columna

        for ($i = 0; $i < count($header_productos); $i++) {
            $align = ($i == 0) ? 'L' : 'C'; // Producto a la izquierda, el resto centrado
            if (in_array($header_productos[$i], ['Unidades', 'L/Unidad', 'L. Total', 'P. Unitario', 'IVA (%)', 'Subtotal'])) {
                $align = 'R'; // Numéricos a la derecha
            }
            // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
            $this->Cell($w_productos[$i], 7, mb_convert_encoding($header_productos[$i], 'ISO-8859-1', 'UTF-8'), 1, 0, $align, true);
        }
        $this->Ln();

        $this->SetFont('Arial', '', 8); // Fuente más pequeña para los detalles de productos
        $this->SetFillColor(240, 240, 240);
        $fill = false;
        $total_pedido_litros = 0;

        if (!empty($pedido['detalles_productos'])) {
            foreach ($pedido['detalles_productos'] as $detalle) {
                $litros_total_linea = $detalle['cantidad_solicitada'] * $detalle['litros_por_unidad'];
                $total_pedido_litros += $litros_total_linea;

                // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
                $this->Cell($w_productos[0], 6, mb_convert_encoding($detalle['nombre_producto'], 'ISO-8859-1', 'UTF-8'), 'LR', 0, 'L', $fill);
                $this->Cell($w_productos[1], 6, $this->formatNumber($detalle['cantidad_solicitada']), 'LR', 0, 'R', $fill);
                $this->Cell($w_productos[2], 6, $this->formatNumber($detalle['litros_por_unidad']), 'LR', 0, 'R', $fill);
                $this->Cell($w_productos[3], 6, $this->formatNumber($litros_total_linea), 'LR', 0, 'R', $fill);
                $this->Cell($w_productos[4], 6, $this->formatNumber($detalle['precio_unitario_venta']) . ' €', 'LR', 0, 'R', $fill);
                $this->Cell($w_productos[5], 6, $this->formatNumber($detalle['porcentaje_iva_actual'], 2) . ' %', 'LR', 0, 'R', $fill);
                $this->Cell($w_productos[6], 6, $this->formatNumber($detalle['subtotal_linea']) . ' €', 'LR', 0, 'R', $fill);
                $this->Ln();
                $fill = !$fill;
            }
        } else {
            // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
            $this->Cell(array_sum($w_productos), 6, mb_convert_encoding('No hay productos en este pedido.', 'ISO-8859-1', 'UTF-8'), 'LR', 0, 'C', $fill);
            $this->Ln();
        }

        // Fila de totales del pedido
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(220, 230, 240); // Color de fondo para totales
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell($w_productos[0] + $w_productos[1] + $w_productos[2], 7, mb_convert_encoding('Total Litros Pedido:', 'ISO-8859-1', 'UTF-8'), 'LTB', 0, 'R', true);
        $this->Cell($w_productos[3], 7, $this->formatNumber($total_pedido_litros), 'RTB', 0, 'R', true);
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell($w_productos[4] + $w_productos[5], 7, mb_convert_encoding('Total Euros Pedido (IVA Incl.):', 'ISO-8859-1', 'UTF-8'), 'LTB', 0, 'R', true);
        $this->Cell($w_productos[6], 7, $this->formatNumber($pedido['total_pedido']) . ' €', 'RTB', 0, 'R', true);
        $this->Ln();
        $this->Cell(array_sum($w_productos), 0, '', 'T'); // Línea de cierre de la tabla
        $this->Ln(10);
    }

    // Tabla de orden de carga general para el parte de ruta
    function PrintLoadOrderSummary($data) {
        $this->SetFont('Arial', 'B', 12);
        // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
        $this->Cell(0, 10, mb_convert_encoding('Resumen de Carga por Artículo para la Ruta', 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(230, 230, 230);
        $this->SetTextColor(0);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(.3);

        $header_carga = ['Artículo', 'Unidades a Cargar'];
        $w_carga = [100, 50]; // Anchos de columna

        for ($i = 0; $i < count($header_carga); $i++) {
            $align = ($i == 0) ? 'L' : 'R';
            // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
            $this->Cell($w_carga[$i], 7, mb_convert_encoding($header_carga[$i], 'ISO-8859-1', 'UTF-8'), 1, 0, $align, true);
        }
        $this->Ln();

        $this->SetFont('Arial', '', 9);
        $this->SetFillColor(240, 240, 240);
        $fill = false;

        if (!empty($data)) {
            foreach ($data as $row) {
                // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
                $this->Cell($w_carga[0], 6, mb_convert_encoding($row['nombre_producto'], 'ISO-8859-1', 'UTF-8'), 'LR', 0, 'L', $fill);
                // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
                $this->Cell($w_carga[1], 6, mb_convert_encoding($this->formatNumber($row['total_cantidad_cargada']) . ' uds.', 'ISO-8859-1', 'UTF-8'), 'LR', 0, 'R', $fill);
                $this->Ln();
                $fill = !$fill;
            }
        } else {
            // Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
            $this->Cell(array_sum($w_carga), 6, mb_convert_encoding('No hay artículos en la orden de carga.', 'ISO-8859-1', 'UTF-8'), 'LR', 0, 'C', $fill);
            $this->Ln();
        }
        $this->Cell(array_sum($w_carga), 0, '', 'T'); // Línea de cierre
        $this->Ln(10);
    }
}

// Obtener el ID del parte de ruta y la lista ordenada de pedidos de la URL
$id_parte_ruta = $_GET['id_parte_ruta'] ?? null;
$ordered_pedidos_json = $_GET['ordered_pedidos'] ?? '[]';

// DEBUG: Log the received JSON string
error_log("DEBUG: JSON de pedidos recibido: " . $ordered_pedidos_json);

$ordered_pedidos_ids = json_decode($ordered_pedidos_json, true);

// DEBUG: Log the decoded array
error_log("DEBUG: IDs de pedidos decodificados: " . print_r($ordered_pedidos_ids, true));


$parte_ruta_details = null;
$all_pedidos_data_map = []; // Usaremos un mapa para acceder fácilmente a los datos por ID

if ($id_parte_ruta) {
    try {
        // Obtener detalles del parte de ruta principal
        $stmt_main = $pdo->prepare("
            SELECT pr.id_parte_ruta, r.nombre_ruta, pr.fecha_parte, pr.observaciones, pr.estado, pr.id_ruta,
                   pr.total_kilometros, pr.duracion_estimada_segundos
            FROM partes_ruta pr
            JOIN rutas r ON pr.id_ruta = r.id_ruta
            WHERE pr.id_parte_ruta = ?
        ");
        $stmt_main->execute([$id_parte_ruta]);
        $parte_ruta_details = $stmt_main->fetch(PDO::FETCH_ASSOC);

        if (!$parte_ruta_details) {
            die('Parte de ruta no encontrado.');
        }

        // Si hay pedidos en la lista ordenada, obtener sus detalles completos
        if (!empty($ordered_pedidos_ids)) {
            $placeholders = implode(',', array_fill(0, count($ordered_pedidos_ids), '?'));
            $sql_pedidos = "
                SELECT
                    p.id_pedido, p.fecha_pedido, p.total_pedido_iva_incluido AS total_pedido, p.estado_pedido,
                    p.id_factura_asociada, fv.estado_pago,
                    c.id_cliente, c.nombre_cliente, c.nif, c.telefono, c.email, c.direccion AS cliente_direccion,
                    c.ciudad AS cliente_ciudad, c.provincia AS cliente_provincia, c.codigo_postal AS cliente_cp,
                    de.id_direccion_envio, de.nombre_direccion AS nombre_direccion_envio, de.direccion AS direccion_envio,
                    de.ciudad AS ciudad_envio, de.provincia AS provincia_envio, de.codigo_postal AS cp_envio
                FROM
                    pedidos p
                JOIN
                    clientes c ON p.id_cliente = c.id_cliente
                LEFT JOIN
                    direcciones_envio de ON p.id_direccion_envio = de.id_direccion_envio
                LEFT JOIN
                    facturas_ventas fv ON p.id_factura_asociada = fv.id_factura
                WHERE
                    p.id_pedido IN ($placeholders)
            ";
            $stmt_pedidos = $pdo->prepare($sql_pedidos);
            $stmt_pedidos->execute($ordered_pedidos_ids);
            $all_pedidos_raw = $stmt_pedidos->fetchAll(PDO::FETCH_ASSOC);

            // DEBUG: Log raw pedidos data fetched from DB
            error_log("DEBUG: Pedidos raw de la DB: " . print_r($all_pedidos_raw, true));


            // Poblar el mapa con los datos de los pedidos
            foreach ($all_pedidos_raw as $p) {
                $all_pedidos_data_map[$p['id_pedido']] = $p;
            }

            // DEBUG: Log the populated map
            error_log("DEBUG: Mapa de pedidos poblado: " . print_r($all_pedidos_data_map, true));


            // Para cada pedido en el orden optimizado, obtener sus detalles de productos
            foreach ($ordered_pedidos_ids as $pedido_id) {
                // DEBUG: Check if current pedido_id exists in the map
                if (!isset($all_pedidos_data_map[$pedido_id])) {
                    error_log("DEBUG: Pedido ID " . $pedido_id . " no encontrado en \$all_pedidos_data_map.");
                    continue; // Skip to next iteration if not found
                }

                $current_pedido = &$all_pedidos_data_map[$pedido_id]; // Usar referencia para modificar directamente el mapa

                $stmt_detalles = $pdo->prepare("
                    SELECT
                        dp.id_detalle_pedido,
                        dp.id_producto,
                        prod.nombre_producto,
                        prod.litros_por_unidad,
                        prod.porcentaje_iva_actual,
                        dp.cantidad_solicitada,
                        dp.precio_unitario_venta,
                        dp.subtotal_linea_total AS subtotal_linea
                    FROM
                        detalle_pedidos dp
                    JOIN
                        productos prod ON dp.id_producto = prod.id_producto
                    WHERE
                        dp.id_pedido = ?
                ");
                $stmt_detalles->execute([$current_pedido['id_pedido']]);
                $current_pedido['detalles_productos'] = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

                // DEBUG: Log product details for current pedido
                error_log("DEBUG: Detalles de productos para Pedido ID " . $current_pedido['id_pedido'] . ": " . print_r($current_pedido['detalles_productos'], true));

            }
        } else {
            error_log("DEBUG: ordered_pedidos_ids está vacío. No se cargarán detalles de pedidos.");
        }


        // Obtener la "Orden de Carga" (total de unidades por producto para este parte de ruta)
        $parte_ruta_orden_carga = [];
        $stmt_load_order = $pdo->prepare("
            SELECT
                prod.nombre_producto,
                SUM(dp.cantidad_solicitada) AS total_cantidad_cargada
            FROM
                partes_ruta_pedidos prp
            JOIN
                detalle_pedidos dp ON prp.id_pedido = dp.id_pedido
            JOIN
                productos prod ON dp.id_producto = prod.id_producto
            WHERE
                prp.id_parte_ruta = ?
            GROUP BY
                prod.id_producto, prod.nombre_producto
            ORDER BY
                prod.nombre_producto ASC
        ");
        $stmt_load_order->execute([$id_parte_ruta]);
        $parte_ruta_orden_carga = $stmt_load_order->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error al preparar datos para PDF: " . $e->getMessage());
        die('Error al preparar los datos del PDF: ' . $e->getMessage());
    }
} else {
    die("ID de parte de ruta no proporcionado.");
}

// Crear instancia de PDF
$pdf = new PDF();
$pdf->AliasNbPages();

// --- Información del Parte de Ruta (Primera Página) ---
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);
// Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
$pdf->Cell(0, 10, mb_convert_encoding('Información General del Parte de Ruta', 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
// Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
$pdf->Cell(0, 7, mb_convert_encoding('ID Parte: ' . $parte_ruta_details['id_parte_ruta'], 'ISO-8859-1', 'UTF-8'), 0, 1);
// Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
$pdf->Cell(0, 7, mb_convert_encoding('Ruta: ' . $parte_ruta_details['nombre_ruta'], 'ISO-8859-1', 'UTF-8'), 0, 1);
// Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
$pdf->Cell(0, 7, mb_convert_encoding('Fecha del Parte: ' . date('d/m/Y', strtotime($parte_ruta_details['fecha_parte'])), 'ISO-8859-1', 'UTF-8'), 0, 1);
// Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
$pdf->Cell(0, 7, mb_convert_encoding('Estado: ' . $parte_ruta_details['estado'], 'ISO-8859-1', 'UTF-8'), 0, 1);
$pdf->Cell(0, 7, mb_convert_encoding('Kilómetros Estimados: ' . $pdf->formatNumber($parte_ruta_details['total_kilometros'] ?? 0) . ' km', 'ISO-8859-1', 'UTF-8'), 0, 1);
$pdf->Cell(0, 7, mb_convert_encoding('Duración Estimada: ' . formatDurationPHP($parte_ruta_details['duracion_estimada_segundos'] ?? 0), 'ISO-8859-1', 'UTF-8'), 0, 1);
// Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
$pdf->MultiCell(0, 7, mb_convert_encoding('Observaciones: ' . ($parte_ruta_details['observaciones'] ?: 'N/A'), 'ISO-8859-1', 'UTF-8'), 0, 'L');
$pdf->Ln(10);

// --- Resumen de Carga por Artículo (en la primera página, si hay espacio) ---
$pdf->PrintLoadOrderSummary($parte_ruta_orden_carga);

// --- Detalles de cada Pedido (en páginas separadas, en el orden optimizado) ---
$pdf->SetFont('Arial', 'B', 14);
// Usando mb_convert_encoding para manejar caracteres especiales sin utf8_decode()
$pdf->Cell(0, 10, mb_convert_encoding('Detalle de Pedidos en Orden de Entrega', 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
$pdf->Ln(5);

foreach ($ordered_pedidos_ids as $index => $pedido_id) {
    if (isset($all_pedidos_data_map[$pedido_id])) {
        $pdf->PrintPedidoDetails($index, $all_pedidos_data_map[$pedido_id], $id_parte_ruta);
    }
}

// Salida del PDF
$pdf->Output('I', 'Parte_Ruta_' . $id_parte_ruta . '.pdf');
?>
