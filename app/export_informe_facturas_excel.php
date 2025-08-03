<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Variables para el filtro de fechas
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

// Configurar cabeceras para la descarga del archivo Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="informe_facturas_emitidas_' . $fecha_inicio . '_a_' . $fecha_fin . '.xls"');
header('Cache-Control: max-age=0');

// Abrir el flujo de salida para escribir en el archivo Excel
$output = fopen('php://output', 'w');

// Escribir la cabecera del informe
fputcsv($output, [utf8_decode('Informe de Facturas Emitidas')], "\t");
fputcsv($output, [utf8_decode('Periodo: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)))], "\t");
fputcsv($output, [], "\t"); // Línea en blanco

// Escribir las cabeceras de la tabla
$headers = [
    utf8_decode('Nº Fact.'),
    'Fecha',
    'Cliente',
    'NIF',
    utf8_decode('Base Imponible'),
    '% IVA',
    'Total IVA',
    utf8_decode('Total Fact.'),
    'Litros'
];
fputcsv($output, $headers, "\t");

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

        // Escribir la fila de datos
        $row_data = [
            $factura['id_factura'],
            date('d/m/Y', strtotime($factura['fecha_factura'])),
            utf8_decode($factura['nombre_cliente']),
            utf8_decode($factura['nif'] ?: 'N/A'),
            number_format($base_imponible_factura, 2, ',', ''), // Cambiado a coma como separador decimal
            empty($unique_ivas) ? 'N/A' : implode(', ', $unique_ivas) . '%',
            number_format($factura['total_iva_factura'], 2, ',', ''), // Cambiado a coma como separador decimal
            number_format($factura['total_factura_iva_incluido'], 2, ',', ''), // Cambiado a coma como separador decimal
            number_format($current_total_litros, 2, ',', '') // Cambiado a coma como separador decimal
        ];
        fputcsv($output, $row_data, "\t");

        // Sumar para los totales generales
        $total_litros_facturados_general += $current_total_litros;
        $total_base_imponible_general += $base_imponible_factura;
        $total_iva_general += $factura['total_iva_factura'];
        $total_factura_general += $factura['total_factura_iva_incluido'];
    }

    // Escribir la fila de totales
    $total_row_data = [
        '', '', '',
        utf8_decode('TOTALES DEL PERIODO:'),
        number_format($total_base_imponible_general, 2, ',', ''), // Cambiado a coma como separador decimal
        '', // Columna vacía para %IVA
        number_format($total_iva_general, 2, ',', ''), // Cambiado a coma como separador decimal
        number_format($total_factura_general, 2, ',', ''), // Cambiado a coma como separador decimal
        number_format($total_litros_facturados_general, 2, ',', '') // Cambiado a coma como separador decimal
    ];
    fputcsv($output, $total_row_data, "\t");

} catch (PDOException $e) {
    // En caso de error, escribir un mensaje en el archivo Excel
    fputcsv($output, [utf8_decode('Error al generar el informe: ' . $e->getMessage())], "\t");
}

// Cerrar el flujo de salida
fclose($output);
exit();
?>
