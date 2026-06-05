<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - SISTEMA DE CONTROL DE PERFIL DEL PROVEEDOR (API AJAX)
 * --------------------------------------------------------------------------------
 * Este script procesa las peticiones asíncronas (AJAX/Fetch) para actualizar
 * la información corporativa del proveedor avícola (Nombre de empresa, Contacto, 
 * Teléfono, Dirección/Ubicación) en la base de datos de manera transaccional.
 */

// 1. INICIAR EL MANEJO DE SESIONES
session_start();

// 2. IMPORTAR LA CONEXIÓN DE LA BASE DE DATOS Y BITÁCORA
require "conexion.php";

// 3. ESTABLECER LA CABECERA PARA RESPUESTAS EN FORMATO JSON
header("Content-Type: application/json");

// 4. CONTROL DE ACCESO - VALIDAR QUE EL USUARIO TIENE ROL DE PROVEEDOR (rol_id = 3)
if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(["status" => "error", "message" => "Tu sesión ha expirado. Por favor, inicia sesión de nuevo."]);
    exit;
}

if ((int)$_SESSION["rol_id"] !== 3) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado: tu sesión activa no corresponde a un Proveedor. Por favor, recarga la página."]);
    exit;
}

// 5. OBTENER Y DECODIFICAR LOS DATOS JSON ENVIADOS POR PETICIÓN POST
$input = json_decode(file_get_contents("php://input"), true);

// 6. VALIDAR QUE SE HAYA RECIBIDO INFORMACIÓN VÁLIDA
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos."]);
    exit;
}

// 7. SANITIZAR Y ASIGNAR VARIABLES DE PERFIL PROVEEDOR
$usuario_id = $_SESSION["usuario_id"];
$nombre_empresa = trim($input["nombre_empresa"] ?? "");
$contacto = trim($input["contacto"] ?? "");
$telefono = trim($input["telefono"] ?? "");
$ubicacion = trim($input["ubicacion"] ?? "");

// 8. COMPROBAR QUE LOS CAMPOS OBLIGATORIOS NO ESTÉN VACÍOS
if (empty($nombre_empresa) || empty($contacto)) {
    echo json_encode(["status" => "error", "message" => "El nombre de la empresa y la persona de contacto son campos requeridos."]);
    exit;
}

// 9. INICIAR TRANSACCIÓN SQL PARA PROTEGER LA INTEGRIDAD DE LOS DATOS
$conn->begin_transaction();

try {
    // 10. VERIFICAR SI YA EXISTE UN PERFIL CREADO PARA ESTE PROVEEDOR
    $stmtCheck = $conn->prepare("SELECT id FROM proveedores WHERE usuario_id = ?");
    $stmtCheck->bind_param("i", $usuario_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows === 0) {
        // 11. SI ES UN NUEVO PROVEEDOR: REALIZAR EL REGISTRO INICIAL
        $stmtInsert = $conn->prepare("INSERT INTO proveedores (usuario_id, nombre_empresa, contacto, telefono, ubicacion, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
        $stmtInsert->bind_param("issss", $usuario_id, $nombre_empresa, $contacto, $telefono, $ubicacion);
        if (!$stmtInsert->execute()) {
            throw new Exception("Error al realizar el registro de la información del proveedor.");
        }
    } else {
        // 12. SI YA EXISTÍA EL PROVEEDOR: ACTUALIZAR SUS DATOS CORPORATIVOS
        $stmtUpdate = $conn->prepare("UPDATE proveedores SET nombre_empresa = ?, contacto = ?, telefono = ?, ubicacion = ? WHERE usuario_id = ?");
        $stmtUpdate->bind_param("ssssi", $nombre_empresa, $contacto, $telefono, $ubicacion, $usuario_id);
        if (!$stmtUpdate->execute()) {
            throw new Exception("Error al actualizar la información del perfil del proveedor.");
        }
    }

    // 13. ACTUALIZAR EL NOMBRE DE CONTACTO EN LA SESIÓN ACTIVA DEL USUARIO
    $_SESSION["nombre"] = $contacto;

    // 14. REGISTRAR OPERACIÓN EN EL LIBRO DE AUDITORÍA (BITÁCORA)
    registrar_bitacora("Perfil actualizado", "Proveedores", "El proveedor '$nombre_empresa' actualizó su información de perfil y contacto.");

    // 15. CONFIRMAR TRANSACCIÓN EN BASE DE DATOS
    $conn->commit();

    // 16. RETORNAR RESPUESTA EXITOSA EN FORMATO JSON
    echo json_encode([
        "status" => "success",
        "message" => "¡El perfil de la empresa ha sido actualizado con éxito!"
    ]);

} catch (Exception $e) {
    // 17. EN CASO DE ERROR, REVERTIR LOS CAMBIOS EN BASE DE DATOS
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
