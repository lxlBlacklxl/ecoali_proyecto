<?php
session_start();
require "conexion.php";

header("Content-Type: application/json");

if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 2) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos."]);
    exit;
}

$cliente_id = $_SESSION["usuario_id"];
$direccion = trim($input["direccion"] ?? "");
$telefono = trim($input["telefono"] ?? "");
$referido_por = trim($input["referido_por"] ?? "");
$carrito = $input["carrito"] ?? [];

if (empty($direccion) || empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "Dirección y teléfono de entrega son obligatorios."]);
    exit;
}

if (empty($carrito)) {
    echo json_encode(["status" => "error", "message" => "El carrito de compras está vacío."]);
    exit;
}

// Iniciar transacción para garantizar la integridad
$conn->begin_transaction();

try {
    // 1. Validar productos y calcular total real desde la BD (evitando alteraciones en JS)
    $total_pedido = 0.0;
    $items_procesados = [];

    foreach ($carrito as $item) {
        $prod_id = (int)($item["producto_id"] ?? 0);
        $cantidad = (int)($item["cantidad"] ?? 0);

        if ($prod_id <= 0 || $cantidad <= 0) {
            throw new Exception("Cantidad o ID de producto inválido en el carrito.");
        }

        // Consultar precio y nombre en base de datos
        $stmtProd = $conn->prepare("SELECT nombre, precio, activo FROM productos WHERE id = ?");
        $stmtProd->bind_param("i", $prod_id);
        $stmtProd->execute();
        $resProd = $stmtProd->get_result();

        if ($resProd->num_rows === 0) {
            throw new Exception("El producto seleccionado no existe.");
        }

        $prodData = $resProd->fetch_assoc();
        if ((int)$prodData["activo"] !== 1) {
            throw new Exception("El producto '" . $prodData["nombre"] . "' ya no se encuentra activo.");
        }

        $precio_real = (float)$prodData["precio"];
        $subtotal = $precio_real * $cantidad;
        $total_pedido += $subtotal;

        $items_procesados[] = [
            "id" => $prod_id,
            "nombre" => $prodData["nombre"],
            "precio" => $precio_real,
            "cantidad" => $cantidad,
            "subtotal" => $subtotal
        ];
    }

    // 2. Insertar cabecera del pedido
    $sqlInsertPedido = "INSERT INTO pedidos (cliente_id, repartidor_id, total, estado, fecha_pedido) VALUES (?, NULL, ?, 'pendiente', NOW())";
    $stmtPedido = $conn->prepare($sqlInsertPedido);
    $stmtPedido->bind_param("id", $cliente_id, $total_pedido);
    if (!$stmtPedido->execute()) {
        throw new Exception("Error al registrar el pedido: " . $conn->error);
    }
    
    $pedido_id = $conn->insert_id;

    // 3. Insertar detalle del pedido
    $sqlInsertDetalle = "INSERT INTO detalle_pedido (pedido_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)";
    $stmtDetalle = $conn->prepare($sqlInsertDetalle);

    foreach ($items_procesados as $item) {
        $stmtDetalle->bind_param("iiidd", $pedido_id, $item["id"], $item["cantidad"], $item["precio"], $item["subtotal"]);
        if (!$stmtDetalle->execute()) {
            throw new Exception("Error al registrar el detalle de '" . $item["nombre"] . "': " . $conn->error);
        }
    }

    // 4. Lógica de Regalías y Referencias (Si se proporciona un usuario de referido válido)
    if (!empty($referido_por)) {
        // Buscar el usuario del referido en la BD (debe ser diferente al cliente actual)
        $stmtRef = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? AND id != ? AND activo = 1");
        $stmtRef->bind_param("si", $referido_por, $cliente_id);
        $stmtRef->execute();
        $resRef = $stmtRef->get_result();

        if ($resRef->num_rows > 0) {
            $beneficiado_id = $resRef->fetch_assoc()["id"];
            // Calcular una regalía del 10% del total de la compra
            $comision_monto = round($total_pedido * 0.10, 2);

            if ($comision_monto > 0) {
                $sqlInsertRegalia = "INSERT INTO regalias (usuario_beneficiado_id, usuario_referido_id, pedido_id, nivel, monto, estado, fecha) VALUES (?, ?, ?, 1, ?, 'pendiente', NOW())";
                $stmtRegalia = $conn->prepare($sqlInsertRegalia);
                $stmtRegalia->bind_param("iiid", $beneficiado_id, $cliente_id, $pedido_id, $comision_monto);
                if ($stmtRegalia->execute()) {
                    // Loggeado en bitácora
                    registrar_bitacora("Regalía generada", "Regalías", "Se generó una comisión pendiente de $$comision_monto para el usuario '$referido_por' por la compra del cliente ID #$cliente_id.");
                }
            }
        }
    }

    // 5. Registrar acción en la Bitácora
    $nCompleto = ($_SESSION["nombre"] ?? "Cliente") . " " . ($_SESSION["apellido"] ?? "");
    registrar_bitacora("Pedido creado", "Logística", "El cliente '$nCompleto' creó el pedido #PED-" . str_pad($pedido_id, 3, "0", STR_PAD_LEFT) . " por un total de $$total_pedido.");

    // Guardar también los detalles de dirección y teléfono en el perfil de usuario si estaban vacíos (conveniencia)
    $stmtProfileUpdate = $conn->prepare("UPDATE usuario_perfil SET direccion = IF(direccion = '' OR direccion IS NULL, ?, direccion), telefono = IF(telefono = '' OR telefono IS NULL, ?, telefono) WHERE usuario_id = ?");
    $stmtProfileUpdate->bind_param("ssi", $direccion, $telefono, $cliente_id);
    $stmtProfileUpdate->execute();

    // Confirmar transacción
    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "¡Pedido realizado con éxito!",
        "pedido_id" => $pedido_id,
        "total" => $total_pedido
    ]);

} catch (Exception $e) {
    // Revertir ante cualquier error
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
