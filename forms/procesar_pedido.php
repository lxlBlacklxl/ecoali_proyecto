<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - PROCESADOR DE COMPRAS, PAGOS E INVENTARIO (FIFO)
 * --------------------------------------------------------------------------------
 * Este script procesa el checkout de clientes de forma segura. Valida la disponibilidad
 * real en la BD, descuenta el stock utilizando un algoritmo FIFO (First In, First Out)
 * de los lotes de huevo en estado 'disponible', registra la cabecera y detalle de
 * pedido con método y estado de pago, y adjudica regalías de referido si corresponden.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// 1. CONTROL DE ACCESO
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 2) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos."]);
    exit;
}

$cliente_id = $_SESSION["usuario_id"];
$direccion = trim($input["direccion"] ?? "");
$telefono = trim($input["telefono"] ?? "");
$referido_por = trim($input["referido_por"] ?? "");
$metodo_pago = trim($input["metodo_pago"] ?? "efectivo");
$pago_estado = trim($input["pago_estado"] ?? "pendiente");
$carrito = $input["carrito"] ?? [];

if (empty($direccion) || empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "Dirección y teléfono de entrega son obligatorios."]);
    exit;
}

if (empty($carrito)) {
    echo json_encode(["status" => "error", "message" => "El carrito de compras está vacío."]);
    exit;
}

// Iniciar transacción de base de datos
$conn->begin_transaction();

