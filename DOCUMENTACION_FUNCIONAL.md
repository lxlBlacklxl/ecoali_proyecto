# 🌿 DOCUMENTACIÓN FUNCIONAL Y TÉCNICA - ECOALI

Esta documentación proporciona una descripción exhaustiva, detallada y rigurosa del estado funcional y técnico del sistema **EcoAli**. Describe el stack tecnológico, el mapa de relaciones entre archivos, el esquema físico de base de datos (DDL), los flujos de usuario por perfil, y el detalle de funcionamiento de cada una de las interfaces y módulos que integran el software, cumpliendo con los estándares de entrega del proyecto.

---

## 🛠️ 1. FICHA TÉCNICA DEL PROYECTO

El sistema EcoAli está concebido como una plataforma de comercio justo, trazabilidad y gestión logística para productos avícolas y orgánicos. Conecta a Granjeros Proveedores, Clientes Finales y Choferes Repartidores bajo la supervisión de un Administrador Central.

### 1.1 Stack Tecnológico
*   **Servidor Web y Backend**: PHP 8.x. Implementación de lógica modular y procedimental estructurada, con comunicación síncrona y controladores AJAX asíncronos para transacciones rápidas.
*   **Base de Datos**: MySQL / MariaDB. Uso estricto de restricciones de integridad referencial (`FOREIGN KEY` con cláusulas `ON DELETE CASCADE` y `ON DELETE SET NULL`), transacciones seguras (`begin_transaction`, `commit`, `rollback`) e índices para optimización de consultas.
*   **Frontend**: HTML5 Semántico, CSS3 Premium estructurado mediante variables de diseño globales (`globals.css`), transiciones fluidas, animaciones dinámicas y diseño totalmente responsivo (Adaptabilidad Híbrida Desktop/Mobile).
*   **JavaScript**: Vanilla JavaScript (ES6) para validación en el cliente, gestión del carrito, manipulación asíncrona del DOM y peticiones asíncronas por Fetch API.
*   **Servicios e Integraciones Externas**:
    *   **Bing Maps API**: Renderizado de rutas logísticas para repartidores, utilizando estilos de mapa oscuros (`canvasDark`) y supresión automatizada de avisos de credenciales para garantizar una visualización limpia.
    *   **Web Speech API**: Síntesis y reconocimiento de voz en español (México) a través del asistente virtual interactivo "Doña Ali" para facilitar la accesibilidad del cliente y de granjeros de edad avanzada.
    *   **Google Sign-In (OAuth 2.0)**: Inicio de sesión integrado mediante cuentas de Google en la pantalla de login.
    *   **Servidor de Correos (SMTP)**: Integración para envío automatizado de notificaciones de registro y estados de despacho.

### 1.2 Políticas y Capas de Seguridad
*   **Control de Acceso y Sesión**: Autenticación centralizada mediante sesiones PHP nativas (`session_start()`). Las pantallas y controladores verifican obligatoriamente el rol del usuario (`$_SESSION["rol_id"]`) al inicio del script, redirigiendo al login en caso de accesos no autorizados.
*   **Seguridad de Contraseñas**: Encriptación mediante el algoritmo hash seguro **Bcrypt** (`PASSWORD_BCRYPT` y `password_verify`), con una longitud estándar de almacenamiento segura.
*   **Prevención de Inyecciones SQL**: Uso mandatorio de consultas preparadas (Prepared Statements) con la interfaz `prepare` de MySQLi para toda manipulación y consulta de datos.
*   **Integridad de Transacciones**: Operaciones críticas como el cobro del pedido (descuento del stock) y la cancelación del pedido (FIFO Restore) se ejecutan dentro de bloques transaccionales. Si alguna de las consultas falla, se realiza un rollback inmediato garantizando que no existan inconsistencias de stock ni pérdidas financieras.
*   **Doble Bloqueo de Checkout**: En el flujo del cliente, se implementa una bandera global (`isProcessingCheckout`) y un spinner de carga que bloquean el botón de pago al primer clic, evitando la duplicidad de pedidos o cobros múltiples por latencia de la red.

---

## 🗄️ 2. ESQUEMA DE BASE DE DATOS (DDL)

El sistema genera y actualiza automáticamente su base de datos al conectarse a través del controlador maestro (`forms/conexion.php`). A continuación se detallan las estructuras físicas de las tablas y sus relaciones de integridad referencial:

### 2.1 Tabla: `usuarios`
Almacena las credenciales de acceso de los distintos perfiles en el sistema.
```sql
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(100) NOT NULL UNIQUE,
  `contrasena` VARCHAR(255) NOT NULL,
  `rol_id` INT NOT NULL,
  `activo` TINYINT NOT NULL DEFAULT 1,
  `codigo_verificacion` VARCHAR(10) NULL,
  `token` VARCHAR(255) NULL,
  `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 2.2 Tabla: `usuario_perfil`
Contiene la información de contacto detallada de cada usuario registrado.
```sql
CREATE TABLE IF NOT EXISTS `usuario_perfil` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `apellido` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `telefono` VARCHAR(20) NULL,
  `direccion` TEXT NULL,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
);
```

### 2.3 Tabla: `granjas`
Almacena los datos de las granjas asociadas a los proveedores, incluyendo su stock local de cartones para el empaque.
```sql
CREATE TABLE IF NOT EXISTS `granjas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `proveedor_id` INT NOT NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `identificacion` VARCHAR(100) NOT NULL,
  `ubicacion` VARCHAR(200) NOT NULL,
  `stock_cartones` INT NOT NULL DEFAULT 120,
  `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE CASCADE
);
```

### 2.4 Tabla: `cedis`
Centros de distribución física donde los proveedores entregan sus lotes para consolidación.
```sql
CREATE TABLE IF NOT EXISTS `cedis` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(150) NOT NULL,
  `direccion` VARCHAR(255) NOT NULL,
  `activo` TINYINT NOT NULL DEFAULT 1,
  `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 2.5 Tabla: `entregas_cedis`
Cabecera para controlar las solicitudes de transporte y entrega de lotes de los proveedores a los centros de distribución.
```sql
CREATE TABLE IF NOT EXISTS `entregas_cedis` (
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
  FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`cedis_id`) REFERENCES `cedis`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`repartidor_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
);
```

### 2.6 Tabla: `detalle_entrega_cedis`
Desglose detallado de los lotes y la cantidad de unidades que contiene una solicitud de entrega al CEDIS.
```sql
CREATE TABLE IF NOT EXISTS `detalle_entrega_cedis` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `entrega_id` INT NOT NULL,
  `lote_id` INT NOT NULL,
  `cantidad` INT NOT NULL,
  FOREIGN KEY (`entrega_id`) REFERENCES `entregas_cedis`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lote_id`) REFERENCES `inventario_huevos`(`id`) ON DELETE CASCADE
);
```

