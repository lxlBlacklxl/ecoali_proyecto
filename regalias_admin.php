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

// Precargar datos de demostración de Regalías si la base de datos de regalías está vacía
$checkRegalias = $conn->query("SELECT id FROM regalias LIMIT 1");
if ($checkRegalias && $checkRegalias->num_rows === 0) {
    // Buscar dos clientes reales registrados
    $cRes = $conn->query("SELECT usuario_id FROM usuario_perfil LIMIT 2");
    if ($cRes && $cRes->num_rows >= 2) {
        $c1 = $cRes->fetch_assoc()["usuario_id"];
        $c2 = $cRes->fetch_assoc()["usuario_id"];
        
        // Registrar comisiones simuladas
        $conn->query("INSERT INTO regalias (usuario_beneficiado_id, usuario_referido_id, pedido_id, nivel, monto, estado) VALUES ($c1, $c2, 1, 1, 15.50, 'pendiente')");
        $conn->query("INSERT INTO regalias (usuario_beneficiado_id, usuario_referido_id, pedido_id, nivel, monto, estado) VALUES ($c1, $c2, 2, 1, 35.00, 'pagado')");
    }
}

// 1. Comisiones Totales Generadas
$totRegRes = $conn->query("SELECT SUM(monto) FROM regalias");
$totalRoyalties = $totRegRes && !is_null($row = $totRegRes->fetch_row()) ? (float)$row[0] : 0.0;

// 2. Comisiones Pagadas
$pagRegRes = $conn->query("SELECT SUM(monto) FROM regalias WHERE estado = 'pagado'");
$pagadasRoyalties = $pagRegRes && !is_null($row = $pagRegRes->fetch_row()) ? (float)$row[0] : 0.0;

// 3. Comisiones Pendientes
$penRegRes = $conn->query("SELECT SUM(monto) FROM regalias WHERE estado = 'pendiente'");
$pendientesRoyalties = $penRegRes && !is_null($row = $penRegRes->fetch_row()) ? (float)$row[0] : 0.0;

// Obtener listado detallado de referidos y regalías
$queryList = "SELECT r.id, r.nivel, r.monto, r.estado, r.fecha, r.pedido_id,
                     CONCAT(upb.nombre, ' ', upb.apellido) AS nombre_beneficiario,
                     CONCAT(upr.nombre, ' ', upr.apellido) AS nombre_referido
              FROM regalias r
              LEFT JOIN usuario_perfil upb ON r.usuario_beneficiado_id = upb.usuario_id
              LEFT JOIN usuario_perfil upr ON r.usuario_referido_id = upr.usuario_id
              ORDER BY r.id DESC";
