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
        $nombre_empresa = trim($_POST["nombre_empresa"] ?? "");
        $contacto = trim($_POST["contacto"] ?? "");
        $telefono = trim($_POST["telefono"] ?? "");
        $ubicacion = trim($_POST["ubicacion"] ?? "");
        $estado = trim($_POST["estado"] ?? "pendiente");

        if (empty($nombre_empresa)) {
            $_SESSION["mensaje_error"] = "El nombre de la empresa es obligatorio.";
            header("Location: ../proveedores_admin.php");
            exit;
        }

        $sql = "INSERT INTO proveedores (nombre_empresa, contacto, telefono, ubicacion, estado) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssss", $nombre_empresa, $contacto, $telefono, $ubicacion, $estado);

        if ($stmt->execute()) {
            $nuevo_id = $conn->insert_id;
            $_SESSION["mensaje_exito"] = "Proveedor agregado correctamente.";
            registrar_bitacora("Proveedor creado", "Proveedores", "Se registró el proveedor '$nombre_empresa' (ID: $nuevo_id) con estado: " . strtoupper($estado) . ".");
        } else {
            $_SESSION["mensaje_error"] = "Error al agregar proveedor: " . $conn->error;
        }
        header("Location: ../proveedores_admin.php");
        exit;

    } elseif ($accion === "editar") {
        $id = (int)($_POST["id"] ?? 0);
        $nombre_empresa = trim($_POST["nombre_empresa"] ?? "");
        $contacto = trim($_POST["contacto"] ?? "");
        $telefono = trim($_POST["telefono"] ?? "");
        $ubicacion = trim($_POST["ubicacion"] ?? "");
        $estado = trim($_POST["estado"] ?? "pendiente");

        if ($id <= 0 || empty($nombre_empresa)) {
            $_SESSION["mensaje_error"] = "Datos inválidos para actualizar.";
            header("Location: ../proveedores_admin.php");
            exit;
        }

        $sql = "UPDATE proveedores SET nombre_empresa = ?, contacto = ?, telefono = ?, ubicacion = ?, estado = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssi", $nombre_empresa, $contacto, $telefono, $ubicacion, $estado, $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Proveedor actualizado correctamente.";
            registrar_bitacora("Proveedor editado", "Proveedores", "Se actualizaron los datos del proveedor '$nombre_empresa' (ID: $id).");
        } else {
            $_SESSION["mensaje_error"] = "Error al actualizar proveedor: " . $conn->error;
        }
        header("Location: ../proveedores_admin.php");
        exit;

    } elseif ($accion === "eliminar") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "ID de proveedor inválido.";
            header("Location: ../proveedores_admin.php");
            exit;
        }

        // Obtener el nombre del proveedor antes de eliminar
        $getN = $conn->query("SELECT nombre_empresa FROM proveedores WHERE id = $id");
        $nombreProv = $getN && $getN->num_rows > 0 ? $getN->fetch_assoc()["nombre_empresa"] : "ID $id";

        $sql = "DELETE FROM proveedores WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Proveedor eliminado correctamente.";
            registrar_bitacora("Proveedor eliminado", "Proveedores", "Se eliminó permanentemente el proveedor '$nombreProv' (ID: $id).");
        } else {
            $_SESSION["mensaje_error"] = "Error al eliminar proveedor: " . $conn->error;
        }
        header("Location: ../proveedores_admin.php");
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

    $sql = "SELECT * FROM proveedores WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $proveedor = $resultado->fetch_assoc();
        echo json_encode(["status" => "success", "data" => $proveedor]);
    } else {
        echo json_encode(["status" => "error", "message" => "Proveedor no encontrado"]);
    }
    exit;
}

header("Location: ../proveedores_admin.php");
exit;
?>
