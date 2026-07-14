<?php
// Obtiene la ruta limpia solicitada (ej: /login o /login.php)
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// 1. Redirección automática: si es una petición GET y termina en .php, redirigir a la versión sin .php.
// Usamos redirección 302 (temporal) para evitar problemas de caché en desarrollo, y solo en peticiones GET
// para no perder los datos enviados por formularios (POST).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\.php$/', $path)) {
    $cleanPath = preg_replace('/\.php$/', '', $path);
    $query = isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] !== '' ? '?' . $_SERVER["QUERY_STRING"] : '';
    header("Location: " . $cleanPath . $query, true, 302);
    exit;
}

// 2. Si el archivo físico real existe (imágenes, CSS, JS, etc.), dejar que el servidor lo sirva directamente
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false;
}

// 3. Si se solicita la raíz, buscar index.php
if ($path === '/') {
    if (file_exists(__DIR__ . '/index.php')) {
        include __DIR__ . '/index.php';
        exit;
    }
}

// 4. Verificar si existe el archivo agregándole la extensión .php
$phpFile = __DIR__ . $path . '.php';
if (file_exists($phpFile)) {
    include $phpFile;
} else {
    // Si no existe el archivo, devolver false para que el servidor muestre su error 404 estándar
    return false;
}
