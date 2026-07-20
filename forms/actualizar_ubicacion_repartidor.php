<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - ACTUALIZAR UBICACIÓN GPS EN TIEMPO REAL DEL REPARTIDOR (API AJAX)
 * --------------------------------------------------------------------------------
 * Recibe latitud y longitud enviadas desde la app web del repartidor mientras
 * realiza una entrega 'en_ruta' y actualiza el campo de coordenadas en la orden.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// 1. CONTROL DE ACCESO - Solo Repartidores (Rol 4)
if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(["status" => "error", "message" => "Tu sesión ha expirado."]);
    exit;
}

if ((int)$_SESSION["rol_id"] !== 4) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Solicitud inválida."]);
    exit;
}

$repartidor_id = (int)$_SESSION["usuario_id"];
$pedido_id = (int)($input["pedido_id"] ?? 0);
$lat = filter_var($input["lat"] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($input["lng"] ?? null, FILTER_VALIDATE_FLOAT);

if ($pedido_id <= 0 || $lat === false || $lng === false) {
    echo json_encode(["status" => "error", "message" => "Coordenadas o ID de pedido inválidos."]);
    exit;
}

// 2. VALIDAR QUE EL PEDIDO PERTENECE AL REPARTIDOR Y ESTÁ EN RUTA
$stmtCheck = $conn->prepare("SELECT estado, repartidor_id FROM pedidos WHERE id = ?");
$stmtCheck->bind_param("i", $pedido_id);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();

if ($resCheck->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "El pedido no existe."]);
    exit;
}

$ped = $resCheck->fetch_assoc();
if ($ped["estado"] !== "en_ruta") {
    echo json_encode(["status" => "error", "message" => "El pedido no está activo en ruta."]);
    exit;
}

if ((int)$ped["repartidor_id"] !== $repartidor_id) {
    echo json_encode(["status" => "error", "message" => "No estás asignado a este pedido."]);
    exit;
}

// 3. ACTUALIZAR COORDENADAS GPS
$coords_str = "$lat,$lng";
$stmtUp = $conn->prepare("UPDATE pedidos SET coordenadas_entrega = ? WHERE id = ?");
$stmtUp->bind_param("si", $coords_str, $pedido_id);

if ($stmtUp->execute()) {
    echo json_encode([
        "status" => "success",
        "message" => "Ubicación GPS actualizada.",
        "coordenadas" => $coords_str,
        "updated_at" => date("Y-m-d H:i:s")
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Error al actualizar coordenadas en la base de datos."]);
}
?>
