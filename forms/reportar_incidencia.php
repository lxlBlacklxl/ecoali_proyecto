<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - CONTROLADOR DE REPORTE DE INCIDENCIAS LOGÍSTICAS (API AJAX)
 * --------------------------------------------------------------------------------
 * Permite a los repartidores logueados reportar cualquier incidencia ocurrida
 * durante el reparto de pedidos (cliente ausente, daño en producto, etc.),
 * guardando la bitácora y la trazabilidad para soporte y control.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// 1. CONTROL DE ACCESO - VALIDAR QUE EL USUARIO TIENE ROL DE REPARTIDOR (rol_id = 4)
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 4) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado para este perfil logístico."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos."]);
    exit;
}

$repartidor_id = $_SESSION["usuario_id"];
$pedido_id = (int)($input["pedido_id"] ?? 0);
$tipo = trim($input["tipo"] ?? "");
$descripcion = trim($input["descripcion"] ?? "");
$coordenadas = trim($input["coordenadas"] ?? "");

if ($pedido_id <= 0 || empty($tipo) || empty($descripcion)) {
    echo json_encode(["status" => "error", "message" => "Todos los campos de la incidencia son obligatorios."]);
    exit;
}

$conn->begin_transaction();

try {
    // 2. VERIFICAR QUE EL PEDIDO PERTENEZCA AL CHOFER O ESTÉ ASIGNADO
    $stmtCheck = $conn->prepare("SELECT repartidor_id FROM pedidos WHERE id = ?");
    $stmtCheck->bind_param("i", $pedido_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows === 0) {
        throw new Exception("El pedido especificado no existe.");
    }

    $ped = $resCheck->fetch_assoc();
    if ($ped["repartidor_id"] !== null && (int)$ped["repartidor_id"] !== $repartidor_id) {
        throw new Exception("Este pedido no te pertenece o no está asignado a tu ruta.");
    }

    // 3. REGISTRAR LA INCIDENCIA EN LA BASE DE DATOS
    $stmtInsert = $conn->prepare("INSERT INTO incidencias (pedido_id, repartidor_id, tipo, descripcion, coordenadas) VALUES (?, ?, ?, ?, ?)");
    $stmtInsert->bind_param("iisss", $pedido_id, $repartidor_id, $tipo, $descripcion, $coordenadas);
    
    if (!$stmtInsert->execute()) {
        throw new Exception("Error al guardar la incidencia en base de datos: " . $conn->error);
    }

    // 4. REGISTRAR EN LA BITÁCORA DEL ADMINISTRADOR
    $nCompleto = ($_SESSION["nombre"] ?? "Repartidor") . " " . ($_SESSION["apellido"] ?? "");
    $tipoStr = [
        "cliente_ausente" => "Cliente Ausente",
        "direccion_erronea" => "Dirección Incorrecta",
        "producto_danado" => "Producto Dañado/Rotura",
        "otros" => "Otros Factores"
    ][$tipo] ?? $tipo;

    registrar_bitacora(
        "Incidencia de reparto",
        "Logística",
        "El repartidor '$nCompleto' reportó una eventualidad de tipo '$tipoStr' para el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . ". Comentario: '$descripcion'."
    );

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Incidencia registrada correctamente. El equipo de soporte de EcoAli ha sido notificado."
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
