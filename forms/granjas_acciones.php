<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - CONTROLADOR DE GRANJAS DEL PROVEEDOR (API AJAX)
 * --------------------------------------------------------------------------------
 * Este script procesa peticiones asíncronas para el alta, listado y eliminación 
 * de granjas avícolas asociadas al proveedor en sesión activa.
 */

session_start();
require "conexion.php";

header("Content-Type: application/json");

// 1. CONTROL DE ACCESO - Solo proveedores autorizados (rol_id = 3)
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 3) {
    echo json_encode(["status" => "error", "message" => "Acceso denegado. Perfil no autorizado."]);
    exit;
}

$usuario_id = $_SESSION["usuario_id"];

// 2. OBTENER EL PROVEEDOR_ID VINCULADO
$stmtProv = $conn->prepare("SELECT id, nombre_empresa FROM proveedores WHERE usuario_id = ?");
$stmtProv->bind_param("i", $usuario_id);
$stmtProv->execute();
$resProv = $stmtProv->get_result();

if ($resProv->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "Tu cuenta no está vinculada a ningún registro de proveedor."]);
    exit;
}

$provData = $resProv->fetch_assoc();
$proveedor_id = (int)$provData["id"];
$nombre_empresa = $provData["nombre_empresa"];

// 3. RECIBIR ENTRADA JSON
$input = json_decode(file_get_contents("php://input"), true);
$accion = trim($input["accion"] ?? "");

if (empty($accion)) {
    echo json_encode(["status" => "error", "message" => "Acción no especificada."]);
    exit;
}

// 4. RUTEO DE ACCIONES
switch ($accion) {
    case "registrar":
        $nombre = trim($input["nombre"] ?? "");
        $identificacion = trim($input["identificacion"] ?? "");
        $ubicacion = trim($input["ubicacion"] ?? "");

        if (empty($nombre) || empty($identificacion) || empty($ubicacion)) {
            echo json_encode(["status" => "error", "message" => "Todos los campos de la granja son obligatorios."]);
            exit;
        }

        // Iniciar transacción
        $conn->begin_transaction();
        try {
            // Validar que la identificación sea única para este proveedor
            $stmtCheck = $conn->prepare("SELECT id FROM granjas WHERE proveedor_id = ? AND identificacion = ?");
            $stmtCheck->bind_param("is", $proveedor_id, $identificacion);
            $stmtCheck->execute();
            if ($stmtCheck->get_result()->num_rows > 0) {
                throw new Exception("Ya tienes registrada una granja con esta identificación/código.");
            }

            // Insertar granja
            $stmtIns = $conn->prepare("INSERT INTO granjas (proveedor_id, nombre, identificacion, ubicacion) VALUES (?, ?, ?, ?)");
            $stmtIns->bind_param("isss", $proveedor_id, $nombre, $identificacion, $ubicacion);
            
            if (!$stmtIns->execute()) {
                throw new Exception("Error al insertar la granja en la base de datos.");
            }

            $granja_id = $conn->insert_id;

            // Registrar en bitácora
            $nCompleto = ($_SESSION["nombre"] ?? "Proveedor") . " (" . $nombre_empresa . ")";
            registrar_bitacora(
                "Granja registrada",
                "Inventario",
                "El proveedor '$nCompleto' dio de alta una nueva granja: '$nombre' (ID: $identificacion) ubicada en '$ubicacion'."
            );

            $conn->commit();
            echo json_encode([
                "status" => "success",
                "message" => "¡La granja '$nombre' ha sido registrada exitosamente!",
                "granja_id" => $granja_id
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    case "listar":
        $stmtList = $conn->prepare("SELECT * FROM granjas WHERE proveedor_id = ? ORDER BY id DESC");
        $stmtList->bind_param("i", $proveedor_id);
        $stmtList->execute();
        $resList = $stmtList->get_result();

        $granjas = [];
        while ($row = $resList->fetch_assoc()) {
            $granjas[] = $row;
        }

        echo json_encode([
            "status" => "success",
            "granjas" => $granjas
        ]);
        break;

    case "eliminar":
        $granja_id = (int)($input["id"] ?? 0);

        if ($granja_id <= 0) {
            echo json_encode(["status" => "error", "message" => "ID de granja inválido."]);
            exit;
        }

        $conn->begin_transaction();
        try {
            // Validar propiedad de la granja antes de borrar
            $stmtVal = $conn->prepare("SELECT nombre FROM granjas WHERE id = ? AND proveedor_id = ?");
            $stmtVal->bind_param("ii", $granja_id, $proveedor_id);
            $stmtVal->execute();
            $resVal = $stmtVal->get_result();

            if ($resVal->num_rows === 0) {
                throw new Exception("No tienes autorización para eliminar esta granja o no existe.");
            }

            $nombre_granja = $resVal->fetch_assoc()["nombre"];

            // Eliminar granja (las llaves foráneas ON DELETE SET NULL reestablecerán la consistencia en producciones y lotes)
            $stmtDel = $conn->prepare("DELETE FROM granjas WHERE id = ?");
            $stmtDel->bind_param("i", $granja_id);
            
            if (!$stmtDel->execute()) {
                throw new Exception("Error al eliminar la granja de la base de datos.");
            }

            // Registrar en bitácora
            $nCompleto = ($_SESSION["nombre"] ?? "Proveedor") . " (" . $nombre_empresa . ")";
            registrar_bitacora(
                "Granja eliminada",
                "Inventario",
                "El proveedor '$nCompleto' eliminó la granja: '$nombre_granja' (ID Sistema: $granja_id)."
            );

            $conn->commit();
            echo json_encode([
                "status" => "success",
                "message" => "La granja '$nombre_granja' ha sido eliminada del sistema."
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Acción no reconocida."]);
        break;
}
?>
