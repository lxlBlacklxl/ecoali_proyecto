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

// --- INICIALIZACIÓN DE DATOS DE PRUEBA SI LA BD ESTÁ VACÍA ---

// 1. Verificar y precargar un cliente si no existe
$checkCliente = $conn->query("SELECT id FROM usuarios WHERE rol_id = 2 LIMIT 1");
if ($checkCliente && $checkCliente->num_rows === 0) {
    // Insertar usuario cliente
    $passHash = password_hash("cliente123", PASSWORD_BCRYPT);
    $conn->query("INSERT INTO usuarios (usuario, password_hash, rol_id, activo) VALUES ('elena_rivas', '$passHash', 2, 1)");
    $clienteId = $conn->insert_id;
    $conn->query("INSERT INTO usuario_perfil (usuario_id, nombre, apellido, direccion, telefono, email) 
                  VALUES ($clienteId, 'Elena', 'Rivas', 'Calle de Alcalá, 45, Madrid', '600123456', 'elena@rivas.com')");
}

// 2. Verificar y precargar repartidores si no existen
$checkRepartidor = $conn->query("SELECT id FROM usuarios WHERE rol_id = 4 LIMIT 1");
if ($checkRepartidor && $checkRepartidor->num_rows === 0) {
    // Insertar dos repartidores de prueba
    $passHash = password_hash("repartidor123", PASSWORD_BCRYPT);
    
    $conn->query("INSERT INTO usuarios (usuario, password_hash, rol_id, activo) VALUES ('carlos_repartidor', '$passHash', 4, 1)");
    $repId1 = $conn->insert_id;
    $conn->query("INSERT INTO usuario_perfil (usuario_id, nombre, apellido, direccion, telefono, email) 
                  VALUES ($repId1, 'Carlos', 'Gómez', 'Av. de la Libertad, 12, Sevilla', '600987654', 'carlos@ecoali.com')");
                  
    $conn->query("INSERT INTO usuarios (usuario, password_hash, rol_id, activo) VALUES ('ana_repartidor', '$passHash', 4, 1)");
    $repId2 = $conn->insert_id;
    $conn->query("INSERT INTO usuario_perfil (usuario_id, nombre, apellido, direccion, telefono, email) 
                  VALUES ($repId2, 'Ana', 'Belén', 'Plaza de Cataluña, 8, Barcelona', '600555555', 'ana@ecoali.com')");
}

// 3. Verificar y precargar pedidos iniciales de demostración
$checkPedidos = $conn->query("SELECT id FROM pedidos LIMIT 1");
if ($checkPedidos && $checkPedidos->num_rows === 0) {
    // Obtener ids de clientes y repartidores reales
    $cliRow = $conn->query("SELECT usuario_id FROM usuario_perfil WHERE email = 'elena@rivas.com'")->fetch_assoc();
    $repRow = $conn->query("SELECT usuario_id FROM usuario_perfil WHERE email = 'carlos@ecoali.com'")->fetch_assoc();
    $repRow2 = $conn->query("SELECT usuario_id FROM usuario_perfil WHERE email = 'ana@ecoali.com'")->fetch_assoc();
    
    $cliId = $cliRow ? $cliRow['usuario_id'] : 1;
    $repId = $repRow ? $repRow['usuario_id'] : null;
    $repId2 = $repRow2 ? $repRow2['usuario_id'] : null;
    
    $conn->query("INSERT INTO pedidos (cliente_id, repartidor_id, total, estado) VALUES ($cliId, NULL, 45.50, 'pendiente')");
    $conn->query("INSERT INTO pedidos (cliente_id, repartidor_id, total, estado) VALUES ($cliId, $repId, 120.00, 'en_ruta')");
    $conn->query("INSERT INTO pedidos (cliente_id, repartidor_id, total, estado) VALUES ($cliId, $repId2, 32.80, 'entregado')");
}

// --- CONSULTAS DINÁMICAS PARA LOGÍSTICA ---

// Pedidos Totales
$totPedRes = $conn->query("SELECT COUNT(*) FROM pedidos");
$pedidosTotales = $totPedRes ? $totPedRes->fetch_row()[0] : 0;

// Pedidos Pendientes
$pendPedRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente'");
$pedidosPendientes = $pendPedRes ? $pendPedRes->fetch_row()[0] : 0;

// Pedidos En Ruta
$rutaPedRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'en_ruta'");
$pedidosEnRuta = $rutaPedRes ? $rutaPedRes->fetch_row()[0] : 0;

// Pedidos Entregados
$entregPedRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'entregado'");
$pedidosEntregados = $entregPedRes ? $entregPedRes->fetch_row()[0] : 0;

// Obtener listas de clientes y repartidores para selectores
$clientesSelect = $conn->query("SELECT u.id, CONCAT(up.nombre, ' ', up.apellido) AS nombre FROM usuarios u INNER JOIN usuario_perfil up ON u.id = up.usuario_id WHERE u.rol_id = 2 ORDER BY up.nombre ASC");
$repartidoresSelect = $conn->query("SELECT u.id, CONCAT(up.nombre, ' ', up.apellido) AS nombre FROM usuarios u INNER JOIN usuario_perfil up ON u.id = up.usuario_id WHERE u.rol_id = 4 ORDER BY up.nombre ASC");

// Obtener listado de pedidos dinámicos con JOINS
$sqlPedidosList = "SELECT p.*, 
                          CONCAT(upc.nombre, ' ', upc.apellido) AS nombre_cliente, 
                          upc.direccion AS direccion_cliente,
                          CONCAT(upr.nombre, ' ', upr.apellido) AS nombre_repartidor
                   FROM pedidos p
                   LEFT JOIN usuario_perfil upc ON p.cliente_id = upc.usuario_id
                   LEFT JOIN usuario_perfil upr ON p.repartidor_id = upr.usuario_id
                   ORDER BY p.id DESC";
$resultPedidos = $conn->query($sqlPedidosList);
$countPedidos = $resultPedidos ? $resultPedidos->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Pedidos y Logística - ECOALI</title>

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
    <div class="div-4"><div class="text-26">Gestión de Pedidos y Logística</div></div>

    <div style="display: flex; gap: 12px;">
      <button class="button-2" style="background: linear-gradient(90deg, #ff8a00, #ffb300); box-shadow: 0 18px 35px rgba(255, 138, 0, 0.15);" onclick="abrirModalImprimir()"><div class="text-3">Imprimir Pedidos</div></button>
      <button class="button-2" onclick="abrirModalCrear()"><div class="text-3">Asignar pedido</div></button>
    </div>
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
            <input type="text" id="buscarPedido" placeholder="Buscar por ID de pedido, cliente o dirección..." onkeyup="filtrarTabla()" style="border:none; width:100%; outline:none; font-family:inherit; color:#462800; font-size:13px; font-weight:600;">
          </div>
        </div>
      </div>

      <div class="container-2">
        <div class="background-shadow">
          <button class="button" onclick="filtrarEstado('todos', this)"><div class="text">Todos</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('pendiente', this)"><div class="text-2">Pendiente</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('en_ruta', this)"><div class="text-2">En ruta</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('entregado', this)"><div class="text-2">Entregado</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('cancelado', this)"><div class="text-2">Cancelado</div></button>
        </div>
      </div>
    </div>

    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
      <div class="background-border">
        <div class="container-5"><div class="text-wrapper-2">Pedidos Totales</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $pedidosTotales; ?></div></div>
      </div>

      <div class="background-border-2">
        <div class="container-5"><div class="text-wrapper-2">Pedidos Pendientes</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $pedidosPendientes; ?></div></div>
      </div>

      <div class="background-border-3">
        <div class="container-5"><div class="text-wrapper-2">Pedidos En Ruta</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $pedidosEnRuta; ?></div></div>
      </div>

      <div class="background-border-2" style="border-color: rgba(23, 106, 33, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #176a21;">Pedidos Entregados</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #176a21;"><?php echo $pedidosEntregados; ?></div></div>
      </div>
    </div>

    <div class="inventory-table">
      <div class="header">
        <div class="row" style="grid-template-columns: 1fr 1.8fr 2.5fr 1.8fr 1.2fr 1.8fr 1.2fr 1.3fr;">
          <div><div class="text-7">ID PEDIDO</div></div>
          <div><div class="text-7">CLIENTE</div></div>
          <div><div class="text-7">DIRECCIÓN</div></div>
          <div><div class="text-8">REPARTIDOR</div></div>
          <div><div class="text-7">ESTADO</div></div>
          <div><div class="text-7">FECHA</div></div>
          <div><div class="text-7">TOTAL</div></div>
          <div><div class="text-9">ACCIONES</div></div>
        </div>
      </div>

      <div id="tablaCuerpo">
      <?php if ($countPedidos > 0): ?>
          <?php while ($row = $resultPedidos->fetch_assoc()): 
              $estado = strtolower($row["estado"]);
              $estadoClass = "overlay-10";
              $bgClass = "background-9";
              $estadoText = "Pendiente";

              if ($estado === "preparado") {
                  $estadoClass = "overlay-7";
                  $bgClass = "background-5";
                  $estadoText = "Preparado";
              } elseif ($estado === "en_ruta") {
                  $estadoClass = "overlay-7";
                  $bgClass = "background-5";
                  $estadoText = "En Ruta";
              } elseif ($estado === "entregado") {
                  $estadoClass = "overlay-6";
                  $bgClass = "background-3";
                  $estadoText = "Entregado";
              } elseif ($estado === "cancelado") {
                  $estadoClass = "overlay-9";
                  $bgClass = "background-7";
                  $estadoText = "Cancelado";
              }
          ?>
          <div class="div-3 row-pedido" data-estado="<?php echo $estado; ?>" style="grid-template-columns: 1fr 1.8fr 2.5fr 1.8fr 1.2fr 1.8fr 1.2fr 1.3fr; border-bottom: 1px solid rgba(213,164,112,.12);">
            <div><div class="text-10">#PED-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?></div></div>
            <div><div class="text-11" style="font-weight: 700;"><?php echo htmlspecialchars($row["nombre_cliente"] ?? "Cliente Anónimo"); ?></div></div>
            <div><div class="text-12"><?php echo htmlspecialchars($row["direccion_cliente"] ?? "Sin dirección de entrega"); ?></div></div>
            <div><div class="text-14"><?php echo htmlspecialchars($row["nombre_repartidor"] ?? "No asignado"); ?></div></div>
            <div>
              <div class="<?php echo $estadoClass; ?>">
                <div class="<?php echo $bgClass; ?>"></div>
                <div class="text-15" style="color: inherit;"><?php echo $estadoText; ?></div>
              </div>
            </div>
            <div><div class="text-14"><?php echo date("d M Y, H:i", strtotime($row["fecha_pedido"])); ?></div></div>
            <div>
              <div class="background-2">
                <div class="text-13">$<?php echo number_format($row["total"], 2); ?></div>
              </div>
            </div>
            <div>
              <div class="text-10" style="display: flex; gap: 8px;">
                <button class="action-btn action-btn-edit" title="Gestionar Pedido / Repartidor" onclick="abrirModalEditar(<?php echo $row['id']; ?>)">✎</button>
                <button class="action-btn action-btn-delete" title="Cancelar Pedido" onclick="confirmarEliminar(<?php echo $row['id']; ?>)">🗑</button>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 30px;">
              <div class="text-11" style="color: #996e3f;">No hay pedidos en la base de datos de logística.</div>
          </div>
      <?php endif; ?>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p" id="paginacionTexto">MOSTRANDO <?php echo $countPedidos; ?> PEDIDOS</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

    <div class="inventory-forecast">
      <div class="overlay-11">
        <div class="heading"><div class="text-wrapper-4">Ruta de Reparto</div></div>
        <p class="text-25">Optimización en tiempo real de rutas, repartidores asignados y control de estado.</p>
      </div>

      <div class="overlay-12">
        <div class="heading"><div class="text-wrapper-5">Alertas de Reparto</div></div>
        <p class="text-25">Todos los repartidores activos están monitoreados. Recuerda asignar los pedidos pendientes con prioridad.</p>
      </div>
    </div>

  </div>
</div>

<!-- ==========================================
     MODALES (CREATE, EDIT, DELETE)
     ========================================== -->

<!-- Modal Opciones de Impresión -->
<div class="modal-overlay" id="modalImprimir">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title">Imprimir Registro de Pedidos</div>
      <button class="modal-close" onclick="cerrarModal('modalImprimir')">×</button>
    </div>
    <div style="display: flex; flex-direction: column; gap: 14px; margin-top: 10px; margin-bottom: 10px;">
      <p style="color: #7a5427; font-size: 13px; line-height: 1.5; margin-bottom: 8px;">
        Selecciona la categoría de pedidos que deseas imprimir o guardar en formato PDF:
      </p>
      
      <a href="imprimir_pedidos.php?filtro=todos" target="_blank" class="btn-submit" style="text-align: center; text-decoration: none; display: block; background: #8d4a00; color: white;" onclick="cerrarModal('modalImprimir')">
        🖨️ Imprimir Todos los Pedidos
      </a>
      
      <a href="imprimir_pedidos.php?filtro=entregados" target="_blank" class="btn-submit" style="text-align: center; text-decoration: none; display: block; background: #176a21; color: white;" onclick="cerrarModal('modalImprimir')">
        🖨️ Imprimir Pedidos Entregados
      </a>
      
      <a href="imprimir_pedidos.php?filtro=cancelados" target="_blank" class="btn-submit" style="text-align: center; text-decoration: none; display: block; background: #b02500; color: white;" onclick="cerrarModal('modalImprimir')">
        🖨️ Imprimir Pedidos Cancelados
      </a>
      
      <a href="imprimir_pedidos.php?filtro=no_asignados" target="_blank" class="btn-submit" style="text-align: center; text-decoration: none; display: block; background: #ff8a00; color: white;" onclick="cerrarModal('modalImprimir')">
        🖨️ Imprimir Pedidos No Asignados
      </a>
    </div>
    <div class="modal-actions" style="margin-top: 20px;">
      <button type="button" class="btn-cancel" onclick="cerrarModal('modalImprimir')">Cerrar</button>
    </div>
  </div>
</div>

<!-- Modal Crear/Asignar Pedido -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Asignar / Crear Pedido</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    <form action="forms/logistica_acciones.php" method="POST">
      <input type="hidden" name="accion" value="crear">
      
      <div class="form-group">
        <label class="form-label">Cliente *</label>
        <select name="cliente_id" class="form-select" required>
          <option value="">Seleccione un cliente...</option>
          <?php if ($clientesSelect && $clientesSelect->num_rows > 0): ?>
              <?php while ($c = $clientesSelect->fetch_assoc()): ?>
                  <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option>
              <?php endwhile; ?>
          <?php endif; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Repartidor (Opcional)</label>
        <select name="repartidor_id" class="form-select">
          <option value="">No asignado</option>
          <?php if ($repartidoresSelect && $repartidoresSelect->num_rows > 0): ?>
              <?php while ($r = $repartidoresSelect->fetch_assoc()): ?>
                  <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['nombre']); ?></option>
              <?php endwhile; ?>
          <?php endif; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Total del Pedido ($) *</label>
        <input type="number" step="0.01" name="total" class="form-input" required placeholder="Ej. 45.50">
      </div>

      <div class="form-group">
        <label class="form-label">Estado de Logística</label>
        <select name="estado" class="form-select">
          <option value="pendiente" selected>Pendiente</option>
          <option value="preparado">Preparado</option>
          <option value="en_ruta">En Ruta</option>
          <option value="entregado">Entregado</option>
          <option value="cancelado">Cancelado</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrear')">Cancelar</button>
        <button type="submit" class="btn-submit">Asignar Pedido</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar Pedido -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Editar Detalles del Pedido</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="forms/logistica_acciones.php" method="POST">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">
      
      <div class="form-group">
        <label class="form-label">Cliente *</label>
        <select name="cliente_id" id="edit_cliente_id" class="form-select" required>
          <?php 
          if ($clientesSelect) $clientesSelect->data_seek(0);
          while ($c = $clientesSelect->fetch_assoc()): ?>
              <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Repartidor Asignado</label>
        <select name="repartidor_id" id="edit_repartidor_id" class="form-select">
          <option value="">No asignado</option>
          <?php 
          if ($repartidoresSelect) $repartidoresSelect->data_seek(0);
          while ($r = $repartidoresSelect->fetch_assoc()): ?>
              <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['nombre']); ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Total del Pedido ($) (Costo Fijo Inmutable) 🔒</label>
        <input type="number" step="0.01" name="total" id="edit_total" class="form-input" readonly style="background:#f9f6f0; color:#8c7864; cursor:not-allowed; font-weight:700; border-color:#e0d5c1;" required>
      </div>

      <div class="form-group">
        <label class="form-label">Estado de Logística</label>
        <select name="estado" id="edit_estado" class="form-select">
          <option value="pendiente">Pendiente</option>
          <option value="preparado">Preparado</option>
          <option value="en_ruta">En Ruta</option>
          <option value="entregado">Entregado</option>
          <option value="cancelado">Cancelado</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditar')">Cancelar</button>
        <button type="submit" class="btn-submit">Guardar Cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Eliminar Pedido -->
<div class="modal-overlay" id="modalEliminar">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Eliminar Pedido</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="forms/logistica_acciones.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="delete_id">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar permanentemente el pedido <strong id="delete_pedido_text" style="color: #462800;"></strong>?<br>Esta acción eliminará de forma irreversible el pedido del registro de logística.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 10px 25px rgba(176, 37, 0, 0.15);">Eliminar Pedido</button>
      </div>
    </form>
  </div>
</div>

<!-- ==========================================
     SCRIPTS DE CONTROL INTERACTIVO
     ========================================== -->
<script>
function abrirModalImprimir() {
    document.getElementById('modalImprimir').classList.add('active');
}

function abrirModalCrear() {
    document.getElementById('modalCrear').classList.add('active');
}

function abrirModalEditar(id) {
    fetch('forms/logistica_acciones.php?accion=obtener&id=' + id)
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('edit_id').value = res.data.id;
                document.getElementById('edit_cliente_id').value = res.data.cliente_id;
                document.getElementById('edit_repartidor_id').value = res.data.repartidor_id || '';
                document.getElementById('edit_total').value = res.data.total;
                document.getElementById('edit_estado').value = res.data.estado;
                
                document.getElementById('modalEditar').classList.add('active');
            } else {
                alert('Error al obtener datos del pedido: ' + res.message);
            }
        })
        .catch(err => {
            alert('Error en la comunicación con el servidor.');
        });
}

