<?php
session_start();

// 1. Destruir todas las variables de sesión
$_SESSION = array();

// 2. Destruir la cookie de sesión si existe
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 3. Destruir la sesión físicamente en el servidor
session_destroy();

// 4. Cabeceras estrictas Anti-Caché para prevenir uso de botón "Atrás" del navegador
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

// 5. Redireccionar al login
header("Location: login.php");
exit;
?>
