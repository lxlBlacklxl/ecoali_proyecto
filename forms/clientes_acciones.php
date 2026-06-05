<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - ACCIONES ADMINISTRATIVAS SOBRE CLIENTES (API FORM/AJAX)
 * --------------------------------------------------------------------------------
 * Procesa el CRUD completo (Altas, Bajas, Cambios) de clientes desde la vista
 * clientes_admin.php de forma segura y auditable.
 */

session_start();
require "conexion.php";

// 1. CONTROL DE ACCESO - VALIDAR ADMINISTRADOR
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
        echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requiere perfil de Administrador."]);
        exit;
    }
}

$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    
    // ACCIÓN: CREAR CLIENTE (ALTA)
    if ($accion === "crear") {
        $usuario = trim($_POST["usuario"] ?? "");
        $password = trim($_POST["password"] ?? "");
        $nombre = trim($_POST["nombre"] ?? "");
        $apellido = trim($_POST["apellido"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $direccion = trim($_POST["direccion"] ?? "");
        $telefono = trim($_POST["telefono"] ?? "");

        if (empty($usuario) || empty($password) || empty($nombre) || empty($email)) {
            $_SESSION["mensaje_error"] = "Campos obligatorios incompletos (Usuario, Contraseña, Nombre y Correo).";
            header("Location: ../clientes_admin.php");
            exit;
        }

        // Iniciar transacción
        $conn->begin_transaction();
        try {
            // Validar que el usuario no exista
            $stmtCheck = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $stmtCheck->bind_param("s", $usuario);
            $stmtCheck->execute();
            if ($stmtCheck->get_result()->num_rows > 0) {
                throw new Exception("El nombre de usuario ya se encuentra registrado.");
            }

            // Validar que el email no exista
            $stmtMailCheck = $conn->prepare("SELECT id FROM usuario_perfil WHERE email = ?");
            $stmtMailCheck->bind_param("s", $email);
            $stmtMailCheck->execute();
            if ($stmtMailCheck->get_result()->num_rows > 0) {
                throw new Exception("El correo electrónico ya se encuentra registrado.");
            }

            // Insertar usuario
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $rol_id = 2; // Cliente
            $activo = 1;
            $stmtUser = $conn->prepare("INSERT INTO usuarios (usuario, password_hash, rol_id, activo) VALUES (?, ?, ?, ?)");
            $stmtUser->bind_param("ssii", $usuario, $password_hash, $rol_id, $activo);
            if (!$stmtUser->execute()) {
                throw new Exception("Error al guardar la cuenta de usuario.");
            }
            $usuario_id = $conn->insert_id;

            // Insertar perfil
            $stmtProfile = $conn->prepare("INSERT INTO usuario_perfil (usuario_id, nombre, apellido, direccion, telefono, email) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtProfile->bind_param("isssss", $usuario_id, $nombre, $apellido, $direccion, $telefono, $email);
            if (!$stmtProfile->execute()) {
                throw new Exception("Error al guardar el perfil del cliente.");
            }

            $conn->commit();
            $_SESSION["mensaje_exito"] = "Cliente '$nombre $apellido' registrado exitosamente.";
            auditar_accion("Clientes", "Cliente creado", "Se dio de alta al cliente '$nombre $apellido' con usuario '$usuario' e ID de perfil: $usuario_id.");
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION["mensaje_error"] = "Error: " . $e->getMessage();
        }

        header("Location: ../clientes_admin.php");
        exit;

    // ACCIÓN: EDITAR CLIENTE (CAMBIO)
    } elseif ($accion === "editar") {
        $usuario_id = (int)($_POST["id"] ?? 0);
        $usuario = trim($_POST["usuario"] ?? "");
        $password = trim($_POST["password"] ?? "");
        $nombre = trim($_POST["nombre"] ?? "");
        $apellido = trim($_POST["apellido"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $direccion = trim($_POST["direccion"] ?? "");
        $telefono = trim($_POST["telefono"] ?? "");

        if ($usuario_id <= 0 || empty($usuario) || empty($nombre) || empty($email)) {
            $_SESSION["mensaje_error"] = "Datos obligatorios incompletos.";
            header("Location: ../clientes_admin.php");
            exit;
        }

        // Iniciar transacción
        $conn->begin_transaction();
        try {
            // Validar que el usuario no esté duplicado en otros registros
            $stmtCheck = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? AND id != ?");
            $stmtCheck->bind_param("si", $usuario, $usuario_id);
            $stmtCheck->execute();
            if ($stmtCheck->get_result()->num_rows > 0) {
                throw new Exception("El nombre de usuario ya está asignado a otra cuenta.");
            }

            // Validar email único
            $stmtMailCheck = $conn->prepare("SELECT id FROM usuario_perfil WHERE email = ? AND usuario_id != ?");
            $stmtMailCheck->bind_param("si", $email, $usuario_id);
            $stmtMailCheck->execute();
            if ($stmtMailCheck->get_result()->num_rows > 0) {
                throw new Exception("El correo electrónico ya está asignado a otra cuenta.");
            }

            // Actualizar credenciales y opcionalmente password
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                $stmtUser = $conn->prepare("UPDATE usuarios SET usuario = ?, password_hash = ? WHERE id = ?");
                $stmtUser->bind_param("ssi", $usuario, $password_hash, $usuario_id);
            } else {
                $stmtUser = $conn->prepare("UPDATE usuarios SET usuario = ? WHERE id = ?");
                $stmtUser->bind_param("si", $usuario, $usuario_id);
            }
            $stmtUser->execute();

            // Actualizar datos de perfil
            $stmtProfile = $conn->prepare("UPDATE usuario_perfil SET nombre = ?, apellido = ?, direccion = ?, telefono = ?, email = ? WHERE usuario_id = ?");
            $stmtProfile->bind_param("sssssi", $nombre, $apellido, $direccion, $telefono, $email, $usuario_id);
            $stmtProfile->execute();

            $conn->commit();
            $_SESSION["mensaje_exito"] = "Datos del cliente '$nombre $apellido' actualizados correctamente.";
            auditar_accion("Clientes", "Cliente editado", "Se modificaron los datos del cliente '$nombre $apellido' (ID: $usuario_id).");
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION["mensaje_error"] = "Error: " . $e->getMessage();
        }

        header("Location: ../clientes_admin.php");
        exit;

    // ACCIÓN: ELIMINAR CLIENTE (BAJA)
    } elseif ($accion === "eliminar") {
        $usuario_id = (int)($_POST["id"] ?? 0);

        if ($usuario_id <= 0) {
            $_SESSION["mensaje_error"] = "ID de cliente inválido.";
            header("Location: ../clientes_admin.php");
            exit;
        }

        $conn->begin_transaction();
        try {
            // Obtener datos antes de eliminar
            $getNom = $conn->query("SELECT nombre, apellido FROM usuario_perfil WHERE usuario_id = $usuario_id");
            $nombreCliente = $getNom && $getNom->num_rows > 0 ? $getNom->fetch_assoc() : ["nombre" => "Cliente", "apellido" => "ID $usuario_id"];
            $nomComp = $nombreCliente["nombre"] . " " . $nombreCliente["apellido"];

            // Eliminar dependencias
            $conn->query("DELETE FROM regalias WHERE usuario_referido_id = $usuario_id OR usuario_beneficiado_id = $usuario_id");
            $conn->query("DELETE FROM detalle_pedido WHERE pedido_id IN (SELECT id FROM pedidos WHERE cliente_id = $usuario_id)");
            $conn->query("DELETE FROM pedidos WHERE cliente_id = $usuario_id");
            $conn->query("DELETE FROM usuario_perfil WHERE usuario_id = $usuario_id");
            $conn->query("DELETE FROM usuarios WHERE id = $usuario_id");

            $conn->commit();
            $_SESSION["mensaje_exito"] = "Cliente '$nomComp' eliminado de forma permanente.";
            auditar_accion("Clientes", "Cliente eliminado", "Se eliminó permanentemente de los registros al cliente '$nomComp' (ID: $usuario_id).");
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION["mensaje_error"] = "Error al eliminar: " . $e->getMessage();
        }

        header("Location: ../clientes_admin.php");
        exit;

    // ACCIÓN: CAMBIAR ESTADO
    } elseif ($accion === "cambiar_estado") {
        $id = (int)($_POST["id"] ?? 0);
        $activo = (int)($_POST["activo"] ?? 1);

        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "El ID del cliente no es válido.";
            header("Location: ../clientes_admin.php");
            exit;
        }

        $sql = "UPDATE usuarios SET activo = ? WHERE id = ? AND rol_id = 2";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $activo, $id);

        if ($stmt->execute()) {
            $estadoText = $activo === 1 ? "activado" : "desactivado";
            $getNom = $conn->query("SELECT nombre, apellido FROM usuario_perfil WHERE usuario_id = $id");
            $nombreCliente = $getNom && $getNom->num_rows > 0 ? $getNom->fetch_assoc() : ["nombre" => "Cliente", "apellido" => "ID $id"];
            $nomComp = $nombreCliente["nombre"] . " " . $nombreCliente["apellido"];

            $_SESSION["mensaje_exito"] = "Estado del cliente actualizado correctamente.";
            auditar_accion("Clientes", "Cliente $estadoText", "Se cambió el estado del cliente '$nomComp' (ID: $id) a: " . strtoupper($estadoText) . ".");
        } else {
            $_SESSION["mensaje_error"] = "Error al actualizar estado.";
        }

        header("Location: ../clientes_admin.php");
        exit;
    }
}

// 2. PETICIONES GET (AJAX / CONSULTAS)
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    
    // ACCIÓN: OBTENER DATOS DE UN CLIENTE PARA EL MODAL DE EDICIÓN
    if ($accion === "obtener") {
        header("Content-Type: application/json");
        $id = (int)($_GET["id"] ?? 0);

        if ($id <= 0) {
            echo json_encode(["status" => "error", "message" => "ID de cliente inválido."]);
            exit;
        }

        $sql = "SELECT u.id, u.usuario, p.nombre, p.apellido, p.email, p.direccion, p.telefono 
                FROM usuarios u 
                INNER JOIN usuario_perfil p ON u.id = p.usuario_id 
                WHERE u.id = ? AND u.rol_id = 2";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            echo json_encode(["status" => "success", "data" => $res->fetch_assoc()]);
        } else {
            echo json_encode(["status" => "error", "message" => "Cliente no encontrado."]);
        }
        exit;

    // ACCIÓN: HISTORIAL DE PEDIDOS
    } elseif ($accion === "obtener_pedidos") {
        header("Content-Type: application/json");
        $cliente_id = (int)($_GET["id"] ?? 0);

        if ($cliente_id <= 0) {
            echo json_encode(["status" => "error", "message" => "ID de cliente inválido"]);
            exit;
        }

        $sql = "SELECT p.id, p.total, p.estado, p.fecha_pedido, 
                       CONCAT(upr.nombre, ' ', upr.apellido) AS nombre_repartidor
                FROM pedidos p
                LEFT JOIN usuario_perfil upr ON p.repartidor_id = upr.usuario_id
                WHERE p.cliente_id = ?
                ORDER BY p.id DESC";
                
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $cliente_id);
        $stmt->execute();
        $resultado = $stmt->get_result();

        $pedidos = [];
        while ($row = $resultado->fetch_assoc()) {
            $pedidos[] = [
                "id" => $row["id"],
                "total" => (float)$row["total"],
                "estado" => ucfirst($row["estado"]),
                "fecha" => date("d/m/Y H:i", strtotime($row["fecha_pedido"])),
                "repartidor" => $row["nombre_repartidor"] ?? "No asignado"
            ];
        }

        echo json_encode(["status" => "success", "data" => $pedidos]);
        exit;
    }
}

header("Location: ../clientes_admin.php");
exit;
?>
