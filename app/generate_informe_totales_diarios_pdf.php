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
    private $total_base_imponible_general;
    private $total_iva_general;
    private $total_factura_general;
    private $fecha_inicio_report;
    private $fecha_fin_report;

    function setReportData($total_base_imponible_general, $total_iva_general, $total_factura_general, $fecha_inicio, $fecha_fin) {
        $this->total_base_imponible_general = $total_base_imponible_general;
        $this->total_iva_general = $total_iva_general;
        $this->total_factura_general = $total_factura_general;
        $this->fecha_inicio_report = $fecha_inicio;
        $this->fecha_fin_report = $fecha_fin;
    }

    // Cabecera de página
    function Header()
    {
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 10, mb_convert_encoding('Informe de Totales Diarios de Facturas', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 10, mb_convert_encoding('Periodo: ' . date('d/m/Y', strtotime($this->fecha_inicio_report)) . ' al ' . date('d/m/Y', strtotime($this->fecha_fin_report)), 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->Ln(5);

        // Cabecera de la tabla
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(40, 7, 'Fecha', 1, 0, 'C', true);
        $this->Cell(50, 7, mb_convert_encoding('Total Base Imponible', 'ISO-8859-1', 'UTF-8'), 1, 0, 'R', true);
        $this->Cell(40, 7, 'Total IVA', 1, 0, 'R', true);
        $this->Cell(50, 7, 'Total Factura', 1, 1, 'R', true);
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

    // Función para imprimir la fila de totales (llamada una vez al final del documento)
    function printTotalsRow() {
        $this->Ln(5); // Espacio antes de los totales
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(200, 220, 255); // Un azul claro para el pie

        $this->Cell(40, 7, mb_convert_encoding('TOTAL GENERAL:', 'ISO-8859-1', 'UTF-8'), 1, 0, 'R', true);
        $this->Cell(50, 7, number_format($this->total_base_imponible_general, 2, ',', '.') . ' €', 1, 0, 'R', true);
        $this->Cell(40, 7, number_format($this->total_iva_general, 2, ',', '.') . ' €', 1, 0, 'R', true);
        $this->Cell(50, 7, number_format($this->total_factura_general, 2, ',', '.') . ' €', 1, 1, 'R', true);
    }
}

// --- Lógica de obtención y procesamiento de datos ---
$report_data = [];
$total_base_imponible_general = 0;
$total_iva_general = 0;
$total_factura_general = 0;
$error_message = null; // Inicializar mensaje de error

try {
    $stmt_daily_totals = $pdo->prepare("
        SELECT
            fecha_factura,
            SUM(total_factura_iva_incluido - total_iva_factura) AS total_base_imponible,
            SUM(total_iva_factura) AS total_iva,
            SUM(total_factura_iva_incluido) AS total_factura
        FROM
            facturas_ventas
        WHERE
            fecha_factura BETWEEN ? AND ?
        GROUP BY
            fecha_factura
        ORDER BY
            fecha_factura ASC
    ");
    $stmt_daily_totals->execute([$fecha_inicio, $fecha_fin]);
    $raw_daily_data = $stmt_daily_totals->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_daily_data as $row) {
        $report_data[] = $row;
        $total_base_imponible_general += (float)$row['total_base_imponible'];
        $total_iva_general += (float)$row['total_iva'];
        $total_factura_general += (float)$row['total_factura'];
    }

} catch (PDOException $e) {
    $error_message = "Error de base de datos al generar el informe: " . $e->getMessage();
    $report_data = []; // Vaciar datos para no intentar imprimirlos
}

// Creación del objeto PDF
$pdf = new PDF('P', 'mm', 'A4'); // 'P' para orientación vertical
$pdf->AliasNbPages();
// Pasar los datos necesarios a la clase PDF antes de generar la cabecera
$pdf->setReportData($total_base_imponible_general, $total_iva_general, $total_factura_general, $fecha_inicio, $fecha_fin);
$pdf->AddPage(); // Esto llamará a Header()

$pdf->SetFont('Arial', '', 8);

// Si hubo un error en la obtención de datos, mostrarlo y salir
if ($error_message) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, mb_convert_encoding('Error al generar el informe:', 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 10, mb_convert_encoding($error_message, 'ISO-8859-1', 'UTF-8'));
} else {
    foreach ($report_data as $row) {
        $pdf->Cell(40, 6, date('d/m/Y', strtotime($row['fecha_factura'])), 1, 0, 'C');
        $pdf->Cell(50, 6, number_format($row['total_base_imponible'], 2, ',', '.') . ' €', 1, 0, 'R');
        $pdf->Cell(40, 6, number_format($row['total_iva'], 2, ',', '.') . ' €', 1, 0, 'R');
        $pdf->Cell(50, 6, number_format($row['total_factura'], 2, ',', '.') . ' €', 1, 1, 'R');
    }

    // Imprimir la fila de totales al final del documento
    $pdf->printTotalsRow();
}

// Salida del PDF
$pdf->Output('I', 'informe_totales_diarios_facturas_' . $fecha_inicio . '_a_' . $fecha_fin . '.pdf');
?>