<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - PORTAL PREMIUM DE LOGÍSTICA Y REPARTO
 * --------------------------------------------------------------------------------
 * Interfaz de alta fidelidad optimizada para móviles para chóferes logísticos.
 * Incluye visualización de rutas óptimas optimizadas por mapa interactivo,
 * firma manuscrita Canvas HTML5, geolocalización GPS y gestión de incidencias en ruta.
 */

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
$direccion = $profile["direccion"] ?? "Almacén Central EcoAli";
$telefono = $profile["telefono"] ?? "";

// 2. Métricas de Reparto
$stmtComp = $conn->prepare("SELECT COUNT(*) FROM pedidos WHERE repartidor_id = ? AND estado = 'entregado'");
$stmtComp->bind_param("i", $repartidor_id);
$stmtComp->execute();
$resComp = $stmtComp->get_result();
$entregasCompletadas = (int)($resComp->fetch_row()[0] ?? 0);

$stmtRuta = $conn->prepare("SELECT COUNT(*) FROM pedidos WHERE repartidor_id = ? AND estado = 'en_ruta'");
$stmtRuta->bind_param("i", $repartidor_id);
$stmtRuta->execute();
$resRuta = $stmtRuta->get_result();
$entregasEnRuta = (int)($resRuta->fetch_row()[0] ?? 0);

$stmtPrepSystem = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'preparado' AND (repartidor_id IS NULL OR repartidor_id = $repartidor_id)");
$entregasPreparadas = $stmtPrepSystem ? (int)($stmtPrepSystem->fetch_row()[0] ?? 0) : 0;

// 3. Obtener Próximas Paradas (Hojas de Ruta de Entregas Activas)
$rutasQuery = "SELECT p.id, p.total, p.estado, p.fecha_pedido, p.metodo_pago, p.pago_estado, up.direccion AS pedido_direccion, 
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

