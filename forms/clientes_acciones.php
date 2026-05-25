<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - ACCIONES ADMINISTRATIVAS SOBRE CLIENTES (API FORM/AJAX)
 * --------------------------------------------------------------------------------
 * Este script procesa dos operaciones clave solicitadas por el Administrador:
 * 1. POST: Cambiar el estado de activación (Activo/Inactivo) de la cuenta de un cliente.
 * 2. GET: Obtener mediante AJAX el historial completo de pedidos realizados por un cliente
 *    específico para visualizarlos de forma dinámica en la interfaz de gestión.
 */

// 1. INICIAR EL MANEJO DE SESIONES
session_start();

// 2. IMPORTAR LA CONEXIÓN DE LA BASE DE DATOS Y BITÁCORA
require "conexion.php";

// 3. CONTROL DE ACCESO - VALIDAR QUE EL USUARIO TIENE ROL DE ADMINISTRADOR (rol_id = 1)
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 1) {
    header("Content-Type: application/json");
    echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requiere perfil de Administrador."]);
    exit;
}

// 4. IDENTIFICAR LA OPERACIÓN/ACCIÓN SOLICITADA POR EL CLIENTE HTTP
$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";

// 5. PROCESAMIENTO DE PETICIONES DE MODIFICACIÓN DE ESTADO (MÉTODO POST)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($accion === "cambiar_estado") {
        $id = (int)($_POST["id"] ?? 0);
        $activo = (int)($_POST["activo"] ?? 1);

        // 5.1 Validar integridad del ID enviado
        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "El ID del cliente especificado no es válido.";
            header("Location: ../clientes_admin.php");
            exit;
        }

        // 5.2 Actualizar el estado 'activo' del cliente (solo si pertenece al rol_id = 2 que es Cliente)
        $sql = "UPDATE usuarios SET activo = ? WHERE id = ? AND rol_id = 2";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $activo, $id);

        if ($stmt->execute()) {
            $estadoText = $activo === 1 ? "activado" : "desactivado";
            
            // 5.3 Obtener el nombre completo del cliente para registrar un log detallado en la bitácora
            $getNom = $conn->query("SELECT nombre, apellido FROM usuario_perfil WHERE usuario_id = $id");
            $nombreCliente = $getNom && $getNom->num_rows > 0 ? $getNom->fetch_assoc() : ["nombre" => "ID", "apellido" => $id];
            $nomComp = $nombreCliente["nombre"] . " " . $nombreCliente["apellido"];

            $_SESSION["mensaje_exito"] = "El estado del cliente ha sido actualizado de manera exitosa.";
            
            // 5.4 Auditoría interna del sistema
            registrar_bitacora("Cliente $estadoText", "Clientes", "Se cambió el estado del cliente '$nomComp' (ID: $id) a: " . strtoupper($estadoText) . ".");
        } else {
            $_SESSION["mensaje_error"] = "Error crítico al actualizar el estado del cliente: " . $conn->error;
        }
        
        // 5.5 Redirigir de regreso al panel maestro de clientes
        header("Location: ../clientes_admin.php");
        exit;
    }
}

// 6. PROCESAMIENTO DE CONSULTA DE HISTORIAL DE PEDIDOS VÍA AJAX (MÉTODO GET)
if ($_SERVER["REQUEST_METHOD"] === "GET" && $accion === "obtener_pedidos") {
    header("Content-Type: application/json");
    $cliente_id = (int)($_GET["id"] ?? 0);
    
    // 6.1 Validar integridad del ID de consulta
    if ($cliente_id <= 0) {
        echo json_encode(["status" => "error", "message" => "ID de cliente inválido"]);
        exit;
    }

    // 6.2 Consulta para obtener pedidos con información del repartidor asociado (LEFT JOIN)
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
    
    // 6.3 Mapear los datos a una estructura limpia para JSON
    while ($row = $resultado->fetch_assoc()) {
        $pedidos[] = [
            "id" => $row["id"],
            "total" => (float)$row["total"],
            "estado" => ucfirst($row["estado"]),
            "fecha" => date("d/m/Y H:i", strtotime($row["fecha_pedido"])),
            "repartidor" => $row["nombre_repartidor"] ?? "No asignado"
        ];
    }

    // 6.4 Retornar el set de datos en formato JSON seguro
    echo json_encode(["status" => "success", "data" => $pedidos]);
    exit;
}

// 7. REDIRECCIÓN POR DEFECTO EN CASO DE ACCESO DIRECTO NO AUTORIZADO
header("Location: ../clientes_admin.php");
exit;
?>