function confirmarEliminar(id) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_pedido_text').textContent = "#PED-" + String(id).padStart(3, '0');
    document.getElementById('modalEliminar').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Búsqueda y filtros interactivos con paginación
let paginaActual = 1;
const registrosPorPagina = 5;
let filtroQuery = "";
let filtroEstado = "todos";

function actualizarVista() {
    const rows = document.querySelectorAll('#tablaCuerpo .row-pedido');
    const matchingRows = [];
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const rEstado = row.getAttribute('data-estado');
        
        const matchesQuery = text.includes(filtroQuery);
        const matchesEstado = (filtroEstado === 'todos' || rEstado === filtroEstado || (filtroEstado === 'en_ruta' && rEstado === 'en proceso'));
        
        if (matchesQuery && matchesEstado) {
            matchingRows.push(row);
        } else {
            row.style.display = 'none';
        }
    });

    const totalRegistros = matchingRows.length;
    const totalPaginas = Math.ceil(totalRegistros / registrosPorPagina) || 1;

    // Asegurar rango válido de página
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
        `MOSTRANDO ${mostradosInicio}-${mostradosFin} DE ${totalRegistros} PEDIDOS`;

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
    actualizarVista();
}

function filtrarTabla() {
    filtroQuery = document.getElementById('buscarPedido').value.toLowerCase();
    paginaActual = 1;
    actualizarVista();
}

function filtrarEstado(estado, btn) {
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

    filtroEstado = estado;
    paginaActual = 1;
    actualizarVista();
}

// Inicializar la vista con paginación al cargar el documento
document.addEventListener("DOMContentLoaded", () => {
    actualizarVista();
});
</script>
</body>
</html>