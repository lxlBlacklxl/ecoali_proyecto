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
        $cliente_id = (int)($_POST["cliente_id"] ?? 0);
        $repartidor_id = isset($_POST["repartidor_id"]) && $_POST["repartidor_id"] !== "" ? (int)$_POST["repartidor_id"] : null;
        $total = (float)($_POST["total"] ?? 0.0);
        $estado = trim($_POST["estado"] ?? "pendiente");

        if ($cliente_id <= 0 || $total < 0) {
            $_SESSION["mensaje_error"] = "Datos inválidos para crear el pedido.";
            header("Location: ../logistica_admin.php");
            exit;
        }

        $sql = "INSERT INTO pedidos (cliente_id, repartidor_id, total, estado) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iids", $cliente_id, $repartidor_id, $total, $estado);

        if ($stmt->execute()) {
            $pedido_id = $conn->insert_id;
            $_SESSION["mensaje_exito"] = "Pedido registrado y asignado correctamente.";
            registrar_bitacora("Pedido creado", "Logística", "Se registró el pedido #$pedido_id por un total de $$total con estado: $estado.");
        } else {
            $_SESSION["mensaje_error"] = "Error al crear el pedido: " . $conn->error;
        }
        header("Location: ../logistica_admin.php");
        exit;

    } elseif ($accion === "editar") {
        $id = (int)($_POST["id"] ?? 0);
        $cliente_id = (int)($_POST["cliente_id"] ?? 0);
        $repartidor_id = isset($_POST["repartidor_id"]) && $_POST["repartidor_id"] !== "" ? (int)$_POST["repartidor_id"] : null;
        $total = (float)($_POST["total"] ?? 0.0);
        $estado = trim($_POST["estado"] ?? "pendiente");

        if ($id <= 0 || $cliente_id <= 0 || $total < 0) {
            $_SESSION["mensaje_error"] = "Datos inválidos para actualizar el pedido.";
            header("Location: ../logistica_admin.php");
            exit;
        }

        $sql = "UPDATE pedidos SET cliente_id = ?, repartidor_id = ?, total = ?, estado = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iidsi", $cliente_id, $repartidor_id, $total, $estado, $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Pedido actualizado correctamente.";
            registrar_bitacora("Pedido editado", "Logística", "Se actualizó el estado del pedido #$id a '$estado' y repartidor ID: " . ($repartidor_id ?? "No asignado") . ".");
        } else {
            $_SESSION["mensaje_error"] = "Error al actualizar el pedido: " . $conn->error;
        }
        header("Location: ../logistica_admin.php");
        exit;

    } elseif ($accion === "eliminar") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "ID de pedido inválido.";
            header("Location: ../logistica_admin.php");
            exit;
        }

        $sql = "DELETE FROM pedidos WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Pedido eliminado correctamente.";
            registrar_bitacora("Pedido eliminado", "Logística", "Se eliminó de forma permanente el pedido #$id de los registros.");
        } else {
            $_SESSION["mensaje_error"] = "Error al eliminar el pedido: " . $conn->error;
        }
        header("Location: ../logistica_admin.php");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && $accion === "obtener") {
    header("Content-Type: application/json");
    $id = (int)($_GET["id"] ?? 0);
    if ($id <= 0) {
        echo json_encode(["status" => "error", "message" => "ID inválido"]);
        exit;
    }

    $sql = "SELECT * FROM pedidos WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $pedido = $resultado->fetch_assoc();
        echo json_encode(["status" => "success", "data" => $pedido]);
    } else {
        echo json_encode(["status" => "error", "message" => "Pedido no encontrado"]);
    }
    exit;
}

header("Location: ../logistica_admin.php");
exit;
?>
