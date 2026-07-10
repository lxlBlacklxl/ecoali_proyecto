<?php
session_start();

require "conexion.php";
require "../vendor/autoload.php";

use Google\Client;

$credential = $_POST["credential"] ?? "";

if (empty($credential)) {
    $_SESSION["mensaje"] = "No se recibió el token de Google.";
    header("Location: ../login.php");
    exit;
}

$client = new Client([
    "client_id" => "137822436644-08aukfg18do9q93idftfe52769fq8lk7.apps.googleusercontent.com"
]);

$payload = $client->verifyIdToken($credential);

if (!$payload) {
    $_SESSION["mensaje"] = "Token de Google inválido.";
    header("Location: ../login.php");
    exit;
}

$email = $payload["email"] ?? "";
$nombre_google = $payload["given_name"] ?? "Usuario";
$apellido_google = $payload["family_name"] ?? "";

if (empty($email)) {
    $_SESSION["mensaje"] = "Google no devolvió un correo válido.";
    header("Location: ../login.php");
    exit;
}

$sql = "SELECT u.id, u.usuario, u.rol_id, u.activo, u.cedis_id, up.nombre, up.apellido, up.email
        FROM usuarios u
        INNER JOIN usuario_perfil up ON u.id = up.usuario_id
        WHERE up.email = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    $usuario_auto = explode("@", $email)[0] . rand(100, 999);
    $password_fake = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $rol_cliente = 2;

    $sqlInsertUser = "INSERT INTO usuarios (usuario, password_hash, rol_id, activo) VALUES (?, ?, ?, 1)";
    $stmtInsertUser = $conn->prepare($sqlInsertUser);
    $stmtInsertUser->bind_param("ssi", $usuario_auto, $password_fake, $rol_cliente);

    if (!$stmtInsertUser->execute()) {
        $_SESSION["mensaje"] = "No se pudo crear la cuenta con Google.";
        header("Location: ../login.php");
        exit;
    }

    $usuario_id = $conn->insert_id;

    $sqlPerfil = "INSERT INTO usuario_perfil (usuario_id, nombre, apellido, email)
                  VALUES (?, ?, ?, ?)";
    $stmtPerfil = $conn->prepare($sqlPerfil);
    $stmtPerfil->bind_param("isss", $usuario_id, $nombre_google, $apellido_google, $email);

    if (!$stmtPerfil->execute()) {
        $_SESSION["mensaje"] = "No se pudo guardar el perfil de Google.";
        header("Location: ../login.php");
        exit;
    }

    $sql = "SELECT u.id, u.usuario, u.rol_id, u.activo, u.cedis_id, up.nombre, up.apellido, up.email
            FROM usuarios u
            INNER JOIN usuario_perfil up ON u.id = up.usuario_id
            WHERE u.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
}

$user = $resultado->fetch_assoc();

if ((int)$user["activo"] !== 1) {
    $_SESSION["mensaje"] = "Tu cuenta está inactiva.";
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