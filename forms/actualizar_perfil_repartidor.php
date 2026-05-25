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
$nombre = trim($input["nombre"] ?? "");
$apellido = trim($input["apellido"] ?? "");
$email = trim($input["email"] ?? "");
$telefono = trim($input["telefono"] ?? "");
$direccion = trim($input["direccion"] ?? "");

if (empty($nombre) || empty($apellido) || empty($email)) {
    echo json_encode(["status" => "error", "message" => "Nombre, apellido y correo electrónico son campos obligatorios."]);
    exit;
}

$conn->begin_transaction();

try {
    // Validar email
    $stmtCheckEmail = $conn->prepare("SELECT usuario_id FROM usuario_perfil WHERE email = ? AND usuario_id != ?");
    $stmtCheckEmail->bind_param("si", $email, $repartidor_id);
    $stmtCheckEmail->execute();
    $resCheck = $stmtCheckEmail->get_result();

    if ($resCheck->num_rows > 0) {
        throw new Exception("El correo electrónico especificado ya se encuentra en uso.");
    }

    // Actualizar perfil
    $stmtUpdate = $conn->prepare("UPDATE usuario_perfil SET nombre = ?, apellido = ?, email = ?, telefono = ?, direccion = ? WHERE usuario_id = ?");
    $stmtUpdate->bind_param("sssssi", $nombre, $apellido, $email, $telefono, $direccion, $repartidor_id);

    if (!$stmtUpdate->execute()) {
        throw new Exception("Error al actualizar la información del perfil.");
    }

    $_SESSION["nombre"] = $nombre;
    $_SESSION["apellido"] = $apellido;
    $_SESSION["email"] = $email;

    registrar_bitacora("Perfil actualizado", "Usuarios", "El repartidor '$nombre $apellido' actualizó su información de perfil.");

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "¡Perfil de repartidor actualizado correctamente!"
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
