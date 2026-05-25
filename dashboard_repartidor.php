<?php
session_start();
require "forms/conexion.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

if ((int)$_SESSION["rol_id"] !== 4) {
    header("Location: login.php");
    exit;
}

$repartidor_id = $_SESSION["usuario_id"];
$nombre = $_SESSION["nombre"] ?? "Repartidor";
$apellido = $_SESSION["apellido"] ?? "";
$email = $_SESSION["email"] ?? "";

// --- CONSULTAS DINÁMICAS ---

// 1. Obtener perfil del repartidor
$stmtProfile = $conn->prepare("SELECT direccion, telefono FROM usuario_perfil WHERE usuario_id = ?");
$stmtProfile->bind_param("i", $repartidor_id);
$stmtProfile->execute();
$resProfile = $stmtProfile->get_result();
$profile = $resProfile->fetch_assoc();
$direccion = $profile["direccion"] ?? "";
$telefono = $profile["telefono"] ?? "";

// 2. Métricas de Reparto
// Entregas completadas por el repartidor
$stmtComp = $conn->prepare("SELECT COUNT(*) FROM pedidos WHERE repartidor_id = ? AND estado = 'entregado'");
$stmtComp->bind_param("i", $repartidor_id);
$stmtComp->execute();
$resComp = $stmtComp->get_result();
$entregasCompletadas = (int)($resComp->fetch_row()[0] ?? 0);

// Entregas en ruta asignadas al repartidor
$stmtRuta = $conn->prepare("SELECT COUNT(*) FROM pedidos WHERE repartidor_id = ? AND estado = 'en_ruta'");
$stmtRuta->bind_param("i", $repartidor_id);
$stmtRuta->execute();
$resRuta = $stmtRuta->get_result();
$entregasEnRuta = (int)($resRuta->fetch_row()[0] ?? 0);

// Pedidos preparados en el sistema esperando asignación de chófer
$stmtPrepSystem = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'preparado' AND (repartidor_id IS NULL OR repartidor_id = $repartidor_id)");
$entregasPreparadas = $stmtPrepSystem ? (int)($stmtPrepSystem->fetch_row()[0] ?? 0) : 0;

// 3. Obtener Próximas Paradas (Hojas de Ruta de Entregas Activas)
// Listar pedidos 'en_ruta' o 'preparado' que le pertenecen, o preparados que no tienen chofer asignado (auto-asignación!)
$rutasQuery = "SELECT p.id, p.total, p.estado, p.fecha_pedido, up.direccion AS pedido_direccion, 
                      up.nombre AS cliente_nombre, up.apellido AS cliente_apellido, up.telefono AS cliente_telefono
               FROM pedidos p
               INNER JOIN usuario_perfil up ON p.cliente_id = up.usuario_id
               WHERE (p.repartidor_id = ? AND p.estado IN ('preparado', 'en_ruta'))
                  OR (p.repartidor_id IS NULL AND p.estado = 'preparado')
               ORDER BY p.estado DESC, p.id ASC";
$stmtRutas = $conn->prepare($rutasQuery);
$stmtRutas->bind_param("i", $repartidor_id);
$stmtRutas->execute();
$resRutas = $stmtRutas->get_result();
$paradasActivas = [];
while ($row = $resRutas->fetch_assoc()) {
    $paradasActivas[] = $row;
}

// 4. Obtener Historial de Entregas Completadas por el Chofer
$histQuery = "SELECT p.id, p.total, p.fecha_pedido, up.direccion AS pedido_direccion, 
                     up.nombre AS cliente_nombre, up.apellido AS cliente_apellido, up.telefono AS cliente_telefono
              FROM pedidos p
              INNER JOIN usuario_perfil up ON p.cliente_id = up.usuario_id
              WHERE p.repartidor_id = ? AND p.estado = 'entregado'
              ORDER BY p.id DESC LIMIT 15";
