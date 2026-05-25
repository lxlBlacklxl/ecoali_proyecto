<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - SISTEMA DE CONTROL DE PERFIL DEL REPARTIDOR (API AJAX)
 * --------------------------------------------------------------------------------
 * Este script procesa las peticiones asíncronas (AJAX/Fetch) para actualizar
 * la información de perfil y contacto de los choferes/repartidores logísticos 
 * (Nombre, Apellido, Email, Teléfono, Dirección) y sincronizar la sesión.
 */

// 1. INICIAR EL MANEJO DE SESIONES
session_start();

// 2. IMPORTAR LA CONEXIÓN DE LA BASE DE DATOS Y BITÁCORA
require "conexion.php";

// 3. ESTABLECER LA CABECERA PARA RESPUESTAS EN FORMATO JSON
header("Content-Type: application/json");

// 4. CONTROL DE ACCESO - VALIDAR QUE EL USUARIO TIENE ROL DE REPARTIDOR (rol_id = 4)
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 4) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado para este perfil de usuario."]);
    exit;
}

// 5. OBTENER Y DECODIFICAR LOS DATOS JSON ENVIADOS POR PETICIÓN POST
$input = json_decode(file_get_contents("php://input"), true);

// 6. VALIDAR QUE SE HAYA RECIBIDO INFORMACIÓN VÁLIDA
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos."]);
    exit;
}

// 7. SANITIZAR Y ASIGNAR VARIABLES DE PERFIL REPARTIDOR
$repartidor_id = $_SESSION["usuario_id"];
$nombre = trim($input["nombre"] ?? "");
$apellido = trim($input["apellido"] ?? "");
$email = trim($input["email"] ?? "");
$telefono = trim($input["telefono"] ?? "");
$direccion = trim($input["direccion"] ?? "");

// 8. COMPROBAR QUE LOS CAMPOS OBLIGATORIOS TIENEN CONTENIDO
if (empty($nombre) || empty($apellido) || empty($email)) {
    echo json_encode(["status" => "error", "message" => "Nombre, apellido y correo electrónico son campos obligatorios."]);
    exit;
}

// 9. INICIAR TRANSACCIÓN SQL PARA PROTEGER LA INTEGRIDAD DE LOS DATOS
$conn->begin_transaction();

try {
    // 10. PREVENIR DUPLICADO DE CORREO ELECTRÓNICO (Excluyendo al usuario actual)
    $stmtCheckEmail = $conn->prepare("SELECT usuario_id FROM usuario_perfil WHERE email = ? AND usuario_id != ?");
    $stmtCheckEmail->bind_param("si", $email, $repartidor_id);
    $stmtCheckEmail->execute();
    $resCheck = $stmtCheckEmail->get_result();

    if ($resCheck->num_rows > 0) {
        throw new Exception("El correo electrónico especificado ya se encuentra en uso por otra cuenta activa.");
    }

    // 11. ACTUALIZAR LOS DATOS DEL PERFIL EN LA TABLA 'usuario_perfil'
    $stmtUpdate = $conn->prepare("UPDATE usuario_perfil SET nombre = ?, apellido = ?, email = ?, telefono = ?, direccion = ? WHERE usuario_id = ?");
    $stmtUpdate->bind_param("sssssi", $nombre, $apellido, $email, $telefono, $direccion, $repartidor_id);

    if (!$stmtUpdate->execute()) {
        throw new Exception("Error al actualizar la información del perfil del repartidor.");
    }

    // 12. ACTUALIZAR LAS VARIABLES DE SESIÓN ACTIVA PARA HACER LOS CAMBIOS INMEDIATOS
    $_SESSION["nombre"] = $nombre;
    $_SESSION["apellido"] = $apellido;
    $_SESSION["email"] = $email;

    // 13. REGISTRAR OPERACIÓN EN EL LIBRO DE AUDITORÍA (BITÁCORA)
    registrar_bitacora("Perfil actualizado", "Usuarios", "El repartidor '$nombre $apellido' actualizó su información de perfil.");

    // 14. CONFIRMAR TRANSACCIÓN EN BASE DE DATOS
    $conn->commit();

    // 15. RETORNAR RESPUESTA EXITOSA EN FORMATO JSON
    echo json_encode([
        "status" => "success",
        "message" => "¡Su perfil de repartidor ha sido actualizado correctamente!"
    ]);

} catch (Exception $e) {
    // 16. EN CASO DE ERROR, REVERTIR LOS CAMBIOS EN BASE DE DATOS
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
