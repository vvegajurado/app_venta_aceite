<?php
// Habilitar la visualización de errores para depuración (¡DESACTIVAR EN PRODUCCIÓN!
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir el archivo de verificación de autenticación al inicio de cada página protegida
include 'auth_check.php';

// Incluir el archivo de conexión a la base de datos
// Este archivo debe inicializar la variable $pdo (objeto PDO)
include 'conexion.php';

// Iniciar sesión para gestionar mensajes
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Inicializar variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// Variables para precargar el formulario
$factura_a_editar = null;
$cliente_precargado = null;
// NEW: Variables para precargar desde pedido
$preselect_client_id = $_GET['new_invoice_client_id'] ?? null;
$preselect_client_name = $_GET['new_invoice_client_name'] ?? '';
$preselect_order_id = $_GET['new_invoice_order_id'] ?? null;
$preselect_parte_ruta_id = $_GET['new_invoice_parte_ruta_id'] ?? null; // NEW: Get parte_ruta ID

// NEW: Initialize filter variables for list view (these are now removed from UI, but kept for potential backend filtering if needed)
$filter_estado_pago = $_GET['filter_pago'] ?? '';
$filter_estado_retiro = $_GET['filter_retiro'] ?? '';


// --- NEW LOGIC: Create Invoice from Order on Page Load if parameters exist ---
// This block will execute only if the page is loaded via GET with client and order IDs.
// It will create the invoice and then redirect to its details view.
if ($preselect_client_id && $preselect_order_id && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo->beginTransaction();

        // Check if the order is already associated with an invoice
        $stmt_check_order_invoiced = $pdo->prepare("SELECT id_factura_asociada FROM pedidos WHERE id_pedido = ?");
        $stmt_check_order_invoiced->execute([$preselect_order_id]);
        $existing_invoice_id = $stmt_check_order_invoiced->fetchColumn();

        if ($existing_invoice_id) {
            $pdo->rollBack();
            mostrarMensaje("Error: El pedido " . htmlspecialchars($preselect_order_id) . " ya ha sido facturado con la factura ID " . htmlspecialchars($existing_invoice_id) . ".", "danger");
            // Redirect back to the parte de ruta if possible, otherwise to the invoice list
            if ($preselect_parte_ruta_id) {
                header("Location: partes_ruta.php?view=details&id=" . htmlspecialchars($preselect_parte_ruta_id));
            } else {
                header("Location: facturas_ventas.php");
            }
            exit();
        }

        // Fetch order details including global discounts and surcharges from the 'pedidos' table
        $stmt_order = $pdo->prepare("
            SELECT
                p.id_cliente,
                p.id_direccion_envio, -- NEW: Fetch shipping address ID from order
                p.fecha_pedido,
                p.descuento_global_aplicado,          -- Fetch global discount from order
                p.recargo_global_aplicado,            -- Fetch global surcharge from order
                p.id_ruta,                            -- Fetch route from order for backorder
                p.tipo_entrega,                       -- Fetch delivery type
                p.empresa_transporte,                 -- Fetch carrier
                p.numero_seguimiento,                 -- Fetch tracking number
                p.observaciones                       -- Fetch observations
            FROM pedidos p
            WHERE p.id_pedido = ?
            FOR UPDATE -- Lock the order row as we will modify it
        ");
        $stmt_order->execute([$preselect_order_id]);
        $order_details = $stmt_order->fetch(PDO::FETCH_ASSOC);

        if (!$order_details) {
            throw new Exception("Pedido no encontrado para crear factura.");
        }

        // 2. Fetch order details from detalle_pedidos, and product info
        $stmt_order_details = $pdo->prepare("
            SELECT
                dp.id_detalle_pedido,
                dp.id_producto,
                dp.cantidad_solicitada AS cantidad,
                dp.precio_unitario_venta,
                dp.descuento_porcentaje,
                dp.recargo_porcentaje,
                dp.iva_porcentaje,
                dp.observaciones_detalle,
                p.nombre_producto,
                p.litros_por_unidad,
                p.stock_actual_unidades -- Get current stock directly
            FROM
                detalle_pedidos dp
            JOIN
                productos p ON dp.id_producto = p.id_producto
            WHERE dp.id_pedido = ?
        ");
        $stmt_order_details->execute([$preselect_order_id]);
        $order_details_lines = $stmt_order_details->fetchAll(PDO::FETCH_ASSOC);

        $lines_to_invoice = [];
        $lines_for_backorder = []; // Array to store lines for a new backorder
        $any_invoiced_units = false; // Flag to check if any units were actually invoiced

        // Set precision for bcmath operations
        bcscale(4);

        // Process each order detail line to determine what to invoice and what to backorder
        foreach ($order_details_lines as $detail_line) {
            $id_producto = $detail_line['id_producto'];
            $cantidad_solicitada_original = (int)$detail_line['cantidad']; // Original requested quantity
            $stock_disponible = (int)($detail_line['stock_actual_unidades'] ?? 0); // Use stock from product table

            $cantidad_a_facturar = min($cantidad_solicitada_original, $stock_disponible);
            $cantidad_para_backorder = $cantidad_solicitada_original - $cantidad_a_facturar;

            // Add to lines to invoice if any quantity can be factured
            if ($cantidad_a_facturar > 0) {
                $lines_to_invoice[] = [
                    'id_producto' => $id_producto,
                    'cantidad' => $cantidad_a_facturar,
                    'precio_unitario_venta' => (float)$detail_line['precio_unitario_venta'], // IVA included
                    'iva_porcentaje' => (float)$detail_line['iva_porcentaje'],
                    'nombre_producto' => $detail_line['nombre_producto'],
                    'original_detalle_pedido_id' => $detail_line['id_detalle_pedido'] // Keep original detail ID for update
                ];
                $any_invoiced_units = true;
            }

            // If there's a remaining quantity, add it to the backorder lines
            if ($cantidad_para_backorder > 0) {
                $lines_for_backorder[] = [
                    'id_producto' => $id_producto,
                    'cantidad' => $cantidad_para_backorder,
                    'precio_unitario_venta' => (float)$detail_line['precio_unitario_venta'],
                    'descuento_porcentaje' => (float)$detail_line['descuento_porcentaje'],
                    'recargo_porcentaje' => (float)$detail_line['recargo_porcentaje'],
                    'iva_porcentaje' => (float)$detail_line['iva_porcentaje'],
                    'observaciones_detalle' => $detail_line['observaciones_detalle'],
                    'nombre_producto' => $detail_line['nombre_producto'] // For warning messages
                ];
            }
        }

        $new_invoice_id = null;
        $final_message = "";
        $final_message_type = "success";
        $redirect_url = "facturas_ventas.php"; // Default redirect

        if ($any_invoiced_units) { // Only create invoice if at least one unit was invoiced
            $stmt_insert_invoice = $pdo->prepare("
                INSERT INTO facturas_ventas (
                    fecha_factura,
                    id_cliente,
                    id_direccion_envio, -- NEW: Insert shipping address ID
                    total_factura,
                    estado_pago,
                    descuento_global_aplicado,
                    recargo_global_aplicado,
                    total_iva_factura,
                    total_factura_iva_incluido,
                    id_pedido_origen,
                    id_parte_ruta_origen
                ) VALUES (?, ?, ?, 0.00, 'pendiente', ?, ?, 0.00, 0.00, ?, ?)
            ");
            $stmt_insert_invoice->execute([
                date('Y-m-d'), // Use current date for invoice
                $order_details['id_cliente'],
                $order_details['id_direccion_envio'], // NEW: Pass shipping address ID
                round((float)$order_details['descuento_global_aplicado'], 2),   // Transfer discount from order
                round((float)$order_details['recargo_global_aplicado'], 2),     // Transfer surcharge from order
                $preselect_order_id,
                $preselect_parte_ruta_id
            ]);
            $new_invoice_id = $pdo->lastInsertId();

            // Initialize accumulators for invoice totals
            $total_base_imponible_factura_acc = "0";
            $total_iva_factura_acc = "0";
            $total_factura_iva_incluido_acc = "0";

            $stmt_insert_detail = $pdo->prepare("
                INSERT INTO detalle_factura_ventas
                (id_factura, id_producto, cantidad, precio_unitario_base, precio_unitario, porcentaje_iva_aplicado_en_factura, importe_iva_linea, subtotal_linea_sin_iva, total_linea_iva_incluido, unidades_retiradas, estado_retiro)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Pendiente')
            ");

            foreach ($lines_to_invoice as $line_data) {
                $id_producto = $line_data['id_producto'];
                $cantidad_str = (string)$line_data['cantidad'];
                $precio_unitario_iva_incluido_str = (string)$line_data['precio_unitario_venta']; // This is IVA included
                $iva_porcentaje_str = (string)$line_data['iva_porcentaje'];

                // Calculate total_linea_iva_incluido_initial (exact)
                $total_linea_iva_incluido_initial = bcmul($cantidad_str, $precio_unitario_iva_incluido_str);

                // Calculate importe_iva_linea_initial from the IVA-inclusive total
                // Formula: IVA Amount = Total (IVA Inc.) * (IVA Rate / (100 + IVA Rate))
                $iva_rate_factor = bcdiv($iva_porcentaje_str, bcadd("100", $iva_porcentaje_str));
                $importe_iva_linea_initial = bcmul($total_linea_iva_incluido_initial, $iva_rate_factor);

                // Calculate subtotal_linea_sin_iva_initial by subtracting IVA from total
                $subtotal_linea_sin_iva_initial = bcsub($total_linea_iva_incluido_initial, $importe_iva_linea_initial);

                // Calculate precio_unitario_base_calculated
                $precio_unitario_base_calculated = "0";
                if (bccomp($cantidad_str, "0", 4) > 0) {
                    $precio_unitario_base_calculated = bcdiv($subtotal_linea_sin_iva_initial, $cantidad_str);
                }

                // Insert the invoice detail line, rounding for DB storage
                $stmt_insert_detail->execute([
                    $new_invoice_id,
                    $id_producto,
                    (int)$cantidad_str,
                    round((float)$precio_unitario_base_calculated, 2),
                    round((float)$precio_unitario_iva_incluido_str, 2), // Store the price WITH IVA here (initial, before global adjustments)
                    round((float)$iva_porcentaje_str, 2),
                    round((float)$importe_iva_linea_initial, 2),
                    round((float)$subtotal_linea_sin_iva_initial, 2),
                    round((float)$total_linea_iva_incluido_initial, 2)
                ]);

                // Decrement stock of finished products
                $stmt_update_stock = $pdo->prepare("UPDATE productos SET stock_actual_unidades = stock_actual_unidades - ? WHERE id_producto = ?");
                $stmt_update_stock->execute([(int)$cantidad_str, $id_producto]);

                // Accumulate totals for the main invoice header
                $total_base_imponible_factura_acc = bcadd($total_base_imponible_factura_acc, $subtotal_linea_sin_iva_initial);
                $total_iva_factura_acc = bcadd($total_iva_factura_acc, $importe_iva_linea_initial);
                $total_factura_iva_incluido_acc = bcadd($total_factura_iva_incluido_acc, $total_linea_iva_incluido_initial);
            }

            // Update the main invoice entry with the accumulated totals (rounded for DB)
            $stmt_update_invoice_totals = $pdo->prepare("
                UPDATE facturas_ventas
                SET
                    total_factura = ?, -- Base imponible total antes de descuento/recargo global
                    total_iva_factura = ?,
                    total_factura_iva_incluido = ? -- Total con IVA antes de descuento/recargo global
                WHERE id_factura = ?
            ");
            $stmt_update_invoice_totals->execute([
                round((float)$total_base_imponible_factura_acc, 2),
                round((float)$total_iva_factura_acc, 2),
                round((float)$total_factura_iva_incluido_acc, 2),
                $new_invoice_id
            ]);

            // Recalculate invoice totals to apply global discount/surcharge proportionally
            recalcularTotalFactura($pdo, $new_invoice_id);

            // Update the associated invoice ID in the 'pedidos' table
            $stmt_update_pedido_factura_asociada = $pdo->prepare("UPDATE pedidos SET id_factura_asociada = ? WHERE id_pedido = ?");
            $stmt_update_pedido_factura_asociada->execute([$new_invoice_id, $preselect_order_id]);

            $final_message .= "Factura creada con éxito a partir del pedido " . htmlspecialchars($preselect_order_id) . ". ID Factura: " . htmlspecialchars($new_invoice_id) . ".";
            $redirect_url = "facturas_ventas.php?view=details&id=" . htmlspecialchars($new_invoice_id);
            if ($preselect_parte_ruta_id) {
                $redirect_url .= "&from_parte_ruta_id=" . htmlspecialchars($preselect_parte_ruta_id);
            }

            // --- LOGIC FOR ORIGINAL ORDER LINE UPDATES ---
            // Update the original order's detail lines based on what was invoiced
            foreach ($order_details_lines as $detail_line) {
                $id_detalle_pedido_original = $detail_line['id_detalle_pedido'];
                $cantidad_solicitada_original = (int)$detail_line['cantidad'];
                $stock_disponible = (int)($detail_line['stock_actual_unidades'] ?? 0);
                $cantidad_a_facturar = min($cantidad_solicitada_original, $stock_disponible);

                $new_estado_linea_original = 'Pendiente'; // Default
                if ($cantidad_a_facturar == $cantidad_solicitada_original) {
                    $new_estado_linea_original = 'Facturado';
                } elseif ($cantidad_a_facturar > 0 && $cantidad_a_facturar < $cantidad_solicitada_original) {
                    $new_estado_linea_original = 'Parcialmente Facturado';
                } else {
                    // If cantidad_a_facturar is 0, but an invoice was created for other lines,
                    // this line is effectively 'Pendiente' for the backorder.
                    $new_estado_linea_original = 'Pendiente';
                }

                $stmt_update_original_order_line = $pdo->prepare("
                    UPDATE detalle_pedidos
                    SET cantidad_solicitada = ?, estado_linea = ?
                    WHERE id_detalle_pedido = ? AND id_pedido = ?
                ");
                $stmt_update_original_order_line->execute([$cantidad_a_facturar, $new_estado_linea_original, $id_detalle_pedido_original, $preselect_order_id]);
            }
            // --- END LOGIC FOR ORIGINAL ORDER LINE UPDATES ---


            // NEW LOGIC: Create a new backorder if there are pending lines
            if (!empty($lines_for_backorder)) {
                // Insert a new order for the backordered items
                $stmt_insert_backorder = $pdo->prepare("
                    INSERT INTO pedidos (
                        id_cliente,
                        id_direccion_envio, -- NEW: Pass shipping address ID to backorder
                        fecha_pedido,
                        estado_pedido,
                        descuento_global_aplicado,
                        recargo_global_aplicado,
                        id_ruta,
                        tipo_entrega,
                        empresa_transporte,
                        numero_seguimiento,
                        observaciones,
                        id_pedido_origen -- Link to the original order
                    ) VALUES (?, ?, ?, 'pendiente', 0.00, 0.00, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_insert_backorder->execute([
                    $order_details['id_cliente'],
                    $order_details['id_direccion_envio'], // NEW: Pass shipping address ID
                    date('Y-m-d'), // Set current date for the backorder
                    $order_details['id_ruta'],
                    $order_details['tipo_entrega'],
                    $order_details['empresa_transporte'],
                    $order_details['numero_seguimiento'],
                    "Backorder generado automáticamente del pedido original ID: " . $preselect_order_id . ". " . $order_details['observaciones'],
                    $preselect_order_id
                ]);
                $new_backorder_id = $pdo->lastInsertId();

                // Insert detail lines for the new backorder
                $stmt_insert_backorder_detail = $pdo->prepare("
                    INSERT INTO detalle_pedidos (
                        id_pedido,
                        id_producto,
                        cantidad_solicitada,
                        precio_unitario_venta,
                        descuento_porcentaje,
                        recargo_porcentaje,
                        iva_porcentaje,
                        observaciones_detalle,
                        estado_linea
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente')
                ");
                foreach ($lines_for_backorder as $backorder_line) {
                    $stmt_insert_backorder_detail->execute([
                        $new_backorder_id,
                        $backorder_line['id_producto'],
                        $backorder_line['cantidad'],
                        $backorder_line['precio_unitario_venta'],
                        $backorder_line['descuento_porcentaje'],
                        $backorder_line['recargo_porcentaje'],
                        $backorder_line['iva_porcentaje'],
                        $backorder_line['observaciones_detalle']
                    ]);
                    // Add to partial invoice warnings only if a backorder is actually created
                    $partial_invoice_warnings[] = "La línea de pedido para el producto '{$backorder_line['nombre_producto']}' (ID: {$backorder_line['id_producto']}) ha generado una cantidad pendiente de {$backorder_line['cantidad']} unidades debido a stock insuficiente. Se creará un nuevo pedido para esta cantidad.";
                }
                // Recalculate totals for the new backorder
                recalcularTotalPedido($pdo, $new_backorder_id);
                $final_message .= "<br>Se ha generado un nuevo pedido (ID: " . htmlspecialchars($new_backorder_id) . ") para las unidades pendientes.";
            }

            // Recalculate totals for the original order based on its potentially updated lines (now reflecting only invoiced quantities)
            recalcularTotalPedido($pdo, $preselect_order_id);

            // Determine the final status of the original order based on what was invoiced and backordered
            if (!empty($lines_for_backorder)) {
                $stmt_update_original_order_status = $pdo->prepare("UPDATE pedidos SET estado_pedido = 'parcialmente_facturado' WHERE id_pedido = ?");
                $stmt_update_original_order_status->execute([$preselect_order_id]);
                $final_message_type = ($final_message_type == "success") ? "warning" : $final_message_type;
                $final_message .= "<br>El pedido original (ID: " . htmlspecialchars($preselect_order_id) . ") ha sido actualizado a 'Parcialmente Facturado'.";
            } else {
                // All lines were fully invoiced, and no backorder was needed
                $stmt_update_original_order_status = $pdo->prepare("UPDATE pedidos SET estado_pedido = 'completado' WHERE id_pedido = ?");
                $stmt_update_original_order_status->execute([$preselect_order_id]);
                $final_message .= "<br>El pedido original (ID: " . htmlspecialchars($preselect_order_id) . ") ha sido actualizado a 'Completado'.";
            }

        } else { // No units were invoiced (meaning no invoice will be created at all)
            $final_message_type = "info";
            $final_message .= "No se creó ninguna factura para el pedido " . htmlspecialchars($preselect_order_id) . " porque no había stock disponible para ninguna de sus líneas.";
            $redirect_url = "pedidos.php?view=details&id=" . htmlspecialchars($preselect_order_id); // Redirect to the original order
            if ($preselect_parte_ruta_id) {
                $redirect_url = "partes_ruta.php?view=details&id=" . htmlspecialchars($preselect_parte_ruta_id);
            }
            // IMPORTANT: No backorder creation, no modification to original order status or lines.
            // The original order simply remains as it was (e.g., 'pendiente').
            // The message clearly states why no invoice was created.
        }

        // Combine all warnings into the final message
        if (isset($partial_invoice_warnings) && !empty($partial_invoice_warnings)) { // Only add if warnings were generated
            $final_message .= "<br><br><strong>Advertencias:</strong><ul>";
            foreach ($partial_invoice_warnings as $warning) {
                $final_message .= "<li>" . htmlspecialchars($warning) . "</li>";
            }
            $final_message .= "</ul>";
        }

        $pdo->commit();

        $_SESSION['mensaje'] = $final_message;
        $_SESSION['tipo_mensaje'] = $final_message_type;

        header("Location: " . $redirect_url);
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['mensaje'] = "Error de base de datos al crear factura desde pedido: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
        // Redirect back to the parte de ruta if possible, otherwise to the invoice list
        if ($preselect_parte_ruta_id) {
            header("Location: partes_ruta.php?view=details&id=" . htmlspecialchars($preselect_parte_ruta_id));
        } else {
            header("Location: facturas_ventas.php");
        }
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['mensaje'] = "Error al crear factura desde pedido: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
        // Redirect back to the parte de ruta if possible, otherwise to the invoice list
        if ($preselect_parte_ruta_id) {
            header("Location: partes_ruta.php?view=details&id=" . htmlspecialchars($preselect_parte_ruta_id));
        } else {
            header("Location: facturas_ventas.php");
        }
        exit();
    }
}
// END NEW LOGIC

// Función para mostrar mensajes (usada internamente y para guardar en sesión)
function mostrarMensaje($msg, $type) {
    $_SESSION['mensaje'] = $msg;
    $_SESSION['tipo_mensaje'] = $type;
}

// --- Funciones auxiliares para la gestión de stock y transacciones ---

/**
 * Reversa el stock de productos terminados cuando se elimina una línea de detalle de factura o una factura completa.
 * Las unidades se devuelven al stock_actual_unidades de la tabla 'productos'.
 * @param PDO $pdo Objeto PDO de la conexión a la base de datos.
 * @param int $unidades_a_sumar Cantidad de unidades a devolver al stock.
 * @param int $id_producto ID del producto terminado.
 * @throws Exception Si ocurre un error al actualizar el stock.
 */
function revertirStockProductoTerminado(PDO $pdo, int $unidades_a_sumar, int $id_producto) {
    if ($unidades_a_sumar <= 0) {
        return; // No hay unidades que revertir
    }

    try {
        // Bloquear la fila del producto para evitar condiciones de carrera
        $stmt_producto = $pdo->prepare("SELECT stock_actual_unidades FROM productos WHERE id_producto = ? FOR UPDATE");
        $stmt_producto->execute([$id_producto]);
        $producto = $stmt_producto->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            throw new Exception("Producto con ID $id_producto no encontrado para revertir stock.");
        }

        $nuevo_stock = $producto['stock_actual_unidades'] + $unidades_a_sumar;

        $stmt_update = $pdo->prepare("UPDATE productos SET stock_actual_unidades = ? WHERE id_producto = ?");
        $stmt_update->execute([$nuevo_stock, $id_producto]);
    } catch (Exception $e) {
        error_log("Error al revertir stock de producto terminado (ID: $id_producto, Unidades: $unidades_a_sumar): " . $e->getMessage());
        throw new Exception("Error al revertir stock de producto terminado.");
    }
}

/**
 * Reversa la asignación de lotes y actualiza las unidades vendidas en detalle_actividad_envasado
 * cuando se elimina una línea de detalle de factura o una factura completa.
 * @param PDO $pdo Objeto PDO de la conexión a la base de datos.
 * @param int $id_detalle_factura ID de la línea de detalle de factura.
 * @throws Exception Si ocurre un error al revertir las asignaciones.
 */
function revertirAsignacionLotes(PDO $pdo, int $id_detalle_factura) {
    try {
        // Obtener todas las asignaciones para esta línea de detalle de factura
        $stmt_asignaciones = $pdo->prepare("SELECT id_asignacion, id_detalle_actividad, unidades_asignadas FROM asignacion_lotes_ventas WHERE id_detalle_factura = ?");
        $stmt_asignaciones->execute([$id_detalle_factura]);
        $asignaciones = $stmt_asignaciones->fetchAll(PDO::FETCH_ASSOC);

        foreach ($asignaciones as $asignacion) {
            $id_detalle_actividad = $asignacion['id_detalle_actividad'];
            $unidades_asignadas = $asignacion['unidades_asignadas'];

            // Revertir las unidades vendidas y litros vendidos en detalle_actividad_envasado
            // Se usa un subquery para obtener litros_por_unidad de productos de forma segura
            $stmt_update_dae = $pdo->prepare("
                UPDATE detalle_actividad_envasado
                SET
                    unidades_vendidas = unidades_vendidas - ?,
                    litros_vendidos = litros_vendidos - (? * (SELECT p.litros_por_unidad FROM productos p JOIN detalle_actividad_envasado dae_sub ON p.id_producto = dae_sub.id_producto WHERE dae_sub.id_detalle_actividad = ?))
                WHERE id_detalle_actividad = ?
            ");
            $stmt_update_dae->execute([$unidades_asignadas, $unidades_asignadas, $id_detalle_actividad, $id_detalle_actividad]);
        }

        // Eliminar las asignaciones de la tabla asignacion_lotes_ventas
        $stmt_delete_asignaciones = $pdo->prepare("DELETE FROM asignacion_lotes_ventas WHERE id_detalle_factura = ?");
        $stmt_delete_asignaciones->execute([$id_detalle_factura]);

    } catch (Exception $e) {
        error_log("Error al revertir asignación de lotes (ID Detalle Factura: $id_detalle_factura): " . $e->getMessage());
        throw new Exception("Error al revertir asignación de lotes.");
    }
}

/**
 * Actualiza el estado de pago de una factura basado en los cobros registrados.
 * @param PDO $pdo Objeto PDO de la conexión a la base de datos.
 * @param int $id_factura ID de la factura a actualizar.
 */
function actualizarEstadoPagoFactura(PDO $pdo, int $id_factura) {
    try {
        // Obtener el total de la factura (total_factura_iva_incluido)
        $stmt_factura = $pdo->prepare("SELECT total_factura_iva_incluido FROM facturas_ventas WHERE id_factura = ?");
        $stmt_factura->execute([$id_factura]);
        $factura = $stmt_factura->fetch(PDO::FETCH_ASSOC);

        if (!$factura) {
            error_log("Factura con ID $id_factura no encontrada al actualizar estado de pago.");
            return; // No lanzar excepción, solo registrar y salir
        }

        $total_factura = (float)$factura['total_factura_iva_incluido']; // Usar el nuevo campo inmutable

        $stmt_cobros = $pdo->prepare("SELECT SUM(cantidad_cobrada) AS total_cobrado FROM cobros_factura WHERE id_factura = ?");
        $stmt_cobros->execute([$id_factura]);
        $cobros = $stmt_cobros->fetch(PDO::FETCH_ASSOC);
        $total_cobrado = (float)($cobros['total_cobrado'] ?? 0);

        $estado_pago = 'pendiente';
        // Usar una pequeña tolerancia para la comparación de flotantes
        if (abs($total_cobrado - $total_factura) < 0.01) { // Si el cobrado es igual al total (con tolerancia)
            $estado_pago = 'pagada';
        } elseif ($total_cobrado > 0 && $total_cobrado < $total_factura) {
            $estado_pago = 'parcialmente_pagada';
        } elseif ($total_cobrado < $total_factura) { // This covers cases where total_cobrado is negative or less than total_factura
            $estado_pago = 'pendiente';
        }

        $stmt_update = $pdo->prepare("UPDATE facturas_ventas SET estado_pago = ? WHERE id_factura = ?");
        $stmt_update->execute([$estado_pago, $id_factura]);
    } catch (Exception $e) {
        error_log("Error al actualizar estado de pago de factura (ID: $id_factura): " . $e->getMessage());
        // No lanzar excepción para no detener el flujo principal, solo registrar el error
    }
}

/**
 * Calcula y actualiza el total de una factura, aplicando un descuento global y un recargo global
 * proporcionalmente a cada línea.
 * Esta función recalculará los nuevos campos de totales de la factura
 * basándose en los detalles de línea ya inmutables (precio_unitario_base).
 * @param PDO $pdo Objeto PDO de la conexión a la base de datos.
 * @param int $id_factura ID de la factura.
 */
function recalcularTotalFactura(PDO $pdo, int $id_factura) {
    try {
        // Set precision for bcmath operations
        bcscale(4);

        // 1. Obtener el descuento global y el recargo global aplicados a esta factura
        $stmt_get_invoice_adjustments = $pdo->prepare("SELECT descuento_global_aplicado, recargo_global_aplicado FROM facturas_ventas WHERE id_factura = ?");
        $stmt_get_invoice_adjustments->execute([$id_factura]);
        $adjustments = $stmt_get_invoice_adjustments->fetch(PDO::FETCH_ASSOC);

        $descuento_global_aplicado_str = (string)($adjustments['descuento_global_aplicado'] ?? "0.00");
        $recargo_global_aplicado_str = (string)($adjustments['recargo_global_aplicado'] ?? "0.00");

        // 2. Obtener todas las líneas de detalle de esta factura, incluyendo el total_linea_iva_incluido
        $stmt_get_line_items = $pdo->prepare("
            SELECT id_detalle_factura, cantidad, precio_unitario_base, porcentaje_iva_aplicado_en_factura, total_linea_iva_incluido
            FROM detalle_factura_ventas
            WHERE id_factura = ? FOR UPDATE
        ");
        $stmt_get_line_items->execute([$id_factura]);
        $line_items = $stmt_get_line_items->fetchAll(PDO::FETCH_ASSOC);

        // Calcular la suma total de los totales IVA incluido de todas las líneas (antes de ajustes globales)
        $total_sum_of_lines_iva_incl = "0";
        foreach ($line_items as $item) {
            $total_sum_of_lines_iva_incl = bcadd($total_sum_of_lines_iva_incl, (string)$item['total_linea_iva_incluido']);
        }

        // IMPORTANT: Asegurarse de que el descuento global no exceda el total de las líneas base.
        if (bccomp($descuento_global_aplicado_str, $total_sum_of_lines_iva_incl) > 0) {
            $descuento_global_aplicado_str = $total_sum_of_lines_iva_incl;
            error_log("Advertencia: El descuento global aplicado a la factura $id_factura excedió el total de las líneas y fue ajustado a " . $descuento_global_aplicado_str);
        }

        // Calcular el ajuste neto (recargo - descuento)
        $net_adjustment = bcsub($recargo_global_aplicado_str, $descuento_global_aplicado_str);

        $total_factura_final_iva_incluido_acc = "0";
        $total_iva_factura_acc = "0";
        $total_base_imponible_factura_acc = "0";

        // 3. Recalcular cada línea de detalle proporcionalmente
        $stmt_update_line_item = $pdo->prepare("
            UPDATE detalle_factura_ventas
            SET
                precio_unitario = ?, -- Este es el precio unitario final con ajuste (IVA incluido)
                subtotal_linea_sin_iva = ?,
                importe_iva_linea = ?,
                total_linea_iva_incluido = ?
            WHERE id_detalle_factura = ?
        ");

        foreach ($line_items as $item) {
            $cantidad_str = (string)$item['cantidad'];
            $porcentaje_iva_str = (string)$item['porcentaje_iva_aplicado_en_factura'];
            $total_linea_iva_incluido_initial_from_db = (string)$item['total_linea_iva_incluido']; // Use the stored exact total

            $proportional_adjustment_linea = "0";
            if (bccomp($total_sum_of_lines_iva_incl, "0", 4) > 0) {
                // Calcular el ajuste proporcional para esta línea
                $ratio = bcdiv($total_linea_iva_incluido_initial_from_db, $total_sum_of_lines_iva_incl);
                $proportional_adjustment_linea = bcmul($ratio, $net_adjustment);
            }

            // Calcular el total final de la línea después del ajuste (recargo - descuento)
            $total_linea_iva_incluido_final = bcadd($total_linea_iva_incluido_initial_from_db, $proportional_adjustment_linea);

            // Recalcular IVA y Subtotal de la línea desde el total final IVA incluido
            $iva_rate_factor = bcdiv($porcentaje_iva_str, bcadd("100", $porcentaje_iva_str));
            $importe_iva_linea_final = bcmul($total_linea_iva_incluido_final, $iva_rate_factor);
            $subtotal_linea_sin_iva_final = bcsub($total_linea_iva_incluido_final, $importe_iva_linea_final);

            // Recalcular precio_unitario_final_iva_incluido
            $precio_unitario_final_iva_incluido = "0";
            if (bccomp($cantidad_str, "0", 4) > 0) {
                $precio_unitario_final_iva_incluido = bcdiv($total_linea_iva_incluido_final, $cantidad_str);
            }

            // Round for database storage
            $stmt_update_line_item->execute([
                round((float)$precio_unitario_final_iva_incluido, 2),
                round((float)$subtotal_linea_sin_iva_final, 2),
                round((float)$importe_iva_linea_final, 2),
                round((float)$total_linea_iva_incluido_final, 2),
                $item['id_detalle_factura']
            ]);

            $total_factura_final_iva_incluido_acc = bcadd($total_factura_final_iva_incluido_acc, $total_linea_iva_incluido_final);
            $total_iva_factura_acc = bcadd($total_iva_factura_acc, $importe_iva_linea_final);
            $total_base_imponible_factura_acc = bcadd($total_base_imponible_factura_acc, $subtotal_linea_sin_iva_final);
        }

        // 4. Actualizar la cabecera de la factura con los nuevos totales y ajustes
        $stmt_update_factura = $pdo->prepare("
            UPDATE facturas_ventas
            SET
                total_factura = ?, -- Base imponible total después de ajustes
                total_iva_factura = ?,
                total_factura_iva_incluido = ?, -- Total final con IVA, descuento y recargo
                descuento_global_aplicado = ?,
                recargo_global_aplicado = ?
            WHERE id_factura = ?
        ");
        $stmt_update_factura->execute([
            round((float)$total_base_imponible_factura_acc, 2),
            round((float)$total_iva_factura_acc, 2),
            round((float)$total_factura_final_iva_incluido_acc, 2),
            round((float)$descuento_global_aplicado_str, 2),
            round((float)$recargo_global_aplicado_str, 2),
            $id_factura
        ]);

        actualizarEstadoPagoFactura($pdo, $id_factura); // Actualizar estado de pago después de recalcular el total
    } catch (Exception $e) {
        error_log("Error al recalcular total de factura (ID: $id_factura): " . $e->getMessage());
        throw new Exception("Error al recalcular total de factura: " . $e->getMessage());
    }
}

/**
 * Recalcula y actualiza los totales de un pedido, aplicando un descuento global y un recargo global
 * proporcionalmente a cada línea de detalle.
 *
 * @param PDO $pdo Objeto PDO de la conexión a la base de datos.
 * @param int $id_pedido ID del pedido a actualizar.
 * @throws Exception Si ocurre un error al recalcular los totales.
 */
function recalcularTotalPedido(PDO $pdo, int $id_pedido) {
    try {
        // Set precision for bcmath operations
        bcscale(4);

        // 1. Obtener el descuento global y el recargo global aplicados a este pedido
        $stmt_get_order_adjustments = $pdo->prepare("SELECT descuento_global_aplicado, recargo_global_aplicado FROM pedidos WHERE id_pedido = ?");
        $stmt_get_order_adjustments->execute([$id_pedido]);
        $adjustments = $stmt_get_order_adjustments->fetch(PDO::FETCH_ASSOC);

        $descuento_global_aplicado_str = (string)($adjustments['descuento_global_aplicado'] ?? "0.00");
        $recargo_global_aplicado_str = (string)($adjustments['recargo_global_aplicado'] ?? "0.00");

        // 2. Obtener todas las líneas de detalle de este pedido
        // Necesitamos los valores originales de cantidad, precio_unitario_venta, descuento_porcentaje, recargo_porcentaje, iva_porcentaje
        $stmt_get_line_items = $pdo->prepare("SELECT id_detalle_pedido, cantidad_solicitada, precio_unitario_venta, descuento_porcentaje, recargo_porcentaje, iva_porcentaje FROM detalle_pedidos WHERE id_pedido = ? FOR UPDATE");
        $stmt_get_line_items->execute([$id_pedido]);
        $line_items = $stmt_get_line_items->fetchAll(PDO::FETCH_ASSOC);

        // Calcular la suma total de los precios (IVA incluido) de las líneas antes de aplicar descuento/recargo global
        $total_sum_of_line_totals_before_global_adjustment = "0";
        foreach ($line_items as $item) {
            $cantidad_str = (string)$item['cantidad_solicitada'];
            $precio_unitario_venta_str = (string)$item['precio_unitario_venta']; // Este es el precio IVA incluido
            $descuento_linea_str = (string)$item['descuento_porcentaje'];
            $recargo_linea_str = (string)$item['recargo_porcentaje'];

            // Calcular el total de la línea con sus propios descuentos/recargos (IVA incluido)
            $total_linea_before_global_adjustment = bcmul(
                $cantidad_str,
                bcmul(
                    $precio_unitario_venta_str,
                    bcmul(
                        bcsub("1", bcdiv($descuento_linea_str, "100")),
                        bcadd("1", bcdiv($recargo_linea_str, "100"))
                    )
                )
            );
            $total_sum_of_line_totals_before_global_adjustment = bcadd($total_sum_of_line_totals_before_global_adjustment, $total_linea_before_global_adjustment);
        }

        // Calcular el ajuste neto global (recargo global - descuento global)
        $net_global_adjustment = bcsub($recargo_global_aplicado_str, $descuento_global_aplicado_str);

        $final_total_base_imponible_pedido = "0";
        $final_total_iva_pedido = "0";
        $final_total_pedido_iva_incluido = "0";

        // 3. Recalcular cada línea de detalle aplicando el ajuste global proporcionalmente
        $stmt_update_line_item = $pdo->prepare("
            UPDATE detalle_pedidos
            SET
                subtotal_linea_base = ?,
                subtotal_linea_iva = ?,
                subtotal_linea_total = ?
            WHERE id_detalle_pedido = ?
        ");

        foreach ($line_items as $item) {
            $cantidad_str = (string)$item['cantidad_solicitada'];
            $precio_unitario_venta_str = (string)$item['precio_unitario_venta']; // Este es el precio IVA incluido
            $descuento_linea_str = (string)$item['descuento_porcentaje'];
            $recargo_linea_str = (string)$item['recargo_porcentaje'];
            $iva_linea_str = (string)$item['iva_porcentaje'];

            // Recalcular el total de la línea con sus propios descuentos/recargos (IVA incluido)
            $total_linea_before_global_adjustment = bcmul(
                $cantidad_str,
                bcmul(
                    $precio_unitario_venta_str,
                    bcmul(
                        bcsub("1", bcdiv($descuento_linea_str, "100")),
                        bcadd("1", bcdiv($recargo_linea_str, "100"))
                    )
                )
            );

            $proportional_global_adjustment_for_line = "0";
            if (bccomp($total_sum_of_line_totals_before_global_adjustment, "0", 4) > 0) {
                $proportional_global_adjustment_for_line = bcmul(
                    bcdiv($total_linea_before_global_adjustment, $total_sum_of_line_totals_before_global_adjustment),
                    $net_global_adjustment
                );
            }

            // Aplicar el ajuste global proporcional al total de la línea (IVA incluido)
            $total_linea_iva_incluido_final = bcadd($total_linea_before_global_adjustment, $proportional_global_adjustment_for_line);

            // Recalcular la base imponible y el IVA de la línea a partir del total final (IVA incluido)
            $subtotal_linea_base_final = bcdiv($total_linea_iva_incluido_final, bcadd("1", bcdiv($iva_linea_str, "100")));
            $subtotal_linea_iva_final = bcsub($total_linea_iva_incluido_final, $subtotal_linea_base_final);

            $stmt_update_line_item->execute([
                round((float)$subtotal_linea_base_final, 2),
                round((float)$subtotal_linea_iva_final, 2),
                round((float)$total_linea_iva_incluido_final, 2), // subtotal_linea_total es el total con IVA incluido
                $item['id_detalle_pedido']
            ]);

            $final_total_base_imponible_pedido = bcadd($final_total_base_imponible_pedido, $subtotal_linea_base_final);
            $final_total_iva_pedido = bcadd($final_total_iva_pedido, $subtotal_linea_iva_final);
            $final_total_pedido_iva_incluido = bcadd($final_total_pedido_iva_incluido, $total_linea_iva_incluido_final);
        }

        // 4. Actualizar la cabecera del pedido con los nuevos totales
        $stmt_update_pedido = $pdo->prepare("
            UPDATE pedidos
            SET
                total_base_imponible_pedido = ?,
                total_iva_pedido = ?,
                total_pedido_iva_incluido = ?,
                descuento_global_aplicado = ?,
                recargo_global_aplicado = ?
            WHERE id_pedido = ?
        ");
        $stmt_update_pedido->execute([
            round((float)$final_total_base_imponible_pedido, 2),
            round((float)$final_total_iva_pedido, 2),
            round((float)$final_total_pedido_iva_incluido, 2),
            round((float)$descuento_global_aplicado_str, 2),
            round((float)$recargo_global_aplicado_str, 2),
            $id_pedido
        ]);

    } catch (Exception $e) {
        error_log("Error al recalcular total de pedido (ID: $id_pedido): " . $e->getMessage());
        throw new Exception("Error al recalcular total de pedido: " . $e->getMessage());
    }
}


// --- Lógica para PROCESAR SOLICITUDES POST y AJAX ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $accion = $_POST['accion'] ?? '';

    // Asegurarse de que la respuesta sea JSON si es una solicitud AJAX
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
    }

    $in_transaction = false;
    // Iniciar transacción si la acción no es solo para obtener datos
    if ($accion !== 'get_client_details' && $accion !== 'search_clients' && $accion !== 'get_product_details' && $accion !== 'get_available_batches' && $accion !== 'get_shipping_addresses' && $accion !== 'search_shipping_addresses') {
        $pdo->beginTransaction();
        $in_transaction = true;
    }

    try {
        switch ($accion) {
            case 'agregar_factura':
                $fecha_factura = $_POST['fecha_factura'];
                $id_cliente = $_POST['id_cliente'];
                $id_direccion_envio = empty($_POST['id_direccion_envio']) ? null : (int)$_POST['id_direccion_envio']; // NEW: Get shipping address ID
                // Nuevos campos para el descuento global y recargo global en la creación de factura (por defecto 0.00)
                $descuento_global = (float)($_POST['descuento_global'] ?? 0.00);
                $recargo_global = (float)($_POST['recargo_global'] ?? 0.00);

                // Basic validation for client ID
                if (empty($id_cliente) || !is_numeric($id_cliente)) {
                    throw new Exception("Cliente no válido. Por favor, seleccione un cliente de la lista.");
                }

                // Insertar la factura con los nuevos campos inicializados
                // NEW: Added id_direccion_envio to the INSERT statement
                $stmt = $pdo->prepare("INSERT INTO facturas_ventas (fecha_factura, id_cliente, id_direccion_envio, total_factura, estado_pago, descuento_global_aplicado, recargo_global_aplicado, total_iva_factura, total_factura_iva_incluido) VALUES (?, ?, ?, 0.00, 'pendiente', ?, ?, 0.00, 0.00)");
                $stmt->execute([$fecha_factura, $id_cliente, $id_direccion_envio, round($descuento_global, 2), round($recargo_global, 2)]);
                $id_factura_nueva = $pdo->lastInsertId();

                // Recalcular aquí para aplicar el descuento/recargo inicial si se ha puesto alguno
                recalcularTotalFactura($pdo, $id_factura_nueva);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Factura creada con éxito. ID: " . htmlspecialchars($id_factura_nueva), "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura_nueva));
                exit();
                break;

            case 'editar_factura':
                $id_factura = $_POST['id_factura'] ?? null;
                $id_cliente = $_POST['id_cliente'] ?? null;
                $fecha_factura = $_POST['fecha_factura'] ?? null;
                $id_direccion_envio = empty($_POST['id_direccion_envio']) ? null : (int)$_POST['id_direccion_envio'];

                if (empty($id_factura) || empty($id_cliente) || empty($fecha_factura)) {
                    throw new Exception("Faltan datos requeridos para editar la factura.");
                }

                $stmt = $pdo->prepare(
                    "UPDATE facturas_ventas SET id_cliente = ?, fecha_factura = ?, id_direccion_envio = ? WHERE id_factura = ?"
                );
                $stmt->execute([$id_cliente, $fecha_factura, $id_direccion_envio, $id_factura]);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Factura actualizada con éxito.", "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura));
                exit();
                break;

            case 'agregar_detalle_linea':
                $id_factura = $_POST['id_factura'];
                $id_producto = $_POST['id_producto'];
                $cantidad = (int)$_POST['cantidad'];

                // Validar entradas
                if ($cantidad <= 0) {
                    throw new Exception("La cantidad debe ser un número positivo.");
                }

                // Obtener el precio de venta (IVA incluido) y el porcentaje de IVA del producto en el momento actual
                $stmt_get_product_info = $pdo->prepare("SELECT precio_venta, porcentaje_iva_actual FROM productos WHERE id_producto = ? FOR UPDATE");
                $stmt_get_product_info->execute([$id_producto]);
                $product_info = $stmt_get_product_info->fetch(PDO::FETCH_ASSOC);

                if (!$product_info) {
                    throw new Exception("Producto no encontrado.");
                }

                $precio_unitario_iva_incluido_str = (string)$product_info['precio_venta']; // This is the price WITH IVA
                $porcentaje_iva_str = (string)$product_info['porcentaje_iva_actual'];
                $cantidad_str = (string)$cantidad;

                // Validar precio unitario (que viene del producto)
                if (bccomp($precio_unitario_iva_incluido_str, "0", 2) <= 0) {
                    throw new Exception("El precio unitario del producto es inválido.");
                }

                // Verificar si hay suficiente stock de producto terminado
                $stmt_check_stock = $pdo->prepare("SELECT stock_actual_unidades FROM productos WHERE id_producto = ? FOR UPDATE");
                $stmt_check_stock->execute([$id_producto]);
                $producto_stock = $stmt_check_stock->fetch(PDO::FETCH_ASSOC);

                if (!$producto_stock || $producto_stock['stock_actual_unidades'] < $cantidad) {
                    throw new Exception("No hay suficiente stock del producto terminado. Stock actual: " . ($producto_stock['stock_actual_unidades'] ?? 0) . " unidades. Cantidad solicitada: {$cantidad}.");
                }

                // Set precision for bcmath operations
                bcscale(4);

                // 1. Calculate total_linea_iva_incluido_initial (exact as per user's requirement)
                $total_linea_iva_incluido_initial = bcmul($cantidad_str, $precio_unitario_iva_incluido_str);

                // 2. Calculate importe_iva_linea_initial from the IVA-inclusive total
                // Formula: IVA Amount = Total (IVA Inc.) * (IVA Rate / (100 + IVA Rate))
                $iva_rate_factor = bcdiv($porcentaje_iva_str, bcadd("100", $porcentaje_iva_str));
                $importe_iva_linea_initial = bcmul($total_linea_iva_incluido_initial, $iva_rate_factor);

                // 3. Calculate subtotal_linea_sin_iva_initial by subtracting IVA from total
                $subtotal_linea_sin_iva_initial = bcsub($total_linea_iva_incluido_initial, $importe_iva_linea_initial);

                // 4. Calculate precio_unitario_base_calculated
                $precio_unitario_base_calculated = "0";
                if (bccomp($cantidad_str, "0", 4) > 0) {
                    $precio_unitario_base_calculated = bcdiv($subtotal_linea_sin_iva_initial, $cantidad_str);
                }

                // Insert the line of invoice detail with the calculated values, rounded for database storage
                $stmt = $pdo->prepare("
                    INSERT INTO detalle_factura_ventas
                    (id_factura, id_producto, cantidad, precio_unitario_base, precio_unitario, porcentaje_iva_aplicado_en_factura, importe_iva_linea, subtotal_linea_sin_iva, total_linea_iva_incluido, unidades_retiradas, estado_retiro)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Pendiente')
                ");
                $stmt->execute([
                    $id_factura,
                    $id_producto,
                    (int)$cantidad_str,
                    round((float)$precio_unitario_base_calculated, 2),
                    round((float)$precio_unitario_iva_incluido_str, 2), // Store the price WITH IVA here (initial, before global adjustments)
                    round((float)$porcentaje_iva_str, 2),
                    round((float)$importe_iva_linea_initial, 2),
                    round((float)$subtotal_linea_sin_iva_initial, 2),
                    round((float)$total_linea_iva_incluido_initial, 2)
                ]);

                // Decrementar el stock de producto terminado
                $nuevo_stock = $producto_stock['stock_actual_unidades'] - $cantidad;
                $stmt_update_stock = $pdo->prepare("UPDATE productos SET stock_actual_unidades = ? WHERE id_producto = ?");
                $stmt_update_stock->execute([$nuevo_stock, $id_producto]);

                // Recalcular el total de la factura, lo que aplicará el descuento/recargo global proporcionalmente
                recalcularTotalFactura($pdo, $id_factura);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Línea de factura agregada con éxito.", "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura));
                exit();
                break;

            case 'delete_detalle_linea':
                $id_detalle_factura = $_POST['id_detalle_factura'];
                $id_factura_original = $_POST['id_factura_original'];

                // Obtener detalles de la línea a eliminar para revertir stock
                $stmt_detalle = $pdo->prepare("SELECT id_producto, cantidad, unidades_retiradas FROM detalle_factura_ventas WHERE id_detalle_factura = ? FOR UPDATE");
                $stmt_detalle->execute([$id_detalle_factura]);
                $detalle_linea = $stmt_detalle->fetch(PDO::FETCH_ASSOC);

                if (!$detalle_linea) {
                    throw new Exception("Línea de detalle de factura no encontrada para eliminar.");
                }

                // Revertir stock de productos terminados (la cantidad original de la línea)
                revertirStockProductoTerminado($pdo, $detalle_linea['cantidad'], $detalle_linea['id_producto']);

                // Revertir asignaciones de lotes y unidades vendidas en detalle_actividad_envasado
                revertirAsignacionLotes($pdo, $id_detalle_factura);

                // Eliminar la línea de detalle
                $stmt_delete = $pdo->prepare("DELETE FROM detalle_factura_ventas WHERE id_detalle_factura = ?");
                $stmt_delete->execute([$id_detalle_factura]);

                // Recalcular el total de la factura, lo que reajustará el descuento/recargo global proporcionalmente
                recalcularTotalFactura($pdo, $id_factura_original);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Línea de factura eliminada con éxito y stock revertido.", "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura_original));
                exit();
                break;

            case 'delete_invoice':
                $id_factura = $_POST['id_factura'];

                // Obtener todas las líneas de detalle de esta factura para revertir stock y asignaciones
                $stmt_detalles_factura = $pdo->prepare("SELECT id_detalle_factura, id_producto, cantidad FROM detalle_factura_ventas WHERE id_factura = ?");
                $stmt_detalles_factura->execute([$id_factura]);
                $lineas_a_eliminar = $stmt_detalles_factura->fetchAll(PDO::FETCH_ASSOC);

                foreach ($lineas_a_eliminar as $linea) {
                    // Revertir stock de productos terminados
                    revertirStockProductoTerminado($pdo, $linea['cantidad'], $linea['id_producto']);
                    // Revertir asignaciones de lotes y unidades vendidas
                    revertirAsignacionLotes($pdo, $linea['id_detalle_factura']); // Corregido: usar $linea['id_detalle_factura']
                }

                // Unlink associated orders from this invoice and set their status to 'pendiente'
                // This logic should be reviewed if backorders are now created.
                // If an order was partially factured and a backorder created, unlinking it might not be desired.
                // For now, it unlinks, but consider the implications.
                $stmt_unlink_orders = $pdo->prepare("UPDATE pedidos SET id_factura_asociada = NULL, estado_pedido = 'pendiente' WHERE id_factura_asociada = ?");
                $stmt_unlink_orders->execute([$id_factura]);

                // Eliminar cobros asociados
                $stmt_delete_cobros = $pdo->prepare("DELETE FROM cobros_factura WHERE id_factura = ?");
                $stmt_delete_cobros->execute([$id_factura]);

                // Eliminar líneas de detalle
                $stmt_delete_detalles = $pdo->prepare("DELETE FROM detalle_factura_ventas WHERE id_factura = ?");
                $stmt_delete_detalles->execute([$id_factura]);

                // Eliminar la factura principal
                $stmt_delete_factura = $pdo->prepare("DELETE FROM facturas_ventas WHERE id_factura = ?");
                $stmt_delete_factura->execute([$id_factura]);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Factura eliminada con éxito, stock revertido y cobros eliminados.", "success");
                header("Location: facturas_ventas.php");
                exit();
                break;

            case 'agregar_cobro':
                $id_factura = $_POST['id_factura'];
                $fecha_cobro = $_POST['fecha_cobro'];
                $cantidad_cobrada = (float)$_POST['cantidad_cobrada'];
                $id_forma_pago = $_POST['id_forma_pago'];

                // Obtener el total de la factura para validar la cantidad cobrada (usando el nuevo campo inmutable)
                $stmt_factura_total = $pdo->prepare("SELECT total_factura_iva_incluido FROM facturas_ventas WHERE id_factura = ?");
                $stmt_factura_total->execute([$id_factura]);
                $factura_data = $stmt_factura_total->fetch(PDO::FETCH_ASSOC);

                if (!$factura_data) {
                    throw new Exception("Factura no encontrada para registrar cobro.");
                }
                $total_factura = (float)$factura_data['total_factura_iva_incluido'];

                // Obtener la suma de cobros existentes para esta factura
                $stmt_cobros_existentes = $pdo->prepare("SELECT SUM(cantidad_cobrada) AS total_cobrado FROM cobros_factura WHERE id_factura = ?");
                $stmt_cobros_existentes->execute([$id_factura]);
                $cobros_existentes = $stmt_cobros_existentes->fetch(PDO::FETCH_ASSOC);
                $total_cobrado_actual = (float)($cobros_existentes['total_cobrado'] ?? 0);

                // Validar que la cantidad cobrada no exceda el saldo pendiente (para cobros positivos)
                $saldo_pendiente = $total_factura - $total_cobrado_actual;
                // Usar una pequeña tolerancia para la comparación de flotantes
                if ($cantidad_cobrada <= 0 || $cantidad_cobrada > ($saldo_pendiente + 0.01)) {
                    throw new Exception("La cantidad a cobrar es inválida o excede el saldo pendiente. Saldo pendiente: " . number_format($saldo_pendiente, 2, ',', '.') . " €");
                }

                $stmt = $pdo->prepare("INSERT INTO cobros_factura (id_factura, fecha_cobro, cantidad_cobrada, id_forma_pago) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id_factura, $fecha_cobro, round($cantidad_cobrada, 2), $id_forma_pago]);

                actualizarEstadoPagoFactura($pdo, $id_factura); // Actualizar estado de pago

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Cobro registrado con éxito.", "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura));
                exit();
                break;

            case 'agregar_reembolso': // NEW ACTION FOR REFUNDS
                $id_factura = $_POST['id_factura'];
                $fecha_reembolso = $_POST['fecha_reembolso'];
                $cantidad_reembolsada = (float)$_POST['cantidad_reembolsada'];
                $id_forma_pago = $_POST['id_forma_pago']; // This will be the method of refund (e.g., 'Transferencia', 'Efectivo')

                // Validate refund amount (must be positive, will be stored as negative)
                if ($cantidad_reembolsada <= 0) {
                    throw new Exception("La cantidad a reembolsar debe ser un número positivo.");
                }

                // Obtener el saldo actual de la factura para validar que no se reembolse más de lo debido
                $stmt_factura_total = $pdo->prepare("SELECT total_factura_iva_incluido FROM facturas_ventas WHERE id_factura = ?");
                $stmt_factura_total->execute([$id_factura]);
                $factura_data = $stmt_factura_total->fetch(PDO::FETCH_ASSOC);

                if (!$factura_data) {
                    throw new Exception("Factura no encontrada para registrar reembolso.");
                }
                $total_factura = (float)$factura_data['total_factura_iva_incluido'];

                $stmt_cobros_existentes = $pdo->prepare("SELECT SUM(cantidad_cobrada) AS total_cobrado FROM cobros_factura WHERE id_factura = ?");
                $stmt_cobros_existentes->execute([$id_factura]);
                $cobros_existentes = $stmt_cobros_existentes->fetch(PDO::FETCH_ASSOC);
                $total_cobrado_actual = (float)($cobros_existentes['total_cobrado'] ?? 0);

                $saldo_actual = $total_factura - $total_cobrado_actual; // This is the amount still owed TO the company.
                                                                        // If negative, it's the amount owed BY the company.

                // Ensure we don't refund more than the *negative* saldo_actual (i.e., more than what's owed to the client)
                // Add tolerance for float comparison
                if ($cantidad_reembolsada > abs($saldo_actual) + 0.01) {
                    throw new Exception("No se puede reembolsar más de lo que se debe al cliente. Cantidad máxima a reembolsar: " . number_format(abs($saldo_actual), 2, ',', '.') . " €");
                }

                // Insert the refund as a negative amount
                $stmt = $pdo->prepare("INSERT INTO cobros_factura (id_factura, fecha_cobro, cantidad_cobrada, id_forma_pago) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id_factura, $fecha_reembolso, -round($cantidad_reembolsada, 2), $id_forma_pago]);

                actualizarEstadoPagoFactura($pdo, $id_factura); // Update payment status

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Reembolso registrado con éxito.", "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura));
                exit();
                break;

            case 'delete_cobro':
                $id_cobro = $_POST['id_cobro'];
                $id_factura_original_cobro = $_POST['id_factura_original_cobro'];

                $stmt_delete = $pdo->prepare("DELETE FROM cobros_factura WHERE id_cobro = ?");
                $stmt_delete->execute([$id_cobro]);

                actualizarEstadoPagoFactura($pdo, $id_factura_original_cobro); // Actualizar estado de pago

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Cobro eliminado con éxito.", "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura_original_cobro));
                exit();
                break;

            case 'retirar_stock_linea':
                $id_detalle_factura = $_POST['id_detalle_factura'];
                $unidades_a_retirar = (int)$_POST['unidades_a_retirar'];
                $id_detalle_actividad_seleccionado = (int)$_POST['id_detalle_actividad_seleccionado']; // Lote seleccionado
                $id_factura = $_POST['id_factura']; // Necesario para la redirección

                // Obtener detalles de la línea de factura
                $stmt_linea_factura = $pdo->prepare("SELECT id_producto, cantidad, unidades_retiradas FROM detalle_factura_ventas WHERE id_detalle_factura = ? FOR UPDATE");
                $stmt_linea_factura->execute([$id_detalle_factura]);
                $linea_factura = $stmt_linea_factura->fetch(PDO::FETCH_ASSOC);

                if (!$linea_factura) {
                    throw new Exception("Línea de detalle de factura no encontrada para retirar stock.");
                }

                $id_producto = $linea_factura['id_producto'];
                $cantidad_total_linea = $linea_factura['cantidad'];
                $unidades_ya_retiradas = $linea_factura['unidades_retiradas'];
                $unidades_pendientes_retiro = $cantidad_total_linea - $unidades_ya_retiradas;

                if ($unidades_a_retirar <= 0) {
                    throw new Exception("La cantidad a retirar debe ser mayor que cero.");
                }
                if ($unidades_a_retirar > $unidades_pendientes_retiro) {
                    throw new Exception("No puedes retirar más unidades de las pendientes. Pendientes: " . htmlspecialchars($unidades_pendientes_retiro) . ".");
                }

                // Obtener litros por unidad del producto para calcular litros vendidos
                $stmt_litros_por_unidad = $pdo->prepare("SELECT litros_por_unidad FROM productos WHERE id_producto = ?");
                $stmt_litros_por_unidad->execute([$id_producto]);
                $litros_por_unidad = $stmt_litros_por_unidad->fetchColumn();

                if (!$litros_por_unidad) {
                    throw new Exception("No se pudo obtener los litros por unidad para el producto con ID: " . htmlspecialchars($id_producto) . ".");
                }

                // Validar y retirar del lote seleccionado
                $stmt_lote_seleccionado = $pdo->prepare("SELECT unidades_envasadas, unidades_vendidas FROM detalle_actividad_envasado WHERE id_detalle_actividad = ? AND id_producto = ? FOR UPDATE");
                $stmt_lote_seleccionado->execute([$id_detalle_actividad_seleccionado, $id_producto]);
                $lote_seleccionado = $stmt_lote_seleccionado->fetch(PDO::FETCH_ASSOC);

                if (!$lote_seleccionado) {
                    throw new Exception("El lote seleccionado no es válido o no pertenece a este producto.");
                }

                $unidades_disponibles_en_lote = $lote_seleccionado['unidades_envasadas'] - $lote_seleccionado['unidades_vendidas'];

                if ($unidades_a_retirar > $unidades_disponibles_en_lote) {
                    throw new Exception("No hay suficientes unidades en el lote seleccionado. Disponibles: " . htmlspecialchars($unidades_disponibles_en_lote) . ".");
                }

                // Insertar en asignacion_lotes_ventas
                $stmt_insert_asignacion = $pdo->prepare("INSERT INTO asignacion_lotes_ventas (id_detalle_factura, id_detalle_actividad, unidades_asignadas) VALUES (?, ?, ?)");
                $stmt_insert_asignacion->execute([$id_detalle_factura, $id_detalle_actividad_seleccionado, $unidades_a_retirar]);

                // Actualizar unidades_vendidas y litros_vendidos en detalle_actividad_envasado para el lote seleccionado
                $stmt_update_dae = $pdo->prepare("UPDATE detalle_actividad_envasado SET unidades_vendidas = unidades_vendidas + ?, litros_vendidos = litros_vendidos + (? * ?) WHERE id_detalle_actividad = ?");
                $stmt_update_dae->execute([$unidades_a_retirar, $unidades_a_retirar, $litros_por_unidad, $id_detalle_actividad_seleccionado]);

                // Actualizar detalle_factura_ventas
                $nuevas_unidades_retiradas = $unidades_ya_retiradas + $unidades_a_retirar;
                $nuevo_estado_retiro = 'Pendiente';
                if ($nuevas_unidades_retiradas == $cantidad_total_linea) {
                    $nuevo_estado_retiro = 'Completado';
                } elseif ($nuevas_unidades_retiradas > 0) {
                    $nuevo_estado_retiro = 'Parcial';
                }

                $stmt_update_detalle_factura = $pdo->prepare("UPDATE detalle_factura_ventas SET unidades_retiradas = ?, estado_retiro = ? WHERE id_detalle_factura = ?");
                $stmt_update_detalle_factura->execute([$nuevas_unidades_retiradas, $nuevo_estado_retiro, $id_detalle_factura]);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Stock retirado y asignado al lote seleccionado con éxito.", "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura));
                exit();
                break;

            case 'update_global_discount': // Acción para actualizar el descuento global
                $id_factura = $_POST['id_factura'];
                $new_descuento_global = (float)($_POST['descuento_global'] ?? 0.00);

                if ($new_descuento_global < 0) {
                    throw new Exception("El descuento global no puede ser negativo.");
                }

                // Actualizar el descuento global en la cabecera de la factura
                $stmt_update_discount = $pdo->prepare("UPDATE facturas_ventas SET descuento_global_aplicado = ? WHERE id_factura = ?");
                $stmt_update_discount->execute([round($new_descuento_global, 2), $id_factura]);

                // Recalcular toda la factura para aplicar el descuento proporcionalmente (y el recargo)
                recalcularTotalFactura($pdo, $id_factura);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Descuento global actualizado con éxito y líneas recalculadas.", "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura));
                exit();
                break;

            case 'update_global_surcharge': // NUEVA ACCIÓN para actualizar el recargo global
                $id_factura = $_POST['id_factura'];
                $new_recargo_global = (float)($_POST['recargo_global'] ?? 0.00);

                if ($new_recargo_global < 0) {
                    throw new Exception("El recargo global no puede ser negativo.");
                }

                // Actualizar el recargo global en la cabecera de la factura
                $stmt_update_surcharge = $pdo->prepare("UPDATE facturas_ventas SET recargo_global_aplicado = ? WHERE id_factura = ?");
                $stmt_update_surcharge->execute([round($new_recargo_global, 2), $id_factura]);

                // Recalcular toda la factura para aplicar el recargo proporcionalmente (y el descuento)
                recalcularTotalFactura($pdo, $id_factura);

                if ($in_transaction) $pdo->commit();
                mostrarMensaje("Recargo global actualizado con éxito y líneas recalculadas.", "success");
                header("Location: facturas_ventas.php?view=details&id=" . htmlspecialchars($id_factura));
                exit();
                break;

            case 'get_available_batches':
                // Asegurarse de que no se imprima nada antes del JSON
                
                header('Content-Type: application/json');

                $id_producto = $_POST['id_producto'] ?? null;

                if (!$id_producto) {
                    echo json_encode(['error' => 'ID de producto no proporcionado.']);
                    exit();
                }

                try {
                    $stmt_batches = $pdo->prepare("
                        SELECT
                            dae.id_detalle_actividad,
                            dae.unidades_envasadas,
                            dae.unidades_vendidas,
                            dae.consecutivo_sublote,
                            ae.fecha_envasado,
                            le.nombre_lote
                        FROM
                            detalle_actividad_envasado dae
                        JOIN
                            actividad_envasado ae ON dae.id_actividad_envasado = ae.id_actividad_envasado
                        JOIN
                            lotes_envasado le ON dae.id_lote_envasado = le.id_lote_envasado
                        WHERE
                            dae.id_producto = ? AND dae.unidades_envasadas > dae.unidades_vendidas
                        ORDER BY
                            ae.fecha_envasado ASC, dae.consecutivo_sublote ASC
                    ");
                    $stmt_batches->execute([$id_producto]);
                    $batches = $stmt_batches->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode($batches);
                } catch (PDOException $e) {
                    error_log("Error al obtener lotes disponibles (AJAX): " . $e->getMessage());
                    echo json_encode(['error' => 'Error de base de datos al obtener lotes.']);
                }
                exit(); // Salir después de enviar la respuesta JSON
                break;

            case 'search_clients':
                
                header('Content-Type: application/json');
                $search_term = $_POST['search_term'] ?? '';

                if (empty($search_term)) {
                    echo json_encode([]);
                    exit();
                }

                $like_param = '%' . $search_term . '%';
                try {
                    $stmt_clients = $pdo->prepare("
                        SELECT id_cliente, nombre_cliente, ciudad, provincia, telefono, email, nif
                        FROM clientes
                        WHERE nombre_cliente LIKE ? OR ciudad LIKE ? OR provincia LIKE ? OR telefono LIKE ? OR email LIKE ? OR nif LIKE ?
                        ORDER BY nombre_cliente ASC
                        LIMIT 10
                    ");
                    $stmt_clients->execute([$like_param, $like_param, $like_param, $like_param, $like_param, $like_param]);
                    $clients = $stmt_clients->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode($clients);
                } catch (PDOException $e) {
                    error_log("Error searching clients (AJAX): " . $e->getMessage());
                    echo json_encode(['error' => 'Database error searching clients.']);
                }
                exit();
                break;

            // NEW: Action for getting shipping addresses of a client
            case 'get_shipping_addresses':
            case 'add_client_from_modal':
                header('Content-Type: application/json');
                try {
                    $nombre_cliente = $_POST['nombre_cliente'] ?? '';
                    if (empty($nombre_cliente)) {
                        throw new Exception("El nombre del cliente es obligatorio.");
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO clientes (nombre_cliente, nif, direccion, ciudad, provincia, codigo_postal, telefono, email)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        strtoupper($nombre_cliente),
                        strtoupper($_POST['nif'] ?? null),
                        strtoupper($_POST['direccion'] ?? null),
                        strtoupper($_POST['ciudad'] ?? null),
                        strtoupper($_POST['provincia'] ?? null),
                        strtoupper($_POST['codigo_postal'] ?? null),
                        strtoupper($_POST['telefono'] ?? null),
                        strtoupper($_POST['email'] ?? null)
                    ]);
                    $new_client_id = $pdo->lastInsertId();

                    if ($in_transaction) $pdo->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => 'Cliente creado con éxito.',
                        'new_client' => [
                            'id_cliente' => $new_client_id,
                            'nombre_cliente' => $nombre_cliente
                        ]
                    ]);

                } catch (Exception $e) {
                    if ($in_transaction) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Error al crear cliente: ' . $e->getMessage()]);
                }
                exit();
                break;

            case 'search_shipping_addresses': // Explicit action for search
                
                header('Content-Type: application/json');
                $id_cliente = $_POST['id_cliente'] ?? null;
                $search_term = $_POST['search_term'] ?? ''; // New search term parameter

                if (!$id_cliente) {
                    echo json_encode(['success' => false, 'message' => 'ID de cliente no proporcionado.']);
                    exit();
                }

                $query_params = [$id_cliente];
                $sql = "SELECT id_direccion_envio, nombre_direccion, direccion, ciudad, provincia, codigo_postal, es_principal FROM direcciones_envio WHERE id_cliente = ?";

                if (!empty($search_term)) {
                    $like_term = '%' . $search_term . '%';
                    $sql .= " AND (nombre_direccion LIKE ? OR direccion LIKE ? OR ciudad LIKE ? OR provincia LIKE ? OR codigo_postal LIKE ?)";
                    $query_params = array_merge($query_params, [$like_term, $like_term, $like_term, $like_term, $like_term]);
                }

                $sql .= " ORDER BY es_principal DESC, nombre_direccion ASC LIMIT 10"; // Limit results

                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($query_params);
                    $direcciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'direcciones' => $direcciones]);
                } catch (PDOException $e) {
                    error_log("Error searching shipping addresses (AJAX): " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Error al buscar direcciones de envío: ' . $e->getMessage()]);
                }
                exit();
                break;


            default:
                throw new Exception("Acción no reconocida.");
        }
    } catch (Exception $e) {
        if ($in_transaction && $pdo->inTransaction()) {
            $pdo->rollBack(); // Revertir la transacción en caso de error
        }
        // Para errores en solicitudes AJAX, no redirigir, solo mostrar el mensaje
        if (in_array($accion, ['get_available_batches', 'search_clients', 'get_shipping_addresses', 'search_shipping_addresses'])) {
            ob_clean(); // Limpiar cualquier búfer antes de enviar el error JSON
            header('Content-Type: application/json');
            echo json_encode(['error' => "Error en la operación: " . $e->getMessage()]);
            exit();
        } else {
            // Para solicitudes POST normales, establecer mensaje y redirigir
            mostrarMensaje("Error: " . $e->getMessage(), "danger");
            // Redirigir a la página anterior o a la vista de detalles si es posible
            $redirect_id = $_POST['id_factura'] ?? $_GET['id'] ?? null; // Intentar obtener ID de factura de POST o GET
            // NEW: Preserve from_parte_ruta_id on error redirect
            $redirect_parte_ruta_id = $_GET['from_parte_ruta_id'] ?? null;
            $redirect_url = "facturas_ventas.php";
            if ($redirect_id) {
                $redirect_url .= "?view=details&id=" . htmlspecialchars($redirect_id);
            }
            if ($redirect_parte_ruta_id) {
                $redirect_url .= ($redirect_id ? "&" : "?") . "from_parte_ruta_id=" . htmlspecialchars($redirect_parte_ruta_id);
            }
            header("Location: " . $redirect_url);
            exit();
        }
    }
}

