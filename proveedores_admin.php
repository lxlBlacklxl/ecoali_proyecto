<?php
session_start();
require "forms/conexion.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

if ((int)$_SESSION["rol_id"] !== 1) {
    header("Location: login.php");
    exit;
}

$nombre = $_SESSION["nombre"] ?? "Admin";

// Consultas dinámicas para las tarjetas de estado
$totalRes = $conn->query("SELECT COUNT(*) FROM proveedores");
$totalProveedores = $totalRes ? $totalRes->fetch_row()[0] : 0;

$activosRes = $conn->query("SELECT COUNT(*) FROM proveedores WHERE estado = 'activo'");
$activosProveedores = $activosRes ? $activosRes->fetch_row()[0] : 0;

$inactivosRes = $conn->query("SELECT COUNT(*) FROM proveedores WHERE estado = 'inactivo'");
$inactivosProveedores = $inactivosRes ? $inactivosRes->fetch_row()[0] : 0;

// Obtener la lista de proveedores con datos de usuario y perfil
$queryProv = "SELECT p.*, u.usuario, up.email 
              FROM proveedores p 
              LEFT JOIN usuarios u ON p.usuario_id = u.id 
              LEFT JOIN usuario_perfil up ON u.id = up.usuario_id 
              ORDER BY p.id DESC";
$resultProv = $conn->query($queryProv);
$countProv = $resultProv ? $resultProv->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Proveedores - ECOALI</title>

<link rel="stylesheet" href="assets/css/globals.css">
<link rel="stylesheet" href="assets/css/inventario_admin.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<script src="assets/js/admin_menu.js" defer></script>
</head>