### 2.7 Tabla: `inventario_huevos`
Control de existencias de lotes de huevos clasificados, registrando trazabilidad y frescura.
```sql
CREATE TABLE IF NOT EXISTS `inventario_huevos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `proveedor_id` INT NOT NULL,
  `producto_id` INT NOT NULL,
  `granja_id` INT NULL,
  `codigo_lote` VARCHAR(50) NOT NULL UNIQUE,
  `cantidad_inicial` INT NOT NULL DEFAULT 0,
  `cantidad` INT NOT NULL,
  `no_viable` INT NOT NULL DEFAULT 0,
  `merma` INT NOT NULL DEFAULT 0,
  `fecha_postura` DATE NOT NULL,
  `fecha_caducidad` DATE NOT NULL,
  `estado` ENUM('disponible','bajo_stock','caducado','vendido','activo','proximo_caducar') DEFAULT 'disponible',
  FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`granja_id`) REFERENCES `granjas`(`id`) ON DELETE SET NULL
);
```

### 2.8 Tabla: `pedidos`
Cabecera transaccional que contiene las compras de los clientes y las evidencias de entrega final.
```sql
CREATE TABLE IF NOT EXISTS `pedidos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cliente_id` INT NOT NULL,
  `repartidor_id` INT DEFAULT NULL,
  `total` DECIMAL(10,2) NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `iva` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `descuento` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `cupon_codigo` VARCHAR(50) NULL,
  `estado` ENUM('pendiente','preparado','en_ruta','entregado','cancelado') DEFAULT 'pendiente',
  `pago_estado` VARCHAR(50) NOT NULL DEFAULT 'pendiente',
  `metodo_pago` VARCHAR(50) NOT NULL,
  `fecha_pedido` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `fecha_entrega` DATETIME NULL,
  `coordenadas_entrega` VARCHAR(100) NULL,
  `firma_entrega` LONGTEXT NULL,
  `foto_entrega` LONGTEXT NULL,
  FOREIGN KEY (`cliente_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`repartidor_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
);
```

### 2.9 Tabla: `detalle_pedido`
Artículos, cantidades y precios unitarios cobrados por cada pedido.
```sql
CREATE TABLE IF NOT EXISTS `detalle_pedido` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pedido_id` INT NOT NULL,
  `producto_id` INT NOT NULL,
  `cantidad` INT NOT NULL,
  `precio` DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (`pedido_id`) REFERENCES `pedidos`(`id`) ON DELETE CASCADE
);
```

### 2.10 Tabla: `productos`
Catálogo maestro de tamaños y precios de los productos comercializados.
```sql
CREATE TABLE IF NOT EXISTS `productos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `tipo_huevo` VARCHAR(50) NOT NULL,
  `tamano` VARCHAR(100) NOT NULL,
  `precio` DECIMAL(10,2) NOT NULL,
  `activo` TINYINT NOT NULL DEFAULT 1
);
```

### 2.11 Tabla: `regalias`
Registro de comisiones generadas a los clientes por la compra de sus referidos (10% de comisión).
```sql
CREATE TABLE IF NOT EXISTS `regalias` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_beneficiado_id` INT NOT NULL,
  `usuario_referido_id` INT NOT NULL,
  `pedido_id` INT DEFAULT NULL,
  `nivel` INT NOT NULL DEFAULT 1,
  `monto` DECIMAL(10,2) NOT NULL,
  `estado` ENUM('pendiente', 'pagado') NOT NULL DEFAULT 'pendiente',
  `fecha` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`usuario_beneficiado_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_referido_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`pedido_id`) REFERENCES `pedidos`(`id`) ON DELETE SET NULL
);
```

### 2.12 Tabla: `incidencias`
Reportes de anomalías registradas en ruta por los repartidores.
```sql
CREATE TABLE IF NOT EXISTS `incidencias` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pedido_id` INT NOT NULL,
  `repartidor_id` INT NOT NULL,
  `tipo` VARCHAR(50) NOT NULL,
  `descripcion` TEXT NOT NULL,
  `coordenadas` VARCHAR(100) NULL,
  `fecha_reporte` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`pedido_id`) REFERENCES `pedidos`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`repartidor_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
);
```

### 2.13 Tabla: `cupones`
Cupones de descuento aplicables de manera manual en el checkout del cliente.
```sql
CREATE TABLE IF NOT EXISTS `cupones` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo` VARCHAR(50) UNIQUE NOT NULL,
  `tipo` ENUM('porcentaje', 'fijo') NOT NULL,
  `descuento` DECIMAL(10,2) NOT NULL,
  `activo` TINYINT NOT NULL DEFAULT 1
);
```

### 2.14 Tabla: `promociones`
Descuentos aplicados automáticamente en el checkout del cliente según montos de compra mínima.
```sql
CREATE TABLE IF NOT EXISTS `promociones` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `tipo` ENUM('porcentaje', 'fijo') NOT NULL,
  `descuento` DECIMAL(10,2) NOT NULL,
  `compra_minima` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `activo` TINYINT NOT NULL DEFAULT 1
);
```