// Cargar mensajes de la sesión si existen
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    $tipo_mensaje = $_SESSION['tipo_mensaje'];
    unset($_SESSION['mensaje']);
    unset($_SESSION['tipo_mensaje']);
}

// --- Lógica para CARGAR DATOS EN LA VISTA ---
$view = $_GET['view'] ?? 'list';
$facturas = [];
// MODIFICACIÓN: clientes_map_js para almacenar detalles de cliente y sus direcciones de envío
$clientes_map_js = [];
$productos = [];
$formas_pago = [];
$factura_actual = null;
$detalles_factura = [];
$cobros_factura = [];
$saldo_pendiente = 0;
$badge_class = ''; // Inicializar aquí para evitar 'Undefined variable' en la vista de detalles

// NEW: Variable to pass parte_ruta_id to JS for the "Return to Parte de Ruta" button
$current_parte_ruta_id = $_GET['from_parte_ruta_id'] ?? null;

try {
    // Cargar clientes y sus direcciones de envío
    $stmt_clientes = $pdo->query("SELECT id_cliente, nombre_cliente, nif, telefono, email, direccion, ciudad, provincia, codigo_postal FROM clientes ORDER BY nombre_cliente ASC");
    $clientes_disponibles = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clientes_disponibles as $cli) {
        $clientes_map_js[$cli['id_cliente']] = [
            'nombre_cliente' => $cli['nombre_cliente'],
            'nif' => $cli['nif'],
            'telefono' => $cli['telefono'],
            'email' => $cli['email'],
            'direccion' => $cli['direccion'],
            'ciudad' => $cli['ciudad'],
            'provincia' => $cli['provincia'],
            'codigo_postal' => $cli['codigo_postal'],
            'direcciones_envio' => [] // Inicializar array para direcciones de envío
        ];

        // Cargar direcciones de envío para cada cliente
        $stmt_direcciones = $pdo->prepare("SELECT id_direccion_envio, nombre_direccion, direccion, ciudad, provincia, codigo_postal, es_principal FROM direcciones_envio WHERE id_cliente = ? ORDER BY es_principal DESC, nombre_direccion ASC");
        $stmt_direcciones->execute([$cli['id_cliente']]);
        $clientes_map_js[$cli['id_cliente']]['direcciones_envio'] = $stmt_direcciones->fetchAll(PDO::FETCH_ASSOC);
    }

    // Cargar productos terminados (ahora también con porcentaje_iva_actual)
    $stmt_productos = $pdo->query("SELECT p.id_producto, p.nombre_producto, p.litros_por_unidad, p.precio_venta, p.stock_actual_unidades, p.porcentaje_iva_actual, a.nombre_articulo FROM productos p JOIN articulos a ON p.id_articulo = a.id_articulo ORDER BY p.nombre_producto ASC");
    $productos = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);

    // Cargar formas de pago
    $stmt_formas_pago = $pdo->query("SELECT id_forma_pago, nombre_forma_pago FROM formas_pago ORDER BY nombre_forma_pago ASC");
    $formas_pago = $stmt_formas_pago->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    mostrarMensaje("Error de base de datos al cargar datos iniciales: " . $e->getMessage(), "danger");
    // No redirigir aquí, ya que podría estar en el flujo de creación automática
    // header("Location: facturas_ventas.php"); // Redirigir a la lista en caso de error de carga
    // exit();
}

