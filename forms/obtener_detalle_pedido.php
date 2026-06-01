<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - OBTENER DETALLE DE PEDIDO PARA CHOFER (API AJAX)
 * --------------------------------------------------------------------------------
 * Retorna de forma asíncrona la cabecera del pedido, desglose de IVA, descuentos,
 * coordenadas GPS, firma digital, foto de entrega e ítems de huevos detallados.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// Control de acceso: solo repartidores (rol_id = 4)
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 4) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado para este perfil logístico."]);
    exit;
}

$pedido_id = isset($_GET["pedido_id"]) ? (int)$_GET["pedido_id"] : 0;

if ($pedido_id <= 0) {
    echo json_encode(["status" => "error", "message" => "ID de pedido inválido."]);
    exit;
}

try {
    // 1. Obtener la cabecera del pedido
    $sqlCab = "SELECT subtotal, iva, descuento, total, metodo_pago, pago_estado, coordenadas_entrega, firma_entrega, foto_entrega, fecha_entrega
               FROM pedidos
               WHERE id = ?";
    $stmtCab = $conn->prepare($sqlCab);
    $stmtCab->bind_param("i", $pedido_id);
    $stmtCab->execute();
    $resCab = $stmtCab->get_result();

    if ($resCab->num_rows === 0) {
        throw new Exception("No se encontró el registro del pedido especificado.");
    }

    $cab = $resCab->fetch_assoc();

    // 2. Obtener los productos/detalles asociados a este pedido
    $sqlItems = "SELECT dp.cantidad, dp.precio_unitario, dp.subtotal, p.nombre AS producto_nombre
                 FROM detalle_pedido dp
                 INNER JOIN productos p ON dp.producto_id = p.id
                 WHERE dp.pedido_id = ?";
    $stmtItems = $conn->prepare($sqlItems);
    $stmtItems->bind_param("i", $pedido_id);
    $stmtItems->execute();
    $resItems = $stmtItems->get_result();

    $items = [];
    while ($row = $resItems->fetch_assoc()) {
        $items[] = [
            "producto_nombre" => $row["producto_nombre"],
            "cantidad" => (int)$row["cantidad"],
            "precio_unitario" => (float)$row["precio_unitario"],
            "subtotal" => (float)$row["subtotal"]
        ];
    }

    echo json_encode([
        "status" => "success",
        "items" => $items,
        "cabecera" => [
            "subtotal" => (float)$cab["subtotal"],
            "iva" => (float)$cab["iva"],
            "descuento" => (float)$cab["descuento"],
            "total" => (float)$cab["total"],
            "metodo_pago" => $cab["metodo_pago"],
            "pago_estado" => $cab["pago_estado"],
            "coordenadas" => $cab["coordenadas_entrega"] ?? "Sin coordenadas GPS",
            "firma" => $cab["firma_entrega"] ?? "",
            "foto" => $cab["foto_entrega"] ?? "",
            "fecha_entrega" => $cab["fecha_entrega"] ? date('d M Y, h:i A', strtotime($cab["fecha_entrega"])) : ""
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
