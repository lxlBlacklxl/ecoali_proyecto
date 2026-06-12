<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - TRAZABILIDAD Y CADENA DE CUSTODIA (VISTA DEL PROVEEDOR)
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

// 2. OBTENER PROVEEDOR_ID
$stmtProv = $conn->prepare("SELECT id, nombre_empresa FROM proveedores WHERE usuario_id = ?");
$stmtProv->bind_param("i", $usuario_id);
$stmtProv->execute();
$resProv = $stmtProv->get_result();
if ($resProv->num_rows === 0) {
    die("Error: Su usuario no está vinculado a ningún proveedor.");
}
$provRow = $resProv->fetch_assoc();
$proveedor_id = (int)$provRow["id"];
$nombre_empresa = $provRow["nombre_empresa"];
$stmtProv->close();

$lote_code = trim($_GET["lote_code"] ?? "");

// 3. OBTENER LISTA DE LOTES PARA EL DROPDOWN
$lotesDropdown = [];
$stmtLD = $conn->prepare("SELECT id, codigo_lote FROM inventario_huevos WHERE proveedor_id = ? ORDER BY id DESC");
$stmtLD->bind_param("i", $proveedor_id);
$stmtLD->execute();
$resLD = $stmtLD->get_result();
while ($row = $resLD->fetch_assoc()) {
    $lotesDropdown[] = $row;
}
$stmtLD->close();

// 4. OBTENER DETALLES DE TRAZABILIDAD DEL LOTE SELECCIONADO
$lote = null;
$entrega = null;

