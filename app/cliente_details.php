<?php
// Este archivo está diseñado para ser cargado dinámicamente en un modal.
// Por lo tanto, solo debe generar el contenido HTML del modal (sin <html>, <head>, <body> tags).

// Incluir el archivo de conexión a la base de datos
include 'conexion.php';

// Incluir el archivo de verificación de autenticación al inicio de cada página protegida
// Asegúrate de que este archivo no redirija si se carga vía AJAX, o maneja la redirección de forma adecuada.
include 'auth_check.php';

// Iniciar sesión para gestionar mensajes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$mensaje = '';
$tipo_mensaje = '';
$cliente_data = null;
$facturas_cliente = [];
$total_deuda_cliente = 0; // Inicializar la deuda total del cliente
$total_facturado_general = 0; // Total general de facturas con IVA incluido
$total_cobrado_general = 0; // Total general de cobros
$total_litros_aceite_general = 0; // Total general de litros de aceite

// Obtener el ID del cliente de la URL (para la carga inicial del modal)
$id_cliente_param = $_GET['id'] ?? null;

// --- Lógica para procesar el formulario de actualización de cliente ---
// Esta parte se ejecutará cuando el formulario dentro del modal se envíe.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'update_cliente') {
    $id_cliente = $_POST['id_cliente'] ?? null;
    $nombre_cliente = trim($_POST['nombre_cliente'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $ciudad = trim($_POST['ciudad'] ?? '');
    $provincia = trim($_POST['provincia'] ?? '');
    $codigo_postal = trim($_POST['codigo_postal'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $nif = trim($_POST['nif'] ?? '');

    if (empty($id_cliente) || empty($nombre_cliente)) {
        $mensaje = "Error: ID de cliente y nombre son obligatorios.";
        $tipo_mensaje = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE clientes SET nombre_cliente = ?, direccion = ?, ciudad = ?, provincia = ?, codigo_postal = ?, telefono = ?, email = ?, nif = ? WHERE id_cliente = ?");
            $stmt->execute([$nombre_cliente, $direccion, $ciudad, $provincia, $codigo_postal, $telefono, $email, $nif, $id_cliente]);
            $mensaje = "Datos del cliente actualizados correctamente.";
            $tipo_mensaje = 'success';
            // Para asegurar que los datos mostrados son los actualizados, re-establecemos el parámetro
            $id_cliente_param = $id_cliente; 
        } catch (PDOException $e) {
            $mensaje = "Error al actualizar cliente: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// --- Lógica para cargar los datos del cliente y sus facturas ---
// Esto se ejecuta tanto en la carga inicial como después de una actualización.
if ($id_cliente_param) {
    try {
        // Cargar detalles del cliente
        $stmt_cliente = $pdo->prepare("SELECT * FROM clientes WHERE id_cliente = ?");
        $stmt_cliente->execute([$id_cliente_param]);
        $cliente_data = $stmt_cliente->fetch(PDO::FETCH_ASSOC);

        if (!$cliente_data) {
            $mensaje = "Cliente no encontrado.";
            $tipo_mensaje = 'danger';
        } else {
            // Cargar facturas del cliente, incluyendo total_iva_incluido y total_litros_aceite
            // total_litros_aceite se calcula sumando la cantidad de los productos que son 'Litros'
            $stmt_facturas = $pdo->prepare("
                SELECT
                    fv.id_factura,
                    fv.fecha_factura,
                    fv.total_factura_iva_incluido,
                    fv.estado_pago,
                    COALESCE(SUM(dfv.cantidad * COALESCE(p.litros_por_unidad, 0)), 0) AS total_litros_aceite
                FROM
                    facturas_ventas fv
                LEFT JOIN
                    detalle_factura_ventas dfv ON fv.id_factura = dfv.id_factura
                LEFT JOIN
                    productos p ON dfv.id_producto = p.id_producto
                WHERE
                    fv.id_cliente = ?
                GROUP BY
                    fv.id_factura, fv.fecha_factura, fv.total_factura_iva_incluido, fv.estado_pago
                ORDER BY
                    fv.id_factura DESC
            ");
            $stmt_facturas->execute([$id_cliente_param]);
            $facturas_cliente = $stmt_facturas->fetchAll(PDO::FETCH_ASSOC);

            // Calcular la deuda total del cliente y los totales generales
            $total_facturado_cliente = 0; // Representa el total facturado con IVA para este cliente
            $total_cobrado_cliente = 0; // Representa el total cobrado para este cliente

            foreach ($facturas_cliente as &$factura) { // Usar & para modificar el array original
                // Sumar al total facturado general (IVA incluido)
                $total_facturado_general += $factura['total_factura_iva_incluido'];

                // Sumar al total de litros de aceite general
                $total_litros_aceite_general += $factura['total_litros_aceite'];

                // Obtener los cobros para cada factura (sumando solo cantidad_cobrada)
                $stmt_cobros_factura = $pdo->prepare("SELECT COALESCE(SUM(cantidad_cobrada), 0) AS total_cobrado_invoice FROM cobros_factura WHERE id_factura = ?");
                $stmt_cobros_factura->execute([$factura['id_factura']]);
                $cobro_invoice = $stmt_cobros_factura->fetch(PDO::FETCH_ASSOC);
                
                // Añadir el total cobrado de esta factura al array de la factura para fácil acceso en la tabla
                $factura['total_cobrado_invoice'] = $cobro_invoice['total_cobrado_invoice'];
                
                // Sumar al total cobrado general del cliente
                $total_cobrado_general += $cobro_invoice['total_cobrado_invoice'];
            }
            unset($factura); // Romper la referencia al último elemento

            // Calcular la deuda total final del cliente
            $total_deuda_cliente = $total_facturado_general - $total_cobrado_general;
        }
    } catch (PDOException $e) {
        $mensaje = "Error de base de datos al cargar datos: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
} else {
    $mensaje = "ID de cliente no proporcionado.";
    $tipo_mensaje = 'danger';
}
?>

<!-- Contenido del modal que será cargado en facturas_ventas.php -->
<div class="modal-header bg-primary text-white">
    <h5 class="modal-title" id="clientDetailsModalLabel">Detalles y Facturas del Cliente: <?php echo htmlspecialchars($cliente_data['nombre_cliente'] ?? 'N/A'); ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($mensaje); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($cliente_data): ?>
        <h5 class="mb-3">Datos del Cliente</h5>
        <form id="editClientForm" action="cliente_details.php?id=<?php echo htmlspecialchars($cliente_data['id_cliente']); ?>" method="POST">
            <input type="hidden" name="accion" value="update_cliente">
            <input type="hidden" name="id_cliente" value="<?php echo htmlspecialchars($cliente_data['id_cliente']); ?>">
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label for="nombre_cliente" class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="nombre_cliente" name="nombre_cliente" value="<?php echo htmlspecialchars($cliente_data['nombre_cliente']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="nif" class="form-label">NIF</label>
                    <input type="text" class="form-control" id="nif" name="nif" value="<?php echo htmlspecialchars($cliente_data['nif'] ?? ''); ?>">
                </div>
                <div class="col-md-12">
                    <label for="direccion" class="form-label">Dirección</label>
                    <input type="text" class="form-control" id="direccion" name="direccion" value="<?php echo htmlspecialchars($cliente_data['direccion'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label for="ciudad" class="form-label">Ciudad</label>
                    <input type="text" class="form-control" id="ciudad" name="ciudad" value="<?php echo htmlspecialchars($cliente_data['ciudad'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label for="provincia" class="form-label">Provincia</label>
                    <input type="text" class="form-control" id="provincia" name="provincia" value="<?php echo htmlspecialchars($cliente_data['provincia'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label for="codigo_postal" class="form-label">C.P.</label>
                    <input type="text" class="form-control" id="codigo_postal" name="codigo_postal" value="<?php echo htmlspecialchars($cliente_data['codigo_postal'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label for="telefono" class="form-label">Teléfono</label>
                    <input type="text" class="form-control" id="telefono" name="telefono" value="<?php echo htmlspecialchars($cliente_data['telefono'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($cliente_data['email'] ?? ''); ?>">
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Guardar Cambios</button>
                <?php
                // Determinar la clase de color para la deuda
                $deuda_class = ($total_deuda_cliente > 0) ? 'text-danger' : 'text-success';
                ?>
                <p class="mb-0 <?php echo $deuda_class; ?> fs-5">
                    <strong>Deuda Total:</strong> <?php echo number_format($total_deuda_cliente, 2, ',', '.'); ?> €
                </p>
            </div>
        </form>

        <hr class="my-4">

        <h5 class="mb-3">Facturas del Cliente</h5>
        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;"> <!-- Estilo para la barra de desplazamiento -->
            <table class="table table-striped table-hover table-sm">
                <thead>
                    <tr>
                        <th>ID Factura</th>
                        <th>Fecha</th>
                        <th class="text-end">Total (IVA Inc.)</th> <!-- Columna para total con IVA -->
                        <th class="text-end">Litros Aceite</th> <!-- Nueva columna para litros de aceite -->
                        <th>Estado Pago</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($facturas_cliente)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No hay facturas para este cliente.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($facturas_cliente as $factura):
                            $badge_class_pago = '';
                            switch ($factura['estado_pago']) {
                                case 'pendiente':
                                    $badge_class_pago = 'bg-warning text-dark';
                                    break;
                                case 'pagada':
                                    $badge_class_pago = 'bg-success';
                                    break;
                                case 'parcialmente_pagada':
                                    $badge_class_pago = 'bg-info';
                                    break;
                            }
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($factura['id_factura']); ?></td>
                                <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($factura['fecha_factura']))); ?></td>
                                <td class="text-end"><?php echo number_format($factura['total_factura_iva_incluido'], 2, ',', '.'); ?> €</td>
                                <td class="text-end"><?php echo number_format($factura['total_litros_aceite'], 2, ',', '.'); ?> L</td>
                                <td><span class="badge <?php echo htmlspecialchars($badge_class_pago); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $factura['estado_pago']))); ?></span></td>
                                <td class="text-center">
                                    <a href="facturas_ventas.php?view=details&id=<?php echo htmlspecialchars($factura['id_factura']); ?>" class="btn btn-info btn-sm" target="_blank" title="Ver Detalles de Factura">
                                        <i class="bi bi-eye"></i> Ver
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" class="text-end">Totales:</th>
                        <th class="text-end"><?php echo number_format($total_facturado_general, 2, ',', '.'); ?> €</th>
                        <th class="text-end"><?php echo number_format($total_litros_aceite_general, 2, ',', '.'); ?> L</th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php else: ?>
        <p class="text-danger">No se pudo cargar la información del cliente.</p>
    <?php endif; ?>
</div>

<script>
    // Este script se ejecutará cada vez que el contenido del modal sea cargado.
    // Es crucial para re-adjuntar listeners a elementos dinámicos.
    document.getElementById('editClientForm').addEventListener('submit', async function(event) {
        event.preventDefault(); // Evitar el envío de formulario tradicional

        const formData = new FormData(this);
        const clientId = formData.get('id_cliente');

        try {
            // Enviamos la solicitud POST a este mismo archivo (cliente_details.php)
            const response = await fetch('cliente_details.php?id=' + clientId, {
                method: 'POST',
                body: formData
            });
            const responseText = await response.text(); // Obtenemos el HTML de vuelta

            // Reemplazamos el contenido del modal con la respuesta actualizada
            document.getElementById('clientDetailsModalBody').innerHTML = responseText;

            // Re-inicializamos los componentes de Bootstrap si es necesario (ej. alertas)
            const alertElement = document.querySelector('#clientDetailsModalBody .alert');
            if (alertElement) {
                new bootstrap.Alert(alertElement);
            }

            // Si necesitas actualizar el nombre del cliente en la página principal (facturas_ventas.php)
            // sin recargarla completamente, tendrías que enviar un evento personalizado
            // o hacer otra llamada AJAX para obtener el nombre actualizado y modificar el DOM.
            // Por simplicidad, aquí solo se actualiza el contenido del modal.

        } catch (error) {
            console.error('Error al actualizar cliente desde el modal:', error);
            // Mostrar un mensaje de error dentro del modal
            const modalBody = document.getElementById('clientDetailsModalBody');
            modalBody.innerHTML = `<div class="modal-header bg-danger text-white"><h5 class="modal-title">Error</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="text-danger">Error al guardar los cambios del cliente: ${error.message}</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>`;
        }
    });
</script>
