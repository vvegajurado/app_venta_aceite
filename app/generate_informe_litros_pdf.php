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
    private $sorted_product_cols;
    private $product_grand_totals;
    private $total_litros_vendidos_general;
    private $fecha_inicio_report;
    private $fecha_fin_report;

    // Propiedades para almacenar los anchos de columna calculados
    private $col_width_fecha;
    private $col_width_total_diario;
    private $col_width_product;

    function setReportData($sorted_product_cols, $product_grand_totals, $total_litros_vendidos_general, $fecha_inicio, $fecha_fin) {
        $this->sorted_product_cols = $sorted_product_cols;
        $this->product_grand_totals = $product_grand_totals;
        $this->total_litros_vendidos_general = $total_litros_vendidos_general;
        $this->fecha_inicio_report = $fecha_inicio;
        $this->fecha_fin_report = $fecha_fin;
    }

    // Cabecera de página
    function Header()
    {
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 10, mb_convert_encoding('Informe de Litros Vendidos por Producto y Fecha', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 10, mb_convert_encoding('Periodo: ' . date('d/m/Y', strtotime($this->fecha_inicio_report)) . ' al ' . date('d/m/Y', strtotime($this->fecha_fin_report)), 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->Ln(5);

        // Calcular anchos de columna dinámicamente y almacenarlos en propiedades de la clase
        $this->col_width_fecha = 20; // Ancho fijo para la fecha
        $this->col_width_total_diario = 20; // Ancho fijo para el total diario
        $num_products = count($this->sorted_product_cols);
        $remaining_width = $this->GetPageWidth() - $this->lMargin - $this->rMargin - $this->col_width_fecha - $this->col_width_total_diario;
        $this->col_width_product = $num_products > 0 ? $remaining_width / $num_products : 0;

        // Ajustar ancho mínimo para productos si es necesario
        if ($this->col_width_product < 15) {
            $this->col_width_product = 15;
        }

        // Fila de cabeceras
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230);
        $this->Cell($this->col_width_fecha, 7, 'Fecha', 1, 0, 'C', true);
        foreach ($this->sorted_product_cols as $product) {
            $this->Cell($this->col_width_product, 7, mb_convert_encoding($product['name'], 'ISO-8859-1', 'UTF-8'), 1, 0, 'C', true);
        }
        $this->Cell($this->col_width_total_diario, 7, 'Total Diario', 1, 1, 'R', true);
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
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(200, 220, 255); // Un azul claro para el pie

        // Usar los anchos de columna ya calculados y almacenados
        $this->Cell($this->col_width_fecha, 7, mb_convert_encoding('TOTALES POR PRODUCTO:', 'ISO-8859-1', 'UTF-8'), 1, 0, 'R', true);
        foreach ($this->sorted_product_cols as $product) {
            $this->Cell($this->col_width_product, 7, number_format($this->product_grand_totals[$product['id']] ?? 0, 2, ',', '.') . ' L', 1, 0, 'R', true);
        }
        $this->Cell($this->col_width_total_diario, 7, number_format($this->total_litros_vendidos_general, 2, ',', '.') . ' L', 1, 1, 'R', true);
    }

    // Método público para obtener los anchos de columna para el bucle principal
    public function getColumnWidths() {
        return [
            'fecha' => $this->col_width_fecha,
            'product' => $this->col_width_product,
            'total_diario' => $this->col_width_total_diario
        ];
    }
}

// --- Lógica de obtención y procesamiento de datos ---
$report_data = [];
$all_products = [];
$product_grand_totals = [];
$daily_totals = [];
$total_litros_vendidos_general = 0;
$error_message = null; // Inicializar mensaje de error

