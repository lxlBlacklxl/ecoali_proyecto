<?php
session_start();
require "conexion.php";

function check_ruta_match($conn, $pedido_id, $repartidor_id) {
    if (!$repartidor_id) return true; // Permitir desasignar

    // Obtener dirección del cliente del pedido
    $stmt = $conn->prepare("SELECT up.direccion FROM pedidos p INNER JOIN usuario_perfil up ON p.cliente_id = up.usuario_id WHERE p.id = ?");
    $stmt->bind_param("i", $pedido_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$res) return true;
    $dir_cliente = strtolower($res['direccion']);

    // Obtener dirección/ruta del repartidor
    $stmtRep = $conn->prepare("SELECT direccion FROM usuario_perfil WHERE usuario_id = ?");
    $stmtRep->bind_param("i", $repartidor_id);
    $stmtRep->execute();
    $resRep = $stmtRep->get_result()->fetch_assoc();
    $stmtRep->close();
    if (!$resRep) return true;
    $dir_rep = strtolower($resRep['direccion']);

    $puntos = ['norte', 'sur', 'este', 'oeste'];
    
    // Si la dirección del cliente tiene alguno de los puntos cardinales, el repartidor debe tener el mismo punto cardinal
    foreach ($puntos as $punto) {
        $in_cliente = (strpos($dir_cliente, $punto) !== false);
        $in_rep = (strpos($dir_rep, $punto) !== false);
        
        if ($in_cliente && !$in_rep) {
            return false; // No coincide la ruta
        }
    }
    
    return true;
}


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
        $isAjax = (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) &&
                   strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest");

        $cliente_id    = (int)($_POST["cliente_id"] ?? 0);
        $repartidor_id = isset($_POST["repartidor_id"]) && $_POST["repartidor_id"] !== ""
                         ? (int)$_POST["repartidor_id"] : null;
        $total  = 0.00; // el total real vendrá de los productos; aquí se crea el pedido base
        $estado = trim($_POST["estado"] ?? "pendiente");

        if ($cliente_id <= 0) {
            if ($isAjax) {
                header("Content-Type: application/json");
                echo json_encode(["status" => "error", "message" => "Selecciona un cliente válido."]);
                exit;
            }
            $_SESSION["mensaje_error"] = "Datos inválidos para crear el pedido.";
            header("Location: ../logistica_admin.php");
            exit;
        }

        if ($repartidor_id !== null) {
            // Verificar coincidencia de ruta para el nuevo pedido
            $stmtCli = $conn->prepare("SELECT direccion FROM usuario_perfil WHERE usuario_id = ?");
            $stmtCli->bind_param("i", $cliente_id);
            $stmtCli->execute();
            $resCli = $stmtCli->get_result()->fetch_assoc();
            $stmtCli->close();
            $dir_cliente = $resCli ? strtolower($resCli['direccion']) : '';

            $stmtRep = $conn->prepare("SELECT direccion FROM usuario_perfil WHERE usuario_id = ?");
            $stmtRep->bind_param("i", $repartidor_id);
            $stmtRep->execute();
            $resRep = $stmtRep->get_result()->fetch_assoc();
            $stmtRep->close();
            $dir_rep = $resRep ? strtolower($resRep['direccion']) : '';

            $puntos = ['norte', 'sur', 'este', 'oeste'];
            $match_failed = false;
            foreach ($puntos as $punto) {
                $in_cliente = (strpos($dir_cliente, $punto) !== false);
                $in_rep = (strpos($dir_rep, $punto) !== false);
                if ($in_cliente && !$in_rep) {
                    $match_failed = true;
                    break;
                }
            }
            if ($match_failed) {
                if ($isAjax) {
                    header("Content-Type: application/json");
                    echo json_encode(["status" => "error", "message" => "El repartidor no cubre la ruta de este cliente."]);
                    exit;
                }
                $_SESSION["mensaje_error"] = "Error: El repartidor no cubre la ruta de este cliente.";
                header("Location: ../logistica_admin.php");
                exit;
            }
        }

        $sql  = "INSERT INTO pedidos (cliente_id, repartidor_id, total, estado) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iids", $cliente_id, $repartidor_id, $total, $estado);

        if ($stmt->execute()) {
            $pedido_id = $conn->insert_id;

            // Propagar el repartidor asignado y el estado a todos los pedidos de este cliente (excluyendo los ya cancelados)
            $updateSql = "UPDATE pedidos SET repartidor_id = ?, estado = ? WHERE cliente_id = ? AND estado != 'cancelado'";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("isi", $repartidor_id, $estado, $cliente_id);
            $updateStmt->execute();
            $updateStmt->close();

            registrar_bitacora("Pedido creado", "Logística",
                "Se registró el pedido #$pedido_id con estado: $estado y repartidor ID: " . ($repartidor_id ?? "ninguno") . ". Se propagó el repartidor a todos los pedidos del cliente ID: $cliente_id.");

            if ($isAjax) {
                // Obtener datos completos para construir la fila en el frontend
                $rowRes = $conn->prepare(
                    "SELECT p.*,
                            CONCAT(upc.nombre, ' ', upc.apellido) AS nombre_cliente,
                            upc.direccion AS direccion_cliente,
                            CONCAT(upr.nombre, ' ', upr.apellido) AS nombre_repartidor
                     FROM pedidos p
                     LEFT JOIN usuario_perfil upc ON p.cliente_id  = upc.usuario_id
                     LEFT JOIN usuario_perfil upr ON p.repartidor_id = upr.usuario_id
                     WHERE p.id = ?"
                );
                $rowRes->bind_param("i", $pedido_id);
                $rowRes->execute();
                $pedidoData = $rowRes->get_result()->fetch_assoc();

                header("Content-Type: application/json");
                echo json_encode(["status" => "success", "pedido" => $pedidoData]);
                exit;
            }

            $_SESSION["mensaje_exito"] = "Pedido registrado y asignado correctamente.";
        } else {
            if ($isAjax) {
                header("Content-Type: application/json");
                echo json_encode(["status" => "error", "message" => "Error al crear: " . $conn->error]);
                exit;
            }
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

        if ($repartidor_id !== null) {
            if (!check_ruta_match($conn, $id, $repartidor_id)) {
                $_SESSION["mensaje_error"] = "Error: El repartidor seleccionado no cubre la ruta de este pedido.";
                header("Location: ../logistica_admin.php");
                exit;
            }
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

        // Iniciar transacción para garantizar la atomicidad de la eliminación
        $conn->begin_transaction();

        try {
            // 1. Eliminar los detalles del pedido
            $sqlDetalle = "DELETE FROM detalle_pedido WHERE pedido_id = ?";
            $stmtDetalle = $conn->prepare($sqlDetalle);
            if ($stmtDetalle) {
                $stmtDetalle->bind_param("i", $id);
                $stmtDetalle->execute();
                $stmtDetalle->close();
            }

            // 2. Eliminar incidencias relacionadas si existen
            $sqlIncidencias = "DELETE FROM incidencias WHERE pedido_id = ?";
            $stmtIncidencias = $conn->prepare($sqlIncidencias);
            if ($stmtIncidencias) {
                $stmtIncidencias->bind_param("i", $id);
                $stmtIncidencias->execute();
                $stmtIncidencias->close();
            }

            // 3. Desvincular regalías relacionadas (poner pedido_id en NULL)
            $sqlRegalias = "UPDATE regalias SET pedido_id = NULL WHERE pedido_id = ?";
            $stmtRegalias = $conn->prepare($sqlRegalias);
            if ($stmtRegalias) {
                $stmtRegalias->bind_param("i", $id);
                $stmtRegalias->execute();
                $stmtRegalias->close();
            }

            // 4. Eliminar el pedido principal
            $sqlPedido = "DELETE FROM pedidos WHERE id = ?";
            $stmtPedido = $conn->prepare($sqlPedido);
            if ($stmtPedido) {
                $stmtPedido->bind_param("i", $id);
                $stmtPedido->execute();
                $stmtPedido->close();
            }

            // Confirmar transacción
            $conn->commit();

            $_SESSION["mensaje_exito"] = "Pedido eliminado correctamente.";
            registrar_bitacora("Pedido eliminado", "Logística", "Se eliminó de forma permanente el pedido #$id de los registros junto con sus detalles, incidencias y regalías vinculadas.");

        } catch (Exception $e) {
            // Revertir en caso de cualquier fallo
            $conn->rollback();
            $_SESSION["mensaje_error"] = "Error al eliminar el pedido: " . $e->getMessage();
        }

        header("Location: ../logistica_admin.php");
        exit;
    } elseif ($accion === "asignar_repartidor") {
        // AJAX quick-assign: actualiza repartidor_id y opcionalmente el estado del pedido
        header("Content-Type: application/json");
        $id            = (int)($_POST["id"] ?? 0);
        $repartidor_id = isset($_POST["repartidor_id"]) && $_POST["repartidor_id"] !== ""
                         ? (int)$_POST["repartidor_id"] : null;
        $nuevo_estado  = trim($_POST["estado"] ?? "");
        $estados_validos = ['pendiente','preparado','en_ruta','entregado','cancelado'];

        if ($id <= 0) {
            echo json_encode(["status" => "error", "message" => "ID de pedido inválido."]);
            exit;
        }

        if ($repartidor_id !== null) {
            if (!check_ruta_match($conn, $id, $repartidor_id)) {
                echo json_encode(["status" => "error", "message" => "El repartidor no cubre la ruta de este pedido."]);
                exit;
            }
        }

        // Si viene un estado válido, actualizar también el estado
        if ($nuevo_estado && in_array($nuevo_estado, $estados_validos)) {
            $sql  = "UPDATE pedidos SET repartidor_id = ?, estado = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isi", $repartidor_id, $nuevo_estado, $id);
        } else {
            $sql  = "UPDATE pedidos SET repartidor_id = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $repartidor_id, $id);
        }

        if ($stmt->execute()) {
            $nombre_rep = "No asignado";
            if ($repartidor_id) {
                $res = $conn->prepare("SELECT CONCAT(nombre, ' ', apellido) AS nombre FROM usuario_perfil WHERE usuario_id = ?");
                $res->bind_param("i", $repartidor_id);
                $res->execute();
                $row = $res->get_result()->fetch_assoc();
                if ($row) $nombre_rep = $row["nombre"];
            }
            $bitMsg = "Se asignó el repartidor '$nombre_rep' (ID: " . ($repartidor_id ?? 'ninguno') . ") al pedido #$id.";
            if ($nuevo_estado && in_array($nuevo_estado, $estados_validos)) {
                $bitMsg .= " Estado actualizado a: $nuevo_estado.";
            }
            registrar_bitacora("Repartidor asignado", "Logística", $bitMsg);
            echo json_encode(["status" => "success", "message" => "Pedido actualizado.", "nuevo_estado" => $nuevo_estado ?: null]);
        } else {
            echo json_encode(["status" => "error", "message" => "Error al actualizar: " . $conn->error]);
        }
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

// Endpoint: conteo de pedidos activos por repartidor
if ($_SERVER["REQUEST_METHOD"] === "GET" && $accion === "pedidos_repartidor") {
    header("Content-Type: application/json");
    $rep_id = (int)($_GET["rep_id"] ?? 0);
    if ($rep_id <= 0) {
        echo json_encode(["status" => "error", "count" => 0]);
        exit;
    }
    $res  = $conn->prepare("SELECT COUNT(*) FROM pedidos WHERE repartidor_id = ? AND estado IN ('pendiente','preparado','en_ruta')");
    $res->bind_param("i", $rep_id);
    $res->execute();
    $count = (int)$res->get_result()->fetch_row()[0];
    echo json_encode(["status" => "success", "count" => $count]);
    exit;
}

// Endpoint: listado de pedidos PENDIENTES por cliente (atrasados primero)
if ($_SERVER["REQUEST_METHOD"] === "GET" && $accion === "pedidos_cliente") {
    header("Content-Type: application/json");
    $cliente_id = (int)($_GET["cliente_id"] ?? 0);
    if ($cliente_id <= 0) {
        echo json_encode(["status" => "error", "count" => 0, "pedidos" => []]);
        exit;
    }
    
    // Solo pedidos pendientes; los atrasados (>24h) tienen mayor prioridad
    $stmt = $conn->prepare(
        "SELECT id, fecha_pedido, total, estado,
                CASE WHEN fecha_pedido < NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END AS es_atrasado
         FROM pedidos
         WHERE cliente_id = ? AND estado = 'pendiente'
         ORDER BY es_atrasado DESC, id DESC"
    );
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pedidos = [];
    while ($row = $result->fetch_assoc()) {
        $pedidos[] = [
            "id"          => $row["id"],
            "fecha_pedido"=> $row["fecha_pedido"],
            "total"       => (float)$row["total"],
            "estado"      => $row["estado"],
            "es_atrasado" => (bool)$row["es_atrasado"]
        ];
    }
    
    echo json_encode([
        "status"  => "success",
        "count"   => count($pedidos),
        "pedidos" => $pedidos
    ]);
    exit;
}

header("Location: ../logistica_admin.php");
exit;
?>
