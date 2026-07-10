<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - ADMINISTRACIÓN DE CENTROS DE DISTRIBUCIÓN (CEDIS)
 * --------------------------------------------------------------------------------
 * Interfaz para que el Administrador (Rol 1) cree, edite, visualice y administre
 * todos los CEDIS del sistema.
 */

session_start();
require "forms/conexion.php";

// Control de acceso para administradores
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

// Calcular métricas rápidas
$totalRes = $conn->query("SELECT COUNT(*) FROM cedis");
$totalCedis = $totalRes ? $totalRes->fetch_row()[0] : 0;

$activosRes = $conn->query("SELECT COUNT(*) FROM cedis WHERE activo = 1");
$activosCedis = $activosRes ? $activosRes->fetch_row()[0] : 0;

$inactivosRes = $conn->query("SELECT COUNT(*) FROM cedis WHERE activo = 0");
$inactivosCedis = $inactivosRes ? $inactivosRes->fetch_row()[0] : 0;

// Obtener todos los CEDIS
$queryList = "SELECT * FROM cedis ORDER BY id DESC";
$resultList = $conn->query($queryList);
$countList = $resultList ? $resultList->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de CEDIS - ECOALI</title>

<link rel="stylesheet" href="assets/css/globals.css">
<link rel="stylesheet" href="assets/css/inventario_admin.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<script src="assets/js/admin_menu.js" defer></script>

<style>
  /* Personalización extra para la interfaz de CEDIS */
  .cedis-grid {
      display: grid;
      grid-template-columns: 80px 1.5fr 2fr 100px 120px 140px;
      align-items: center;
      padding: 18px 24px;
      border-bottom: 1px solid rgba(213, 164, 112, 0.08);
      transition: background-color 0.2s ease;
  }
  .cedis-grid:hover {
      background-color: rgba(255, 255, 255, 0.02);
  }
  .cedis-grid-header {
      display: grid;
      grid-template-columns: 80px 1.5fr 2fr 100px 120px 140px;
      padding: 14px 24px;
      background: rgba(255, 255, 255, 0.02);
      border-bottom: 1px solid rgba(213, 164, 112, 0.15);
      font-weight: 800;
      text-transform: uppercase;
      font-size: 11px;
      letter-spacing: 0.5px;
      color: #a39585;
  }
  @media (max-width: 991px) {
      .cedis-grid, .cedis-grid-header {
          grid-template-columns: 1fr;
          gap: 10px;
          padding: 15px;
      }
      .cedis-grid-header {
          display: none;
      }
  }
</style>
</head>

<body>
<div class="gestin-de-inventario">

  <div class="organic-background"></div>
  <div class="background-blur"></div>
  <div class="div"></div>

  <!-- Menú lateral -->
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
          'bitacora_admin.php' => 'Bitácora',
          'cedis_admin.php' => 'CEDIS'
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

  <!-- Header -->
  <div class="header-topappbar">
    <div class="div-4"><div class="text-26">Gestión de Centros de Distribución (CEDIS)</div></div>
    <button class="button-2" onclick="abrirModalCrear()">
      <div class="text-3">Agregar CEDIS</div>
    </button>
  </div>

  <div class="main-content">

    <!-- Notificaciones -->
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
                <span>⚠</span> <?php echo $_SESSION["mensaje_error"]; unset($_SESSION["mensaje_error"]); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tarjetas de métricas -->
    <div class="row-cards">
      <div class="card-metric">
        <div class="div-3">
          <div class="metric-title">TOTAL CEDIS</div>
          <div class="metric-value"><?php echo $totalCedis; ?></div>
        </div>
        <div class="metric-icon">🏢</div>
      </div>

      <div class="card-metric">
        <div class="div-3">
          <div class="metric-title">CEDIS ACTIVOS</div>
          <div class="metric-value" style="color: #4cd137;"><?php echo $activosCedis; ?></div>
        </div>
        <div class="metric-icon" style="background: rgba(76, 209, 55, 0.1); color: #4cd137;">✓</div>
      </div>

      <div class="card-metric">
        <div class="div-3">
          <div class="metric-title">CEDIS INACTIVOS</div>
          <div class="metric-value" style="color: #e84118;"><?php echo $inactivosCedis; ?></div>
        </div>
        <div class="metric-icon" style="background: rgba(232, 65, 24, 0.1); color: #e84118;">✗</div>
      </div>
    </div>

    <!-- Buscador y Tabla -->
    <div class="card-table">
      <div class="table-header">
        <div class="table-title">Sucursales Logísticas</div>
        <div class="search-box">
          <input type="text" id="buscarCedis" placeholder="Buscar por nombre o dirección..." onkeyup="filtrarTabla()">
        </div>
      </div>

      <div class="cedis-grid-header">
        <div>ID</div>
        <div>Nombre</div>
        <div>Dirección</div>
        <div style="text-align: center;">Estado</div>
        <div>Creado el</div>
        <div style="text-align: right;">Acciones</div>
      </div>

      <div id="tablaCuerpo">
        <?php if ($countList > 0): ?>
          <?php while ($row = $resultList->fetch_assoc()): ?>
            <div class="cedis-grid row-cedis">
              <div><strong>#<?php echo str_pad($row["id"], 2, "0", STR_PAD_LEFT); ?></strong></div>
              <div><strong style="color: #462800;"><?php echo htmlspecialchars($row["nombre"]); ?></strong></div>
              <div style="color: #6a6a6a; font-size: 13px;"><?php echo htmlspecialchars($row["direccion"]); ?></div>
              <div style="text-align: center;">
                <?php if ((int)$row["activo"] === 1): ?>
                  <span class="badge-status" style="background: rgba(76, 209, 55, 0.15); color: #4cd137; border: 1px solid rgba(76, 209, 55, 0.3); padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 800;">ACTIVO</span>
                <?php else: ?>
                  <span class="badge-status" style="background: rgba(232, 65, 24, 0.15); color: #e84118; border: 1px solid rgba(232, 65, 24, 0.3); padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 800;">INACTIVO</span>
                <?php endif; ?>
              </div>
              <div style="color: #7a5427; font-size: 13px;"><?php echo date("d/m/Y", strtotime($row["creado_en"])); ?></div>
              <div style="text-align: right; display: flex; gap: 8px; justify-content: flex-end;">
                <button class="btn-action-edit" onclick="abrirModalEditar(<?php echo $row['id']; ?>)">Editar</button>
                <button class="btn-action-delete" style="background: rgba(232, 65, 24, 0.1); color: #e84118; border: 1px solid rgba(232, 65, 24, 0.2); padding: 6px 12px; border-radius: 8px; font-weight: 700; cursor: pointer;" onclick="confirmarEliminar(<?php echo $row['id']; ?>, '<?php echo addslashes($row['nombre']); ?>')">Eliminar</button>
              </div>
            </div>
          <?php endwhile; ?>
        <?php else: ?>
          <div style="text-align: center; padding: 48px; color: #7a5427;">
            No se encontraron Centros de Distribución (CEDIS) registrados.
          </div>
        <?php endif; ?>
      </div>

      <div class="table-footer">
        <div id="paginacionTexto">MOSTRANDO <?php echo $countList; ?> DE <?php echo $countList; ?> SUCURSALES</div>
      </div>
    </div>

  </div>
