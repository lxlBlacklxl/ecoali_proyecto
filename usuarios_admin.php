<?php
session_start();
require "forms/conexion.php";

if (!isset($_SESSION["admin_session"])) {
    if (isset($_SESSION["usuario_id"]) && (int)$_SESSION["rol_id"] === 1) {
        $_SESSION["admin_session"] = [
            "usuario_id" => $_SESSION["usuario_id"],
            "usuario" => $_SESSION["usuario"] ?? "admin",
            "rol_id" => $_SESSION["rol_id"],
            "nombre" => $_SESSION["nombre"] ?? "Admin",
            "apellido" => $_SESSION["apellido"] ?? "",
            "email" => $_SESSION["email"] ?? ""
        ];
    } else {
        header("Location: login.php");
        exit;
    }
}

$nombre = $_SESSION["admin_session"]["nombre"] ?? "Admin";

// Calcular totales
$totalRes = $conn->query("SELECT COUNT(*) FROM usuarios");
$totalUsuarios = $totalRes ? $totalRes->fetch_row()[0] : 0;

$activosRes = $conn->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1");
$activosUsuarios = $activosRes ? $activosRes->fetch_row()[0] : 0;

$inactivosRes = $conn->query("SELECT COUNT(*) FROM usuarios WHERE activo = 0");
$inactivosUsuarios = $inactivosRes ? $inactivosRes->fetch_row()[0] : 0;

// Obtener todos los usuarios con su respectivo perfil
$queryUsers = "SELECT u.id, u.usuario, u.rol_id, u.activo,
                      up.nombre, up.apellido, up.email, up.telefono, up.direccion
               FROM usuarios u
               LEFT JOIN usuario_perfil up ON u.id = up.usuario_id
               ORDER BY u.id DESC";
$resultUsers = $conn->query($queryUsers);
$countUsers = $resultUsers ? $resultUsers->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Usuarios - ECOALI</title>

<link rel="stylesheet" href="assets/css/globals.css">
<link rel="stylesheet" href="assets/css/inventario_admin.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<script src="assets/js/admin_menu.js" defer></script>
<style>
/* Estilos específicos y optimizados para la Tabla de Usuarios */
.gestin-de-inventario .row-usuario-header,
.gestin-de-inventario .row-usuario {
  display: grid;
  grid-template-columns: 1.1fr 1.7fr 1.5fr 2.2fr 1.2fr 1.2fr 1.1fr 0.8fr 1.1fr;
  align-items: center;
}

.gestin-de-inventario .row-usuario-header > div,
.gestin-de-inventario .row-usuario > div {
  padding: 14px 12px !important;
  white-space: nowrap !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
}

/* Asegurar alineación y centrado de los badges de estado y acciones */
.gestin-de-inventario .row-usuario .overlay-6,
.gestin-de-inventario .row-usuario .overlay-10 {
  display: inline-flex !important;
  justify-content: center !important;
  align-items: center !important;
}

.gestin-de-inventario .row-usuario .action-btn {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
}
</style>
</head>