try {
    $stmt_sales = $pdo->prepare("
        SELECT
            f.fecha_factura,
            p.id_producto,
            p.nombre_producto,
            SUM(df.cantidad * p.litros_por_unidad) AS litros_vendidos_dia_producto
        FROM
            facturas_ventas f
        JOIN
            detalle_factura_ventas df ON f.id_factura = df.id_factura
        JOIN
            productos p ON df.id_producto = p.id_producto
        WHERE
            f.fecha_factura BETWEEN ? AND ?
        GROUP BY
            f.fecha_factura, p.id_producto, p.nombre_producto
        ORDER BY
            f.fecha_factura ASC, p.id_producto ASC
    ");
    $stmt_sales->execute([$fecha_inicio, $fecha_fin]);
    $raw_sales_data = $stmt_sales->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_sales_data as $row) {
        $fecha = $row['fecha_factura'];
        $id_producto = $row['id_producto'];
        $nombre_producto = $row['nombre_producto'];
        $litros_vendidos = (float)$row['litros_vendidos_dia_producto'];

        if (!isset($report_data[$fecha])) {
            $report_data[$fecha] = [];
            $daily_totals[$fecha] = 0;
        }
        $report_data[$fecha][$id_producto] = $litros_vendidos;

        if (!isset($all_products[$id_producto])) {
            $all_products[$id_producto] = ['id' => $id_producto, 'name' => $nombre_producto];
        }

        $daily_totals[$fecha] += $litros_vendidos;
        $product_grand_totals[$id_producto] = ($product_grand_totals[$id_producto] ?? 0) + $litros_vendidos;
        $total_litros_vendidos_general += $litros_vendidos;
    }

    $custom_product_order_ids = [1, 6, 7, 3, 4, 5];
    $ordered_product_cols = [];
    $other_product_cols = [];

    foreach ($all_products as $prod_info) {
        if (in_array($prod_info['id'], $custom_product_order_ids)) {
            $ordered_product_cols[$prod_info['id']] = $prod_info;
        } else {
            $other_product_cols[] = $prod_info;
        }
    }

    $sorted_product_cols = [];
    foreach ($custom_product_order_ids as $id) {
        if (isset($ordered_product_cols[$id])) {
            $sorted_product_cols[] = $ordered_product_cols[$id];
        }
    }
    usort($other_product_cols, function($a, $b) {
        return $a['name'] <=> $b['name'];
    });
    $sorted_product_cols = array_merge($sorted_product_cols, $other_product_cols);

    ksort($report_data);

} catch (PDOException $e) {
    $error_message = "Error de base de datos al generar el informe: " . $e->getMessage();
    $report_data = []; // Vaciar datos para no intentar imprimirlos
}

// Creación del objeto PDF
$pdf = new PDF('L', 'mm', 'A4'); // 'L' para orientación horizontal
$pdf->AliasNbPages();
// Pasar los datos necesarios a la clase PDF antes de generar la cabecera
$pdf->setReportData($sorted_product_cols, $product_grand_totals, $total_litros_vendidos_general, $fecha_inicio, $fecha_fin);
$pdf->AddPage(); // Esto llamará a Header() donde se calculan los anchos de columna

$pdf->SetFont('Arial', '', 8);

// Si hubo un error en la obtención de datos, mostrarlo y salir
if ($error_message) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, mb_convert_encoding('Error al generar el informe:', 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 10, mb_convert_encoding($error_message, 'ISO-8859-1', 'UTF-8'));
} else {
    // Obtener los anchos de columna calculados desde la clase PDF
    $col_widths = $pdf->getColumnWidths();
    $col_width_fecha = $col_widths['fecha'];
    $col_width_product = $col_widths['product'];
    $col_width_total_diario = $col_widths['total_diario'];

    foreach ($report_data as $fecha => $productos_vendidos) {
        $pdf->Cell($col_width_fecha, 6, date('d/m/Y', strtotime($fecha)), 1, 0, 'C');
        foreach ($sorted_product_cols as $product) {
            $litros = $productos_vendidos[$product['id']] ?? 0;
            $pdf->Cell($col_width_product, 6, number_format($litros, 2, ',', '.'), 1, 0, 'R');
        }
        $pdf->Cell($col_width_total_diario, 6, number_format($daily_totals[$fecha], 2, ',', '.'), 1, 1, 'R');
    }

    // Imprimir la fila de totales al final del documento
    $pdf->printTotalsRow();
}

// Salida del PDF
$pdf->Output('I', 'informe_litros_vendidos_' . $fecha_inicio . '_a_' . $fecha_fin . '.pdf');
?>