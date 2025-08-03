<?php
// Incluir la librería FPDF
require('fpdf/fpdf.php'); // Asegúrate de que la ruta a fpdf.php sea correcta

// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Variables para el filtro de fechas
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

// Crear una nueva clase PDF que extienda FPDF para añadir encabezado y pie de página
class PDF extends FPDF
{
    // Cabecera de página
    function Header()
    {
        // Logo (ajusta la ruta y las dimensiones si tienes uno)
        // $this->Image('assets/img/logo.png', 10, 8, 30);
        // Arial bold 15
        $this->SetFont('Arial', 'B', 15);
        // Movernos a la derecha
        $this->Cell(80);
        // Título
        $this->Cell(30, 10, mb_convert_encoding('Informe de Facturas Emitidas', 'ISO-8859-1', 'UTF-8'), 0, 0, 'C');
        // Salto de línea
        $this->Ln(10);
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 10, mb_convert_encoding('Periodo: ' . date('d/m/Y', strtotime($_GET['fecha_inicio'] ?? date('Y-m-01'))) . ' al ' . date('d/m/Y', strtotime($_GET['fecha_fin'] ?? date('Y-m-d'))), 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->Ln(5);

        // Cabecera de la tabla
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(15, 7, mb_convert_encoding('Nº Fact.', 'ISO-8859-1', 'UTF-8'), 1, 0, 'C', true);
        $this->Cell(20, 7, 'Fecha', 1, 0, 'C', true);
        $this->Cell(45, 7, 'Cliente', 1, 0, 'C', true);
        $this->Cell(25, 7, 'NIF', 1, 0, 'C', true);
        $this->Cell(25, 7, mb_convert_encoding('Base Imp.', 'ISO-8859-1', 'UTF-8'), 1, 0, 'R', true);
        $this->Cell(15, 7, '% IVA', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Total IVA', 1, 0, 'R', true);
        $this->Cell(25, 7, mb_convert_encoding('Total Fact.', 'ISO-8859-1', 'UTF-8'), 1, 0, 'R', true);
        $this->Cell(20, 7, 'Litros', 1, 1, 'R', true);
    }

    // Pie de página
    function Footer()
    {
        // Posición: a 1.5 cm del final
        $this->SetY(-15);
        // Arial italic 8
        $this->SetFont('Arial', 'I', 8);
        // Número de página
        $this->Cell(0, 10, mb_convert_encoding('Página ' . $this->PageNo() . '/{nb}', 'ISO-8859-1', 'UTF-8'), 0, 0, 'C');
    }
}

// Creación del objeto PDF
$pdf = new PDF('L', 'mm', 'A4'); // 'L' para orientación horizontal
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 8);

$total_litros_facturados_general = 0;
$total_base_imponible_general = 0;
$total_iva_general = 0;
$total_factura_general = 0;

try {
    // Consulta para obtener las facturas emitidas en el rango de fechas
    $stmt_facturas = $pdo->prepare("
        SELECT
            f.id_factura,
            f.fecha_factura,
            c.nombre_cliente,
            c.nif,
            f.total_factura_iva_incluido,
            f.total_iva_factura,
            f.descuento_global_aplicado,
            f.recargo_global_aplicado
        FROM
            facturas_ventas f
        JOIN
            clientes c ON f.id_cliente = c.id_cliente
        WHERE
            f.fecha_factura BETWEEN ? AND ?
        ORDER BY
            f.fecha_factura ASC, f.id_factura ASC
    ");
    $stmt_facturas->execute([$fecha_inicio, $fecha_fin]);
    $facturas_cabecera = $stmt_facturas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($facturas_cabecera as $factura) {
        $id_factura = $factura['id_factura'];
        $current_total_litros = 0;
        $unique_ivas = [];

        // Obtener detalles de línea para calcular litros y %IVA
        $stmt_detalles = $pdo->prepare("
            SELECT
                df.cantidad,
                p.litros_por_unidad,
                df.porcentaje_iva_aplicado_en_factura
            FROM
                detalle_factura_ventas df
            JOIN
                productos p ON df.id_producto = p.id_producto
            WHERE
                df.id_factura = ?
        ");
        $stmt_detalles->execute([$id_factura]);
        $detalles_linea = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

        foreach ($detalles_linea as $detalle) {
            $current_total_litros += $detalle['cantidad'] * $detalle['litros_por_unidad'];
            if (!in_array($detalle['porcentaje_iva_aplicado_en_factura'], $unique_ivas)) {
                $unique_ivas[] = $detalle['porcentaje_iva_aplicado_en_factura'];
            }
        }
        sort($unique_ivas);

        $base_imponible_factura = $factura['total_factura_iva_incluido'] - $factura['total_iva_factura'];

        // Añadir fila a la tabla PDF
        $pdf->Cell(15, 6, $factura['id_factura'], 1, 0, 'C');
        $pdf->Cell(20, 6, date('d/m/Y', strtotime($factura['fecha_factura'])), 1, 0, 'C');
        $pdf->Cell(45, 6, mb_convert_encoding($factura['nombre_cliente'], 'ISO-8859-1', 'UTF-8'), 1, 0, 'L');
        $pdf->Cell(25, 6, mb_convert_encoding($factura['nif'] ?: 'N/A', 'ISO-8859-1', 'UTF-8'), 1, 0, 'C');
        $pdf->Cell(25, 6, number_format($base_imponible_factura, 2, ',', '.') . ' €', 1, 0, 'R');
        $pdf->Cell(15, 6, empty($unique_ivas) ? 'N/A' : implode(', ', $unique_ivas) . '%', 1, 0, 'C');
        $pdf->Cell(25, 6, number_format($factura['total_iva_factura'], 2, ',', '.') . ' €', 1, 0, 'R');
        $pdf->Cell(25, 6, number_format($factura['total_factura_iva_incluido'], 2, ',', '.') . ' €', 1, 0, 'R');
        $pdf->Cell(20, 6, number_format($current_total_litros, 2, ',', '.') . ' L', 1, 1, 'R');

        // Sumar para los totales generales
        $total_litros_facturados_general += $current_total_litros;
        $total_base_imponible_general += $base_imponible_factura;
        $total_iva_general += $factura['total_iva_factura'];
        $total_factura_general += $factura['total_factura_iva_incluido'];
    }

    // Totales del periodo en el pie de la tabla
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(200, 220, 255); // Un azul claro para el pie
    $pdf->Cell(105, 7, mb_convert_encoding('TOTALES DEL PERIODO:', 'ISO-8859-1', 'UTF-8'), 1, 0, 'R', true); // Colspan 4
    $pdf->Cell(25, 7, number_format($total_base_imponible_general, 2, ',', '.') . ' €', 1, 0, 'R', true);
    $pdf->Cell(15, 7, '', 1, 0, 'C', true); // Columna vacía para %IVA
    $pdf->Cell(25, 7, number_format($total_iva_general, 2, ',', '.') . ' €', 1, 0, 'R', true);
    $pdf->Cell(25, 7, number_format($total_factura_general, 2, ',', '.') . ' €', 1, 0, 'R', true);
    $pdf->Cell(20, 7, number_format($total_litros_facturados_general, 2, ',', '.') . ' L', 1, 1, 'R', true);

} catch (PDOException $e) {
    // Manejo de errores en el PDF (puedes personalizarlo)
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, mb_convert_encoding('Error al generar el informe:', 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 10, mb_convert_encoding($e->getMessage(), 'ISO-8859-1', 'UTF-8'));
}

// Salida del PDF
$pdf->Output('I', 'informe_facturas_emitidas_' . $fecha_inicio . '_a_' . $fecha_fin . '.pdf');
?>