<body>
<div class="gestin-de-inventario">

  <div class="organic-background"></div>
  <div class="background-blur"></div>
  <div class="div"></div>

  <!-- Menú de Navegación Lateral Unificado -->
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

    <div class="nav" style="gap: 6px;">
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
          $active = ($current_page === $href);
          if ($active) {
              echo '
              <a class="link-active-state" href="' . $href . '">
                <div class="link-active-state-2"></div>
                <div class="div-4"><div class="text-31">' . $label . '</div></div>
              </a>';
          } else {
              echo '
              <a class="link" href="' . $href . '">
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

  <!-- Barra Superior -->
  <div class="header-topappbar">
    <div class="div-4"><div class="text-26">Control de Cuentas y Usuarios</div></div>
    <button class="button-2" onclick="abrirModalCrear()">
      <div class="text-3">Agregar usuario</div>
    </button>
  </div>

  <div class="main-content">

    <!-- Mensajes de Estado del Sistema -->
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

    <!-- Buscador e Interfaz de Filtro por Rol -->
    <div class="section-search">
      <div class="container">
        <div class="input">
          <div class="container">
            <input type="text" id="buscarUsuario" placeholder="Buscar por nombre, usuario, correo o teléfono..." onkeyup="filtrarTabla()" style="border:none; width:100%; outline:none; font-family:inherit; color:#462800; font-size:13px; font-weight:600;">
          </div>
        </div>
      </div>

      <div class="container-2">
        <div class="background-shadow">
          <button class="button" onclick="filtrarRol('todos', this)"><div class="text">Todos</div></button>
          <button class="div-wrapper" onclick="filtrarRol('1', this)"><div class="text-2">Admin</div></button>
          <button class="div-wrapper" onclick="filtrarRol('2', this)"><div class="text-2">Cliente</div></button>
          <button class="div-wrapper" onclick="filtrarRol('3', this)"><div class="text-2">Proveedor</div></button>
          <button class="div-wrapper" onclick="filtrarRol('4', this)"><div class="text-2">Repartidor</div></button>
        </div>
      </div>
    </div>

    <!-- Indicadores de Cuentas -->
    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
      <div class="background-border">
        <div class="container-5"><div class="text-wrapper-2">Total de Usuarios</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $totalUsuarios; ?></div></div>
      </div>

      <div class="background-border-2" style="border-color: rgba(23, 106, 33, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #176a21;">Usuarios Activos</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #176a21;"><?php echo $activosUsuarios; ?></div></div>
      </div>

      <div class="background-border-3">
        <div class="container-5"><div class="text-wrapper-2">Usuarios Inactivos</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $inactivosUsuarios; ?></div></div>
      </div>
    </div>

    <!-- Tabla Principal de Usuarios -->
    <div class="inventory-table" style="margin-top: 24px;">
      <div class="header">
        <div class="row row-usuario-header">
          <div><div class="text-7">ID</div></div>
          <div><div class="text-7">NOMBRE COMPLETO</div></div>
          <div><div class="text-7">USUARIO</div></div>
          <div><div class="text-7">CORREO</div></div>
          <div><div class="text-7">TELÉFONO</div></div>
          <div><div class="text-7">ROL</div></div>
          <div><div class="text-7">ESTADO</div></div>
          <div><div class="text-8">REGISTRO</div></div>
          <div><div class="text-9">ACCIONES</div></div>
        </div>
      </div>

      <div id="tablaCuerpo">
      <?php if ($countUsers > 0): ?>
          <?php while ($row = $resultUsers->fetch_assoc()): 
              $activo = (int)$row["activo"];
              $rol_id = (int)$row["rol_id"];
              
              // Mapeo visual de Rol
              $rolText = "Cliente";
              if ($rol_id === 1) $rolText = "Administrador";
              elseif ($rol_id === 3) $rolText = "Proveedor";
              elseif ($rol_id === 4) $rolText = "Repartidor";

              // Mapeo visual de Estado
              $estadoClass = "overlay-6";
              $bgClass = "background-3";
              $estadoText = "Activo";

              if ($activo === 0) {
                  $estadoClass = "overlay-10";
                  $bgClass = "background-9";
                  $estadoText = "Inactivo";
              }
          ?>
          <div class="div-3 row-usuario" data-rol="<?php echo $rol_id; ?>" style="border-bottom: 1px solid rgba(213,164,112,.12);">
            <div><div class="text-10">#USR-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?></div></div>
            <div><div class="text-11" style="font-weight: 700;"><?php echo htmlspecialchars(($row["nombre"] ?? "") . " " . ($row["apellido"] ?? "")); ?></div></div>
            <div><div class="text-11"><?php echo htmlspecialchars($row["usuario"]); ?></div></div>
            <div><div class="text-14" style="text-transform: none;"><?php echo htmlspecialchars($row["email"] ?? "Sin correo"); ?></div></div>
            <div><div class="text-11"><?php echo htmlspecialchars($row["telefono"] ?? "N/A"); ?></div></div>
            <div><div class="text-11" style="font-weight: 700; color: #7a5427;"><?php echo $rolText; ?></div></div>
            <div>
              <div class="<?php echo $estadoClass; ?>">
                <div class="<?php echo $bgClass; ?>"></div>
                <div class="text-15" style="color: inherit;"><?php echo $estadoText; ?></div>
              </div>
            </div>
            <div><div class="text-14">N/D</div></div>
            <div>
              <div class="text-10" style="display: flex; gap: 8px;">
                <button class="action-btn action-btn-edit" title="Editar Cuenta" onclick="abrirModalEditar(<?php echo $row['id']; ?>)">✎</button>
                <?php if ($row['id'] !== (int)$_SESSION['usuario_id']): ?>
                    <button class="action-btn action-btn-delete" title="Eliminar Cuenta" onclick="confirmarEliminar(<?php echo $row['id']; ?>, '<?php echo addslashes($row['usuario']); ?>')">🗑</button>
                <?php else: ?>
                    <button class="action-btn" title="Cuenta Activa (Tú)" disabled style="background:#cccccc; color:#666666; width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; border:none; font-size:11px; cursor:not-allowed;">🔒</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 30px;">
              <div class="text-11" style="color: #996e3f;">No hay usuarios registrados en el sistema.</div>
          </div>
      <?php endif; ?>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p" id="paginacionTexto">MOSTRANDO <?php echo $countUsers; ?> CUENTAS</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal Crear Usuario -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Agregar Nueva Cuenta</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    <form action="forms/usuarios_acciones.php" method="POST">
      <input type="hidden" name="accion" value="crear">
      
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Nombre de Usuario *</label>
          <input type="text" name="usuario" class="form-input" required placeholder="Ej. juan_perez">
        </div>
        <div class="form-group">
          <label class="form-label">Contraseña *</label>
          <input type="password" name="password" class="form-input" required placeholder="Min 6 caracteres">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Nombre *</label>
          <input type="text" name="nombre" class="form-input" required placeholder="Ej. Juan">
        </div>
        <div class="form-group">
          <label class="form-label">Apellido</label>
          <input type="text" name="apellido" class="form-input" placeholder="Ej. Pérez">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Correo Electrónico *</label>
          <input type="email" name="email" class="form-input" required placeholder="juan@ecoali.com">
        </div>
        <div class="form-group">
          <label class="form-label">Teléfono</label>
          <input type="text" name="telefono" class="form-input" placeholder="Ej. 600123456">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; display: none;" id="crear-grupo-direccion-zona">
        <div class="form-group">
          <label class="form-label">Zona de Trabajo (Ruta CDMX) *</label>
          <select name="direccion" id="crear_direccion_zona" class="form-select" disabled>
            <option value="Norte de la CDMX">Norte de la CDMX</option>
            <option value="Sur de la CDMX">Sur de la CDMX</option>
            <option value="Este de la CDMX">Este de la CDMX</option>
            <option value="Oeste de la CDMX">Oeste de la CDMX</option>
          </select>
        </div>
        <div></div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Rol del Sistema</label>
          <select name="rol_id" id="crear_rol_id" class="form-select">
            <option value="1">Administrador</option>
            <option value="2" selected>Cliente</option>
            <option value="3">Proveedor</option>
            <option value="4">Repartidor</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Estado Inicial</label>
          <select name="activo" class="form-select">
            <option value="1" selected>Activo</option>
            <option value="0">Inactivo</option>
          </select>
        </div>
      </div>

      <div class="modal-actions" style="margin-top: 15px;">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrear')">Cancelar</button>
        <button type="submit" class="btn-submit">Registrar Usuario</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar Usuario -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Editar Detalles del Usuario</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="forms/usuarios_acciones.php" method="POST">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">
      
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Nombre de Usuario *</label>
          <input type="text" name="usuario" id="edit_usuario" class="form-input" required>
        </div>
        <div class="form-group">
          <label class="form-label">Nueva Contraseña (Dejar en blanco para conservar)</label>
          <input type="password" name="password" class="form-input" placeholder="Nueva contraseña opcional">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Nombre *</label>
          <input type="text" name="nombre" id="edit_nombre" class="form-input" required>
        </div>
        <div class="form-group">
          <label class="form-label">Apellido</label>
          <input type="text" name="apellido" id="edit_apellido" class="form-input">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Correo Electrónico *</label>
          <input type="email" name="email" id="edit_email" class="form-input" required>
        </div>
        <div class="form-group">
          <label class="form-label">Teléfono</label>
          <input type="text" name="telefono" id="edit_telefono" class="form-input">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; display: none;" id="edit-grupo-direccion-zona">
        <div class="form-group">
          <label class="form-label">Zona de Trabajo (Ruta CDMX) *</label>
          <select name="direccion" id="edit_direccion_zona" class="form-select" disabled>
            <option value="Norte de la CDMX">Norte de la CDMX</option>
            <option value="Sur de la CDMX">Sur de la CDMX</option>
            <option value="Este de la CDMX">Este de la CDMX</option>
            <option value="Oeste de la CDMX">Oeste de la CDMX</option>
          </select>
        </div>
        <div></div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Rol del Sistema</label>
          <select name="rol_id" id="edit_rol_id" class="form-select">
            <option value="1">Administrador</option>
            <option value="2">Cliente</option>
            <option value="3">Proveedor</option>
            <option value="4">Repartidor</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Estado</label>
          <select name="activo" id="edit_activo" class="form-select">
            <option value="1">Activo</option>
            <option value="0">Inactivo</option>
          </select>
        </div>
      </div>

      <div class="modal-actions" style="margin-top: 15px;">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditar')">Cancelar</button>
        <button type="submit" class="btn-submit">Guardar Cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Confirmar Eliminación -->
<div class="modal-overlay" id="modalEliminar">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Eliminar Cuenta de Usuario</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="forms/usuarios_acciones.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="delete_id">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar permanentemente el usuario <strong id="delete_nombre" style="color: #462800;"></strong>?<br>Esta acción borrará de forma irreversible el perfil asociado en la base de datos.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 10px 25px rgba(176, 37, 0, 0.15);">Eliminar Cuenta</button>
      </div>
    </form>
  </div>
</div>

<!-- Scripts de Interacción AJAX y Filtrado Rápido -->
<script>
function abrirModalCrear() {
    document.getElementById('modalCrear').classList.add('active');
}

function abrirModalEditar(id) {
    fetch('forms/usuarios_acciones.php?accion=obtener&id=' + id)
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('edit_id').value = res.data.id;
                document.getElementById('edit_usuario').value = res.data.usuario;
                document.getElementById('edit_nombre').value = res.data.nombre || '';
                document.getElementById('edit_apellido').value = res.data.apellido || '';
                document.getElementById('edit_email').value = res.data.email || '';
                document.getElementById('edit_telefono').value = res.data.telefono || '';
                
                // Si es repartidor, seleccionar la zona correspondiente en el dropdown
                if (String(res.data.rol_id) === '4') {
                    const dirVal = res.data.direccion || '';
                    const editZoneInput = document.getElementById('edit_direccion_zona');
                    if (dirVal.toLowerCase().includes('norte')) {
                        editZoneInput.value = 'Norte de la CDMX';
                    } else if (dirVal.toLowerCase().includes('sur')) {
                        editZoneInput.value = 'Sur de la CDMX';
                    } else if (dirVal.toLowerCase().includes('este')) {
                        editZoneInput.value = 'Este de la CDMX';
                    } else if (dirVal.toLowerCase().includes('oeste')) {
                        editZoneInput.value = 'Oeste de la CDMX';
                    }
                }
                
                document.getElementById('edit_rol_id').value = res.data.rol_id;
                // Forzar la actualización del toggler después de cargar la data
                const event = new Event('change');
                document.getElementById('edit_rol_id').dispatchEvent(event);
                
                document.getElementById('edit_activo').value = res.data.activo;
                
                document.getElementById('modalEditar').classList.add('active');
            } else {
                alert('Error al recuperar datos: ' + res.message);
            }
        })
        .catch(err => {
            alert('Error al comunicar con el servidor.');
        });
}