$stmtHist = $conn->prepare($histQuery);
$stmtHist->bind_param("i", $repartidor_id);
$stmtHist->execute();
$resHist = $stmtHist->get_result();
$entregasHistorial = [];
while ($row = $resHist->fetch_assoc()) {
    $entregasHistorial[] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel de Repartidor - ECOALI</title>

  <link rel="stylesheet" href="assets/css/globals.css">
  <link rel="stylesheet" href="assets/css/repartidor.css?v=<?php echo time(); ?>">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <script src="assets/js/admin_menu.js" defer></script>
  <style>
    /* Estilos Premium Repartidores EcoAli */
    :root {
      --bg-organic: #fff5ed;
      --primary: #ff8a00;
      --primary-hover: #e07b00;
      --secondary: #176a21;
      --text-dark: #462800;
      --text-medium: #7a5427;
      --glass-bg: rgba(255, 255, 255, 0.85);
      --glass-border: rgba(213, 164, 112, 0.22);
      --shadow-premium: 0 20px 45px rgba(70, 40, 0, 0.08);
      --transition-fast: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
      background-color: #1a1c1a;
      background-image: radial-gradient(circle at 10% 20%, rgba(255,138,0,0.06) 0%, transparent 40%),
                        radial-gradient(circle at 90% 80%, rgba(157,241,151,0.08) 0%, transparent 45%);
      color: var(--text-dark);
      font-family: 'Manrope', sans-serif;
      min-height: 100vh;
      margin: 0;
    }

    .delivery-container {
      display: flex;
      min-height: 100vh;
      position: relative;
    }

    /* Sidebar */
    .sidebar {
      width: 280px;
      background: var(--glass-bg);
      backdrop-filter: blur(16px);
      border-right: 1px solid var(--glass-border);
      display: flex;
      flex-direction: column;
      padding: 30px 24px;
      position: fixed;
      height: 100vh;
      z-index: 10;
      box-shadow: 10px 0 35px rgba(0,0,0,0.02);
      transition: var(--transition-fast);
    }

    .sidebar .brand {
      font-size: 26px;
      font-weight: 800;
      color: var(--secondary);
      letter-spacing: -1px;
      margin-bottom: 35px;
    }

    .sidebar .profile-card {
      background: rgba(255, 138, 0, 0.08);
      border-radius: 20px;
      padding: 16px;
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 30px;
      border: 1px solid rgba(255, 138, 0, 0.12);
    }

    .sidebar .profile-card .avatar {
      width: 44px;
      height: 44px;
      background: var(--primary);
      color: white;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-weight: 800;
      font-size: 18px;
      box-shadow: 0 8px 16px rgba(255, 138, 0, 0.25);
    }

    .sidebar .profile-card .info h4 {
      margin: 0;
      font-size: 14px;
      font-weight: 700;
      color: var(--text-dark);
    }

    .sidebar .profile-card .info p {
      margin: 2px 0 0;
      font-size: 11px;
      font-weight: 600;
      color: var(--text-medium);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .sidebar-menu {
      display: flex;
      flex-direction: column;
      gap: 8px;
      flex-grow: 1;
    }

    .sidebar-menu button {
      background: none;
      border: none;
      text-align: left;
      padding: 14px 20px;
      border-radius: 14px;
      font-size: 14px;
      font-weight: 700;
      color: var(--text-medium);
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 14px;
      transition: var(--transition-fast);
    }

    .sidebar-menu button:hover {
      background: rgba(213, 164, 112, 0.1);
      color: var(--text-dark);
      transform: translateX(4px);
    }

    .sidebar-menu button.active {
      background: var(--primary);
      color: white;
      box-shadow: 0 10px 20px rgba(255, 138, 0, 0.25);
    }

    .sidebar-footer {
      margin-top: auto;
    }

    .logout-btn {
      width: 100%;
      padding: 14px;
      border-radius: 14px;
      border: 1px solid rgba(176, 37, 0, 0.15);
      background: rgba(176, 37, 0, 0.04);
      color: #b02500;
      font-size: 14px;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      transition: var(--transition-fast);
    }

    .logout-btn:hover {
      background: #b02500;
      color: white;
      box-shadow: 0 10px 20px rgba(176, 37, 0, 0.2);
    }

    /* Main Content */
    .main-content {
      margin-left: 280px;
      flex-grow: 1;
      min-height: 100vh;
      background: var(--bg-organic);
      padding: 40px;
      transition: var(--transition-fast);
      position: relative;
      z-index: 1;
      padding-bottom: 60px;
    }

    .app-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 35px;
    }

    .app-header h1 {
      margin: 0;
      font-size: 32px;
      font-weight: 800;
      color: var(--text-dark);
      letter-spacing: -0.5px;
    }

    .app-header p {
      margin: 4px 0 0;
      font-size: 14px;
      color: var(--text-medium);
      font-weight: 500;
    }

    /* Pestañas de Contenido */
    .tab-pane {
      display: none;
      animation: fadeIn 0.4s ease-out forwards;
    }

    .tab-pane.active {
      display: block;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Metrics */
    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 24px;
      margin-bottom: 35px;
    }

    .metric-card {
      background: white;
      border-radius: 24px;
      padding: 24px;
      border: 1px solid var(--glass-border);
      box-shadow: var(--shadow-premium);
      display: flex;
      flex-direction: column;
    }

    .metric-card .label {
      font-size: 12px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .metric-card .value {
      font-size: 32px;
      font-weight: 800;
      color: var(--text-dark);
      margin-top: 10px;
    }

    .metric-card.active-delivery { border-color: rgba(255, 138, 0, 0.35); background: rgba(255, 138, 0, 0.02); }
    .metric-card.completadas { border-color: rgba(23, 106, 33, 0.35); background: rgba(23, 106, 33, 0.02); }
    .metric-card.completadas .value { color: var(--secondary); }

    /* Routing Stops Layout */
    .stops-container {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .stop-card {
      background: white;
      border-radius: 24px;
      border: 1px solid var(--glass-border);
      box-shadow: var(--shadow-premium);
      padding: 24px;
      display: grid;
      grid-template-columns: auto 1fr 1fr auto;
      align-items: center;
      gap: 24px;
      transition: var(--transition-fast);
    }

    .stop-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 25px 45px rgba(70,40,0,0.06);
    }

    .stop-number {
      width: 48px;
      height: 48px;
      background: #ffe3ca;
      border-radius: 14px;
      display: grid;
      place-items: center;
      font-size: 18px;
      font-weight: 800;
      color: var(--primary);
    }

    .stop-card.en_ruta .stop-number {
      background: var(--secondary-light) ?? #effeed;
      color: var(--secondary);
    }

    .stop-details h4 {
      margin: 0;
      font-size: 16px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .stop-details p {
      margin: 6px 0 0;
      font-size: 13px;
      color: var(--text-medium);
      line-height: 1.5;
    }

    .stop-details strong {
      color: var(--text-dark);
    }

    .badge-status {
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      width: fit-content;
    }

    .badge-status.pendiente { background: rgba(255, 138, 0, 0.12); color: var(--primary); }
    .badge-status.preparado { background: rgba(23, 134, 186, 0.12); color: #1786ba; }
    .badge-status.en_ruta { background: rgba(23, 106, 33, 0.12); color: var(--secondary); }

    .action-btn {
      background: var(--primary);
      color: white;
      border: none;
      padding: 12px 24px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      cursor: pointer;
      box-shadow: 0 8px 16px rgba(255, 138, 0, 0.2);
      transition: var(--transition-fast);
    }

    .action-btn:hover {
      background: var(--primary-hover);
      transform: scale(1.02);
    }

    .action-btn.deliver {
      background: var(--secondary);
      box-shadow: 0 8px 16px rgba(23, 106, 33, 0.2);
    }

    .action-btn.deliver:hover {
      background: #2ea33c;
    }

    /* History Table */
    .history-card {
      background: white;
      border-radius: 28px;
      padding: 30px;
      border: 1px solid var(--glass-border);
      box-shadow: var(--shadow-premium);
    }

    .history-card h3 {
      margin: 0 0 20px;
      font-size: 20px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .table-responsive {
      overflow-x: auto;
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    .data-table th {
      padding: 12px 16px;
      font-size: 11px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      border-bottom: 2px solid var(--glass-border);
    }

    .data-table td {
      padding: 14px 16px;
      font-size: 13px;
      font-weight: 600;
      color: var(--text-dark);
      border-bottom: 1px solid rgba(213, 164, 112, 0.08);
    }

    /* Profile form */
    .profile-box {
      max-width: 680px;
      margin: 0 auto;
    }

    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .form-group.full-width {
      grid-column: 1 / -1;
    }

    .form-group label {
      font-size: 12px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      margin-left: 8px;
    }

    .form-group input {
      height: 52px;
      border-radius: 14px;
      border: 1px solid rgba(213, 164, 112, 0.35);
      background: white;
      padding: 0 16px;
      font-size: 14px;
      font-weight: 600;
      color: var(--text-dark);
      outline: none;
      transition: var(--transition-fast);
    }

    .form-group input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(255, 138, 0, 0.1);
    }

    .btn-submit {
      grid-column: 1 / -1;
      height: 54px;
      border-radius: 14px;
      border: none;
      background: linear-gradient(135deg, var(--secondary), #2ea33c);
      color: white;
      font-size: 15px;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 12px 24px rgba(23, 106, 33, 0.22);
      transition: var(--transition-fast);
      margin-top: 10px;
    }

    /* Modales */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(0,0,0,0.5);
      backdrop-filter: blur(4px);
      z-index: 200;
      opacity: 0;
      pointer-events: none;
      display: grid;
      place-items: center;
      transition: opacity 0.3s ease;
    }

    .modal-overlay.active {
      opacity: 1;
      pointer-events: all;
    }

    .modal-container {
      background: white;
      border-radius: 28px;
      width: 90%;
      max-width: 420px;
      padding: 40px 30px;
      box-shadow: 0 25px 55px rgba(0,0,0,0.15);
      border: 1px solid var(--glass-border);
      text-align: center;
    }

    .mobile-nav {
      display: none;
      position: fixed;
      bottom: 0;
      left: 0;
      width: 100%;
      height: 64px;
      background: white;
      box-shadow: 0 -5px 20px rgba(0,0,0,0.05);
      border-top: 1px solid var(--glass-border);
      z-index: 99;
      grid-template-columns: repeat(4, 1fr);
      align-items: center;
    }

    .mobile-nav-btn {
      background: none;
      border: none;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 4px;
      color: var(--text-medium);
      font-size: 18px;
      font-weight: 700;
      cursor: pointer;
    }

    .mobile-nav-btn span {
      font-size: 9px;
    }

    .mobile-nav-btn.active {
      color: var(--primary);
    }

    @media (max-width: 991px) {
      .admin-hamburger, .admin-menu-overlay {
        display: flex;
      }

      .sidebar {
        position: fixed !important;
        top: 0 !important;
        left: -280px !important; /* Fully hidden on left */
        width: 260px !important;
        height: 100vh !important;
        margin: 0 !important;
        border-radius: 0 !important;
        z-index: 9999 !important;
        display: flex !important;
        flex-direction: column !important;
        padding: 30px 20px !important;
        box-shadow: 10px 0 35px rgba(70,40,0,.15) !important;
        background: var(--bg-organic) !important;
        transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
      }

      .sidebar.active {
        left: 0 !important;
      }

      .sidebar .brand {
        font-size: 20px !important;
        margin-bottom: 30px !important;
        padding-left: 10px;
      }

      .sidebar .profile-card {
        display: flex !important;
        margin-bottom: 25px !important;
      }

      .sidebar-menu button span {
        display: inline !important;
      }

      .sidebar-menu button {
        padding: 14px 18px !important;
        justify-content: flex-start !important;
      }

      .main-content {
        margin-left: 0 !important;
        padding: 85px 20px 24px !important; /* Extra top padding to clear floating hamburger */
        width: 100% !important;
      }
    }

    @media (max-width: 767px) {
      .mobile-nav {
        display: grid;
      }

      .main-content {
        padding: 85px 16px 84px !important; /* Clear bottom nav and top hamburger */
      }

      .stop-card {
        grid-template-columns: auto 1fr;
        gap: 16px;
      }

      .stop-card .badge-status,
      .stop-card .action-btn {
        grid-column: 1 / -1;
        width: 100%;
        text-align: center;
      }

      .form-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>

<div class="delivery-container">

  <!-- Sidebar (Desktop) -->
  <aside class="sidebar">
    <div class="brand">☰ ECOALI</div>

    <div class="profile-card">
      <div class="avatar"><?php echo strtoupper(substr($nombre, 0, 1)); ?></div>
      <div class="info">
        <h4><?php echo htmlspecialchars($nombre . " " . $apellido); ?></h4>
        <p>Chofer Repartidor</p>
      </div>
    </div>

    <nav class="sidebar-menu">
      <button class="menu-btn active" onclick="switchTab('dashboard', this)">
        <span>▦</span> <span>Dashboard</span>
      </button>
      <button class="menu-btn" onclick="switchTab('hoja-ruta', this)">
        <span>🔀</span> <span>Hoja de Ruta</span>
      </button>
      <button class="menu-btn" onclick="switchTab('historial', this)">
        <span>▤</span> <span>Historial Envíos</span>
      </button>
      <button class="menu-btn" onclick="switchTab('perfil', this)">
        <span>♙</span> <span>Mi Perfil</span>
      </button>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" style="text-decoration:none;">
        <button class="logout-btn">
          <span>⤶</span> Salir
        </button>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">

    <header class="app-header">
      <div>
        <h1 id="page-title">Panel Logístico</h1>
        <p id="page-subtitle">Monitoreo de envíos de huevos orgánicos.</p>
      </div>
    </header>

    <!-- PESTAÑA: DASHBOARD -->
    <section id="tab-dashboard" class="tab-pane active">
      <div class="metrics-grid">
        <div class="metric-card active-delivery">
          <span class="label">Entregas en Tránsito</span>
          <span class="value"><?php echo $entregasEnRuta; ?> pedidos</span>
        </div>
        <div class="metric-card completadas">
          <span class="label">Entregas Completadas</span>
          <span class="value"><?php echo $entregasCompletadas; ?> envíos</span>
        </div>
        <div class="metric-card">
          <span class="label">Pedidos por Recoger</span>
          <span class="value"><?php echo $entregasPreparadas; ?> órdenes</span>
        </div>
      </div>

      <div style="display:grid; grid-template-columns: 1fr; gap: 30px;">
        <div class="history-card">
          <h3>Ruta de Hoy</h3>
          <div class="timeline" style="padding-left: 20px; position:relative;">
            <div class="timeline-item" style="margin-bottom: 20px;">
              <small>07:00 AM</small>
              <h4>Salida de Almacén Central</h4>
              <p>Inspección de lotes orgánicos y carga en camión refrigerado.</p>
            </div>
            <div class="timeline-item">
              <small>Operación Actual</small>
              <h4>Despacho de Pedidos en Cola</h4>
              <p>Esperando recolección de órdenes listas para iniciar envíos.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- PESTAÑA: HOJA DE RUTA -->
    <section id="tab-hoja-ruta" class="tab-pane">
      <div class="stops-container">
        <?php if (!empty($paradasActivas)): ?>
          <?php 
          $idx = 1;
          foreach ($paradasActivas as $parada): 
            $est = strtolower($parada["estado"]);
            $isAssignedToMe = true;
          ?>
            <article class="stop-card <?php echo $est; ?>" id="stop-card-<?php echo $parada['id']; ?>">
              <div class="stop-number">#<?php echo $idx++; ?></div>
              
              <div class="stop-details">
                <h4>Pedido #PED-<?php echo str_pad($parada["id"], 3, "0", STR_PAD_LEFT); ?></h4>
                <p>
                  👤 Cliente: <strong><?php echo htmlspecialchars($parada["cliente_nombre"] . " " . $parada["cliente_apellido"]); ?></strong><br>
                  📍 Dirección: <strong><?php echo htmlspecialchars($parada["pedido_direccion"]); ?></strong><br>
                  📞 Teléfono: <strong><?php echo htmlspecialchars($parada["cliente_telefono"]); ?></strong>
                </p>
              </div>

              <div>
                <span class="badge-status <?php echo $est; ?>" id="stop-status-badge-<?php echo $parada['id']; ?>"><?php echo $parada["estado"]; ?></span>
              </div>

              <div>
                <?php if ($est === "preparado"): ?>
                  <button class="action-btn" id="stop-btn-<?php echo $parada['id']; ?>" onclick="updateDeliveryStatus(<?php echo $parada['id']; ?>, 'en_ruta')">Recoger y Salir ➜</button>
                <?php elseif ($est === "en_ruta"): ?>
                  <button class="action-btn deliver" id="stop-btn-<?php echo $parada['id']; ?>" onclick="updateDeliveryStatus(<?php echo $parada['id']; ?>, 'entregado')">Completar Entrega ✓</button>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="text-align:center; padding:50px; background:white; border-radius:24px; border:1px solid var(--glass-border); color:var(--text-medium); font-weight:700;">
            No tienes paradas o envíos pendientes asignados en tu hoja de ruta actualmente.
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- PESTAÑA: HISTORIAL -->
    <section id="tab-historial" class="tab-pane">
      <div class="history-card">
        <h3>Historial de Entregas Realizadas</h3>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
          Registro de los despachos de huevos frescos completados de forma segura.
        </p>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Código Pedido</th>
                <th>Cliente</th>
                <th>Dirección Entrega</th>
                <th>Teléfono</th>
                <th>Valor Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($entregasHistorial)): ?>
                <?php foreach ($entregasHistorial as $hist): ?>
                  <tr>
                    <td><strong style="color:var(--text-dark);">#PED-<?php echo str_pad($hist["id"], 3, "0", STR_PAD_LEFT); ?></strong></td>
                    <td><?php echo htmlspecialchars($hist["cliente_nombre"] . " " . $hist["cliente_apellido"]); ?></td>
                    <td><?php echo htmlspecialchars($hist["pedido_direccion"]); ?></td>
                    <td><?php echo htmlspecialchars($hist["cliente_telefono"]); ?></td>
                    <td><strong style="color:var(--secondary);">$<?php echo number_format($hist["total"], 2); ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" style="text-align:center; color:var(--text-medium); padding:30px;">
                    Aún no registras entregas completadas en el historial.
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- PESTAÑA: PERFIL -->
    <section id="tab-perfil" class="tab-pane">
      <div class="history-card profile-box">
        <h3>Mi Perfil de Repartidor</h3>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
          Mantén tus datos personales y de contacto de conductor actualizados.
        </p>

        <form id="profile-form" onsubmit="saveProfile(event)">
          <div class="form-grid">
            <div class="form-group">
              <label>Nombre</label>
              <input type="text" id="prof-nombre" value="<?php echo htmlspecialchars($nombre); ?>" required>
            </div>

            <div class="form-group">
              <label>Apellido</label>
              <input type="text" id="prof-apellido" value="<?php echo htmlspecialchars($apellido); ?>" required>
            </div>

            <div class="form-group">
              <label>Correo Electrónico</label>
              <input type="email" id="prof-email" value="<?php echo htmlspecialchars($email); ?>" required>
            </div>

            <div class="form-group">
              <label>Teléfono</label>
              <input type="text" id="prof-telefono" value="<?php echo htmlspecialchars($telefono); ?>">
            </div>

            <div class="form-group full-width">
              <label>Centro de Operaciones Principal</label>
              <input type="text" id="prof-direccion" value="<?php echo htmlspecialchars($direccion); ?>" placeholder="Ej: Centro de Operaciones Sevilla Centro">
            </div>

            <button type="submit" class="btn-submit">Guardar Datos de Conductor</button>
          </div>
        </form>
      </div>
    </section>

  </main>

  <!-- Mobile Nav -->
  <nav class="mobile-nav">
    <button class="mobile-nav-btn active" onclick="switchTab('dashboard', this)">
      <span>▦</span>
      <span>Dashboard</span>
    </button>
    <button class="mobile-nav-btn" onclick="switchTab('hoja-ruta', this)">
      <span>🔀</span>
      <span>Ruta</span>
    </button>
    <button class="mobile-nav-btn" onclick="switchTab('historial', this)">
      <span>▤</span>
      <span>Historial</span>
    </button>
    <button class="mobile-nav-btn" onclick="switchTab('perfil', this)">
      <span>♙</span>
      <span>Perfil</span>
    </button>
  </nav>

</div>

<!-- Modal: Alertas -->
<div class="modal-overlay" id="alert-modal">
  <div class="modal-container">
    <div style="font-size: 60px; color: var(--secondary); margin-bottom: 20px;" id="alert-icon">✓</div>
    <h3 style="margin: 0 0 10px; font-size: 22px; font-weight: 800;" id="alert-title">¡Operación Exitosa!</h3>
    <p style="margin: 0 0 25px; font-size: 14px; color: var(--text-medium); line-height: 1.6;" id="alert-message">
      El estado de la entrega se actualizó correctamente en la cadena logística.
    </p>
    <button onclick="closeAlertModal()" style="background: var(--text-dark); color: white; border: none; padding: 12px 30px; border-radius: 12px; font-weight: 800; cursor: pointer;">
      Entendido
    </button>
  </div>
</div>

<script>
  function switchTab(tabName, element) {
      document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
      document.getElementById('tab-' + tabName).classList.add('active');

      document.querySelectorAll('.sidebar-menu button, .mobile-nav-btn').forEach(btn => btn.classList.remove('active'));
      document.querySelectorAll('.sidebar-menu button, .mobile-nav-btn').forEach(btn => {
          if (btn.outerHTML.includes(tabName)) {
              btn.classList.add('active');
          }
      });

      const titles = {
          'dashboard': { title: 'Panel Logístico', subtitle: 'Monitoreo de envíos de huevos orgánicos.' },
          'hoja-ruta': { title: 'Hoja de Ruta', subtitle: 'Listado de entregas y paradas programadas para hoy.' },
          'historial': { title: 'Historial de Entregas', subtitle: 'Registro de despachos completados con éxito.' },
          'perfil': { title: 'Mi Perfil de Conductor', subtitle: 'Datos personales y de contacto del repartidor.' }
      };

      if (titles[tabName]) {
          document.getElementById('page-title').textContent = titles[tabName].title;
          document.getElementById('page-subtitle').textContent = titles[tabName].subtitle;
      }
  }

  // Actualizar estado de entrega (AJAX)
  function updateDeliveryStatus(id, newStatus) {
      const payload = {
          pedido_id: id,
          nuevo_estado: newStatus
      };

      fetch('forms/actualizar_estado_entrega.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              showAlertModal(
                  '¡Ruta Actualizada!',
                  data.message,
                  '✓',
                  'var(--secondary)',
                  true
              );
          } else {
              showAlertModal('Error', data.message, '✗', '#b02500');
          }
      })
      .catch(err => {
          console.error(err);
          showAlertModal('Error de Servidor', 'Ocurrió un error inesperado al actualizar.', '✗', '#b02500');
      });
  }

  // Guardar Perfil de Repartidor (AJAX)
  function saveProfile(e) {
      e.preventDefault();

      const nom = document.getElementById('prof-nombre').value;
      const ape = document.getElementById('prof-apellido').value;
      const ema = document.getElementById('prof-email').value;
      const tel = document.getElementById('prof-telefono').value;
      const dir = document.getElementById('prof-direccion').value;

      const payload = {
          nombre: nom,
          apellido: ape,
          email: ema,
          telefono: tel,
          direccion: dir
      };

      fetch('forms/actualizar_perfil_repartidor.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              showAlertModal('Perfil Guardado', data.message, '✓', 'var(--secondary)', true);
          } else {
              showAlertModal('Error', data.message, '✗', '#b02500');
          }
      })
      .catch(err => {
          console.error(err);
          showAlertModal('Error de Servidor', 'Ocurrió un error inesperado al guardar.', '✗', '#b02500');
      });
  }

  let reloadOnClose = false;

  function showAlertModal(title, message, icon, color, reload = false) {
      document.getElementById('alert-title').textContent = title;
      document.getElementById('alert-message').textContent = message;
      
      const iconEl = document.getElementById('alert-icon');
      iconEl.textContent = icon;
      iconEl.style.color = color;

      reloadOnClose = reload;

      document.getElementById('alert-modal').classList.add('active');
  }

  function closeAlertModal() {
      document.getElementById('alert-modal').classList.remove('active');
      if (reloadOnClose) {
          window.location.reload();
      }
  }
</script>

</body>
</html>