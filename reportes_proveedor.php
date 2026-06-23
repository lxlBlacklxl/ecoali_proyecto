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
$stmtH3 = $conn->prepare("SELECT COALESCE(SUM(det.cantidad), 0) 
                          FROM detalle_entrega_cedis det 
                          INNER JOIN entregas_cedis ec ON det.entrega_id = ec.id 
                          WHERE ec.proveedor_id = ? AND ec.estado IN ('repartidor_asignado', 'recolectado', 'en_ruta', 'entregado_cedis')");
$stmtH3->bind_param("i", $proveedor_id);
$stmtH3->execute();
$huevosEnviados = (int)$stmtH3->get_result()->fetch_row()[0];
$stmtH3->close();

// D. Huevos recibidos por ECOALI (Estado recibido)
$stmtH4 = $conn->prepare("SELECT COALESCE(SUM(det.cantidad), 0) 
                          FROM detalle_entrega_cedis det 
                          INNER JOIN entregas_cedis ec ON det.entrega_id = ec.id 
                          WHERE ec.proveedor_id = ? AND ec.estado = 'recibido'");
$stmtH4->bind_param("i", $proveedor_id);
$stmtH4->execute();
$huevosRecibidos = (int)$stmtH4->get_result()->fetch_row()[0];
$stmtH4->close();

// E. Huevos rechazados
$stmtH5 = $conn->prepare("SELECT COALESCE(SUM(det.cantidad), 0) 
                          FROM detalle_entrega_cedis det 
                          INNER JOIN entregas_cedis ec ON det.entrega_id = ec.id 
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
        <span>🚜</span> <span>Mi Resumen</span>
      </a>
      <a href="produccion_proveedor.php" class="menu-link <?php echo ($current_page === 'produccion_proveedor.php') ? 'active' : ''; ?>">
        <span>🥚</span> <span>Registrar Postura (Recolección)</span>
      </a>
      <a href="lotes_proveedor.php" class="menu-link <?php echo ($current_page === 'lotes_proveedor.php') ? 'active' : ''; ?>">
        <span>📦</span> <span>Mis Lotes de Huevos</span>
      </a>
      <a href="inventario_proveedor.php" class="menu-link <?php echo ($current_page === 'inventario_proveedor.php') ? 'active' : ''; ?>">
        <span>🧺</span> <span>Mi Almacén (Stock)</span>
      </a>
      <a href="entregas_proveedor.php" class="menu-link <?php echo ($current_page === 'entregas_proveedor.php') ? 'active' : ''; ?>">
        <span>🚚</span> <span>Enviar al CEDIS (Entregas)</span>
      </a>
      <a href="trazabilidad_proveedor.php" class="menu-link <?php echo ($current_page === 'trazabilidad_proveedor.php') ? 'active' : ''; ?>">
        <span>🔍</span> <span>Origen y Calidad</span>
      </a>
      <a href="reportes_proveedor.php" class="menu-link <?php echo ($current_page === 'reportes_proveedor.php') ? 'active' : ''; ?>">
        <span>📊</span> <span>Mis Reportes</span>
      </a>
      <a href="editar_perfil.php" class="menu-link <?php echo ($current_page === 'editar_perfil.php') ? 'active' : ''; ?>">
        <span>⚙️</span> <span>Mi Perfil y Granjas</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" style="text-decoration:none;">
        <button class="logout-btn">
          <span>🚪</span> Salir (Cerrar Sesión)
        </button>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">
    <header class="app-header">
      <div>
        <h1>Mis Reportes y Estadísticas</h1>
        <p>Analiza de forma sencilla la recolección de tus huevos y el rendimiento de tus granjas.</p>
      </div>
    </header>

    <!-- Tarjetas de Resumen Rápido -->
    <div class="metrics-grid">
      <div class="metric-card success">
        <span class="label">Huevos Cosechados 🥚</span>
        <span class="value"><?php echo number_format($huevosRegistrados); ?> ud</span>
      </div>
      <div class="metric-card success">
        <span class="label">Listos en Almacén 🧺</span>
        <span class="value"><?php echo number_format($huevosDisponibles); ?> ud</span>
      </div>
      <div class="metric-card">
        <span class="label">Enviados al CEDIS 🚚</span>
        <span class="value"><?php echo number_format($huevosEnviados); ?> ud</span>
      </div>
      <div class="metric-card success">
        <span class="label">Recibidos EcoAli 🎉</span>
        <span class="value"><?php echo number_format($huevosRecibidos); ?> ud</span>
      </div>
      <div class="metric-card danger">
        <span class="label">Huevos Rechazados ❌</span>
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
      <span>🚜</span>
      <span>Mi Resumen</span>
    </a>
    <a href="produccion_proveedor.php" class="mobile-nav-btn">
      <span>🥚</span>
      <span>Postura</span>
    </a>
    <a href="inventario_proveedor.php" class="mobile-nav-btn">
      <span>🧺</span>
      <span>Almacén</span>
    </a>
    <a href="entregas_proveedor.php" class="mobile-nav-btn">
      <span>🚚</span>
      <span>Entregas</span>
    </a>
    <a href="editar_perfil.php" class="mobile-nav-btn active">
      <span>⚙️</span>
      <span>Perfil</span>
    </a>
  </nav>

</div>

<!-- ASISTENTE VIRTUAL ACCESIBLE: DOÑA ALI PARA GRANJEROS -->
<div id="dona-ali-container" style="position:fixed; bottom:80px; right:24px; z-index:99999; display:flex; flex-direction:column; align-items:flex-end; gap:12px; font-family:inherit;">
  
  <!-- Burbuja de Diálogo de Doña Ali -->
  <div id="dona-ali-bubble" style="display:none; width:300px; background:white; border-radius:20px; border:1px solid rgba(213, 164, 112, 0.25); box-shadow:0 10px 30px rgba(0,0,0,0.15); padding:20px; flex-direction:column; gap:12px; transition:all 0.3s ease;">
    <!-- Encabezado de la Burbuja -->
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(213,164,112,0.15); padding-bottom:8px;">
      <span style="font-weight:800; color:var(--text-dark); font-size:14px; display:inline-flex; align-items:center; gap:6px;">👵 Doña Ali Asistente</span>
      <button onclick="toggleDonaAliBubble()" style="background:none; border:none; font-size:18px; cursor:pointer; color:var(--text-medium); line-height:1;">×</button>
    </div>
    
    <!-- Texto de Respuesta -->
    <p id="dona-ali-text" style="margin:0; font-size:13px; color:var(--text-medium); line-height:1.6; font-weight:700;">¡Hola, granjero! Soy Doña Ali. Estoy aquí para ayudarte a manejar tus huevos y registros. Haz clic en una pregunta o cuéntame qué necesitas.</p>
    
    <!-- Opciones / Preguntas frecuentes -->
    <div id="dona-ali-options" style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
      <button onclick="askDonaAli('postura')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🥚 ¿Cómo registro recolección?</button>
      <button onclick="askDonaAli('lotes')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">📦 ¿Qué es un lote?</button>
      <button onclick="askDonaAli('envio')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213, 164, 112, 0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🚚 ¿Cómo envío a la ciudad?</button>
      <button onclick="askDonaAli('insumos')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213, 164, 112, 0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🚜 ¿No me deja guardar postura?</button>
    </div>

    <!-- Controles de Voz -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; border-top:1px solid rgba(213,164,112,0.1); padding-top:8px;">
      <button id="dona-ali-speak-btn" onclick="readDonaResponse()" style="background:none; border:none; cursor:pointer; font-size:12px; font-weight:800; color:var(--text-medium);" title="Escuchar respuesta">🔊 Escuchar</button>
      <button id="dona-ali-listen-btn" onclick="listenToUser()" style="background:none; border:none; cursor:pointer; font-size:12px; font-weight:800; color:#b02500;" title="Hablarle a Doña Ali">🎙️ Hablarle</button>
    </div>
  </div>

  <!-- Botón Circular Flotante (Trigger) -->
  <button onclick="toggleDonaAliBubble()" style="width:60px; height:60px; border-radius:50%; background:linear-gradient(135deg, var(--primary), #e07b00); border:none; color:white; font-size:28px; cursor:pointer; box-shadow:0 8px 25px rgba(255,138,0,0.35); display:grid; place-items:center; transition:transform 0.2s ease-in-out;" onmouseover="this.style.transform='scale(1.08)';" onmouseout="this.style.transform='scale(1)';">
    👵
  </button>
</div>

<script>
  let donaSpeechUtterance = null;
  let voiceRecognition = null;

  function toggleDonaAliBubble() {
      const bubble = document.getElementById('dona-ali-bubble');
      if (bubble.style.display === 'none' || bubble.style.display === '') {
          bubble.style.display = 'flex';
          speakText("Hola, granjero. Soy Doña Ali. ¿En qué te ayudo hoy con tus tareas del campo?");
      } else {
          bubble.style.display = 'none';
          if (window.speechSynthesis) {
              window.speechSynthesis.cancel();
          }
      }
  }

  function askDonaAli(topic) {
      const textEl = document.getElementById('dona-ali-text');
      let response = '';

      if (topic === 'postura') {
          response = 'Para registrar tus huevos recolectados del día, haz clic en el botón naranja que dice "Registrar Postura" en la esquina de arriba. Indica de qué granja provienen, el tipo de huevo, la cantidad y la fecha. El sistema calculará cuántos cartones utilizarás de forma automática.';
      } else if (topic === 'lotes') {
          response = 'Cada vez que registras una postura, el sistema crea un Lote de huevos de forma automática. Este lote tiene una etiqueta especial y una fecha de caducidad calculada de 3 días desde su postura para asegurar la frescura de los huevos.';
      } else if (topic === 'envio') {
          response = 'Ve a la pestaña "Enviar al CEDIS (Entregas)". Presiona "Solicitar Recolección", elige el centro de distribución de EcoAli al que quieres enviar y la fecha. Luego, marca las casillas de los lotes de tu almacén que vas a mandar e ingresa la cantidad de cada uno.';
      } else if (topic === 'insumos') {
          response = 'Para asegurar la calidad, cada postura debe registrarse empacada en cartones. Si tu granja tiene 0 o pocos cartones disponibles, no te dejará guardar. Puedes reabastecer cartones yendo a "Mi Perfil y Granjas" en la sección de tus granjas.';
      } else {
          response = 'Hola, hijo. Soy Doña Ali. Estoy aquí para ayudarte a manejar tus registros de postura y tus envíos.';
      }

      textEl.textContent = response;
      speakText(response);
  }

  let selectedFemaleVoice = null;
  function loadVoices() {
      if (!window.speechSynthesis) return;
      const voices = window.speechSynthesis.getVoices();
      if (!voices || voices.length === 0) return;
      const spanishVoices = voices.filter(v => v.lang.includes('es') || v.lang.includes('ES'));
      let found = spanishVoices.find(v => {
          const nameLower = v.name.toLowerCase();
          return nameLower.includes('sabina') || 
                 nameLower.includes('dalia') || 
                 nameLower.includes('yolanda') || 
                 nameLower.includes('helena') || 
                 nameLower.includes('laura') || 
                 nameLower.includes('hilda') || 
                 nameLower.includes('female') ||
                 nameLower.includes('zira') ||
                 nameLower.includes('dona') ||
                 nameLower.includes('mujer') ||
                 nameLower.includes('google');
      });
      if (!found) {
          found = spanishVoices.find(v => {
              const nameLower = v.name.toLowerCase();
              return !nameLower.includes('david') && 
                     !nameLower.includes('raul') && 
                     !nameLower.includes('carlos') && 
                     !nameLower.includes('jorge') && 
                     !nameLower.includes('male') && 
                     !nameLower.includes('hombre');
          });
      }
      if (!found && spanishVoices.length > 0) {
          found = spanishVoices[0];
      }
      selectedFemaleVoice = found;
  }
  if (window.speechSynthesis) {
      window.speechSynthesis.onvoiceschanged = loadVoices;
      loadVoices();
  }

  function speakText(text) {
      if (!window.speechSynthesis) return;
      window.speechSynthesis.cancel();
      
      donaSpeechUtterance = new SpeechSynthesisUtterance(text);
      donaSpeechUtterance.lang = 'es-MX';
      
      if (!selectedFemaleVoice) {
          loadVoices();
      }
      if (selectedFemaleVoice) {
          donaSpeechUtterance.voice = selectedFemaleVoice;
      }
      window.speechSynthesis.speak(donaSpeechUtterance);
  }

  function readDonaResponse() {
      const text = document.getElementById('dona-ali-text').textContent;
      speakText(text);
  }

  function listenToUser() {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SpeechRecognition) {
          alert("Tu navegador no soporta el reconocimiento de voz. Te recomiendo usar Google Chrome.");
          return;
      }

      const listenBtn = document.getElementById('dona-ali-listen-btn');
      listenBtn.textContent = "🎙️ Escuchando...";
      listenBtn.style.color = "var(--secondary)";

      voiceRecognition = new SpeechRecognition();
      voiceRecognition.lang = 'es-MX';
      voiceRecognition.interimResults = false;
      voiceRecognition.maxAlternatives = 1;

      voiceRecognition.start();

      voiceRecognition.onresult = function(event) {
          const phrase = event.results[0][0].transcript.toLowerCase();
          console.log("Usuario dijo: " + phrase);
          
          if (phrase.includes('postura') || phrase.includes('recolect') || phrase.includes('huevo')) {
              askDonaAli('postura');
          } else if (phrase.includes('lote') || phrase.includes('paquete')) {
              askDonaAli('lotes');
          } else if (phrase.includes('envio') || phrase.includes('enviar') || phrase.includes('cedis')) {
              askDonaAli('envio');
          } else if (phrase.includes('insumo') || phrase.includes('carton') || phrase.includes('no me deja')) {
              askDonaAli('insumos');
          } else {
              const textEl = document.getElementById('dona-ali-text');
              textEl.textContent = 'Te escuché: "' + phrase + '". ¿Me puedes preguntar de otra forma, por favor?';
              speakText(textEl.textContent);
          }
      };

      voiceRecognition.onspeechend = function() {
          listenBtn.textContent = "🎙️ Hablarle";
          listenBtn.style.color = "#b02500";
          voiceRecognition.stop();
      };

      voiceRecognition.onerror = function(event) {
          listenBtn.textContent = "🎙️ Hablarle";
          listenBtn.style.color = "#b02500";
          console.log("Error de reconocimiento: " + event.error);
      };
  }
</script>

</body>
</html>
