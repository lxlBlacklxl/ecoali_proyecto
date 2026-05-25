<?php
session_start();
require "conexion.php";

header("Content-Type: application/json");

if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 4) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos."]);
    exit;
}

$repartidor_id = $_SESSION["usuario_id"];
$pedido_id = (int)($input["pedido_id"] ?? 0);
$nuevo_estado = trim($input["nuevo_estado"] ?? "");

if ($pedido_id <= 0 || empty($nuevo_estado)) {
    echo json_encode(["status" => "error", "message" => "ID de pedido y nuevo estado son requeridos."]);
    exit;
}

if (!in_array($nuevo_estado, ["en_ruta", "entregado"])) {
    echo json_encode(["status" => "error", "message" => "Estado de entrega inválido."]);
    exit;
}

$conn->begin_transaction();

try {
    // 1. Obtener información del pedido
    $stmtCheck = $conn->prepare("SELECT estado, repartidor_id, total FROM pedidos WHERE id = ?");
    $stmtCheck->bind_param("i", $pedido_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows === 0) {
        throw new Exception("El pedido especificado no existe.");
    }

    $pedInfo = $resCheck->fetch_assoc();
    $estado_actual = $pedInfo["estado"];
    $assigned_driver = $pedInfo["repartidor_id"];

    // Validar transiciones permitidas
    if ($nuevo_estado === "en_ruta") {
        // Para iniciar ruta, el pedido debe estar 'preparado' y no estar ya asignado a otro repartidor
        if ($estado_actual !== "preparado") {
            throw new Exception("Solo se pueden iniciar rutas para pedidos en estado 'preparado'.");
        }
        if ($assigned_driver !== null && (int)$assigned_driver !== $repartidor_id) {
            throw new Exception("Este pedido ya está asignado a otro repartidor.");
        }

        // Asignar el repartidor y cambiar estado a 'en_ruta'
        $stmtUpdate = $conn->prepare("UPDATE pedidos SET repartidor_id = ?, estado = 'en_ruta' WHERE id = ?");
        $stmtUpdate->bind_param("ii", $repartidor_id, $pedido_id);
        
    } elseif ($nuevo_estado === "entregado") {
        // Para entregar, el pedido debe estar 'en_ruta' y asignado al repartidor actual
        if ($estado_actual !== "en_ruta") {
            throw new Exception("Solo se pueden marcar como entregados los pedidos que están 'en_ruta'.");
        }
        if ((int)$assigned_driver !== $repartidor_id) {
            throw new Exception("Este pedido no te pertenece o no está asignado a tu ruta.");
        }

        // Cambiar estado a 'entregado'
        $stmtUpdate = $conn->prepare("UPDATE pedidos SET estado = 'entregado' WHERE id = ?");
        $stmtUpdate->bind_param("i", $pedido_id);
    }

    if (!$stmtUpdate->execute()) {
        throw new Exception("Error al actualizar el estado del pedido: " . $conn->error);
    }

    // 2. Registrar en bitácora
    $nCompleto = ($_SESSION["nombre"] ?? "Repartidor") . " " . ($_SESSION["apellido"] ?? "");
    $actionMsg = ($nuevo_estado === "en_ruta") ? "inició la ruta de despacho" : "completó la entrega";
    registrar_bitacora(
        "Entrega de pedido",
        "Logística",
        "El repartidor '$nCompleto' $actionMsg para el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . "."
    );

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Pedido #" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " actualizado a '$nuevo_estado' con éxito."
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
