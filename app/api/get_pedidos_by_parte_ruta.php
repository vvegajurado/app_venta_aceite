<?php
// api/get_pedidos_by_parte_ruta.php

// Incluir el archivo de conexión a la base de datos
// Asegúrate de que 'conexion.php' inicializa $pdo
include '../conexion.php';

header('Content-Type: application/json'); // Indicar que la respuesta será JSON

$response = [
    'success' => false,
    'message' => '',
    'pedidos' => []
];

// Verificar si se recibió el parámetro id_parte_ruta
if (!isset($_GET['id_parte_ruta']) || empty($_GET['id_parte_ruta'])) {
    $response['message'] = "ID de parte de ruta no proporcionado.";
    echo json_encode($response);
    exit();
}

$id_parte_ruta = $_GET['id_parte_ruta'];

try {
    // Preparar la consulta para obtener los pedidos asociados a este parte de ruta
    // y la información del cliente, incluyendo latitud y longitud.
    $stmt = $pdo->prepare("
        SELECT
            p.id_pedido,
            c.nombre_cliente,
            p.fecha_pedido,
            p.total_pedido,
            p.estado_pedido,
            c.latitud,
            c.longitud
        FROM
            partes_ruta_pedidos prp
        JOIN
            pedidos p ON prp.id_pedido = p.id_pedido
        JOIN
            clientes c ON p.id_cliente = c.id_cliente
        WHERE
            prp.id_parte_ruta = ?
        ORDER BY
            p.fecha_pedido ASC
    ");
    $stmt->execute([$id_parte_ruta]);
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($pedidos) {
        $response['success'] = true;
        $response['message'] = "Pedidos obtenidos exitosamente.";
        $response['pedidos'] = $pedidos;
    } else {
        $response['message'] = "No se encontraron pedidos para el parte de ruta ID: " . $id_parte_ruta;
    }

} catch (PDOException $e) {
    $response['message'] = "Error de base de datos: " . $e->getMessage();
}

echo json_encode($response);
?>
