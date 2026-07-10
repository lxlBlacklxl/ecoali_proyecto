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
$host = "127.0.0.1";
$usuario = "root";
$password = "Ecoali123!";
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

// 8.0.1 MIGRACIÓN AUTOMÁTICA: CREAR TABLA DE CEDIS SI NO EXISTE
$conn->query("CREATE TABLE IF NOT EXISTS `cedis` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(150) NOT NULL,
    `direccion` VARCHAR(255) NOT NULL,
    `activo` TINYINT NOT NULL DEFAULT 1,
    `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Sembrar datos de prueba para CEDIS si está vacía
$resCedisCheck = $conn->query("SELECT COUNT(*) FROM `cedis`");
if ($resCedisCheck && (int)$resCedisCheck->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO `cedis` (nombre, direccion, activo) VALUES 
        ('CEDIS Principal', 'Avenida de la Constitución 120, Sevilla', 1),
        ('CEDIS Norte', 'Polígono Industrial Norte, Calle A, Madrid', 1),
        ('CEDIS Sur', 'Avenida de Andalucía 54, Málaga', 1)
    ");
}

// 8.1 MIGRACIÓN AUTOMÁTICA: CREAR TABLA DE ENTREGAS AL CEDIS SI NO EXISTE
$conn->query("CREATE TABLE IF NOT EXISTS `entregas_cedis` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `proveedor_id` INT NOT NULL,
    `cedis_id` INT NOT NULL,
    `repartidor_id` INT DEFAULT NULL,
    `fecha_solicitud` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `fecha_recoleccion` DATE NOT NULL,
    `fecha_recepcion` DATETIME DEFAULT NULL,
    `estado` ENUM('pendiente', 'en_ruta', 'recibido', 'cancelado') NOT NULL DEFAULT 'pendiente',
    `observaciones` TEXT,
    `motivo_rechazo` TEXT,
    FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE CASCADE
)");

// 8.2 MIGRACIÓN AUTOMÁTICA: CREAR TABLA DE DETALLE DE ENTREGA AL CEDIS SI NO EXISTE
$conn->query("CREATE TABLE IF NOT EXISTS `detalle_entrega_cedis` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `entrega_id` INT NOT NULL,
    `lote_id` INT NOT NULL,
    `cantidad` INT NOT NULL,
    FOREIGN KEY (`entrega_id`) REFERENCES `entregas_cedis`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`lote_id`) REFERENCES `inventario_huevos`(`id`) ON DELETE CASCADE
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

// 9.1 MIGRACIÓN AUTOMÁTICA: AGREGAR codigo_lote A produccion SI NO EXISTE
$resColProdLote = $conn->query("SHOW COLUMNS FROM `produccion` LIKE 'codigo_lote'");
if ($resColProdLote && $resColProdLote->num_rows === 0) {
    $conn->query("ALTER TABLE `produccion` ADD COLUMN `codigo_lote` VARCHAR(50) NULL");
    
    // Intentar asociar lotes existentes de inventario_huevos a produccion
    $conn->query("UPDATE produccion p 
                  INNER JOIN inventario_huevos i ON p.proveedor_id = i.proveedor_id 
                    AND p.producto_id = i.producto_id 
                    AND p.cantidad = i.cantidad 
                    AND p.fecha_produccion = i.fecha_postura 
                    AND (p.granja_id = i.granja_id OR (p.granja_id IS NULL AND i.granja_id IS NULL))
                  SET p.codigo_lote = i.codigo_lote
                  WHERE p.codigo_lote IS NULL OR p.codigo_lote = ''");
}

// 9.1.2 MIGRACIÓN AUTOMÁTICA: AGREGAR cantidad_inicial A inventario_huevos SI NO EXISTE
$resColInvInit = $conn->query("SHOW COLUMNS FROM `inventario_huevos` LIKE 'cantidad_inicial'");
if ($resColInvInit && $resColInvInit->num_rows === 0) {
    $conn->query("ALTER TABLE `inventario_huevos` ADD COLUMN `cantidad_inicial` INT NOT NULL DEFAULT 0");
    // Inicializar cantidad_inicial con el valor de cantidad para los registros existentes
    $conn->query("UPDATE `inventario_huevos` SET `cantidad_inicial` = `cantidad` WHERE `cantidad_inicial` = 0");
}

// 9.1.3 MIGRACIÓN AUTOMÁTICA: COMPATIBILIDAD ENUM ESTADO EN inventario_huevos
$conn->query("ALTER TABLE `inventario_huevos` MODIFY COLUMN `estado` ENUM('disponible','bajo_stock','caducado','vendido','activo','proximo_caducar') DEFAULT 'disponible'");

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

// 9.4 MIGRACIÓN AUTOMÁTICA: CREAR TABLA DE CUPONES SI NO EXISTE
$conn->query("CREATE TABLE IF NOT EXISTS `cupones` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `codigo` VARCHAR(50) UNIQUE NOT NULL,
    `tipo` ENUM('porcentaje', 'fijo') NOT NULL,
    `descuento` DECIMAL(10,2) NOT NULL,
    `activo` TINYINT NOT NULL DEFAULT 1
)");

// 9.5 MIGRACIÓN AUTOMÁTICA: CREAR TABLA DE PROMOCIONES SI NO EXISTE
$conn->query("CREATE TABLE IF NOT EXISTS `promociones` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(100) NOT NULL,
    `tipo` ENUM('porcentaje', 'fijo') NOT NULL,
    `descuento` DECIMAL(10,2) NOT NULL,
    `compra_minima` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `activo` TINYINT NOT NULL DEFAULT 1
)");

// 9.6 MIGRACIÓN AUTOMÁTICA: AGREGAR stock_cartones A granjas SI NO EXISTE
$resColCarton = $conn->query("SHOW COLUMNS FROM `granjas` LIKE 'stock_cartones'");
if ($resColCarton && $resColCarton->num_rows === 0) {
    $conn->query("ALTER TABLE `granjas` ADD COLUMN `stock_cartones` INT NOT NULL DEFAULT 120");
}

// 9.7 MIGRACIÓN AUTOMÁTICA: AGREGAR COLUMNAS DE DESCUENTO A PEDIDOS SI NO EXISTEN
$resDescuento = $conn->query("SHOW COLUMNS FROM `pedidos` LIKE 'descuento'");
if ($resDescuento && $resDescuento->num_rows === 0) {
    $conn->query("ALTER TABLE `pedidos` 
                  ADD COLUMN `descuento` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  ADD COLUMN `cupon_codigo` VARCHAR(50) NULL");
}

$resIva = $conn->query("SHOW COLUMNS FROM `pedidos` LIKE 'iva'");
if ($resIva && $resIva->num_rows === 0) {
    $conn->query("ALTER TABLE `pedidos` 
                  ADD COLUMN `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  ADD COLUMN `iva` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
}

