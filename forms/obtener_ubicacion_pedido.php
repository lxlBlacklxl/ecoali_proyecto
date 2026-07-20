<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - OBTENER UBICACIÓN GPS EN TIEMPO REAL DEL PEDIDO (API AJAX)
 * --------------------------------------------------------------------------------
 * Permite a los clientes consultar las coordenadas GPS actualizadas del repartidor
 * cuando su pedido se encuentra en ruta.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// 1. CONTROL DE ACCESO - Sesión iniciada obligatoria
if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(["status" => "error", "message" => "Tu sesión ha expirado."]);
    exit;
}

$usuario_id = (int)$_SESSION["usuario_id"];
$rol_id = (int)($_SESSION["rol_id"] ?? 0);
$pedido_id = (int)($_GET["pedido_id"] ?? 0);

if ($pedido_id <= 0) {
    echo json_encode(["status" => "error", "message" => "ID de pedido inválido."]);
    exit;
}

// 2. OBTENER INFORMACIÓN DE LA ORDEN Y SU UBICACIÓN GPS
$sql = "SELECT p.id, p.cliente_id, p.repartidor_id, p.estado, p.coordenadas_entrega,
               up.nombre AS repartidor_nombre, up.apellido AS repartidor_apellido, up.telefono AS repartidor_telefono
        FROM pedidos p
        LEFT JOIN usuario_perfil up ON p.repartidor_id = up.usuario_id
        WHERE p.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $pedido_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "El pedido especificado no existe."]);
    exit;
}

$ped = $res->fetch_assoc();

// Solo el cliente dueño del pedido, el repartidor asignado o administradores pueden consultar
if ($rol_id !== 1 && $rol_id !== 4 && (int)$ped["cliente_id"] !== $usuario_id) {
    echo json_encode(["status" => "error", "message" => "No tienes acceso a este pedido."]);
    exit;
}

$coords_raw = trim($ped["coordenadas_entrega"] ?? "");
$lat = null;
$lng = null;

if (!empty($coords_raw)) {
    $parts = explode(",", $coords_raw);
    if (count($parts) === 2) {
        $lat = (float)trim($parts[0]);
        $lng = (float)trim($parts[1]);
    }
}

echo json_encode([
    "status" => "success",
    "pedido_id" => (int)$ped["id"],
    "estado" => $ped["estado"],
    "coordenadas_raw" => $coords_raw,
    "lat" => $lat,
    "lng" => $lng,
    "repartidor" => [
        "nombre" => trim(($ped["repartidor_nombre"] ?? "Repartidor") . " " . ($ped["repartidor_apellido"] ?? "")),
        "telefono" => $ped["repartidor_telefono"] ?? ""
    ],
    "timestamp" => date("Y-m-d H:i:s")
]);
?>