if ($view == 'list') {
    try {
        $stmt_facturas = $pdo->query("
            SELECT
                fv.id_factura,
                fv.fecha_factura,
                fv.id_cliente,
                fv.id_direccion_envio, -- NEW: Select id_direccion_envio
                c.nombre_cliente,
                de.nombre_direccion AS nombre_direccion_envio, -- NEW: Select shipping address name
                de.direccion AS direccion_envio_completa, -- NEW: Select full shipping address
                fv.total_factura_iva_incluido,
                fv.estado_pago,
                fv.id_pedido_origen,
                fv.id_parte_ruta_origen,
                (SELECT SUM(dfv.cantidad) FROM detalle_factura_ventas dfv WHERE dfv.id_factura = fv.id_factura) AS total_unidades_facturadas,
                (SELECT SUM(dfv.unidades_retiradas) FROM detalle_factura_ventas dfv WHERE dfv.id_factura = fv.id_factura) AS total_unidades_retiradas
            FROM
                facturas_ventas fv
            JOIN
                clientes c ON fv.id_cliente = c.id_cliente
            LEFT JOIN
                direcciones_envio de ON fv.id_direccion_envio = de.id_direccion_envio -- NEW: JOIN with direcciones_envio
            ORDER BY
                fv.fecha_factura DESC, fv.id_factura DESC
        ");
        $facturas = $stmt_facturas->fetchAll(PDO::FETCH_ASSOC);

        // Post-process to determine estado_retiro_calculado
        foreach ($facturas as &$factura) {
            if ($factura['total_unidades_facturadas'] == 0) {
                $factura['estado_retiro_calculado'] = 'Sin Líneas';
            } elseif ($factura['total_unidades_retiradas'] == 0) {
                $factura['estado_retiro_calculado'] = 'Pendiente';
            } elseif ($factura['total_unidades_retiradas'] < $factura['total_unidades_facturadas']) {
                $factura['estado_retiro_calculado'] = 'Parcial';
            } else { // $factura['total_unidades_retiradas'] >= $factura['total_unidades_facturadas']
                $factura['estado_retiro_calculado'] = 'Completado';
            }
        }
        unset($factura); // Break the reference with the last element
    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos al cargar facturas: " . $e->getMessage(), "danger");
    }
} elseif ($view == 'details' && isset($_GET['id'])) {
    $id_factura = $_GET['id'];
    try {
        // Cargar datos de la factura principal
        $stmt_factura_actual = $pdo->prepare("
            SELECT
                fv.*,
                c.nombre_cliente, c.nif, c.telefono, c.email,
                c.direccion AS cliente_direccion_principal, c.ciudad AS cliente_ciudad_principal, c.provincia AS cliente_provincia_principal, c.codigo_postal AS cliente_codigo_postal_principal, -- NEW: Fetch client's main address
                de.nombre_direccion AS nombre_direccion_envio, -- NEW: Shipping address name
                de.direccion AS direccion_envio, -- NEW: Shipping address full street
                de.ciudad AS ciudad_envio, -- NEW: Shipping address city
                de.provincia AS provincia_envio, -- NEW: Shipping address province
                de.codigo_postal AS codigo_postal_envio, -- NEW: Shipping address postal code
                p.fecha_pedido AS fecha_pedido_origen,
                p.tipo_entrega AS tipo_entrega_pedido_origen,
                p.estado_pedido AS estado_pedido_origen,
                pr.nombre_ruta AS nombre_ruta_origen,
                pr.nombre_ruta AS nombre_parte_ruta_origen
            FROM
                facturas_ventas fv
            JOIN
                clientes c ON fv.id_cliente = c.id_cliente
            LEFT JOIN
                direcciones_envio de ON fv.id_direccion_envio = de.id_direccion_envio -- NEW: JOIN with direcciones_envio
            LEFT JOIN
                pedidos p ON fv.id_pedido_origen = p.id_pedido
            LEFT JOIN
                rutas pr ON p.id_ruta = pr.id_ruta
            LEFT JOIN
                partes_paqueteria pp ON fv.id_parte_ruta_origen = pp.id_parte_paqueteria
            WHERE fv.id_factura = ?
        ");
        $stmt_factura_actual->execute([$id_factura]);
        $factura_actual = $stmt_factura_actual->fetch(PDO::FETCH_ASSOC);

        if (!$factura_actual) {
            mostrarMensaje("Factura no encontrada.", "danger");
            header("Location: facturas_ventas.php");
            exit();
        }

        // Cargar detalles de las líneas de factura
        $stmt_detalles = $pdo->prepare("
            SELECT
                dfv.*,
                p.nombre_producto,
                p.litros_por_unidad,
                p.stock_actual_unidades -- Incluir stock actual para validaciones en JS
            FROM
                detalle_factura_ventas dfv
            JOIN
                productos p ON dfv.id_producto = p.id_producto
            WHERE dfv.id_factura = ?
            ORDER BY dfv.id_detalle_factura ASC
        ");
        $stmt_detalles->execute([$id_factura]);
        $detalles_factura = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

        // Calcular total de unidades facturadas y retiradas para el estado de retiro
        $total_unidades_facturadas = 0;
        $total_unidades_retiradas = 0;
        foreach ($detalles_factura as $detalle) {
            $total_unidades_facturadas += $detalle['cantidad'];
            $total_unidades_retiradas += $detalle['unidades_retiradas'];
        }

        if ($total_unidades_facturadas == 0) {
            $factura_actual['estado_retiro_calculado'] = 'Sin Líneas';
        } elseif ($total_unidades_retiradas == 0) {
            $factura_actual['estado_retiro_calculado'] = 'Pendiente';
        } elseif ($total_unidades_retiradas < $total_unidades_facturadas) {
            $factura_actual['estado_retiro_calculado'] = 'Parcial';
        } else { // $factura['total_unidades_retiradas'] >= $factura['total_unidades_facturadas']
            $factura_actual['estado_retiro_calculado'] = 'Completado';
        }


        // Cargar cobros de la factura
        $stmt_cobros = $pdo->prepare("
            SELECT
                cf.*,
                fp.nombre_forma_pago
            FROM
                cobros_factura cf
            JOIN
                formas_pago fp ON cf.id_forma_pago = fp.id_forma_pago
            WHERE cf.id_factura = ?
            ORDER BY cf.fecha_cobro DESC, cf.id_cobro DESC
        ");
        $stmt_cobros->execute([$id_factura]);
        $cobros_factura = $stmt_cobros->fetchAll(PDO::FETCH_ASSOC);

        // Calcular saldo pendiente
        $total_cobrado = array_sum(array_column($cobros_factura, 'cantidad_cobrada'));
        $saldo_pendiente = $factura_actual['total_factura_iva_incluido'] - $total_cobrado;

        // Determinar clase de badge para estado de pago
        switch ($factura_actual['estado_pago']) {
            case 'pendiente':
                $badge_class = 'bg-warning text-dark';
                break;
            case 'parcialmente_pagada':
                $badge_class = 'bg-info';
                break;
            case 'pagada':
                $badge_class = 'bg-success';
                break;
            default:
                $badge_class = 'bg-secondary';
                break;
        }

    } catch (PDOException $e) {
        mostrarMensaje("Error de base de datos al cargar detalles de la factura: " . $e->getMessage(), "danger");
        header("Location: facturas_ventas.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Facturas de Ventas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <style>
        /* Estilos generales */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
        }
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        .content {
            flex-grow: 1;
            padding: 20px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #0056b3;
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #0056b3;
            border-color: #0056b3;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background-color: #004494;
            border-color: #004494;
        }
        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
            border-radius: 8px;
        }
        .btn-success:hover {
            background-color: #218838;
            border-color: #218838;
        }
        .btn-info {
            background-color: #17a2b8;
            border-color: #17a2b8;
            border-radius: 8px;
        }
        .btn-info:hover {
            background-color: #138496;
            border-color: #138496;
        }
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            border-radius: 8px;
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #c82333;
        }
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #333; /* Darker text for visibility */
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #d39e00;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            font-weight: 600;
            padding: 12px 25px;
            border-radius: 8px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #545b62;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .table thead {
            background-color: #e9ecef;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .form-control, .form-select {
            border-radius: 8px;
        }
        .modal-content {
            border-radius: 15px;
        }
        .modal-header {
            background-color: #0056b3;
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .alert {
            border-radius: 8px;
        }

        /* Estilos del Sidebar (copia de sidebar.php) */
        :root {
            --sidebar-width: 250px;
            --primary-color: #007bff; /* Un azul más estándar de Bootstrap */
            --sidebar-bg: #343a40; /* Fondo oscuro */
            --sidebar-link: #adb5bd; /* Gris claro para enlaces */
            --sidebar-hover: #495057; /* Gris un poco más oscuro al pasar el ratón */
            --sidebar-active: #0056b3; /* Azul más oscuro para el elemento activo */
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            flex-shrink: 0; /* Evita que el sidebar se encoja */
            height: 100vh; /* Asegura que el sidebar ocupe toda la altura de la ventana */
            position: sticky; /* Hace que el sidebar se mantenga visible al hacer scroll */
            top: 0; /* Alinea el sidebar con la parte superior de la ventana */
            overflow-y: auto; /* Permite el scroll si el contenido del sidebar es demasiado largo */
        }

        .sidebar-header {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header .app-logo {
            max-width: 100px; /* Adjust as needed */
            height: auto;
            margin-bottom: 10px;
        }

        .sidebar-header .app-icon {
            font-size: 3rem;
            color: #fff;
            margin-bottom: 10px;
        }

        .sidebar-header .app-name {
            color: #fff;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            display: flex;
            flex-direction: column;
            height: 100%; /* Asegura que la lista ocupe toda la altura disponible */
        }

        .sidebar-menu-item {
            margin-bottom: 5px;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            color: var(--sidebar-link);
            text-decoration: none;
            border-radius: 8px;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .sidebar-menu-link i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .sidebar-menu-link:hover {
            background-color: var(--sidebar-hover);
            color: white;
        }

        .sidebar-menu-link.active {
            background-color: var(--sidebar-active);
            color: white;
            font-weight: bold;
        }

        /* Estilos adicionales para los menús desplegables */
        .sidebar-submenu-list {
            padding-left: 0;
            margin-top: 5px;
            margin-bottom: 0;
            list-style: none;
        }

        .sidebar-submenu-item {
            margin-bottom: 2px;
        }

        .sidebar-submenu-link {
            display: block;
            padding: 8px 15px 8px 55px; /* Ajuste para la indentación */
            color: var(--sidebar-link);
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
            font-size: 0.95rem;
            border-left: 5px solid transparent; /* Para la línea activa del submenú */
        }

        .sidebar-submenu-link:hover, .sidebar-submenu-link.active {
            background-color: var(--sidebar-hover);
            color: white;
            border-left-color: var(--primary-color);
        }

        /* Ajuste del icono de flecha del colapsable */
        .sidebar-menu-link .sidebar-collapse-icon {
            transition: transform 0.3s ease;
        }
        .sidebar-menu-link[aria-expanded="true"] .sidebar-collapse-icon {
            transform: rotate(180deg);
        }

        /* Estilo para empujar el elemento de cerrar sesión al final */
        .sidebar-menu-item.mt-auto {
            margin-top: auto !important; /* Empuja este elemento al final de la flexbox */
            border-top: 1px solid rgba(255, 255, 255, 0.1); /* Separador visual */
            padding-top: 10px;
        }

        /* Styles for client search dropdown */
        .client-search-container {
            position: relative;
        }
        .client-search-results {
            position: absolute;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .client-search-results-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        .client-search-results-item:last-child {
            border-bottom: none;
        }
        .client-search-results-item:hover {
            background-color: #f8f9fa;
        }

        /* Custom style for scrollable table */
        .scrollable-table-container {
            max-height: 750px; /* Adjusted height for about 10-15 rows */
            overflow-y: auto;
            border: 1px solid #e9ecef; /* Optional: add a border to the scrollable area */
            border-radius: 8px;
        }
        .scrollable-table-container table {
            margin-bottom: 0; /* Remove default table margin if inside a scrollable div */
        }

        /* Badges for status */
        .badge.bg-pending {
            background-color: #ffc107 !important; /* Warning yellow */
            color: #212529 !important; /* Dark text for contrast */
        }
        .badge.bg-partial {
            background-color: #17a2b8 !important; /* Info blue */
        }
        .badge.bg-completed {
            background-color: #28a745 !important; /* Success green */
        }
        .badge.bg-danger {
            background-color: #dc3545 !important; /* Danger red */
        }
        .badge.bg-secondary {
            background-color: #6c757d !important; /* Secondary gray */
        }
        .badge.bg-dark {
            background-color: #343a40 !important; /* Dark */
        }
        .badge.bg-purple {
            background-color: #6f42c1; /* Púrpura */
            color: white;
        }
        /* Specific badge for 'Sin Líneas' */
        .badge.bg-no-lines {
            background-color: #adb5bd !important; /* Lighter gray */
            color: #212529 !important;
        }

        /* NEW: Styles for 3-column layout in invoice header details */
        .invoice-header-details p {
            margin-bottom: 0.5rem; /* Reduce space between paragraphs */
        }
        .invoice-header-financials .form-control {
            max-width: 120px; /* Adjust width of input fields for discount/surcharge */
        }
        /* Justify right for financial values in header */
        /* Specific alignment for discount/surcharge input fields */
        .invoice-header-financials .d-flex .form-control {
            text-align: right; /* Justify input values to the right */
        }

        /* Styles for filter buttons */
        .filter-btn-group .btn {
            border-radius: 8px; /* Consistent rounded corners */
            margin-left: 5px; /* Spacing between buttons, changed from margin-right */
            transition: all 0.2s ease-in-out; /* Smooth transitions */
        }

        .filter-btn-group .btn.active {
            box-shadow: 0 4px 8px rgba(0,0,0,0.2); /* Add shadow for active state */
            transform: translateY(-2px); /* Slight lift for active state */
        }

        /* Specific styles for filter buttons */
        .filter-btn-group .btn-secondary.active {
            background-color: #5a6268; /* Darker secondary when active */
            border-color: #545b62;
            color: white;
        }
        .filter-btn-group .btn-warning.active {
            background-color: #e0a800; /* Darker warning when active */
            border-color: #d39e00;
            color: #333;
        }
        .filter-btn-group .btn-info.active {
            background-color: #138496; /* Darker info when active */
            border-color: #117a8b;
            color: white;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; // Incluir la barra lateral ?>
        <div class="content">
            <div class="container-fluid">
                <h1 class="mb-4">Gestión de Facturas de Ventas</h1>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; // Mensaje ya está htmlspecialchars si viene de $_SESSION ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($view == 'list'): ?>
                    <div class="card">
                        <div class="card-header">
                            Lista de Facturas de Ventas
                        </div>
                        <div class="card-body">
                            <!-- NEW: Flex container for buttons -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInvoiceModal">
                                    <i class="bi bi-plus-circle"></i> Nueva Factura
                                </button>

                                <!-- Filter Buttons Group -->
                                <div class="filter-btn-group d-flex flex-wrap">
                                    <button class="btn btn-secondary filter-btn active" data-filter-type="all">Mostrar Todos</button>
                                    <button class="btn btn-outline-warning filter-btn" data-filter-type="estado_retiro" data-filter-value="Pendiente">Pendientes de Retirada</button>
                                    <button class="btn btn-outline-info filter-btn" data-filter-type="estado_pago" data-filter-value="pendiente">Pendientes de Cobro</button>
                                </div>
                            </div>

                            <div class="table-responsive scrollable-table-container">
                                <table class="table table-striped table-hover" id="invoicesTable">
                                    <thead>
                                        <tr>
                                            <th>ID Factura</th>
                                            <th>Fecha</th>
                                            <th>Cliente</th>
                                            <th>Dirección Envío</th> <!-- NEW: Added column for shipping address -->
                                            <th class="text-end">Total (IVA Inc.)</th>
                                            <th>Estado Pago</th>
                                            <th>Estado Retiro</th>
                                            <th>Pedido Origen</th>
                                            <th>Parte de Ruta Origen</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($facturas)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center">No hay facturas de ventas registradas.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($facturas as $factura):
                                                $badge_class_pago = '';
                                                switch ($factura['estado_pago']) {
                                                    case 'pendiente': $badge_class_pago = 'bg-warning text-dark'; break;
                                                    case 'parcialmente_pagada': $badge_class_pago = 'bg-info'; break;
                                                    case 'pagada': $badge_class_pago = 'bg-success'; break;
                                                    default: $badge_class_pago = 'bg-secondary'; break;
                                                }

                                                $badge_class_retiro = '';
                                                switch ($factura['estado_retiro_calculado']) {
                                                    case 'Pendiente': $badge_class_retiro = 'bg-warning text-dark'; break;
                                                    case 'Parcial': $badge_class_retiro = 'bg-info'; break;
                                                    case 'Completado': $badge_class_retiro = 'bg-success'; break;
                                                    case 'Sin Líneas': $badge_class_retiro = 'bg-no-lines'; break;
                                                    default: $badge_class_retiro = 'bg-secondary'; break;
                                                }
                                            ?>
                                                <tr
                                                    data-estado-pago="<?php echo htmlspecialchars($factura['estado_pago']); ?>"
                                                    data-estado-retiro="<?php echo htmlspecialchars($factura['estado_retiro_calculado']); ?>"
                                                >
                                                    <td><?php echo htmlspecialchars($factura['id_factura']); ?></td>
                                                    <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($factura['fecha_factura']))); ?></td>
                                                    <td><?php echo htmlspecialchars($factura['nombre_cliente']); ?></td>
                                                    <td>
                                                        <?php
                                                            // Display shipping address name and full address if available, otherwise client's main address
                                                            if (!empty($factura['nombre_direccion_envio'])) {
                                                                echo htmlspecialchars($factura['nombre_direccion_envio']);
                                                                if (!empty($factura['direccion_envio_completa'])) {
                                                                    echo ' <small class="text-muted">(' . htmlspecialchars($factura['direccion_envio_completa']) . ')</small>';
                                                                }
                                                            } else {
                                                                // Fallback to client's main address if no specific shipping address chosen for the invoice
                                                                $client_main_address = $clientes_map_js[$factura['id_cliente']]['direccion'] ?? 'N/A';
                                                                $client_main_city = $clientes_map_js[$factura['id_cliente']]['ciudad'] ?? 'N/A';
                                                                echo 'Dirección Principal';
                                                                echo ' <small class="text-muted">(' . htmlspecialchars($client_main_address) . ', ' . htmlspecialchars($client_main_city) . ')</small>';
                                                            }
                                                        ?>
                                                    </td>
                                                    <td class="text-end"><?php echo number_format($factura['total_factura_iva_incluido'], 2, ',', '.'); ?> €</td>
                                                    <td><span class="badge <?php echo htmlspecialchars($badge_class_pago); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $factura['estado_pago']))); ?></span></td>
                                                    <td><span class="badge <?php echo htmlspecialchars($badge_class_retiro); ?>"><?php echo htmlspecialchars($factura['estado_retiro_calculado']); ?></span></td>
                                                    <td>
                                                        <?php if ($factura['id_pedido_origen']): ?>
                                                            <a href="pedidos.php?view=details&id=<?php echo htmlspecialchars($factura['id_pedido_origen']); ?>" class="badge bg-primary">Pedido #<?php echo htmlspecialchars($factura['id_pedido_origen']); ?></a>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($factura['id_parte_ruta_origen']): ?>
                                                            <a href="partes_ruta.php?view=details&id=<?php echo htmlspecialchars($factura['id_parte_ruta_origen']); ?>" class="badge bg-purple">Parte #<?php echo htmlspecialchars($factura['id_parte_ruta_origen']); ?></a>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <a href="facturas_ventas.php?view=details&id=<?php echo htmlspecialchars($factura['id_factura']); ?>" class="btn btn-info btn-sm me-1" title="Ver Detalles">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <button class="btn btn-danger btn-sm" onclick="confirmDeleteInvoice(<?php echo htmlspecialchars($factura['id_factura']); ?>)" title="Eliminar Factura">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Añadir Nueva Factura -->
                    <div class="modal fade" id="addInvoiceModal" tabindex="-1" aria-labelledby="addInvoiceModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="facturas_ventas.php" method="POST" id="invoiceForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addInvoiceModalLabel">Nueva Factura de Venta</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="agregar_factura">
                                        <div class="mb-3">
                                            <label for="fecha_factura" class="form-label">Fecha Factura</label>
                                            <input type="date" class="form-control" id="fecha_factura" name="fecha_factura" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="client_search_input" class="form-label">Cliente</label>
                                            <div class="client-search-container">
                                                <input type="text" class="form-control" id="client_search_input" placeholder="Buscar cliente por nombre, NIF, ciudad..." autocomplete="off" value="<?php echo htmlspecialchars($preselect_client_name); ?>">
                                                <input type="hidden" id="id_cliente_selected" name="id_cliente" value="<?php echo htmlspecialchars($preselect_client_id); ?>">
                                                <div id="client_search_results" class="client-search-results"></div>
                                            </div>
                                            <small class="text-danger" id="client_selection_error" style="display:none;">Por favor, seleccione un cliente de la lista.</small>
                                            <div class="mt-2">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#addClientModal">
                                                    <i class="bi bi-person-plus"></i> Crear Nuevo Cliente
                                                </button>
                                            </div>
                                        </div>
                                        <!-- NEW: Campo para Dirección de Envío con búsqueda -->
                                        <div class="mb-3" id="shippingAddressGroup" style="display: none;">
                                            <label for="id_direccion_envio" class="form-label">Dirección de Envío</label>
                                            <select id="id_direccion_envio" name="id_direccion_envio" placeholder="Seleccione una dirección..."></select>
                                            <small class="text-muted" id="no_shipping_addresses_info" style="display:none;">Este cliente no tiene direcciones de envío secundarias. Se usará la dirección principal del cliente.</small>
                                        </div>

                                        <div class="mb-3">
                                            <label for="descuento_global" class="form-label">Descuento Global (€)</label>
                                            <input type="number" class="form-control" id="descuento_global" name="descuento_global" step="0.01" min="0" value="0.00">
                                        </div>
                                        <div class="mb-3">
                                            <label for="recargo_global" class="form-label">Recargo Global (€)</label>
                                            <input type="number" class="form-control" id="recargo_global" name="recargo_global" step="0.01" min="0" value="0.00">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary" id="submitNewInvoiceBtn">Crear Factura</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Añadir Nuevo Cliente -->
                    <div class="modal fade" id="addClientModal" tabindex="-1" aria-labelledby="addClientModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form id="addClientForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addClientModalLabel">Crear Nuevo Cliente</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="new_nombre_cliente" class="form-label">Nombre del Cliente</label>
                                                <input type="text" class="form-control" id="new_nombre_cliente" name="nombre_cliente" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="new_nif" class="form-label">NIF</label>
                                                <input type="text" class="form-control" id="new_nif" name="nif">
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="new_direccion" class="form-label">Dirección</label>
                                            <input type="text" class="form-control" id="new_direccion" name="direccion">
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="new_ciudad" class="form-label">Ciudad</label>
                                                <input type="text" class="form-control" id="new_ciudad" name="ciudad">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="new_provincia" class="form-label">Provincia</label>
                                                <input type="text" class="form-control" id="new_provincia" name="provincia">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="new_codigo_postal" class="form-label">Código Postal</label>
                                                <input type="text" class="form-control" id="new_codigo_postal" name="codigo_postal">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="new_telefono" class="form-label">Teléfono</label>
                                                <input type="text" class="form-control" id="new_telefono" name="telefono">
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="new_email" class="form-label">Email</label>
                                            <input type="email" class="form-control" id="new_email" name="email">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Guardar Cliente</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php elseif ($view == 'details' && $factura_actual): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            Detalles de Factura #<?php echo htmlspecialchars($factura_actual['id_factura']); ?>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3 invoice-header-details">
                                <div class="col-md-6">
                                    <p><strong>Fecha Factura:</strong> <?php echo htmlspecialchars(date('d/m/Y', strtotime($factura_actual['fecha_factura']))); ?></p>
                                    <p>
                                        <strong>Cliente:</strong>
                                        <?php echo htmlspecialchars($factura_actual['nombre_cliente']); ?>
                                        <a href="clientes.php?id=<?php echo htmlspecialchars($factura_actual['id_cliente']); ?>" class="btn btn-sm btn-outline-secondary ms-2" title="Ver/Modificar Ficha Cliente">
                                            <i class="bi bi-person-lines-fill"></i> Ver Ficha
                                        </a>
                                    </p>
                                    <p>
                                        <strong>Dirección de Envío:</strong>
                                        <?php
                                            // Mostrar nombre y dirección de envío o dirección principal del cliente
                                            if (!empty($factura_actual['nombre_direccion_envio'])) {
                                                echo htmlspecialchars($factura_actual['nombre_direccion_envio']);
                                                echo ' <small class="text-muted">(' . htmlspecialchars($factura_actual['direccion_envio'] ?? '') . ', ' . htmlspecialchars($factura_actual['ciudad_envio'] ?? '') . ', ' . htmlspecialchars($factura_actual['provincia_envio'] ?? '') . ' ' . htmlspecialchars($factura_actual['codigo_postal_envio'] ?? '') . ')</small>';
                                            } else {
                                                // Display client's main address if no specific shipping address is chosen
                                                $client_main_address_full = '';
                                                if (!empty($factura_actual['cliente_direccion_principal'])) {
                                                    $client_main_address_full .= htmlspecialchars($factura_actual['cliente_direccion_principal']);
                                                }
                                                if (!empty($factura_actual['cliente_ciudad_principal'])) {
                                                    $client_main_address_full .= (!empty($client_main_address_full) ? ', ' : '') . htmlspecialchars($factura_actual['cliente_ciudad_principal']);
                                                }
                                                if (!empty($factura_actual['cliente_provincia_principal'])) {
                                                    $client_main_address_full .= (!empty($client_main_address_full) ? ', ' : '') . htmlspecialchars($factura_actual['cliente_provincia_principal']);
                                                }
                                                if (!empty($factura_actual['cliente_codigo_postal_principal'])) {
                                                    $client_main_address_full .= (!empty($client_main_address_full) ? ' ' : '') . htmlspecialchars($factura_actual['cliente_codigo_postal_principal']);
                                                }

                                                echo 'Dirección Principal del Cliente';
                                                if (!empty($client_main_address_full)) {
                                                    echo ' <small class="text-muted">(' . $client_main_address_full . ')</small>';
                                                }
                                            }
                                        ?>
                                    </p>
                                    <p><strong>NIF:</strong> <?php echo htmlspecialchars($factura_actual['nif'] ?? 'N/A'); ?></p>
                                    <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($factura_actual['telefono'] ?? 'N/A'); ?></p>
                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($factura_actual['email'] ?? 'N/A'); ?></p>
                                </div>
                                <div class="col-md-3 invoice-header-financials">
                                    <!-- Formulario para Descuento Global -->
                                    <form id="updateDiscountForm" action="facturas_ventas.php" method="POST" class="mb-2">
                                        <input type="hidden" name="accion" value="update_global_discount">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <div class="d-flex justify-content-start align-items-center">
                                            <label for="descuento_global_edit" class="form-label mb-0 me-2"><strong>Descuento Global:</strong></label>
                                            <input type="number" class="form-control w-auto text-end me-2" id="descuento_global_edit" name="descuento_global" step="0.01" min="0" value="<?php echo number_format($factura_actual['descuento_global_aplicado'], 2, '.', ''); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Actualizar Descuento">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                    </form>
                                    <!-- Formulario para Recargo Global -->
                                    <form id="updateSurchargeForm" action="facturas_ventas.php" method="POST" class="mb-2">
                                        <input type="hidden" name="accion" value="update_global_surcharge">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <div class="d-flex justify-content-start align-items-center">
                                            <label for="recargo_global_edit" class="form-label mb-0 me-2"><strong>Recargo Global:</strong></label>
                                            <input type="number" class="form-control w-auto text-end me-2" id="recargo_global_edit" name="recargo_global" step="0.01" min="0" value="<?php echo number_format($factura_actual['recargo_global_aplicado'], 2, '.', ''); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Actualizar Recargo">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                    </form>
                                    <p class="d-flex justify-content-between"><strong>Base Imponible Total:</strong> <span class="fs-5 text-dark"><?php echo number_format($factura_actual['total_factura'], 2, ',', '.'); ?> €</span></p>
                                    <p class="d-flex justify-content-between"><strong>Total IVA:</strong> <span class="fs-5 text-dark"><?php echo number_format($factura_actual['total_iva_factura'], 2, ',', '.'); ?> €</span></p>
                                </div>
                                <div class="col-md-3">
                                    <p class="d-flex justify-content-between"><strong>Total Factura (IVA Inc.):</strong> <span class="fs-4 text-primary"><?php echo number_format($factura_actual['total_factura_iva_incluido'], 2, ',', '.'); ?> €</span></p>
                                    <p class="d-flex justify-content-between"><strong>Saldo Pendiente:</strong> <span class="fs-5 <?php echo ($saldo_pendiente > 0) ? 'text-danger' : 'text-success'; ?>"><?php echo number_format($saldo_pendiente, 2, ',', '.'); ?> €</span></p>
                                    <p><strong>Estado Pago:</strong> <span class="badge <?php echo htmlspecialchars($badge_class); ?> fs-6"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $factura_actual['estado_pago']))); ?></span></p>
                                    <p><strong>Estado Retiro:</strong> <span class="badge <?php echo htmlspecialchars($badge_class_retiro); ?> fs-6"><?php echo htmlspecialchars($factura_actual['estado_retiro_calculado']); ?></span></p>
                                    <?php if ($factura_actual['id_pedido_origen']): ?>
                                        <p><strong>Pedido Origen:</strong> <a href="pedidos.php?view=details&id=<?php echo htmlspecialchars($factura_actual['id_pedido_origen']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-seam"></i> Ver Pedido #<?php echo htmlspecialchars($factura_actual['id_pedido_origen']); ?></a></p>
                                    <?php endif; ?>
                                    <?php if ($factura_actual['id_parte_ruta_origen']): ?>
                                        <p><strong>Parte de Ruta Origen:</strong> <a href="partes_ruta.php?view=details&id=<?php echo htmlspecialchars($factura_actual['id_parte_ruta_origen']); ?>" class="btn btn-sm btn-outline-purple"><i class="bi bi-truck"></i> Ver Parte #<?php echo htmlspecialchars($factura_actual['id_parte_ruta_origen']); ?></a></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="facturas_ventas.php" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Volver a la Lista</a>
                            <button class="btn btn-warning mb-3 ms-2" data-bs-toggle="modal" data-bs-target="#editFacturaModal">
                                <i class="bi bi-pencil"></i> Editar Factura
                            </button>
                            <?php if ($current_parte_ruta_id): ?>
                                <a href="partes_ruta.php?view=details&id=<?php echo htmlspecialchars($current_parte_ruta_id); ?>" class="btn btn-info mb-3 ms-2">
                                    <i class="bi bi-truck"></i> Volver a Parte de Ruta #<?php echo htmlspecialchars($current_parte_ruta_id); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            Líneas de Detalle de Factura
                        </div>
                        <div class="card-body">
                            <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addLineaModal">
                                <i class="bi bi-plus-circle"></i> Añadir Línea
                            </button>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th class="text-end">Cantidad</th>
                                            <th class="text-end">Precio Unitario Base</th>
                                            <th class="text-end">Precio Unitario (IVA Inc.)</th>
                                            <th class="text-end">IVA (%)</th>
                                            <th class="text-end">Importe IVA</th>
                                            <th class="text-end">Subtotal (Sin IVA)</th>
                                            <th class="text-end">Total Línea (IVA Inc.)</th>
                                            <th class="text-end">Unidades Retiradas</th>
                                            <th>Estado Retiro</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($detalles_factura)): ?>
                                            <tr>
                                                <td colspan="11" class="text-center">No hay líneas de detalle para esta factura.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($detalles_factura as $detalle):
                                                $badge_class_retiro_linea = '';
                                                switch ($detalle['estado_retiro']) {
                                                    case 'Pendiente': $badge_class_retiro_linea = 'bg-warning text-dark'; break;
                                                    case 'Parcial': $badge_class_retiro_linea = 'bg-info'; break;
                                                    case 'Completado': $badge_class_retiro_linea = 'bg-success'; break;
                                                    default: $badge_class_retiro_linea = 'bg-secondary'; break;
                                                }
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($detalle['nombre_producto']); ?></td>
                                                    <td class="text-end"><?php echo htmlspecialchars($detalle['cantidad']); ?></td>
                                                    <td class="text-end"><?php echo number_format($detalle['precio_unitario_base'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['precio_unitario'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['porcentaje_iva_aplicado_en_factura'], 2, ',', '.'); ?></td>
                                                    <td class="text-end"><?php echo number_format($detalle['importe_iva_linea'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['subtotal_linea_sin_iva'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo number_format($detalle['total_linea_iva_incluido'], 2, ',', '.'); ?> €</td>
                                                    <td class="text-end"><?php echo htmlspecialchars($detalle['unidades_retiradas']); ?></td>
                                                    <td><span class="badge <?php echo htmlspecialchars($badge_class_retiro_linea); ?>"><?php echo htmlspecialchars($detalle['estado_retiro']); ?></span></td>
                                                    <td class="text-center">
                                                        <?php if ($detalle['unidades_retiradas'] < $detalle['cantidad']): ?>
                                                            <button class="btn btn-success btn-sm me-1" data-bs-toggle="modal" data-bs-target="#retirarStockModal"
                                                                data-id-detalle-factura="<?php echo htmlspecialchars($detalle['id_detalle_factura']); ?>"
                                                                data-id-producto="<?php echo htmlspecialchars($detalle['id_producto']); ?>"
                                                                data-unidades-pendientes="<?php echo htmlspecialchars($detalle['cantidad'] - $detalle['unidades_retiradas']); ?>"
                                                                data-nombre-producto="<?php echo htmlspecialchars($detalle['nombre_producto']); ?>"
                                                            >
                                                                <i class="bi bi-box-arrow-up"></i> Retirar
                                                            </button>
                                                        <?php endif; ?>
                                                        <button class="btn btn-danger btn-sm" onclick="confirmDeleteDetalle(<?php echo htmlspecialchars($detalle['id_detalle_factura']); ?>, <?php echo htmlspecialchars($factura_actual['id_factura']); ?>)" title="Eliminar Línea">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            Cobros de Factura
                        </div>
                        <div class="card-body">
                            <?php if ($saldo_pendiente > 0): ?>
                                <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addCobroModal"
                                    data-saldo-pendiente="<?php echo number_format($saldo_pendiente, 2, '.', ''); ?>">
                                    <i class="bi bi-cash"></i> Registrar Cobro
                                </button>
                            <?php elseif ($saldo_pendiente < 0): ?>
                                <button class="btn btn-warning mb-3" data-bs-toggle="modal" data-bs-target="#addReembolsoModal"
                                    data-saldo-a-favor="<?php echo number_format(abs($saldo_pendiente), 2, '.', ''); ?>">
                                    <i class="bi bi-arrow-return-left"></i> Registrar Reembolso
                                </button>
                            <?php else: ?>
                                <p class="text-success">Esta factura está completamente pagada.</p>
                            <?php endif; ?>

                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID Cobro</th>
                                            <th>Fecha Cobro</th>
                                            <th class="text-end">Cantidad Cobrada</th>
                                            <th>Forma de Pago</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($cobros_factura)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No hay cobros registrados para esta factura.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($cobros_factura as $cobro): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($cobro['id_cobro']); ?></td>
                                                    <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($cobro['fecha_cobro']))); ?></td>
                                                    <td class="text-end"><?php echo number_format($cobro['cantidad_cobrada'], 2, ',', '.'); ?> €</td>
                                                    <td><?php echo htmlspecialchars($cobro['nombre_forma_pago']); ?></td>
                                                    <td class="text-center">
                                                        <button class="btn btn-danger btn-sm" onclick="confirmDeleteCobro(<?php echo htmlspecialchars($cobro['id_cobro']); ?>, <?php echo htmlspecialchars($factura_actual['id_factura']); ?>)" title="Eliminar Cobro">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Añadir Línea de Detalle -->
                    <div class="modal fade" id="addLineaModal" tabindex="-1" aria-labelledby="addLineaModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="facturas_ventas.php" method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addLineaModalLabel">Añadir Línea a Factura #<?php echo htmlspecialchars($factura_actual['id_factura']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="agregar_detalle_linea">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <div class="mb-3">
                                            <label for="id_producto" class="form-label">Producto</label>
                                            <select class="form-select" id="id_producto" name="id_producto" required onchange="updateProductDetails()">
                                                <option value="">Seleccione un producto</option>
                                                <?php foreach ($productos as $producto): ?>
                                                    <option
                                                        value="<?php echo htmlspecialchars($producto['id_producto']); ?>"
                                                        data-precio-venta="<?php echo htmlspecialchars($producto['precio_venta'] ?? 0); ?>"
                                                        data-iva-porcentaje="<?php echo htmlspecialchars($producto['porcentaje_iva_actual'] ?? 0); ?>"
                                                        data-stock-actual="<?php echo htmlspecialchars($producto['stock_actual_unidades'] ?? 0); ?>"
                                                    >
                                                        <?php echo htmlspecialchars($producto['nombre_producto']); ?> (Stock: <?php echo htmlspecialchars($producto['stock_actual_unidades']); ?> uds)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted" id="productStockInfo"></small>
                                        </div>
                                        <div class="mb-3">
                                            <label for="cantidad" class="form-label">Cantidad</label>
                                            <input type="number" class="form-control" id="cantidad" name="cantidad" min="1" required oninput="updateProductDetails()">
                                        </div>
                                        <div class="mb-3">
                                            <p><strong>Precio Unitario Base:</strong> <span id="precio_unitario_base_display">0.00 €</span></p>
                                            <p><strong>Precio Unitario (IVA Inc.):</strong> <span id="precio_unitario_iva_inc_display">0.00 €</span></p>
                                            <p><strong>IVA (%):</strong> <span id="iva_porcentaje_display">0.00</span></p>
                                            <p><strong>Importe IVA Línea:</strong> <span id="importe_iva_linea_display">0.00 €</span></p>
                                            <p><strong>Subtotal Línea (Sin IVA):</strong> <span id="subtotal_linea_sin_iva_display">0.00 €</span></p>
                                            <p><strong>Total Línea (IVA Inc.):</strong> <span id="total_linea_iva_inc_display">0.00 €</span></p>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Añadir Línea</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Retirar Stock -->
                    <div class="modal fade" id="retirarStockModal" tabindex="-1" aria-labelledby="retirarStockModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="facturas_ventas.php" method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="retirarStockModalLabel">Retirar Stock para <span id="retirar_producto_nombre"></span></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="retirar_stock_linea">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <input type="hidden" id="retirar_id_detalle_factura" name="id_detalle_factura">
                                        <input type="hidden" id="retirar_id_producto" name="id_producto">

                                        <p>Unidades pendientes de retiro: <strong id="unidades_pendientes_display"></strong></p>

                                        <div class="mb-3">
                                            <label for="unidades_a_retirar" class="form-label">Cantidad a Retirar</label>
                                            <input type="number" class="form-control" id="unidades_a_retirar" name="unidades_a_retirar" min="1" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="id_detalle_actividad_seleccionado" class="form-label">Seleccionar Lote de Envasado</label>
                                            <select class="form-select" id="id_detalle_actividad_seleccionado" name="id_detalle_actividad_seleccionado" required>
                                                <option value="">Cargando lotes...</option>
                                            </select>
                                            <small class="text-danger" id="lote_selection_error" style="display:none;">Por favor, seleccione un lote.</small>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Confirmar Retiro</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Añadir Cobro -->
                    <div class="modal fade" id="addCobroModal" tabindex="-1" aria-labelledby="addCobroModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="facturas_ventas.php" method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addCobroModalLabel">Añadir Cobro a Factura #<?php echo htmlspecialchars($factura_actual['id_factura']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="agregar_cobro">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <p>Saldo Pendiente Actual: <strong class="fs-5 <?php echo ($saldo_pendiente > 0) ? 'text-danger' : 'text-success'; ?>"><?php echo number_format($saldo_pendiente, 2, ',', '.'); ?> €</strong></p>
                                        <div class="mb-3">
                                            <label for="fecha_cobro" class="form-label">Fecha Cobro</label>
                                            <input type="date" class="form-control" id="fecha_cobro" name="fecha_cobro" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="cantidad_cobrada" class="form-label">Cantidad Cobrada</label>
                                            <input type="number" class="form-control" id="cantidad_cobrada" name="cantidad_cobrada" step="0.01" min="0.01" value="<?php echo number_format(max(0, $saldo_pendiente), 2, '.', ''); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="id_forma_pago" class="form-label">Forma de Pago</label>
                                            <select class="form-select" id="id_forma_pago" name="id_forma_pago" required>
                                                <option value="">Seleccione una forma de pago</option>
                                                <?php foreach ($formas_pago as $fp): ?>
                                                    <option value="<?php echo htmlspecialchars($fp['id_forma_pago']); ?>">
                                                        <?php echo htmlspecialchars($fp['nombre_forma_pago']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Registrar Cobro</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Añadir Reembolso -->
                    <div class="modal fade" id="addReembolsoModal" tabindex="-1" aria-labelledby="addReembolsoModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="facturas_ventas.php" method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="addReembolsoModalLabel">Añadir Reembolso a Factura #<?php echo htmlspecialchars($factura_actual['id_factura']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="agregar_reembolso">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <p>Saldo Actual (lo que la empresa debe al cliente si es negativo): <strong class="fs-5 <?php echo ($saldo_pendiente < 0) ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($saldo_pendiente, 2, ',', '.'); ?> €</strong></p>
                                        <div class="mb-3">
                                            <label for="fecha_reembolso" class="form-label">Fecha Reembolso</label>
                                            <input type="date" class="form-control" id="fecha_reembolso" name="fecha_reembolso" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="cantidad_reembolsada" class="form-label">Cantidad a Reembolsar</label>
                                            <input type="number" class="form-control" id="cantidad_reembolsada" name="cantidad_reembolsada" step="0.01" min="0.01" max="<?php echo number_format(abs($saldo_pendiente), 2, '.', ''); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="id_forma_pago_reembolso" class="form-label">Forma de Pago (Reembolso)</label>
                                            <select class="form-select" id="id_forma_pago_reembolso" name="id_forma_pago" required>
                                                <option value="">Seleccione una forma de pago</option>
                                                <?php foreach ($formas_pago as $fp): ?>
                                                    <option value="<?php echo htmlspecialchars($fp['id_forma_pago']); ?>">
                                                        <?php echo htmlspecialchars($fp['nombre_forma_pago']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Registrar Reembolso</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Modal para Editar Factura -->
                    <div class="modal fade" id="editFacturaModal" tabindex="-1" aria-labelledby="editFacturaModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form action="facturas_ventas.php" method="POST" id="editFacturaForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="editFacturaModalLabel">Editar Factura #<?php echo htmlspecialchars($factura_actual['id_factura']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="accion" value="editar_factura">
                                        <input type="hidden" name="id_factura" value="<?php echo htmlspecialchars($factura_actual['id_factura']); ?>">
                                        <div class="mb-3">
                                            <label for="edit_fecha_factura" class="form-label">Fecha Factura</label>
                                            <input type="date" class="form-control" id="edit_fecha_factura" name="fecha_factura" value="<?php echo htmlspecialchars($factura_actual['fecha_factura']); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="edit_client_search_input" class="form-label">Cliente</label>
                                            <div class="client-search-container">
                                                <input type="text" class="form-control" id="edit_client_search_input" placeholder="Buscar cliente por nombre, NIF, ciudad..." autocomplete="off" value="<?php echo htmlspecialchars($factura_actual['nombre_cliente']); ?>">
                                                <input type="hidden" id="edit_id_cliente_selected" name="id_cliente" value="<?php echo htmlspecialchars($factura_actual['id_cliente']); ?>">
                                                <div id="edit_client_search_results" class="client-search-results"></div>
                                            </div>
                                            <small class="text-danger" id="edit_client_selection_error" style="display:none;">Por favor, seleccione un cliente de la lista.</small>
                                        </div>
                                        <div class="mb-3" id="editShippingAddressGroup" style="display: none;">
                                            <label for="edit_id_direccion_envio" class="form-label">Dirección de Envío</label>
                                            <select id="edit_id_direccion_envio" name="id_direccion_envio" placeholder="Seleccione una dirección..."></select>
                                            <small class="text-muted" id="edit_no_shipping_addresses_info" style="display:none;">Este cliente no tiene direcciones de envío secundarias. Se usará la dirección principal del cliente.</small>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; // Cierre del if/elseif principal ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
    <script>
        // Mapeo de productos para acceso rápido por ID
        const productosMapJs = <?php echo json_encode(array_column($productos, null, 'id_producto')); ?>;
        // NEW: Mapeo de clientes para acceso rápido por ID (incluye direcciones de envío)
        const clientesMapJs = <?php echo json_encode($clientes_map_js); ?>;


        // Función para enviar formularios dinámicamente
        function createAndSubmitForm(action, inputs) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'facturas_ventas.php';

            const inputAction = document.createElement('input');
            inputAction.type = 'hidden';
            inputAction.name = 'accion';
            inputAction.value = action;
            form.appendChild(inputAction);

            for (const key in inputs) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = inputs[key];
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }

        // Función para mostrar modal de confirmación personalizado
        function showCustomModal(title, message, type, callback) {
            const modalHtml = `
                <div class="modal fade" id="customConfirmModal" tabindex="-1" aria-labelledby="customConfirmModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title" id="customConfirmModalLabel">${title}</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>${message}</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="window.customConfirmCallback(false)">Cancelar</button>
                                <button type="button" class="btn btn-${type === 'confirm' ? 'danger' : 'primary'}" data-bs-dismiss="modal" onclick="window.customConfirmCallback(true)">Aceptar</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            // Eliminar modal anterior si existe
            const existingModal = document.getElementById('customConfirmModal');
            if (existingModal) {
                existingModal.remove();
            }
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const customConfirmModal = new bootstrap.Modal(document.getElementById('customConfirmModal'));
            window.customConfirmCallback = callback; // Guardar el callback globalmente
            customConfirmModal.show();
        }

        function confirmDeleteInvoice(id) {
            showCustomModal("Eliminar Factura", "¿Seguro que quieres eliminar esta factura? Se eliminarán todas sus líneas de detalle, sus cobros asociados y se revertirá el stock de productos terminados. ¡Esta acción es irreversible!", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('delete_invoice', { id_factura: id });
            });
        }

        function confirmDeleteDetalle(id, idFactura) {
            showCustomModal("Eliminar Línea de Detalle", "¿Seguro que quieres eliminar esta línea de detalle de la factura? Se revertirá el stock y las asignaciones de lote.", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('delete_detalle_linea', { id_detalle_factura: id, id_factura_original: idFactura });
            });
        }

        function confirmDeleteCobro(id, idFactura) {
            showCustomModal("Eliminar Cobro", "¿Seguro que quieres eliminar este cobro? El estado de pago de la factura se actualizará.", 'confirm', (confirmed) => {
                if (confirmed) createAndSubmitForm('delete_cobro', { id_cobro: id, id_factura_original_cobro: idFactura });
            });
        }

        // Función para actualizar los detalles del producto al seleccionar uno en el modal de añadir línea
        function updateProductDetails() {
            const selectProducto = document.getElementById('id_producto');
            const cantidadInput = document.getElementById('cantidad');

            const precioUnitarioBaseDisplay = document.getElementById('precio_unitario_base_display');
            const precioUnitarioIvaIncDisplay = document.getElementById('precio_unitario_iva_inc_display');
            const ivaPorcentajeDisplay = document.getElementById('iva_porcentaje_display');
            const importeIvaLineaDisplay = document.getElementById('importe_iva_linea_display');
            const subtotalLineaSinIvaDisplay = document.getElementById('subtotal_linea_sin_iva_display');
            const totalLineaIvaIncDisplay = document.getElementById('total_linea_iva_inc_display');
            const productStockInfo = document.getElementById('productStockInfo');

            const selectedProductId = selectProducto.value;
            const cantidad = parseFloat(cantidadInput.value) || 0;

            let precioUnitarioVenta = 0; // Precio con IVA
            let ivaPorcentaje = 0;
            let stockActual = 0;

            if (selectedProductId && productosMapJs[selectedProductId]) {
                const productData = productosMapJs[selectedProductId];
                precioUnitarioVenta = parseFloat(productData.precio_venta);
                ivaPorcentaje = parseFloat(productData.porcentaje_iva_actual);
                stockActual = parseFloat(productData.stock_actual_unidades);

                productStockInfo.textContent = `Stock actual: ${stockActual} unidades`;
                if (cantidad > stockActual) {
                    productStockInfo.classList.remove('text-muted');
                    productStockInfo.classList.add('text-danger');
                } else {
                    productStockInfo.classList.remove('text-danger');
                    productStockInfo.classList.add('text-muted');
                }
            } else {
                productStockInfo.textContent = '';
            }

            // Calculations for display
            const precioUnitarioBase = precioUnitarioVenta / (1 + (ivaPorcentaje / 100));
            const subtotalLineaSinIva = cantidad * precioUnitarioBase;
            const importeIvaLinea = subtotalLineaSinIva * (ivaPorcentaje / 100);
            const totalLineaIvaInc = subtotalLineaSinIva + importeIvaLinea;

            precioUnitarioBaseDisplay.textContent = precioUnitarioBase.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            precioUnitarioIvaIncDisplay.textContent = precioUnitarioVenta.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            ivaPorcentajeDisplay.textContent = ivaPorcentaje.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            importeIvaLineaDisplay.textContent = importeIvaLinea.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            subtotalLineaSinIvaDisplay.textContent = subtotalLineaSinIva.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            totalLineaIvaIncDisplay.textContent = totalLineaIvaInc.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        }

        // Wrap the entire script in an IIFE to prevent global variable conflicts
        (function() {
            let clientSearchTimeout; // Moved inside IIFE
            let shippingAddressSearchTimeout; // NEW: Timeout for shipping address search

            // Consolidated DOMContentLoaded listener
            document.addEventListener('DOMContentLoaded', () => {
                // Client Search elements (New Invoice Modal)
                const clientSearchInput = document.getElementById('client_search_input');
                const idClienteSelectedInput = document.getElementById('id_cliente_selected');
                const clientSearchResultsDiv = document.getElementById('client_search_results');
                const clientSelectionError = document.getElementById('client_selection_error');
                const invoiceForm = document.getElementById('invoiceForm'); // Get the form for new invoice

                // NEW: Elements for shipping address selection
                const shippingAddressGroup = document.getElementById('shippingAddressGroup');
                const noShippingAddressesInfo = document.getElementById('no_shipping_addresses_info');
                let tomSelectDireccion;


                // Retirar Stock Modal elements
                const retirarStockModal = document.getElementById('retirarStockModal');
                const retirarIdDetalleFacturaInput = document.getElementById('retirar_id_detalle_factura');
                const retirarIdProductoInput = document.getElementById('retirar_id_producto');
                const retirarProductoNombreSpan = document.getElementById('retirar_producto_nombre');
                const unidadesPendientesDisplay = document.getElementById('unidades_pendientes_display');
                const unidadesARetirarInput = document.getElementById('unidades_a_retirar');
                const idDetalleActividadSeleccionadoSelect = document.getElementById('id_detalle_actividad_seleccionado');
                const loteSelectionError = document.getElementById('lote_selection_error');

                // Add Cobro Modal elements
                const addCobroModal = document.getElementById('addCobroModal');
                const cantidadCobradaInput = document.getElementById('cantidad_cobrada');

                // Add Reembolso Modal elements
                const addReembolsoModal = document.getElementById('addReembolsoModal');
                const cantidadReembolsadaInput = document.getElementById('cantidad_reembolsada');

                // Filter buttons elements
                const filterButtons = document.querySelectorAll('.filter-btn');
                const invoicesTableBody = document.querySelector('#invoicesTable tbody');


                // If a client ID was passed to pre-fill the new invoice modal
                const urlParams = new URLSearchParams(window.location.search);
                const newInvoiceClientId = urlParams.get('new_invoice_client_id');
                const newInvoiceClientName = urlParams.get('new_invoice_client_name'); // Get client name if passed
                const newInvoiceOrderId = urlParams.get('new_invoice_order_id'); // Get order ID
                // const newInvoiceParteRutaId = urlParams.get('new_invoice_parte_ruta_id'); // Not needed for JS pre-fill, handled by PHP redirect

                // IMPORTANT: Only pre-fill the modal if it's NOT an automatic invoice creation from order
                // If newInvoiceOrderId exists, PHP will handle creation and redirect, so no need to show modal here.
                if (newInvoiceClientId && clientSearchInput && idClienteSelectedInput && !newInvoiceOrderId) {
                    const addInvoiceModal = new bootstrap.Modal(document.getElementById('addInvoiceModal'));
                    addInvoiceModal.show();
                    idClienteSelectedInput.value = newInvoiceClientId;
                    clientSearchInput.value = newInvoiceClientName || ''; // Set the name in the search input

                    // NEW: Load shipping addresses for the pre-selected client
                    loadShippingAddresses(newInvoiceClientId, 'id_direccion_envio', shippingAddressGroup, noShippingAddressesInfo);


                    // Remove the parameters from the URL to avoid re-opening the modal on refresh
                    urlParams.delete('new_invoice_client_id');
                    urlParams.delete('new_invoice_client_name');
                    const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                    window.history.replaceState({}, document.title, newUrl);
                }

                // --- Client Search Functionality for NEW INVOICE MODAL ---
                if (clientSearchInput) {
                    clientSearchInput.addEventListener('input', function() {
                        const searchTerm = this.value.trim();
                        idClienteSelectedInput.value = ''; // Clear selected client ID on new search
                        clientSelectionError.style.display = 'none'; // Hide error message

                        // NEW: Clear and hide shipping address fields when client search changes
                        shippingAddressGroup.style.display = 'none';
                        if (tomSelectDireccion) {
                            tomSelectDireccion.destroy();
                            tomSelectDireccion = null;
                        }
                        noShippingAddressesInfo.style.display = 'none';


                        clearTimeout(clientSearchTimeout);
                        if (searchTerm.length > 1) { // Start search after 2 characters
                            clientSearchTimeout = setTimeout(async () => {
                                try {
                                    const formData = new FormData();
                                    formData.append('accion', 'search_clients');
                                    formData.append('search_term', searchTerm);

                                    const response = await fetch('facturas_ventas.php', {
                                        method: 'POST',
                                        body: formData
                                    });
                                    const clients = await response.json();

                                    clientSearchResultsDiv.innerHTML = '';
                                    if (clients.error) {
                                        clientSearchResultsDiv.innerHTML = `<div class="client-search-results-item text-danger">Error: ${clients.error}</div>`;
                                    } else if (clients.length > 0) {
                                        clients.forEach(client => {
                                            const item = document.createElement('div');
                                            item.classList.add('client-search-results-item');
                                            item.textContent = `${client.nombre_cliente} (${client.nif || 'N/A'}) - ${client.ciudad}`;
                                            item.dataset.clientId = client.id_cliente;
                                            item.dataset.clientName = client.nombre_cliente;
                                            item.addEventListener('click', function() {
                                                clientSearchInput.value = this.dataset.clientName;
                                                idClienteSelectedInput.value = this.dataset.clientId;
                                                clientSearchResultsDiv.innerHTML = ''; // Clear results
                                                // NEW: Load shipping addresses after client is selected
                                                loadShippingAddresses(this.dataset.clientId, 'id_direccion_envio', shippingAddressGroup, noShippingAddressesInfo);
                                            });
                                            clientSearchResultsDiv.appendChild(item);
                                        });
                                    } else {
                                        clientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-muted">No se encontraron clientes.</div>';
                                    }
                                } catch (error) {
                                    console.error('Error searching clients:', error);
                                    clientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-danger">Error al buscar clientes.</div>';
                                }
                            }, 300); // Debounce search
                        } else {
                            clientSearchResultsDiv.innerHTML = ''; // Clear results if search term is too short
                        }
                    });

                    // Hide search results when clicking outside
                    document.addEventListener('click', function(event) {
                        if (!clientSearchInput.contains(event.target) && !clientSearchResultsDiv.contains(event.target)) {
                            clientSearchResultsDiv.innerHTML = '';
                        }
                    });

                    // Validate client selection and shipping address selection before submitting the form
                    if (invoiceForm) {
                        invoiceForm.addEventListener('submit', function(event) {
                            let isValid = true;

                            // Validate client selection
                            if (!idClienteSelectedInput.value) {
                                event.preventDefault(); // Prevent form submission
                                clientSelectionError.style.display = 'block'; // Show error message
                                isValid = false;
                            } else {
                                clientSelectionError.style.display = 'none'; // Hide error message
                            }

                            if (!isValid) {
                                event.preventDefault(); // Ensure form is not submitted if any validation fails
                            }
                        });
                    }
                }

                // NEW: Function to load and populate the shipping address search/select
                async function loadShippingAddresses(clientId, selectId, groupElement, noInfoElement, selectedAddressId = null) {
                    groupElement.style.display = 'block';
                    noInfoElement.style.display = 'none';

                    let existingTomSelect = document.getElementById(selectId).tomselect;
                    if (existingTomSelect) {
                        existingTomSelect.destroy();
                    }

                    const tomSelect = new TomSelect(`#${selectId}`, {
                        valueField: 'id_direccion_envio',
                        labelField: 'full_address',
                        searchField: ['full_address'],
                        create: false,
                        load: async (query, callback) => {
                            if (!clientId) return callback();
                            try {
                                const formData = new FormData();
                                formData.append('accion', 'search_shipping_addresses');
                                formData.append('id_cliente', clientId);
                                formData.append('search_term', query);

                                const response = await fetch('facturas_ventas.php', {
                                    method: 'POST',
                                    body: formData
                                });
                                const data = await response.json();

                                if (data.success) {
                                    const addresses = data.direcciones.map(dir => ({
                                        ...dir,
                                        full_address: `${dir.nombre_direccion} - ${dir.direccion}, ${dir.ciudad}`
                                    }));
                                    if (addresses.length === 0) {
                                        noInfoElement.style.display = 'block';
                                    }
                                    callback(addresses);
                                } else {
                                    callback();
                                }
                            } catch (error) {
                                console.error('Error loading addresses for TomSelect:', error);
                                callback();
                            }
                        },
                        render: {
                            option: function(data, escape) {
                                return `<div>${escape(data.full_address)}</div>`;
                            },
                            item: function(data, escape) {
                                return `<div>${escape(data.full_address)}</div>`;
                            }
                        }
                    });

                    tomSelect.load(function(callback) {
                        // This function is a bit of a hack to load the initial selected value
                        // since TomSelect doesn't have a direct way to set an option that isn't loaded yet.
                        const clientData = clientesMapJs[clientId];
                        if (clientData && clientData.direcciones_envio) {
                            const addresses = clientData.direcciones_envio.map(dir => ({
                                ...dir,
                                full_address: `${dir.nombre_direccion} - ${dir.direccion}, ${dir.ciudad}`
                            }));
                            callback(addresses);
                            if (selectedAddressId) {
                                tomSelect.setValue(selectedAddressId);
                            }
                        } else {
                            callback();
                        }
                    });
                }


                // --- Retirar Stock Modal Logic ---
                if (retirarStockModal) {
                    retirarStockModal.addEventListener('show.bs.modal', async (event) => {
                        // Button that triggered the modal
                        const button = event.relatedTarget;
                        const idDetalleFactura = button.dataset.idDetalleFactura;
                        const idProducto = button.dataset.idProducto;
                        const unidadesPendientes = button.dataset.unidadesPendientes;
                        const nombreProducto = button.dataset.nombreProducto;

                        // Update the modal's content.
                        retirarIdDetalleFacturaInput.value = idDetalleFactura;
                        retirarIdProductoInput.value = idProducto;
                        retirarProductoNombreSpan.textContent = nombreProducto;
                        unidadesPendientesDisplay.textContent = unidadesPendientes;
                        unidadesARetirarInput.value = unidadesPendientes; // Pre-fill with max available
                        unidadesARetirarInput.max = unidadesPendientes; // Set max attribute

                        // Clear previous options and show loading
                        idDetalleActividadSeleccionadoSelect.innerHTML = '<option value="">Cargando lotes...</option>';
                        loteSelectionError.style.display = 'none'; // Hide error initially

                        // Fetch available batches for the product via AJAX
                        try {
                            const formData = new FormData();
                            formData.append('accion', 'get_available_batches');
                            formData.append('id_producto', idProducto);

                            const response = await fetch('facturas_ventas.php', {
                                method: 'POST',
                                body: formData
                            });
                            const batches = await response.json();

                            idDetalleActividadSeleccionadoSelect.innerHTML = ''; // Clear loading message

                            if (batches.error) {
                                const option = document.createElement('option');
                                option.value = '';
                                option.textContent = `Error: ${batches.error}`;
                                idDetalleActividadSeleccionadoSelect.appendChild(option);
                                idDetalleActividadSeleccionadoSelect.setAttribute('disabled', 'true');
                                loteSelectionError.textContent = `Error al cargar lotes: ${batches.error}`;
                                loteSelectionError.style.display = 'block';
                            } else if (batches.length > 0) {
                                const defaultOption = document.createElement('option');
                                defaultOption.value = '';
                                defaultOption.textContent = 'Seleccione un lote';
                                idDetalleActividadSeleccionadoSelect.appendChild(defaultOption);

                                batches.forEach(batch => {
                                    const option = document.createElement('option');
                                    option.value = batch.id_detalle_actividad;
                                    option.textContent = `Lote: ${batch.nombre_lote} - Sublote: ${batch.consecutivo_sublote} (Disp: ${batch.unidades_envasadas - batch.unidades_vendidas} uds)`;
                                    idDetalleActividadSeleccionadoSelect.appendChild(option);
                                });
                                idDetalleActividadSeleccionadoSelect.removeAttribute('disabled');
                            } else {
                                const option = document.createElement('option');
                                option.value = '';
                                option.textContent = 'No hay lotes disponibles para este producto.';
                                idDetalleActividadSeleccionadoSelect.appendChild(option);
                                idDetalleActividadSeleccionadoSelect.setAttribute('disabled', 'true');
                                loteSelectionError.textContent = 'No hay lotes disponibles para este producto.';
                                loteSelectionError.style.display = 'block';
                            }
                        } catch (error) {
                            console.error('Error fetching batches:', error);
                            idDetalleActividadSeleccionadoSelect.innerHTML = '<option value="">Error al cargar lotes.</option>';
                            idDetalleActividadSeleccionadoSelect.setAttribute('disabled', 'true');
                            loteSelectionError.textContent = 'Error de red o servidor al cargar lotes.';
                            loteSelectionError.style.display = 'block';
                        }
                    });

                    // Validate lote selection before submitting the form
                    retirarStockModal.querySelector('form').addEventListener('submit', function(event) {
                        if (!idDetalleActividadSeleccionadoSelect.value) {
                            event.preventDefault();
                            loteSelectionError.style.display = 'block';
                        } else {
                            loteSelectionError.style.display = 'none';
                        }
                    });
                }

                // --- Add Cobro Modal Logic (Pre-fill amount) ---
                if (addCobroModal) {
                    addCobroModal.addEventListener('show.bs.modal', (event) => {
                        const button = event.relatedTarget;
                        const saldoPendiente = parseFloat(button.dataset.saldoPendiente);
                        cantidadCobradaInput.value = saldoPendiente.toFixed(2);
                        cantidadCobradaInput.max = saldoPendiente.toFixed(2);
                    });
                }

                // --- Add Reembolso Modal Logic (Pre-fill amount) ---
                if (addReembolsoModal) {
                    addReembolsoModal.addEventListener('show.bs.modal', (event) => {
                        const button = event.relatedTarget;
                        const saldoAFavor = parseFloat(button.dataset.saldoAFavor);
                        cantidadReembolsadaInput.value = saldoAFavor.toFixed(2);
                        cantidadReembolsadaInput.max = saldoAFavor.toFixed(2);
                    });
                }

                // --- Edit Invoice Modal ---
                const editFacturaModal = document.getElementById('editFacturaModal');
                if (editFacturaModal) {
                    const editClientSearchInput = document.getElementById('edit_client_search_input');
                    const editIdClienteSelectedInput = document.getElementById('edit_id_cliente_selected');
                    const editClientSearchResultsDiv = document.getElementById('edit_client_search_results');
                    const editClientSelectionError = document.getElementById('edit_client_selection_error');
                    const editFacturaForm = document.getElementById('editFacturaForm');
                    const editShippingAddressGroup = document.getElementById('editShippingAddressGroup');
                    const editNoShippingAddressesInfo = document.getElementById('edit_no_shipping_addresses_info');
                    let tomSelectEditDireccion;

                    // Client search functionality for the edit modal
                    if (editClientSearchInput) {
                        editClientSearchInput.addEventListener('input', function() {
                            const searchTerm = this.value.trim();
                            editIdClienteSelectedInput.value = '';
                            if(editClientSelectionError) editClientSelectionError.style.display = 'none';

                            // Clear shipping address fields when client changes
                            if (tomSelectEditDireccion) {
                                tomSelectEditDireccion.destroy();
                                tomSelectEditDireccion = null;
                            }
                            editNoShippingAddressesInfo.style.display = 'none';
                            editShippingAddressGroup.style.display = 'none';


                            clearTimeout(clientSearchTimeout);
                            if (searchTerm.length > 1) {
                                clientSearchTimeout = setTimeout(async () => {
                                    try {
                                        const formData = new FormData();
                                        formData.append('accion', 'search_clients');
                                        formData.append('search_term', searchTerm);

                                        const response = await fetch('facturas_ventas.php', { method: 'POST', body: formData });
                                        const clients = await response.json();

                                        editClientSearchResultsDiv.innerHTML = '';
                                        if (clients.error) {
                                            editClientSearchResultsDiv.innerHTML = `<div class="client-search-results-item text-danger">${clients.error}</div>`;
                                        } else if (clients.length > 0) {
                                            clients.forEach(client => {
                                                const item = document.createElement('div');
                                                item.classList.add('client-search-results-item');
                                                item.textContent = `${client.nombre_cliente} (${client.nif || 'N/A'}) - ${client.ciudad}`;
                                                item.dataset.clientId = client.id_cliente;
                                                item.dataset.clientName = client.nombre_cliente;
                                                item.addEventListener('click', function() {
                                                    editClientSearchInput.value = this.dataset.clientName;
                                                    editIdClienteSelectedInput.value = this.dataset.clientId;
                                                    editClientSearchResultsDiv.innerHTML = '';
                                                    loadShippingAddresses(this.dataset.clientId, 'edit_id_direccion_envio', editShippingAddressGroup, editNoShippingAddressesInfo);
                                                });
                                                editClientSearchResultsDiv.appendChild(item);
                                            });
                                        } else {
                                            editClientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-muted">No se encontraron clientes.</div>';
                                        }
                                    } catch (error) {
                                        console.error('Error searching clients:', error);
                                        editClientSearchResultsDiv.innerHTML = '<div class="client-search-results-item text-danger">Error al buscar.</div>';
                                    }
                                }, 300);
                            } else {
                                editClientSearchResultsDiv.innerHTML = '';
                            }
                        });

                        document.addEventListener('click', function(event) {
                            if (editClientSearchResultsDiv && editClientSearchInput && !editClientSearchInput.contains(event.target) && !editClientSearchResultsDiv.contains(event.target)) {
                                editClientSearchResultsDiv.innerHTML = '';
                            }
                        });
                    }

                    // Load addresses when the modal is shown
                    editFacturaModal.addEventListener('show.bs.modal', () => {
                        const currentClientId = editIdClienteSelectedInput.value;
                        const currentShippingAddressId = <?php echo json_encode($factura_actual['id_direccion_envio'] ?? null); ?>;
                        loadShippingAddresses(currentClientId, 'edit_id_direccion_envio', editShippingAddressGroup, editNoShippingAddressesInfo, currentShippingAddressId);
                    });

                    if (editFacturaForm) {
                        editFacturaForm.addEventListener('submit', function(event) {
                            if (!editIdClienteSelectedInput.value) {
                                event.preventDefault();
                                if(editClientSelectionError) editClientSelectionError.style.display = 'block';
                            } else {
                                if(editClientSelectionError) editClientSelectionError.style.display = 'none';
                            }
                        });
                    }
                }

                // --- Filter Buttons Logic ---
                filterButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        // Remove active class from all buttons
                        filterButtons.forEach(btn => {
                            btn.classList.remove('active');
                            // Revert to outline for non-active specific filter buttons
                            if (btn.dataset.filterType !== 'all') {
                                btn.classList.add(`btn-outline-${btn.dataset.filterType === 'estado_retiro' ? 'warning' : 'info'}`);
                                btn.classList.remove(`btn-${btn.dataset.filterType === 'estado_retiro' ? 'warning' : 'info'}`);
                            } else {
                                // Revert 'Mostrar Todos' to outline-secondary if not active
                                btn.classList.add('btn-secondary');
                                btn.classList.remove('btn-primary'); // Assuming it was primary when active
                            }
                        });

                        // Add active class to the clicked button and apply solid style
                        this.classList.add('active');
                        if (this.dataset.filterType !== 'all') {
                            this.classList.remove(`btn-outline-${this.dataset.filterType === 'estado_retiro' ? 'warning' : 'info'}`);
                            this.classList.add(`btn-${this.dataset.filterType === 'estado_retiro' ? 'warning' : 'info'}`);
                        } else {
                            this.classList.remove('btn-secondary');
                            this.classList.add('btn-primary');
                        }


                        const filterType = this.dataset.filterType;
                        const filterValue = this.dataset.filterValue;

                        Array.from(invoicesTableBody.children).forEach(row => {
                            let showRow = true;

                            if (filterType === 'all') {
                                showRow = true;
                            } else if (filterType === 'estado_retiro') {
                                const estadoRetiro = row.dataset.estadoRetiro;
                                showRow = (estadoRetiro === filterValue || estadoRetiro === 'Parcial'); // Show 'Pendiente' and 'Parcial' for withdrawal
                            } else if (filterType === 'estado_pago') {
                                const estadoPago = row.dataset.estadoPago;
                                showRow = (estadoPago === filterValue || estadoPago === 'parcialmente_pagada'); // Show 'pendiente' and 'parcialmente_pagada' for payment
                            }

                            row.style.display = showRow ? '' : 'none';
                        });
                    });
                });

                // Trigger "Mostrar Todos" on initial load to ensure all are visible and button is active
                document.querySelector('.filter-btn[data-filter-type="all"]').click();

                // --- Add Client Modal Logic ---
                const addClientForm = document.getElementById('addClientForm');
                if (addClientForm) {
                    addClientForm.addEventListener('submit', async function(event) {
                        event.preventDefault();
                        const formData = new FormData(addClientForm);
                        formData.append('accion', 'add_client_from_modal');

                        try {
                            const response = await fetch('facturas_ventas.php', {
                                method: 'POST',
                                body: formData
                            });
                            const result = await response.json();

                            if (result.success) {
                                // Close the 'Add Client' modal
                                const addClientModal = bootstrap.Modal.getInstance(document.getElementById('addClientModal'));
                                addClientModal.hide();

                                // Populate the new client data in the 'New Invoice' modal
                                document.getElementById('client_search_input').value = result.new_client.nombre_cliente;
                                document.getElementById('id_cliente_selected').value = result.new_client.id_cliente;

                                // Hide client search results and error messages
                                if(clientSearchResultsDiv) clientSearchResultsDiv.innerHTML = '';
                                if(clientSelectionError) clientSelectionError.style.display = 'none';

                                // Optionally, show a success message (e.g., using a toast notification)
                                alert(result.message);

                                // Clear the form for the next time
                                addClientForm.reset();
                            } else {
                                alert('Error: ' + result.message);
                            }
                        } catch (error) {
                            console.error('Error creating client:', error);
                            alert('Hubo un error de red al crear el cliente.');
                        }
                    });
                }

            });
        })(); // End of IIFE
    </script>
</body>
</html>
