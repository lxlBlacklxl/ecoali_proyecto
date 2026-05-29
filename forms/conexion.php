<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - CONECTOR MAESTRO DE BASE DE DATOS Y SERVICIOS GLOBALES
 * --------------------------------------------------------------------------------
 * Este script establece el canal de comunicación seguro a través del controlador
 * nativo MySQLi en modo orientado a objetos. Además, realiza migraciones automáticas
 * autogenerando tablas esenciales (bitácora, regalías) si no existen, y expone la
 * función de auditoría universal `registrar_bitacora`.
 */

// 1. CONFIGURACIÓN DE PARÁMETROS DEL SERVIDOR DE BASE DE DATOS
$host = "localhost";
$usuario = "root";
$password = "";
$bd = "ecoali";

// 2. CREACIÓN DE LA INSTANCIA DE CONEXIÓN CON MYSQLI
$conn = new mysqli($host, $usuario, $password, $bd);

// 3. CONTROL DE FALLOS DE CONEXIÓN A BASE DE DATOS
if ($conn->connect_error) {
    die("Error crítico de conexión a la base de datos: " . $conn->connect_error);
}

// 4. CONFIGURACIÓN DEL JUEGO DE CARACTERES A UTF-8 SEGURO (Multibyte)
$conn->set_charset("utf8mb4");

// 5. MIGRACIÓN AUTOMÁTICA: CREAR TABLA DE BITÁCORA SI NO EXISTE
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

// 6. MIGRACIÓN AUTOMÁTICA: CREAR TABLA DE REGALÍAS SI NO EXISTE
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

// 7. COMPATIBILIDAD LOGÍSTICA: MODIFICAR ENUM DE ESTADOS DE PEDIDO
$conn->query("ALTER TABLE pedidos MODIFY COLUMN estado ENUM('pendiente','preparado','en_ruta','entregado','cancelado') DEFAULT 'pendiente'");

// 8. MIGRACIÓN AUTOMÁTICA: CREAR TABLA DE GRANJAS SI NO EXISTE
$conn->query("CREATE TABLE IF NOT EXISTS `granjas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `proveedor_id` INT NOT NULL,
    `nombre` VARCHAR(150) NOT NULL,
    `identificacion` VARCHAR(100) NOT NULL,
    `ubicacion` VARCHAR(200) NOT NULL,
    `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE CASCADE
)");

// 9. MIGRACIÓN AUTOMÁTICA: AGREGAR granja_id A produccion E inventario_huevos SI NO EXISTEN
$resColProd = $conn->query("SHOW COLUMNS FROM `produccion` LIKE 'granja_id'");
if ($resColProd && $resColProd->num_rows === 0) {
    $conn->query("ALTER TABLE `produccion` ADD COLUMN `granja_id` INT NULL, ADD FOREIGN KEY (`granja_id`) REFERENCES `granjas`(`id`) ON DELETE SET NULL");
}

$resColInv = $conn->query("SHOW COLUMNS FROM `inventario_huevos` LIKE 'granja_id'");
if ($resColInv && $resColInv->num_rows === 0) {
    $conn->query("ALTER TABLE `inventario_huevos` ADD COLUMN `granja_id` INT NULL, ADD FOREIGN KEY (`granja_id`) REFERENCES `granjas`(`id`) ON DELETE SET NULL");
}

// 9.2 COMPATIBILIDAD LOGÍSTICA: AGREGAR COLUMNAS DE ENTREGA A PEDIDOS SI NO EXISTEN
$resFechaEntrega = $conn->query("SHOW COLUMNS FROM `pedidos` LIKE 'fecha_entrega'");
if ($resFechaEntrega && $resFechaEntrega->num_rows === 0) {
    $conn->query("ALTER TABLE `pedidos` 
                  ADD COLUMN `fecha_entrega` DATETIME NULL,
                  ADD COLUMN `coordenadas_entrega` VARCHAR(100) NULL,
                  ADD COLUMN `firma_entrega` LONGTEXT NULL,
                  ADD COLUMN `foto_entrega` LONGTEXT NULL");
}

// 9.3 MIGRACIÓN AUTOMÁTICA: CREAR TABLA DE INCIDENCIAS SI NO EXISTE
$conn->query("CREATE TABLE IF NOT EXISTS `incidencias` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pedido_id` INT NOT NULL,
    `repartidor_id` INT NOT NULL,
    `tipo` VARCHAR(50) NOT NULL,
    `descripcion` TEXT NOT NULL,
    `coordenadas` VARCHAR(100) NULL,
    `fecha_reporte` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 10. FUNCIÓN AUXILIAR DE REGISTRO EN BITÁCORA (AUDITORÍA INTERNA)
if (!function_exists('registrar_bitacora')) {
    /**
     * Registra una acción relevante realizada en el sistema para fines de auditoría.
     * 
     * @param string $accion El nombre identificador del evento (ej. "Inicio de Sesión").
     * @param string $modulo El nombre del módulo donde ocurre (ej. "Usuarios", "Clientes").
     * @param string $descripcion Detalle explicativo de la operación efectuada.
     */
    function registrar_bitacora($accion, $modulo, $descripcion) {
        global $conn;
        
        // 8.1 Iniciar sesión del usuario si no está activa para obtener su identidad
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $usuario_id = $_SESSION['usuario_id'] ?? 1; // ID 1 por defecto (ej. Admin inicial en CLI)
        $fecha = date('Y-m-d');
        $hora = date('H:i:s');
        
        // 8.2 Insertar de forma segura empleando Prepared Statements
        $stmt = $conn->prepare("INSERT INTO bitacora (usuario_id, accion_realizada, modulo_afectado, descripcion, fecha, hora) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isssss", $usuario_id, $accion, $modulo, $descripcion, $fecha, $hora);
            $stmt->execute();
            $stmt->close();
        }
    }
}
?>