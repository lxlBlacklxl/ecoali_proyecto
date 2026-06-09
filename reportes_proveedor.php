<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - REPORTES Y ESTADÍSTICAS DEL PROVEEDOR
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


// 3. CONSULTAR MÉTRICAS DE REPORTES
// A. Huevos registrados (Total histórico producido)
$stmtH1 = $conn->prepare("SELECT COALESCE(SUM(cantidad), 0) FROM produccion WHERE proveedor_id = ?");
$stmtH1->bind_param("i", $proveedor_id);
$stmtH1->execute();
$huevosRegistrados = (int)$stmtH1->get_result()->fetch_row()[0];
$stmtH1->close();

// B. Huevos disponibles (Stock actual listo)
$stmtH2 = $conn->prepare("SELECT COALESCE(SUM(cantidad), 0) FROM inventario_huevos WHERE proveedor_id = ? AND estado IN ('activo', 'proximo_caducar', 'disponible', 'bajo_stock')");
$stmtH2->bind_param("i", $proveedor_id);
$stmtH2->execute();
$huevosDisponibles = (int)$stmtH2->get_result()->fetch_row()[0];
$stmtH2->close();

// C. Huevos enviados al CEDIS (En proceso de entrega o entregados en CEDIS)
$stmtH3 = $conn->prepare("SELECT COALESCE(SUM(dec.cantidad), 0) 
                          FROM detalle_entrega_cedis dec 
                          INNER JOIN entregas_cedis ec ON dec.entrega_id = ec.id 
                          WHERE ec.proveedor_id = ? AND ec.estado IN ('repartidor_asignado', 'recolectado', 'en_ruta', 'entregado_cedis')");
$stmtH3->bind_param("i", $proveedor_id);
$stmtH3->execute();
$huevosEnviados = (int)$stmtH3->get_result()->fetch_row()[0];
$stmtH3->close();

// D. Huevos recibidos por ECOALI (Estado recibido)
$stmtH4 = $conn->prepare("SELECT COALESCE(SUM(dec.cantidad), 0) 
                          FROM detalle_entrega_cedis dec 
                          INNER JOIN entregas_cedis ec ON dec.entrega_id = ec.id 
                          WHERE ec.proveedor_id = ? AND ec.estado = 'recibido'");
$stmtH4->bind_param("i", $proveedor_id);
$stmtH4->execute();
$huevosRecibidos = (int)$stmtH4->get_result()->fetch_row()[0];
$stmtH4->close();

// E. Huevos rechazados
$stmtH5 = $conn->prepare("SELECT COALESCE(SUM(dec.cantidad), 0) 
                          FROM detalle_entrega_cedis dec 
                          INNER JOIN entregas_cedis ec ON dec.entrega_id = ec.id 
                          WHERE ec.proveedor_id = ? AND ec.estado = 'rechazado'");
$stmtH5->bind_param("i", $proveedor_id);
$stmtH5->execute();
$huevosRechazados = (int)$stmtH5->get_result()->fetch_row()[0];
$stmtH5->close();

// F. Lotes activos
$stmtL1 = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'activo' AND cantidad > 0");
$stmtL1->bind_param("i", $proveedor_id);
$stmtL1->execute();
$lotesActivos = (int)$stmtL1->get_result()->fetch_row()[0];
$stmtL1->close();

// G. Lotes caducados
$stmtL2 = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'caducado'");
$stmtL2->bind_param("i", $proveedor_id);
$stmtL2->execute();
$lotesCaducados = (int)$stmtL2->get_result()->fetch_row()[0];
$stmtL2->close();

// H. Entregas pendientes al CEDIS
$stmtE1 = $conn->prepare("SELECT COUNT(*) FROM entregas_cedis WHERE proveedor_id = ? AND estado = 'pendiente'");
$stmtE1->bind_param("i", $proveedor_id);
$stmtE1->execute();
$entregasPendientes = (int)$stmtE1->get_result()->fetch_row()[0];
$stmtE1->close();

// I. Entregas en ruta
$stmtE2 = $conn->prepare("SELECT COUNT(*) FROM entregas_cedis WHERE proveedor_id = ? AND estado = 'en_ruta'");
$stmtE2->bind_param("i", $proveedor_id);
$stmtE2->execute();
$entregasEnRuta = (int)$stmtE2->get_result()->fetch_row()[0];
$stmtE2->close();

// J. Entregas recibidas
$stmtE3 = $conn->prepare("SELECT COUNT(*) FROM entregas_cedis WHERE proveedor_id = ? AND estado = 'recibido'");
$stmtE3->bind_param("i", $proveedor_id);
$stmtE3->execute();
$entregasRecibidas = (int)$stmtE3->get_result()->fetch_row()[0];
$stmtE3->close();

// K. Producto más producido
$stmtP = $conn->prepare("SELECT p.nombre, p.tamano, SUM(pr.cantidad) as total 
                         FROM produccion pr 
                         INNER JOIN productos p ON pr.producto_id = p.id 
                         WHERE pr.proveedor_id = ? 
                         GROUP BY pr.producto_id 
                         ORDER BY total DESC LIMIT 1");
$stmtP->bind_param("i", $proveedor_id);
$stmtP->execute();
$resP = $stmtP->get_result();
$prodMasProducido = "N/A";
if ($resP->num_rows > 0) {
    $rowP = $resP->fetch_assoc();
    $prodMasProducido = $rowP["nombre"] . " (" . $rowP["tamano"] . ") - " . number_format($rowP["total"]) . " ud";
}
$stmtP->close();

// L. Granja con mayor producción
$stmtG = $conn->prepare("SELECT g.nombre, SUM(pr.cantidad) as total 
                         FROM produccion pr 
                         INNER JOIN granjas g ON pr.granja_id = g.id 
                         WHERE pr.proveedor_id = ? 
                         GROUP BY pr.granja_id 
                         ORDER BY total DESC LIMIT 1");
$stmtG->bind_param("i", $proveedor_id);
$stmtG->execute();
$resG = $stmtG->get_result();
$granjaMayorProd = "N/A";
if ($resG->num_rows > 0) {
    $rowG = $resG->fetch_assoc();
    $granjaMayorProd = $rowG["nombre"] . " - " . number_format($rowG["total"]) . " ud";
}
$stmtG->close();


// 4. GENERAR DATOS DE GRÁFICOS (ÚLTIMOS 7 DÍAS Y ÚLTIMOS 6 MESES)
$weeklyData = [];
$maxWeekly = 0;
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $label = date('d M', strtotime($date));
    
    $stmtW = $conn->prepare("SELECT COALESCE(SUM(cantidad), 0) FROM produccion WHERE proveedor_id = ? AND fecha_produccion = ?");
    $stmtW->bind_param("is", $proveedor_id, $date);
    $stmtW->execute();
    $val = (int)($stmtW->get_result()->fetch_row()[0]);
    $stmtW->close();
    
    if ($val > $maxWeekly) $maxWeekly = $val;
    $weeklyData[] = ["label" => $label, "value" => $val];
}

$monthlyData = [];
$maxMonthly = 0;
for ($i = 5; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    $label = date('M Y', strtotime($monthStart));
    
    $stmtM = $conn->prepare("SELECT COALESCE(SUM(cantidad), 0) FROM produccion WHERE proveedor_id = ? AND fecha_produccion >= ? AND fecha_produccion <= ?");
    $stmtM->bind_param("iss", $proveedor_id, $monthStart, $monthEnd);
    $stmtM->execute();
    $val = (int)($stmtM->get_result()->fetch_row()[0]);
    $stmtM->close();
    
    if ($val > $maxMonthly) $maxMonthly = $val;
    $monthlyData[] = ["label" => $label, "value" => $val];
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reportes Estadísticos - ECOALI</title>
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
        <h1>Reportes y Estadísticas</h1>
        <p>Analice el rendimiento de producción de huevo y trazabilidad logística al CEDIS.</p>
      </div>
    </header>

    <!-- Tarjetas de Resumen Rápido -->
    <div class="metrics-grid">
      <div class="metric-card success">
        <span class="label">Huevos Registrados</span>
        <span class="value"><?php echo number_format($huevosRegistrados); ?> ud</span>
      </div>
      <div class="metric-card success">
        <span class="label">Huevos Disponibles</span>
        <span class="value"><?php echo number_format($huevosDisponibles); ?> ud</span>
      </div>
      <div class="metric-card">
        <span class="label">Enviados al CEDIS</span>
        <span class="value"><?php echo number_format($huevosEnviados); ?> ud</span>
      </div>
      <div class="metric-card success">
        <span class="label">Recibidos por ECOALI</span>
        <span class="value"><?php echo number_format($huevosRecibidos); ?> ud</span>
      </div>
      <div class="metric-card danger">
        <span class="label">Huevos Rechazados</span>
        <span class="value"><?php echo number_format($huevosRechados ?? $huevosRechazados); ?> ud</span>
      </div>
    </div>

    <!-- Rendimiento por Producto y Granja -->
    <div class="dashboard-layout" style="margin-bottom:30px;">
      <div class="card" style="margin-bottom:0; display:flex; flex-direction:column; justify-content:center; padding: 30px;">
        <h4 style="font-size:11px; text-transform:uppercase; color:var(--text-medium); font-weight:800; margin-bottom:8px;">Producto Más Producido</h4>
        <div style="font-size:20px; font-weight:800; color:var(--secondary); display:flex; align-items:center; gap:8px;">
          <span>🥚</span> <span><?php echo htmlspecialchars($prodMasProducido); ?></span>
        </div>
      </div>
      
      <div class="card" style="margin-bottom:0; display:flex; flex-direction:column; justify-content:center; padding: 30px;">
        <h4 style="font-size:11px; text-transform:uppercase; color:var(--text-medium); font-weight:800; margin-bottom:8px;">Granja con Mayor Rendimiento</h4>
        <div style="font-size:20px; font-weight:800; color:var(--primary); display:flex; align-items:center; gap:8px;">
          <span>🚜</span> <span><?php echo htmlspecialchars($granjaMayorProd); ?></span>
        </div>
      </div>
    </div>

    <!-- Gráficos de Producción -->
    <div class="dashboard-layout">
      
      <!-- Gráfico Semanal -->
      <div class="card">
        <h3>Producción Diaria (Últimos 7 días)</h3>
        <p style="font-size:12px; color:var(--text-medium); margin-top:-10px; margin-bottom:20px;">Seguimiento diario de recolección de postura.</p>
        
        <div class="chart-container">
          <div class="chart-bar-layout">
            <?php foreach ($weeklyData as $wd): 
              $height = $maxWeekly > 0 ? (int)(($wd["value"] / $maxWeekly) * 100) : 0;
            ?>
              <div class="chart-bar-wrapper">
                <div class="chart-bar" style="height: <?php echo $height; ?>%;">
                  <?php if ($wd["value"] > 0): ?>
                    <span class="chart-bar-value"><?php echo $wd["value"]; ?></span>
                  <?php endif; ?>
                </div>
                <span class="chart-label"><?php echo $wd["label"]; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Gráfico Mensual -->
      <div class="card">
        <h3>Producción Mensual (Últimos 6 meses)</h3>
        <p style="font-size:12px; color:var(--text-medium); margin-top:-10px; margin-bottom:20px;">Volumen mensual acumulado de huevos.</p>
        
        <div class="chart-container">
          <div class="chart-bar-layout">
            <?php foreach ($monthlyData as $md): 
              $height = $maxMonthly > 0 ? (int)(($md["value"] / $maxMonthly) * 100) : 0;
            ?>
              <div class="chart-bar-wrapper">
                <div class="chart-bar orange" style="height: <?php echo $height; ?>%;">
                  <?php if ($md["value"] > 0): ?>
                    <span class="chart-bar-value"><?php echo number_format($md["value"]); ?></span>
                  <?php endif; ?>
                </div>
                <span class="chart-label"><?php echo $md["label"]; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>

    <!-- Tabla Detalle Estados -->
    <div class="card" style="margin-top: 30px; padding: 24px;">
      <h3>Resumen Detallado de Operaciones</h3>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Concepto Operativo</th>
              <th>Lotes Relacionados</th>
              <th>Unidades Totales</th>
              <th>Descripción</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>Lotes Activos en Granja</strong></td>
              <td><?php echo $lotesActivos; ?> lotes</td>
              <td><strong style="color:var(--secondary);"><?php echo number_format($huevosDisponibles); ?> ud</strong></td>
              <td>Huevo disponible en sus granjas esperando logística.</td>
            </tr>
            <tr>
              <td><strong>Lotes Caducados / Vencidos</strong></td>
              <td><?php echo $lotesCaducados; ?> lotes</td>
              <td><strong style="color:#b02500;">- ud</strong></td>
              <td>Lotes bloqueados automáticamente que no pueden comercializarse.</td>
            </tr>
            <tr>
              <td><strong>Entregas Pendientes CEDIS</strong></td>
              <td><?php echo $entregasPendientes; ?> sol.</td>
              <td><strong>-</strong></td>
              <td>Entregas programadas en espera de asignación de chofer.</td>
            </tr>
            <tr>
              <td><strong>Pedidos en Tránsito / Ruta</strong></td>
              <td><?php echo $entregasEnRuta; ?> ruta</td>
              <td><strong>-</strong></td>
              <td>Lotes recolectados por el transportista en camino al CEDIS.</td>
            </tr>
            <tr>
              <td><strong>Lotes Aceptados y Recibidos</strong></td>
              <td><?php echo $entregasRecibidas; ?> recib.</td>
              <td><strong style="color:var(--secondary);"><?php echo number_format($huevosRecibidos); ?> ud</strong></td>
              <td>Lotes validados por ECOALI y sumados al inventario de venta.</td>
            </tr>
            <tr>
              <td><strong>Lotes Rechazados en CEDIS</strong></td>
              <td>-</td>
              <td><strong style="color:#b02500;"><?php echo number_format($huevosRechados ?? $huevosRechazados); ?> ud</strong></td>
              <td>Lotes que no cumplieron las especificaciones sanitarias.</td>
            </tr>
          </tbody>
        </table>
      </div>
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
    <a href="editar_perfil.php" class="mobile-nav-btn active">
      <span>👤</span>
      <span>Perfil</span>
    </a>
  </nav>

</div>

</body>
</html>
