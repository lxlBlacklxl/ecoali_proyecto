<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - SISTEMA DE CANCELACIÓN DE PEDIDOS CON RETORNO DE STOCK (API AJAX)
 * --------------------------------------------------------------------------------
 * Este script asíncrono permite a los clientes cancelar un pedido pendiente.
 * La operación es transaccional y reincorpora el stock de vuelta a los lotes
 * de origen correspondientes en inventario_huevos, garantizando la consistencia logistica.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// 1. CONTROL DE ACCESO
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 2) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado para este perfil."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$pedido_id = (int)($input["pedido_id"] ?? 0);
$cliente_id = $_SESSION["usuario_id"];

if ($pedido_id <= 0) {
    echo json_encode(["status" => "error", "message" => "ID de pedido inválido."]);
    exit;
}

$conn->begin_transaction();

try {
    // 2. VERIFICAR PEDIDO PENDIENTE
    $stmtCheck = $conn->prepare("SELECT estado, total FROM pedidos WHERE id = ? AND cliente_id = ?");
    $stmtCheck->bind_param("ii", $pedido_id, $cliente_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows === 0) {
        throw new Exception("El pedido especificado no existe o no te pertenece.");
    }

    $pedInfo = $resCheck->fetch_assoc();
    if ($pedInfo["estado"] !== "pendiente") {
        throw new Exception("Solo se pueden cancelar pedidos en estado 'pendiente'.");
    }

    // 3. ACTUALIZAR ESTADO A CANCELADO
    $stmtUpdate = $conn->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?");
    $stmtUpdate->bind_param("i", $pedido_id);
    if (!$stmtUpdate->execute()) {
        throw new Exception("Error al procesar la cancelación en base de datos.");
    }

    // 4. DEVOLVER Y REINCORPORAR STOCK EN INVENTARIO (FIFO RESTORE)
    $stmtItems = $conn->prepare("SELECT producto_id, cantidad FROM detalle_pedido WHERE pedido_id = ?");
    $stmtItems->bind_param("i", $pedido_id);
    $stmtItems->execute();
    $resItems = $stmtItems->get_result();
    
    while ($item = $resItems->fetch_assoc()) {
        $prod_id = (int)$item["producto_id"];
        $cant_to_restore = (int)$item["cantidad"] * 30; // Reincorporar huevos individuales (1 cartón = 30 huevos)

        // Buscar el lote más reciente de ese producto para reintegrarle el stock
        $stmtLote = $conn->prepare("SELECT id, cantidad FROM inventario_huevos WHERE producto_id = ? ORDER BY id DESC LIMIT 1");
        $stmtLote->bind_param("i", $prod_id);
        $stmtLote->execute();
        $resLote = $stmtLote->get_result();
        
        if ($resLote->num_rows > 0) {
            $loteRow = $resLote->fetch_assoc();
            $lote_id = $loteRow["id"];
            $new_lote_cant = (int)$loteRow["cantidad"] + $cant_to_restore;
            $new_lote_estado = $new_lote_cant >= 100 ? 'disponible' : 'bajo_stock';

            $stmtRestore = $conn->prepare("UPDATE inventario_huevos SET cantidad = ?, estado = ? WHERE id = ?");
            $stmtRestore->bind_param("isi", $new_lote_cant, $new_lote_estado, $lote_id);
            $stmtRestore->execute();
        }
    }

    // 5. ELIMINAR REGALÍAS ASOCIADAS
    $stmtDelReg = $conn->prepare("DELETE FROM regalias WHERE pedido_id = ?");
    $stmtDelReg->bind_param("i", $pedido_id);
    $stmtDelReg->execute();

    // 6. REGISTRAR EN LA BITÁCORA DE AUDITORÍA
    $nCompleto = ($_SESSION["nombre"] ?? "Cliente") . " " . ($_SESSION["apellido"] ?? "");
    registrar_bitacora("Pedido cancelado", "Logística", "El cliente '$nCompleto' canceló el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " por un total de $" . number_format($pedInfo["total"], 2) . " y se reintegraron los productos al stock.");

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " cancelado con éxito y stock reincorporado."
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
