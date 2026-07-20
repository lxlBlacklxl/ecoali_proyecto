<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - CONTROLADOR DE ACCIONES PARA OPERADOR DE CEDIS (API AJAX)
 * --------------------------------------------------------------------------------
 * Procesa recepciones de envíos de proveedores, mermas internas de almacén y
 * cambios de estado a 'preparado' para pedidos de clientes.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// 1. CONTROL DE ACCESO - Solo Operadores de CEDIS (Rol 5) y Administradores (Rol 1)
if (!isset($_SESSION["usuario_id"])) {
    echo json_encode(["status" => "error", "message" => "Tu sesión ha expirado. Por favor, inicia sesión de nuevo."]);
    exit;
}

$rol_actual = (int)$_SESSION["rol_id"];
if ($rol_actual !== 5 && $rol_actual !== 1) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado."]);
    exit;
}

$cedis_usuario_id = $_SESSION["cedis_id"] ?? null;

// Para Administradores, permitir que actúen sobre cualquier CEDIS
// Para Operadores, validar que tengan un CEDIS asignado
if ($rol_actual === 5 && empty($cedis_usuario_id)) {
    echo json_encode(["status" => "error", "message" => "Tu perfil de operador no tiene ningún CEDIS asignado. Contacta al administrador."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $accion = trim($_GET["accion"] ?? "");
} else {
    $input = json_decode(file_get_contents("php://input"), true);
    $accion = trim($input["accion"] ?? "");
}

if (empty($accion)) {
    echo json_encode(["status" => "error", "message" => "Acción no especificada."]);
    exit;
}

switch ($accion) {
    case "obtener_detalle_entrega":
        $entrega_id = (int)($_GET["entrega_id"] ?? 0);
        if ($entrega_id <= 0) {
            echo json_encode(["status" => "error", "message" => "ID de entrega inválido."]);
            exit;
        }

        // Obtener los lotes asociados a esta entrega
        $sql = "SELECT de_cedis.lote_id, de_cedis.cantidad, ih.codigo_lote, pr.tipo_huevo, pr.tamano 
                FROM detalle_entrega_cedis de_cedis
                INNER JOIN inventario_huevos ih ON de_cedis.lote_id = ih.id
                INNER JOIN productos pr ON ih.producto_id = pr.id
                WHERE de_cedis.entrega_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $entrega_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $lotes = [];
        while ($row = $res->fetch_assoc()) {
            $lotes[] = $row;
        }

        echo json_encode(["status" => "success", "data" => $lotes]);
        exit;
    case "obtener_detalle_pedido":
        $pedido_id = (int)($_GET["pedido_id"] ?? 0);
        if ($pedido_id <= 0) {
            echo json_encode(["status" => "error", "message" => "ID de pedido inválido."]);
            exit;
        }

        // Obtener la cabecera del pedido (dirección, teléfono, total)
        $sqlCab = "SELECT p.id, p.total, p.metodo_pago, p.pago_estado, p.fecha_pedido, 
                          up.nombre, up.apellido, up.telefono, up.direccion 
                   FROM pedidos p
                   INNER JOIN usuario_perfil up ON p.cliente_id = up.usuario_id
                   WHERE p.id = ?";
        $stmtCab = $conn->prepare($sqlCab);
        $stmtCab->bind_param("i", $pedido_id);
        $stmtCab->execute();
        $resCab = $stmtCab->get_result();
        if ($resCab->num_rows === 0) {
            echo json_encode(["status" => "error", "message" => "El pedido no existe."]);
            exit;
        }
        $cabecera = $resCab->fetch_assoc();

        // Obtener los detalles (productos y cantidades)
        $sqlDet = "SELECT dp.cantidad, dp.precio_unitario, dp.subtotal, pr.id AS producto_id, pr.nombre AS producto_nombre, pr.tipo_huevo, pr.tamano
                   FROM detalle_pedido dp
                   INNER JOIN productos pr ON dp.producto_id = pr.id
                   WHERE dp.pedido_id = ?";
        $stmtDet = $conn->prepare($sqlDet);
        $stmtDet->bind_param("i", $pedido_id);
        $stmtDet->execute();
        $resDet = $stmtDet->get_result();
        $items = [];
        while ($row = $resDet->fetch_assoc()) {
            $prod_id = (int)$row["producto_id"];
            
            // Buscar lotes sugeridos bajo criterio FIFO (fecha_postura ASC) en el CEDIS actual o en todos si es admin
            $lotesSugeridos = [];
            if (!empty($cedis_usuario_id)) {
                $sqlLotes = "SELECT ih.codigo_lote, ih.cantidad, ih.fecha_postura, c.nombre AS cedis_nombre
                             FROM inventario_huevos ih
                             INNER JOIN detalle_entrega_cedis de_cedis ON ih.id = de_cedis.lote_id
                             INNER JOIN entregas_cedis ec ON de_cedis.entrega_id = ec.id
                             INNER JOIN cedis c ON ec.cedis_id = c.id
                             WHERE ih.producto_id = ? AND ec.cedis_id = ? AND ec.estado = 'recibido' AND ih.cantidad > 0
                             ORDER BY ih.fecha_postura ASC";
                $stmtLotes = $conn->prepare($sqlLotes);
                $stmtLotes->bind_param("ii", $prod_id, $cedis_usuario_id);
            } else {
                $sqlLotes = "SELECT ih.codigo_lote, ih.cantidad, ih.fecha_postura, c.nombre AS cedis_nombre
                             FROM inventario_huevos ih
                             INNER JOIN detalle_entrega_cedis de_cedis ON ih.id = de_cedis.lote_id
                             INNER JOIN entregas_cedis ec ON de_cedis.entrega_id = ec.id
                             INNER JOIN cedis c ON ec.cedis_id = c.id
                             WHERE ih.producto_id = ? AND ec.estado = 'recibido' AND ih.cantidad > 0
                             ORDER BY ih.fecha_postura ASC";
                $stmtLotes = $conn->prepare($sqlLotes);
                $stmtLotes->bind_param("i", $prod_id);
            }
            
            if ($stmtLotes) {
                $stmtLotes->execute();
                $resLotes = $stmtLotes->get_result();
                while ($lRow = $resLotes->fetch_assoc()) {
                    $lotesSugeridos[] = [
                        "codigo_lote" => $lRow["codigo_lote"],
                        "cantidad" => (int)$lRow["cantidad"],
                        "fecha_postura" => $lRow["fecha_postura"],
                        "cedis_nombre" => $lRow["cedis_nombre"]
                    ];
                }
                $stmtLotes->close();
            }
            
            $row["lotes_sugeridos"] = $lotesSugeridos;
            $items[] = $row;
        }

        echo json_encode(["status" => "success", "cabecera" => $cabecera, "items" => $items]);
        exit;

    case "recepcion":
        $entrega_id = (int)($input["entrega_id"] ?? 0);
        $lotes = $input["lotes"] ?? []; // Array de { lote_id, merma, no_viable }

        if ($entrega_id <= 0 || empty($lotes)) {
            echo json_encode(["status" => "error", "message" => "Datos de entrega o lotes incompletos."]);
            exit;
        }

        // Validar estado de la entrega
        $stmtCheck = $conn->prepare("SELECT estado, cedis_id FROM entregas_cedis WHERE id = ?");
        $stmtCheck->bind_param("i", $entrega_id);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        if ($resCheck->num_rows === 0) {
            echo json_encode(["status" => "error", "message" => "La entrega no existe."]);
            exit;
        }

        $entrega = $resCheck->fetch_assoc();
        if ($entrega["estado"] === "recibido") {
            echo json_encode(["status" => "error", "message" => "Esta entrega ya fue recibida anteriormente."]);
            exit;
        }
        if ($entrega["estado"] === "cancelado") {
            echo json_encode(["status" => "error", "message" => "Esta entrega fue cancelada y no se puede recibir."]);
            exit;
        }

        // Si es Operador, verificar que la entrega esté destinada a su CEDIS
        if ($rol_actual === 5 && (int)$entrega["cedis_id"] !== (int)$cedis_usuario_id) {
            echo json_encode(["status" => "error", "message" => "No tienes permisos para recibir entregas de otro CEDIS."]);
            exit;
        }

        $conn->begin_transaction();

        try {
            foreach ($lotes as $l) {
                $lote_id = (int)($l["lote_id"] ?? 0);
                $merma = (int)($l["merma"] ?? 0);
                $no_viable = (int)($l["no_viable"] ?? 0);

                if ($lote_id <= 0 || $merma < 0 || $no_viable < 0) {
                    throw new Exception("Lote ID o cantidades de merma/no-viable inválidas.");
                }

                // Obtener detalles de este lote en la entrega para validar la cantidad enviada
                $stmtDet = $conn->prepare("SELECT cantidad FROM detalle_entrega_cedis WHERE entrega_id = ? AND lote_id = ?");
                $stmtDet->bind_param("ii", $entrega_id, $lote_id);
                $stmtDet->execute();
                $resDet = $stmtDet->get_result();
                if ($resDet->num_rows === 0) {
                    throw new Exception("El lote ID $lote_id no corresponde a esta entrega.");
                }
                $detCantidad = $resDet->fetch_assoc()["cantidad"];

                if (($merma + $no_viable) > $detCantidad) {
                    throw new Exception("La suma de merma y no viables ($merma + $no_viable) excede el total enviado del lote ($detCantidad).");
                }

                // 1. Actualizar mermas en el detalle del envío para fines de trazabilidad
                $stmtUpDet = $conn->prepare("UPDATE detalle_entrega_cedis SET merma_recepcion = ?, no_viable_recepcion = ? WHERE entrega_id = ? AND lote_id = ?");
                $stmtUpDet->bind_param("iiii", $merma, $no_viable, $entrega_id, $lote_id);
                $stmtUpDet->execute();

                // 2. Modificar el inventario real: restar mermas/no viables del lote y activarlo para la venta
                $stmtInv = $conn->prepare("SELECT cantidad, codigo_lote FROM inventario_huevos WHERE id = ?");
                $stmtInv->bind_param("i", $lote_id);
                $stmtInv->execute();
                $resInv = $stmtInv->get_result();
                if ($resInv->num_rows === 0) {
                    throw new Exception("El lote ID $lote_id no existe en inventario.");
                }
                $invData = $resInv->fetch_assoc();
                $codigo_lote = $invData["codigo_lote"];

                // Restar merma/no_viable de la cantidad actual del lote en stock
                $descuento = $merma + $no_viable;
                $stmtUpInv = $conn->prepare("UPDATE inventario_huevos 
                                             SET cantidad = cantidad - ?, 
                                                 merma = merma + ?, 
                                                 no_viable = no_viable + ?, 
                                                 estado = 'disponible' 
                                             WHERE id = ?");
                $stmtUpInv->bind_param("iiii", $descuento, $merma, $no_viable, $lote_id);
                $stmtUpInv->execute();
            }

            // 3. Cambiar estado de la entrega a 'recibido'
            $stmtUpEnt = $conn->prepare("UPDATE entregas_cedis SET estado = 'recibido', fecha_recepcion = NOW() WHERE id = ?");
            $stmtUpEnt->bind_param("i", $entrega_id);
            $stmtUpEnt->execute();

            // Auditoría
            registrar_bitacora("Recepción de envío", "CEDIS", "El operador ID " . $_SESSION["usuario_id"] . " recibió el envío #ENT-" . str_pad($entrega_id, 3, "0", STR_PAD_LEFT) . " auditando mermas físicas.");

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Entrega recibida e inventario actualizado correctamente."]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => "Error al recibir entrega: " . $e->getMessage()]);
        }
        break;

    case "preparar":
        $pedido_id = (int)($input["pedido_id"] ?? 0);

        if ($pedido_id <= 0) {
            echo json_encode(["status" => "error", "message" => "ID de pedido inválido."]);
            exit;
        }

        // Obtener estado actual del pedido
        $stmtPed = $conn->prepare("SELECT estado FROM pedidos WHERE id = ?");
        $stmtPed->bind_param("i", $pedido_id);
        $stmtPed->execute();
        $resPed = $stmtPed->get_result();
        if ($resPed->num_rows === 0) {
            echo json_encode(["status" => "error", "message" => "El pedido solicitado no existe."]);
            exit;
        }

        $pedido = $resPed->fetch_assoc();
        if ($pedido["estado"] !== "pendiente") {
            echo json_encode(["status" => "error", "message" => "Solo se pueden preparar pedidos en estado 'pendiente'. (Estado actual: " . $pedido["estado"] . ")"]);
            exit;
        }

        // Actualizar estado del pedido a 'preparado'
        $stmtUpPed = $conn->prepare("UPDATE pedidos SET estado = 'preparado' WHERE id = ?");
        $stmtUpPed->bind_param("i", $pedido_id);

        if ($stmtUpPed->execute()) {
            registrar_bitacora("Preparación de pedido", "CEDIS", "El operador preparó y empaquetó físicamente el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT));
            echo json_encode(["status" => "success", "message" => "Pedido marcado como preparado y listo para distribución."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Error al actualizar el estado del pedido: " . $conn->error]);
        }
        break;

    case "merma_local":
        $lote_id = (int)($input["lote_id"] ?? 0);
        $cantidad = (int)($input["cantidad"] ?? 0);
        $motivo = trim($input["motivo"] ?? "Daño local en almacén");

        if ($lote_id <= 0 || $cantidad <= 0) {
            echo json_encode(["status" => "error", "message" => "Lote ID y cantidad de merma válidos son requeridos."]);
            exit;
        }

        $conn->begin_transaction();

        try {
            // Validar stock disponible en el lote
            $stmtLote = $conn->prepare("SELECT cantidad, codigo_lote FROM inventario_huevos WHERE id = ?");
            $stmtLote->bind_param("i", $lote_id);
            $stmtLote->execute();
            $resLote = $stmtLote->get_result();
            if ($resLote->num_rows === 0) {
                throw new Exception("El lote especificado no existe.");
            }

            $lote = $resLote->fetch_assoc();
            if ($lote["cantidad"] < $cantidad) {
                throw new Exception("No puedes reportar una merma mayor al stock actual del lote (" . $lote["cantidad"] . " unidades).");
            }

            // Descontar del lote y registrar merma
            $stmtUpLote = $conn->prepare("UPDATE inventario_huevos SET cantidad = cantidad - ?, merma = merma + ? WHERE id = ?");
            $stmtUpLote->bind_param("iii", $cantidad, $cantidad, $lote_id);
            $stmtUpLote->execute();

            registrar_bitacora("Merma Almacén CEDIS", "CEDIS", "Se reportó merma local de $cantidad huevos en el lote '" . $lote["codigo_lote"] . "'. Motivo: $motivo");

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Merma local registrada con éxito."]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Acción no reconocida."]);
        break;
}
?>
