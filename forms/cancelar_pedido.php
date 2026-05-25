<?php
session_start();
require "conexion.php";

header("Content-Type: application/json");

if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 2) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$pedido_id = (int)($input["pedido_id"] ?? 0);
$cliente_id = $_SESSION["usuario_id"];

if ($pedido_id <= 0) {
    echo json_encode(["status" => "error", "message" => "ID de pedido inválido."]);
    exit;
}

// Iniciar transacción
$conn->begin_transaction();

try {
    // Verificar que el pedido pertenezca al cliente logueado y que esté 'pendiente'
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

    // Actualizar estado a cancelado
    $stmtUpdate = $conn->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?");
    $stmtUpdate->bind_param("i", $pedido_id);
    
    if (!$stmtUpdate->execute()) {
        throw new Exception("Error al cancelar el pedido.");
    }

    // Si había regalías asociadas a este pedido, marcarlas como canceladas o eliminarlas.
    // En nuestro caso, la tabla regalias no tiene un estado 'cancelado', pero podemos eliminarlas para mantener la limpieza financiera.
    $stmtDelReg = $conn->prepare("DELETE FROM regalias WHERE pedido_id = ?");
    $stmtDelReg->bind_param("i", $pedido_id);
    $stmtDelReg->execute();

    // Registrar acción en bitácora
    $nCompleto = ($_SESSION["nombre"] ?? "Cliente") . " " . ($_SESSION["apellido"] ?? "");
    registrar_bitacora("Pedido cancelado", "Logística", "El cliente '$nCompleto' canceló el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " por un total de $" . number_format($pedInfo["total"], 2) . ".");

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " cancelado con éxito."
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
