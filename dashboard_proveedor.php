<?php
session_start();
require "forms/conexion.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

if ((int)$_SESSION["rol_id"] !== 3) {
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION["usuario_id"];

// --- CONSULTAS DINÁMICAS ---

// 1. Obtener perfil completo del proveedor
$stmtProv = $conn->prepare("SELECT * FROM proveedores WHERE usuario_id = ?");
$stmtProv->bind_param("i", $usuario_id);
$stmtProv->execute();
$resProv = $stmtProv->get_result();
$proveedor = $resProv->fetch_assoc();

$nombre_empresa = $proveedor["nombre_empresa"] ?? "Granja EcoAli";
$contacto = $proveedor["contacto"] ?? ($_SESSION["nombre"] ?? "Proveedor");
$telefono = $proveedor["telefono"] ?? "";
$ubicacion = $proveedor["ubicacion"] ?? "";
$proveedor_id = $proveedor ? (int)$proveedor["id"] : 0;

// 2. Obtener lista de productos activos para registro de postura
$productosRes = $conn->query("SELECT * FROM productos WHERE activo = 1 ORDER BY nombre ASC");
$productosList = [];
if ($productosRes) {
    while ($row = $productosRes->fetch_assoc()) {
        $productosList[] = $row;
    }
}

// 3. Métricas de Producción del Proveedor
$huevosTotales = 0;
$lotesTotales = 0;
$lotesCaducados = 0;
$lotesBajoStock = 0;

if ($proveedor_id > 0) {
    // Total huevos producidos
    $stmtMet = $conn->prepare("SELECT SUM(cantidad), COUNT(*) FROM produccion WHERE proveedor_id = ?");
    $stmtMet->bind_param("i", $proveedor_id);
    $stmtMet->execute();
    $resMet = $stmtMet->get_result();
    $metRow = $resMet->fetch_row();
    $huevosTotales = (int)($metRow[0] ?? 0);
    $lotesTotales = (int)($metRow[1] ?? 0);

    // Lotes caducados
    $stmtCad = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'caducado'");
    $stmtCad->bind_param("i", $proveedor_id);
    $stmtCad->execute();
    $resCad = $stmtCad->get_result();
    $lotesCaducados = (int)($resCad->fetch_row()[0] ?? 0);

    // Lotes bajo stock (< 100 unidades)
    $stmtLow = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'bajo_stock' OR (proveedor_id = ? AND estado = 'disponible' AND cantidad < 100)");
    $stmtLow->bind_param("ii", $proveedor_id, $proveedor_id);
    $stmtLow->execute();
    $resLow = $stmtLow->get_result();
    $lotesBajoStock = (int)($resLow->fetch_row()[0] ?? 0);
}

// 4. Obtener Granjas / Historial de Producción Reciente
$producciones = [];
if ($proveedor_id > 0) {
    $prodQuery = "SELECT p.id, p.cantidad, p.fecha_produccion, p.observaciones, pr.nombre AS producto_nombre, pr.tipo_huevo, pr.tamano
                  FROM produccion p
                  INNER JOIN productos pr ON p.producto_id = pr.id
                  WHERE p.proveedor_id = ?
                  ORDER BY p.id DESC LIMIT 10";
    $stmtPList = $conn->prepare($prodQuery);
    $stmtPList->bind_param("i", $proveedor_id);
    $stmtPList->execute();
    $resPList = $stmtPList->get_result();
    while ($row = $resPList->fetch_assoc()) {
        $producciones[] = $row;
    }
}

// 5. Lotes Activos en Almacén
$lotesAlmacen = [];
if ($proveedor_id > 0) {
    $lotesQuery = "SELECT i.id, i.codigo_lote, i.cantidad, i.fecha_postura, i.fecha_caducidad, i.estado, pr.nombre AS producto_nombre
                   FROM inventario_huevos i
                   INNER JOIN productos pr ON i.producto_id = pr.id
                   WHERE i.proveedor_id = ?
                   ORDER BY i.id DESC LIMIT 15";
    $stmtLList = $conn->prepare($lotesQuery);
    $stmtLList->bind_param("i", $proveedor_id);
    $stmtLList->execute();
    $resLList = $stmtLList->get_result();
    while ($row = $resLList->fetch_assoc()) {
        $lotesAlmacen[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel de Proveedor - ECOALI</title>

  <link rel="stylesheet" href="assets/css/globals.css">
  <link rel="stylesheet" href="assets/css/proveedor.css?v=<?php echo time(); ?>">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <style>
    /* Estilos Corporativos EcoAli Proveedores */
    :root {
      --bg-organic: #fff5ed;
      --primary: #ff8a00;
      --primary-hover: #e07b00;
      --secondary: #176a21;
      --secondary-light: #effeed;
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

    .provider-container {
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
      background: rgba(23, 106, 33, 0.08);
      border-radius: 20px;
      padding: 16px;
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 30px;
      border: 1px solid rgba(23, 106, 33, 0.12);
    }

    .sidebar .profile-card .avatar {
      width: 44px;
      height: 44px;
      background: var(--secondary);
      color: white;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-weight: 800;
      font-size: 18px;
      box-shadow: 0 8px 16px rgba(23, 106, 33, 0.25);
    }

    .sidebar .profile-card .info h4 {
      margin: 0;
      font-size: 14px;
      font-weight: 700;
      color: var(--text-dark);
      text-overflow: ellipsis;
      overflow: hidden;
      white-space: nowrap;
      max-width: 150px;
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
      background: var(--secondary);
      color: white;
      box-shadow: 0 10px 20px rgba(23, 106, 33, 0.25);
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

    .header-btn {
      background: var(--primary);
      color: white;
      border: none;
      padding: 12px 24px;
      border-radius: 16px;
      font-weight: 800;
      font-size: 14px;
      cursor: pointer;
      box-shadow: 0 10px 20px rgba(255, 138, 0, 0.25);
      transition: var(--transition-fast);
    }

    .header-btn:hover {
      background: var(--primary-hover);
      transform: scale(1.02);
    }

    /* Tab Pane */
    .tab-pane {
      display: none;
      animation: fadeIn 0.4s ease-out forwards;
    }

    .tab-pane.active {
      display: block;
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

    .metric-card.warn { border-color: rgba(255, 138, 0, 0.35); background: rgba(255, 138, 0, 0.02); }
    .metric-card.danger { border-color: rgba(176, 37, 0, 0.35); background: rgba(176, 37, 0, 0.02); }
    .metric-card.danger .value { color: #b02500; }

    /* Layouts */
    .dashboard-layout {
      display: grid;
      grid-template-columns: 1.2fr 1fr;
      gap: 30px;
    }

    .card {
      background: white;
      border-radius: 28px;
      padding: 30px;
      border: 1px solid var(--glass-border);
      box-shadow: var(--shadow-premium);
      margin-bottom: 30px;
    }

    .card h3 {
      margin: 0 0 20px;
      font-size: 20px;
      font-weight: 800;
      color: var(--text-dark);
    }

    /* Granjas */
    .farm-item {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 16px 0;
      border-bottom: 1px solid rgba(213,164,112,0.08);
    }

    .farm-item:last-child {
      border: none;
    }

    .farm-icon {
      width: 52px;
      height: 52px;
      background: #ffe3ca;
      border-radius: 16px;
      display: grid;
      place-items: center;
      font-size: 24px;
    }

    .farm-details h4 {
      margin: 0;
      font-size: 15px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .farm-details p {
      margin: 4px 0 0;
      font-size: 12px;
      color: var(--text-medium);
      font-weight: 600;
    }

    /* Lotes Table */
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

    .badge-status {
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
    }

    .badge-status.disponible { background: var(--secondary-light); color: var(--secondary); }
    .badge-status.bajo_stock { background: rgba(255, 138, 0, 0.1); color: var(--primary); }
    .badge-status.caducado { background: rgba(176, 37, 0, 0.1); color: #b02500; }

    /* Timeline Traceability */
    .timeline {
      display: flex;
      flex-direction: column;
      gap: 20px;
      position: relative;
      padding-left: 20px;
    }

    .timeline::before {
      content: "";
      position: absolute;
      left: 4px;
      top: 8px;
      bottom: 8px;
      width: 2px;
      background: var(--glass-border);
    }

    .timeline-item {
      position: relative;
    }

    .timeline-item::before {
      content: "";
      position: absolute;
      left: -20px;
      top: 6px;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: var(--primary);
      border: 2px solid white;
      box-shadow: 0 0 0 4px rgba(255, 138, 0, 0.15);
    }

    .timeline-item small {
      font-size: 10px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
    }

    .timeline-item h4 {
      margin: 4px 0 2px;
      font-size: 14px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .timeline-item p {
      margin: 0;
      font-size: 12px;
      color: var(--text-medium);
      line-height: 1.5;
    }

    /* PESTAÑA: REGISTRAR PRODUCCIÓN */
    .form-box {
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

    .form-group input,
    .form-group select,
    .form-group textarea {
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
      height: 120px;
      padding: 16px;
      resize: none;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      border-color: var(--secondary);
      box-shadow: 0 0 0 4px rgba(23, 106, 33, 0.1);
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

    .btn-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 16px 28px rgba(23, 106, 33, 0.32);
    }

    /* Modal */
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
      color: var(--secondary);
    }

    @media (max-width: 991px) {
      .sidebar {
        display: none !important;
      }

      .mobile-nav {
        display: grid !important;
      }

      .main-content {
        margin-left: 0 !important;
        padding: 24px 20px 84px !important;
        width: 100% !important;
      }

      .dashboard-layout {
        grid-template-columns: 1fr;
      }

      .form-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>

<div class="provider-container">

  <!-- Sidebar (Desktop) -->
  <aside class="sidebar">
    <div class="brand">☰ ECOALI</div>

    <div class="profile-card">
      <div class="avatar">🥚</div>
      <div class="info">
        <h4><?php echo htmlspecialchars($nombre_empresa); ?></h4>
        <p>Granja Proveedora</p>
      </div>
    </div>

    <nav class="sidebar-menu">
      <button class="menu-btn active" onclick="switchTab('dashboard', this)">
        <span>▦</span> <span>Dashboard</span>
      </button>
      <button class="menu-btn" onclick="switchTab('registrar', this)">
        <span>✚</span> <span>Registrar Producción</span>
      </button>
      <button class="menu-btn" onclick="switchTab('lotes', this)">
        <span>▣</span> <span>Historial Lotes</span>
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
        <h1 id="page-title">Panel de Control</h1>
        <p id="page-subtitle">Gestión de lotes orgánicos y trazabilidad total.</p>
      </div>

      <button class="header-btn" onclick="switchTab('registrar', document.querySelectorAll('.sidebar-menu button')[1])">
        Registrar Producción
      </button>
    </header>

    <!-- PESTAÑA: DASHBOARD -->
    <section id="tab-dashboard" class="tab-pane active">
      <div class="metrics-grid">
        <div class="metric-card">
          <span class="label">Huevos Producidos</span>
          <span class="value"><?php echo number_format($huevosTotales); ?> ud</span>
        </div>
        <div class="metric-card">
          <span class="label">Lotes Registrados</span>
          <span class="value"><?php echo $lotesTotales; ?> lotes</span>
        </div>
        <div class="metric-card warn">
          <span class="label">Lotes con Bajo Stock</span>
          <span class="value"><?php echo $lotesBajoStock; ?> lotes</span>
        </div>
        <div class="metric-card danger">
          <span class="label">Lotes Caducados</span>
          <span class="value"><?php echo $lotesCaducados; ?> lotes</span>
        </div>
      </div>

      <div class="dashboard-layout">
        <!-- Granjas e Historial -->
        <div>
          <div class="card">
            <h3>Granjas Proveedoras Vinculadas</h3>
            <div class="farm-item">
              <div class="farm-icon">🚜</div>
              <div class="farm-details">
                <h4>Granja Los Olivos</h4>
                <p>Ubicación: <?php echo htmlspecialchars($ubicacion ?: "Vereda El Salitre"); ?> | Tel: <?php echo htmlspecialchars($telefono ?: "+34 600 000 000"); ?></p>
              </div>
            </div>
            <div class="farm-item">
              <div class="farm-icon">🏡</div>
              <div class="farm-details">
                <h4>Hacienda El Rocío</h4>
                <p>Ubicación: Valle de Atuntaqui | Operación Principal</p>
              </div>
            </div>
          </div>

          <div class="card">
            <h3>Historial de Producción Reciente</h3>
            <div class="table-responsive">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Producto</th>
                    <th>Tamaño</th>
                    <th>Cantidad</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($producciones)): ?>
                    <?php foreach ($producciones as $prod): ?>
                      <tr>
                        <td><?php echo date("d M Y", strtotime($prod["fecha_produccion"])); ?></td>
                        <td><?php echo htmlspecialchars($prod["producto_nombre"]); ?></td>
                        <td><?php echo htmlspecialchars($prod["tamano"]); ?></td>
                        <td><strong style="color:var(--secondary);"><?php echo number_format($prod["cantidad"]); ?> ud</strong></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="4" style="text-align:center; color:var(--text-medium); padding:20px;">
                        Aún no has registrado producciones de huevos.
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Timeline Traceability -->
        <div>
          <div class="card">
            <h3>🔀 Trazabilidad y Eventos</h3>
            <div class="timeline">
              <?php if (!empty($lotesAlmacen)): ?>
                <?php foreach (array_slice($lotesAlmacen, 0, 4) as $l): ?>
                  <div class="timeline-item">
                    <small><?php echo date("d M Y", strtotime($l["fecha_postura"])); ?></small>
                    <h4>Ingreso Lote <?php echo htmlspecialchars($l["codigo_lote"]); ?></h4>
                    <p>
                      Se clasificaron e ingresaron **<?php echo number_format($l["cantidad"]); ?> ud** de **<?php echo htmlspecialchars($l["producto_nombre"]); ?>** al almacén de distribución.
                    </p>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="timeline-item">
                  <small>EcoAli</small>
                  <h4>Operaciones Limpias</h4>
                  <p>No se reportan ingresos de lotes recientes.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- PESTAÑA: REGISTRAR PRODUCCIÓN -->
    <section id="tab-registrar" class="tab-pane">
      <div class="card form-box">
        <h3>Registrar Nueva Producción Diaria</h3>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
          Ingresa la postura recolectada del día. Esto generará un lote automático y actualizará el stock disponible para ventas en tiempo real en la tienda del cliente.
        </p>

        <form id="production-form" onsubmit="submitProduction(event)">
          <div class="form-grid">
            <div class="form-group">
              <label>Tipo de Huevo Producido</label>
              <select id="prod-producto-id" required>
                <option value="">Selecciona tipo de huevo</option>
                <?php foreach ($productosList as $pr): ?>
                  <option value="<?php echo $pr['id']; ?>"><?php echo htmlspecialchars($pr['nombre'] . " [" . $pr['tamano'] . "]"); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label>Cantidad Recolectada (Unidades)</label>
              <input type="number" id="prod-cantidad" placeholder="Ej: 1500" min="1" required>
            </div>

            <div class="form-group">
              <label>Fecha de Recolección / Postura</label>
              <input type="date" id="prod-fecha" value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="form-group">
              <label>Observaciones de Trazabilidad</label>
              <input type="text" id="prod-obs" placeholder="Ej: Excelente color, cáscara firme. Granja 1.">
            </div>

            <button type="submit" class="btn-submit">Registrar Postura y Generar Lote</button>
          </div>
        </form>
      </div>
    </section>

    <!-- PESTAÑA: HISTORIAL LOTES -->
    <section id="tab-lotes" class="tab-pane">
      <div class="card">
        <h3>Lotes de Huevos en Almacén</h3>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
          Listado completo de tus lotes. La fecha de caducidad se calcula automáticamente a los 30 días de la postura.
        </p>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Código Lote</th>
                <th>Producto</th>
                <th>Cantidad Stock</th>
                <th>Fecha Postura</th>
                <th>Fecha Caducidad</th>
                <th>Estado Lote</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($lotesAlmacen)): ?>
                <?php foreach ($lotesAlmacen as $l): 
                  $estClass = strtolower($l["estado"]);
                ?>
                  <tr>
                    <td><strong style="color:var(--text-dark);"><?php echo htmlspecialchars($l["codigo_lote"]); ?></strong></td>
                    <td><?php echo htmlspecialchars($l["producto_nombre"]); ?></td>
                    <td><strong><?php echo number_format($l["cantidad"]); ?> ud</strong></td>
                    <td><?php echo date("d M Y", strtotime($l["fecha_postura"])); ?></td>
                    <td><?php echo date("d M Y", strtotime($l["fecha_caducidad"])); ?></td>
                    <td><span class="badge-status <?php echo $estClass; ?>"><?php echo $l["estado"]; ?></span></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6" style="text-align:center; color:var(--text-medium); padding:30px;">
                    No hay lotes ingresados al inventario de EcoAli.
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
      <div class="card form-box">
        <h3>Información del Proveedor</h3>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
          Actualiza los datos de tu empresa proveedora avícola.
        </p>

        <form id="profile-form" onsubmit="saveProfile(event)">
          <div class="form-grid">
            <div class="form-group">
              <label>Nombre de la Empresa / Granja</label>
              <input type="text" id="prof-empresa" value="<?php echo htmlspecialchars($nombre_empresa); ?>" required>
            </div>

            <div class="form-group">
              <label>Contacto Representante</label>
              <input type="text" id="prof-contacto" value="<?php echo htmlspecialchars($contacto); ?>" required>
            </div>

            <div class="form-group">
              <label>Teléfono de la Empresa</label>
              <input type="text" id="prof-telefono" value="<?php echo htmlspecialchars($telefono); ?>">
            </div>

            <div class="form-group">
              <label>Ubicación Geográfica Principal</label>
              <input type="text" id="prof-ubicacion" value="<?php echo htmlspecialchars($ubicacion); ?>" placeholder="Ej: Sevilla, España">
            </div>

            <button type="submit" class="btn-submit">Guardar Cambios de Proveedor</button>
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
    <button class="mobile-nav-btn" onclick="switchTab('registrar', this)">
      <span>✚</span>
      <span>Postura</span>
    </button>
    <button class="mobile-nav-btn" onclick="switchTab('lotes', this)">
      <span>▣</span>
      <span>Lotes</span>
    </button>
    <button class="mobile-nav-btn" onclick="switchTab('perfil', this)">
      <span>♙</span>
      <span>Perfil</span>
    </button>
  </nav>

</div>

<!-- Modal: Éxito -->
<div class="modal-overlay" id="alert-modal">
  <div class="modal-container">
    <div style="font-size: 60px; color: var(--secondary); margin-bottom: 20px;" id="alert-icon">✓</div>
    <h3 style="margin: 0 0 10px; font-size: 22px; font-weight: 800;" id="alert-title">¡Lote Creado!</h3>
    <p style="margin: 0 0 25px; font-size: 14px; color: var(--text-medium); line-height: 1.6;" id="alert-message">
      Lote registrado y disponible para la venta.
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
          'dashboard': { title: 'Panel de Control', subtitle: 'Gestión de lotes orgánicos y trazabilidad total.' },
          'registrar': { title: 'Registrar Postura', subtitle: 'Ingresa la recolección del día para generar un lote.' },
          'lotes': { title: 'Lotes en Almacén', subtitle: 'Trazabilidad y control de stock avícola.' },
          'perfil': { title: 'Perfil de Proveedor', subtitle: 'Configura los datos de tu empresa avícola.' }
      };

      if (titles[tabName]) {
          document.getElementById('page-title').textContent = titles[tabName].title;
          document.getElementById('page-subtitle').textContent = titles[tabName].subtitle;
      }
  }

  // Enviar Producción (AJAX)
  function submitProduction(e) {
      e.preventDefault();

      const prodId = document.getElementById('prod-producto-id').value;
      const cant = document.getElementById('prod-cantidad').value;
      const fec = document.getElementById('prod-fecha').value;
      const obs = document.getElementById('prod-obs').value;

      const payload = {
          producto_id: prodId,
          cantidad: cant,
          fecha_produccion: fec,
          observaciones: obs
      };

      fetch('forms/procesar_produccion.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              showAlertModal(
                  '¡Lote Generado!',
                  `Se registró el lote '${data.codigo_lote}' con ${data.cantidad} huevos frescos en el inventario.`,
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
          showAlertModal('Error de Servidor', 'Ocurrió un error inesperado al procesar.', '✗', '#b02500');
      });
  }

  // Guardar Perfil de Proveedor (AJAX)
  function saveProfile(e) {
      e.preventDefault();

      const emp = document.getElementById('prof-empresa').value;
      const con = document.getElementById('prof-contacto').value;
      const tel = document.getElementById('prof-telefono').value;
      const ubi = document.getElementById('prof-ubicacion').value;

      const payload = {
          nombre_empresa: emp,
          contacto: con,
          telefono: tel,
          ubicacion: ubi
      };

      fetch('forms/actualizar_perfil_proveedor.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              showAlertModal('Perfil Actualizado', data.message, '✓', 'var(--secondary)', true);
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