$resultList = $conn->query($queryList);
$countList = $resultList ? $resultList->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadena de Regalías y Referidos - ECOALI</title>

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
    <div class="div-4"><div class="text-26">Cadena de Regalías y Referidos</div></div>
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
            <input type="text" id="buscarRegalia" placeholder="Buscar por beneficiario, referido, pedido..." onkeyup="filtrarTabla()" style="border:none; width:100%; outline:none; font-family:inherit; color:#462800; font-size:13px; font-weight:600;">
          </div>
        </div>
      </div>
    </div>

    <!-- Tarjetas de Métricas de Comisión -->
    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
      <div class="background-border">
        <div class="container-5"><div class="text-wrapper-2">Comisiones Totales</div></div>
        <div class="div-2"><div class="text-wrapper-3">$<?php echo number_format($totalRoyalties, 2); ?></div></div>
      </div>

      <div class="background-border-2" style="border-color: rgba(23, 106, 33, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #176a21;">Comisiones Pagadas</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #176a21;">$<?php echo number_format($pagadasRoyalties, 2); ?></div></div>
      </div>

      <div class="background-border-3">
        <div class="container-5"><div class="text-wrapper-2">Comisiones Pendientes</div></div>
        <div class="div-2"><div class="text-wrapper-3">$<?php echo number_format($pendientesRoyalties, 2); ?></div></div>
      </div>
    </div>

    <!-- Tabla de Regalías -->
    <div class="inventory-table" style="margin-top: 24px;">
      <div class="header">
        <div class="row" style="grid-template-columns: 1fr 2fr 2fr 1.5fr 1fr 1.2fr 1.2fr 1.5fr 1.5fr;">
          <div><div class="text-7">ID</div></div>
          <div><div class="text-7">BENEFICIARIO</div></div>
          <div><div class="text-7">REFERIDO</div></div>
          <div><div class="text-7">PEDIDO REL.</div></div>
          <div><div class="text-8">NIVEL</div></div>
          <div><div class="text-7">MONTO</div></div>
          <div><div class="text-7">ESTADO</div></div>
          <div><div class="text-7">FECHA</div></div>
          <div><div class="text-9">ACCIONES</div></div>
        </div>
      </div>

      <div id="tablaCuerpo">
      <?php if ($countList > 0): ?>
          <?php while ($row = $resultList->fetch_assoc()): 
              $estado = strtolower($row["estado"]);
              $estadoClass = "overlay-6";
              $bgClass = "background-3";
              $estadoText = "Pagado";

              if ($estado === "pendiente") {
                  $estadoClass = "overlay-10";
                  $bgClass = "background-9";
                  $estadoText = "Pendiente";
              }
          ?>
          <div class="div-3 row-regalia" style="grid-template-columns: 1fr 2fr 2fr 1.5fr 1fr 1.2fr 1.2fr 1.5fr 1.5fr; border-bottom: 1px solid rgba(213,164,112,.12);">
            <div><div class="text-10">#REG-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?></div></div>
            <div><div class="text-11" style="font-weight: 700;"><?php echo htmlspecialchars($row["nombre_beneficiario"] ?? "Admin General"); ?></div></div>
            <div><div class="text-11"><?php echo htmlspecialchars($row["nombre_referido"] ?? "Compra Directa"); ?></div></div>
            <div>
              <div class="background-2">
                <div class="text-13">#PED-<?php echo str_pad($row["pedido_id"], 3, "0", STR_PAD_LEFT); ?></div>
              </div>
            </div>
            <div><div class="text-11" style="font-weight: 700; text-align: center;"><?php echo $row["nivel"]; ?>º</div></div>
            <div><div class="text-11" style="font-weight: 700; color: #176a21;">$<?php echo number_format($row["monto"], 2); ?></div></div>
            <div>
              <div class="<?php echo $estadoClass; ?>">
                <div class="<?php echo $bgClass; ?>"></div>
                <div class="text-15" style="color: inherit;"><?php echo $estadoText; ?></div>
              </div>
            </div>
            <div><div class="text-14"><?php echo date("d M Y", strtotime($row["fecha"])); ?></div></div>
            <div>
              <div class="text-10" style="display: flex; gap: 8px;">
                <?php if ($estado === "pendiente"): ?>
                    <button class="action-btn" title="Autorizar Pago" onclick="abrirConfirmarPago(<?php echo $row['id']; ?>, '<?php echo addslashes($row['nombre_beneficiario']); ?>', <?php echo $row['monto']; ?>)" style="background: linear-gradient(90deg, #176a21, #2ea33c); color:#fff; border:none; padding:4px 10px; border-radius:8px; display:flex; align-items:center; gap: 4px; font-size:11px; cursor:pointer; font-weight:700;">✓ Pagar</button>
                <?php else: ?>
                    <button class="action-btn" title="Comisión Pagada" disabled style="background:#cccccc; color:#666666; border:none; padding:4px 10px; border-radius:8px; display:flex; align-items:center; gap: 4px; font-size:11px; cursor:not-allowed; font-weight:700;">🔒 Cerrado</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 30px;">
              <div class="text-11" style="color: #996e3f;">No hay comisiones de referidos generadas en el sistema.</div>
          </div>
      <?php endif; ?>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p" id="paginacionTexto">MOSTRANDO <?php echo $countList; ?> COMISIONES</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal Confirmar Pago de Regalía -->
<div class="modal-overlay" id="modalConfirmarPago">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title" style="color: #176a21;">Autorizar Pago de Regalía</div>
      <button class="modal-close" onclick="cerrarModal('modalConfirmarPago')">×</button>
    </div>
    <form action="forms/regalias_acciones.php" method="POST">
      <input type="hidden" name="accion" value="pagar">
      <input type="hidden" name="id" id="pago_id">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Deseas autorizar y liberar el desembolso de <strong id="pago_monto" style="color: #176a21;"></strong> a favor del beneficiario <strong id="pago_beneficiario" style="color: #462800;"></strong>?<br>Esta acción registrará la transacción de inmediato en la auditoría inmutable de la bitácora.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalConfirmarPago')">Cancelar</button>
        <button type="submit" class="btn-submit" style="background: #176a21;">Autorizar Pago</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirConfirmarPago(id, beneficiario, monto) {
    document.getElementById('pago_id').value = id;
    document.getElementById('pago_beneficiario').textContent = beneficiario;
    document.getElementById('pago_monto').textContent = '$' + parseFloat(monto).toFixed(2);
    
    document.getElementById('modalConfirmarPago').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Búsqueda en tiempo real por texto
function filtrarTabla() {
    const query = document.getElementById('buscarRegalia').value.toLowerCase();
    const rows = document.querySelectorAll('#tablaCuerpo .row-regalia');
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

    document.getElementById('paginacionTexto').textContent = `MOSTRANDO ${visibleCount} DE ${rows.length} COMISIONES`;
}
</script>
</body>
</html>
