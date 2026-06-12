<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - DASHBOARD PRINCIPAL DEL PROVEEDOR
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

// 2. OBTENER PERFIL DEL PROVEEDOR
$stmtProv = $conn->prepare("SELECT * FROM proveedores WHERE usuario_id = ?");
$stmtProv->bind_param("i", $usuario_id);
$stmtProv->execute();
$resProv = $stmtProv->get_result();
if ($resProv->num_rows === 0) {
    die("Error: Su usuario no está vinculado a ningún proveedor avícola en el sistema.");
}
$proveedor = $resProv->fetch_assoc();
$proveedor_id = (int)$proveedor["id"];
$nombre_empresa = $proveedor["nombre_empresa"] ?? "Granja EcoAli";

$fechaHoy = date('Y-m-d');
$fechaLimite = date('Y-m-d', strtotime('+1 day')); // Próximo a caducar en 1 día o menos

// 3. OBTENER MÉTRICAS DEL PROVEEDOR

// A. Total huevos producidos (Historial total)
$stmtProd = $conn->prepare("SELECT SUM(cantidad) FROM produccion WHERE proveedor_id = ?");
$stmtProd->bind_param("i", $proveedor_id);
$stmtProd->execute();
$resProd = $stmtProd->get_result();
$huevosProducidos = (int)($resProd->fetch_row()[0] ?? 0);
$stmtProd->close();

// B. Total huevos disponibles (Stock actual en lotes activos/próximos)
$stmtStock = $conn->prepare("SELECT SUM(cantidad) FROM inventario_huevos WHERE proveedor_id = ? AND estado IN ('activo', 'proximo_caducar', 'disponible', 'bajo_stock')");
$stmtStock->bind_param("i", $proveedor_id);
$stmtStock->execute();
$resStock = $stmtStock->get_result();
$huevosDisponibles = (int)($resStock->fetch_row()[0] ?? 0);
$stmtStock->close();

