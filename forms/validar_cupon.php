<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - VALIDADOR DE CUPONES DE FIDELIZACIÓN (API AJAX)
 * --------------------------------------------------------------------------------
 * Valida la existencia y estado de un cupón de descuento y retorna el tipo
 * y monto del descuento en formato JSON.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// 1. CONTROL DE ACCESO - VALIDAR SESIÓN ACTIVA
if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(["status" => "error", "message" => "Sesión no válida."]);
    exit;
}

// 2. OBTENER CÓDIGO
$input = json_decode(file_get_contents("php://input"), true);
$codigo = trim($input["codigo"] ?? $_GET["codigo"] ?? "");

if (empty($codigo)) {
    echo json_encode(["status" => "error", "message" => "El código de cupón no puede estar vacío."]);
    exit;
}

// 3. BUSCAR EN BASE DE DATOS
$stmt = $conn->prepare("SELECT id, tipo, descuento, activo FROM cupones WHERE codigo = ?");
if ($stmt) {
    $stmt->bind_param("s", $codigo);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $cupon = $res->fetch_assoc();
        
        if ((int)$cupon["activo"] === 1) {
            echo json_encode([
                "status" => "success",
                "message" => "¡Cupón aplicado correctamente!",
                "data" => [
                    "codigo" => strtoupper($codigo),
                    "tipo" => $cupon["tipo"],
                    "descuento" => (float)$cupon["descuento"]
                ]
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Este cupón se encuentra inactivo."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "El código de cupón ingresado no existe."]);
    }
    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "Error interno al validar el cupón."]);
}
exit;
?>
