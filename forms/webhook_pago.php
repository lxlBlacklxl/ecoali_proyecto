<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - WEBHOOK DE PROCESAMIENTO DE PAGO Y FACTURACIÓN ELECTRÓNICA
 * --------------------------------------------------------------------------------
 * Simula el recibo seguro y asíncrono de notificaciones IPN/Webhooks desde
 * pasarelas externas de pago (Stripe, PayPal, Mercado Pago). Valida la firma digital
 * simulada, actualiza el estado de pago del pedido a 'pagado', el estado logístico
 * a 'preparado', y dispara el registro de auditoría.
 */

require "conexion.php";

header("Content-Type: application/json");

// 1. OBTENER DATOS DE LA PASARELA (RAW POST BODY)
$payload_raw = file_get_contents("php://input");
$input = json_decode($payload_raw, true);

if (!$input) {
    // Si no es raw JSON, buscar en $_POST/$_GET
    $input = $_REQUEST;
}

$pedido_id = (int)($input["pedido_id"] ?? 0);
$metodo_pago = trim($input["metodo_pago"] ?? "stripe");
$estado_pago = trim($input["status"] ?? "");
$firma_segura = trim($input["firma"] ?? ""); // Token de seguridad simulado

if ($pedido_id <= 0 || empty($estado_pago)) {
    echo json_encode(["status" => "error", "message" => "Parámetros de webhook incompletos."]);
    exit;
}

// 2. SIMULAR VALIDACIÓN DE FIRMA DIGITAL DE SEGURIDAD (SSL/HMAC)
$signature_expected = hash_hmac('sha256', $pedido_id . $metodo_pago, 'ECOALI_SECRET_TOKEN');
if (!empty($firma_segura) && $firma_segura !== $signature_expected) {
    echo json_encode(["status" => "error", "message" => "Firma digital no válida. Intento de intrusión bloqueado."]);
    exit;
}

// 3. PROCESAR TRANSACCIÓN EN BASE DE DATOS
$conn->begin_transaction();

try {
    // Verificar si el pedido existe
    $stmtCheck = $conn->prepare("SELECT id, total, pago_estado FROM pedidos WHERE id = ?");
    $stmtCheck->bind_param("i", $pedido_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows === 0) {
        throw new Exception("El pedido especificado no existe.");
    }

    $pedido = $resCheck->fetch_assoc();
    
    if ($pedido["pago_estado"] === "pagado") {
        // Idempotencia: Si ya está pagado, retornar success inmediatamente
        $conn->commit();
        echo json_encode(["status" => "success", "message" => "El pedido ya se encontraba pagado.", "idempotent" => true]);
        exit;
    }

    if (in_array(strtolower($estado_pago), ["approved", "success", "aprobado", "completed"])) {
        // Pago aprobado: Actualizar pago a 'pagado' y estado a 'preparado' para despacho
        $stmtUp = $conn->prepare("UPDATE pedidos SET pago_estado = 'pagado', estado = 'preparado' WHERE id = ?");
        $stmtUp->bind_param("i", $pedido_id);
        
        if ($stmtUp->execute()) {
            auditar_accion(
                "Logística", 
                "Pago aprobado Webhook", 
                "Pasarela " . strtoupper($metodo_pago) . " reportó pago aprobado para el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " por un monto de $" . number_format($pedido["total"], 2)
            );
        } else {
            throw new Exception("Error al actualizar estado en la base de datos.");
        }
    } else {
        // Pago rechazado o fallido
        $stmtUp = $conn->prepare("UPDATE pedidos SET pago_estado = 'fallido' WHERE id = ?");
        $stmtUp->bind_param("i", $pedido_id);
        $stmtUp->execute();
        
        auditar_accion(
            "Logística", 
            "Pago fallido Webhook", 
            "La pasarela " . strtoupper($metodo_pago) . " reportó transacción rechazada o fallida para el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT)
        );
        throw new Exception("Transacción rechazada por el emisor.");
    }

    $conn->commit();
    echo json_encode([
        "status" => "success",
        "message" => "Transacción validada y procesada correctamente en EcoAli.",
        "pedido_id" => $pedido_id,
        "monto" => $pedido["total"]
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
exit;
?>
