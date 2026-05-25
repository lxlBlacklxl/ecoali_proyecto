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
    if ($accion === "cambiar_estado") {
        $id = (int)($_POST["id"] ?? 0);
        $activo = (int)($_POST["activo"] ?? 1);

        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "ID de cliente inválido.";
            header("Location: ../clientes_admin.php");
            exit;
        }

        $sql = "UPDATE usuarios SET activo = ? WHERE id = ? AND rol_id = 2";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $activo, $id);

        if ($stmt->execute()) {
            $estadoText = $activo === 1 ? "activado" : "desactivado";
            
            // Obtener el nombre del cliente para la bitácora
            $getNom = $conn->query("SELECT nombre, apellido FROM usuario_perfil WHERE usuario_id = $id");
            $nombreCliente = $getNom && $getNom->num_rows > 0 ? $getNom->fetch_assoc() : ["nombre" => "ID", "apellido" => $id];
            $nomComp = $nombreCliente["nombre"] . " " . $nombreCliente["apellido"];

            $_SESSION["mensaje_exito"] = "Estado del cliente actualizado correctamente.";
            registrar_bitacora("Cliente $estadoText", "Clientes", "Se cambió el estado del cliente '$nomComp' (ID: $id) a: " . strtoupper($estadoText) . ".");
        } else {
            $_SESSION["mensaje_error"] = "Error al actualizar estado del cliente: " . $conn->error;
        }
        header("Location: ../clientes_admin.php");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && $accion === "obtener_pedidos") {
    header("Content-Type: application/json");
    $cliente_id = (int)($_GET["id"] ?? 0);
    if ($cliente_id <= 0) {
        echo json_encode(["status" => "error", "message" => "ID de cliente inválido"]);
        exit;
    }

    // Obtener los pedidos del cliente con JOINS
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

header("Location: ../clientes_admin.php");
exit;
?>