function confirmarEliminar(id, usuario) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_nombre').textContent = usuario;
    document.getElementById('modalEliminar').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Búsqueda en tiempo real combinada con filtro de rol y paginación
let paginaActual = 1;
const registrosPorPagina = 5;

function actualizarVistaUsuarios() {
    const query = document.getElementById('buscarUsuario').value.toLowerCase();
    
    // Obtener el rol actualmente seleccionado
    const activeBtn = document.querySelector('.background-shadow button.button');
    let activeRol = 'todos';
    if (activeBtn) {
        const onclickAttr = activeBtn.getAttribute('onclick') || '';
        if (onclickAttr.includes("'1'")) activeRol = '1';
        else if (onclickAttr.includes("'2'")) activeRol = '2';
        else if (onclickAttr.includes("'3'")) activeRol = '3';
        else if (onclickAttr.includes("'4'")) activeRol = '4';
    }

    const rows = document.querySelectorAll('#tablaCuerpo .row-usuario');
    const matchingRows = [];

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const rRol = row.getAttribute('data-rol');
        const matchesQuery = text.includes(query);
        const matchesRol = (activeRol === 'todos' || rRol === activeRol);

        if (matchesQuery && matchesRol) {
            matchingRows.push(row);
        } else {
            row.style.display = 'none';
        }
    });

    const totalRegistros = matchingRows.length;
    const totalPaginas = Math.ceil(totalRegistros / registrosPorPagina) || 1;

    if (paginaActual > totalPaginas) {
        paginaActual = totalPaginas;
    }
    if (paginaActual < 1) {
        paginaActual = 1;
    }

    const inicio = (paginaActual - 1) * registrosPorPagina;
    const fin = inicio + registrosPorPagina;

    matchingRows.forEach((row, index) => {
        if (index >= inicio && index < fin) {
            row.style.display = 'grid';
        } else {
            row.style.display = 'none';
        }
    });

    const mostradosInicio = totalRegistros > 0 ? inicio + 1 : 0;
    const mostradosFin = Math.min(fin, totalRegistros);
    document.getElementById('paginacionTexto').textContent = 
        `MOSTRANDO ${mostradosInicio}-${mostradosFin} DE ${totalRegistros} CUENTAS`;

    const contenedorPaginacion = document.querySelector('.pagination-like .container-8');
    if (contenedorPaginacion) {
        contenedorPaginacion.innerHTML = "";
        
        for (let p = 1; p <= totalPaginas; p++) {
            const btnClass = (p === paginaActual) ? 'button-3' : 'button-4';
            const textClass = (p === paginaActual) ? 'text-23' : 'text-24';
            
            const btn = document.createElement('button');
            btn.className = btnClass;
            btn.style.cursor = 'pointer';
            btn.onclick = () => cambiarPagina(p);
            
            const divText = document.createElement('div');
            divText.className = textClass;
            divText.textContent = p;
            
            btn.appendChild(divText);
            contenedorPaginacion.appendChild(btn);
        }
    }
}

