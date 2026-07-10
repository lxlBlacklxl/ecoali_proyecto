<?php
session_start();
require "conexion.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../login.php");
    exit;
}

$usuario = trim($_POST["usuario"] ?? "");
$password = $_POST["password"] ?? "";

if (empty($usuario) || empty($password)) {
    $_SESSION["mensaje"] = "Completa usuario y contraseña.";
    header("Location: ../login.php");
    exit;
}

$sql = "SELECT u.id, u.usuario, u.password_hash, u.rol_id, u.activo, u.cedis_id,
               up.nombre, up.apellido, up.email
        FROM usuarios u
        INNER JOIN usuario_perfil up ON u.id = up.usuario_id
        WHERE u.usuario = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $usuario);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    $_SESSION["mensaje"] = "Usuario no encontrado.";
    header("Location: ../login.php");
    exit;
}

$user = $resultado->fetch_assoc();

if ((int)$user["activo"] !== 1) {
    $_SESSION["mensaje"] = "Tu cuenta está inactiva.";
    header("Location: ../login.php");
    exit;
}

if (!password_verify($password, $user["password_hash"])) {
    $_SESSION["mensaje"] = "Contraseña incorrecta.";
    header("Location: ../login.php");
    exit;
}

$_SESSION["usuario_id"] = $user["id"];
$_SESSION["usuario"] = $user["usuario"];
$_SESSION["rol_id"] = $user["rol_id"];
$_SESSION["nombre"] = $user["nombre"];
$_SESSION["apellido"] = $user["apellido"];
$_SESSION["email"] = $user["email"];
$_SESSION["cedis_id"] = $user["cedis_id"];

if ((int)$user["rol_id"] === 1) {
    $_SESSION["admin_session"] = [
        "usuario_id" => $user["id"],
        "usuario" => $user["usuario"],
        "rol_id" => $user["rol_id"],
        "nombre" => $user["nombre"],
        "apellido" => $user["apellido"],
        "email" => $user["email"]
    ];
}

switch ((int)$user["rol_id"]) {
    case 1:
        header("Location: ../dashboard_admin.php");
        break;

    case 2:
        header("Location: ../dashboard_cliente.php");
        break;

    case 3:
        header("Location: ../dashboard_proveedor.php");
        break;

    case 4:
        header("Location: ../dashboard_repartidor.php");
        break;

    case 5:
        header("Location: ../dashboard_cedis.php");
        break;

    default:
        $_SESSION["mensaje"] = "Tu rol aún no tiene panel asignado.";
        header("Location: ../login.php");
        break;
}

exit;