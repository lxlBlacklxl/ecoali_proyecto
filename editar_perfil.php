<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - EDICIÓN DE PERFIL Y CONFIGURACIÓN DE GRANJAS DEL PROVEEDOR
 * --------------------------------------------------------------------------------
 */

session_start();
require_once "forms/conexion.php";

// 1. CONTROL DE ACCESO
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 3) {
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION["usuario_id"];

$mensaje_exito = "";
$mensaje_error = "";

// 2. PROCESAR ACTUALIZACIÓN DE PERFIL (POST)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"]) && $_POST["accion"] === "actualizar_perfil") {
    $nombre = trim($_POST["nombre"] ?? "");
    $apellido = trim($_POST["apellido"] ?? "");
    $telefono = trim($_POST["telefono"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $direccion = trim($_POST["direccion"] ?? "");
    
    $nombre_empresa = trim($_POST["nombre_empresa"] ?? "");
    $contacto = trim($_POST["contacto"] ?? "");
    $telefono_prov = trim($_POST["telefono_proveedor"] ?? "");
    $ubicacion_prov = trim($_POST["ubicacion_proveedor"] ?? "");
    
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (empty($nombre) || empty($apellido) || empty($email) || empty($nombre_empresa) || empty($contacto)) {
        $mensaje_error = "Por favor complete todos los campos obligatorios (*).";
    } elseif (!empty($password) && $password !== $confirm_password) {
        $mensaje_error = "Las contraseñas no coinciden.";
    } else {
        $conn->begin_transaction();
        try {
            // A. Actualizar usuario_perfil
            $stmtUp = $conn->prepare("UPDATE usuario_perfil SET nombre = ?, apellido = ?, telefono = ?, email = ?, direccion = ? WHERE usuario_id = ?");
            $stmtUp->bind_param("sssssi", $nombre, $apellido, $telefono, $email, $direccion, $usuario_id);
            $stmtUp->execute();
            $stmtUp->close();

            // B. Actualizar proveedores
            $stmtProv = $conn->prepare("UPDATE proveedores SET nombre_empresa = ?, contacto = ?, telefono = ?, ubicacion = ? WHERE usuario_id = ?");
            $stmtProv->bind_param("ssssi", $nombre_empresa, $contacto, $telefono_prov, $ubicacion_prov, $usuario_id);
            $stmtProv->execute();
            $stmtProv->close();

            // C. Si ingresó contraseña
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmtUsr = $conn->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
                $stmtUsr->bind_param("si", $password_hash, $usuario_id);
                $stmtUsr->execute();
                $stmtUsr->close();
            }

            // Actualizar datos de sesión
            $_SESSION["nombre"] = $contacto;

            registrar_bitacora("Perfil actualizado", "Proveedores", "El proveedor '$nombre_empresa' actualizó su perfil y datos de contacto.");
            $conn->commit();
            $mensaje_exito = "¡Perfil corporativo y personal actualizado con éxito!";
        } catch (Exception $e) {
            $conn->rollback();
            $mensaje_error = "Error al actualizar: " . $e->getMessage();
        }
    }
}

// 3. CONSULTAR DATOS DEL PROVEEDOR
// Datos de proveedores
$stmtProv = $conn->prepare("SELECT * FROM proveedores WHERE usuario_id = ?");
$stmtProv->bind_param("i", $usuario_id);
$stmtProv->execute();
$resProv = $stmtProv->get_result();
if ($resProv->num_rows === 0) {
    die("Error: Perfil de proveedor no encontrado.");
}
$proveedor = $resProv->fetch_assoc();
$proveedor_id = (int)$proveedor["id"];
$nombre_empresa = $proveedor["nombre_empresa"];
$stmtProv->close();

// Datos de usuario_perfil
$stmtUp = $conn->prepare("SELECT * FROM usuario_perfil WHERE usuario_id = ?");
$stmtUp->bind_param("i", $usuario_id);
$stmtUp->execute();
$resUp = $stmtUp->get_result();
$perfil = $resUp->fetch_assoc();
$stmtUp->close();

// Granjas del Proveedor
$granjas = [];
$stmtG = $conn->prepare("SELECT * FROM granjas WHERE proveedor_id = ? ORDER BY id DESC");
$stmtG->bind_param("i", $proveedor_id);
$stmtG->execute();
$resG = $stmtG->get_result();
while ($row = $resG->fetch_assoc()) {
    $granjas[] = $row;
}
$stmtG->close();

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mi Perfil y Granjas - ECOALI</title>
  <link rel="stylesheet" href="assets/css/globals.css">
  <link rel="stylesheet" href="assets/css/proveedor.css?v=<?php echo time(); ?>">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
</head>
<body>

<div class="provider-container">
  
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand">🌱 ECOALI</div>

    <div class="profile-card">
      <div class="avatar">👨‍🌾</div>
      <div class="info">
        <h4><?php echo htmlspecialchars($nombre_empresa); ?></h4>
        <p>Granjero Proveedor</p>
      </div>
    </div>

    <nav class="sidebar-menu">
      <a href="dashboard_proveedor.php" class="menu-link <?php echo ($current_page === 'dashboard_proveedor.php') ? 'active' : ''; ?>">
        <span>▦</span> <span>Dashboard</span>
      </a>
      <a href="produccion_proveedor.php" class="menu-link <?php echo ($current_page === 'produccion_proveedor.php') ? 'active' : ''; ?>">
        <span>🚜</span> <span>Producción</span>
      </a>
      <a href="lotes_proveedor.php" class="menu-link <?php echo ($current_page === 'lotes_proveedor.php') ? 'active' : ''; ?>">
        <span>▣</span> <span>Lotes</span>
      </a>
      <a href="inventario_proveedor.php" class="menu-link <?php echo ($current_page === 'inventario_proveedor.php') ? 'active' : ''; ?>">
        <span>📦</span> <span>Inventario</span>
      </a>
      <a href="entregas_proveedor.php" class="menu-link <?php echo ($current_page === 'entregas_proveedor.php') ? 'active' : ''; ?>">
        <span>🚚</span> <span>Entregas al CEDIS</span>
      </a>
      <a href="trazabilidad_proveedor.php" class="menu-link <?php echo ($current_page === 'trazabilidad_proveedor.php') ? 'active' : ''; ?>">
        <span>🔀</span> <span>Trazabilidad</span>
      </a>
      <a href="reportes_proveedor.php" class="menu-link <?php echo ($current_page === 'reportes_proveedor.php') ? 'active' : ''; ?>">
        <span>📊</span> <span>Reportes</span>
      </a>
      <a href="editar_perfil.php" class="menu-link <?php echo ($current_page === 'editar_perfil.php') ? 'active' : ''; ?>">
        <span>👤</span> <span>Mi perfil</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" style="text-decoration:none;">
        <button class="logout-btn">
          <span>⤶</span> Cerrar Sesión
        </button>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">
    <header class="app-header">
      <div>
        <h1>Configuración de Cuenta</h1>
        <p>Edite su información de perfil avícola y administre el stock de insumos de sus granjas.</p>
      </div>
    </header>

    <!-- Notificaciones -->
    <?php if (!empty($mensaje_exito)): ?>
      <div class="alert-container">
        <div class="alert alert-success"><span>✓</span> <?php echo htmlspecialchars($mensaje_exito); ?></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($mensaje_error)): ?>
      <div class="alert-container">
        <div class="alert alert-danger"><span>✗</span> <?php echo htmlspecialchars($mensaje_error); ?></div>
      </div>
    <?php endif; ?>

    <div class="dashboard-layout" style="grid-template-columns: 1.2fr 1fr; gap: 30px;">
      
      <!-- Izquierda: Formulario de Perfil -->
      <div class="card" style="margin-bottom:0;">
        <h3>Mi Perfil de Proveedor</h3>
        
        <form action="editar_perfil.php" method="POST">
          <input type="hidden" name="accion" value="actualizar_perfil">

          <div class="form-grid">
            <h4 style="grid-column: 1 / -1; font-size: 12px; text-transform: uppercase; color: var(--secondary); margin-bottom: 8px; border-bottom: 1px solid var(--glass-border); padding-bottom:4px;">Datos Personales</h4>
            
            <div class="form-group">
              <label>Nombre *</label>
              <input type="text" name="nombre" value="<?php echo htmlspecialchars($perfil["nombre"] ?? ""); ?>" required>
            </div>

            <div class="form-group">
              <label>Apellidos *</label>
              <input type="text" name="apellido" value="<?php echo htmlspecialchars($perfil["apellido"] ?? ""); ?>" required>
            </div>

            <div class="form-group">
              <label>Teléfono Personal</label>
              <input type="text" name="telefono" value="<?php echo htmlspecialchars($perfil["telefono"] ?? ""); ?>">
            </div>

            <div class="form-group">
              <label>Correo Electrónico *</label>
              <input type="email" name="email" value="<?php echo htmlspecialchars($perfil["email"] ?? ""); ?>" required>
            </div>

            <div class="form-group full-width">
              <label>Dirección Personal / Fiscal</label>
              <input type="text" name="direccion" value="<?php echo htmlspecialchars($perfil["direccion"] ?? ""); ?>">
            </div>

            <h4 style="grid-column: 1 / -1; font-size: 12px; text-transform: uppercase; color: var(--secondary); margin-top:16px; margin-bottom: 8px; border-bottom: 1px solid var(--glass-border); padding-bottom:4px;">Datos de la Empresa / Productora</h4>

            <div class="form-group">
              <label>Nombre de la Empresa o Granja *</label>
              <input type="text" name="nombre_empresa" value="<?php echo htmlspecialchars($proveedor["nombre_empresa"] ?? ""); ?>" required>
            </div>

            <div class="form-group">
              <label>Persona de Contacto / Granjero *</label>
              <input type="text" name="contacto" value="<?php echo htmlspecialchars($proveedor["contacto"] ?? ""); ?>" required>
            </div>

            <div class="form-group">
              <label>Teléfono Corporativo</label>
              <input type="text" name="telefono_proveedor" value="<?php echo htmlspecialchars($proveedor["telefono"] ?? ""); ?>">
            </div>

            <div class="form-group">
              <label>Ubicación Geográfica Principal</label>
              <input type="text" name="ubicacion_proveedor" value="<?php echo htmlspecialchars($proveedor["ubicacion"] ?? ""); ?>">
            </div>

            <h4 style="grid-column: 1 / -1; font-size: 12px; text-transform: uppercase; color: var(--secondary); margin-top:16px; margin-bottom: 8px; border-bottom: 1px solid var(--glass-border); padding-bottom:4px;">Seguridad</h4>

            <div class="form-group">
              <label>Nueva Contraseña (Opcional)</label>
              <input type="password" name="password" placeholder="Mínimo 6 caracteres">
            </div>

            <div class="form-group">
              <label>Confirmar Contraseña</label>
              <input type="password" name="confirm_password" placeholder="Confirmar contraseña">
            </div>

            <button type="submit" class="btn-submit full-width" style="grid-column: 1 / -1; margin-top: 15px;">Guardar Perfil</button>
          </div>
        </form>
      </div>

      <!-- Derecha: Gestión de Granjas -->
      <div style="display:flex; flex-direction:column; gap:30px;">
        
        <!-- Formulario registrar Granja -->
        <div class="card" style="margin-bottom:0;">
          <h3>Registrar Nueva Granja</h3>
          <form id="form-registrar-granja" onsubmit="registrarGranja(event)">
            <div class="form-grid" style="grid-template-columns: 1fr;">
              <div class="form-group">
                <label>Nombre de la Granja</label>
                <input type="text" id="g_nombre" placeholder="Ej: Granja El Nido" required>
              </div>

              <div class="form-group">
                <label>Código de Registro / Identificación</label>
                <input type="text" id="g_identificacion" placeholder="Ej: ES-SE-41001" required>
              </div>

              <div class="form-group">
                <label>Ubicación de la Granja</label>
                <input type="text" id="g_ubicacion" placeholder="Ej: Sevilla, España" required>
              </div>

              <button type="submit" class="btn-submit" style="margin-top: 8px;">Añadir Granja</button>
            </div>
          </form>
        </div>

        <!-- Listado de granjas -->
        <div class="card" style="margin-bottom:0;">
          <h3>Granjas Registradas</h3>
          <div style="display:flex; flex-direction:column; gap:16px;" id="lista-granjas-wrapper">
            <?php if (!empty($granjas)): ?>
              <?php foreach ($granjas as $g): ?>
                <div class="farm-card" id="granja_card_<?php echo $g['id']; ?>">
                  <span class="badge-id"><?php echo htmlspecialchars($g["identificacion"]); ?></span>
                  <div style="font-size:24px; margin-bottom:8px;">🚜</div>
                  <h4><?php echo htmlspecialchars($g["nombre"]); ?></h4>
                  <p>📍 <?php echo htmlspecialchars($g["ubicacion"]); ?></p>
                  
                  <!-- Abastecimiento de Cartones -->
                  <div style="margin-top:12px; padding:10px; background:rgba(213, 164, 112, 0.04); border-radius:10px; border:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                    <div>
                      <small style="font-size:9px; font-weight:800; color:var(--text-medium); display:block; text-transform:uppercase;">Stock de Cartones</small>
                      <strong style="font-size:13px; color:<?php echo $g['stock_cartones'] < 30 ? '#b02500' : 'var(--secondary)'; ?>;" id="stock_carton_<?php echo $g['id']; ?>"><?php echo $g['stock_cartones']; ?> uds</strong>
                    </div>
                    <button type="button" onclick="cargarInsumos(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars($g['nombre'], ENT_QUOTES); ?>')" style="background:var(--secondary); color:white; border:none; padding:6px 10px; border-radius:6px; font-size:10px; font-weight:800; cursor:pointer;">Abastecer</button>
                  </div>

                  <button type="button" class="btn-submit btn-danger" style="margin-top:12px; height:32px; font-size:11px; border-radius:8px;" onclick="eliminarGranja(<?php echo $g['id']; ?>)">Eliminar Granja</button>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div style="text-align: center; color: var(--text-medium); padding: 20px;" id="no-granjas-msg">No has registrado ninguna granja aún.</div>
            <?php endif; ?>
          </div>
        </div>

      </div>

    </div>

  </main>

  <!-- Mobile Nav -->
  <nav class="mobile-nav">
    <a href="dashboard_proveedor.php" class="mobile-nav-btn">
      <span>▦</span>
      <span>Dashboard</span>
    </a>
    <a href="produccion_proveedor.php" class="mobile-nav-btn">
      <span>🚜</span>
      <span>Producción</span>
    </a>
    <a href="lotes_proveedor.php" class="mobile-nav-btn">
      <span>▣</span>
      <span>Lotes</span>
    </a>
    <a href="entregas_proveedor.php" class="mobile-nav-btn">
      <span>🚚</span>
      <span>Entregas</span>
    </a>
    <a href="editar_perfil.php" class="mobile-nav-btn active">
      <span>👤</span>
      <span>Perfil</span>
    </a>
  </nav>

