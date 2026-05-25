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

// Verificar y precargar productos base si la tabla está vacía
$prodCountRes = $conn->query("SELECT COUNT(*) FROM productos");
$prodCount = $prodCountRes ? $prodCountRes->fetch_row()[0] : 0;
if ($prodCount == 0) {
    $conn->query("INSERT INTO productos (nombre, tipo_huevo, tamano, precio, activo) VALUES 
        ('Orgánico Camperos Extra', 'Orgánico Camperos', 'Extra (XL)', 4.50, 1),
        ('Blanco Tradicional Grande', 'Blanco Tradicional', 'Grande (L)', 3.20, 1),
        ('Ponedora Rubia Medio', 'Ponedora Rubia', 'Medio (M)', 2.80, 1),
        ('Ecológico de Pasto Chico', 'Ecológico de Pasto', 'Pequeño (S)', 5.00, 1)
    ");
}

// Consultas dinámicas para las tarjetas de estado del inventario
$totalHuevosRes = $conn->query("SELECT SUM(cantidad) FROM inventario_huevos");
$totHueRow = $totalHuevosRes ? $totalHuevosRes->fetch_row() : null;
$totalHuevos = $totHueRow && !is_null($totHueRow[0]) ? (int)$totHueRow[0] : 0;

$disponiblesHuevosRes = $conn->query("SELECT SUM(cantidad) FROM inventario_huevos WHERE estado = 'disponible'");
$dispHueRow = $disponiblesHuevosRes ? $disponiblesHuevosRes->fetch_row() : null;
$disponiblesHuevos = $dispHueRow && !is_null($dispHueRow[0]) ? (int)$dispHueRow[0] : 0;

$proximosRes = $conn->query("SELECT COUNT(*) FROM inventario_huevos WHERE estado = 'disponible' AND fecha_caducidad <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND fecha_caducidad >= CURDATE()");
$proximosCaducar = $proximosRes ? $proximosRes->fetch_row()[0] : 0;

$caducadosRes = $conn->query("SELECT COUNT(*) FROM inventario_huevos WHERE estado = 'caducado' OR (estado = 'disponible' AND fecha_caducidad < CURDATE())");
$caducadosStock = $caducadosRes ? $caducadosRes->fetch_row()[0] : 0;

// Obtener proveedores y productos para los modales selectores
$proveedoresSelect = $conn->query("SELECT id, nombre_empresa FROM proveedores WHERE estado = 'activo' ORDER BY nombre_empresa ASC");
$productosSelect = $conn->query("SELECT id, tipo_huevo, tamano FROM productos WHERE activo = 1 ORDER BY tipo_huevo ASC");

// Obtener la lista dinámica de lotes del inventario
$sqlLotes = "SELECT ih.*, p.nombre_empresa, pr.tipo_huevo, pr.tamano 
             FROM inventario_huevos ih 
             LEFT JOIN proveedores p ON ih.proveedor_id = p.id 
             LEFT JOIN productos pr ON ih.producto_id = pr.id 
             ORDER BY ih.id DESC";
$resultLotes = $conn->query($sqlLotes);
$countLotes = $resultLotes ? $resultLotes->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Inventario - ECOALI</title>

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
    <div class="div-4"><div class="text-26">Gestión de Inventario</div></div>
  </div>

  <div class="main-content">

    <!-- Alertas del sistema -->
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
            <input type="text" id="buscarLote" placeholder="Buscar por ID del lote, tipo de huevo o proveedor..." onkeyup="filtrarTabla()" style="border:none; width:100%; outline:none; font-family:inherit; color:#462800; font-size:13px; font-weight:600;">
          </div>
        </div>
      </div>

      <div class="container-2">
        <div class="background-shadow">
          <button class="button" onclick="filtrarEstado('todos', this)"><div class="text">Todo</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('disponible', this)"><div class="text-2">Disponible</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('bajo_stock', this)"><div class="text-2">Bajo Stock</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('caducado', this)"><div class="text-2">Caducado</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('vendido', this)"><div class="text-2">Vendido</div></button>
        </div>

        <button class="button-2" onclick="abrirModalCrear()"><div class="text-3">Agregar lote</div></button>
      </div>
    </div>

    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
      <div class="background-border">
        <div class="container-5"><div class="text-wrapper-2">Total de Huevos</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo number_format($totalHuevos); ?> ud</div></div>
      </div>

      <div class="background-border-2" style="border-color: rgba(23, 106, 33, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #176a21;">Huevos Disponibles</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #176a21;"><?php echo number_format($disponiblesHuevos); ?> ud</div></div>
      </div>

      <div class="background-border" style="border-color: rgba(255, 138, 0, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #ff8a00;">Próximos a Caducar</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #ff8a00;"><?php echo $proximosCaducar; ?> lotes</div></div>
      </div>

      <div class="background-border-3">
        <div class="container-5"><div class="text-wrapper-2">Stock Caducado</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $caducadosStock; ?> lotes</div></div>
      </div>
    </div>

    <div class="inventory-table">
      <div class="header">
        <div class="row" style="grid-template-columns: 1.1fr 1.6fr 1.6fr 1fr 1.1fr 1.1fr 1.1fr 1.1fr 1.8fr;">
          <div><div class="text-7">LOTE</div></div>
          <div><div class="text-7">PROVEEDOR</div></div>
          <div><div class="text-7">TIPO DE HUEVO</div></div>
          <div><div class="text-7">TAMAÑO</div></div>
          <div><div class="text-8">CANTIDAD</div></div>
          <div><div class="text-7">POSTURA</div></div>
          <div><div class="text-7">CADUCIDAD</div></div>
          <div><div class="text-7">ESTADO</div></div>
          <div><div class="text-9">ACCIONES</div></div>
        </div>
      </div>

      <div id="tablaCuerpo">
      <?php if ($countLotes > 0): ?>
          <?php while ($row = $resultLotes->fetch_assoc()): 
              $estado = strtolower($row["estado"]);
              $estadoClass = "overlay-6";
              $bgClass = "background-3";
              $estadoText = "Disponible";

              if ($estado === "bajo_stock") {
                  $estadoClass = "overlay-10";
                  $bgClass = "background-9";
                  $estadoText = "Bajo Stock";
              } elseif ($estado === "caducado") {
                  $estadoClass = "overlay-9";
                  $bgClass = "background-7";
                  $estadoText = "Caducado";
              } elseif ($estado === "vendido") {
                  $estadoClass = "overlay-7";
                  $bgClass = "background-5";
                  $estadoText = "Vendido";
              }
              
              // Datos de trazabilidad para el modal JS
              $trazabilidadJson = json_encode([
                  'lote' => $row['codigo_lote'],
                  'proveedor' => $row['nombre_empresa'] ?? 'Desconocido',
                  'tipo' => $row['tipo_huevo'] ?? 'N/A',
                  'tamano' => $row['tamano'] ?? 'M',
                  'cantidad' => $row['cantidad'],
                  'postura' => $row['fecha_postura'] ? date("d/m/Y", strtotime($row['fecha_postura'])) : 'N/A',
                  'caducidad' => $row['fecha_caducidad'] ? date("d/m/Y", strtotime($row['fecha_caducidad'])) : 'N/A',
                  'estado' => $estadoText
              ]);
          ?>
          <div class="div-3 row-lote" data-estado="<?php echo $estado; ?>" style="grid-template-columns: 1.1fr 1.6fr 1.6fr 1fr 1.1fr 1.1fr 1.1fr 1.1fr 1.8fr; border-bottom: 1px solid rgba(213,164,112,.12);">
            <div><div class="text-10"><?php echo htmlspecialchars($row["codigo_lote"]); ?></div></div>
            <div><div class="text-11" style="font-weight: 700;"><?php echo htmlspecialchars($row["nombre_empresa"] ?? "Granja Local"); ?></div></div>
            <div><div class="text-11"><?php echo htmlspecialchars($row["tipo_huevo"] ?? "No especificado"); ?></div></div>
            <div><div class="text-12"><?php echo htmlspecialchars($row["tamano"] ?? "-"); ?></div></div>
            <div>
              <div class="background-2">
                <div class="text-13"><?php echo $row["cantidad"]; ?> ud.</div>
              </div>
            </div>
            <div><div class="text-14"><?php echo $row["fecha_postura"] ? date("d M Y", strtotime($row["fecha_postura"])) : "-"; ?></div></div>
            <div><div class="text-14"><?php echo $row["fecha_caducidad"] ? date("d M Y", strtotime($row["fecha_caducidad"])) : "-"; ?></div></div>
            <div>
              <div class="<?php echo $estadoClass; ?>">
                <div class="<?php echo $bgClass; ?>"></div>
                <div class="text-15" style="color: inherit;"><?php echo $estadoText; ?></div>
              </div>
            </div>
            <div>
              <div class="text-10" style="display: flex; gap: 6px; align-items: center;">
                <button class="action-btn action-btn-edit" title="Editar Lote" onclick="abrirModalEditar(<?php echo $row['id']; ?>)">✎</button>
                <button class="action-btn action-btn-delete" title="Eliminar Lote" onclick="confirmarEliminar(<?php echo $row['id']; ?>, '<?php echo addslashes($row['codigo_lote']); ?>')">🗑</button>
                
                <?php if ($estado !== 'caducado'): ?>
                    <button class="action-btn" title="Bloquear Lote Caducado" onclick="bloquearLote(<?php echo $row['id']; ?>, '<?php echo addslashes($row['codigo_lote']); ?>')" style="background:#b02500; color:#fff; border:none; width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:11px;">🚫</button>
                <?php else: ?>
                    <button class="action-btn" title="Lote Ya Bloqueado" disabled style="background:#cccccc; color:#666666; border:none; width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:11px; cursor:not-allowed;">🔒</button>
                <?php endif; ?>
                
                <button class="action-btn" title="Ver Trazabilidad Completa" onclick='mostrarTrazabilidad(<?php echo htmlspecialchars($trazabilidadJson, ENT_QUOTES, 'UTF-8'); ?>)' style="background:#462800; color:#fff; border:none; width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:11px;">🔍</button>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 30px;">
              <div class="text-11" style="color: #996e3f;">No hay lotes en el inventario actualmente. ¡Agrega uno!</div>
          </div>
      <?php endif; ?>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p" id="paginacionTexto">MOSTRANDO <?php echo $countLotes; ?> LOTES</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

    <div class="inventory-forecast">
      <div class="overlay-11">
        <div class="heading"><div class="text-wrapper-4">Optimización de Residuos</div></div>
        <p class="text-25">Detectamos que el control de fechas de caducidad está activo. Considera realizar ofertas para los lotes próximos a vencer.</p>
      </div>

      <div class="overlay-12">
        <div class="heading"><div class="text-wrapper-5">Previsión Semanal</div></div>
        <p class="text-25">La demanda de huevos orgánicos subió un 15%. Se recomienda mantener los niveles de stock disponibles altos.</p>
      </div>
    </div>

  </div>
</div>

<!-- ==========================================
     MODALES (CREATE, EDIT, DELETE)
     ========================================== -->

<!-- Modal Crear Lote -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Agregar Nuevo Lote</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    <form action="forms/inventario_acciones.php" method="POST">
      <input type="hidden" name="accion" value="crear">
      
      <div class="form-group">
        <label class="form-label">Proveedor *</label>
        <select name="proveedor_id" class="form-select" required>
          <option value="">Seleccione un proveedor...</option>
          <?php if ($proveedoresSelect && $proveedoresSelect->num_rows > 0): ?>
              <?php while ($p = $proveedoresSelect->fetch_assoc()): ?>
                  <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre_empresa']); ?></option>
              <?php endwhile; ?>
          <?php endif; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Tipo de Huevo (Producto) *</label>
        <select name="producto_id" class="form-select" required>
          <option value="">Seleccione el tipo de huevo...</option>
          <?php if ($productosSelect && $productosSelect->num_rows > 0): ?>
              <?php while ($pr = $productosSelect->fetch_assoc()): ?>
                  <option value="<?php echo $pr['id']; ?>"><?php echo htmlspecialchars($pr['tipo_huevo'] . " (" . $pr['tamano'] . ")"); ?></option>
              <?php endwhile; ?>
          <?php endif; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Código de Lote *</label>
        <input type="text" name="codigo_lote" class="form-input" required placeholder="Ej. #LT-2026-001" value="#LT-<?php echo date('Y'); ?>-<?php echo str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT); ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Cantidad (Unidades) *</label>
        <input type="number" name="cantidad" class="form-input" required min="0" placeholder="Ej. 450">
      </div>

      <div class="form-group">
        <label class="form-label">Fecha de Postura</label>
        <input type="date" name="fecha_postura" class="form-input" value="<?php echo date('Y-m-d'); ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Fecha de Caducidad</label>
        <input type="date" name="fecha_caducidad" class="form-input" value="<?php echo date('Y-m-d', strtotime('+28 days')); ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Estado</label>
        <select name="estado" class="form-select">
          <option value="disponible" selected>Disponible</option>
          <option value="bajo_stock">Bajo Stock</option>
          <option value="caducado">Caducado</option>
          <option value="vendido">Vendido</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrear')">Cancelar</button>
        <button type="submit" class="btn-submit">Guardar Lote</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar Lote -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Editar Lote de Inventario</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="forms/inventario_acciones.php" method="POST">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">
      
      <div class="form-group">
        <label class="form-label">Proveedor *</label>
        <select name="proveedor_id" id="edit_proveedor_id" class="form-select" required>
          <?php 
          if ($proveedoresSelect) $proveedoresSelect->data_seek(0);
          while ($p = $proveedoresSelect->fetch_assoc()): ?>
              <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre_empresa']); ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Tipo de Huevo (Producto) *</label>
        <select name="producto_id" id="edit_producto_id" class="form-select" required>
          <?php 
          if ($productosSelect) $productosSelect->data_seek(0);
          while ($pr = $productosSelect->fetch_assoc()): ?>
              <option value="<?php echo $pr['id']; ?>"><?php echo htmlspecialchars($pr['tipo_huevo'] . " (" . $pr['tamano'] . ")"); ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Código de Lote *</label>
        <input type="text" name="codigo_lote" id="edit_codigo_lote" class="form-input" required>
      </div>

      <div class="form-group">
        <label class="form-label">Cantidad (Unidades) *</label>
        <input type="number" name="cantidad" id="edit_cantidad" class="form-input" required min="0">
      </div>

      <div class="form-group">
        <label class="form-label">Fecha de Postura</label>
        <input type="date" name="fecha_postura" id="edit_fecha_postura" class="form-input">
      </div>

      <div class="form-group">
        <label class="form-label">Fecha de Caducidad</label>
        <input type="date" name="fecha_caducidad" id="edit_fecha_caducidad" class="form-input">
      </div>

      <div class="form-group">
        <label class="form-label">Estado</label>
        <select name="estado" id="edit_estado" class="form-select">
          <option value="disponible">Disponible</option>
          <option value="bajo_stock">Bajo Stock</option>
          <option value="caducado">Caducado</option>
          <option value="vendido">Vendido</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditar')">Cancelar</button>
        <button type="submit" class="btn-submit">Actualizar Lote</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Eliminar Lote -->
<div class="modal-overlay" id="modalEliminar">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Confirmar Eliminación de Lote</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="forms/inventario_acciones.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="delete_id">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar el lote <strong id="delete_codigo" style="color: #462800;"></strong> del inventario?<br>Esta acción eliminará el registro de stock permanentemente.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 10px 25px rgba(176, 37, 0, 0.15);">Eliminar Lote</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Trazabilidad de Lote -->
<div class="modal-overlay" id="modalTrazabilidad">
  <div class="modal-container" style="max-width: 500px;">
    <div class="modal-header">
      <div class="modal-title" style="color: #176a21;">Trazabilidad Completa del Lote</div>
      <button class="modal-close" onclick="cerrarModal('modalTrazabilidad')">×</button>
    </div>
    
    <div style="color: #462800; font-size: 14px; line-height: 1.8; margin-bottom: 24px;">
      <div style="background: rgba(213, 164, 112, 0.1); border-radius: 12px; padding: 16px; margin-bottom: 16px; border: 1px solid rgba(213, 164, 112, 0.2);">
        <p style="margin: 0 0 8px 0; font-family: 'Plus Jakarta Sans', sans-serif;"><strong>Código de Lote:</strong> <span id="traza_lote" style="font-weight: 700; color:#ff8a00;"></span></p>
        <p style="margin: 0 0 8px 0;"><strong>Granja de Origen / Proveedor:</strong> <span id="traza_proveedor" style="font-weight: 700;"></span></p>
        <p style="margin: 0 0 8px 0;"><strong>Módulo de Trazabilidad:</strong> <span style="font-weight: 700; color:#176a21;">Huevo 100% Orgánico Certificado</span></p>
      </div>
      
      <p style="margin: 6px 0; border-bottom: 1px solid rgba(213,164,112,.08); padding-bottom: 4px;"><strong>Tipo de Huevo:</strong> <span id="traza_tipo"></span></p>
      <p style="margin: 6px 0; border-bottom: 1px solid rgba(213,164,112,.08); padding-bottom: 4px;"><strong>Tamaño de Presentación:</strong> <span id="traza_tamano"></span></p>
      <p style="margin: 6px 0; border-bottom: 1px solid rgba(213,164,112,.08); padding-bottom: 4px;"><strong>Cantidad Disponible:</strong> <span id="traza_cantidad" style="font-weight: 700;"></span> ud.</p>
      <p style="margin: 6px 0; border-bottom: 1px solid rgba(213,164,112,.08); padding-bottom: 4px;"><strong>Fecha de Postura:</strong> <span id="traza_postura"></span></p>
      <p style="margin: 6px 0; border-bottom: 1px solid rgba(213,164,112,.08); padding-bottom: 4px;"><strong>Fecha de Vencimiento:</strong> <span id="traza_caducidad"></span></p>
      <p style="margin: 6px 0;"><strong>Estado de Almacén:</strong> <span id="traza_estado" style="font-weight:700;"></span></p>
    </div>

    <div class="modal-actions" style="margin-top: 20px;">
      <button type="button" class="btn-submit" style="background:#176a21; width:100%;" onclick="cerrarModal('modalTrazabilidad')">Entendido</button>
    </div>
  </div>
</div>

<!-- Modal Confirmación Bloqueo Lote -->
<div class="modal-overlay" id="modalBloquearLote">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Bloquear Lote Caducado</div>
      <button class="modal-close" onclick="cerrarModal('modalBloquearLote')">×</button>
    </div>
    <form action="forms/inventario_acciones.php" method="POST">
      <input type="hidden" name="accion" value="bloquear">
      <input type="hidden" name="id" id="block_id">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Deseas bloquear el lote <strong id="block_codigo" style="color: #462800;"></strong> en el inventario?<br>Esta acción cambiará el estado del lote a <strong>Caducado</strong> de forma inmediata para prevenir su venta y despacho logístico.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalBloquearLote')">Cancelar</button>
        <button type="submit" class="btn-submit" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 10px 25px rgba(176, 37, 0, 0.15);">Bloquear Lote</button>
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
    fetch('forms/inventario_acciones.php?accion=obtener&id=' + id)
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('edit_id').value = res.data.id;
                document.getElementById('edit_proveedor_id').value = res.data.proveedor_id;
                document.getElementById('edit_producto_id').value = res.data.producto_id;
                document.getElementById('edit_codigo_lote').value = res.data.codigo_lote;
                document.getElementById('edit_cantidad').value = res.data.cantidad;
                document.getElementById('edit_fecha_postura').value = res.data.fecha_postura || '';
                document.getElementById('edit_fecha_caducidad').value = res.data.fecha_caducidad || '';
                document.getElementById('edit_estado').value = res.data.estado;
                
                document.getElementById('modalEditar').classList.add('active');
            } else {
                alert('Error al obtener datos del lote: ' + res.message);
            }
        })
        .catch(err => {
            alert('Error en la comunicación con el servidor.');
        });
}

