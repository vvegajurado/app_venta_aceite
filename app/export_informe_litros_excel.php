<?php
// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Variables para el filtro de fechas
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

// Configurar cabeceras para la descarga del archivo Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="informe_litros_vendidos_' . $fecha_inicio . '_a_' . $fecha_fin . '.xls"');
header('Cache-Control: max-age=0');

// Abrir el flujo de salida para escribir en el archivo Excel
$output = fopen('php://output', 'w');

// Escribir la cabecera del informe
fputcsv($output, [utf8_decode('Informe de Litros Vendidos por Producto y Fecha')], "\t");
fputcsv($output, [utf8_decode('Periodo: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)))], "\t");
fputcsv($output, [], "\t"); // Línea en blanco

$report_data = [];
$all_products = [];
$product_grand_totals = [];
$daily_totals = [];
$total_litros_vendidos_general = 0;

try {
    // Consulta para obtener litros vendidos por fecha y por producto
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

    // Procesar los datos para pivotar la tabla
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

    // Ordenar los productos para las columnas
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

    // Escribir las cabeceras de la tabla
    $headers = ['Fecha'];
    foreach ($sorted_product_cols as $product) {
        $headers[] = utf8_decode($product['name']);
    }
    $headers[] = 'Total Diario';
    fputcsv($output, $headers, "\t");

    // Escribir las filas de datos
    foreach ($report_data as $fecha => $productos_vendidos) {
        $row_data = [date('d/m/Y', strtotime($fecha))];
        foreach ($sorted_product_cols as $product) {
            $litros = $productos_vendidos[$product['id']] ?? 0;
            $row_data[] = number_format($litros, 2, ',', ''); // Usar coma para decimales
        }
        $row_data[] = number_format($daily_totals[$fecha], 2, ',', ''); // Usar coma para decimales
        fputcsv($output, $row_data, "\t");
    }

    // Escribir la fila de totales por producto
    fputcsv($output, [], "\t"); // Línea en blanco
    $total_row_data = [utf8_decode('TOTALES POR PRODUCTO:')];
    foreach ($sorted_product_cols as $product) {
        $total_row_data[] = number_format($product_grand_totals[$product['id']] ?? 0, 2, ',', ''); // Usar coma para decimales
    }
    $total_row_data[] = number_format($total_litros_vendidos_general, 2, ',', ''); // Usar coma para decimales
    fputcsv($output, $total_row_data, "\t");

} catch (PDOException $e) {
    fputcsv($output, [utf8_decode('Error al generar el informe: ' . $e->getMessage())], "\t");
}

// Cerrar el flujo de salida
fclose($output);
exit();
?>
