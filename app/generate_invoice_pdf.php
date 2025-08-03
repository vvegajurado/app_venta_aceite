<?php
require('fpdf/fpdf.php'); // Asegúrate de que la ruta a fpdf.php sea correcta
include 'conexion.php'; // Incluir tu archivo de conexión a la base de datos

// Función para formatear números con separador de miles y decimales
function formatNumber($number, $decimals = 2) {
    return number_format($number, $decimals, ',', '.');
}

// Obtener el ID de la factura de la URL
$id_factura = $_GET['id'] ?? null;

if (!$id_factura) {
    die("ID de factura no proporcionado.");
}

// Obtener los datos de la factura
try {
    $stmt_factura = $pdo->prepare("
        SELECT
            f.id_factura,
            f.fecha_factura,
            f.total_factura,
            f.estado_pago,
            c.nombre_cliente,
            c.direccion,
            c.ciudad,
            c.provincia,
            c.codigo_postal,
            c.telefono,
            c.email,
            c.nif
        FROM
            facturas_ventas f
        JOIN
            clientes c ON f.id_cliente = c.id_cliente
        WHERE
            f.id_factura = ?
    ");
    $stmt_factura->execute([$id_factura]);
    $factura = $stmt_factura->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        die("Factura no encontrada.");
    }

    // Obtener los detalles de la factura, incluyendo id_detalle_factura
    $stmt_detalles = $pdo->prepare("
        SELECT
            df.id_detalle_factura,
            df.cantidad,
            df.precio_unitario,
            df.subtotal,
            p.nombre_producto
        FROM
            detalle_factura_ventas df
        JOIN
            productos p ON df.id_producto = p.id_producto
        WHERE
            df.id_factura = ?
        ORDER BY
            p.nombre_producto ASC
    ");
    $stmt_detalles->execute([$id_factura]);
    $detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

    // Para cada línea de detalle, obtener la información de los lotes asociados
    foreach ($detalles as &$detalle) { // Usar & para modificar el array directamente
        $stmt_lotes = $pdo->prepare("
            SELECT
                le.nombre_lote,
                dae.consecutivo_sublote
            FROM
                asignacion_lotes_ventas alv
            JOIN
                detalle_actividad_envasado dae ON alv.id_detalle_actividad = dae.id_detalle_actividad
            JOIN
                lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
            WHERE
                alv.id_detalle_factura = ?
            ORDER BY
                le.nombre_lote ASC, dae.consecutivo_sublote ASC;
        ");
        $stmt_lotes->execute([$detalle['id_detalle_factura']]);
        $lotes_asignados = $stmt_lotes->fetchAll(PDO::FETCH_ASSOC);

        $lote_info = [];
        if (!empty($lotes_asignados)) {
            foreach ($lotes_asignados as $lote) {
                $lote_info[] = "{$lote['nombre_lote']} (Sublote: {$lote['consecutivo_sublote']})";
            }
            $detalle['lote_info'] = implode(", ", $lote_info);
        } else {
            $detalle['lote_info'] = 'N/A'; // O 'Sin asignar' si no hay lotes
        }
    }
    unset($detalle); // Romper la referencia del último elemento

} catch (PDOException $e) {
    die("Error de base de datos: " . $e->getMessage());
}

class PDF extends FPDF
{
    // Propiedades de la empresa (ajustar según sea necesario)
    private $companyName = 'MOLINO DE ESPERA, S.L.';
    private $companyAddress = 'C/Veracruz 25';
    private $companyCityZip = '11648 Espera (Cádiz)';
    private $companyPhone = 'telf: 956 720 137 - 607 720 137';
    private $companyNIF = 'C.I.F.: B-11842804';
    private $companyWebsite = 'www.molinodeespera.com';
    private $logoPath = 'assets/img/logo.png'; // Ruta al logo de la empresa

    // Cabecera de página
    function Header()
    {
        // Logo
        if (file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 10, 8, 30); // X, Y, Ancho
        } else {
            // Fallback text if logo not found
            $this->SetFont('Arial', 'B', 14);
            $this->SetXY(10, 10);
            $this->Cell(30, 10, utf8_decode($this->companyName), 0, 0, 'L');
        }

        // Información de la empresa
        $this->SetFont('Arial', '', 9);
        $this->SetXY(45, 10);
        $this->Cell(0, 5, utf8_decode($this->companyName), 0, 1, 'L');
        $this->SetX(45);
        $this->Cell(0, 5, utf8_decode($this->companyAddress), 0, 1, 'L');
        $this->SetX(45);
        $this->Cell(0, 5, utf8_decode($this->companyCityZip), 0, 1, 'L');
        $this->SetX(45);
        $this->Cell(0, 5, utf8_decode($this->companyPhone), 0, 1, 'L');
        $this->SetX(45);
        $this->Cell(0, 5, utf8_decode($this->companyNIF), 0, 1, 'L');

        // Título de la factura
        $this->SetFont('Arial', 'B', 15);
        $this->SetXY(10, 40); // Ajustar posición del título
        $this->Cell(0, 10, utf8_decode('FACTURA VENTA DE ACEITE'), 0, 1, 'C');
        $this->Ln(5);
    }

    // Pie de página
    function Footer()
    {
        $this->SetY(-20); // Posición a 20 mm del final
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 5, utf8_decode('IVA INCLUIDO 2%'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode($this->companyWebsite), 0, 1, 'C');
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    // Detalles de la factura
    function InvoiceBody($factura, $detalles)
    {
        $this->SetFont('Arial', '', 10);
        $this->SetFillColor(230, 230, 230); // Color de fondo para encabezados de sección

        // Información de la Factura
        $this->SetY(60); // Ajustar posición
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(30, 7, utf8_decode('FACTURA Nº:'), 0, 0, 'L', 1);
        $this->SetFont('Arial', '', 10);
        $this->Cell(30, 7, utf8_decode($factura['id_factura']), 0, 0, 'L');
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(20, 7, utf8_decode('FECHA:'), 0, 0, 'L', 1);
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 7, utf8_decode(date('d/m/Y', strtotime($factura['fecha_factura']))), 0, 1, 'L');
        $this->Ln(5);

        // Información del Cliente
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 7, utf8_decode('Cliente'), 0, 1, 'L', 1);
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode($factura['nombre_cliente']), 0, 1, 'L');
        $this->Cell(0, 5, utf8_decode($factura['direccion']), 0, 1, 'L');
        $this->Cell(0, 5, utf8_decode($factura['codigo_postal'] . ' ' . $factura['ciudad']), 0, 1, 'L');
        $this->Cell(0, 5, utf8_decode($factura['provincia']), 0, 1, 'L');
        $this->Cell(0, 5, utf8_decode($factura['nif']), 0, 1, 'L');
        $this->Ln(10);

        // Cabecera de la tabla de detalles
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(200, 220, 255); // Color de fondo para la tabla
        // Anchos de columna ajustados para incluir "Lote"
        $w = [60, 40, 25, 30, 35]; // Producto, Lote, Cantidad, Precio Unitario, Total
        $this->Cell($w[0], 7, utf8_decode('Producto'), 1, 0, 'C', 1);
        $this->Cell($w[1], 7, utf8_decode('Lote'), 1, 0, 'C', 1); // Nueva columna para Lote
        $this->Cell($w[2], 7, utf8_decode('Cantidad'), 1, 0, 'C', 1);
        $this->Cell($w[3], 7, utf8_decode('Precio Unitario'), 1, 0, 'C', 1);
        $this->Cell($w[4], 7, utf8_decode('Total'), 1, 1, 'C', 1); // Salto de línea

        // Filas de la tabla de detalles
        $this->SetFont('Arial', '', 10);
        $this->SetFillColor(255, 255, 255); // Fondo blanco para las filas
        foreach ($detalles as $detalle) {
            // Guardar posición actual para MultiCell
            $x = $this->GetX();
            $y = $this->GetY();

            // Producto (MultiCell para permitir saltos de línea si el nombre es largo)
            $this->MultiCell($w[0], 7, utf8_decode($detalle['nombre_producto']), 1, 'L', 1);
            // Volver a la posición para la siguiente celda
            $this->SetXY($x + $w[0], $y);

            // Lote (MultiCell para permitir saltos de línea si la info del lote es larga)
            $x_lote = $this->GetX();
            $y_lote = $this->GetY();
            $this->MultiCell($w[1], 7, utf8_decode($detalle['lote_info']), 1, 'L', 1);
            $this->SetXY($x_lote + $w[1], $y_lote);

            // Cantidad, Precio Unitario, Total
            $this->Cell($w[2], 7, formatNumber($detalle['cantidad']), 1, 0, 'R', 1);
            $this->Cell($w[3], 7, formatNumber($detalle['precio_unitario']) . ' €', 1, 0, 'R', 1);
            $this->Cell($w[4], 7, formatNumber($detalle['subtotal']) . ' €', 1, 1, 'R', 1);
        }
        $this->Ln(10);

        // Totales
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(array_sum($w) - $w[4], 7, utf8_decode('SUBTOTAL:'), 0, 0, 'R'); // Ajustar ancho para subtotales
        $this->Cell($w[4], 7, formatNumber($factura['total_factura']) . ' €', 0, 1, 'R');
        $this->Cell(array_sum($w) - $w[4], 7, utf8_decode('DTO:'), 0, 0, 'R'); // Ajustar ancho para DTO
        $this->Cell($w[4], 7, formatNumber(0.00) . ' €', 0, 1, 'R'); // Asumiendo DTO 0.00 por ahora
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(array_sum($w) - $w[4], 7, utf8_decode('TOTAL:'), 0, 0, 'R'); // Ajustar ancho para TOTAL
        $this->Cell($w[4], 7, formatNumber($factura['total_factura']) . ' €', 0, 1, 'R');
    }
}

// Creación del PDF
$pdf = new PDF();
$pdf->AliasNbPages(); // Necesario para {nb} en el pie de página
$pdf->AddPage();
$pdf->InvoiceBody($factura, $detalles);
$pdf->Output('I', 'Factura_' . $factura['id_factura'] . '.pdf'); // 'I' para mostrar en el navegador
?>