function confirmarEliminar(id, codigo) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_codigo').textContent = codigo;
    document.getElementById('modalEliminar').classList.add('active');
}

function mostrarTrazabilidad(data) {
    document.getElementById('traza_lote').textContent = data.lote;
    document.getElementById('traza_proveedor').textContent = data.proveedor;
    document.getElementById('traza_tipo').textContent = data.tipo;
    document.getElementById('traza_tamano').textContent = data.tamano;
    document.getElementById('traza_cantidad').textContent = data.cantidad;
    document.getElementById('traza_postura').textContent = data.postura;
    document.getElementById('traza_caducidad').textContent = data.caducidad;
    document.getElementById('traza_estado').textContent = data.estado;
    document.getElementById('modalTrazabilidad').classList.add('active');
}

function bloquearLote(id, codigo) {
    document.getElementById('block_id').value = id;
    document.getElementById('block_codigo').textContent = codigo;
    document.getElementById('modalBloquearLote').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Búsqueda y filtros interactivos
function filtrarTabla() {
    const query = document.getElementById('buscarLote').value.toLowerCase();
    const rows = document.querySelectorAll('#tablaCuerpo .row-lote');
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

    document.getElementById('paginacionTexto').textContent = `MOSTRANDO ${visibleCount} DE ${rows.length} LOTES`;
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

    const rows = document.querySelectorAll('#tablaCuerpo .row-lote');
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

    document.getElementById('paginacionTexto').textContent = `MOSTRANDO ${visibleCount} DE ${rows.length} LOTES`;
}

// Auto-filtro por URL si viene parámetro de búsqueda (ej. buscar=NombreProveedor)
window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const buscarVal = urlParams.get('buscar');
    if (buscarVal) {
        document.getElementById('buscarLote').value = buscarVal;
        filtrarTabla();
    }
});
</script>
</body>
</html>