<body>
<div class="gestin-de-inventario">

  <div class="organic-background"></div>
  <div class="background-blur"></div>
  <div class="div"></div>

  <div class="aside">
    <div class="margin">
      <div class="container-13">
        <div class="div-4"><div class="text-27">ECOALI</div></div>
      </div>
    </div>

    <div class="margin-2">
      <div class="background-10">
        <div class="avatar"></div>
        <div class="div-4">
          <div class="div-2"><div class="text-28"><?php echo htmlspecialchars($nombre); ?></div></div>
          <div class="div-2"><div class="text-29">Gestión Pro</div></div>
        </div>
      </div>
    </div>

    <div class="nav">
      <?php
      $current_page = basename($_SERVER['PHP_SELF']);
      $menu_items = [
          'dashboard_admin.php' => 'Dashboard',
          'usuarios_admin.php' => 'Usuarios',
          'clientes_admin.php' => 'Clientes',
          'proveedores_admin.php' => 'Proveedores',
          'inventario_admin.php' => 'Inventario',
          'productos_admin.php' => 'Productos',
          'logistica_admin.php' => 'Logística',
          'reportes_admin.php' => 'Reportes',
          'regalias_admin.php' => 'Regalías',
          'bitacora_admin.php' => 'Bitácora'
      ];

      foreach ($menu_items as $href => $label) {
          $link_href = $href;
          $active = ($current_page === $href);
          
          if ($active) {
              echo '
              <a class="link-active-state" href="' . $link_href . '">
                <div class="link-active-state-2"></div>
                <div class="div-4"><div class="text-31">' . $label . '</div></div>
              </a>';
          } else {
              echo '
              <a class="link" href="' . $link_href . '">
                <div class="div-4"><div class="text-30">' . $label . '</div></div>
              </a>';
          }
      }
      ?>
    </div>

    <div class="button-wrapper">
      <a href="logout.php" class="button-5">
        <div class="container-3"><div class="text-32">Cerrar Sesión</div></div>
      </a>
    </div>
  </div>

  <div class="header-topappbar">
    <div class="div-4"><div class="text-26">Gestión de Proveedores</div></div>

    <button class="button-2" onclick="abrirModalCrear()"><div class="text-3">Agregar proveedor</div></button>
  </div>

  <div class="main-content">

    <!-- Notificaciones del sistema -->
    <?php if (isset($_SESSION["mensaje_exito"])): ?>
        <div class="alert-container">
            <div class="alert alert-success">
                <span>✓</span> <?php echo $_SESSION["mensaje_exito"]; unset($_SESSION["mensaje_exito"]); ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION["mensaje_error"])): ?>
        <div class="alert-container">
            <div class="alert alert-danger">
                <span>✗</span> <?php echo $_SESSION["mensaje_error"]; unset($_SESSION["mensaje_error"]); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="section-search">
      <div class="container">
        <div class="input">
          <div class="container">
            <input type="text" id="buscarProveedor" placeholder="Buscar por nombre, ID o ubicación..." onkeyup="filtrarTabla()" style="border:none; width:100%; outline:none; font-family:inherit; color:#462800; font-size:13px; font-weight:600;">
          </div>
        </div>
      </div>

      <div class="container-2">
        <div class="background-shadow">
          <button class="button" onclick="filtrarEstado('todos', this)"><div class="text">Todos</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('activo', this)"><div class="text-2">Activo</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('pendiente', this)"><div class="text-2">Pendiente</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('inactivo', this)"><div class="text-2">Inactivo</div></button>
        </div>
      </div>
    </div>

    <div class="status-overviews">
      <div class="background-border">
        <div class="container-5"><div class="text-wrapper-2">Total de Proveedores</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $totalProveedores; ?></div></div>
      </div>

      <div class="background-border-2">
        <div class="container-5"><div class="text-wrapper-2">Proveedores Activos</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $activosProveedores; ?></div></div>
      </div>

      <div class="background-border-3">
        <div class="container-5"><div class="text-wrapper-2">Proveedores Inactivos</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $inactivosProveedores; ?></div></div>
      </div>
    </div>

    <div class="inventory-table">
      <div class="header">
        <div class="row" style="grid-template-columns: 1fr 1.2fr 2fr 2fr 1.2fr 2fr 1.2fr 1.4fr;">
          <div><div class="text-7">ID</div></div>
          <div><div class="text-7">USUARIO</div></div>
          <div><div class="text-7">NOMBRE/EMPRESA</div></div>
          <div><div class="text-7">CORREO</div></div>
          <div><div class="text-7">TELÉFONO</div></div>
          <div><div class="text-8">DIRECCIÓN</div></div>
          <div><div class="text-7">ESTADO</div></div>
          <div><div class="text-9">ACCIONES</div></div>
        </div>
      </div>

      <div id="tablaCuerpo">
      <?php if ($countProv > 0): ?>
          <?php while ($row = $resultProv->fetch_assoc()): 
              $estado = strtolower($row["estado"]);
              $estadoClass = "overlay-6";
              $bgClass = "background-3";
              $estadoText = "Activo";

              if ($estado === "pendiente") {
                  $estadoClass = "overlay-7";
                  $bgClass = "background-5";
                  $estadoText = "Pendiente";
              } elseif ($estado === "inactivo") {
                  $estadoClass = "overlay-10";
                  $bgClass = "background-9";
                  $estadoText = "Inactivo";
              } elseif ($estado === "rechazado") {
                  $estadoClass = "overlay-9";
                  $bgClass = "background-7";
                  $estadoText = "Rechazado";
              }
          ?>
          <div class="div-3 row-proveedor" data-estado="<?php echo $estado; ?>" style="grid-template-columns: 1fr 1.2fr 2fr 2fr 1.2fr 2fr 1.2fr 1.4fr; border-bottom: 1px solid rgba(213,164,112,.12);">
            <div><div class="text-10">#PV-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?></div></div>
            <div><div class="text-11"><?php echo htmlspecialchars($row["usuario"] ?? "N/A"); ?></div></div>
            <div><div class="text-11" style="font-weight: 700;"><?php echo htmlspecialchars($row["nombre_empresa"]); ?></div></div>
            <div><div class="text-14" style="text-transform: none;"><?php echo htmlspecialchars($row["email"] ?? "N/A"); ?></div></div>
            <div><div class="text-11"><?php echo htmlspecialchars($row["telefono"] ?? "N/A"); ?></div></div>
            <div><div class="text-14"><?php echo htmlspecialchars($row["ubicacion"] ?? "N/A"); ?></div></div>
            <div>
              <div class="<?php echo $estadoClass; ?>">
                <div class="<?php echo $bgClass; ?>"></div>
                <div class="text-15" style="color: inherit;"><?php echo $estadoText; ?></div>
              </div>
            </div>
            <div>
              <div class="text-10" style="display: flex; gap: 6px; align-items: center;">
                <button class="action-btn action-btn-edit" title="Editar Proveedor" onclick="abrirModalEditar(<?php echo $row['id']; ?>)">✎</button>
                <button class="action-btn action-btn-delete" title="Eliminar Proveedor" onclick="confirmarEliminar(<?php echo $row['id']; ?>, '<?php echo addslashes($row['nombre_empresa']); ?>')">🗑</button>
                <a href="inventario_admin.php?buscar=<?php echo urlencode($row['nombre_empresa']); ?>" class="action-btn" title="Ver Stock e Inventario" style="text-decoration:none; background:#176a21; color:#fff; display:flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:8px; font-size:11px;">📦</a>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 30px;">
              <div class="text-11" style="color: #996e3f;">No hay proveedores registrados actualmente.</div>
          </div>
      <?php endif; ?>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p" id="paginacionTexto">MOSTRANDO <?php echo $countProv; ?> PROVEEDORES</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ==========================================
     MODALES (CREATE & EDIT)
     ========================================== -->

