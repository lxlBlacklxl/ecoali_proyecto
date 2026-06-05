<?php
session_start();
require "conexion.php";

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
        $usuario = trim($_POST["usuario"] ?? "");
        $password = trim($_POST["password"] ?? "");
        $rol_id = (int)($_POST["rol_id"] ?? 2);
        $activo = (int)($_POST["activo"] ?? 1);
        
        $nombre = trim($_POST["nombre"] ?? "");
        $apellido = trim($_POST["apellido"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $telefono = trim($_POST["telefono"] ?? "");
        $direccion = trim($_POST["direccion"] ?? "");

        if (empty($usuario) || empty($password) || empty($nombre) || empty($email)) {
            $_SESSION["mensaje_error"] = "Por favor, completa los campos requeridos (Usuario, Contraseña, Nombre y Correo).";
            header("Location: ../usuarios_admin.php");
            exit;
        }

        // Verificar si el nombre de usuario ya está registrado
        $checkUser = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $checkUser->bind_param("s", $usuario);
        $checkUser->execute();
        if ($checkUser->get_result()->num_rows > 0) {
            $_SESSION["mensaje_error"] = "El nombre de usuario ya está en uso.";
            header("Location: ../usuarios_admin.php");
            exit;
        }

        // Hashing seguro de contraseña
        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        // Insertar en la tabla usuarios
        $sqlUser = "INSERT INTO usuarios (usuario, password_hash, rol_id, activo) VALUES (?, ?, ?, ?)";
        $stmtUser = $conn->prepare($sqlUser);
        $stmtUser->bind_param("ssii", $usuario, $password_hash, $rol_id, $activo);

        if ($stmtUser->execute()) {
            $nuevo_usuario_id = $conn->insert_id;

            // Insertar en la tabla usuario_perfil
            $sqlPerfil = "INSERT INTO usuario_perfil (usuario_id, nombre, apellido, direccion, telefono, email) VALUES (?, ?, ?, ?, ?, ?)";
            $stmtPerfil = $conn->prepare($sqlPerfil);
            $stmtPerfil->bind_param("isssss", $nuevo_usuario_id, $nombre, $apellido, $direccion, $telefono, $email);
            $stmtPerfil->execute();

            $_SESSION["mensaje_exito"] = "Usuario creado correctamente.";
            registrar_bitacora("Usuario creado", "Usuarios", "Se registró el usuario '$usuario' (ID: $nuevo_usuario_id) con rol ID: $rol_id.");
        } else {
            $_SESSION["mensaje_error"] = "Error al registrar el usuario: " . $conn->error;
        }
        header("Location: ../usuarios_admin.php");
        exit;

    } elseif ($accion === "editar") {
        $id = (int)($_POST["id"] ?? 0);
        $usuario = trim($_POST["usuario"] ?? "");
        $password = trim($_POST["password"] ?? "");
        $rol_id = (int)($_POST["rol_id"] ?? 2);
        $activo = (int)($_POST["activo"] ?? 1);
        
        $nombre = trim($_POST["nombre"] ?? "");
        $apellido = trim($_POST["apellido"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $telefono = trim($_POST["telefono"] ?? "");
        $direccion = trim($_POST["direccion"] ?? "");

        if ($id <= 0 || empty($usuario) || empty($nombre) || empty($email)) {
            $_SESSION["mensaje_error"] = "Datos inválidos para actualizar el usuario.";
            header("Location: ../usuarios_admin.php");
            exit;
        }

        // 1. Actualizar tabla usuarios
        if (!empty($password)) {
            // Si el admin digitó una nueva contraseña
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $sqlUser = "UPDATE usuarios SET usuario = ?, password_hash = ?, rol_id = ?, activo = ? WHERE id = ?";
            $stmtUser = $conn->prepare($sqlUser);
            $stmtUser->bind_param("ssiii", $usuario, $password_hash, $rol_id, $activo, $id);
        } else {
            // Si conserva la misma contraseña
            $sqlUser = "UPDATE usuarios SET usuario = ?, rol_id = ?, activo = ? WHERE id = ?";
            $stmtUser = $conn->prepare($sqlUser);
            $stmtUser->bind_param("siii", $usuario, $rol_id, $activo, $id);
        }

        if ($stmtUser->execute()) {
            // 2. Actualizar o insertar perfil en usuario_perfil
            $checkPerfil = $conn->query("SELECT usuario_id FROM usuario_perfil WHERE usuario_id = $id");
            if ($checkPerfil && $checkPerfil->num_rows > 0) {
                $sqlPerfil = "UPDATE usuario_perfil SET nombre = ?, apellido = ?, direccion = ?, telefono = ?, email = ? WHERE usuario_id = ?";
                $stmtPerfil = $conn->prepare($sqlPerfil);
                $stmtPerfil->bind_param("sssssi", $nombre, $apellido, $direccion, $telefono, $email, $id);
                $stmtPerfil->execute();
            } else {
                $sqlPerfil = "INSERT INTO usuario_perfil (usuario_id, nombre, apellido, direccion, telefono, email) VALUES (?, ?, ?, ?, ?, ?)";
                $stmtPerfil = $conn->prepare($sqlPerfil);
                $stmtPerfil->bind_param("isssss", $id, $nombre, $apellido, $direccion, $telefono, $email);
                $stmtPerfil->execute();
            }

            $_SESSION["mensaje_exito"] = "Usuario actualizado correctamente.";
            registrar_bitacora("Usuario editado", "Usuarios", "Se actualizaron los datos del usuario '$usuario' (ID: $id).");
        } else {
            $_SESSION["mensaje_error"] = "Error al actualizar el usuario: " . $conn->error;
        }
        header("Location: ../usuarios_admin.php");
        exit;

    } elseif ($accion === "eliminar") {
        $id = (int)($_POST["id"] ?? 0);

        $admin_usuario_id = (int)($_SESSION["admin_session"]["usuario_id"] ?? $_SESSION["usuario_id"] ?? 0);
        if ($id <= 0 || $id === $admin_usuario_id) {
            $_SESSION["mensaje_error"] = "ID de usuario inválido o intento de eliminar tu propia cuenta de administrador activa.";
            header("Location: ../usuarios_admin.php");
            exit;
        }

        // Obtener el nombre de usuario antes de eliminarlo
        $getU = $conn->query("SELECT usuario FROM usuarios WHERE id = $id");
        $usuario = $getU && $getU->num_rows > 0 ? $getU->fetch_assoc()["usuario"] : "ID $id";

        // Eliminar perfil y luego el usuario (consistencia referencial)
        $conn->query("DELETE FROM usuario_perfil WHERE usuario_id = $id");
        $sql = "DELETE FROM usuarios WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $_SESSION["mensaje_exito"] = "Usuario eliminado correctamente.";
            registrar_bitacora("Usuario eliminado", "Usuarios", "Se eliminó permanentemente al usuario '$usuario' (ID: $id).");
        } else {
            $_SESSION["mensaje_error"] = "Error al eliminar el usuario: " . $conn->error;
        }
        header("Location: ../usuarios_admin.php");
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

    $sql = "SELECT u.id, u.usuario, u.rol_id, u.activo, up.nombre, up.apellido, up.email, up.telefono, up.direccion 
            FROM usuarios u 
            LEFT JOIN usuario_perfil up ON u.id = up.usuario_id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $user = $resultado->fetch_assoc();
        echo json_encode(["status" => "success", "data" => $user]);
    } else {
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
    }
    exit;
}

header("Location: ../usuarios_admin.php");
exit;
?>