</div>

<!-- Modal Crear CEDIS -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container" style="max-width: 500px;">
    <div class="modal-header">
      <div class="modal-title">Agregar Nuevo CEDIS</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    <form action="forms/cedis_admin_acciones.php" method="POST">
      <input type="hidden" name="accion" value="crear">
      
      <div class="form-group">
        <label class="form-label">Nombre del CEDIS *</label>
        <input type="text" name="nombre" class="form-input" placeholder="Ej. CEDIS Principal" required>
      </div>

      <div class="form-group">
        <label class="form-label">Dirección Completa *</label>
        <input type="text" name="direccion" class="form-input" placeholder="Dirección, Ciudad" required>
      </div>

      <div class="form-group">
        <label class="form-label">Estado Inicial</label>
        <select name="activo" class="form-select">
          <option value="1">Activo / Operativo</option>
          <option value="0">Inactivo / En Mantenimiento</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrear')">Cancelar</button>
        <button type="submit" class="btn-submit">Guardar CEDIS</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar CEDIS -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container" style="max-width: 500px;">
    <div class="modal-header">
      <div class="modal-title">Editar Detalles del CEDIS</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="forms/cedis_admin_acciones.php" method="POST">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">
      
      <div class="form-group">
        <label class="form-label">Nombre del CEDIS *</label>
        <input type="text" name="nombre" id="edit_nombre" class="form-input" required>
      </div>

      <div class="form-group">
        <label class="form-label">Dirección Completa *</label>
        <input type="text" name="direccion" id="edit_direccion" class="form-input" required>
      </div>

      <div class="form-group">
        <label class="form-label">Estado Operativo</label>
        <select name="activo" id="edit_activo" class="form-select">
          <option value="1">Activo / Operativo</option>
          <option value="0">Inactivo / En Mantenimiento</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditar')">Cancelar</button>
        <button type="submit" class="btn-submit">Actualizar CEDIS</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Confirmar Eliminación -->
<div class="modal-overlay" id="modalEliminar">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Eliminar Centro de Distribución</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="forms/cedis_admin_acciones.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="delete_id">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar permanentemente el CEDIS <strong id="delete_nombre" style="color: #462800;"></strong>?<br>
        Esta acción solo se completará si no tiene registros de logística (envíos, inventarios u operadores) asociados en el sistema. En caso contrario, se desactivará automáticamente.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 10px 25px rgba(176, 37, 0, 0.15);">Eliminar</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalCrear() {
    document.getElementById('modalCrear').classList.add('active');
}

function abrirModalEditar(id) {
    fetch('forms/cedis_admin_acciones.php?accion=obtener&id=' + id)
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('edit_id').value = res.data.id;
                document.getElementById('edit_nombre').value = res.data.nombre;
                document.getElementById('edit_direccion').value = res.data.direccion;
                document.getElementById('edit_activo').value = res.data.activo;
                
                document.getElementById('modalEditar').classList.add('active');
            } else {
                alert('Error al recuperar datos: ' + res.message);
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

// Búsqueda en tiempo real por texto
function filtrarTabla() {
    const query = document.getElementById('buscarCedis').value.toLowerCase();
    const rows = document.querySelectorAll('#tablaCuerpo .row-cedis');
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

    document.getElementById('paginacionTexto').textContent = `MOSTRANDO ${visibleCount} DE ${rows.length} SUCURSALES`;
}
</script>
</body>
</html>
