<?php

$host = "localhost";
$usuario = "root";
$password = "";
$bd = "ecoali";

$conn = new mysqli($host, $usuario, $password, $bd);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// 1. Crear automáticamente la tabla de Bitácora si no existe
$conn->query("CREATE TABLE IF NOT EXISTS `bitacora` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `usuario_id` INT NOT NULL,
    `accion_realizada` VARCHAR(150) NOT NULL,
    `modulo_afectado` VARCHAR(100) NOT NULL,
    `descripcion` TEXT,
    `fecha` DATE NOT NULL,
    `hora` TIME NOT NULL,
    `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 2. Crear automáticamente la tabla de Regalías si no existe
$conn->query("CREATE TABLE IF NOT EXISTS `regalias` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `usuario_beneficiado_id` INT NOT NULL,
    `usuario_referido_id` INT NOT NULL,
    `pedido_id` INT DEFAULT NULL,
    `nivel` INT NOT NULL DEFAULT 1,
    `monto` DECIMAL(10,2) NOT NULL,
    `estado` ENUM('pendiente', 'pagado') NOT NULL DEFAULT 'pendiente',
    `fecha` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Alterar columna estado de la tabla pedidos para incluir 'preparado'
$conn->query("ALTER TABLE pedidos MODIFY COLUMN estado ENUM('pendiente','preparado','en_ruta','entregado','cancelado') DEFAULT 'pendiente'");

// 3. Función auxiliar global para registrar acciones en la Bitácora
if (!function_exists('registrar_bitacora')) {
    function registrar_bitacora($accion, $modulo, $descripcion) {
        global $conn;
        
        // Iniciar sesión solo si no está ya activa
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $usuario_id = $_SESSION['usuario_id'] ?? 1; // ID 1 por defecto (ej. Admin inicial en CLI)
        $fecha = date('Y-m-d');
        $hora = date('H:i:s');
        
        $stmt = $conn->prepare("INSERT INTO bitacora (usuario_id, accion_realizada, modulo_afectado, descripcion, fecha, hora) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isssss", $usuario_id, $accion, $modulo, $descripcion, $fecha, $hora);
            $stmt->execute();
            $stmt->close();
        }
    }
}
?>