try {
    $total_pedido = 0.0;
    $items_procesados = [];

    // 1. VALIDAR PRODUCTOS Y STOCK DISPONIBLE
    foreach ($carrito as $item) {
        $prod_id = (int)($item["producto_id"] ?? 0);
        $cantidad = (int)($item["cantidad"] ?? 0);

        if ($prod_id <= 0 || $cantidad <= 0) {
            throw new Exception("Cantidad o ID de producto inválido en el carrito.");
        }

        // Consultar producto
        $stmtProd = $conn->prepare("SELECT nombre, precio, activo FROM productos WHERE id = ?");
        $stmtProd->bind_param("i", $prod_id);
        $stmtProd->execute();
        $resProd = $stmtProd->get_result();

        if ($resProd->num_rows === 0) {
            throw new Exception("El producto seleccionado no existe en el catálogo.");
        }

        $prodData = $resProd->fetch_assoc();
        if ((int)$prodData["activo"] !== 1) {
            throw new Exception("El producto '" . $prodData["nombre"] . "' ya no se encuentra activo.");
        }

        // Calcular stock disponible actual en lotes para este producto
        $stmtStock = $conn->prepare("SELECT SUM(cantidad) FROM inventario_huevos WHERE producto_id = ? AND estado = 'disponible' AND cantidad > 0");
        $stmtStock->bind_param("i", $prod_id);
        $stmtStock->execute();
        $resStock = $stmtStock->get_result();
        $stockRow = $resStock->fetch_row();
        $stock_disponible = (int)($stockRow[0] ?? 0);

        if ($stock_disponible < $cantidad) {
            throw new Exception("Disculpe, no hay suficiente stock disponible para '" . $prodData["nombre"] . "'. Stock actual: " . $stock_disponible . " unidades.");
        }

        $precio_real = (float)$prodData["precio"];
        $subtotal = $precio_real * $cantidad;
        $total_pedido += $subtotal;

        $items_procesados[] = [
            "id" => $prod_id,
            "nombre" => $prodData["nombre"],
            "precio" => $precio_real,
            "cantidad" => $cantidad,
            "subtotal" => $subtotal
        ];
    }

    // 2. DESCONTAR STOCK USANDO LOGÍSTICA FIFO (First In, First Out)
    foreach ($items_procesados as $item) {
        $prod_id = $item["id"];
        $rem = $item["cantidad"];

        $stmtLotes = $conn->prepare("SELECT id, cantidad FROM inventario_huevos WHERE producto_id = ? AND estado = 'disponible' AND cantidad > 0 ORDER BY fecha_postura ASC");
        $stmtLotes->bind_param("i", $prod_id);
        $stmtLotes->execute();
        $resLotes = $stmtLotes->get_result();

        while ($lote = $resLotes->fetch_assoc()) {
            if ($rem <= 0) break;

            $lote_id = $lote["id"];
            $lote_cant = (int)$lote["cantidad"];

            if ($lote_cant >= $rem) {
                $new_cant = $lote_cant - $rem;
                $new_estado = ($new_cant < 100) ? 'bajo_stock' : 'disponible';
                
                $stmtUp = $conn->prepare("UPDATE inventario_huevos SET cantidad = ?, estado = ? WHERE id = ?");
                $stmtUp->bind_param("isi", $new_cant, $new_estado, $lote_id);
                $stmtUp->execute();
                
                $rem = 0;
            } else {
                // Agotar este lote y pasar al siguiente
                $stmtUp = $conn->prepare("UPDATE inventario_huevos SET cantidad = 0, estado = 'bajo_stock' WHERE id = ?");
                $stmtUp->bind_param("i", $lote_id);
                $stmtUp->execute();
                
                $rem -= $lote_cant;
            }
        }

        if ($rem > 0) {
            throw new Exception("Error inesperado en la asignación de inventario para '" . $item["nombre"] . "'.");
        }
    }

    // 2.2 CALCULAR DESCUENTOS Y PROMOCIONES AUTOMÁTICAS (Fase 3)
    $subtotal_bruto = $total_pedido;
    $auto_promo_descuento = 0.0;
    if ($subtotal_bruto >= 50.0) {
        $auto_promo_descuento = round($subtotal_bruto * 0.05, 2);
    }

    $cupon_codigo = trim($input["cupon_codigo"] ?? "");
    $cupon_descuento = 0.0;
    if (!empty($cupon_codigo)) {
        $stmtCupon = $conn->prepare("SELECT tipo, descuento, activo FROM cupones WHERE codigo = ? AND activo = 1");
        if ($stmtCupon) {
            $stmtCupon->bind_param("s", $cupon_codigo);
            $stmtCupon->execute();
            $resCupon = $stmtCupon->get_result();
            if ($resCupon->num_rows > 0) {
                $cupon = $resCupon->fetch_assoc();
                if ($cupon["tipo"] === 'porcentaje') {
                    $cupon_descuento = round($subtotal_bruto * ($cupon["descuento"] / 100), 2);
                } else {
                    $cupon_descuento = (float)$cupon["descuento"];
                }
            }
            $stmtCupon->close();
        }
    }

    $descuento_total = $auto_promo_descuento + $cupon_descuento;
    if ($descuento_total > $subtotal_bruto) {
        $descuento_total = $subtotal_bruto;
    }

    $total_final = $subtotal_bruto - $descuento_total;

    // Calcular IVA del 16% (incluido en el total)
    $subtotal_base = round($total_final / 1.16, 2);
    $iva_calculado = round($total_final - $subtotal_base, 2);

    // 3. REGISTRAR CABECERA DEL PEDIDO (CON PAGO, DESCUENTOS E IVA)
    $sqlInsertPedido = "INSERT INTO pedidos (cliente_id, repartidor_id, total, estado, fecha_pedido, metodo_pago, pago_estado, descuento, cupon_codigo, subtotal, iva) VALUES (?, NULL, ?, 'pendiente', NOW(), ?, ?, ?, ?, ?, ?)";
    $stmtPedido = $conn->prepare($sqlInsertPedido);
    $stmtPedido->bind_param("idssdsdd", $cliente_id, $total_final, $metodo_pago, $pago_estado, $descuento_total, $cupon_codigo, $subtotal_base, $iva_calculado);
    if (!$stmtPedido->execute()) {
        throw new Exception("Error al registrar la cabecera de la orden: " . $conn->error);
    }
    
    $pedido_id = $conn->insert_id;

    // 4. REGISTRAR DETALLE DEL PEDIDO
    $sqlInsertDetalle = "INSERT INTO detalle_pedido (pedido_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)";
    $stmtDetalle = $conn->prepare($sqlInsertDetalle);

    foreach ($items_procesados as $item) {
        $stmtDetalle->bind_param("iiidd", $pedido_id, $item["id"], $item["cantidad"], $item["precio"], $item["subtotal"]);
        if (!$stmtDetalle->execute()) {
            throw new Exception("Error al registrar el detalle de '" . $item["nombre"] . "': " . $conn->error);
        }
    }

    // 5. SISTEMA DE REFERIDOS Y FIDELIZACIÓN (REGALÍA DEL 10%)
    if (!empty($referido_por)) {
        $stmtRef = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? AND id != ? AND activo = 1");
        $stmtRef->bind_param("si", $referido_por, $cliente_id);
        $stmtRef->execute();
        $resRef = $stmtRef->get_result();

        if ($resRef->num_rows > 0) {
            $beneficiado_id = $resRef->fetch_assoc()["id"];
            $comision_monto = round($total_final * 0.10, 2);

            if ($comision_monto > 0) {
                $sqlInsertRegalia = "INSERT INTO regalias (usuario_beneficiado_id, usuario_referido_id, pedido_id, nivel, monto, estado, fecha) VALUES (?, ?, ?, 1, ?, 'pendiente', NOW())";
                $stmtRegalia = $conn->prepare($sqlInsertRegalia);
                $stmtRegalia->bind_param("iiid", $beneficiado_id, $cliente_id, $pedido_id, $comision_monto);
                if ($stmtRegalia->execute()) {
                    registrar_bitacora("Regalía generada", "Regalías", "Se generó una comisión pendiente de $$comision_monto para el usuario '$referido_por' por la compra del cliente ID #$cliente_id.");
                }
            }
        }
    }

    // 6. ACTUALIZAR DIRECCIÓN Y TELÉFONO EN EL PERFIL CON LOS DATOS DE ESTA COMPRA
    $stmtProfileUpdate = $conn->prepare("UPDATE usuario_perfil SET direccion = ?, telefono = ? WHERE usuario_id = ?");
    $stmtProfileUpdate->bind_param("ssi", $direccion, $telefono, $cliente_id);
    $stmtProfileUpdate->execute();

    // 7. REGISTRAR LOG EN LA BITÁCORA DEL SISTEMA
    $nCompleto = ($_SESSION["nombre"] ?? "Cliente") . " " . ($_SESSION["apellido"] ?? "");
    registrar_bitacora("Pedido creado", "Logística", "El cliente '$nCompleto' creó el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " pagado vía " . strtoupper($metodo_pago) . " por un total de $$total_final.");

    // Confirmar transacción
    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "¡Pedido e inventario procesados con éxito!",
        "pedido_id" => $pedido_id,
        "total" => $total_final
    ]);

} catch (Exception $e) {
    // Revertir ante cualquier fallo
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
