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
    if ($accion === "pagar") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id <= 0) {
            $_SESSION["mensaje_error"] = "ID de regalía inválido.";
            header("Location: ../regalias_admin.php");
            exit;
        }

        // Consultar monto y beneficiario para bitácora antes de actualizar
        $getNom = $conn->query("SELECT r.monto, CONCAT(upb.nombre, ' ', upb.apellido) AS nombre_beneficiario 
                                FROM regalias r
                                LEFT JOIN usuario_perfil upb ON r.usuario_beneficiado_id = upb.usuario_id
                                WHERE r.id = $id");
                                
        if ($getNom && $getNom->num_rows > 0) {
            $reg = $getNom->fetch_assoc();
            $monto = (float)$reg["monto"];
            $beneficiario = $reg["nombre_beneficiario"] ?? "Usuario ID #" . $id;

            $sql = "UPDATE regalias SET estado = 'pagado' WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $_SESSION["mensaje_exito"] = "Regalía autorizada y marcada como PAGADA con éxito.";
                registrar_bitacora("Regalía pagada", "Regalías", "Se autorizó y pagó la comisión #$id de $$monto a favor del cliente '$beneficiario'.");
            } else {
                $_SESSION["mensaje_error"] = "Error al registrar el pago: " . $conn->error;
            }
        } else {
            $_SESSION["mensaje_error"] = "Regalía no encontrada.";
        }
        header("Location: ../regalias_admin.php");
        exit;
    }
}

header("Location: ../regalias_admin.php");
exit;
?>
