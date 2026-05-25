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

// 1. Clientes totales
$totCliRes = $conn->query("SELECT COUNT(*) FROM usuarios WHERE rol_id = 2");
$totalClientes = $totCliRes ? $totCliRes->fetch_row()[0] : 0;

// 2. Clientes activos
$actCliRes = $conn->query("SELECT COUNT(*) FROM usuarios WHERE rol_id = 2 AND activo = 1");
$activosClientes = $actCliRes ? $actCliRes->fetch_row()[0] : 0;

// 3. Clientes inactivos
$inactCliRes = $conn->query("SELECT COUNT(*) FROM usuarios WHERE rol_id = 2 AND activo = 0");
$inactivosClientes = $inactCliRes ? $inactCliRes->fetch_row()[0] : 0;

// 4. Clientes con pedidos
$conPedRes = $conn->query("SELECT COUNT(DISTINCT cliente_id) FROM pedidos");
$conPedidosClientes = $conPedRes ? $conPedRes->fetch_row()[0] : 0;

// Obtener listado de clientes con compras y pedidos agregados
$queryList = "SELECT u.id, u.activo, up.nombre, up.apellido, up.email, up.direccion, up.telefono,
                     COUNT(p.id) AS total_pedidos,
                     COALESCE(SUM(p.total), 0) AS total_gastado
              FROM usuarios u
              LEFT JOIN usuario_perfil up ON u.id = up.usuario_id
              LEFT JOIN pedidos p ON u.id = p.cliente_id
              WHERE u.rol_id = 2
              GROUP BY u.id
              ORDER BY total_gastado DESC, u.id DESC";
