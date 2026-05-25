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
        $nombre = trim($_POST["nombre"] ?? "");
        $tipo_huevo = trim($_POST["tipo_huevo"] ?? "");
        $tamano = trim($_POST["tamano"] ?? "");
        $precio = (float)($_POST["precio"] ?? 0.0);
        $activo = (int)($_POST["activo"] ?? 1);

        if (empty($nombre) || empty($tipo_huevo) || empty($tamano) || $precio < 0) {
            $_SESSION["mensaje_error"] = "Por favor completa todos los campos con valores válidos.";
            header("Location: ../productos_admin.php");
            exit;
        }

        $sql = "INSERT INTO productos (nombre, tipo_huevo, tamano, precio, activo) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdi", $nombre, $tipo_huevo, $tamano, $precio, $activo);

        if ($stmt->execute()) {
            $producto_id = $conn->insert_id;
            $_SESSION["mensaje_exito"] = "Producto registrado correctamente en el catálogo.";
            registrar_bitacora("Producto creado", "Productos", "Se agregó el producto '$nombre' ($tipo_huevo, $tamano) a $$precio (ID: $producto_id).");
        } else {
            $_SESSION["mensaje_error"] = "Error al registrar el producto: " . $conn->error;
        }
        header("Location: ../productos_admin.php");
        exit;

    } elseif ($accion === "editar") {
        $id = (int)($_POST["id"] ?? 0);
        $nombre = trim($_POST["nombre"] ?? "");
        $tipo_huevo = trim($_POST["tipo_huevo"] ?? "");
        $tamano = trim($_POST["tamano"] ?? "");
        $precio = (float)($_POST["precio"] ?? 0.0);
        $activo = (int)($_POST["activo"] ?? 1);

        if ($id <= 0 || empty($nombre) || empty($tipo_huevo) || empty($tamano) || $precio < 0) {
            $_SESSION["mensaje_error"] = "Datos inválidos para actualizar el producto.";
            header("Location: ../productos_admin.php");
            exit;
        }

        $sql = "UPDATE productos SET nombre = ?, tipo_huevo = ?, tamano = ?, precio = ?, activo = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdii", $nombre, $tipo_huevo, $tamano, $precio, $activo, $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Producto actualizado correctamente.";
            registrar_bitacora("Producto editado", "Productos", "Se actualizaron los datos del producto '$nombre' (ID: $id). Nuevo precio: $$precio.");
        } else {
            $_SESSION["mensaje_error"] = "Error al actualizar el producto: " . $conn->error;
        }
        header("Location: ../productos_admin.php");
        exit;

    } elseif ($accion === "eliminar") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "ID de producto inválido.";
            header("Location: ../productos_admin.php");
            exit;
        }

        // Obtener el nombre del producto para la bitácora
        $getN = $conn->query("SELECT nombre FROM productos WHERE id = $id");
        $nombreProd = $getN && $getN->num_rows > 0 ? $getN->fetch_assoc()["nombre"] : "ID $id";

        $sql = "DELETE FROM productos WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Producto eliminado correctamente.";
            registrar_bitacora("Producto eliminado", "Productos", "Se eliminó permanentemente del catálogo el producto '$nombreProd' (ID: $id).");
        } else {
            $_SESSION["mensaje_error"] = "Error al eliminar producto (puede tener lotes vinculados en inventario): " . $conn->error;
        }
        header("Location: ../productos_admin.php");
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

    $sql = "SELECT * FROM productos WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $producto = $resultado->fetch_assoc();
        echo json_encode(["status" => "success", "data" => $producto]);
    } else {
        echo json_encode(["status" => "error", "message" => "Producto no encontrado"]);
    }
    exit;
}

header("Location: ../productos_admin.php");
exit;
?>