function cambiarPagina(pagina) {
    paginaActual = pagina;
    actualizarVistaUsuarios();
}

function filtrarTabla() {
    paginaActual = 1;
    actualizarVistaUsuarios();
}

// Filtro rápido por rol
function filtrarRol(rol, btn) {
    // Actualizar estilos de los botones de filtro
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

    paginaActual = 1;
    actualizarVistaUsuarios();
}

// Función para alternar la visualización del desplegable de Zona según el rol (solo Repartidor)
function configurarAlternanciaDireccion(rolSelectId, zoneGrpId, zoneInputId) {
    const rolSelect = document.getElementById(rolSelectId);
    const zoneGrp = document.getElementById(zoneGrpId);
    const zoneInput = document.getElementById(zoneInputId);

    if (!rolSelect) return;

    function actualizar() {
        if (rolSelect.value === '4') { // 4 = Repartidor
            zoneGrp.style.display = 'grid';
            zoneInput.disabled = false;
        } else {
            zoneGrp.style.display = 'none';
            zoneInput.disabled = true;
        }
    }

    rolSelect.addEventListener('change', actualizar);
    actualizar(); // Ejecutar al inicio
}

// Inicializar la vista con paginación al cargar el documento
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        actualizarVistaUsuarios();
        configurarAlternanciaDireccion('crear_rol_id', 'crear-grupo-direccion-zona', 'crear_direccion_zona');
        configurarAlternanciaDireccion('edit_rol_id', 'edit-grupo-direccion-zona', 'edit_direccion_zona');
    });
} else {
    actualizarVistaUsuarios();
    configurarAlternanciaDireccion('crear_rol_id', 'crear-grupo-direccion-zona', 'crear_direccion_zona');
    configurarAlternanciaDireccion('edit_rol_id', 'edit-grupo-direccion-zona', 'edit_direccion_zona');
}
</script>
</body>
</html>