$resultList = $conn->query($queryList);
$countList = $resultList ? $resultList->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Clientes - ECOALI</title>

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
    <div class="div-4"><div class="text-26">Cartera de Clientes y Consumo</div></div>
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
            <input type="text" id="buscarCliente" placeholder="Buscar por nombre, correo o dirección de entrega..." onkeyup="filtrarTabla()" style="border:none; width:100%; outline:none; font-family:inherit; color:#462800; font-size:13px; font-weight:600;">
          </div>
        </div>
      </div>
    </div>

    <!-- Indicadores -->
    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
      <div class="background-border">
        <div class="container-5"><div class="text-wrapper-2">Clientes Totales</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $totalClientes; ?></div></div>
      </div>

      <div class="background-border-2" style="border-color: rgba(23, 106, 33, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #176a21;">Clientes Activos</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #176a21;"><?php echo $activosClientes; ?></div></div>
      </div>

      <div class="background-border-3">
        <div class="container-5"><div class="text-wrapper-2">Clientes Inactivos</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $inactivosClientes; ?></div></div>
      </div>

      <div class="background-border" style="border-color: rgba(255, 138, 0, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #ff8a00;">Con Pedidos</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #ff8a00;"><?php echo $conPedidosClientes; ?></div></div>
      </div>
    </div>

    <!-- Tabla de Clientes -->
    <div class="inventory-table" style="margin-top: 24px;">
      <div class="header">
        <div class="row" style="grid-template-columns: 1fr 2fr 2fr 2.5fr 1.2fr 1.2fr 1fr 1.5fr;">
          <div><div class="text-7">ID</div></div>
          <div><div class="text-7">NOMBRE COMPLETO</div></div>
          <div><div class="text-7">CORREO</div></div>
          <div><div class="text-7">DIRECCIÓN</div></div>
          <div><div class="text-8">Nº PEDIDOS</div></div>
          <div><div class="text-7">TOTAL GASTADO</div></div>
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
          <div class="div-3 row-cliente" style="grid-template-columns: 1fr 2fr 2fr 2.5fr 1.2fr 1.2fr 1fr 1.5fr; border-bottom: 1px solid rgba(213,164,112,.12);">
            <div><div class="text-10">#CLI-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?></div></div>
            <div><div class="text-11" style="font-weight: 700;"><?php echo htmlspecialchars(($row["nombre"] ?? "Anónimo") . " " . ($row["apellido"] ?? "")); ?></div></div>
            <div><div class="text-14" style="text-transform: none;"><?php echo htmlspecialchars($row["email"] ?? "N/A"); ?></div></div>
            <div><div class="text-12"><?php echo htmlspecialchars($row["direccion"] ?? "Sin dirección registrada"); ?></div></div>
            <div>
              <div class="background-2">
                <div class="text-13"><?php echo $row["total_pedidos"]; ?> ped.</div>
              </div>
            </div>
            <div><div class="text-11" style="font-weight: 700; color: #176a21;">$<?php echo number_format($row["total_gastado"], 2); ?></div></div>
            <div>
              <div class="<?php echo $estadoClass; ?>">
                <div class="<?php echo $bgClass; ?>"></div>
                <div class="text-15" style="color: inherit;"><?php echo $estadoText; ?></div>
              </div>
            </div>
            <div>
              <div class="text-10" style="display: flex; gap: 6px; align-items: center;">
                
                <button class="action-btn" title="Historial de Consumo" onclick="abrirConsumo(<?php echo $row['id']; ?>, '<?php echo addslashes(($row['nombre'] ?? 'Cliente') . ' ' . $row['apellido']); ?>')" style="background:#176a21; color:#fff; border:none; width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:11px;">📊</button>
                
                <?php if ($activo === 1): ?>
                    <button class="action-btn" title="Desactivar Cliente" onclick="abrirConfirmarEstado(<?php echo $row['id']; ?>, 0, '<?php echo addslashes($row['nombre']); ?>')" style="background:#b02500; color:#fff; border:none; width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:11px;">🚫</button>
                <?php else: ?>
                    <button class="action-btn" title="Activar Cliente" onclick="abrirConfirmarEstado(<?php echo $row['id']; ?>, 1, '<?php echo addslashes($row['nombre']); ?>')" style="background:#d5a470; color:#fff; border:none; width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:11px;">✓</button>
                <?php endif; ?>
                
              </div>
            </div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 30px;">
              <div class="text-11" style="color: #996e3f;">No hay perfiles de clientes registrados en el sistema.</div>
          </div>
      <?php endif; ?>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p" id="paginacionTexto">MOSTRANDO <?php echo $countList; ?> CLIENTES</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal Historial de Consumo -->
<div class="modal-overlay" id="modalConsumo">
  <div class="modal-container" style="max-width: 600px;">
    <div class="modal-header">
      <div class="modal-title">Historial de Pedidos de <span id="consumo_nombre" style="color:#176a21;"></span></div>
      <button class="modal-close" onclick="cerrarModal('modalConsumo')">×</button>
    </div>
    
    <div style="max-height: 350px; overflow-y: auto; margin-bottom: 20px; border-radius: 8px; border: 1px solid rgba(213, 164, 112, 0.15);">
      <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
        <thead>
          <tr style="background: rgba(213, 164, 112, 0.1); border-bottom: 1px solid rgba(213, 164, 112, 0.2);">
            <th style="padding: 10px; color:#462800; font-weight:700;">ID Pedido</th>
            <th style="padding: 10px; color:#462800; font-weight:700;">Fecha</th>
            <th style="padding: 10px; color:#462800; font-weight:700;">Repartidor</th>
            <th style="padding: 10px; color:#462800; font-weight:700;">Estado</th>
            <th style="padding: 10px; color:#462800; font-weight:700; text-align: right;">Monto</th>
          </tr>
        </thead>
        <tbody id="consumo_cuerpo">
          <!-- Carga vía AJAX -->
        </tbody>
      </table>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn-submit" style="background:#176a21; width:100%;" onclick="cerrarModal('modalConsumo')">Cerrar Historial</button>
    </div>
  </div>
</div>

