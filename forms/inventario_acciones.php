<?php
session_start();
require "conexion.php";

if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 1) {
    header("Content-Type: application/json");
    echo json_encode(["status" => "error", "message" => "Acceso no autorizado"]);
    exit;
}

$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($accion === "crear") {
        $proveedor_id = (int)($_POST["proveedor_id"] ?? 0);
        $producto_id = (int)($_POST["producto_id"] ?? 0);
        $codigo_lote = trim($_POST["codigo_lote"] ?? "");
        $cantidad = (int)($_POST["cantidad"] ?? 0);
        $fecha_postura = trim($_POST["fecha_postura"] ?? null);
        $fecha_caducidad = trim($_POST["fecha_caducidad"] ?? null);
        $estado = trim($_POST["estado"] ?? "disponible");

        if ($proveedor_id <= 0 || $producto_id <= 0 || empty($codigo_lote) || $cantidad < 0) {
            $_SESSION["mensaje_error"] = "Por favor completa todos los campos con valores válidos.";
            header("Location: ../inventario_admin.php");
            exit;
        }

        $sql = "INSERT INTO inventario_huevos (proveedor_id, producto_id, codigo_lote, cantidad, fecha_postura, fecha_caducidad, estado) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisssss", $proveedor_id, $producto_id, $codigo_lote, $cantidad, $fecha_postura, $fecha_caducidad, $estado);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Lote agregado al inventario correctamente.";
            registrar_bitacora("Lote agregado", "Inventario", "Se agregó el lote #$codigo_lote con $cantidad huevos.");
        } else {
            $_SESSION["mensaje_error"] = "Error al agregar lote: " . $conn->error;
        }
        header("Location: ../inventario_admin.php");
        exit;

    } elseif ($accion === "editar") {
        $id = (int)($_POST["id"] ?? 0);
        $proveedor_id = (int)($_POST["proveedor_id"] ?? 0);
        $producto_id = (int)($_POST["producto_id"] ?? 0);
        $codigo_lote = trim($_POST["codigo_lote"] ?? "");
        $cantidad = (int)($_POST["cantidad"] ?? 0);
        $fecha_postura = trim($_POST["fecha_postura"] ?? null);
        $fecha_caducidad = trim($_POST["fecha_caducidad"] ?? null);
        $estado = trim($_POST["estado"] ?? "disponible");

        if ($id <= 0 || $proveedor_id <= 0 || $producto_id <= 0 || empty($codigo_lote) || $cantidad < 0) {
            $_SESSION["mensaje_error"] = "Datos inválidos para actualizar el lote.";
            header("Location: ../inventario_admin.php");
            exit;
        }

        $sql = "UPDATE inventario_huevos SET proveedor_id = ?, producto_id = ?, codigo_lote = ?, cantidad = ?, fecha_postura = ?, fecha_caducidad = ?, estado = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisssssi", $proveedor_id, $producto_id, $codigo_lote, $cantidad, $fecha_postura, $fecha_caducidad, $estado, $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Lote de inventario actualizado correctamente.";
            registrar_bitacora("Lote editado", "Inventario", "Se actualizó el lote #$codigo_lote. Nueva cantidad: $cantidad unidades.");
        } else {
            $_SESSION["mensaje_error"] = "Error al actualizar lote: " . $conn->error;
        }
        header("Location: ../inventario_admin.php");
        exit;

    } elseif ($accion === "eliminar") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "ID de lote inválido.";
            header("Location: ../inventario_admin.php");
            exit;
        }

        // Obtener código de lote para la bitácora antes de borrarlo
        $codigo_lote = "";
        $getLote = $conn->query("SELECT codigo_lote FROM inventario_huevos WHERE id = $id");
        if ($getLote && $getLote->num_rows > 0) {
            $codigo_lote = $getLote->fetch_assoc()["codigo_lote"];
        }

        $sql = "DELETE FROM inventario_huevos WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Lote eliminado correctamente.";
            registrar_bitacora("Lote eliminado", "Inventario", "Se eliminó de forma permanente el lote #$codigo_lote (ID: $id).");
        } else {
            $_SESSION["mensaje_error"] = "Error al eliminar lote: " . $conn->error;
        }
        header("Location: ../inventario_admin.php");
        exit;

    } elseif ($accion === "bloquear") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "ID de lote inválido para bloqueo.";
            header("Location: ../inventario_admin.php");
            exit;
        }

        $codigo_lote = "";
        $getLote = $conn->query("SELECT codigo_lote FROM inventario_huevos WHERE id = $id");
        if ($getLote && $getLote->num_rows > 0) {
            $codigo_lote = $getLote->fetch_assoc()["codigo_lote"];
        }

        $sql = "UPDATE inventario_huevos SET estado = 'caducado' WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "El lote #" . $codigo_lote . " ha sido bloqueado y marcado como caducado.";
            registrar_bitacora("Lote caducado", "Inventario", "Se inhabilitó/bloqueó preventivamente el lote caducado #$codigo_lote.");
        } else {
            $_SESSION["mensaje_error"] = "Error al bloquear lote: " . $conn->error;
        }
        header("Location: ../inventario_admin.php");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && $accion === "obtener") {
    header("Content-Type: application/json");
    $id = (int)$_GET["id"];
    if ($id <= 0) {
        echo json_encode(["status" => "error", "message" => "ID inválido"]);
        exit;
    }

    $sql = "SELECT * FROM inventario_huevos WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $lote = $resultado->fetch_assoc();
        echo json_encode(["status" => "success", "data" => $lote]);
    } else {
        echo json_encode(["status" => "error", "message" => "Lote no encontrado"]);
    }
    exit;
}

header("Location: ../inventario_admin.php");
exit;
?>
