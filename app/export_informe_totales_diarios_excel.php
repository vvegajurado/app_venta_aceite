<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Variables para el filtro de fechas
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

// Configurar cabeceras para la descarga del archivo Excel
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment;filename="informe_totales_diarios_facturas_' . $fecha_inicio . '_a_' . $fecha_fin . '.csv"');
header('Cache-Control: max-age=0');

// Abrir el flujo de salida para escribir en el archivo Excel
$output = fopen('php://output', 'w');

// Añadir BOM de UTF-8 para compatibilidad con Excel
fwrite($output, "\xEF\xBB\xBF");

// Escribir la cabecera del informe
fwrite($output, "Informe de Totales Diarios de Facturas\n");
fwrite($output, 'Periodo: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)) . "\n");
fwrite($output, "\n"); // Línea en blanco

// Escribir las cabeceras de la tabla
$headers = [
    'Fecha',
    'Total Base Imponible',
    'Total IVA',
    'Total Factura'
];
fwrite($output, implode(";", $headers) . "\n");

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
        fwrite($output, implode(";", $row_data) . "\n");
    }

    // Escribir la fila de totales generales
    fwrite($output, "\n"); // Línea en blanco antes de los totales
    $total_row_data = [
        'TOTAL GENERAL:',
        number_format($total_base_imponible_general, 2, ',', ''),
        number_format($total_iva_general, 2, ',', ''),
        number_format($total_factura_general, 2, ',', '')
    ];
    fwrite($output, implode(";", $total_row_data) . "\n");

} catch (PDOException $e) {
    fwrite($output, 'Error al generar el informe: ' . $e->getMessage() . "\n");
}

// Cerrar el flujo de salida
fclose($output);
exit();
?>
