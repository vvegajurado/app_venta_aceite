<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Variables para el filtro de fechas
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

// Configurar cabeceras para la descarga del archivo Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="informe_totales_diarios_facturas_' . $fecha_inicio . '_a_' . $fecha_fin . '.xls"');
header('Cache-Control: max-age=0');

// Abrir el flujo de salida para escribir en el archivo Excel
$output = fopen('php://output', 'w');

// Escribir la cabecera del informe
fputcsv($output, [utf8_decode('Informe de Totales Diarios de Facturas')], "\t");
fputcsv($output, [utf8_decode('Periodo: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)))], "\t");
fputcsv($output, [], "\t"); // Línea en blanco

// Escribir las cabeceras de la tabla
$headers = [
    'Fecha',
    utf8_decode('Total Base Imponible'),
    'Total IVA',
    'Total Factura'
];
fputcsv($output, array_map('utf8_decode', $headers), "\t");

$report_data = [];
$total_base_imponible_general = 0;
$total_iva_general = 0;
$total_factura_general = 0;

try {
    // Consulta para obtener los totales agrupados por fecha
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

    // Escribir las filas de datos
    foreach ($report_data as $row) {
        $row_data = [
            date('d/m/Y', strtotime($row['fecha_factura'])),
            number_format($row['total_base_imponible'], 2, ',', ''), // Usar coma para decimales
            number_format($row['total_iva'], 2, ',', ''), // Usar coma para decimales
            number_format($row['total_factura'], 2, ',', '') // Usar coma para decimales
        ];
        fputcsv($output, $row_data, "\t");
    }

    // Escribir la fila de totales generales
    fputcsv($output, [], "\t"); // Línea en blanco antes de los totales
    $total_row_data = [
        utf8_decode('TOTAL GENERAL:'),
        number_format($total_base_imponible_general, 2, ',', ''),
        number_format($total_iva_general, 2, ',', ''),
        number_format($total_factura_general, 2, ',', '')
    ];
    fputcsv($output, $total_row_data, "\t");

} catch (PDOException $e) {
    fputcsv($output, [utf8_decode('Error al generar el informe: ' . $e->getMessage())], "\t");
}

// Cerrar el flujo de salida
fclose($output);
exit();
?>