</div>

<!-- Modal Abastecimiento Insumos -->
<div class="modal-overlay" id="modalAbastecer">
  <div class="modal-container" style="max-width: 400px;">
    <div class="modal-header">
      <div class="modal-title">Cargar Cartones de Empaque</div>
      <button class="modal-close" onclick="cerrarModal('modalAbastecer')">×</button>
    </div>
    <form id="form-abastecer-insumos" onsubmit="submitAbastecer(event)">
      <input type="hidden" id="abast_id">
      <p style="font-size:13px; color:var(--text-medium); line-height:1.5; margin-bottom:15px;">
        Registrar ingreso de insumos para: <strong id="abast_nombre" style="color:var(--text-dark);">Granja</strong>
      </p>

      <div class="form-group" style="margin-bottom:15px;">
        <label>Cantidad de cartones a añadir</label>
        <input type="number" id="abast_cantidad" value="120" min="1" required>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalAbastecer')">Cancelar</button>
        <button type="submit" class="btn-submit">Añadir Stock</button>
      </div>
    </form>
  </div>
</div>

<!-- Scripts AJAX de Granjas -->
<script>
function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

function registrarGranja(e) {
    e.preventDefault();
    const nom = document.getElementById('g_nombre').value;
    const ide = document.getElementById('g_identificacion').value;
    const ubi = document.getElementById('g_ubicacion').value;

    fetch('forms/granjas_acciones.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accion: 'registrar', nombre: nom, identificacion: ide, ubicacion: ubi })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert(data.message);
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Error en la conexión.'));
}

function cargarInsumos(id, nombre) {
    document.getElementById('abast_id').value = id;
    document.getElementById('abast_nombre').textContent = nombre;
    document.getElementById('abast_cantidad').value = 120;
    document.getElementById('modalAbastecer').classList.add('active');
}

function submitAbastecer(e) {
    e.preventDefault();
    const id = document.getElementById('abast_id').value;
    const cant = document.getElementById('abast_cantidad').value;

    fetch('forms/granjas_acciones.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accion: 'abastecer_cartones', id: id, cantidad: cant })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            cerrarModal('modalAbastecer');
            alert(data.message);
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Error en la comunicación.'));
}

function eliminarGranja(id) {
    if (!confirm('¿Estás seguro de que deseas eliminar esta granja?')) return;

    fetch('forms/granjas_acciones.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accion: 'eliminar', id: id })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert(data.message);
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Error al procesar eliminación.'));
}
</script>

</body>
</html>
