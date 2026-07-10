<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - CONTROLADOR LOGÍSTICO DE ESTADO DE ENTREGA (API AJAX)
 * --------------------------------------------------------------------------------
 * Permite a los repartidores actualizar el estado de los pedidos, capturar evidencias
 * de entrega (firma manuscrita en Canvas, coordenadas GPS) y gestionar cancelaciones
 * con retorno automático del stock al Almacén Central.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// 1. CONTROL DE ACCESO - VALIDAR QUE EL USUARIO TIENE ROL DE REPARTIDOR (rol_id = 4)
if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(["status" => "error", "message" => "Tu sesión ha expirado. Por favor, inicia sesión de nuevo."]);
    exit;
}

if ((int)$_SESSION["rol_id"] !== 4) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado: tu sesión activa no corresponde a un Repartidor. Por favor, recarga la página."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos."]);
    exit;
}

$repartidor_id = $_SESSION["usuario_id"];
$pedido_id = (int)($input["pedido_id"] ?? 0);
$nuevo_estado = trim($input["nuevo_estado"] ?? "");
$coordenadas = trim($input["coordenadas"] ?? "");
$firma = trim($input["firma"] ?? "");
$foto = trim($input["foto"] ?? "");

if ($pedido_id <= 0 || empty($nuevo_estado)) {
    echo json_encode(["status" => "error", "message" => "ID de pedido y nuevo estado son requeridos."]);
    exit;
}

if (!in_array($nuevo_estado, ["en_ruta", "entregado", "cancelado"])) {
    echo json_encode(["status" => "error", "message" => "Estado de entrega inválido."]);
    exit;
}

$conn->begin_transaction();

try {
    // 2. OBTENER INFORMACIÓN DEL PEDIDO
    $stmtCheck = $conn->prepare("SELECT estado, repartidor_id, total FROM pedidos WHERE id = ?");
    $stmtCheck->bind_param("i", $pedido_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows === 0) {
        throw new Exception("El pedido especificado no existe.");
    }

    $pedInfo = $resCheck->fetch_assoc();
    $estado_actual = $pedInfo["estado"];
    $assigned_driver = $pedInfo["repartidor_id"];

    // 3. VALIDAR TRANSICIONES DE ESTADO Y EJECUTAR ACCIONES
    if ($nuevo_estado === "en_ruta") {
        // Iniciar ruta: debe estar preparado y libre o asignado a mí
        if ($estado_actual !== "preparado" && $estado_actual !== "pendiente") {
            throw new Exception("Solo se pueden iniciar rutas para pedidos en estado 'preparado' o 'pendiente'.");
        }
        if ($assigned_driver !== null && (int)$assigned_driver !== $repartidor_id) {
            throw new Exception("Este pedido ya está asignado a otro repartidor.");
        }

        $stmtUpdate = $conn->prepare("UPDATE pedidos SET repartidor_id = ?, estado = 'en_ruta' WHERE id = ?");
        $stmtUpdate->bind_param("ii", $repartidor_id, $pedido_id);
        
    } elseif ($nuevo_estado === "entregado") {
        // Entregar: debe estar en ruta y asignado a mí
        if ($estado_actual !== "en_ruta") {
            throw new Exception("Solo se pueden marcar como entregados los pedidos que están 'en_ruta'.");
        }
        if ((int)$assigned_driver !== $repartidor_id) {
            throw new Exception("Este pedido no te pertenece o no está asignado a tu ruta.");
        }

        // Guardar estado entregado con firma, GPS y timestamp exacto. Además, aprobamos el pago si venía contra reembolso.
        $stmtUpdate = $conn->prepare("UPDATE pedidos SET estado = 'entregado', pago_estado = 'aprobado', fecha_entrega = NOW(), coordenadas_entrega = ?, firma_entrega = ?, foto_entrega = ? WHERE id = ?");
        $stmtUpdate->bind_param("sssi", $coordenadas, $firma, $foto, $pedido_id);
        
    } elseif ($nuevo_estado === "cancelado") {
        // Cancelar / Fallo de Entrega: debe estar en ruta o preparado
        if ($estado_actual !== "en_ruta" && $estado_actual !== "preparado") {
            throw new Exception("Solo se pueden cancelar pedidos que estén 'preparado' o 'en_ruta'.");
        }
        if ($assigned_driver !== null && (int)$assigned_driver !== $repartidor_id) {
            throw new Exception("Este pedido no te pertenece para ser cancelado.");
        }

        // 3.1 Actualizar estado del pedido a cancelado en la cabecera
        $stmtUpdate = $conn->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?");
        $stmtUpdate->bind_param("i", $pedido_id);

        // 3.2 REINCORPORAR STOCK EN INVENTARIO (FIFO RESTORE)
        $stmtItems = $conn->prepare("SELECT producto_id, cantidad FROM detalle_pedido WHERE pedido_id = ?");
        $stmtItems->bind_param("i", $pedido_id);
        $stmtItems->execute();
        $resItems = $stmtItems->get_result();
        
        while ($item = $resItems->fetch_assoc()) {
            $prod_id = (int)$item["producto_id"];
            $cant_to_restore = (int)$item["cantidad"] * 30; // Reincorporar huevos individuales (1 cartón = 30 huevos)

            // Buscar el lote más reciente de ese producto para reintegrarle el stock
            $stmtLote = $conn->prepare("SELECT id, cantidad FROM inventario_huevos WHERE producto_id = ? ORDER BY id DESC LIMIT 1");
            $stmtLote->bind_param("i", $prod_id);
            $stmtLote->execute();
            $resLote = $stmtLote->get_result();
            
            if ($resLote->num_rows > 0) {
                $loteRow = $resLote->fetch_assoc();
                $lote_id = $loteRow["id"];
                $new_lote_cant = (int)$loteRow["cantidad"] + $cant_to_restore;
                $new_lote_estado = $new_lote_cant >= 100 ? 'disponible' : 'bajo_stock';

                $stmtRestore = $conn->prepare("UPDATE inventario_huevos SET cantidad = ?, estado = ? WHERE id = ?");
                $stmtRestore->bind_param("isi", $new_lote_cant, $new_lote_estado, $lote_id);
                $stmtRestore->execute();
            }
        }
    }

    if (!$stmtUpdate->execute()) {
        throw new Exception("Error al actualizar el estado del pedido: " . $conn->error);
    }

    // 4. REGISTRAR OPERACIÓN EN LA BITÁCORA DEL ADMINISTRADOR
    $nCompleto = ($_SESSION["nombre"] ?? "Repartidor") . " " . ($_SESSION["apellido"] ?? "");
    $actionMsg = "actualizó el pedido #" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " al estado '$nuevo_estado'";
    if ($nuevo_estado === "entregado") {
        $actionMsg = "completó la entrega del pedido #" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " y registró firma y GPS";
    } elseif ($nuevo_estado === "cancelado") {
        $actionMsg = "canceló la entrega del pedido #" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " y reintegró los productos al stock de huevos";
    }

    registrar_bitacora(
        "Entrega de pedido",
        "Logística",
        "El repartidor '$nCompleto' $actionMsg."
    );

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Pedido #" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " actualizado a '$nuevo_estado' de forma exitosa."
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
