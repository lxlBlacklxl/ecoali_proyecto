<?php
session_start();
require "conexion.php";

header("Content-Type: application/json");

if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 3) {
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "Datos de solicitud inválidos."]);
    exit;
}

$usuario_id = $_SESSION["usuario_id"];
$producto_id = (int)($input["producto_id"] ?? 0);
$cantidad = (int)($input["cantidad"] ?? 0);
$fecha_produccion = trim($input["fecha_produccion"] ?? "");
$observaciones = trim($input["observaciones"] ?? "");
$granja_id = (int)($input["granja_id"] ?? 0);

if ($producto_id <= 0 || $cantidad <= 0 || empty($fecha_produccion) || $granja_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Todos los campos obligatorios deben completarse correctamente, incluyendo la granja de origen."]);
    exit;
}

// Iniciar transacción
$conn->begin_transaction();

try {
    // 1. Obtener el proveedor_id correspondiente a este usuario
    $stmtProv = $conn->prepare("SELECT id, nombre_empresa FROM proveedores WHERE usuario_id = ?");
    $stmtProv->bind_param("i", $usuario_id);
    $stmtProv->execute();
    $resProv = $stmtProv->get_result();

    if ($resProv->num_rows === 0) {
        throw new Exception("Tu usuario no está vinculado a ninguna granja o proveedor autorizado en el sistema.");
    }

    $provData = $resProv->fetch_assoc();
    $proveedor_id = (int)$provData["id"];
    $nombre_empresa = $provData["nombre_empresa"];

    // 1.2 Validar que la granja existe y pertenece al proveedor
    $stmtGranja = $conn->prepare("SELECT nombre, stock_cartones FROM granjas WHERE id = ? AND proveedor_id = ?");
    $stmtGranja->bind_param("ii", $granja_id, $proveedor_id);
    $stmtGranja->execute();
    $resGranja = $stmtGranja->get_result();
    if ($resGranja->num_rows === 0) {
        throw new Exception("La granja de origen seleccionada no es válida o no pertenece a tu cuenta.");
    }
    $granja_data = $resGranja->fetch_assoc();
    $granja_nombre = $granja_data["nombre"];
    $stock_cartones = (int)$granja_data["stock_cartones"];

    // Calcular cartones necesarios (1 cartón = 30 huevos)
    $cartones_necesarios = (int)ceil($cantidad / 30);
    if ($stock_cartones < $cartones_necesarios) {
        throw new Exception("Insumos insuficientes: La granja '$granja_nombre' no cuenta con suficientes cartones de empaque ($stock_cartones disponibles, se requieren $cartones_necesarios para empacar $cantidad huevos). Por favor, reabastece los insumos en tu panel antes de registrar la postura.");
    }

    // Descontar cartones de empaque de la granja
    $nuevo_stock_cartones = $stock_cartones - $cartones_necesarios;
    $stmtDeduct = $conn->prepare("UPDATE granjas SET stock_cartones = ? WHERE id = ?");
    $stmtDeduct->bind_param("ii", $nuevo_stock_cartones, $granja_id);
    if (!$stmtDeduct->execute()) {
        throw new Exception("Error al actualizar el inventario de insumos de la granja.");
    }
    $stmtDeduct->close();

    // 2. Validar que el producto existe
    $stmtProd = $conn->prepare("SELECT nombre FROM productos WHERE id = ? AND activo = 1");
    $stmtProd->bind_param("i", $producto_id);
    $stmtProd->execute();
    $resProd = $stmtProd->get_result();

    if ($resProd->num_rows === 0) {
        throw new Exception("El tipo de producto seleccionado no es válido o está inactivo.");
    }

    $prod_nombre = $resProd->fetch_assoc()["nombre"];

    // 3. Registrar en la tabla `produccion`
    $sqlInsertProd = "INSERT INTO produccion (proveedor_id, producto_id, cantidad, fecha_produccion, observaciones, granja_id) VALUES (?, ?, ?, ?, ?, ?)";
    $stmtInsertProd = $conn->prepare($sqlInsertProd);
    $stmtInsertProd->bind_param("iiissi", $proveedor_id, $producto_id, $cantidad, $fecha_produccion, $observaciones, $granja_id);
    
    if (!$stmtInsertProd->execute()) {
        throw new Exception("Error al registrar la producción: " . $conn->error);
    }

    // 4. Generar código de lote único (ej: LOTE-P1-U3-A42F)
    $lote_id_hash = strtoupper(substr(md5(time() . rand(1, 100)), 0, 4));
    $codigo_lote = "LOTE-P" . $producto_id . "-U" . $usuario_id . "-" . $lote_id_hash;

    // Calcular fecha de caducidad (30 días después de la postura/producción)
    $fecha_caducidad = date('Y-m-d', strtotime('+30 days', strtotime($fecha_produccion)));

    // 5. Crear lote en `inventario_huevos`
    $sqlInsertInv = "INSERT INTO inventario_huevos (proveedor_id, producto_id, codigo_lote, cantidad, fecha_postura, fecha_caducidad, estado, granja_id)
                     VALUES (?, ?, ?, ?, ?, ?, 'disponible', ?)";
    $stmtInsertInv = $conn->prepare($sqlInsertInv);
    $stmtInsertInv->bind_param("iisissi", $proveedor_id, $producto_id, $codigo_lote, $cantidad, $fecha_produccion, $fecha_caducidad, $granja_id);

    if (!$stmtInsertInv->execute()) {
        throw new Exception("Error al ingresar el lote al inventario: " . $conn->error);
    }

    // 6. Registrar en bitácora
    $nCompleto = ($_SESSION["nombre"] ?? "Proveedor") . " (" . $nombre_empresa . ")";
    registrar_bitacora(
        "Producción registrada", 
        "Inventario", 
        "El proveedor '$nCompleto' registró un lote de $cantidad huevos de '$prod_nombre' originarios de la granja '$granja_nombre' bajo el código de lote '$codigo_lote'."
    );

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "¡Producción y lote registrados correctamente en el inventario!",
        "codigo_lote" => $codigo_lote,
        "cantidad" => $cantidad
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