if (!empty($lote_code)) {
    // Info del Lote y Granja
    $stmtInfo = $conn->prepare("SELECT ih.*, pr.nombre AS producto_nombre, pr.tipo_huevo, pr.tamano, 
                                       g.nombre AS granja_nombre, g.identificacion AS granja_identificacion, g.ubicacion AS granja_ubicacion
                                FROM inventario_huevos ih
                                INNER JOIN productos pr ON ih.producto_id = pr.id
                                LEFT JOIN granjas g ON ih.granja_id = g.id
                                WHERE ih.codigo_lote = ? AND ih.proveedor_id = ?");
    $stmtInfo->bind_param("si", $lote_code, $proveedor_id);
    $stmtInfo->execute();
    $resInfo = $stmtInfo->get_result();
    if ($resInfo->num_rows > 0) {
        $lote = $resInfo->fetch_assoc();
        $lote_id = (int)$lote["id"];

        // Info de la Entrega y Repartidor
        $stmtEnt = $conn->prepare("SELECT dec.cantidad AS cantidad_entregada, ec.id AS entrega_id, ec.estado AS entrega_estado, ec.fecha_solicitud, 
                                           ec.fecha_recoleccion, ec.fecha_recepcion, ec.observaciones AS entrega_obs, ec.motivo_rechazo,
                                           c.nombre AS cedis_nombre, c.direccion AS cedis_direccion,
                                           up.nombre AS rep_nombre, up.apellido AS rep_apellido, up.telefono AS rep_tel
                                    FROM detalle_entrega_cedis dec
                                    INNER JOIN entregas_cedis ec ON dec.entrega_id = ec.id
                                    INNER JOIN cedis c ON ec.cedis_id = c.id
                                    LEFT JOIN usuarios u ON ec.repartidor_id = u.id
                                    LEFT JOIN usuario_perfil up ON u.id = up.usuario_id
                                    WHERE dec.lote_id = ?
                                    ORDER BY ec.id DESC LIMIT 1");
        $stmtEnt->bind_param("i", $lote_id);
        $stmtEnt->execute();
        $resEnt = $stmtEnt->get_result();
        if ($resEnt->num_rows > 0) {
            $entrega = $resEnt->fetch_assoc();
        }
        $stmtEnt->close();
    }
    $stmtInfo->close();
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trazabilidad del Producto - ECOALI</title>
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
        <h1>Trazabilidad Alimentaria</h1>
        <p>Consulte el rastreo físico completo y cadena de custodia desde su granja hasta el CEDIS de ECOALI.</p>
      </div>
    </header>

    <div class="card" style="max-width: 760px; margin: 0 auto;">
      <h3>🔀 Rastreador de Lote</h3>
      <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
        Seleccione un lote de la lista para verificar en qué etapa de la cadena de suministro se encuentra.
      </p>

      <form action="trazabilidad_proveedor.php" method="GET" style="margin-bottom: 30px;">
        <div class="form-group">
          <label>Código de Lote a Rastrear</label>
          <div style="display: flex; gap:12px;">
            <select name="lote_code" style="flex:1; height: 50px; font-size:15px;" required>
              <option value="">-- Seleccione un lote --</option>
              <?php foreach ($lotesDropdown as $ld): 
                $selected = ($ld["codigo_lote"] === $lote_code) ? 'selected' : '';
              ?>
                <option value="<?php echo htmlspecialchars($ld["codigo_lote"]); ?>" <?php echo $selected; ?>>🥚 Lote: <?php echo htmlspecialchars($ld["codigo_lote"]); ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-submit" style="width:120px; height:50px; margin-top:0;">Buscar 🔍</button>
          </div>
        </div>
      </form>

      <?php if (!empty($lote_code) && $lote): ?>
        <div style="border-top: 1px solid var(--glass-border); padding-top: 24px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <div>
              <h4 style="font-size: 18px; color: var(--text-dark); margin: 0;"><?php echo htmlspecialchars($lote["codigo_lote"]); ?></h4>
              <p style="font-size:13px; color:var(--text-medium); margin: 4px 0 0;">
                Huevo: <?php echo htmlspecialchars($lote["producto_nombre"] . ' [' . $lote["tipo_huevo"] . ' - ' . $lote["tamano"] . ']'); ?>
              </p>
            </div>
            <span class="badge-status <?php echo strtolower($lote["estado"]); ?>"><?php echo htmlspecialchars($lote["estado"]); ?></span>
          </div>

          <!-- Timeline Stepper -->
          <div class="stepper">
            
            <!-- Fase 1: Postura -->
            <div class="step active">
              <small>Fase 1: Postura y Cosecha</small>
              <h4>Origen: 🚜 <?php echo htmlspecialchars($lote["granja_nombre"] ?: "Granja No Asociada"); ?></h4>
              <p>
                Cosecha de <strong><?php echo number_format($lote["cantidad_inicial"]); ?> huevos</strong> recolectados en la fecha de postura: <strong><?php echo date("d/m/Y", strtotime($lote["fecha_postura"])); ?></strong>.<br>
                Ubicación de origen: <?php echo htmlspecialchars($lote["granja_ubicacion"] ?: "Sevilla, España"); ?> (Código Granja: <?php echo htmlspecialchars($lote["granja_identificacion"] ?: "N/A"); ?>).
              </p>
            </div>

            <!-- Fase 2: Empaque -->
            <div class="step active">
              <small>Fase 2: Clasificación y Calidad</small>
              <h4>Lote Empacado y Sellado</h4>
              <p>
                Los huevos fueron empaquetados consumiendo un total de <strong><?php echo (int)ceil($lote["cantidad_inicial"] / 30); ?> cartones</strong> de empaque oficial.<br>
                Fecha de caducidad calculada: <strong><?php echo date("d/m/Y", strtotime($lote["fecha_caducidad"])); ?></strong> (Consumo preferente: postura + 3 días).
              </p>
            </div>

            <!-- Fase 3: Tránsito Logístico -->
            <?php if ($entrega): 
              $ent_estado = strtolower($entrega["entrega_estado"]);
              $transit_active = in_array($ent_estado, ["repartidor_asignado", "recolectado", "en_ruta", "entregado_cedis", "recibido", "rechazado"]);
              $transit_class = $transit_active ? "active" : "";
              if ($ent_estado === 'cancelado') $transit_class = "danger";
            ?>
              <div class="step <?php echo $transit_class; ?>">
                <small>Fase 3: Tránsito Logístico</small>
                <h4>Envío CEDIS: #ENT-<?php echo str_pad($entrega["entrega_id"], 3, "0", STR_PAD_LEFT); ?> (<?php echo htmlspecialchars($entrega["entrega_estado"]); ?>)</h4>
                <p>
                  Solicitud de entrega creada el <?php echo date("d/m/Y", strtotime($entrega["fecha_solicitud"])); ?> para enviar <?php echo number_format($entrega["cantidad_entregada"]); ?> huevos al <strong>🏢 <?php echo htmlspecialchars($entrega["cedis_nombre"]); ?></strong>.<br>
                  <?php if ($entrega["rep_nombre"]): ?>
                    Repartidor Asignado: 🚚 <strong><?php echo htmlspecialchars($entrega["rep_nombre"] . ' ' . $entrega["rep_apellido"]); ?></strong> (Tlf: <?php echo htmlspecialchars($entrega["rep_tel"]); ?>).
                  <?php else: ?>
                    Estado: <span style="font-style: italic;">Esperando asignación de transportista por el administrador de ECOALI.</span>
                  <?php endif; ?>
                  <?php if (!empty($entrega["fecha_recoleccion"])): ?>
                    <br>Fecha de recolección programada: <?php echo date("d/m/Y", strtotime($entrega["fecha_recoleccion"])); ?>
                  <?php endif; ?>
                </p>
              </div>
            <?php else: ?>
              <div class="step">
                <small>Fase 3: Tránsito Logístico</small>
                <h4>Logística de Despacho</h4>
                <p style="font-style: italic; color: var(--text-medium);">
                  Lote guardado en almacén de granja. Aún no se ha solicitado la entrega de este lote al CEDIS de ECOALI.<br>
                  <a href="entregas_proveedor.php?lote_id=<?php echo $lote['id']; ?>" style="color: var(--secondary); font-weight: bold; text-decoration:none;">Solicitar envío al CEDIS ahora ➔</a>
                </p>
              </div>
            <?php endif; ?>

            <!-- Fase 4: CEDIS -->
            <?php if ($entrega && in_array(strtolower($entrega["entrega_estado"]), ["recibido", "rechazado", "entregado_cedis"])): 
              $cedis_state = strtolower($entrega["entrega_estado"]);
              $cedis_class = ($cedis_state === 'recibido') ? "active" : (($cedis_state === 'rechazado') ? "danger" : "warning");
            ?>
              <div class="step <?php echo $cedis_class; ?>">
                <small>Fase 4: Recepción y Auditoría CEDIS</small>
                <h4>ECOALI CEDIS - <?php echo htmlspecialchars($entrega["cedis_nombre"]); ?></h4>
                <p>
                  <?php if ($cedis_state === 'recibido'): ?>
                    <strong>✓ Recibido y Aprobado</strong>. Los huevos pasaron el control de calidad e higiene en las instalaciones de ECOALI el <?php echo date("d/m/Y H:i", strtotime($entrega["fecha_recepcion"])); ?>. Se agregaron al inventario comercial de la empresa.
                  <?php elseif ($cedis_state === 'rechazado'): ?>
                    <strong>✗ Rechazado</strong>. El lote no cumplió con las políticas de aceptación o frescura de ECOALI. <br>
                    <span style="color:#b02500; font-weight:bold;">Motivo del rechazo: <?php echo htmlspecialchars($entrega["motivo_rechazo"] ?: "No especificado"); ?></span>.
                  <?php else: ?>
                    <strong>El repartidor ha entregado físicamente el producto en el CEDIS</strong>. Esperando a que el personal administrativo audite el lote para agregarlo al stock.
                  <?php endif; ?>
                </p>
              </div>
            <?php else: ?>
              <div class="step">
                <small>Fase 4: Recepción y Auditoría CEDIS</small>
                <h4>Control de Entrada de Inventario</h4>
                <p style="font-style: italic; color: var(--text-medium);">
                  Lote pendiente de recepción física en el CEDIS de ECOALI y evaluación organoléptica.
                </p>
              </div>
            <?php endif; ?>

          </div>

        </div>
      <?php elseif (!empty($lote_code)): ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--text-medium);">
          <span style="font-size: 40px; display:block; margin-bottom:12px;">⚠️</span>
          El código de lote ingresado no pertenece a su inventario o no existe.
        </div>
      <?php else: ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--text-medium);">
          <span style="font-size: 40px; display:block; margin-bottom:12px;">🔍</span>
          Seleccione un lote del menú desplegable de arriba para ver su trazabilidad detallada en tiempo real.
        </div>
      <?php endif; ?>

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
    <a href="editar_perfil.php" class="mobile-nav-btn">
      <span>👤</span>
      <span>Perfil</span>
    </a>
  </nav>

</div>

</body>
</html>
