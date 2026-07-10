<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - CONTROLADOR DE ACCIONES DE ADMINISTRACIÓN DE CEDIS
 * --------------------------------------------------------------------------------
 * Permite a los administradores (Rol 1) crear, editar, deactivar y eliminar
 * los Centros de Distribución (CEDIS) del sistema.
 */

session_start();
require "conexion.php";

// Validar sesión administrativa
if (!isset($_SESSION["admin_session"])) {
    if (isset($_SESSION["usuario_id"]) && (int)$_SESSION["rol_id"] === 1) {
        $_SESSION["admin_session"] = [
            "usuario_id" => $_SESSION["usuario_id"],
            "usuario" => $_SESSION["usuario"] ?? "admin",
            "rol_id" => $_SESSION["rol_id"],
            "nombre" => $_SESSION["nombre"] ?? "Admin",
            "apellido" => $_SESSION["apellido"] ?? "",
            "email" => $_SESSION["email"] ?? ""
        ];
    } else {
        header("Content-Type: application/json");
        echo json_encode(["status" => "error", "message" => "Acceso no autorizado"]);
        exit;
    }
}

$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($accion === "crear") {
        $nombre = trim($_POST["nombre"] ?? "");
        $direccion = trim($_POST["direccion"] ?? "");
        $activo = (int)($_POST["activo"] ?? 1);

        if (empty($nombre) || empty($direccion)) {
            $_SESSION["mensaje_error"] = "Por favor completa todos los campos con valores válidos.";
            header("Location: ../cedis_admin.php");
            exit;
        }

        $sql = "INSERT INTO cedis (nombre, direccion, activo) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $nombre, $direccion, $activo);

        if ($stmt->execute()) {
            $cedis_id = $conn->insert_id;
            $_SESSION["mensaje_exito"] = "Centro de Distribución (CEDIS) creado correctamente.";
            registrar_bitacora("CEDIS creado", "Logística", "Se agregó el CEDIS '$nombre' en '$direccion' (ID: $cedis_id).");
        } else {
            $_SESSION["mensaje_error"] = "Error al registrar el CEDIS: " . $conn->error;
        }
        header("Location: ../cedis_admin.php");
        exit;

    } elseif ($accion === "editar") {
        $id = (int)($_POST["id"] ?? 0);
        $nombre = trim($_POST["nombre"] ?? "");
        $direccion = trim($_POST["direccion"] ?? "");
        $activo = (int)($_POST["activo"] ?? 1);

        if ($id <= 0 || empty($nombre) || empty($direccion)) {
            $_SESSION["mensaje_error"] = "Datos inválidos para actualizar el CEDIS.";
            header("Location: ../cedis_admin.php");
            exit;
        }

        $sql = "UPDATE cedis SET nombre = ?, direccion = ?, activo = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssii", $nombre, $direccion, $activo, $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Centro de Distribución (CEDIS) actualizado correctamente.";
            registrar_bitacora("CEDIS editado", "Logística", "Se actualizaron los datos del CEDIS '$nombre' (ID: $id). Activo: $activo.");
        } else {
            $_SESSION["mensaje_error"] = "Error al actualizar el CEDIS: " . $conn->error;
        }
        header("Location: ../cedis_admin.php");
        exit;

    } elseif ($accion === "eliminar") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "ID de CEDIS inválido.";
            header("Location: ../cedis_admin.php");
            exit;
        }

        // Obtener el nombre del CEDIS para la bitácora
        $getN = $conn->query("SELECT nombre FROM cedis WHERE id = $id");
        $nombreCedis = $getN && $getN->num_rows > 0 ? $getN->fetch_assoc()["nombre"] : "ID $id";

        $sql = "DELETE FROM cedis WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        try {
            if ($stmt->execute()) {
                $_SESSION["mensaje_exito"] = "Centro de Distribución (CEDIS) eliminado correctamente.";
                registrar_bitacora("CEDIS eliminado", "Logística", "Se eliminó el CEDIS '$nombreCedis' (ID: $id).");
            } else {
                $_SESSION["mensaje_error"] = "Error al intentar eliminar el CEDIS.";
            }
        } catch (mysqli_sql_exception $e) {
            // Si hay registros relacionados, lo desactivamos en su lugar
            if ($e->getCode() === 1451) {
                $stmtDes = $conn->prepare("UPDATE cedis SET activo = 0 WHERE id = ?");
                $stmtDes->bind_param("i", $id);
                if ($stmtDes->execute()) {
                    $_SESSION["mensaje_exito"] = "El CEDIS no se puede eliminar por tener envíos/operadores asociados. Se ha desactivado automáticamente.";
                    registrar_bitacora("CEDIS desactivado por integridad", "Logística", "Se desactivó el CEDIS '$nombreCedis' (ID: $id) debido a historial logístico asociado.");
                } else {
                    $_SESSION["mensaje_error"] = "Error al desactivar el CEDIS.";
                }
                $stmtDes->close();
            } else {
                $_SESSION["mensaje_error"] = "Error crítico al eliminar: " . $e->getMessage();
            }
        }
        header("Location: ../cedis_admin.php");
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

    $sql = "SELECT * FROM cedis WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $cedis = $resultado->fetch_assoc();
        echo json_encode(["status" => "success", "data" => $cedis]);
    } else {
        echo json_encode(["status" => "error", "message" => "CEDIS no encontrado"]);
    }
    exit;
}

header("Location: ../cedis_admin.php");
exit;
?>