### 2.15 Tabla: `bitacora`
Libro diario de auditoría inmutable donde se registran las acciones del sistema.
```sql
CREATE TABLE IF NOT EXISTS `bitacora` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NOT NULL,
  `accion_realizada` VARCHAR(150) NOT NULL,
  `modulo_afectado` VARCHAR(100) NOT NULL,
  `descripcion` TEXT,
  `fecha` DATE NOT NULL,
  `hora` TIME NOT NULL,
  `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
);
```

---

## 🗺️ 3. MAPA DE RELACIÓN VISTA-CONTROLADOR

Para entender el funcionamiento de la arquitectura, a continuación se muestra la correspondencia exacta entre las interfaces visuales del frontend y sus controladores de lógica del backend:

| Interfaz de Usuario (Vista Frontend) | Tipo de Petición | Controlador de Lógica (Backend) | Operación que realiza |
| :--- | :--- | :--- | :--- |
| `login.php` | POST / AJAX | `forms/procesar_login.php` | Valida credenciales e inicia la sesión del usuario. |
| `login.php` | OAuth 2.0 | `forms/google_login.php` | Autenticación y registro automático mediante cuenta Google. |
| `register.php` | POST | `forms/usuarios_acciones.php` | Auto-registro inicial de un cliente en la plataforma. |
| `verificar_codigo.php` | POST | `forms/completar_registro.php` | Verifica el código de seguridad 2FA enviado al usuario. |
| `usuarios_admin.php` | AJAX (POST) | `forms/usuarios_acciones.php` | CRUD de usuarios (Crear, Editar, Eliminar, Cambiar Estado). |
| `clientes_admin.php` | AJAX (POST) | `forms/clientes_acciones.php` | CRUD de clientes y consulta asíncrona de su historial de compras. |
| `proveedores_admin.php` | AJAX (POST) | `forms/proveedores_acciones.php` | CRUD de proveedores y visualización de sus granjas. |
| `inventario_admin.php` | AJAX (POST) | `forms/inventario_acciones.php` | Ajuste manual de mermas y auditoría de inventario global. |
| `logistica_admin.php` | POST | `forms/logistica_admin.php` (Lógica interna) | Asigna despachos a repartidores y actualiza estados de preparación. |
| `regalias_admin.php` | POST | `forms/regalias_acciones.php` | Procesa y liquida el pago de regalías acumuladas de referidos. |
| `reportes_admin.php` | GET / Exportar | `forms/exportar_reporte.php` | Generación y descarga de reportes operativos en formato CSV. |
| `dashboard_cliente.php` | AJAX (POST) | `forms/procesar_pedido.php` | Procesa compras, aplica FIFO en inventario y calcula comisiones. |
| `dashboard_cliente.php` | AJAX (POST) | `forms/cancelar_pedido.php` | Cancela pedido pendiente devolviendo stock con FIFO Restore. |
| `produccion_proveedor.php` | POST | `forms/procesar_produccion.php` | Registra postura diaria multiorigen y genera lotes. |
| `lotes_proveedor.php` | POST | `lotes_proveedor.php` (Lógica interna) | Edita y elimina lotes generados reincorporando cartones. |
| `entregas_proveedor.php` | POST | `entregas_proveedor.php` (Lógica interna) | Solicita recogida de lotes al CEDIS y descuenta stock en granja. |
| `trazabilidad_proveedor.php` | GET | `trazabilidad_proveedor.php` (Lógica interna) | Consulta la cadena de custodia y trayecto logístico del lote. |
| `dashboard_repartidor.php` | AJAX (POST) | `forms/actualizar_estado_entrega.php` | Cierre de entregas (guarda firma, coordenadas GPS y foto Base64). |
| `dashboard_repartidor.php` | AJAX (POST) | `forms/reportar_incidencia.php` | Registra eventualidades en ruta y ejecuta reincorporación FIFO. |
| `editar_perfil.php` | POST | `forms/perfil_*.php` (Rol correspondiente) | Actualización de datos de contacto y zona del repartidor. |

---

## 👥 4. FLUJOS DE TRABAJO GENERALES POR ROL

El sistema funciona de manera integrada. Las acciones de un perfil disparan de forma automática tareas o cambios en la interfaz de otro:

### 4.1 Flujo del Granjero Proveedor (Abastecimiento de Producto)
1. El proveedor ingresa a su portal y verifica su stock de cartones. Si es bajo, añade cartones a su granja.
2. Registra la recolección diaria de huevos (postura) introduciendo las cantidades de huevo Chico, Mediano y Grande cosechados. El sistema descuenta los cartones y genera un lote automático con el código único: `GRJ{clave}-{fecha_postura}-{consecutivo}`.
3. El proveedor solicita el envío de sus lotes disponibles al CEDIS de la ciudad, seleccionando el CEDIS destino y las cantidades de cada lote.
4. El inventario local del proveedor queda en estado `pendiente_entrega`, a la espera de que el administrador asigne logística.

### 4.2 Flujo del Administrador (Auditoría, Logística y Liquidación)
1. En el Dashboard visualiza las métricas clave y alarmas del sistema en tiempo real.
2. Desde la pestaña de **Logística**, el administrador visualiza los envíos pendientes del proveedor y los asigna a un repartidor disponible.
3. El administrador también procesa los pedidos del cliente final. Cuando un cliente realiza una compra, el pedido entra en estado `pendiente`. El administrador cambia el estado a `preparado` cuando el pedido ha sido empaquetado en el CEDIS, dejándolo listo para su reparto.
4. Asigna los pedidos en estado `preparado` a los repartidores, ordenando de forma prioritaria los pedidos que presenten retrasos.

### 4.3 Flujo del Repartidor (Última Milla y Evidencia Digital)
1. El repartidor accede a su portal desde un dispositivo móvil. En su pantalla principal visualiza su **Hoja de Ruta** con las paradas programadas de forma ordenada.
2. La interfaz de Bing Maps le traza la ruta más eficiente de entrega. Al arrancar, marca el pedido como `en_ruta`.
3. Al llegar a la ubicación del cliente, el repartidor abre el modal de entrega, captura las coordenadas de geolocalización GPS, solicita al cliente su firma manuscrita sobre el Canvas HTML5, toma una foto del producto entregado y confirma el cobro si el pago es a contra entrega. El pedido se actualiza a `entregado` en el sistema.
4. Si ocurre un contratiempo (ej. cliente ausente), registra una **Incidencia**. Si se cancela el pedido, el sistema ejecuta de forma automática el **FIFO Restore**, reincorporando el stock en el lote de inventario más reciente del CEDIS para evitar mermas financieras.

### 4.4 Flujo del Cliente Final (Compra y Referidos)
1. El cliente inicia sesión, navega por el catálogo de productos con filtros dinámicos en tiempo real y añade productos (clasificados por tamaño) al carrito de compras.
2. En la pantalla de checkout, ingresa una dirección estructurada de México, opcionalmente aplica un cupón de descuento y presiona pagar. El sistema ejecuta el algoritmo **FIFO** ( First In, First Out ) para descontar el stock del lote de huevos más antiguo en el CEDIS, asegurando que el cliente reciba el producto más fresco.
3. Si el cliente fue referido por otro usuario de la plataforma, el sistema calcula de manera automática una comisión (regalía) del 10% del total de la compra y se la abona al usuario que lo recomendó en estado `pendiente`.
4. El cliente puede descargar su **Comprobante de Pedido** en formato PDF y realizar el seguimiento de su envío en tiempo real.

---

## 💻 5. DETALLE FUNCIONAL DE MÓDULOS E INTERFACES

A continuación se detalla exhaustivamente cada una de las pantallas del sistema:

---

### 5.1 MÓDULO DE AUTENTICACIÓN Y ACCESO

#### A. Pantalla de Acceso (Login)
*   **Perfil al que pertenece**: Público / Todos los roles (Administrador, Cliente, Proveedor, Repartidor).
*   **Objetivo**: Validar las credenciales de los usuarios registrados y redirigirlos de forma automática al portal que corresponda a su rol.
*   **Campos de Entrada**:
    *   `usuario` (Texto, Obligatorio): Nombre de usuario único del sistema.
    *   `contrasena` (Contraseña, Obligatorio): Contraseña de acceso asociada a la cuenta.
*   **Botones y Acciones Disponibles**:
    *   `Iniciar Sesión`: Envía los datos para su validación en el servidor mediante el controlador `forms/procesar_login.php`.
    *   `Iniciar Sesión con Google`: Botón interactivo que ejecuta el flujo OAuth 2.0 (Google Login) mediante el script `forms/google_login.php`.
    *   `¿No tienes cuenta? Regístrate aquí`: Enlace que redirige a la pantalla de auto-registro para clientes (`register.php`).
*   **Flujo Paso a Paso**:
    1. El usuario digita sus credenciales y presiona `Iniciar Sesión`.
    2. El backend recibe los datos mediante una consulta preparada que obtiene el registro activo.
    3. Compara el hash de la contraseña usando `password_verify`. Si es correcto, guarda la información del rol, sesión y perfil.
    4. Redirige según el `rol_id` (1: `dashboard_admin.php`, 2: `dashboard_cliente.php`, 3: `dashboard_proveedor.php`, 4: `dashboard_repartidor.php`).
*   **Validaciones y Mensajes**:
    *   *"El usuario no existe en la base de datos"* (Alerta de error roja).
    *   *"La contraseña es incorrecta"* (Alerta de error roja).
    *   *"Su cuenta se encuentra inactiva. Contacte al administrador"* (Alerta de error si `activo = 0`).

#### B. Registro de Clientes
*   **Perfil al que pertenece**: Público / Clientes nuevos.
*   **Objetivo**: Permitir a nuevos clientes crear una cuenta en el sistema para poder realizar compras.
*   **Campos de Entrada**:
    *   `usuario` (Texto, Obligatorio): Nombre de usuario deseado.
    *   `nombre` (Texto, Obligatorio): Nombre de pila del cliente.
    *   `apellido` (Texto, Obligatorio): Apellidos del cliente.
    *   `email` (Email, Obligatorio): Correo electrónico del cliente (para confirmaciones y 2FA).
    *   `contrasena` (Contraseña, Obligatorio): Contraseña del usuario.
    *   `referido_por` (Texto, Opcional): Nombre del usuario que lo invitó al sistema (para activar regalías).
*   **Botones y Acciones Disponibles**:
    *   `Registrarse`: Envía el formulario de registro.
    *   `¿Ya tienes cuenta? Ingresa aquí`: Enlace de redirección a la pantalla de login.
*   **Flujo Paso a Paso**:
    1. El usuario completa los datos requeridos.
    2. El sistema valida en el frontend que el correo tenga un formato válido y que la contraseña cumpla los requisitos mínimos.
    3. Al enviar, el backend encripta la contraseña usando `PASSWORD_BCRYPT` y crea un código de validación de 6 dígitos enviado por correo.
    4. Redirige a la pantalla de verificación de código (`verificar_codigo.php`).
*   **Validaciones y Mensajes**:
    *   *"El nombre de usuario ya está registrado por otra cuenta"* (Alerta roja).
    *   *"El correo electrónico ya está registrado"* (Alerta roja).
    *   *"El usuario de referido no existe"* (Alerta de error si el campo opcional no coincide en la base de datos).

#### C. Verificación en Dos Pasos (2FA)
*   **Perfil al que pertenece**: Clientes de reciente registro.
*   **Objetivo**: Validar la autenticidad del correo del usuario mediante el ingreso de un código temporal de validación de 6 dígitos.
*   **Campos de Entrada**:
    *   `codigo` (Texto / Numérico de 6 dígitos, Obligatorio): Código numérico enviado al correo registrado.
*   **Botones y Acciones Disponibles**:
    *   `Verificar Código`: Envía el código ingresado para su cotejo.
*   **Flujo Paso a Paso**:
    1. El usuario abre su correo, copia el código de seguridad y lo digita en la pantalla.
    2. Al presionar verificar, el backend comprueba que el código coincida con el valor temporal guardado en la tabla `usuarios`.
    3. Si coincide, establece `activo = 1`, limpia el campo `codigo_verificacion` y redirige al Dashboard de Cliente con un mensaje de bienvenida.
*   **Validaciones y Mensajes**:
    *   *"Código de verificación inválido o expirado"* (Alerta de error roja).
    *   *"¡Registro completado con éxito! Bienvenido a EcoAli"* (Alerta verde de éxito en el login).

---

### 5.2 MÓDULO DEL ADMINISTRADOR

#### A. Dashboard Administrativo (`dashboard_admin.php`)
*   **Perfil al que pertenece**: Administrador (`rol_id = 1`).
*   **Objetivo**: Visualizar de forma integrada el rendimiento general del negocio en tiempo real.
*   **Campos de Visualización**:
    *   Métricas clave consolidadas: Ingresos Totales de Ventas, Total de Clientes Activos, Total de Lotes en Stock, Total de Entregas Pendientes.
    *   Sección de **Alarmas del Sistema** (Alertas sobre stock crítico o lotes próximos a caducar).
    *   Tabla de los últimos 5 Pedidos ingresados en el sistema.
*   **Botones y Acciones Disponibles**:
    *   `Ver Todo` (en pedidos): Redirige a la sección logística.
    *   Accesos del menú lateral: Enlaces fijos a todos los submódulos.
*   **Flujo Paso a Paso**:
    1. El administrador inicia sesión y carga de forma automática el dashboard.
    2. El script realiza las consultas de agregación SQL (`SUM` y `COUNT`) sobre las tablas de pedidos, usuarios, inventario y entregas para llenar las tarjetas métricas.
    3. Si el script de conexión (`conexion.php`) detecta lotes con más de 3 días de postura, los marca automáticamente como caducados, disparando una alarma de inventario vencido que se visualiza en la sección de alarmas del Dashboard.

#### B. Gestión de Usuarios (`usuarios_admin.php`)
*   **Perfil al que pertenece**: Administrador (`rol_id = 1`).
*   **Objetivo**: Controlar las cuentas de usuarios internos del sistema (administradores, proveedores y repartidores).
*   **Campos de Formulario (Crear / Editar Modal)**:
    *   `usuario` (Texto, Obligatorio).
    *   `nombre` (Texto, Obligatorio).
    *   `apellido` (Texto, Obligatorio).
    *   `email` (Email, Obligatorio).
    *   `telefono` (Texto, Obligatorio).
    *   `contrasena` (Contraseña, Obligatorio solo en creación).
    *   `rol_id` (Selector, Obligatorio): Administrador, Cliente, Proveedor, Repartidor.
    *   `direccion` (Texto, Obligatorio si el rol es Repartidor o Proveedor).
*   **Botones y Acciones Disponibles**:
    *   `Agregar Usuario`: Abre el modal con formulario en blanco.
    *   `Editar` (en fila de tabla): Carga asíncronamente los datos en el modal de edición.
    *   `Inactivar / Activar`: Alterna el estado activo de la cuenta.
    *   `Eliminar`: Remueve físicamente el registro (con confirmación modal previa).
*   **Flujo Paso a Paso**:
    1. El administrador ingresa a la pantalla de usuarios. La tabla muestra los registros paginados de 5 en 5.
    2. Para crear un usuario, presiona `Agregar Usuario`, completa el formulario y selecciona el rol. Si selecciona el rol de "Repartidor", mediante JavaScript (`configurarAlternanciaDireccion`) se activa el campo "Zona de Trabajo / Dirección".
    3. Al enviar, el script controlador `forms/usuarios_acciones.php` verifica que el nombre de usuario no esté duplicado, encripta la contraseña e inserta el registro en las tablas `usuarios` y `usuario_perfil`.
    4. La acción es registrada automáticamente en el registro de auditoría (`bitacora`).
*   **Validaciones y Mensajes**:
    *   *"El nombre de usuario ya está en uso"* (Alerta de error roja).
    *   *"El usuario se creó correctamente"* (Toast de éxito verde).
    *   *"¿Está seguro de que desea eliminar a este usuario? Esta acción es irreversible"* (Alerta de confirmación de navegador).

#### C. Gestión de Clientes (`clientes_admin.php`)
*   **Perfil al que pertenece**: Administrador.
*   **Objetivo**: Monitorear los perfiles de los clientes, sus consumos y activar o inactivar cuentas.
*   **Campos de Visualización y Entrada**:
    *   Buscador interactivo en tiempo real (por nombre, usuario, correo o teléfono).
    *   Detalle de Consumo Histórico (monto total comprado y número de pedidos del cliente).
*   **Botones y Acciones Disponibles**:
    *   `Ver Pedidos`: Abre un modal de consulta que carga asíncronamente (vía AJAX) la lista detallada de pedidos realizados por el cliente.
    *   `Activar / Inactivar`: Alterna el estado del cliente bloqueando o permitiendo su acceso.
*   **Flujo Paso a Paso**:
    1. El administrador busca un cliente ingresando su nombre en el buscador. La tabla se filtra de forma instantánea mediante JavaScript en el cliente.
    2. Presiona `Ver Pedidos`. Se ejecuta una petición Fetch a `forms/clientes_acciones.php?accion=historial_pedidos&cliente_id=X`.
    3. El backend devuelve un arreglo JSON con el historial que se renderiza dinámicamente en el modal sin recargar la página.
*   **Validaciones y Mensajes**:
    *   *"Cliente inactivado de forma correcta"* (Notificación emergente).
    *   *"No se registran compras previas para este cliente"* (Mensaje de marcador en el modal de historial).

#### D. Gestión de Proveedores (`proveedores_admin.php`)
*   **Perfil al que pertenece**: Administrador.
*   **Objetivo**: Registrar a los granjeros del sistema, asociar sus empresas avícolas y ver la lista de sus granjas asignadas.
*   **Campos de Formulario (Modal Proveedor)**:
    *   `nombre_empresa` (Texto, Obligatorio): Razón social del proveedor.
    *   `cif` (Texto, Obligatorio): Identificación fiscal.
    *   `usuario_id` (Selector, Obligatorio): Cuenta de usuario que se vinculará como proveedor.
*   **Botones y Acciones Disponibles**:
    *   `Registrar Proveedor`: Crea el perfil corporativo del proveedor avícola.
    *   `Ver Granjas`: Abre un modal que lista las instalaciones avícolas registradas por este granjero.
*   **Flujo Paso a Paso**:
    1. El administrador asocia una cuenta de usuario existente con el perfil de proveedor avícola.
    2. Al guardar, el controlador `forms/proveedores_acciones.php` asocia el `usuario_id` en la tabla `proveedores`.
    3. El proveedor a partir de ese momento puede acceder a su portal y registrar granjas.
*   **Validaciones y Mensajes**:
    *   *"Este usuario ya cuenta con un perfil de proveedor asociado"* (Mensaje de error del servidor).
    *   *"Proveedor registrado con éxito"* (Toast de confirmación).

#### E. Catálogo de Productos (`productos_admin.php`)
*   **Perfil al que pertenece**: Administrador.
*   **Objetivo**: Administrar el catálogo de productos disponibles para la venta al cliente final.
*   **Campos de Formulario**:
    *   `nombre` (Texto, Obligatorio): Nombre comercial (ej. Huevo Ecológico).
    *   `tipo_huevo` (Selector, Obligatorio): Orgánico, Campero, Blanco, Rojo.
    *   `tamano` (Selector, Obligatorio): Chico (Menos de 56g), Mediano (56g a 70g), Jumbo (Más de 70g). *(Validaciones estrictas de clasificación de tamaño por peso).*
    *   `precio` (Numérico Decimal, Obligatorio): Precio de venta por unidad.
*   **Botones y Acciones Disponibles**:
    *   `Guardar Producto`: Inserta o actualiza la información en la tabla `productos`.
    *   `Alternar Estado`: Activa/desactiva productos del catálogo comercial.
*   **Flujo Paso a Paso**:
    1. El administrador define el nombre, tipo de huevo y peso.
    2. El backend procesa la inserción y ejecuta una regla de migración para normalizar la descripción del tamaño en base al peso establecido en los estándares avícolas.
    3. El producto queda disponible de inmediato para que los clientes finales lo visualicen y lo compren en su respectivo portal.

#### F. Control de Inventario y Calidad (`inventario_admin.php`)
*   **Perfil al que pertenece**: Administrador.
*   **Objetivo**: Auditar el inventario global de huevos consolidado en el CEDIS, registrar las mermas reportadas por calidad y verificar la trazabilidad de los lotes.
*   **Campos de Entrada (Modal de Ajuste)**:
    *   `lote_id` (Oculto): Lote sobre el cual se aplicará el ajuste.
    *   `merma_adicional` (Numérico, Obligatorio): Cantidad de huevos rotos o dañados detectados.
    *   `no_viable_adicional` (Numérico, Obligatorio): Cantidad de huevos rechazados por tamaño irregular o manchas.
*   **Botones y Acciones Disponibles**:
    *   `Auditar Lote / Registrar Merma`: Abre el formulario de ajuste de inventario.
    *   `Guardar Ajuste`: Ejecuta la deducción en base de datos.
*   **Flujo Paso a Paso**:
    1. El administrador visualiza en la tabla el listado general de lotes recibidos en el CEDIS.
    2. Al detectar huevos rotos en el desempaque, selecciona el lote, presiona `Auditar Lote` e ingresa la cantidad de merma detectada.
    3. El controlador asocia los valores a los campos `merma` y `no_viable` de la tabla `inventario_huevos`, restando la cantidad del stock disponible para evitar que se vendan huevos dañados.
    4. La operación se registra en la bitácora detallando la cantidad y el código del lote auditado.
*   **Validaciones y Mensajes**:
    *   *"La merma ingresada supera el stock disponible en el lote"* (Bloqueo en el backend).
    *   *"Inventario de lote actualizado de forma exitosa"* (Mensaje verde de confirmación).

#### G. Gestión Logística y Despacho (`logistica_admin.php`)
*   **Perfil al que pertenece**: Administrador.
*   **Objetivo**: Asignar repartidores a los pedidos de los clientes, programar la entrega de solicitudes al CEDIS y despachar las unidades correspondientes.
*   **Campos e Interfaces**:
    *   Sección de **Pedidos Atrasados** (destacados con sombreado de advertencia rojo y ordenados con prioridad de atención).
    *   Selector de Repartidor para cada pedido.
    *   Visualizador de costo del flete (Bloqueado de forma estricta con atributo `readonly` en la vista del administrador para evitar modificaciones manuales).
*   **Botones y Acciones Disponibles**:
    *   `Preparar Pedido`: Cambia el estado del pedido de `pendiente` a `preparado`.
    *   `Asignar Repartidor`: Asocia el pedido al conductor seleccionado.
    *   `Despachar a Ruta`: Pone el pedido en estado `en_ruta`.
*   **Flujo Paso a Paso**:
    1. El administrador ingresa al panel logístico. Identifica los pedidos atrasados en color rojo.
    2. Presiona `Preparar Pedido` para descontar el inventario en el CEDIS aplicando la regla FIFO.
    3. Selecciona un repartidor disponible del listado desplegable y presiona `Asignar Repartidor`.
    4. Al despachar la carga física, presiona `Despachar a Ruta`, lo cual notifica el pedido en el portal móvil del chofer.
*   **Validaciones y Mensajes**:
    *   *"El pedido debe estar en estado 'preparado' antes de ser despachado a ruta"* (Validación del sistema).
    *   *"Repartidor asignado correctamente al pedido #PED-XXX"* (Mensaje en pantalla).

#### H. Programa de Regalías y Referidos (`regalias_admin.php`)
*   **Perfil al que pertenece**: Administrador.
*   **Objetivo**: Supervisar las regalías generadas a los clientes por las compras de sus invitados y liquidar los saldos pendientes.
*   **Campos de Entrada (Liquidación)**:
    *   `usuario_beneficiado_id` (Oculto): Cliente que recibirá el pago.
    *   `monto_a_pagar` (Decimal, Bloqueado): Monto total acumulado por comisiones del 10% en estado pendiente.
*   **Botones y Acciones Disponibles**:
    *   `Pagar Regalías`: Cambia el estado de todas las comisiones del cliente de `pendiente` a `pagado` y registra la transferencia.
*   **Flujo Paso a Paso**:
    1. El administrador visualiza en la tabla a los clientes que han acumulado comisiones por referidos.
    2. Al procesar el pago físico o transferencia, presiona `Pagar Regalías`.
    3. El backend en `forms/regalias_acciones.php` ejecuta una transacción que actualiza todos los registros asociados al cliente de `pendiente` a `pagado` en la tabla `regalias`.
    4. Se añade el registro correspondiente en la bitácora de auditoría detallando el total liquidado.
*   **Validaciones y Mensajes**:
    *   *"No hay comisiones acumuladas pendientes de pago para este usuario"* (Mensaje informativo).
    *   *"Regalías marcadas como pagadas correctamente"* (Toast verde de confirmación).

#### I. Bitácora de Auditoría (`bitacora_admin.php`)
*   **Perfil al que pertenece**: Administrador.
*   **Objetivo**: Supervisar el registro de logs del sistema de forma inmutable, con fines de auditoría y detección de accesos o fallos.
*   **Campos de Entrada y Filtros**:
    *   Filtro por módulo (Usuarios, Clientes, Inventario, Logística, Regalías, Producción, Pedidos).
    *   Filtro por rango de fechas (Fecha Inicio / Fecha Fin).
    *   Paginación dinámica integrada para navegación ágil.
*   **Botones y Acciones Disponibles**:
    *   `Filtrar Bitácora`: Aplica los criterios de búsqueda sobre la tabla de auditoría.
    *   `Limpiar Filtros`: Restablece la vista general con los logs más recientes.
*   **Flujo Paso a Paso**:
    1. El administrador accede al módulo de bitácora. La pantalla realiza la consulta SQL filtrada por los parámetros seleccionados.
    2. El backend devuelve los logs ordenados cronológicamente mostrando el usuario que realizó la acción, la IP (si aplica), la fecha, hora, módulo afectado y la descripción de la operación.
*   **Validaciones y Mensajes**:
    *   *"Mostrando registros del DD/MM/AAAA al DD/MM/AAAA"* (Texto de control sobre la tabla).

#### J. Reportes Administrativos (`reportes_admin.php`)
*   **Perfil al que pertenece**: Administrador.
*   **Objetivo**: Generar análisis estadísticos financieros y de rendimiento avícola, permitiendo exportar la información.
*   **Campos e Inputs**:
    *   Selector de tipo de reporte (Ventas, Producción, Mermas, Regalías).
    *   Selector de fechas para el rango del informe.
*   **Botones y Acciones Disponibles**:
    *   `Consultar Reporte`: Renderiza gráficos dinámicos e histogramas del período seleccionado.
    *   `Exportar a CSV`: Descarga el archivo de hoja de cálculo delimitado por comas a través del script `forms/exportar_reporte.php`.
*   **Flujo Paso a Paso**:
    1. El administrador define el rango de fechas e indica el reporte que desea analizar.
    2. El backend realiza agregaciones SQL para calcular los totales de ventas e inventario del rango definido.
    3. Si presiona `Exportar a CSV`, el script limpia el buffer de salida de PHP, añade las cabeceras `Content-Type: text/csv` y escribe los registros correspondientes en un archivo descargable.
*   **Validaciones y Mensajes**:
    *   *"El rango de fechas seleccionado es inválido"* (Error si la fecha de inicio es posterior a la de fin).

---

### 5.3 MÓDULO DEL CLIENTE

#### A. Dashboard y Catálogo de Compra (`dashboard_cliente.php`)
*   **Perfil al que pertenece**: Cliente (`rol_id = 2`).
*   **Objetivo**: Permitir al cliente navegar por los productos, gestionar su carrito y realizar el checkout de manera rápida y segura.
*   **Campos de Entrada (Checkout y Carrito)**:
    *   `calle_numero` (Texto, Obligatorio): Dirección física de entrega.
    *   `colonia` (Texto, Obligatorio): Colonia/Vecindario.
    *   `codigo_postal` (Texto Numérico de 5 dígitos, Obligatorio): Código postal de México.
    *   `ciudad` (Texto, Obligatorio): Ciudad del cliente.
    *   `estado_mexico` (Selector, Obligatorio): Desglose de los 32 estados de la República Mexicana.
    *   `metodo_pago` (Selector, Obligatorio): Tarjeta de Crédito, Débito, Pago contra entrega (Contra reembolso).
    *   `cupon_descuento` (Texto, Opcional): Código de descuento manual (ej. `ECO20`, `FRESCO10`).
*   **Botones y Acciones Disponibles**:
    *   `Añadir al Carrito`: Suma unidades del producto seleccionado al almacenamiento local.
    *   `Aplicar Cupón`: Ejecuta validación asíncrona del cupón, recalculando el descuento en pantalla.
    *   `Proceder al Pago`: Abre el modal de checkout y dirección estructurada.
    *   `Confirmar Pedido`: Ejecuta la transacción de compra en el backend.
    *   `Cancelar Pedido` (en historial): Permite cancelar pedidos que aún se encuentren en estado `pendiente`.
*   **Flujo Paso a Paso**:
    1. El cliente agrega productos al carrito y presiona `Proceder al Pago`.
    2. Ingresa su dirección y método de pago. El sistema valida los datos de dirección en el cliente.
    3. Al presionar `Confirmar Pedido`, se activa el **Doble Bloqueo de Checkout** (se deshabilita el botón de pago y se muestra un indicador de carga).
    4. El script `forms/procesar_pedido.php` inicia una transacción de base de datos.
    5. Utiliza el algoritmo **FIFO** (First In, First Out) ordenando los lotes de huevo en el CEDIS por fecha de postura (más antiguo primero) y va restando la cantidad requerida del stock disponible.
    6. Calcula el IVA, el subtotal, aplica el cupón de descuento y registra el pedido en `pedidos` y `detalle_pedido`.
    7. Si la cuenta del cliente fue recomendada por otro usuario, se calcula una regalía del 10% del total cobrado y se registra en la tabla `regalias`.
    8. La transacción se confirma (`commit`) y se redirige a la pantalla de confirmación.
*   **Validaciones y Mensajes**:
    *   *"Procesando su pago... Por favor espere y no cierre esta ventana"* (Bloqueo visual del checkout).
    *   *"¡Pedido confirmado! Su número de pedido es #PED-XXX"* (Mensaje de confirmación).
    *   *"Lo sentimos, no hay stock suficiente de huevos frescos en este momento"* (Transacción revertida si el stock del CEDIS es insuficiente).

#### B. Asistente Virtual "Doña Ali"
*   **Perfil al que pertenece**: Cliente y Granjero.
*   **Objetivo**: Facilitar la navegación del catálogo de compras y el registro de procesos a usuarios de la tercera edad o con debilidad visual, utilizando tecnologías de voz y accesibilidad.
*   **Botones e Interacciones**:
    *   `👵 Botón Flotante Doña Ali` (Esquina inferior derecha): Despliega la burbuja de conversación del asistente.
    *   `🔊 Escuchar`: Lee la respuesta en pantalla en voz alta.
    *   `🎙️ Hablarle`: Activa el micrófono para recibir comandos de voz.
    *   Preguntas rápidas: Enlaces de clic directo para responder preguntas frecuentes sobre el funcionamiento del sistema.
*   **Flujo Paso a Paso**:
    1. El usuario hace clic en el botón circular de Doña Ali. La burbuja de diálogo se abre y le da la bienvenida con síntesis de voz en español.
    2. El usuario puede hacer clic en una pregunta sugerida o presionar `Hablarle` y autorizar el micrófono del navegador.
    3. El sistema procesa el dictado de voz y busca palabras clave (ej. "huevo", "compra", "regalías", "postura", "lote").
    4. Devuelve la respuesta redactada en pantalla y la lee automáticamente con síntesis de voz femenina configurada para simular la personalidad de Doña Ali.
*   **Validaciones y Mensajes**:
    *   *"Tu navegador no soporta el reconocimiento de voz. Te recomiendo usar Google Chrome"* (Advertencia si la API no está disponible).
    *   *"🎙️ Escuchando..."* (Indicador de color rojo parpadeante cuando el micrófono está capturando audio).

---

### 5.4 MÓDULO DEL PROVEEDOR (GRANJERO)

#### A. Portal del Proveedor (`dashboard_proveedor.php`)
*   **Perfil al que pertenece**: Granjero Proveedor (`rol_id = 3`).
*   **Objetivo**: Monitorear el estado de su inventario local en granja, sus lotes activos y los envíos realizados al CEDIS.
*   **Campos de Visualización**:
    *   Resumen de Lotes en Almacén Propio, Lotes Caducados (excedieron los 3 días en granja) e Ingresos Acumulados por Ventas.
    *   Alertas de stock de insumos (cartones de empaque por granja avícola).
*   **Botones y Acciones Disponibles**:
    *   Navegación lateral fija: Registrar Postura, Mis Lotes, Enviar al CEDIS, Origen y Calidad, Reportes.

#### B. Registro de Postura y Cosecha Diaria (`produccion_proveedor.php`)
*   **Perfil al que pertenece**: Granjero Proveedor (`rol_id = 3`).
*   **Objetivo**: Permitir al proveedor registrar la producción diaria de huevos recolectada en sus granjas de forma unificada.
*   **Campos de Entrada (Formulario Unificado)**:
    *   `granja_id` (Selector, Obligatorio): Granja avícola de donde provienen los huevos.
    *   `fecha_produccion` (Fecha, Obligatorio): Fecha en la que se realizó la postura.
    *   **Sección Huevos Chicos**:
        *   `cantidad_chico` (Numérico, Obligatorio): Unidades de tamaño chico recolectadas.
        *   `no_viable_chico` (Numérico, Opcional): Unidades descartadas por problemas de cáscara o pigmentación.
        *   `merma_chico` (Numérico, Opcional): Unidades rotas o dañadas en recolección.
    *   **Sección Huevos Medianos**:
        *   `cantidad_mediano` (Numérico, Obligatorio).
        *   `no_viable_mediano` (Numérico, Opcional).
        *   `merma_mediano` (Numérico, Opcional).
    *   **Sección Huevos Jumbo**:
        *   `cantidad_jumbo` (Numérico, Obligatorio).
        *   `no_viable_jumbo` (Numérico, Opcional).
        *   `merma_jumbo` (Numérico, Opcional).
*   **Botones y Acciones Disponibles**:
    *   `Registrar Producción Diaria`: Envía el formulario para su procesamiento.
    *   `Administrar Insumos` (en sección de granjas): Permite recargar cartones de empaque.
*   **Flujo Paso a Paso**:
    1. El proveedor ingresa a la pantalla de producción.
    2. El sistema calcula de forma automática cuántos cartones requerirá para empacar las unidades totales ingresadas (30 huevos por cartón).
    3. Si la granja seleccionada no cuenta con cartones suficientes en su campo `stock_cartones`, el sistema bloquea el guardado.
    4. Al guardar, el controlador `forms/procesar_produccion.php` ejecuta una transacción. Resta los cartones utilizados de la granja y crea un lote por cada tamaño de huevo ingresado con cantidad mayor a cero.
    5. Cada lote se inserta en `inventario_huevos` con su código único de trazabilidad autogenerado (`GRJ{clave}-{fecha}-{consecutivo}`), y se calcula su fecha de caducidad exacta sumando 3 días a la fecha de postura.
*   **Validaciones y Mensajes**:
    *   *"Insumos insuficientes: Esta postura requiere X cartones, pero la granja solo cuenta con Y disponibles"* (Alerta de error roja).
    *   *"¡Producción y lotes creados de forma exitosa! Se generaron los lotes: LOTE-XXX"* (Confirmación verde).

#### C. Solicitudes de Envíos al CEDIS (`entregas_proveedor.php`)
*   **Perfil al que pertenece**: Granjero Proveedor (`rol_id = 3`).
*   **Objetivo**: Solicitar la recogida física de lotes empacados y su envío a los Centros de Distribución de EcoAli.
*   **Campos de Entrada (Modal de Solicitud)**:
    *   `cedis_id` (Selector, Obligatorio): CEDIS de destino para los huevos.
    *   `fecha_recoleccion` (Fecha, Obligatorio): Fecha solicitada para el paso del camión de reparto.
    *   Lista de Lotes Disponibles (Checkboxes): Permite marcar los lotes que se enviarán y capturar la cantidad a enviar por cada uno.
    *   `observaciones` (Texto, Opcional): Notas para el transportista (ej. pallet de huevos frágiles, requiere rampa).
*   **Botones y Acciones Disponibles**:
    *   `Solicitar Recogida de Huevos`: Abre el modal de creación de solicitud.
    *   `Solicitar Entrega` (dentro de formulario): Registra el envío.
    *   `Cancelar` (en fila de tabla de envíos): Cancela una solicitud en estado `pendiente`.
*   **Flujo Paso a Paso**:
    1. El granjero selecciona los lotes y digita las cantidades a enviar.
    2. El backend valida en una transacción que los lotes tengan stock suficiente.
    3. Crea la cabecera en `entregas_cedis` en estado `pendiente` y los detalles en `detalle_entrega_cedis`.
    4. Descuenta las cantidades del stock en granja de los lotes seleccionados. Si un lote queda en 0 unidades disponibles, actualiza su estado a `pendiente_entrega` para sacarlo de circulación.
    5. Si el granjero cancela una entrega pendiente, el sistema devuelve los huevos a los lotes avícolas correspondientes en granja, restaurando su estado a `activo`.
*   **Validaciones y Mensajes**:
    *   *"La cantidad a enviar supera el stock disponible en el lote"* (Mensaje de error del servidor).
    *   *"No se puede cancelar esta entrega porque su estado es: EN RUTA / RECIBIDO"* (Bloqueo si el transportista ya procesó la carga).

#### D. Origen y Calidad / Trazabilidad (`trazabilidad_proveedor.php`)
*   **Perfil al que pertenece**: Granjero Proveedor.
*   **Objetivo**: Visualizar la cadena de custodia completa que ha seguido un lote de huevos en tiempo real.
*   **Campos de Búsqueda**:
    *   `lote_code` (Selector / Texto): Código de lote único que se desea rastrear.
*   **Botones y Acciones Disponibles**:
    *   `Buscar 🔍`: Ejecuta la consulta de trazabilidad.
*   **Flujo Paso a Paso (Línea de Tiempo del Lote)**:
    1. El usuario selecciona un lote de la lista desplegable y presiona buscar.
    2. El sistema recupera la información de origen: Granja donde se puso, fecha de postura, peso y tamaño de huevo, y cartones consumidos.
    3. Si el lote fue enviado, muestra los datos del transportista asignado, la fecha de recogida y el número de entrega.
    4. Muestra la fase final de recepción en el CEDIS, incluyendo la fecha/hora de auditoría y si fue **Aprobado** o **Rechazado** por el personal administrativo (con motivo de rechazo visible si aplica).

---

### 5.5 MÓDULO DEL REPARTIDOR (LOGÍSTICA)

#### A. Portal del Repartidor (`dashboard_repartidor.php`)
*   **Perfil al que pertenece**: Chofer Repartidor (`rol_id = 4`).
*   **Objetivo**: Visualizar y realizar el despacho de su Hoja de Ruta de pedidos en una interfaz móvil optimizada para conducción en ruta.
*   **Campos y Componentes Visuales**:
    *   Hoja de Ruta Interactiva (lista ordenada de paradas con direcciones de entrega, teléfono del cliente y detalles de carga).
    *   Mapa Bing Maps integrado en estilo oscuro (`canvasDark`) para guiar al conductor.
    *   Tarjeta de métricas de rendimiento diario (Completadas, En Ruta, Preparadas).
*   **Botones y Acciones Disponibles**:
    *   `Iniciar Ruta`: Cambia el estado del pedido asignado a `en_ruta`.
    *   `Confirmar Entrega`: Abre el modal de recolección de evidencias.
    *   `Reportar Incidencia`: Abre el modal de reporte de contratiempos en ruta.

#### B. Modal de Confirmación de Entrega (Evidencias de Entrega)
*   **Perfil al que pertenece**: Chofer Repartidor.
*   **Objetivo**: Capturar evidencias obligatorias de recepción física del producto en el domicilio del cliente.
*   **Campos de Entrada (Formulario de Evidencia)**:
    *   `coordenadas` (Texto, Capturado automáticamente): Campo oculto que guarda la latitud y longitud GPS del dispositivo móvil.
    *   `firma` (Canvas HTML5, Obligatorio): Panel táctil interactivo donde el cliente escribe su firma digital.
    *   `foto` (Imagen Base64, Obligatorio): Carga obligatoria de la foto física de los paquetes entregados.
*   **Botones y Acciones Disponibles**:
    *   `Limpiar Firma`: Borra el trazo del Canvas HTML5 para volver a firmar.
    *   `Tomar Foto / Seleccionar Foto`: Abre la cámara del dispositivo móvil.
    *   `Confirmar Entrega y Cobro`: Envía las evidencias al servidor.
*   **Flujo Paso a Paso**:
    1. Al llegar con el cliente, el repartidor presiona `Confirmar Entrega`.
    2. El script de JavaScript solicita acceso a la geolocalización del dispositivo y llena el campo de coordenadas GPS en segundo plano.
    3. El cliente firma sobre el panel táctil. El repartidor toma una fotografía del producto que se codifica automáticamente a formato Base64 en el cliente.
    4. El repartidor presiona `Confirmar Entrega y Cobro`. El script `forms/actualizar_estado_entrega.php` actualiza el pedido a `entregado`, registra la fecha y hora exacta, aprueba el estado del pago si era contra entrega y guarda las coordenadas, la firma y la foto en la base de datos.
*   **Validaciones y Mensajes**:
    *   *"Es obligatorio capturar la firma del cliente antes de confirmar"* (Bloqueo en el cliente).
    *   *"Es obligatorio adjuntar una fotografía como evidencia de entrega"* (Bloqueo en el cliente).
    *   *"Geolocalización GPS capturada con éxito"* (Pequeño check verde de éxito).

#### C. Reporte de Incidencias en Ruta y FIFO Restore
*   **Perfil al que pertenece**: Chofer Repartidor.
*   **Objetivo**: Reportar contratiempos graves que impiden completar la entrega, cancelando el pedido y reincorporando el producto de forma segura al stock.
*   **Campos de Entrada**:
    *   `tipo_incidencia` (Selector, Obligatorio): Cliente ausente, Dirección incorrecta, Producto dañado, Vehículo averiado.
    *   `descripcion` (Texto, Obligatorio): Detalles del suceso.
*   **Botones y Acciones Disponibles**:
    *   `Registrar Incidencia y Cancelar Pedido`: Confirma la cancelación de la parada logística.
*   **Flujo Paso a Paso**:
    1. Si el repartidor no localiza al cliente, presiona `Reportar Incidencia`. Selecciona el motivo e ingresa el detalle.
    2. Al confirmar, el controlador `forms/actualizar_estado_entrega.php` con la acción `cancelado` inicia una transacción.
    3. Cambia el estado del pedido a `cancelado`.
    4. Ejecuta el algoritmo **FIFO Restore**: Consulta los artículos y cantidades contenidas en `detalle_pedido`. Busca el lote de inventario del producto más reciente en el CEDIS y le suma las unidades devueltas, reajustando su estado de inventario para evitar pérdidas de stock.
    5. Registra la incidencia y la reincorporación de productos en la bitácora de auditoría.
*   **Validaciones y Mensajes**:
    *   *"El pedido ha sido cancelado y los productos reincorporados al inventario de forma exitosa"* (Notificación al repartidor).

---

## ⚙️ 6. REGLAS DE NEGOCIO AUTOMATIZADAS

El sistema cuenta con procesos inteligentes que corren de forma automática en el servidor para garantizar la calidad y la frescura del producto:

1.  **Regla de Caducidad de Lotes (3 días)**: Al establecer la conexión a la base de datos (`forms/conexion.php`), el sistema ejecuta de forma automática una consulta que identifica lotes de huevo en stock en granja cuya fecha de postura tenga más de 3 días de antigüedad. El sistema cambia su estado a `caducado` bloqueándolos para su venta al público o envío al CEDIS, registrando el suceso en la bitácora de auditoría detallando los lotes desechados.
2.  **Consumo de Cartones en Producción**: Cada registro de postura valida que el granjero tenga suficientes cartones de empaque en la granja seleccionada. El sistema deduce de forma automática 1 cartón por cada 30 huevos registrados.
3.  **Algoritmo de Salida de Stock (FIFO)**: Al registrarse una compra en la plataforma, el sistema recorre los lotes del producto seleccionado disponibles en el CEDIS ordenándolos de más antiguo a más reciente. Consume primero el stock de los lotes más antiguos para asegurar que el inventario rote correctamente y el consumidor reciba el producto más fresco.
4.  **Algoritmo de Reincorporación (FIFO Restore)**: Al cancelarse un pedido en el portal de clientes o por incidencias del repartidor, el sistema identifica el lote más reciente en el CEDIS para el producto cancelado e incrementa su stock disponible con las unidades devueltas, manteniendo la consistencia de inventario de forma automática.
5.  **Cálculo Automático de Regalías (10%)**: Al procesarse una compra confirmada, el sistema identifica si el cliente cuenta con un `usuario_referido_id` registrado (quien lo recomendó). Si existe, el sistema calcula de forma automática una regalía equivalente al 10% del total de la compra y se la abona en estado `pendiente` en la tabla `regalias`, a la espera de ser cobrada.

---

## 📝 7. OBSERVACIONES Y PENDIENTES DE DESARROLLO

Actualmente, el sistema se encuentra completamente operativo para las pruebas de entrega del proyecto. No obstante, se han identificado las siguientes oportunidades de mejora y pendientes para futuras versiones:

1.  **Módulo de Cupones y Promociones en Backend**: Aunque las tablas `cupones` y `promociones` se crean y se siembran de forma automática, la creación, edición y eliminación de cupones por parte del administrador se realiza directamente en la base de datos. Se requiere diseñar una interfaz gráfica CRUD en el portal de administración para la gestión de cupones y promociones.
2.  **Pasarela de Pago Real**: La pasarela de pago del cliente simula el cobro de la tarjeta de crédito o débito mediante validaciones sintácticas. En una fase de producción, se requiere integrar la API de Stripe, PayPal o MercadoPago para transacciones reales.
3.  **Monitoreo GPS en Vivo**: El repartidor registra sus coordenadas GPS en el momento exacto en que confirma la entrega física o reporta una incidencia. En futuras etapas, se puede implementar un script de fondo en el cliente móvil que reporte las coordenadas cada 5 minutos para permitir al administrador y al cliente realizar el seguimiento del camión repartidor en un mapa en tiempo real.
4.  **Optimización de Cuentas de Insumos**: El reabastecimiento de cartones por parte del proveedor se efectúa manualmente desde el panel de perfil del proveedor. Se sugiere añadir un flujo de compras de cartones al administrador para formalizar la cadena de suministro de empaques de EcoAli.
