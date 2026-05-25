<?php
session_start();
require "conexion.php";

header("Content-Type: application/json");

if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 3) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos."]);
    exit;
}

$usuario_id = $_SESSION["usuario_id"];
$nombre_empresa = trim($input["nombre_empresa"] ?? "");
$contacto = trim($input["contacto"] ?? "");
$telefono = trim($input["telefono"] ?? "");
$ubicacion = trim($input["ubicacion"] ?? "");

if (empty($nombre_empresa) || empty($contacto)) {
    echo json_encode(["status" => "error", "message" => "Nombre de la empresa y persona de contacto son campos obligatorios."]);
    exit;
}

$conn->begin_transaction();

try {
    // Verificar si el proveedor existe
    $stmtCheck = $conn->prepare("SELECT id FROM proveedores WHERE usuario_id = ?");
    $stmtCheck->bind_param("i", $usuario_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows === 0) {
        // Si no existe, lo creamos
        $stmtInsert = $conn->prepare("INSERT INTO proveedores (usuario_id, nombre_empresa, contacto, telefono, ubicacion, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
        $stmtInsert->bind_param("issss", $usuario_id, $nombre_empresa, $contacto, $telefono, $ubicacion);
        if (!$stmtInsert->execute()) {
            throw new Exception("Error al registrar información del proveedor.");
        }
    } else {
        // Si existe, lo actualizamos
        $stmtUpdate = $conn->prepare("UPDATE proveedores SET nombre_empresa = ?, contacto = ?, telefono = ?, ubicacion = ? WHERE usuario_id = ?");
        $stmtUpdate->bind_param("ssssi", $nombre_empresa, $contacto, $telefono, $ubicacion, $usuario_id);
        if (!$stmtUpdate->execute()) {
            throw new Exception("Error al actualizar la información del proveedor.");
        }
    }

    // Actualizar nombre en sesión
    $_SESSION["nombre"] = $contacto;

    // Registrar en bitácora
    registrar_bitacora("Perfil actualizado", "Proveedores", "El proveedor '$nombre_empresa' actualizó su información de perfil y contacto.");

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "¡Perfil de proveedor actualizado con éxito!"
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
