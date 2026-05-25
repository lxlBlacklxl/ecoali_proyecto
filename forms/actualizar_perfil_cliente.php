<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - SISTEMA DE CONTROL DE PERFIL DEL CLIENTE (API AJAX)
 * --------------------------------------------------------------------------------
 * Este script procesa las peticiones asíncronas (AJAX/Fetch) para actualizar
 * de forma segura la información del perfil del cliente (Nombre, Apellido, Email, 
 * Teléfono, Dirección) y mantener sincronizada la sesión activa.
 */

// 1. INICIAR EL MANEJO DE SESIONES
session_start();

// 2. IMPORTAR LA CONEXIÓN DE LA BASE DE DATOS Y BITÁCORA
require "conexion.php";

// 3. ESTABLECER LA CABECERA PARA RESPUESTAS EN FORMATO JSON
header("Content-Type: application/json");

// 4. CONTROL DE ACCESO - VALIDAR QUE EL USUARIO TIENE ROL DE CLIENTE (rol_id = 2)
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 2) {
    echo json_encode(["status" => "error", "message" => "Acceso denegado. No está autorizado para realizar esta acción."]);
    exit;
}

// 5. OBTENER Y DECODIFICAR LOS DATOS JSON ENVIADOS POR PETICIÓN POST
$input = json_decode(file_get_contents("php://input"), true);

// 6. VALIDAR QUE SE HAYA ENVIADO INFORMACIÓN EN EL CUERPO DE LA PETICIÓN
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos o vacíos."]);
    exit;
}

// 7. SANITIZAR Y ASIGNAR LAS VARIABLES A PARTIR DE LOS DATOS RECIBIDOS
$cliente_id = $_SESSION["usuario_id"];
$nombre = trim($input["nombre"] ?? "");
$apellido = trim($input["apellido"] ?? "");
$email = trim($input["email"] ?? "");
$telefono = trim($input["telefono"] ?? "");
$direccion = trim($input["direccion"] ?? "");

// 8. COMPROBAR QUE LOS CAMPOS OBLIGATORIOS TIENEN CONTENIDO
if (empty($nombre) || empty($apellido) || empty($email)) {
    echo json_encode(["status" => "error", "message" => "El nombre, apellido y correo electrónico son de carácter obligatorio."]);
    exit;
}

// 9. INICIAR TRANSACCIÓN SQL PARA GARANTIZAR INTEGRIDAD REFERENCIAL
$conn->begin_transaction();

try {
    // 10. PREVENIR DUPLICADO DE CORREO ELECTRÓNICO (Excluyendo al usuario actual)
    $stmtCheckEmail = $conn->prepare("SELECT usuario_id FROM usuario_perfil WHERE email = ? AND usuario_id != ?");
    $stmtCheckEmail->bind_param("si", $email, $cliente_id);
    $stmtCheckEmail->execute();
    $resCheck = $stmtCheckEmail->get_result();

    if ($resCheck->num_rows > 0) {
        throw new Exception("El correo electrónico especificado ya se encuentra en uso por otra cuenta activa.");
    }

    // 11. ACTUALIZAR LOS DATOS EN LA TABLA 'usuario_perfil' DE FORMA PREPARADA
    $stmtUpdate = $conn->prepare("UPDATE usuario_perfil SET nombre = ?, apellido = ?, email = ?, telefono = ?, direccion = ? WHERE usuario_id = ?");
    $stmtUpdate->bind_param("sssssi", $nombre, $apellido, $email, $telefono, $direccion, $cliente_id);

    if (!$stmtUpdate->execute()) {
        throw new Exception("Ocurrió un error interno al intentar actualizar los datos en el perfil.");
    }

    // 12. ACTUALIZAR LAS VARIABLES DE SESIÓN ACTIVA PARA REFLEJAR LOS CAMBIOS DE INMEDIATO
    $_SESSION["nombre"] = $nombre;
    $_SESSION["apellido"] = $apellido;
    $_SESSION["email"] = $email;

    // 13. AUDITAR ACCIÓN REGISTRANDO EN LA BITÁCORA DEL ADMINISTRADOR
    registrar_bitacora("Perfil actualizado", "Usuarios", "El cliente '$nombre $apellido' actualizó su información de contacto.");

    // 14. CONFIRMAR TRANSACCIÓN EN BASE DE DATOS
    $conn->commit();

    // 15. RETORNAR RESPUESTA DE ÉXITO EN FORMATO JSON
    echo json_encode([
        "status" => "success",
        "message" => "¡Su perfil ha sido actualizado de manera exitosa!"
    ]);

} catch (Exception $e) {
    // 16. EN CASO DE ERROR, REVERTIR TODAS LAS OPERACIONES PARA EVITAR INCONSISTENCIAS
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
