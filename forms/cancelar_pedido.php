<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - SISTEMA DE CANCELACIÓN DE PEDIDOS POR EL CLIENTE (API AJAX)
 * --------------------------------------------------------------------------------
 * Este script asíncrono permite a los clientes logueados cancelar un pedido
 * de manera autónoma, siempre y cuando se encuentre en estado 'pendiente'.
 * La operación es transaccional, eliminando las regalías asociadas y dejando
 * un registro de bitácora detallado para control de inventario.
 */

// 1. INICIAR EL MANEJO DE SESIONES
session_start();

// 2. IMPORTAR LA CONEXIÓN DE LA BASE DE DATOS Y BITÁCORA
require "conexion.php";

// 3. ESTABLECER LA CABECERA PARA RESPUESTAS EN FORMATO JSON
header("Content-Type: application/json");

// 4. CONTROL DE ACCESO - VALIDAR QUE EL USUARIO TIENE ROL DE CLIENTE (rol_id = 2)
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 2) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado para este perfil."]);
    exit;
}

// 5. OBTENER Y DECODIFICAR LOS DATOS JSON ENVIADOS POR PETICIÓN POST
$input = json_decode(file_get_contents("php://input"), true);
$pedido_id = (int)($input["pedido_id"] ?? 0);
$cliente_id = $_SESSION["usuario_id"];

// 6. VALIDAR QUE EL ID DEL PEDIDO ENVIADO SEA VÁLIDO
if ($pedido_id <= 0) {
    echo json_encode(["status" => "error", "message" => "ID de pedido inválido."]);
    exit;
}

// 7. INICIAR TRANSACCIÓN SQL PARA PROTEGER LA INTEGRIDAD DE LOS DATOS
$conn->begin_transaction();

try {
    // 8. VERIFICAR QUE EL PEDIDO PERTENEZCA AL CLIENTE Y ESTÉ EN ESTADO 'PENDIENTE'
    $stmtCheck = $conn->prepare("SELECT estado, total FROM pedidos WHERE id = ? AND cliente_id = ?");
    $stmtCheck->bind_param("ii", $pedido_id, $cliente_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows === 0) {
        throw new Exception("El pedido especificado no existe o no te pertenece.");
    }

    $pedInfo = $resCheck->fetch_assoc();
    if ($pedInfo["estado"] !== "pendiente") {
        throw new Exception("Solo se pueden cancelar pedidos que estén en estado 'pendiente'.");
    }

    // 9. ACTUALIZAR EL ESTADO DEL PEDIDO A 'CANCELADO'
    $stmtUpdate = $conn->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?");
    $stmtUpdate->bind_param("i", $pedido_id);
    
    if (!$stmtUpdate->execute()) {
        throw new Exception("Error al procesar la cancelación del pedido en el servidor.");
    }

    // 10. ELIMINAR REGALÍAS ASOCIADAS A ESTE PEDIDO PARA MANTENER LA LIMPIEZA FINANCIERA
    $stmtDelReg = $conn->prepare("DELETE FROM regalias WHERE pedido_id = ?");
    $stmtDelReg->bind_param("i", $pedido_id);
    $stmtDelReg->execute();

    // 11. REGISTRAR OPERACIÓN EN EL LIBRO DE AUDITORÍA (BITÁCORA)
    $nCompleto = ($_SESSION["nombre"] ?? "Cliente") . " " . ($_SESSION["apellido"] ?? "");
    registrar_bitacora("Pedido cancelado", "Logística", "El cliente '$nCompleto' canceló el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " por un total de $" . number_format($pedInfo["total"], 2) . ".");

    // 12. CONFIRMAR TRANSACCIÓN EN BASE DE DATOS
    $conn->commit();

    // 13. RETORNAR RESPUESTA EXITOSA EN FORMATO JSON
    echo json_encode([
        "status" => "success",
        "message" => "Pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " cancelado con éxito."
    ]);

} catch (Exception $e) {
    // 14. EN CASO DE ERROR, REVERTIR LOS CAMBIOS EN BASE DE DATOS
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