// C. Producción semanal
$stmtSem = $conn->prepare("SELECT SUM(cantidad) FROM produccion WHERE proveedor_id = ? AND fecha_produccion >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$stmtSem->bind_param("i", $proveedor_id);
$stmtSem->execute();
$resSem = $stmtSem->get_result();
$prodSemanal = (int)($resSem->fetch_row()[0] ?? 0);
$stmtSem->close();

// D. Producción mensual
$stmtMen = $conn->prepare("SELECT SUM(cantidad) FROM produccion WHERE proveedor_id = ? AND fecha_produccion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$stmtMen->bind_param("i", $proveedor_id);
$stmtMen->execute();
$resMen = $stmtMen->get_result();
$prodMensual = (int)($resMen->fetch_row()[0] ?? 0);
$stmtMen->close();

// E. Lotes activos
$stmtLAct = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'activo' AND cantidad > 0");
$stmtLAct->bind_param("i", $proveedor_id);
$stmtLAct->execute();
$resLAct = $stmtLAct->get_result();
$lotesActivos = (int)($resLAct->fetch_row()[0] ?? 0);
$stmtLAct->close();

// F. Lotes próximos a caducar (≤ 1 día)
$stmtLProx = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'proximo_caducar' AND cantidad > 0");
$stmtLProx->bind_param("i", $proveedor_id);
$stmtLProx->execute();
$resLProx = $stmtLProx->get_result();
$lotesProximos = (int)($resLProx->fetch_row()[0] ?? 0);
$stmtLProx->close();

// G. Lotes caducados
$stmtLCad = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'caducado'");
$stmtLCad->bind_param("i", $proveedor_id);
$stmtLCad->execute();
$resLCad = $stmtLCad->get_result();
$lotesCaducados = (int)($resLCad->fetch_row()[0] ?? 0);
$stmtLCad->close();

// H. Entregas pendientes al CEDIS
$stmtEntPend = $conn->prepare("SELECT COUNT(*) FROM entregas_cedis WHERE proveedor_id = ? AND estado = 'pendiente'");
$stmtEntPend->bind_param("i", $proveedor_id);
$stmtEntPend->execute();
$resEntPend = $stmtEntPend->get_result();
$entregasPendientes = (int)($resEntPend->fetch_row()[0] ?? 0);
$stmtEntPend->close();

// I. Entregas en ruta
$stmtEntRuta = $conn->prepare("SELECT COUNT(*) FROM entregas_cedis WHERE proveedor_id = ? AND estado = 'en_ruta'");
$stmtEntRuta->bind_param("i", $proveedor_id);
$stmtEntRuta->execute();
$resEntRuta = $stmtEntRuta->get_result();
$entregasEnRuta = (int)($resEntRuta->fetch_row()[0] ?? 0);
$stmtEntRuta->close();

// J. Entregas recibidas
$stmtEntRec = $conn->prepare("SELECT COUNT(*) FROM entregas_cedis WHERE proveedor_id = ? AND estado = 'recibido'");
$stmtEntRec->bind_param("i", $proveedor_id);
$stmtEntRec->execute();
$resEntRec = $stmtEntRec->get_result();
$entregasRecibidas = (int)($resEntRec->fetch_row()[0] ?? 0);
$stmtEntRec->close();


// 4. TABLAR DE INFORMACIÓN RECIENTE

// A. Últimos 5 lotes registrados
$lotesList = [];
$stmtL = $conn->prepare("SELECT ih.*, p.nombre AS producto_nombre, g.nombre AS granja_nombre 
                         FROM inventario_huevos ih 
                         INNER JOIN productos p ON ih.producto_id = p.id 
                         LEFT JOIN granjas g ON ih.granja_id = g.id 
                         WHERE ih.proveedor_id = ? 
                         ORDER BY ih.id DESC LIMIT 5");
$stmtL->bind_param("i", $proveedor_id);
$stmtL->execute();
$resL = $stmtL->get_result();
while ($row = $resL->fetch_assoc()) {
    $lotesList[] = $row;
}
$stmtL->close();

// B. Últimas 5 entregas al CEDIS
$entregasList = [];
$stmtE = $conn->prepare("SELECT e.*, c.nombre AS cedis_nombre, u.usuario AS repartidor_nombre
                         FROM entregas_cedis e
                         INNER JOIN cedis c ON e.cedis_id = c.id
                         LEFT JOIN usuarios u ON e.repartidor_id = u.id
                         WHERE e.proveedor_id = ?
                         ORDER BY e.id DESC LIMIT 5");
$stmtE->bind_param("i", $proveedor_id);
$stmtE->execute();
$resE = $stmtE->get_result();
while ($row = $resE->fetch_assoc()) {
    $entregasList[] = $row;
}
$stmtE->close();

// C. Alertas de caducidad (Lotes próximos a caducar o caducados con stock > 0)
$alertasCaducidad = [];
$stmtA = $conn->prepare("SELECT ih.*, p.nombre AS producto_nombre 
                         FROM inventario_huevos ih
                         INNER JOIN productos p ON ih.producto_id = p.id
                         WHERE ih.proveedor_id = ? AND ih.cantidad > 0 AND ih.estado IN ('proximo_caducar', 'caducado')
                         ORDER BY ih.fecha_caducidad ASC LIMIT 5");
$stmtA->bind_param("i", $proveedor_id);
$stmtA->execute();
$resA = $stmtA->get_result();
while ($row = $resA->fetch_assoc()) {
    $alertasCaducidad[] = $row;
}
$stmtA->close();

// D. Resumen de producción por granja
$granjaResumen = [];
$stmtG = $conn->prepare("SELECT g.nombre AS granja_nombre, g.identificacion, COALESCE(SUM(p.cantidad), 0) AS total_producido, g.stock_cartones
                         FROM granjas g
                         LEFT JOIN produccion p ON g.id = p.granja_id
                         WHERE g.proveedor_id = ?
                         GROUP BY g.id ORDER BY total_producido DESC");
$stmtG->bind_param("i", $proveedor_id);
$stmtG->execute();
$resG = $stmtG->get_result();
while ($row = $resG->fetch_assoc()) {
    $granjaResumen[] = $row;
}
$stmtG->close();

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard Proveedor - ECOALI</title>
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
        <h1>Panel de Proveedor</h1>
        <p>Resumen de operaciones avícolas y entregas logísticas.</p>
      </div>
      <a href="produccion_proveedor.php" class="header-btn">
        <span>✚</span> Registrar Postura
      </a>
    </header>

    <!-- Métricas del Dashboard -->
    <div class="metrics-grid">
      <div class="metric-card success">
        <span class="label">Total Producido</span>
        <span class="value"><?php echo number_format($huevosProducidos); ?> ud</span>
      </div>
      <div class="metric-card success">
        <span class="label">Stock Disponible</span>
        <span class="value"><?php echo number_format($huevosDisponibles); ?> ud</span>
      </div>
      <div class="metric-card">
        <span class="label">Lotes Activos</span>
        <span class="value"><?php echo $lotesActivos; ?> lotes</span>
      </div>
      <div class="metric-card warn">
        <span class="label">Próximos a Caducar</span>
        <span class="value"><?php echo $lotesProximos; ?> lotes</span>
      </div>
      <div class="metric-card danger">
        <span class="label">Lotes Caducados</span>
        <span class="value"><?php echo $lotesCaducados; ?> lotes</span>
      </div>
      <div class="metric-card">
        <span class="label">Entregas Pendientes</span>
        <span class="value"><?php echo $entregasPendientes; ?> sol.</span>
      </div>
      <div class="metric-card warn">
        <span class="label">Entregas en Ruta</span>
        <span class="value"><?php echo $entregasEnRuta; ?> ruta</span>
      </div>
      <div class="metric-card success">
        <span class="label">Entregas Recibidas</span>
        <span class="value"><?php echo $entregasRecibidas; ?> ent.</span>
      </div>
    </div>

    <!-- Alertas Críticas (Insumos o Caducidad) -->
    <?php
    $bajoInsumo = false;
    foreach ($granjaResumen as $g) {
        if ($g["stock_cartones"] < 30) {
            $bajoInsumo = true;
            break;
        }
    }
    if ($bajoInsumo || !empty($alertasCaducidad)):
    ?>
      <div class="card" style="border-left: 5px solid var(--primary); background: rgba(255, 138, 0, 0.02); padding: 24px;">
        <h3 style="margin-bottom: 15px; color: var(--text-dark); display: flex; align-items: center; gap: 8px;">
          <span>⚠️</span> Alertas Críticas de Operación
        </h3>
        <div style="display: flex; flex-direction: column; gap: 12px;">
          <!-- Alertas de Insumos -->
          <?php foreach ($granjaResumen as $gr): ?>
            <?php if ($gr["stock_cartones"] < 30): ?>
              <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; background: white; border-radius: 12px; border: 1px solid rgba(176, 37, 0, 0.2);">
                <div>
                  <strong style="color: #b02500;">Insumos bajos: <?php echo htmlspecialchars($gr["granja_nombre"]); ?></strong>
                  <span style="font-size:12px; color: var(--text-medium); margin-left: 10px;">Código: <?php echo htmlspecialchars($gr["identificacion"]); ?></span>
                </div>
                <span class="badge-status caducado">Stock cartones: <?php echo $gr["stock_cartones"]; ?> ud</span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>

          <!-- Alertas de Caducidad -->
          <?php foreach ($alertasCaducidad as $ac): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; background: white; border-radius: 12px; border: 1px solid rgba(213, 164, 112, 0.15);">
              <div>
                <strong>Lote próximo a vencer o vencido: <?php echo htmlspecialchars($ac["codigo_lote"]); ?></strong>
                <span style="font-size:12px; color: var(--text-medium); margin-left: 10px;"><?php echo htmlspecialchars($ac["producto_nombre"]); ?></span>
              </div>
              <span class="badge-status <?php echo $ac["estado"] === 'caducado' ? 'caducado' : 'bajo_stock'; ?>">
                <?php echo $ac["estado"] === 'caducado' ? 'CADUCADO' : 'VENCE: ' . date("d/m/Y", strtotime($ac["fecha_caducidad"])); ?> (<?php echo $ac["cantidad"]; ?> ud)
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="dashboard-layout">
      <!-- Izquierda: Lotes y Entregas -->
      <div>
        <div class="card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3>Últimos Lotes Creados</h3>
            <a href="lotes_proveedor.php" style="color: var(--secondary); font-size: 13px; font-weight: 800; text-decoration: none;">Ver todos ➔</a>
          </div>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Lote</th>
                  <th>Granja</th>
                  <th>Cantidad</th>
                  <th>Caducidad</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($lotesList)): ?>
                  <?php foreach ($lotesList as $l): ?>
                    <tr>
                      <td><strong><?php echo htmlspecialchars($l["codigo_lote"]); ?></strong></td>
                      <td>🚜 <?php echo htmlspecialchars($l["granja_nombre"] ?? "N/A"); ?></td>
                      <td><?php echo number_format($l["cantidad"]); ?> ud</td>
                      <td><?php echo date("d/m/Y", strtotime($l["fecha_caducidad"])); ?></td>
                      <td><span class="badge-status <?php echo strtolower($l["estado"]); ?>"><?php echo htmlspecialchars($l["estado"]); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-medium); padding: 20px;">No has registrado lotes aún.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3>Últimas Solicitudes al CEDIS</h3>
            <a href="entregas_proveedor.php" style="color: var(--secondary); font-size: 13px; font-weight: 800; text-decoration: none;">Ver todas ➔</a>
          </div>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>CEDIS</th>
                  <th>Solicitado</th>
                  <th>Repartidor</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($entregasList)): ?>
                  <?php foreach ($entregasList as $e): ?>
                    <tr>
                      <td>#ENT-<?php echo str_pad($e["id"], 3, "0", STR_PAD_LEFT); ?></td>
                      <td>🏢 <?php echo htmlspecialchars($e["cedis_nombre"]); ?></td>
                      <td><?php echo date("d/m/Y", strtotime($e["fecha_solicitud"])); ?></td>
                      <td>🚚 <?php echo htmlspecialchars($e["repartidor_nombre"] ?? "Pendiente"); ?></td>
                      <td><span class="badge-status <?php echo strtolower($e["estado"]); ?>"><?php echo htmlspecialchars($e["estado"]); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-medium); padding: 20px;">No has solicitado entregas al CEDIS aún.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Derecha: Resumen por granjas y alertas -->
      <div>
        <div class="card">
          <h3>Resumen de Producción por Granja</h3>
          <p style="font-size: 13px; color: var(--text-medium); margin-top: -10px; margin-bottom: 20px;">Producción acumulada y estado de insumos de tus instalaciones.</p>
          <?php if (!empty($granjaResumen)): ?>
            <div style="display: flex; flex-direction: column; gap: 16px;">
              <?php foreach ($granjaResumen as $gr): ?>
                <div class="farm-item">
                  <div class="farm-icon">🚜</div>
                  <div style="flex: 1;">
                    <h4 style="color: var(--text-dark); font-size: 14px; font-weight: 700;"><?php echo htmlspecialchars($gr["granja_nombre"]); ?></h4>
                    <p style="font-size: 12px; color: var(--text-medium);">Código: <?php echo htmlspecialchars($gr["identificacion"]); ?></p>
                  </div>
                  <div style="text-align: right;">
                    <strong style="color: var(--secondary); font-size: 14px;"><?php echo number_format($gr["total_producido"]); ?> ud</strong>
                    <div style="font-size: 10px; color: <?php echo $gr["stock_cartones"] < 30 ? '#b02500' : 'var(--text-medium)'; ?>; font-weight: bold;">
                      Insumo: <?php echo $gr["stock_cartones"]; ?> cartones
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="text-align: center; padding: 20px; color: var(--text-medium);">No tienes granjas registradas.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>

  <!-- Mobile Navigation -->
  <nav class="mobile-nav">
    <a href="dashboard_proveedor.php" class="mobile-nav-btn active">
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