<!-- Modal Confirmar Cambio de Estado -->
<div class="modal-overlay" id="modalConfirmarEstado">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title" id="estado_titulo">Cambiar Estado de Cliente</div>
      <button class="modal-close" onclick="cerrarModal('modalConfirmarEstado')">×</button>
    </div>
    <form action="forms/clientes_acciones.php" method="POST">
      <input type="hidden" name="accion" value="cambiar_estado">
      <input type="hidden" name="id" id="estado_id">
      <input type="hidden" name="activo" id="estado_activo">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Deseas <strong id="estado_accion_texto"></strong> el acceso del cliente <strong id="estado_nombre_cliente" style="color: #462800;"></strong> en el sistema?
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalConfirmarEstado')">Cancelar</button>
        <button type="submit" class="btn-submit" id="estado_btn_submit">Confirmar Cambios</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirConsumo(id, nombre) {
    document.getElementById('consumo_nombre').textContent = nombre;
    const cuerpo = document.getElementById('consumo_cuerpo');
    cuerpo.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#7a5427;">Cargando pedidos...</td></tr>';
    
    document.getElementById('modalConsumo').classList.add('active');
    
    fetch('forms/clientes_acciones.php?accion=obtener_pedidos&id=' + id)
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                if (res.data.length === 0) {
                    cuerpo.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#7a5427;">Este cliente no registra ningún pedido aún.</td></tr>';
                    return;
                }
                
                let html = '';
                res.data.forEach(ped => {
                    html += `<tr style="border-bottom: 1px solid rgba(213, 164, 112, 0.08);">
                        <td style="padding:10px; font-weight:700;">#PED-${String(ped.id).padStart(3, '0')}</td>
                        <td style="padding:10px;">${ped.fecha}</td>
                        <td style="padding:10px; color:#7a5427;">${ped.repartidor}</td>
                        <td style="padding:10px;"><strong style="color: ${ped.estado === 'Entregado' ? '#176a21' : (ped.estado === 'Cancelado' ? '#b02500' : '#ff8a00')}">${ped.estado}</strong></td>
                        <td style="padding:10px; text-align:right; font-weight:700; color:#176a21;">$${ped.total.toFixed(2)}</td>
                    </tr>`;
                });
                cuerpo.innerHTML = html;
            } else {
                cuerpo.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:#b02500;">Error: ${res.message}</td></tr>`;
            }
        })
        .catch(err => {
            cuerpo.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#b02500;">Error al conectar con el servidor.</td></tr>';
        });
}

function abrirConfirmarEstado(id, activo, nombre) {
    document.getElementById('estado_id').value = id;
    document.getElementById('estado_activo').value = activo;
    document.getElementById('estado_nombre_cliente').textContent = nombre;
    
    const titulo = document.getElementById('estado_titulo');
    const accionTexto = document.getElementById('estado_accion_texto');
    const btn = document.getElementById('estado_btn_submit');
    
    if (activo === 1) {
        titulo.textContent = 'Activar Cuenta de Cliente';
        titulo.style.color = '#176a21';
        accionTexto.textContent = 'ACTIVAR y restaurar';
        btn.textContent = 'Activar Cliente';
        btn.style.background = '#176a21';
    } else {
        titulo.textContent = 'Desactivar Cuenta de Cliente';
        titulo.style.color = '#b02500';
        accionTexto.textContent = 'DESACTIVAR e inhabilitar';
        btn.textContent = 'Desactivar Cliente';
        btn.style.background = '#b02500';
    }
    
    document.getElementById('modalConfirmarEstado').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Búsqueda en tiempo real por texto
function filtrarTabla() {
    const query = document.getElementById('buscarCliente').value.toLowerCase();
    const rows = document.querySelectorAll('#tablaCuerpo .row-cliente');
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

    document.getElementById('paginacionTexto').textContent = `MOSTRANDO ${visibleCount} DE ${rows.length} CLIENTES`;
}
</script>
</body>
</html>
