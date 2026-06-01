<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - PORTAL PREMIUM DEL PROVEEDOR / GRANJERO
 * --------------------------------------------------------------------------------
 * Este panel permite a los productores avícolas gestionar sus granjas, registrar
 * la postura diaria asociando los lotes a granjas específicas para trazabilidad,
 * monitorear alertas de stock y caducidad, y consultar la cadena de custodia.
 */

session_start();
require "forms/conexion.php";

// 1. CONTROL DE ACCESO
if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

if ((int)$_SESSION["rol_id"] !== 3) {
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION["usuario_id"];

// 2. OBTENER PERFIL DEL PROVEEDOR
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

// 3. OBTENER LISTA DE PRODUCTOS ACTIVOS
$productosRes = $conn->query("SELECT * FROM productos WHERE activo = 1 ORDER BY nombre ASC");
$productosList = [];
if ($productosRes) {
    while ($row = $productosRes->fetch_assoc()) {
        $productosList[] = $row;
    }
}

// 4. OBTENER GRANJAS DEL PROVEEDOR
$granjas = [];
if ($proveedor_id > 0) {
    $stmtG = $conn->prepare("SELECT * FROM granjas WHERE proveedor_id = ? ORDER BY id DESC");
    $stmtG->bind_param("i", $proveedor_id);
    $stmtG->execute();
    $resG = $stmtG->get_result();
    while ($row = $resG->fetch_assoc()) {
        $granjas[] = $row;
    }
}

// 5. MÉTRICAS Y ALERTAS AUTOMÁTICAS
$huevosTotales = 0;
$lotesTotales = 0;
$lotesCaducados = 0;
$lotesBajoStock = 0;
$lotesProximosACaducar = 0;

$fechaHoy = date('Y-m-d');
$fechaLimite = date('Y-m-d', strtotime('+7 days'));

if ($proveedor_id > 0) {
    // Total de posturas y lotes
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

    // Lotes con bajo stock (< 100 unidades y con cantidad > 0)
    $stmtLow = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND cantidad > 0 AND (estado = 'bajo_stock' OR cantidad < 100)");
    $stmtLow->bind_param("i", $proveedor_id);
    $stmtLow->execute();
    $resLow = $stmtLow->get_result();
    $lotesBajoStock = (int)($resLow->fetch_row()[0] ?? 0);

    // Lotes próximos a caducar (en los próximos 7 días, cantidad > 0 y no caducados aún)
    $stmtProx = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND cantidad > 0 AND estado != 'caducado' AND fecha_caducidad >= ? AND fecha_caducidad <= ?");
    $stmtProx->bind_param("iss", $proveedor_id, $fechaHoy, $fechaLimite);
    $stmtProx->execute();
    $resProx = $stmtProx->get_result();
    $lotesProximosACaducar = (int)($resProx->fetch_row()[0] ?? 0);
}

// 6. HISTORIAL DE PRODUCCIÓN RECIENTE (CON GRANJA)
$producciones = [];
if ($proveedor_id > 0) {
    $prodQuery = "SELECT p.id, p.cantidad, p.fecha_produccion, p.observaciones, pr.nombre AS producto_nombre, pr.tipo_huevo, pr.tamano, g.nombre AS granja_nombre
                  FROM produccion p
                  INNER JOIN productos pr ON p.producto_id = pr.id
                  LEFT JOIN granjas g ON p.granja_id = g.id
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

// 7. HISTORIAL COMPLETO DE LOTES (CON GRANJA)
$lotesAlmacen = [];
if ($proveedor_id > 0) {
    $lotesQuery = "SELECT i.id, i.codigo_lote, i.cantidad, i.fecha_postura, i.fecha_caducidad, i.estado, pr.nombre AS producto_nombre, g.nombre AS granja_nombre
                   FROM inventario_huevos i
                   INNER JOIN productos pr ON i.producto_id = pr.id
                   LEFT JOIN granjas g ON i.granja_id = g.id
                   WHERE i.proveedor_id = ?
                   ORDER BY i.id DESC LIMIT 20";
    $stmtLList = $conn->prepare($lotesQuery);
    $stmtLList->bind_param("i", $proveedor_id);
    $stmtLList->execute();
    $resLList = $stmtLList->get_result();
    while ($row = $resLList->fetch_assoc()) {
        $lotesAlmacen[] = $row;
    }
}

// 8. OBTENER INFORMACIÓN DE TRAZABILIDAD (Mapeo completo indexado para búsqueda instantánea en JS)
$traceData = [];
if ($proveedor_id > 0) {
    $traceQuery = "SELECT i.codigo_lote, i.cantidad, i.fecha_postura, i.fecha_caducidad, i.estado, 
                          pr.nombre AS producto_nombre, pr.tipo_huevo, pr.tamano, 
                          g.nombre AS granja_nombre, g.identificacion AS granja_identificacion, g.ubicacion AS granja_ubicacion
                   FROM inventario_huevos i
                   INNER JOIN productos pr ON i.producto_id = pr.id
                   LEFT JOIN granjas g ON i.granja_id = g.id
                   WHERE i.proveedor_id = ?
                   ORDER BY i.id DESC";
    $stmtT = $conn->prepare($traceQuery);
    $stmtT->bind_param("i", $proveedor_id);
    $stmtT->execute();
    $resT = $stmtT->get_result();
    while ($row = $resT->fetch_assoc()) {
        $daysLeft = (strtotime($row["fecha_caducidad"]) - strtotime($fechaHoy)) / 86400;
        $row["dias_restantes"] = max(0, (int)$daysLeft);
        $traceData[$row["codigo_lote"]] = $row;
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
    :root {
      --bg-organic: #fff8f3;
      --primary: #ff8a00;
      --primary-hover: #e07b00;
      --secondary: #176a21;
      --secondary-light: #effeed;
      --text-dark: #3c2000;
      --text-medium: #704d25;
      --glass-bg: rgba(255, 255, 255, 0.92);
      --glass-border: rgba(213, 164, 112, 0.22);
      --shadow-premium: 0 15px 35px rgba(70, 40, 0, 0.06);
      --transition-fast: all 0.2s ease-in-out;
    }

    body {
      background-color: #fffaf7;
      background-image: radial-gradient(circle at 5% 5%, rgba(255,138,0,0.03) 0%, transparent 35%),
                        radial-gradient(circle at 95% 95%, rgba(23,106,33,0.03) 0%, transparent 40%);
      color: var(--text-dark);
      font-family: 'Manrope', sans-serif;
      min-height: 100vh;
      margin: 0;
    }

    .provider-container {
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar Premium */
    .sidebar {
      width: 280px;
      background: var(--glass-bg);
      backdrop-filter: blur(20px);
      border-right: 1px solid var(--glass-border);
      display: flex;
      flex-direction: column;
      padding: 35px 24px;
      position: fixed;
      height: 100vh;
      z-index: 10;
      box-shadow: 8px 0 30px rgba(70, 40, 0, 0.02);
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
      background: rgba(23, 106, 33, 0.05);
      border-radius: 20px;
      padding: 16px;
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 30px;
      border: 1px solid rgba(23, 106, 33, 0.08);
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
      background: rgba(213, 164, 112, 0.08);
      color: var(--text-dark);
      transform: translateX(4px);
    }

    .sidebar-menu button.active {
      background: var(--secondary);
      color: white;
      box-shadow: 0 8px 20px rgba(23, 106, 33, 0.2);
    }

    .logout-btn {
      width: 100%;
      padding: 14px;
      border-radius: 14px;
      border: 1px solid rgba(176, 37, 0, 0.15);
      background: rgba(176, 37, 0, 0.03);
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
      box-shadow: 0 8px 20px rgba(176, 37, 0, 0.15);
    }

    /* Main Content Area */
    .main-content {
      margin-left: 280px;
      flex-grow: 1;
      min-height: 100vh;
      background: var(--bg-organic);
      padding: 40px;
      position: relative;
      padding-bottom: 80px;
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
      box-shadow: 0 8px 20px rgba(255, 138, 0, 0.2);
      transition: var(--transition-fast);
    }

    .header-btn:hover {
      background: var(--primary-hover);
      transform: scale(1.02);
    }

    /* Tab Layouts */
    .tab-pane {
      display: none;
      animation: fadeIn 0.3s ease-out forwards;
    }

    .tab-pane.active {
      display: block;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Cards & Layout */
    .card {
      background: white;
      border-radius: 24px;
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

    .dashboard-layout {
      display: grid;
      grid-template-columns: 1.2fr 1fr;
      gap: 30px;
    }

    /* Metrics Grid */
    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 24px;
      margin-bottom: 35px;
    }

    .metric-card {
      background: white;
      border-radius: 20px;
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
      font-size: 28px;
      font-weight: 800;
      color: var(--text-dark);
      margin-top: 10px;
    }

    .metric-card.warn { border-color: rgba(255, 138, 0, 0.3); background: rgba(255, 138, 0, 0.01); }
    .metric-card.warn .value { color: var(--primary); }
    .metric-card.danger { border-color: rgba(176, 37, 0, 0.3); background: rgba(176, 37, 0, 0.01); }
    .metric-card.danger .value { color: #b02500; }

    /* Farms Tab Styling */
    .farm-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 24px;
      margin-bottom: 30px;
    }

    .farm-card {
      background: white;
      border-radius: 20px;
      border: 1px solid var(--glass-border);
      padding: 24px;
      box-shadow: var(--shadow-premium);
      display: flex;
      flex-direction: column;
      position: relative;
      transition: var(--transition-fast);
    }

    .farm-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 20px 40px rgba(70, 40, 0, 0.08);
    }

    .farm-card .badge-id {
      position: absolute;
      top: 20px;
      right: 20px;
      background: var(--secondary-light);
      color: var(--secondary);
      font-size: 10px;
      font-weight: 800;
      padding: 4px 10px;
      border-radius: 6px;
      text-transform: uppercase;
    }

    .farm-card .farm-icon {
      font-size: 32px;
      margin-bottom: 16px;
    }

    .farm-card h4 {
      margin: 0 0 8px;
      font-size: 18px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .farm-card p {
      margin: 4px 0 0;
      font-size: 13px;
      color: var(--text-medium);
      line-height: 1.5;
    }

    .farm-card .btn-delete-farm {
      margin-top: 20px;
      border: none;
      background: rgba(176, 37, 0, 0.05);
      color: #b02500;
      padding: 10px;
      border-radius: 10px;
      font-size: 12px;
      font-weight: 800;
      cursor: pointer;
      text-align: center;
      transition: var(--transition-fast);
    }

    .farm-card .btn-delete-farm:hover {
      background: #b02500;
      color: white;
    }

    /* Stepper Traceability Timeline */
    .stepper {
      position: relative;
      padding-left: 30px;
      margin-top: 20px;
      display: flex;
      flex-direction: column;
      gap: 25px;
    }

    .stepper::before {
      content: "";
      position: absolute;
      left: 7px;
      top: 10px;
      bottom: 10px;
      width: 2px;
      background: rgba(23, 106, 33, 0.15);
    }

    .step {
      position: relative;
    }

    .step::before {
      content: "";
      position: absolute;
      left: -30px;
      top: 4px;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: var(--secondary);
      border: 3px solid white;
      box-shadow: 0 0 0 4px rgba(23, 106, 33, 0.15);
      z-index: 2;
    }

    .step.active::before {
      background: var(--primary);
      box-shadow: 0 0 0 4px rgba(255, 138, 0, 0.15);
    }

    .step small {
      font-size: 10px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .step h4 {
      margin: 4px 0 2px;
      font-size: 15px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .step p {
      margin: 0;
      font-size: 13px;
      color: var(--text-medium);
      line-height: 1.5;
    }

    /* Inputs y Formularios */
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
      font-size: 11px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      margin-left: 4px;
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
      height: 100px;
      padding: 16px;
      resize: none;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      border-color: var(--secondary);
      box-shadow: 0 0 0 4px rgba(23, 106, 33, 0.08);
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
      box-shadow: 0 8px 20px rgba(23, 106, 33, 0.15);
      transition: var(--transition-fast);
      margin-top: 10px;
    }

    .btn-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 12px 25px rgba(23, 106, 33, 0.25);
    }

    /* Tables */
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
      border-bottom: 1px solid rgba(213, 164, 112, 0.06);
    }

    .badge-status {
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      display: inline-block;
    }

    .badge-status.disponible { background: var(--secondary-light); color: var(--secondary); }
    .badge-status.bajo_stock { background: rgba(255, 138, 0, 0.1); color: var(--primary); }
    .badge-status.caducado { background: rgba(176, 37, 0, 0.1); color: #b02500; }

    /* Modales */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(0,0,0,0.4);
      backdrop-filter: blur(5px);
      z-index: 200;
      opacity: 0;
      pointer-events: none;
      display: grid;
      place-items: center;
      transition: opacity 0.25s ease;
    }

    .modal-overlay.active {
      opacity: 1;
      pointer-events: all;
    }

    .modal-container {
      background: white;
      border-radius: 28px;
      width: 90%;
      max-width: 440px;
      padding: 40px 30px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.15);
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
      box-shadow: 0 -5px 20px rgba(0,0,0,0.03);
      border-top: 1px solid var(--glass-border);
      z-index: 99;
      grid-template-columns: repeat(5, 1fr);
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
      font-size: 16px;
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
    <div class="brand">🌱 ECOALI</div>

    <div class="profile-card">
      <div class="avatar">👨‍🌾</div>
      <div class="info">
        <h4><?php echo htmlspecialchars($nombre_empresa); ?></h4>
        <p>Granjero Proveedor</p>
      </div>
    </div>

    <nav class="sidebar-menu">
      <button class="menu-btn active" onclick="switchTab('dashboard', this)">
        <span>▦</span> <span>Dashboard</span>
      </button>
      <button class="menu-btn" onclick="switchTab('granjas', this)">
        <span>🚜</span> <span>Mis Granjas</span>
      </button>
      <button class="menu-btn" onclick="switchTab('registrar', this)">
        <span>✚</span> <span>Registrar Postura</span>
      </button>
      <button class="menu-btn" onclick="switchTab('lotes', this)">
        <span>▣</span> <span>Historial Lotes</span>
      </button>
      <button class="menu-btn" onclick="switchTab('trazabilidad', this)">
        <span>🔀</span> <span>Trazabilidad</span>
      </button>
      <button class="menu-btn" onclick="switchTab('perfil', this)">
        <span>👤</span> <span>Mi Perfil</span>
      </button>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" style="text-decoration:none;">
        <button class="logout-btn">
          <span>⤶</span> Salir del Panel
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

      <button class="header-btn" onclick="switchTab('registrar', document.querySelectorAll('.sidebar-menu button')[2])">
        Registrar Postura
      </button>
    </header>

    <!-- PESTAÑA: DASHBOARD -->
    <section id="tab-dashboard" class="tab-pane active">
      
      <!-- Panel de Alertas Críticas (Requirement 3) -->
      <?php
      $lotesCriticos = [];
      $granjasBajoInsumo = [];
      if ($proveedor_id > 0) {
          // Lotes bajo stock
          $stmtCritLow = $conn->prepare("SELECT codigo_lote, cantidad, pr.nombre AS producto_nombre FROM inventario_huevos i INNER JOIN productos pr ON i.producto_id = pr.id WHERE i.proveedor_id = ? AND i.cantidad > 0 AND (i.estado = 'bajo_stock' OR i.cantidad < 100) ORDER BY i.cantidad ASC LIMIT 5");
          $stmtCritLow->bind_param("i", $proveedor_id);
          $stmtCritLow->execute();
          $resCritLow = $stmtCritLow->get_result();
          while ($row = $resCritLow->fetch_assoc()) {
              $row["motivo"] = "bajo_stock";
              $lotesCriticos[] = $row;
          }
          $stmtCritLow->close();

          // Lotes próximos a caducar
          $stmtCritExp = $conn->prepare("SELECT codigo_lote, cantidad, fecha_caducidad, pr.nombre AS producto_nombre FROM inventario_huevos i INNER JOIN productos pr ON i.producto_id = pr.id WHERE i.proveedor_id = ? AND i.cantidad > 0 AND i.fecha_caducidad >= ? AND i.fecha_caducidad <= ? ORDER BY i.fecha_caducidad ASC LIMIT 5");
          $stmtCritExp->bind_param("iss", $proveedor_id, $fechaHoy, $fechaLimite);
          $stmtCritExp->execute();
          $resCritExp = $stmtCritExp->get_result();
          while ($row = $resCritExp->fetch_assoc()) {
              $row["motivo"] = "cercano_caducidad";
              $lotesCriticos[] = $row;
          }
          $stmtCritExp->close();

          // Alertas de Insumos: bajo stock de cartones (< 30)
          $stmtIns = $conn->prepare("SELECT id, nombre, identificacion, stock_cartones FROM granjas WHERE proveedor_id = ? AND stock_cartones < 30 ORDER BY stock_cartones ASC");
          $stmtIns->bind_param("i", $proveedor_id);
          $stmtIns->execute();
          $resIns = $stmtIns->get_result();
          while ($row = $resIns->fetch_assoc()) {
              $granjasBajoInsumo[] = $row;
          }
          $stmtIns->close();
      }
      ?>
      <?php if (!empty($lotesCriticos) || !empty($granjasBajoInsumo)): ?>
        <div class="card" style="border-left: 5px solid var(--primary); background: rgba(255, 138, 0, 0.02); padding: 24px 28px; margin-bottom: 30px;">
          <h3 style="margin: 0 0 15px; display: flex; align-items: center; gap: 10px; color: var(--text-dark);">
            <span>⚠️</span> Alertas Críticas del Sistema (Lotes e Insumos)
          </h3>
          <div style="display: flex; flex-direction: column; gap: 12px;">
            <!-- Alertas de Insumos (Cartones) -->
            <?php foreach ($granjasBajoInsumo as $gbi): ?>
              <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; background: white; border-radius: 12px; border: 1px solid rgba(176, 37, 0, 0.25); box-shadow: 0 2px 8px rgba(0,0,0,0.01);">
                <div style="display: flex; align-items: center; gap: 10px;">
                  <span style="font-size: 16px;">📦</span>
                  <div>
                    <strong style="font-size: 14px; color: #b02500;">¡Bajo stock de cartones de empaque! Granja: <?php echo htmlspecialchars($gbi["nombre"]); ?></strong>
                    <span style="font-size: 12px; color: var(--text-medium); margin-left: 8px;">Código: <?php echo htmlspecialchars($gbi["identificacion"]); ?></span>
                  </div>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                  <span class="badge-status caducado" style="font-size: 11px; margin-right:5px;">
                    Stock: <?php echo $gbi["stock_cartones"]; ?> uds
                  </span>
                  <button onclick="openReplenishModal(<?php echo $gbi['id']; ?>, '<?php echo htmlspecialchars($gbi['nombre'], ENT_QUOTES); ?>')" style="background:var(--secondary); color:white; border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:800; cursor:pointer;">Abastecer ✚</button>
                </div>
              </div>
            <?php endforeach; ?>

            <!-- Alertas de Lotes -->
            <?php foreach ($lotesCriticos as $lc): ?>
              <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; background: white; border-radius: 12px; border: 1px solid rgba(213, 164, 112, 0.15); box-shadow: 0 2px 8px rgba(0,0,0,0.01);">
                <div style="display: flex; align-items: center; gap: 10px;">
                  <span style="font-size: 16px;"><?php echo $lc["motivo"] === "bajo_stock" ? "📉" : "⏳"; ?></span>
                  <div>
                    <strong style="font-size: 14px; color: var(--text-dark);"><?php echo htmlspecialchars($lc["codigo_lote"]); ?></strong>
                    <span style="font-size: 12px; color: var(--text-medium); margin-left: 8px;"><?php echo htmlspecialchars($lc["producto_nombre"]); ?></span>
                  </div>
                </div>
                <span class="badge-status <?php echo $lc["motivo"] === "bajo_stock" ? "bajo_stock" : "caducado"; ?>" style="font-size: 11px;">
                  <?php if ($lc["motivo"] === "bajo_stock"): ?>
                    Bajo Stock: <?php echo $lc["cantidad"]; ?> ud
                  <?php else: ?>
                    Expira en: <?php echo date("d M Y", strtotime($lc["fecha_caducidad"])); ?>
                  <?php endif; ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="metrics-grid">
        <div class="metric-card">
          <span class="label">Total Huevos Producidos</span>
          <span class="value"><?php echo number_format($huevosTotales); ?> ud</span>
        </div>
        <div class="metric-card">
          <span class="label">Total Lotes Generados</span>
          <span class="value"><?php echo $lotesTotales; ?> lotes</span>
        </div>
        <div class="metric-card warn">
          <span class="label">Lotes con Bajo Stock</span>
          <span class="value"><?php echo $lotesBajoStock; ?> lotes</span>
        </div>
        <div class="metric-card danger">
          <span class="label">Próximos a Caducar (≤ 7 días)</span>
          <span class="value"><?php echo $lotesProximosACaducar; ?> lotes</span>
        </div>
      </div>

      <div class="dashboard-layout">
        <!-- Granjas e Historial -->
        <div>
          <div class="card">
            <h3>Tus Granjas Activas</h3>
            <?php if (!empty($granjas)): ?>
              <div style="display: flex; flex-direction: column; gap: 14px;">
                <?php foreach (array_slice($granjas, 0, 3) as $g): ?>
                  <div class="farm-item" style="padding: 10px 0;">
                    <div class="farm-icon">🚜</div>
                    <div class="farm-details">
                      <h4><?php echo htmlspecialchars($g["nombre"]); ?></h4>
                      <p>Código: <strong><?php echo htmlspecialchars($g["identificacion"]); ?></strong> | Ubicación: <?php echo htmlspecialchars($g["ubicacion"]); ?></p>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if (count($granjas) > 3): ?>
                <p style="margin: 15px 0 0; font-size: 13px;"><a href="#" onclick="switchTab('granjas', document.querySelectorAll('.sidebar-menu button')[1])" style="color:var(--secondary); font-weight:800; text-decoration:none;">Ver las <?php echo count($granjas); ?> granjas ➔</a></p>
              <?php endif; ?>
            <?php else: ?>
              <div style="text-align: center; padding: 25px; color: var(--text-medium); border: 1px dashed var(--glass-border); border-radius: 16px;">
                <span style="font-size: 24px; display:block; margin-bottom: 8px;">🚜</span>
                No tienes granjas registradas.<br>
                <a href="#" onclick="switchTab('granjas', document.querySelectorAll('.sidebar-menu button')[1])" style="color: var(--secondary); font-weight: 800; text-decoration:none; margin-top:10px; display:inline-block;">¡Registra tu primera granja aquí!</a>
              </div>
            <?php endif; ?>
          </div>

          <div class="card">
            <h3>Historial de Producción Reciente</h3>
            <div class="table-responsive">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Producto</th>
                    <th>Granja Origen</th>
                    <th>Cantidad</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($producciones)): ?>
                    <?php foreach ($producciones as $prod): ?>
                      <tr>
                        <td><?php echo date("d M Y", strtotime($prod["fecha_produccion"])); ?></td>
                        <td><?php echo htmlspecialchars($prod["producto_nombre"]); ?></td>
                        <td><small>🚜</small> <strong><?php echo htmlspecialchars($prod["granja_nombre"] ?? "General"); ?></strong></td>
                        <td><strong style="color:var(--secondary);"><?php echo number_format($prod["amount"] ?? $prod["cantidad"]); ?> ud</strong></td>
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

        <!-- Trazabilidad rápida en Timeline -->
        <div>
          <div class="card">
            <h3>🔀 Línea de Tiempo de Lotes</h3>
            <div class="timeline">
              <?php if (!empty($lotesAlmacen)): ?>
                <?php foreach (array_slice($lotesAlmacen, 0, 4) as $l): ?>
                  <div class="timeline-item">
                    <small><?php echo date("d M Y", strtotime($l["fecha_postura"])); ?></small>
                    <h4>Lote <?php echo htmlspecialchars($l["codigo_lote"]); ?></h4>
                    <p>
                      <strong><?php echo number_format($l["cantidad"]); ?> ud</strong> de <?php echo htmlspecialchars($l["producto_nombre"]); ?><br>
                      Procedencia: 🚜 <strong><?php echo htmlspecialchars($l["granja_nombre"] ?? "General"); ?></strong>
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

    <!-- PESTAÑA: MIS GRANJAS (Requirement 1) -->
    <section id="tab-granjas" class="tab-pane">
      <div style="display:grid; grid-template-columns: 1fr 1.5fr; gap:30px; align-items: start;">
        
        <!-- Formulario para Registrar Granja -->
        <div class="card">
          <h3>Registrar Nueva Granja</h3>
          <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
            Da de alta una instalación avícola para asociar tus lotes cosechados y asegurar la trazabilidad.
          </p>

          <form id="farm-form" onsubmit="submitFarm(event)">
            <div style="display: flex; flex-direction: column; gap:16px;">
              <div class="form-group">
                <label>Nombre de la Granja</label>
                <input type="text" id="farm-nombre" placeholder="Ej: Granja Santa Ana" required>
              </div>

              <div class="form-group">
                <label>Identificación de Granja / Código de Registro</label>
                <input type="text" id="farm-identificacion" placeholder="Ej: ES-AN-41001" required>
              </div>

              <div class="form-group">
                <label>Ubicación Geográfica Completa</label>
                <input type="text" id="farm-ubicacion" placeholder="Ej: Sevilla, España" required>
              </div>

              <button type="submit" class="btn-submit" style="margin-top:10px;">Registrar Granja</button>
            </div>
          </form>
        </div>

        <!-- Listado de Granjas -->
        <div class="card" style="margin-bottom:0;">
          <h3>Granjas Registradas</h3>
          <div id="farms-list-container" class="farm-grid">
            <!-- Cargado vía AJAX/PHP -->
            <?php if (!empty($granjas)): ?>
              <?php foreach ($granjas as $g): ?>
                <div class="farm-card">
                  <span class="badge-id"><?php echo htmlspecialchars($g["identificacion"]); ?></span>
                  <div class="farm-icon">🚜</div>
                  <h4><?php echo htmlspecialchars($g["nombre"]); ?></h4>
                  <p>📍 <strong>Ubicación:</strong> <?php echo htmlspecialchars($g["ubicacion"]); ?></p>
                  <p>📅 <strong>Registrado:</strong> <?php echo date("d M Y", strtotime($g["creado_en"])); ?></p>
                  
                  <div style="margin-top:12px; padding:10px; background:rgba(213, 164, 112, 0.05); border-radius:10px; border:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div>
                      <small style="font-size:9px; font-weight:800; color:var(--text-medium); display:block; text-transform:uppercase;">Stock de Cartones</small>
                      <strong style="font-size:13px; color:<?php echo $g['stock_cartones'] < 30 ? '#b02500' : 'var(--secondary)'; ?>;"><?php echo $g['stock_cartones']; ?> uds</strong>
                    </div>
                    <button onclick="openReplenishModal(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars($g['nombre'], ENT_QUOTES); ?>')" style="background:var(--secondary); color:white; border:none; padding:6px 10px; border-radius:6px; font-size:10px; font-weight:800; cursor:pointer;">Cargar</button>
                  </div>
                  
                  <button class="btn-delete-farm" onclick="deleteFarm(<?php echo $g['id']; ?>)">Eliminar Granja</button>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-medium); border: 1px dashed var(--glass-border); border-radius: 16px;">
                <span style="font-size: 32px; display:block; margin-bottom: 12px;">🚜</span>
                No has registrado ninguna granja aún.
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </section>

    <!-- PESTAÑA: REGISTRAR PRODUCCIÓN (Requirement 2) -->
    <section id="tab-registrar" class="tab-pane">
      <div class="card form-box">
        <h3>Registrar Nueva Postura y Lote</h3>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
          Ingresa la postura recolectada del día. **Es obligatorio vincular una granja de origen** para garantizar la trazabilidad de los huevos. El lote se generará inmediatamente y estará disponible en el catálogo de clientes.
        </p>

        <?php if (!empty($granjas)): ?>
          <form id="production-form" onsubmit="submitProduction(event)">
            <div class="form-grid">
              
              <div class="form-group">
                <label>Granja de Origen</label>
                <select id="prod-granja-id" required>
                  <option value="">-- Selecciona la Granja de Origen --</option>
                  <?php foreach ($granjas as $g): ?>
                    <option value="<?php echo $g['id']; ?>">🚜 <?php echo htmlspecialchars($g['nombre'] . ' [' . $g['identificacion'] . ']'); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

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
                <label>Fecha de Postura</label>
                <input type="date" id="prod-fecha" value="<?php echo date('Y-m-d'); ?>" required>
              </div>

              <div class="form-group full-width">
                <label>Observaciones de Trazabilidad</label>
                <textarea id="prod-obs" placeholder="Ej: Huevos de excelente frescura, alimentación 100% ecológica en corral libre."></textarea>
              </div>

              <button type="submit" class="btn-submit">Registrar Postura y Generar Lote</button>
            </div>
          </form>
        <?php else: ?>
          <div style="text-align: center; padding: 40px; border: 1px dashed var(--glass-border); border-radius: 20px; background: rgba(255,138,0,0.01);">
            <span style="font-size: 40px; display:block; margin-bottom:15px;">🚜</span>
            <h4 style="margin: 0 0 10px; font-size:18px; color:var(--text-dark);">¡Falta Registrar Granjas!</h4>
            <p style="margin: 0 0 20px; font-size:14px; color:var(--text-medium); line-height:1.6;">
              Por políticas de trazabilidad del sistema EcoAli, es obligatorio que registres al menos una granja antes de poder dar de alta lotes de huevo en la plataforma.
            </p>
            <button onclick="switchTab('granjas', document.querySelectorAll('.sidebar-menu button')[1])" style="background:var(--secondary); color:white; border:none; padding:12px 30px; border-radius:12px; font-weight:800; cursor:pointer;">
              Registrar Granja Ahora
            </button>
          </div>
        <?php endif; ?>
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
                <th>Granja Origen</th>
                <th>Cantidad Stock</th>
                <th>Fecha Postura</th>
                <th>Fecha Caducidad</th>
                <th>Estado Lote</th>
                <th>Acciones</th>
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
                    <td>🚜 <strong><?php echo htmlspecialchars($l["granja_nombre"] ?? "General"); ?></strong></td>
                    <td><strong><?php echo number_format($l["cantidad"]); ?> ud</strong></td>
                    <td><?php echo date("d M Y", strtotime($l["fecha_postura"])); ?></td>
                    <td><?php echo date("d M Y", strtotime($l["fecha_caducidad"])); ?></td>
                    <td><span class="badge-status <?php echo $estClass; ?>"><?php echo $l["estado"]; ?></span></td>
                    <td>
                      <button onclick="openTrazabilidadDirecta('<?php echo htmlspecialchars($l['codigo_lote']); ?>')" style="background:var(--secondary); color:white; border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:800; cursor:pointer;">
                        Rastrear 🔀
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="8" style="text-align:center; color:var(--text-medium); padding:30px;">
                    No hay lotes ingresados al inventario de EcoAli.
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- PESTAÑA: TRAZABILIDAD INTERACTIVA (Requirement 4) -->
    <section id="tab-trazabilidad" class="tab-pane">
      <div class="card" style="max-width:750px; margin: 0 auto;">
        <h3>🔀 Centro de Trazabilidad e Historial</h3>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
          Rastrea de forma transparente cualquier lote de huevo producido. Selecciona un código de lote abajo para ver su cadena de custodia completa en tiempo real.
        </p>

        <div class="form-group" style="margin-bottom:30px;">
          <label>Código de Lote a Rastrear</label>
          <select id="trace-lote-select" onchange="loadTraceability(this.value)" style="height:56px; font-size:16px;">
            <option value="">-- Selecciona un Lote de la Lista --</option>
            <?php foreach ($lotesAlmacen as $l): ?>
              <option value="<?php echo htmlspecialchars($l['codigo_lote']); ?>">🥚 <?php echo htmlspecialchars($l['codigo_lote'] . ' (' . $l['producto_nombre'] . ')'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Timeline dynamically generated -->
        <div id="trace-result-container" style="display:none; border-top: 1px solid var(--glass-border); padding-top:30px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px;">
            <div>
              <h4 style="margin:0; font-size:18px; color:var(--text-dark);" id="trace-lote-code">LOTE-1234</h4>
              <p style="margin:4px 0 0; font-size:13px; color:var(--text-medium);" id="trace-lote-product">Tipo de Huevo: Eco-Organic</p>
            </div>
            <span class="badge-status disponible" id="trace-lote-status">Disponible</span>
          </div>

          <div class="stepper">
            
            <div class="step">
              <small>Fase 1: Postura y Cosecha</small>
              <h4 id="trace-step-farm">Granja de Origen</h4>
              <p id="trace-step-farm-desc">Detalles de la cosecha y fecha de recolección.</p>
            </div>

            <div class="step">
              <small>Fase 2: Clasificación y Calidad</small>
              <h4>Control de Trazabilidad</h4>
              <p id="trace-step-quality-desc">Clasificado por tamaño y tipo bajo estándares orgánicos de EcoAli.</p>
            </div>

            <div class="step">
              <small>Fase 3: Almacén e Inventario</small>
              <h4>Ingreso al Almacén EcoAli</h4>
              <p id="trace-step-stock-desc">Ingresado al stock listo para la venta y con fecha de expiración.</p>
            </div>

            <div class="step" id="trace-step-distribution-block">
              <small>Fase 4: Distribución al Cliente</small>
              <h4>Entrega y Venta Final</h4>
              <p id="trace-step-distribution-desc">Pendiente de compra por clientes de la plataforma.</p>
            </div>

          </div>
        </div>

        <div id="trace-empty-message" style="text-align: center; padding: 40px 20px; color: var(--text-medium);">
          <span style="font-size: 40px; display:block; margin-bottom:12px;">🔍</span>
          Selecciona un código de lote del menú desplegable para generar el rastreo completo.
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

  <!-- Mobile Nav Footer -->
  <nav class="mobile-nav">
    <button class="mobile-nav-btn active" onclick="switchTab('dashboard', this)">
      <span>▦</span>
      <span>Dashboard</span>
    </button>
    <button class="mobile-nav-btn" onclick="switchTab('granjas', this)">
      <span>🚜</span>
      <span>Granjas</span>
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
      <span>👤</span>
      <span>Perfil</span>
    </button>
  </nav>

</div>

<!-- Modal Universal: Éxito / Error -->
<div class="modal-overlay" id="alert-modal">
  <div class="modal-container">
    <div style="font-size: 60px; color: var(--secondary); margin-bottom: 20px;" id="alert-icon">✓</div>
    <h3 style="margin: 0 0 10px; font-size: 22px; font-weight: 800;" id="alert-title">¡Éxito!</h3>
    <p style="margin: 0 0 25px; font-size: 14px; color: var(--text-medium); line-height: 1.6;" id="alert-message">
      Operación completada con éxito.
    </p>
    <button onclick="closeAlertModal()" style="background: var(--text-dark); color: white; border: none; padding: 12px 30px; border-radius: 12px; font-weight: 800; cursor: pointer;">
      Entendido
    </button>
  </div>
</div>

<!-- Modal: Reabastecer Cartones (Requirement 7) -->
<div class="modal-overlay" id="replenish-modal">
  <div class="modal-container" style="max-width: 420px; text-align: left; padding: 30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
      <h3 style="margin:0; font-size:20px; font-weight:800; color:var(--text-dark);">Abastecer Insumos</h3>
      <button onclick="closeReplenishModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:var(--text-medium); line-height:1;">&times;</button>
    </div>
    <form id="replenish-form" onsubmit="submitReplenish(event)">
      <input type="hidden" id="rep-granja-id">
      <p style="font-size:13px; color:var(--text-medium); line-height:1.5; margin:0 0 20px;">
        Vas a registrar un reabastecimiento de cartones de empaque para la granja: <strong id="rep-granja-nombre" style="color:var(--text-dark);">Granja</strong>.
      </p>
      
      <div class="form-group" style="margin-bottom:20px; display:flex; flex-direction:column; gap:8px;">
        <label style="font-size:11px; font-weight:800; color:var(--text-medium);">CANTIDAD DE CARTONES A AGREGAR</label>
        <input type="number" id="rep-cantidad" value="120" min="1" max="1000" required style="height:48px; border-radius:12px; border:1px solid var(--glass-border); padding:0 12px; font-size:14px; font-weight:600; outline:none; box-sizing:border-box; width:100%;">
      </div>

      <button type="submit" class="btn-submit" style="width:100%; height:48px; margin-top:10px;">Confirmar Reabastecimiento</button>
    </form>
  </div>
</div>

<script>
  // Inyección de datos de lotes y trazabilidad
  var ecoaliLotesTrace = <?php echo json_encode($traceData); ?>;

  let activeReplenishFarmId = null;

  function openReplenishModal(id, nombre) {
      activeReplenishFarmId = id;
      document.getElementById('rep-granja-id').value = id;
      document.getElementById('rep-granja-nombre').textContent = nombre;
      document.getElementById('rep-cantidad').value = 120;
      document.getElementById('replenish-modal').classList.add('active');
  }

  function closeReplenishModal() {
      document.getElementById('replenish-modal').classList.remove('active');
      activeReplenishFarmId = null;
  }

  function submitReplenish(e) {
      e.preventDefault();
      const id = document.getElementById('rep-granja-id').value;
      const cant = document.getElementById('rep-cantidad').value;

      fetch('forms/granjas_acciones.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ accion: 'abastecer_cartones', id: id, cantidad: cant })
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              closeReplenishModal();
              showAlertModal('Reabastecido', data.message, '✓', 'var(--secondary)', true);
          } else {
              showAlertModal('Error', data.message, '✗', '#b02500');
          }
      })
      .catch(err => {
          console.error(err);
          showAlertModal('Error', 'No se pudo procesar el reabastecimiento.', '✗', '#b02500');
      });
  }

  function switchTab(tabName, element) {
      document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
      
      const targetPane = document.getElementById('tab-' + tabName);
      if (targetPane) {
          targetPane.classList.add('active');
      }

      document.querySelectorAll('.sidebar-menu button, .mobile-nav-btn').forEach(btn => btn.classList.remove('active'));
      
      // Activar el botón correspondiente en sidebar y nav móvil
      document.querySelectorAll('.sidebar-menu button, .mobile-nav-btn').forEach(btn => {
          if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(tabName)) {
              btn.classList.add('active');
          }
      });

      const titles = {
          'dashboard': { title: 'Panel de Control', subtitle: 'Gestión de lotes orgánicos y trazabilidad total.' },
          'granjas': { title: 'Mis Granjas', subtitle: 'Administra tus instalaciones y locales avícolas.' },
          'registrar': { title: 'Registrar Postura', subtitle: 'Ingresa la recolección del día para generar un lote.' },
          'lotes': { title: 'Lotes en Almacén', subtitle: 'Trazabilidad y control de stock avícola.' },
          'trazabilidad': { title: 'Trazabilidad de Lotes', subtitle: 'Rastreo y cadena de custodia del producto.' },
          'perfil': { title: 'Perfil de Proveedor', subtitle: 'Configura los datos de tu empresa avícola.' }
      };

      if (titles[tabName]) {
          document.getElementById('page-title').textContent = titles[tabName].title;
          document.getElementById('page-subtitle').textContent = titles[tabName].subtitle;
      }
  }

  // Registrar Granja (AJAX)
  function submitFarm(e) {
      e.preventDefault();

      const nom = document.getElementById('farm-nombre').value;
      const ide = document.getElementById('farm-identificacion').value;
      const ubi = document.getElementById('farm-ubicacion').value;

      const payload = {
          accion: 'registrar',
          nombre: nom,
          identificacion: ide,
          ubicacion: ubi
      };

      fetch('forms/granjas_acciones.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              showAlertModal(
                  '¡Granja Registrada!',
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
          showAlertModal('Error de Servidor', 'Ocurrió un error inesperado al registrar la granja.', '✗', '#b02500');
      });
  }

  // Eliminar Granja (AJAX)
  function deleteFarm(id) {
      if (!confirm('¿Estás seguro de que deseas eliminar esta granja? Todos los lotes vinculados se mantendrán pero perderán la referencia de origen.')) {
          return;
      }

      fetch('forms/granjas_acciones.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ accion: 'eliminar', id: id })
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              showAlertModal('Granja Eliminada', data.message, '✓', 'var(--secondary)', true);
          } else {
              showAlertModal('Error', data.message, '✗', '#b02500');
          }
      })
      .catch(err => {
          console.error(err);
          showAlertModal('Error', 'No se pudo eliminar la granja.', '✗', '#b02500');
      });
  }

  // Enviar Postura (AJAX)
  function submitProduction(e) {
      e.preventDefault();

      const granjaId = document.getElementById('prod-granja-id').value;
      const prodId = document.getElementById('prod-producto-id').value;
      const cant = document.getElementById('prod-cantidad').value;
      const fec = document.getElementById('prod-fecha').value;
      const obs = document.getElementById('prod-obs').value;

      const payload = {
          granja_id: granjaId,
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

  // Cargar Trazabilidad Interactiva en Stepper (Instantáneo sin lag)
  function loadTraceability(loteCode) {
      const resultContainer = document.getElementById('trace-result-container');
      const emptyMessage = document.getElementById('trace-empty-message');

      if (!loteCode || !ecoaliLotesTrace[loteCode]) {
          resultContainer.style.display = 'none';
          emptyMessage.style.display = 'block';
          return;
      }

      const lote = ecoaliLotesTrace[loteCode];
      emptyMessage.style.display = 'none';
      resultContainer.style.display = 'block';

      // 1. Encabezado
      document.getElementById('trace-lote-code').textContent = lote.codigo_lote;
      document.getElementById('trace-lote-product').textContent = `Producto: ${lote.producto_nombre} (${lote.tipo_huevo} - ${lote.tamano})`;
      
      const statusEl = document.getElementById('trace-lote-status');
      statusEl.className = `badge-status ${lote.estado}`;
      statusEl.textContent = lote.estado.replace('_', ' ');

      // 2. Paso 1: Granja
      const farmName = lote.granja_nombre || "General/No especificado";
      document.getElementById('trace-step-farm').textContent = `Origen: 🚜 ${farmName}`;
      document.getElementById('trace-step-farm-desc').textContent = `Recolección e identificación física en la granja con código ${lote.granja_identificacion || 'N/A'}, ubicada en ${lote.granja_ubicacion || 'General'}.`;

      // 3. Paso 2: Postura y Calidad
      document.getElementById('trace-step-quality-desc').textContent = `Huevo orgánico cosechado el día ${formatDateString(lote.fecha_postura)} bajo rigurosas pautas de alimentación y cría libre.`;

      // 4. Paso 3: Stock
      let stockMsg = `Ingreso exitoso al almacén distribuidor de EcoAli. Cantidad en lote: **${lote.cantidad} unidades**. `;
      if (lote.estado === 'caducado') {
          stockMsg += `⚠️ **LOTE CADUCADO**. Expiró el día ${formatDateString(lote.fecha_caducidad)}.`;
      } else {
          stockMsg += `Expira en ${lote.dias_restantes} días (${formatDateString(lote.fecha_caducidad)}).`;
      }
      document.getElementById('trace-step-stock-desc').innerHTML = stockMsg;

      // 5. Paso 4: Distribución
      const distBlock = document.getElementById('trace-step-distribution-block');
      const distDesc = document.getElementById('trace-step-distribution-desc');

      if (lote.cantidad === 0 || lote.estado === 'vendido') {
          distBlock.className = "step active";
          distDesc.textContent = `Lote completamente distribuido y vendido a consumidores finales a través de la tienda EcoAli. ¡Ciclo de frescura completado!`;
      } else {
          distBlock.className = "step";
          distDesc.textContent = `Lote disponible y visible en el catálogo de clientes. Pendiente de asignación logística de venta.`;
      }
  }

  // Ir directamente a Trazabilidad de Lote
  function openTrazabilidadDirecta(loteCode) {
      switchTab('trazabilidad', document.querySelectorAll('.sidebar-menu button')[4]);
      document.getElementById('trace-lote-select').value = loteCode;
      loadTraceability(loteCode);
  }

  function formatDateString(dateStr) {
      if (!dateStr) return '';
      const parts = dateStr.split('-');
      if (parts.length !== 3) return dateStr;
      const date = new Date(parts[0], parts[1] - 1, parts[2]);
      return date.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });
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