<!-- Modal Crear Proveedor -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Agregar Nuevo Proveedor</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    <form action="forms/proveedores_acciones.php" method="POST">
      <input type="hidden" name="accion" value="crear">
      
      <div class="form-group">
        <label class="form-label">Nombre de la Empresa *</label>
        <input type="text" name="nombre_empresa" class="form-input" required placeholder="Ej. AgroOrgánicos del Norte">
      </div>

      <div class="form-group">
        <label class="form-label">Nombre del Contacto</label>
        <input type="text" name="contacto" class="form-input" placeholder="Ej. Juan Pérez">
      </div>

      <div class="form-group">
        <label class="form-label">Teléfono</label>
        <input type="text" name="telefono" class="form-input" placeholder="Ej. +34 600 000 000">
      </div>

      <div class="form-group">
        <label class="form-label">Ubicación</label>
        <input type="text" name="ubicacion" class="form-input" placeholder="Ej. Sevilla, España">
      </div>

      <div class="form-group">
        <label class="form-label">Estado</label>
        <select name="estado" class="form-select">
          <option value="pendiente">Pendiente</option>
          <option value="activo" selected>Activo</option>
          <option value="inactivo">Inactivo</option>
          <option value="rechazado">Rechazado</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrear')">Cancelar</button>
        <button type="submit" class="btn-submit">Guardar Proveedor</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar Proveedor -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Editar Proveedor</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="forms/proveedores_acciones.php" method="POST">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">
      
      <div class="form-group">
        <label class="form-label">Nombre de la Empresa *</label>
        <input type="text" name="nombre_empresa" id="edit_nombre_empresa" class="form-input" required>
      </div>

      <div class="form-group">
        <label class="form-label">Nombre del Contacto</label>
        <input type="text" name="contacto" id="edit_contacto" class="form-input">
      </div>

      <div class="form-group">
        <label class="form-label">Teléfono</label>
        <input type="text" name="telefono" id="edit_telefono" class="form-input">
      </div>

      <div class="form-group">
        <label class="form-label">Ubicación</label>
        <input type="text" name="ubicacion" id="edit_ubicacion" class="form-input">
      </div>

      <div class="form-group">
        <label class="form-label">Estado</label>
        <select name="estado" id="edit_estado" class="form-select">
          <option value="pendiente">Pendiente</option>
          <option value="activo">Activo</option>
          <option value="inactivo">Inactivo</option>
          <option value="rechazado">Rechazado</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditar')">Cancelar</button>
        <button type="submit" class="btn-submit">Actualizar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Eliminar Proveedor -->
<div class="modal-overlay" id="modalEliminar">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Confirmar Eliminación</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="forms/proveedores_acciones.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="delete_id">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar al proveedor <strong id="delete_nombre" style="color: #462800;"></strong>?<br>Esta acción no se puede deshacer y puede afectar los lotes del inventario asociados.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 10px 25px rgba(176, 37, 0, 0.15);">Eliminar</button>
      </div>
    </form>
  </div>
</div>

<!-- ==========================================
     SCRIPTS DE CONTROL INTERACTIVO
     ========================================== -->
<script>
function abrirModalCrear() {
    document.getElementById('modalCrear').classList.add('active');
}

function abrirModalEditar(id) {
    // Cargar datos vía AJAX
    fetch('forms/proveedores_acciones.php?accion=obtener&id=' + id)
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('edit_id').value = res.data.id;
                document.getElementById('edit_nombre_empresa').value = res.data.nombre_empresa;
                document.getElementById('edit_contacto').value = res.data.contacto || '';
                document.getElementById('edit_telefono').value = res.data.telefono || '';
                document.getElementById('edit_ubicacion').value = res.data.ubicacion || '';
                document.getElementById('edit_estado').value = res.data.estado;
                
                document.getElementById('modalEditar').classList.add('active');
            } else {
                alert('Error al obtener datos del proveedor: ' + res.message);
            }
        })
        .catch(err => {
            alert('Error en la comunicación con el servidor.');
        });
}

function confirmarEliminar(id, nombre) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_nombre').textContent = nombre;
    document.getElementById('modalEliminar').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Búsqueda y filtros interactivos
function filtrarTabla() {
    const query = document.getElementById('buscarProveedor').value.toLowerCase();
    const rows = document.querySelectorAll('#tablaCuerpo .row-proveedor');
    let visibleCount = 0;

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(query)) {
            row.style.display = 'grid';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('paginacionTexto').textContent = `MOSTRANDO ${visibleCount} DE ${rows.length} PROVEEDORES`;
}

function filtrarEstado(estado, btn) {
    // Actualizar botones de filtro
    const buttons = document.querySelectorAll('.background-shadow button');
    buttons.forEach(b => {
        b.className = 'div-wrapper';
        if (b.querySelector('div')) {
            b.querySelector('div').className = 'text-2';
        }
    });

    btn.className = 'button';
    if (btn.querySelector('div')) {
        btn.querySelector('div').className = 'text';
    }

    const rows = document.querySelectorAll('#tablaCuerpo .row-proveedor');
    let visibleCount = 0;

    rows.forEach(row => {
        const rEstado = row.getAttribute('data-estado');
        if (estado === 'todos' || rEstado === estado) {
            row.style.display = 'grid';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('paginacionTexto').textContent = `MOSTRANDO ${visibleCount} DE ${rows.length} PROVEEDORES`;
}
</script>
</body>
</html>