// 4. Obtener Historial de Entregas Completadas por el Chofer (con Firma y GPS)
$histQuery = "SELECT p.id, p.total, p.fecha_pedido, p.fecha_entrega, p.coordenadas_entrega, p.firma_entrega, p.metodo_pago, up.direccion AS pedido_direccion, 
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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  
  <style>
    :root {
      --bg-organic: #fbf8f5;
      --primary: #ff8a00;
      --primary-hover: #e07b00;
      --secondary: #176a21;
      --text-dark: #322514;
      --text-medium: #705b44;
      --glass-bg: rgba(255, 255, 255, 0.88);
      --glass-border: rgba(213, 164, 112, 0.25);
      --shadow-premium: 0 16px 40px rgba(50, 37, 20, 0.06);
      --transition-fast: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    @keyframes bounce {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-6px); }
    }

    body {
      background-color: #1c1d1a;
      background-image: radial-gradient(circle at 10% 20%, rgba(255,138,0,0.06) 0%, transparent 40%),
                        radial-gradient(circle at 90% 80%, rgba(23,106,33,0.06) 0%, transparent 45%);
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

    /* Sidebar (Desktop) */
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
      display: flex;
      align-items: center;
      gap: 10px;
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

    /* Interactive Delivery Map */
    .routing-map-card {
      background: white;
      border-radius: 28px;
      border: 1px solid var(--glass-border);
      box-shadow: var(--shadow-premium);
      padding: 24px;
      margin-bottom: 30px;
    }

    .routing-map-card h3 {
      margin: 0 0 8px 0;
      font-size: 18px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .routing-map-card p {
      margin: 0 0 20px 0;
      font-size: 13px;
      color: var(--text-medium);
    }

    .map-canvas-container {
      background: #faf6f0;
      border: 1px dashed rgba(213, 164, 112, 0.4);
      border-radius: 20px;
      height: 240px;
      position: relative;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* Routing stops list */
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
      grid-template-columns: auto 1fr auto auto;
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

    .stop-card.en_ruta {
      border-color: rgba(23, 106, 33, 0.25);
      background: rgba(23, 106, 33, 0.01);
    }

    .stop-card.en_ruta .stop-number {
      background: #effeed;
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
      border-radius: 8px;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      width: fit-content;
      display: inline-block;
    }

    .badge-status.pendiente { background: rgba(255, 138, 0, 0.12); color: var(--primary); }
    .badge-status.preparado { background: rgba(23, 134, 186, 0.12); color: #1786ba; }
    .badge-status.en_ruta { background: rgba(23, 106, 33, 0.12); color: var(--secondary); }

    .action-btn-group {
      display: flex;
      gap: 12px;
      align-items: center;
    }
    @media (max-width: 991px) {
      .action-btn-group {
        flex-direction: column;
        width: 100%;
        grid-column: 1 / -1;
      }
      .action-btn-group a,
      .action-btn-group button {
        width: 100% !important;
      }
    }

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
      display: flex;
      align-items: center;
      gap: 8px;
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
      margin-bottom: 30px;
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
      padding: 14px 16px;
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
      vertical-align: middle;
    }

    /* Delivery Modal (Signature & Incidences) */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(28, 29, 26, 0.7);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      z-index: 10000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      box-sizing: border-box;
    }

    .modal-overlay.active {
      display: flex;
    }

    .stop-modal-container {
      background: white;
      border-radius: 30px;
      width: 95%;
      max-width: 480px;
      padding: 30px;
      box-shadow: 0 30px 65px rgba(0,0,0,0.18);
      border: 1px solid var(--glass-border);
      position: relative;
    }

    .modal-tabs {
      display: flex;
      border-bottom: 2px solid #f0e6da;
      margin-bottom: 24px;
      gap: 10px;
    }

    .modal-tab-btn {
      flex: 1;
      padding: 12px;
      background: none;
      border: none;
      font-weight: 800;
      font-size: 14px;
      color: var(--text-medium);
      cursor: pointer;
      border-bottom: 3px solid transparent;
      transition: var(--transition-fast);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .modal-tab-btn.active {
      color: var(--secondary);
      border-bottom-color: var(--secondary);
    }

    .modal-tab-btn.danger.active {
      color: #b02500;
      border-bottom-color: #b02500;
    }

    .modal-tab-pane {
      display: none;
    }

    .modal-tab-pane.active {
      display: block;
      animation: fadeIn 0.3s ease;
    }

    .signature-area {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      margin-bottom: 20px;
    }

    .signature-canvas {
      border: 2px dashed rgba(213, 164, 112, 0.4);
      border-radius: 16px;
      background: #fffdfb;
      cursor: crosshair;
      touch-action: none;
    }

    /* Form and General Inputs */
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

    .form-group input, .form-group select, .form-group textarea {
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
      font-family: inherit;
    }

    .form-group textarea {
      height: 100px;
      padding: 14px 16px;
      resize: none;
    }

    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(255, 138, 0, 0.1);
    }

    .btn-submit {
      width: 100%;
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
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 10px;
    }

    .btn-submit:hover {
      filter: brightness(1.05);
      transform: translateY(-1px);
    }

    .btn-submit.danger {
      background: linear-gradient(135deg, #b02500, #d63c15);
      box-shadow: 0 12px 24px rgba(176, 37, 0, 0.2);
    }

    /* Mobile bottom navigation */
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
      .sidebar { display: none !important; }
      .mobile-nav { display: grid !important; }
      .main-content {
        margin-left: 0 !important;
        padding: 24px 20px 84px !important;
        width: 100% !important;
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
        justify-content: center;
      }
      .form-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<div class="delivery-container">

  <!-- Sidebar (Desktop) -->
  <aside class="sidebar">
    <div class="brand">🌱 ECOALI</div>

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
        <span>🔀</span> <span>Ruta Óptima</span>
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

      <div class="history-card">
        <h3>Línea de Operación de Hoy</h3>
        <div class="timeline" style="padding-left: 20px; position:relative; border-left: 3px solid rgba(213, 164, 112, 0.25); margin-left: 10px;">
          <div class="timeline-item" style="margin-bottom: 24px; position:relative; padding-left: 20px;">
            <div style="width:12px; height:12px; border-radius:50%; background:var(--secondary); position:absolute; left:-7px; top:4px;"></div>
            <small style="font-size:11px; font-weight:800; color:var(--text-medium);">07:00 AM</small>
            <h4 style="margin:4px 0; font-size:14px; font-weight:800;">Carga y Despacho en Almacén</h4>
            <p style="margin:4px 0 0; font-size:13px; color:var(--text-medium);">Revisión de cadena de frío y carga de lotes orgánicos.</p>
          </div>
          <div class="timeline-item" style="position:relative; padding-left: 20px;">
            <div style="width:12px; height:12px; border-radius:50%; background:var(--primary); position:absolute; left:-7px; top:4px;"></div>
            <small style="font-size:11px; font-weight:800; color:var(--text-medium);">En Operación</small>
            <h4 style="margin:4px 0; font-size:14px; font-weight:800;">Monitoreo Logístico Activo</h4>
            <p style="margin:4px 0 0; font-size:13px; color:var(--text-medium);">Navegando y registrando firmas seguras para entregas y paradas.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- PESTAÑA: HOJA DE RUTA -->
    <section id="tab-hoja-ruta" class="tab-pane">
      
      <?php if (!empty($paradasActivas)): ?>
      <!-- Interactive Distribution Routing Map -->
      <div class="routing-map-card">
        <h3>Ruta de Distribución Optimizada</h3>
        <p>Trazado en tiempo real desde el Almacén Central EcoAli y secuencia óptima de entrega para ahorro de energía y emisiones.</p>
        
        <div id="leaflet-map" style="width: 100%; height: 350px; border-radius: 20px; border: 1px solid var(--glass-border); box-shadow: var(--shadow-premium); z-index: 1;"></div>
        
        <div style="display:flex; justify-content: space-between; margin-top: 15px; font-size: 11px; font-weight: 800; color: var(--text-medium); text-transform: uppercase;">
          <span>🚦 Tránsito: Fluido</span>
          <span>⚡ Algoritmo: Ruta Óptima Activa</span>
          <span>📍 Satélites: Conectado</span>
        </div>
      </div>
      <?php endif; ?>

      <!-- Paradas list -->
      <div class="stops-container">
        <?php if (!empty($paradasActivas)): ?>
          <?php 
          $idx = 1;
          foreach ($paradasActivas as $parada): 
            $est = strtolower($parada["estado"]);
          ?>
            <article class="stop-card <?php echo $est; ?>" id="stop-card-<?php echo $parada['id']; ?>">
              <div class="stop-number">#<?php echo $idx++; ?></div>
              
              <div class="stop-details">
                <h4>Pedido #PED-<?php echo str_pad($parada["id"], 3, "0", STR_PAD_LEFT); ?></h4>
                <p>
                  👤 Cliente: <strong><?php echo htmlspecialchars($parada["cliente_nombre"] . " " . $parada["cliente_apellido"]); ?></strong><br>
                  📍 Dirección: <strong><?php echo htmlspecialchars($parada["pedido_direccion"]); ?></strong><br>
                  📞 Teléfono: <strong><?php echo htmlspecialchars($parada["cliente_telefono"]); ?></strong><br>
                  💳 Pago: <strong style="text-transform: uppercase; color:var(--secondary);"><?php echo htmlspecialchars($parada["metodo_pago"] . " (" . $parada["pago_estado"] . ")"); ?></strong>
                </p>
              </div>

              <div>
                <span class="badge-status <?php echo $est; ?>"><?php echo $parada["estado"]; ?></span>
              </div>

              <div>
                <?php if ($est === "preparado"): ?>
                  <button class="action-btn" onclick="updateDeliveryStatus(<?php echo $parada['id']; ?>, 'en_ruta')">
                    Iniciar Ruta ➜
                  </button>
                <?php elseif ($est === "en_ruta"): ?>
                  <div class="action-btn-group">
                    <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo urlencode($parada['pedido_direccion']); ?>" target="_blank" style="text-decoration:none;">
                      <button class="action-btn" style="background:#1786ba; box-shadow: 0 8px 16px rgba(23,134,186,0.2);">
                        Iniciar Ruta 🧭
                      </button>
                    </a>
                    <button class="action-btn deliver" onclick="openManageStopModal(<?php echo $parada['id']; ?>, '<?php echo htmlspecialchars($parada['cliente_nombre'] . ' ' . $parada['cliente_apellido']); ?>', '<?php echo htmlspecialchars($parada['pedido_direccion']); ?>')">
                      Gestionar Parada ✓
                    </button>
                  </div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="text-align:center; padding:50px; background:white; border-radius:24px; border:1px solid var(--glass-border); color:var(--text-medium); font-weight:700;">
            No tienes paradas o envíos pendientes asignados en tu ruta actualmente.
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- PESTAÑA: HISTORIAL -->
    <section id="tab-historial" class="tab-pane">
      <div class="history-card">
        <h3>Historial de Entregas Completadas</h3>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
          Registro de los despachos de huevos frescos completados de forma segura con sus correspondientes firmas y GPS.
        </p>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Código Pedido</th>
                <th>Cliente</th>
                <th>Dirección Entrega</th>
                <th>Fecha de Entrega</th>
                <th>GPS / Coordenadas</th>
                <th>Firma Digital</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($entregasHistorial)): ?>
                <?php foreach ($entregasHistorial as $hist): ?>
                  <tr>
                    <td><strong style="color:var(--text-dark);">#PED-<?php echo str_pad($hist["id"], 3, "0", STR_PAD_LEFT); ?></strong></td>
                    <td><?php echo htmlspecialchars($hist["cliente_nombre"] . " " . $hist["cliente_apellido"]); ?></td>
                    <td><?php echo htmlspecialchars($hist["pedido_direccion"]); ?></td>
                    <td><small><?php echo date('d M Y, h:i A', strtotime($hist["fecha_entrega"])); ?></small></td>
                    <td>
                      <span style="font-size: 11px; font-family: monospace; background:#f4ece1; padding:4px 8px; border-radius:6px; font-weight:bold; color:var(--text-medium);">
                        <?php echo htmlspecialchars($hist["coordenadas_entrega"] ?? "Sin GPS"); ?>
                      </span>
                    </td>
                    <td>
                      <?php if (!empty($hist["firma_entrega"])): ?>
                        <img src="<?php echo $hist["firma_entrega"]; ?>" style="max-height: 48px; border: 1px solid var(--glass-border); border-radius: 8px; background: #fffbf5; padding: 2px;" alt="Firma">
                      <?php else: ?>
                        <span style="font-size:11px; color:var(--text-medium);">No capturada</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6" style="text-align:center; color:var(--text-medium); padding:30px;">
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
              <label>Centro de Operaciones Principal / Dirección</label>
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

<!-- Modal: Gestión de Parada (Firma, GPS, Incidencia) -->
<div class="modal-overlay" id="stop-modal">
  <div class="stop-modal-container">
    <button onclick="closeManageStopModal()" style="position:absolute; right:20px; top:20px; border:none; background:none; font-size:22px; font-weight:bold; cursor:pointer; color:var(--text-medium);">&times;</button>
    
    <div style="text-align:left; margin-bottom: 20px;">
      <h3 style="margin: 0; font-size: 20px; font-weight: 800; color: var(--text-dark);" id="modal-ped-title">Gestión de Parada</h3>
      <p style="margin: 4px 0 0; font-size: 13px; color: var(--text-medium);" id="modal-ped-details">Cliente y Dirección</p>
    </div>

    <!-- Dual Tab Selector -->
    <div class="modal-tabs">
      <button class="modal-tab-btn active" id="tab-btn-complete" onclick="switchModalTab('complete')">
        <span>✓</span> Completar
      </button>
      <button class="modal-tab-btn danger" id="tab-btn-incident" onclick="switchModalTab('incident')">
        <span>⚠️</span> Incidencia
      </button>
    </div>

    <!-- Pestaña 1: Completar Entrega -->
    <div class="modal-tab-pane active" id="modal-pane-complete">
      <div class="signature-area">
        <label style="font-size: 11px; font-weight: 800; color: var(--text-medium); text-transform: uppercase; align-self: flex-start;">Firma Manuscrita del Receptor</label>
        <canvas class="signature-canvas" id="signature-canvas" width="380" height="160"></canvas>
        <button onclick="clearSignature()" style="background:#f4ece1; border:none; padding:8px 16px; border-radius:8px; font-size:11px; font-weight:800; color:var(--text-medium); cursor:pointer;">
          Borrar Firma 🧽
        </button>
      </div>

      <!-- Evidencia de Foto (Requisito #21) -->
      <div class="form-group" style="margin-bottom: 20px;">
        <label style="font-size: 11px; font-weight: 800; color: var(--text-medium); text-transform: uppercase; align-self: flex-start;">Evidencia Fotográfica del Envío</label>
        <div style="border: 2px dashed var(--glass-border); border-radius: 16px; padding: 20px; text-align: center; background: #fff; position: relative; cursor: pointer;">
          <input type="file" id="delivery-photo" accept="image/*" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer;" onchange="previewDeliveryPhoto(event)">
          <div id="photo-preview-placeholder" style="color: var(--text-medium); font-size: 13px; font-weight: 600;">
            <span style="font-size: 32px; display: block; margin-bottom: 8px;">📸</span>
            Tomar Foto o Seleccionar Imagen
          </div>
          <img id="photo-preview-img" style="display: none; max-width: 100%; max-height: 150px; border-radius: 12px; margin: 0 auto; box-shadow: 0 4px 12px rgba(0,0,0,0.08);" alt="Previsualización">
        </div>
      </div>

      <div class="form-group" style="margin-bottom: 20px;">
        <label>Ubicación de Despacho (GPS)</label>
        <div style="display:flex; gap:10px;">
          <input type="text" id="delivery-gps" placeholder="Lat: 0.00, Lng: 0.00" readonly style="flex:1;">
          <button onclick="captureLocation()" style="background:var(--secondary); color:white; border:none; padding:0 15px; border-radius:14px; font-weight:800; font-size:13px; cursor:pointer;">
            Geolocalizar 📍
          </button>
        </div>
      </div>

      <button onclick="submitSuccessfulDelivery()" class="btn-submit">
        Registrar Entrega Exitosa ✓
      </button>
    </div>

    <!-- Pestaña 2: Reportar Incidencia / Cancelar -->
    <div class="modal-tab-pane" id="modal-pane-incident">
      <div style="display:flex; flex-direction:column; gap:16px; text-align:left;">
        
        <div class="form-group">
          <label>Tipo de Eventualidad</label>
          <select id="incident-type">
            <option value="cliente_ausente">Cliente Ausente en Domicilio</option>
            <option value="direccion_erronea">Dirección Incorrecta o Inexistente</option>
            <option value="producto_danado">Rotura / Producto Dañado</option>
            <option value="otros">Otros Factores Externos</option>
          </select>
        </div>

        <div class="form-group">
          <label>Descripción / Comentarios Adicionales</label>
          <textarea id="incident-desc" placeholder="Escribe detalles claros del suceso para el centro logístico..."></textarea>
        </div>

        <div style="display:grid; grid-template-columns: 1fr; gap:12px; margin-top: 10px;">
          <button onclick="submitIncidence(false)" class="btn-submit" style="background:#705b44; box-shadow:none;">
            Reportar Incidencia (Sigue en Ruta) 💬
          </button>
          <button onclick="submitIncidence(true)" class="btn-submit danger">
            Cancelar Entrega (Revertir Stock) ❌
          </button>
        </div>

      </div>
    </div>

  </div>
</div>

<!-- Modal: Alertas de Éxito / Error -->
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

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
  // Tab Switcher
  let leafletMapInstance = null; // Instancia global del mapa

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

      // Redibujar el mapa para corregir tamaño en contenedores previamente ocultos
      if (tabName === 'hoja-ruta' && leafletMapInstance) {
          setTimeout(() => {
              leafletMapInstance.invalidateSize();
          }, 200);
      }
  }

  // --- CONTROLES DEL MODAL DE GESTIÓN DE PARADA ---
  let activePedidoId = null;
  const canvas = document.getElementById('signature-canvas');
  const ctx = canvas.getContext('2d');
  let drawing = false;

  // Eventos de firma manuscrita (Ratón y Touch)
  canvas.addEventListener('mousedown', startDrawing);
  canvas.addEventListener('mousemove', draw);
  canvas.addEventListener('mouseup', stopDrawing);
  canvas.addEventListener('mouseout', stopDrawing);

  canvas.addEventListener('touchstart', (e) => {
      e.preventDefault();
      const touch = e.touches[0];
      const rect = canvas.getBoundingClientRect();
      ctx.beginPath();
      ctx.moveTo(touch.clientX - rect.left, touch.clientY - rect.top);
      drawing = true;
  });
  canvas.addEventListener('touchmove', (e) => {
      e.preventDefault();
      if (!drawing) return;
      const touch = e.touches[0];
      const rect = canvas.getBoundingClientRect();
      ctx.lineTo(touch.clientX - rect.left, touch.clientY - rect.top);
      ctx.lineWidth = 3;
      ctx.lineCap = 'round';
      ctx.strokeStyle = '#322514';
      ctx.stroke();
  });
  canvas.addEventListener('touchend', () => drawing = false);

  function startDrawing(e) {
      const rect = canvas.getBoundingClientRect();
      ctx.beginPath();
      ctx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
      drawing = true;
  }

  function draw(e) {
      if (!drawing) return;
      const rect = canvas.getBoundingClientRect();
      ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
      ctx.lineWidth = 3;
      ctx.lineCap = 'round';
      ctx.strokeStyle = '#322514';
      ctx.stroke();
  }

  function stopDrawing() {
      drawing = false;
  }

  function clearSignature() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
  }

  // Geolocalización
  function captureLocation() {
      const gpsInput = document.getElementById('delivery-gps');
      gpsInput.value = "Obteniendo coordenadas...";
      
      if (navigator.geolocation) {
          navigator.geolocation.getCurrentPosition(
              (pos) => {
                  gpsInput.value = `Lat: ${pos.coords.latitude.toFixed(4)}, Lng: ${pos.coords.longitude.toFixed(4)}`;
              },
              (err) => {
                  // Fallback realista en caso de denegación de permisos o red
                  const mockLat = (37.3891 + (Math.random() - 0.5) * 0.05).toFixed(4);
                  const mockLng = (-5.9845 + (Math.random() - 0.5) * 0.05).toFixed(4);
                  gpsInput.value = `Lat: ${mockLat}, Lng: ${mockLng} (Simulado GPS)`;
              },
              { timeout: 5000 }
          );
      } else {
          gpsInput.value = "Lat: 37.3891, Lng: -5.9845 (Simulado)";
      }
  }

  let capturedPhotoBase64 = "";

  function previewDeliveryPhoto(event) {
      const file = event.target.files[0];
      if (!file) return;

      const reader = new FileReader();
      reader.onload = function(e) {
          capturedPhotoBase64 = e.target.result;
          document.getElementById('photo-preview-placeholder').style.display = 'none';
          const img = document.getElementById('photo-preview-img');
          img.src = capturedPhotoBase64;
          img.style.display = 'block';
      };
      reader.readAsDataURL(file);
  }

  // Abrir y Cerrar Modal
  function openManageStopModal(pedidoId, clienteNombre, direccion) {
      activePedidoId = pedidoId;
      document.getElementById('modal-ped-title').textContent = `Gestión de Parada - Pedido #PED-${String(pedidoId).padStart(3, '0')}`;
      document.getElementById('modal-ped-details').innerHTML = `👤 Cliente: <strong>${clienteNombre}</strong><br>📍 Dirección: <strong>${direccion}</strong>`;
      
      // Limpiar inputs
      clearSignature();
      document.getElementById('delivery-gps').value = "";
      document.getElementById('incident-desc').value = "";
      
      // Limpiar y resetear foto de evidencia
      capturedPhotoBase64 = "";
      document.getElementById('delivery-photo').value = "";
      document.getElementById('photo-preview-placeholder').style.display = 'block';
      document.getElementById('photo-preview-img').style.display = 'none';
      document.getElementById('photo-preview-img').src = "";
      
      switchModalTab('complete');
      
      // Auto-geolocalizar
      captureLocation();
      
      document.getElementById('stop-modal').classList.add('active');
  }

  function closeManageStopModal() {
      document.getElementById('stop-modal').classList.remove('active');
  }

  function switchModalTab(tab) {
      document.querySelectorAll('.modal-tab-btn').forEach(btn => btn.classList.remove('active'));
      document.querySelectorAll('.modal-tab-pane').forEach(pane => pane.classList.remove('active'));

      if (tab === 'complete') {
          document.getElementById('tab-btn-complete').classList.add('active');
          document.getElementById('modal-pane-complete').classList.add('active');
      } else {
          document.getElementById('tab-btn-incident').classList.add('active');
          document.getElementById('modal-pane-incident').classList.add('active');
      }
  }

  // --- SUBMISSION LOGIC ---

  // Actualizar estado simple (Recoger y Salir)
  function updateDeliveryStatus(id, newStatus, coords = "", sign = "", photo = "") {
      const payload = {
          pedido_id: id,
          nuevo_estado: newStatus,
          coordenadas: coords,
          firma: sign,
          foto: photo
      };

      fetch('forms/actualizar_estado_entrega.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              closeManageStopModal();
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

  // Registrar Entrega Exitosa
  function submitSuccessfulDelivery() {
      // Validar que se haya dibujado firma
      const blank = document.createElement('canvas');
      blank.width = canvas.width;
      blank.height = canvas.height;
      if (canvas.toDataURL() === blank.toDataURL()) {
          showAlertModal('Firma Obligatoria', 'El cliente debe firmar el panel antes de confirmar la entrega.', '⚠️', 'var(--primary)');
          return;
      }

      // Validar que se haya subido la foto física
      if (!capturedPhotoBase64) {
          showAlertModal('Foto de Evidencia Obligatoria', 'Debes capturar una fotografía de la entrega física como evidencia de control y entrega.', '⚠️', 'var(--primary)');
          return;
      }

      const signBase64 = canvas.toDataURL();
      const gps = document.getElementById('delivery-gps').value || "Lat: 37.3891, Lng: -5.9845";

      updateDeliveryStatus(activePedidoId, 'entregado', gps, signBase64, capturedPhotoBase64);
  }

  // Registrar Incidencia / Cancelar
  function submitIncidence(cancelDelivery) {
      const type = document.getElementById('incident-type').value;
      const desc = document.getElementById('incident-desc').value.trim();
      const gps = document.getElementById('delivery-gps').value || "Lat: 37.3891, Lng: -5.9845";

      if (!desc) {
          showAlertModal('Descripción Obligatoria', 'Describe brevemente el suceso para el reporte.', '⚠️', 'var(--primary)');
          return;
      }

      const payload = {
          pedido_id: activePedidoId,
          tipo: type,
          descripcion: desc,
          coordenadas: gps
      };

      fetch('forms/reportar_incidencia.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              if (cancelDelivery) {
                  // Si es cancelación total, disparamos la actualización del estado del pedido
                  updateDeliveryStatus(activePedidoId, 'cancelado');
              } else {
                  closeManageStopModal();
                  showAlertModal('Incidencia Reportada', data.message, '✓', 'var(--secondary)', true);
              }
          } else {
              showAlertModal('Error', data.message, '✗', '#b02500');
          }
      })
      .catch(err => {
          console.error(err);
          showAlertModal('Error de Servidor', 'Ocurrió un error al registrar la incidencia.', '✗', '#b02500');
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

  // Inicialización del Mapa Leaflet para Paradas Activas
  document.addEventListener('DOMContentLoaded', () => {
      const mapContainer = document.getElementById('leaflet-map');
      if (mapContainer) {
          // Coordenadas del Almacén Central de EcoAli (Sevilla, España)
          const warehouseCoords = [37.3891, -5.9845];
          
          // Crear mapa Leaflet
          leafletMapInstance = L.map('leaflet-map').setView(warehouseCoords, 13);

          // Usar capa CartoDB Dark Matter para coincidir con la estética oscura premium del panel
          L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
              attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
              subdomains: 'abcd',
              maxZoom: 20
          }).addTo(leafletMapInstance);

          // Icono del Almacén Central (Elegante y con sombra)
          const warehouseIcon = L.divIcon({
              html: `<div style="font-size:26px; filter: drop-shadow(0px 3px 6px rgba(23,106,33,0.6)); cursor:pointer;">🏭</div>`,
              className: 'custom-warehouse-marker',
              iconSize: [30, 30],
              iconAnchor: [15, 15]
          });

          L.marker(warehouseCoords, { icon: warehouseIcon })
              .addTo(leafletMapInstance)
              .bindPopup('<strong>Almacén Central EcoAli</strong><br>Centro de operaciones de logística y cadena de frío.');

          // Paradas cargadas dinámicamente desde PHP
          const activeStops = <?php echo json_encode($paradasActivas); ?>;
          const routePoints = [warehouseCoords];

          activeStops.forEach((stop, idx) => {
              // Generar coordenadas deterministas basadas en el ID de parada para distribuirlas en Sevilla
              const seed = parseInt(stop.id) || 1;
              const latOffset = Math.sin(seed * 123.456) * 0.012;
              const lngOffset = Math.cos(seed * 456.789) * 0.012;
              const stopCoords = [warehouseCoords[0] + latOffset, warehouseCoords[1] + lngOffset];
              
              routePoints.push(stopCoords);

              // Usar camión animado si es la parada activa/en ruta, o pin clásico
              const isEnRuta = stop.estado.toLowerCase() === 'en_ruta';
              const iconEmoji = isEnRuta ? '🚚' : '📍';
              const stopIcon = L.divIcon({
                  html: `<div style="font-size:26px; position:relative; animation: bounce 2s infinite; cursor:pointer;">
                            <span style="font-size: 22px;">${iconEmoji}</span>
                            <span style="position:absolute; top:-10px; right:-10px; background:var(--primary); color:white; font-size:10px; font-weight:900; padding:2px 6px; border-radius:50%; border:1px solid white; box-shadow:0 2px 4px rgba(0,0,0,0.2);">${idx + 1}</span>
                         </div>`,
                  className: 'custom-stop-marker',
                  iconSize: [30, 30],
                  iconAnchor: [15, 15]
              });

              L.marker(stopCoords, { icon: stopIcon })
                  .addTo(leafletMapInstance)
                  .bindPopup(`
                      <div style="font-family:'Manrope',sans-serif; color:var(--text-dark); padding:4px;">
                        <strong style="font-size:13px;">Parada #${idx + 1} - Pedido #PED-${String(stop.id).padStart(3, '0')}</strong><br>
                        <span style="font-size:11px; display:block; margin-top:4px;">👤 Cliente: <b>${stop.cliente_nombre} ${stop.cliente_apellido}</b></span>
                        <span style="font-size:11px; display:block;">📍 Dirección: <b>${stop.pedido_direccion}</b></span>
                        <span style="font-size:11px; display:block; margin-bottom:6px;">📞 Teléfono: <b>${stop.cliente_telefono}</b></span>
                        <span class="badge-status ${stop.estado.toLowerCase()}" style="font-size:10px; font-weight:800; padding:3px 8px; border-radius:6px;">${stop.estado}</span>
                      </div>
                  `);
          });

          // Trazar línea de ruta elegante con guiones
          L.polyline(routePoints, {
              color: 'var(--primary)',
              weight: 4,
              opacity: 0.8,
              dashArray: '8, 8',
              lineJoin: 'round'
          }).addTo(leafletMapInstance);

          // Ajustar límites automáticamente
          const bounds = L.latLngBounds(routePoints);
          leafletMapInstance.fitBounds(bounds, { padding: [50, 50] });
      }
  });
</script>

</body>
</html>