// 9.8 MIGRACIÓN AUTOMÁTICA: INSERTAR CUPONES Y PROMOCIONES DE MUESTRA SI LA TABLA ESTÁ VACÍA
$resCuponCheck = $conn->query("SELECT COUNT(*) FROM cupones");
if ($resCuponCheck && (int)$resCuponCheck->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO cupones (codigo, tipo, descuento, activo) VALUES 
        ('ECO20', 'porcentaje', 20.00, 1),
        ('FRESCO10', 'porcentaje', 10.00, 1),
        ('AHORRO5', 'fijo', 5.00, 1)
    ");
}

$resPromoCheck = $conn->query("SELECT COUNT(*) FROM promociones");
if ($resPromoCheck && (int)$resPromoCheck->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO promociones (nombre, tipo, descuento, compra_minima, activo) VALUES 
        ('Descuento Verde', 'porcentaje', 10.00, 50.00, 1),
        ('Mega Ahorro', 'fijo', 15.00, 100.00, 1)
    ");
}

// 9.8.2 MIGRACIÓN AUTOMÁTICA: ESTABLECER CLAVES FORÁNEAS (INTEGRIDAD REFERENCIAL DE BASE DE DATOS)
$resFKCheck = $conn->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '$bd' AND CONSTRAINT_NAME = 'fk_pedidos_cliente'");
if ($resFKCheck && $resFKCheck->num_rows === 0) {
    // Obtener primer ID administrador como fallback para registros huérfanos
    $adminIdRes = $conn->query("SELECT id FROM usuarios WHERE rol_id = 1 LIMIT 1");
    $firstAdminId = 4; // Default fallback
    if ($adminIdRes && $adminIdRes->num_rows > 0) {
        $firstAdminId = (int)$adminIdRes->fetch_row()[0];
    }

    // 1. Limpieza de datos huérfanos antes de aplicar las llaves foráneas
    $conn->query("DELETE FROM pedidos WHERE cliente_id NOT IN (SELECT id FROM usuarios)");
    $conn->query("UPDATE pedidos SET repartidor_id = NULL WHERE repartidor_id IS NOT NULL AND repartidor_id NOT IN (SELECT id FROM usuarios)");
    
    $conn->query("DELETE FROM incidencias WHERE pedido_id NOT IN (SELECT id FROM pedidos)");
    $conn->query("DELETE FROM incidencias WHERE repartidor_id NOT IN (SELECT id FROM usuarios)");
    
    $conn->query("DELETE FROM regalias WHERE usuario_beneficiado_id NOT IN (SELECT id FROM usuarios)");
    $conn->query("DELETE FROM regalias WHERE usuario_referido_id NOT IN (SELECT id FROM usuarios)");
    $conn->query("UPDATE regalias SET pedido_id = NULL WHERE pedido_id IS NOT NULL AND pedido_id NOT IN (SELECT id FROM pedidos)");
    
    $conn->query("UPDATE bitacora SET usuario_id = $firstAdminId WHERE usuario_id NOT IN (SELECT id FROM usuarios)");
    
    $conn->query("DELETE FROM entregas_cedis WHERE cedis_id NOT IN (SELECT id FROM cedis)");
    $conn->query("UPDATE entregas_cedis SET repartidor_id = NULL WHERE repartidor_id IS NOT NULL AND repartidor_id NOT IN (SELECT id FROM usuarios)");
    
    $conn->query("DELETE FROM proveedores WHERE usuario_id NOT IN (SELECT id FROM usuarios)");

    // 2. Aplicar ALTER TABLE para agregar claves foráneas (Constraints)
    $conn->query("ALTER TABLE pedidos ADD CONSTRAINT fk_pedidos_cliente FOREIGN KEY (cliente_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    $conn->query("ALTER TABLE pedidos ADD CONSTRAINT fk_pedidos_repartidor FOREIGN KEY (repartidor_id) REFERENCES usuarios(id) ON DELETE SET NULL");
    
    $conn->query("ALTER TABLE incidencias ADD CONSTRAINT fk_incidencias_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE");
    $conn->query("ALTER TABLE incidencias ADD CONSTRAINT fk_incidencias_repartidor FOREIGN KEY (repartidor_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    
    $conn->query("ALTER TABLE regalias ADD CONSTRAINT fk_regalias_beneficiado FOREIGN KEY (usuario_beneficiado_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    $conn->query("ALTER TABLE regalias ADD CONSTRAINT fk_regalias_referido FOREIGN KEY (usuario_referido_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    $conn->query("ALTER TABLE regalias ADD CONSTRAINT fk_regalias_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE SET NULL");
    
    $conn->query("ALTER TABLE bitacora ADD CONSTRAINT fk_bitacora_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    
    $conn->query("ALTER TABLE entregas_cedis ADD CONSTRAINT fk_entregas_cedis_cedis FOREIGN KEY (cedis_id) REFERENCES cedis(id) ON DELETE CASCADE");
    $conn->query("ALTER TABLE entregas_cedis ADD CONSTRAINT fk_entregas_cedis_repartidor FOREIGN KEY (repartidor_id) REFERENCES usuarios(id) ON DELETE SET NULL");
    
    $conn->query("ALTER TABLE proveedores ADD CONSTRAINT fk_proveedores_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE");
}


/**
 * Función global de captura de auditoría para el Sistema de Auditoría y Bitácoras (Requisito #25).
 * Registra de manera unificada cualquier acción de precio, cancelaciones y movimientos de inventario.
 */
if (!function_exists('auditar_accion')) {
    function auditar_accion($modulo, $accion, $descripcion) {
        registrar_bitacora($accion, $modulo, $descripcion);
    }
}

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
        
        $usuario_id = $_SESSION["admin_session"]["usuario_id"] ?? $_SESSION['usuario_id'] ?? 1; // ID 1 por defecto (ej. Admin inicial en CLI)
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

// 9.9 CADUCIDAD AUTOMÁTICA DE LOTES (REGLA DE NEGOCIO #6):
// Lotes de postura que excedan los 3 días de antigüedad a partir de hoy son marcados como 'caducado'.
// Para evitar ciclos infinitos, solo buscamos lotes 'disponible' o 'bajo_stock' con fecha de postura > 3 días.
$stmtCaducador = $conn->query("SELECT id, codigo_lote FROM inventario_huevos WHERE estado IN ('disponible', 'bajo_stock') AND DATEDIFF(CURDATE(), fecha_postura) > 3");
if ($stmtCaducador && $stmtCaducador->num_rows > 0) {
    while ($loteCaducado = $stmtCaducador->fetch_assoc()) {
        $lote_id = $loteCaducado["id"];
        $lote_code = $loteCaducado["codigo_lote"];
        $conn->query("UPDATE inventario_huevos SET estado = 'caducado' WHERE id = $lote_id");
        // Registrar auditoría automática en bitácora
        registrar_bitacora(
            "Lote caducado automáticamente", 
            "Inventario", 
            "El sistema detectó que el lote '$lote_code' superó los 3 días de antigüedad desde su postura y fue bloqueado automáticamente para la venta."
        );
    }
}

// 9.9.2 MIGRACIÓN AUTOMÁTICA: ACTUALIZACIÓN DE CLASIFICACIÓN DE TAMAÑOS DE HUEVOS POR PESO
// Chico: menos de 56g, Mediano: 56g a 70g, Jumbo: más de 70g
$conn->query("UPDATE productos SET tamano = 'Chico (Menos de 56g)' WHERE tamano LIKE '%Pequeño%' OR tamano LIKE '%Chico%' OR tamano = 'S'");
$conn->query("UPDATE productos SET tamano = 'Mediano (56g a 70g)' WHERE tamano LIKE '%Medio%' OR tamano LIKE '%Mediano%' OR tamano = 'M'");
$conn->query("UPDATE productos SET tamano = 'Jumbo (Más de 70g)' WHERE tamano LIKE '%Grande%' OR tamano LIKE '%Extra%' OR tamano LIKE '%Jumbo%' OR tamano = 'L' OR tamano = 'XL'");

// 9.9.3 MIGRACIÓN AUTOMÁTICA: AGREGAR no_viable Y merma A LA TABLA produccion
$resColNoViable = $conn->query("SHOW COLUMNS FROM `produccion` LIKE 'no_viable'");
if ($resColNoViable && $resColNoViable->num_rows === 0) {
    $conn->query("ALTER TABLE `produccion` ADD COLUMN `no_viable` INT NOT NULL DEFAULT 0");
}
$resColMerma = $conn->query("SHOW COLUMNS FROM `produccion` LIKE 'merma'");
if ($resColMerma && $resColMerma->num_rows === 0) {
    $conn->query("ALTER TABLE `produccion` ADD COLUMN `merma` INT NOT NULL DEFAULT 0");
}

// 9.9.4 MIGRACIÓN AUTOMÁTICA: AGREGAR no_viable Y merma A LA TABLA inventario_huevos
$resColInvNoViable = $conn->query("SHOW COLUMNS FROM `inventario_huevos` LIKE 'no_viable'");
if ($resColInvNoViable && $resColInvNoViable->num_rows === 0) {
    $conn->query("ALTER TABLE `inventario_huevos` ADD COLUMN `no_viable` INT NOT NULL DEFAULT 0");
}
$resColInvMerma = $conn->query("SHOW COLUMNS FROM `inventario_huevos` LIKE 'merma'");
if ($resColInvMerma && $resColInvMerma->num_rows === 0) {
    $conn->query("ALTER TABLE `inventario_huevos` ADD COLUMN `merma` INT NOT NULL DEFAULT 0");
}
?>