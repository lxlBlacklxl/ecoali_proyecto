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

// Calcular totales de catálogo
$totalRes = $conn->query("SELECT COUNT(*) FROM productos");
$totalProductos = $totalRes ? $totalRes->fetch_row()[0] : 0;

$activosRes = $conn->query("SELECT COUNT(*) FROM productos WHERE activo = 1");
$activosProductos = $activosRes ? $activosRes->fetch_row()[0] : 0;

$inactivosRes = $conn->query("SELECT COUNT(*) FROM productos WHERE activo = 0");
$inactivosProductos = $inactivosRes ? $inactivosRes->fetch_row()[0] : 0;

// Obtener listado de productos con stock consolidado real de lotes disponibles
$queryList = "SELECT pr.*, 
                     COALESCE(SUM(ih.cantidad), 0) AS stock_disponible
              FROM productos pr
              LEFT JOIN inventario_huevos ih ON pr.id = ih.producto_id AND ih.estado = 'disponible'
              GROUP BY pr.id
              ORDER BY pr.id DESC";
$resultList = $conn->query($queryList);
$countList = $resultList ? $resultList->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Catálogo de Productos - ECOALI</title>

<link rel="stylesheet" href="assets/css/globals.css">
<link rel="stylesheet" href="assets/css/inventario_admin.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
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

  <!-- Header -->
  <div class="header-topappbar">
    <div class="div-4"><div class="text-26">Catálogo General de Productos</div></div>
    <button class="button-2" onclick="abrirModalCrear()">
      <div class="text-3">Agregar producto</div>
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
                <span>✗</span> <?php echo $_SESSION["mensaje_error"]; unset($_SESSION["mensaje_error"]); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Buscador -->
    <div class="section-search">
      <div class="container" style="max-width: 100%; width: 100%;">
        <div class="input" style="width: 100%;">
          <div class="container">
            <input type="text" id="buscarProducto" placeholder="Buscar por nombre, tipo de huevo o presentación..." onkeyup="filtrarTabla()" style="border:none; width:100%; outline:none; font-family:inherit; color:#462800; font-size:13px; font-weight:600;">
          </div>
        </div>
      </div>
    </div>

    <!-- Indicadores -->
    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
      <div class="background-border">
        <div class="container-5"><div class="text-wrapper-2">Total de Productos</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $totalProductos; ?></div></div>
      </div>

      <div class="background-border-2" style="border-color: rgba(23, 106, 33, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #176a21;">Productos Activos</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #176a21;"><?php echo $activosProductos; ?></div></div>
      </div>

      <div class="background-border-3">
        <div class="container-5"><div class="text-wrapper-2">Productos Inactivos</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $inactivosProductos; ?></div></div>
      </div>
    </div>

    <!-- Tabla de Productos -->
    <div class="inventory-table" style="margin-top: 24px;">
      <div class="header">
        <div class="row" style="grid-template-columns: 1fr 2.5fr 2fr 1.5fr 1.2fr 1.5fr 1.2fr 1.5fr;">
          <div><div class="text-7">ID</div></div>
          <div><div class="text-7">PRODUCTO</div></div>
          <div><div class="text-7">TIPO DE HUEVO</div></div>
          <div><div class="text-7">TAMAÑO</div></div>
          <div><div class="text-7">PRECIO</div></div>
          <div><div class="text-8">STOCK DISPONIBLE</div></div>
          <div><div class="text-7">ESTADO</div></div>
          <div><div class="text-9">ACCIONES</div></div>
        </div>
      </div>

      <div id="tablaCuerpo">
      <?php if ($countList > 0): ?>
          <?php while ($row = $resultList->fetch_assoc()): 
              $activo = (int)$row["activo"];
              $estadoClass = "overlay-6";
              $bgClass = "background-3";
              $estadoText = "Activo";

              if ($activo === 0) {
                  $estadoClass = "overlay-10";
                  $bgClass = "background-9";
                  $estadoText = "Inactivo";
              }
          ?>
          <div class="div-3 row-producto" style="grid-template-columns: 1fr 2.5fr 2fr 1.5fr 1.2fr 1.5fr 1.2fr 1.5fr; border-bottom: 1px solid rgba(213,164,112,.12);">
            <div><div class="text-10">#PROD-<?php echo str_pad($row["id"], 2, "0", STR_PAD_LEFT); ?></div></div>
            <div><div class="text-11" style="font-weight: 700;"><?php echo htmlspecialchars($row["nombre"]); ?></div></div>
            <div><div class="text-11"><?php echo htmlspecialchars($row["tipo_huevo"]); ?></div></div>
            <div><div class="text-12"><?php echo htmlspecialchars($row["tamano"]); ?></div></div>
            <div><div class="text-11" style="font-weight: 700; color: #176a21;">$<?php echo number_format($row["precio"], 2); ?></div></div>
            <div>
              <div class="background-2" style="background: <?php echo $row['stock_disponible'] > 0 ? 'rgba(23,106,33,.1)' : 'rgba(176,37,0,.1)'; ?>;">
                <div class="text-13" style="color: <?php echo $row['stock_disponible'] > 0 ? '#176a21' : '#b02500'; ?>; font-weight:700;"><?php echo number_format($row["stock_disponible"]); ?> ud.</div>
              </div>
            </div>
            <div>
              <div class="<?php echo $estadoClass; ?>">
                <div class="<?php echo $bgClass; ?>"></div>
                <div class="text-15" style="color: inherit;"><?php echo $estadoText; ?></div>
              </div>
            </div>
            <div>
              <div class="text-10" style="display: flex; gap: 8px;">
                <button class="action-btn action-btn-edit" title="Editar Producto" onclick="abrirModalEditar(<?php echo $row['id']; ?>)">✎</button>
                <button class="action-btn action-btn-delete" title="Eliminar Producto" onclick="confirmarEliminar(<?php echo $row['id']; ?>, '<?php echo addslashes($row['nombre']); ?>')">🗑</button>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 30px;">
              <div class="text-11" style="color: #996e3f;">No hay productos registrados en el catálogo. ¡Agrega uno!</div>
          </div>
      <?php endif; ?>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p" id="paginacionTexto">MOSTRANDO <?php echo $countList; ?> PRODUCTOS</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal Crear Producto -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Agregar Producto al Catálogo</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    <form action="forms/productos_acciones.php" method="POST">
      <input type="hidden" name="accion" value="crear">
      
      <div class="form-group">
        <label class="form-label">Nombre del Producto *</label>
        <input type="text" name="nombre" class="form-input" required placeholder="Ej. Huevo Ecológico Premium">
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Tipo de Huevo *</label>
          <input type="text" name="tipo_huevo" class="form-input" required placeholder="Ej. Ecológico Camperos, Orgánico">
        </div>
        <div class="form-group">
          <label class="form-label">Presentación / Tamaño *</label>
          <select name="tamano" class="form-select">
            <option value="Extra (XL)" selected>Extra (XL)</option>
            <option value="Grande (L)">Grande (L)</option>
            <option value="Medio (M)">Medio (M)</option>
            <option value="Pequeño (S)">Pequeño (S)</option>
          </select>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Precio Unitario / Docena ($) *</label>
          <input type="number" step="0.01" name="precio" class="form-input" required placeholder="Ej. 4.50">
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
        <button type="submit" class="btn-submit">Registrar Producto</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar Producto -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Editar Producto</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="forms/productos_acciones.php" method="POST">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">
      
      <div class="form-group">
        <label class="form-label">Nombre del Producto *</label>
        <input type="text" name="nombre" id="edit_nombre" class="form-input" required>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Tipo de Huevo *</label>
          <input type="text" name="tipo_huevo" id="edit_tipo_huevo" class="form-input" required>
        </div>
        <div class="form-group">
          <label class="form-label">Presentación / Tamaño *</label>
          <select name="tamano" id="edit_tamano" class="form-select">
            <option value="Extra (XL)">Extra (XL)</option>
            <option value="Grande (L)">Grande (L)</option>
            <option value="Medio (M)">Medio (M)</option>
            <option value="Pequeño (S)">Pequeño (S)</option>
          </select>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <div class="form-group">
          <label class="form-label">Precio Unitario / Docena ($) *</label>
          <input type="number" step="0.01" name="precio" id="edit_precio" class="form-input" required>
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
      <div class="modal-title" style="color: #b02500;">Eliminar Producto del Catálogo</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="forms/productos_acciones.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="delete_id">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar permanentemente el producto <strong id="delete_nombre" style="color: #462800;"></strong> del catálogo?<br>Esta acción liberará el registro del catálogo y solo se completará si no hay lotes asociados a este producto en almacén.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 10px 25px rgba(176, 37, 0, 0.15);">Eliminar Producto</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalCrear() {
    document.getElementById('modalCrear').classList.add('active');
}

function abrirModalEditar(id) {
    fetch('forms/productos_acciones.php?accion=obtener&id=' + id)
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('edit_id').value = res.data.id;
                document.getElementById('edit_nombre').value = res.data.nombre;
                document.getElementById('edit_tipo_huevo').value = res.data.tipo_huevo;
                document.getElementById('edit_tamano').value = res.data.tamano;
                document.getElementById('edit_precio').value = res.data.precio;
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
    const query = document.getElementById('buscarProducto').value.toLowerCase();
    const rows = document.querySelectorAll('#tablaCuerpo .row-producto');
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

    document.getElementById('paginacionTexto').textContent = `MOSTRANDO ${visibleCount} DE ${rows.length} PRODUCTOS`;
}
</script>
</body>
</html>
