<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - PORTAL PREMIUM DEL CLIENTE
 * --------------------------------------------------------------------------------
 * Este panel permite a los consumidores finales explorar el catálogo de huevos orgánicos
 * con stock real sincronizado, agregar productos al carrito, realizar compras seguras
 * simulando pasarelas (Stripe, PayPal, Mercado Pago), gestionar direcciones de envío,
 * administrar su código de referidos y recibir alertas/notificaciones de sus pedidos.
 */

session_start();
require "forms/conexion.php";

// 1. CONTROL DE ACCESO
if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

if ((int)$_SESSION["rol_id"] !== 2) {
    header("Location: login.php");
    exit;
}

$cliente_id = $_SESSION["usuario_id"];
$nombre = $_SESSION["nombre"] ?? "Cliente";
$apellido = $_SESSION["apellido"] ?? "";
$email = $_SESSION["email"] ?? "";

// --- CONSULTAS DINÁMICAS ---

// 1. Obtener perfil completo
$stmtProfile = $conn->prepare("SELECT direccion, telefono FROM usuario_perfil WHERE usuario_id = ?");
$stmtProfile->bind_param("i", $cliente_id);
$stmtProfile->execute();
$resProfile = $stmtProfile->get_result();
$profile = $resProfile->fetch_assoc();
$direccion = $profile["direccion"] ?? "";
$telefono = $profile["telefono"] ?? "";

// 2. Obtener productos activos de la BD con stock disponible real-time (Requirement 1)
$productosRes = $conn->query("SELECT p.*, COALESCE(SUM(i.cantidad), 0) AS stock_total 
                             FROM productos p 
                             LEFT JOIN inventario_huevos i ON p.id = i.producto_id AND i.estado = 'disponible' AND i.cantidad > 0
                             WHERE p.activo = 1 
                             GROUP BY p.id 
                             ORDER BY p.id DESC");
$productos = [];
if ($productosRes) {
    while ($row = $productosRes->fetch_assoc()) {
        $productos[] = $row;
    }
}

// 3. Obtener Historial de Pedidos del Cliente con Detalles y Repartidor
$pedidosQuery = "SELECT p.id, p.total, p.estado, p.fecha_pedido, p.metodo_pago, p.pago_estado, r.nombre AS repartidor_nombre 
                 FROM pedidos p
                 LEFT JOIN usuario_perfil r ON p.repartidor_id = r.usuario_id
                 WHERE p.cliente_id = ?
                 ORDER BY p.id DESC";
$stmtPedidos = $conn->prepare($pedidosQuery);
$stmtPedidos->bind_param("i", $cliente_id);
$stmtPedidos->execute();
$resPedidos = $stmtPedidos->get_result();
$pedidos = [];
while ($row = $resPedidos->fetch_assoc()) {
    $pedido_id = $row["id"];
    
    // Obtener ítems del pedido
    $itemsQuery = "SELECT dp.cantidad, dp.precio_unitario, dp.subtotal, pr.nombre AS producto_nombre, pr.tipo_huevo, pr.tamano
                   FROM detalle_pedido dp
                   INNER JOIN productos pr ON dp.producto_id = pr.id
                   WHERE dp.pedido_id = ?";
    $stmtItems = $conn->prepare($itemsQuery);
    $stmtItems->bind_param("i", $pedido_id);
    $stmtItems->execute();
    $resItems = $stmtItems->get_result();
    
    $items = [];
    while ($itemRow = $resItems->fetch_assoc()) {
        $items[] = $itemRow;
    }
    
    $row["items"] = $items;
    $pedidos[] = $row;
}

// 4. Obtener estadísticas de regalías del Cliente
$stmtPen = $conn->prepare("SELECT SUM(monto) FROM regalias WHERE usuario_beneficiado_id = ? AND estado = 'pendiente'");
$stmtPen->bind_param("i", $cliente_id);
$stmtPen->execute();
$resPen = $stmtPen->get_result();
$comisionPendiente = (float)($resPen->fetch_row()[0] ?? 0.0);

$stmtPag = $conn->prepare("SELECT SUM(monto) FROM regalias WHERE usuario_beneficiado_id = ? AND (estado = 'pagado' OR estado = 'pagated')");
$stmtPag->bind_param("i", $cliente_id);
$stmtPag->execute();
$resPag = $stmtPag->get_result();
$comisionPagada = (float)($resPag->fetch_row()[0] ?? 0.0);

$comisionTotal = $comisionPendiente + $comisionPagada;

// Obtener listado de comisiones obtenidas por referidos
$regaliasQuery = "SELECT r.id, r.monto, r.estado, r.fecha, r.nivel, pr.nombre AS nombre_referido 
                  FROM regalias r
                  INNER JOIN usuario_perfil pr ON r.usuario_referido_id = pr.usuario_id
                  WHERE r.usuario_beneficiado_id = ?
                  ORDER BY r.id DESC";
$stmtRegList = $conn->prepare($regaliasQuery);
$stmtRegList->bind_param("i", $cliente_id);
$stmtRegList->execute();
$resRegList = $stmtRegList->get_result();
$listaRegalias = [];
while ($row = $resRegList->fetch_assoc()) {
    $listaRegalias[] = $row;
}

// Generar código de referido único (usuario en mayúsculas)
$codigoReferido = strtoupper($_SESSION["usuario"] ?? "INVITE");

// 5. CONSTRUCCIÓN DEL FEED DE NOTIFICACIONES (Requirement 5)
$notificaciones = [];
$notificaciones[] = [
    "id" => "welcome",
    "titulo" => "¡Bienvenido a EcoAli! 🌱",
    "mensaje" => "Disfruta de huevos 100% frescos y orgánicos directos de granjas locales auditadas.",
    "fecha" => "Hoy",
    "tipo" => "info"
];

if (!empty($listaRegalias)) {
    foreach (array_slice($listaRegalias, 0, 3) as $reg) {
        $notificaciones[] = [
            "id" => "reg_" . $reg["id"],
            "titulo" => "💸 Regalía Recibida",
            "mensaje" => "Has ganado $" . number_format($reg["monto"], 2) . " de comisión por la compra de tu referido " . htmlspecialchars($reg["nombre_referido"]) . ".",
            "fecha" => date("d M", strtotime($reg["fecha"])),
            "tipo" => "success"
        ];
    }
}

if (!empty($pedidos)) {
    foreach (array_slice($pedidos, 0, 5) as $ped) {
        $pedCode = "#PED-" . str_pad($ped["id"], 3, "0", STR_PAD_LEFT);
        $pagMetodo = strtoupper($ped["metodo_pago"] ?? "EFECTIVO");
        
        if ($ped["estado"] === 'pendiente') {
            $notificaciones[] = [
                "id" => "ped_pend_" . $ped["id"],
                "titulo" => "💳 Pago Aprobado - " . $pedCode,
                "mensaje" => "Tu pedido de $" . number_format($ped["total"], 2) . " ha sido confirmado y el pago vía " . $pagMetodo . " ha sido aprobado de manera segura.",
                "fecha" => date("d M", strtotime($ped["fecha_pedido"])),
                "tipo" => "success"
            ];
        } elseif ($ped["estado"] === 'preparado') {
            $notificaciones[] = [
                "id" => "ped_prep_" . $ped["id"],
                "titulo" => "📦 Pedido Listo - " . $pedCode,
                "mensaje" => "Tu pedido de huevos frescos ha sido clasificado y empacado. Esperando recolección del repartidor.",
                "fecha" => "Hace poco",
                "tipo" => "info"
            ];
        } elseif ($ped["estado"] === 'en_ruta') {
            $notificaciones[] = [
                "id" => "ped_ruta_" . $ped["id"],
                "titulo" => "🚚 Pedido en Camino - " . $pedCode,
                "mensaje" => "El repartidor " . htmlspecialchars($ped["repartidor_nombre"] ?? "de EcoAli") . " lleva tu pedido en camino a tu domicilio.",
                "fecha" => "Ahora",
                "tipo" => "warning"
            ];
        } elseif ($ped["estado"] === 'entregado') {
            $notificaciones[] = [
                "id" => "ped_ent_" . $ped["id"],
                "titulo" => "🎉 Entregado - " . $pedCode,
                "mensaje" => "El pedido de $" . number_format($ped["total"], 2) . " fue entregado con éxito. ¡Buen provecho!",
                "fecha" => date("d M", strtotime($ped["fecha_pedido"])),
                "tipo" => "success"
            ];
        } elseif ($ped["estado"] === 'cancelado') {
            $notificaciones[] = [
                "id" => "ped_canc_" . $ped["id"],
                "titulo" => "❌ Pedido Cancelado - " . $pedCode,
                "mensaje" => "El pedido ha sido cancelado con éxito y el stock reincorporado al almacén.",
                "fecha" => date("d M", strtotime($ped["fecha_pedido"])),
                "tipo" => "danger"
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal de Clientes - ECOALI</title>

  <link rel="stylesheet" href="assets/css/globals.css">
  <link rel="stylesheet" href="assets/css/cliente.css?v=<?php echo time(); ?>">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  
  <style>
    :root {
      --bg-organic: #fffaf7;
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

    .dashboard-container {
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
      background: rgba(255, 138, 0, 0.05);
      border-radius: 20px;
      padding: 16px;
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 30px;
      border: 1px solid rgba(255, 138, 0, 0.08);
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

    .order-filter-tag {
      background: white;
      border: 1px solid var(--glass-border);
      color: var(--text-medium);
      padding: 10px 20px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 13px;
      cursor: pointer;
      font-family: inherit;
      transition: var(--transition-fast);
    }
    .order-filter-tag:hover {
      background: rgba(255, 138, 0, 0.02);
      border-color: var(--primary);
    }
    .order-filter-tag.active {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
      box-shadow: 0 8px 16px rgba(255, 138, 0, 0.2);
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
      background: var(--primary);
      color: white;
      box-shadow: 0 8px 20px rgba(255, 138, 0, 0.2);
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

    /* Interactive Stepper in Checkout Modal */
    .checkout-step-container {
      display: none;
      animation: fadeIn 0.25s ease-out forwards;
    }
    .checkout-step-container.active {
      display: block;
    }

    /* Payment Method Selection Cards */
    .payment-methods-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin: 15px 0;
    }
    .pay-card-option {
      border: 1px solid var(--glass-border);
      border-radius: 12px;
      padding: 16px;
      text-align: center;
      cursor: pointer;
      background: white;
      transition: var(--transition-fast);
    }
    .pay-card-option:hover {
      border-color: var(--primary);
      background: rgba(255, 138, 0, 0.02);
    }
    .pay-card-option.active {
      border-color: var(--primary);
      background: rgba(255, 138, 0, 0.05);
      box-shadow: 0 4px 12px rgba(255, 138, 0, 0.15);
    }
    .pay-card-option div {
      font-size: 24px;
      margin-bottom: 6px;
    }
    .pay-card-option span {
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      color: var(--text-dark);
    }

    /* Glassmorphism Credit Card Flipping Preview (Stripe Option) */
    .flip-card {
      background-color: transparent;
      width: 100%;
      height: 180px;
      perspective: 1000px;
      margin: 20px 0;
    }
    .flip-card-inner {
      position: relative;
      width: 100%;
      height: 100%;
      text-align: center;
      transition: transform 0.6s;
      transform-style: preserve-3d;
    }
    .flip-card.flipped .flip-card-inner {
      transform: rotateY(180deg);
    }
    .flip-card-front, .flip-card-back {
      position: absolute;
      width: 100%;
      height: 100%;
      -webkit-backface-visibility: hidden;
      backface-visibility: hidden;
      border-radius: 16px;
      padding: 20px;
      box-sizing: border-box;
    }
    .flip-card-front {
      background: linear-gradient(135deg, #1d1d1d, #444);
      color: white;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      text-align: left;
    }
    .flip-card-back {
      background: linear-gradient(135deg, #444, #1d1d1d);
      color: white;
      transform: rotateY(180deg);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      text-align: left;
    }

    /* Notification Drawer & Overlay */
    .notification-drawer-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(0,0,0,0.5);
      backdrop-filter: blur(4px);
      z-index: 100;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s ease;
    }
    .notification-drawer-overlay.active {
      opacity: 1;
      pointer-events: all;
    }
    .notification-drawer {
      position: fixed;
      top: 0;
      right: -420px;
      width: 420px;
      height: 100vh;
      background: var(--bg-organic);
      border-left: 1px solid var(--glass-border);
      box-shadow: -15px 0 45px rgba(0,0,0,0.15);
      z-index: 101;
      display: flex;
      flex-direction: column;
      transition: right 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .notification-drawer.active {
      right: 0;
    }
    .notif-item {
      background: white;
      border-radius: 16px;
      padding: 16px;
      border: 1px solid var(--glass-border);
      border-left: 4px solid var(--secondary);
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .notif-item.success { border-left-color: var(--secondary); }
    .notif-item.warning { border-left-color: var(--primary); }
    .notif-item.danger { border-left-color: #b02500; }
    .notif-item.info { border-left-color: #1786ba; }

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
      max-width: 480px;
      padding: 40px 30px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.15);
      border: 1px solid var(--glass-border);
    }

    /* Tab pane styling */
    .tab-pane {
      display: none !important;
      animation: fadeIn 0.3s ease-out forwards;
    }
    .tab-pane.active {
      display: block !important;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Cart drawer & overlay active states */
    .cart-drawer.active {
      right: 0 !important;
    }
    .cart-drawer-overlay.active {
      opacity: 1 !important;
      pointer-events: all !important;
    }

    /* Notification drawer active states */
    .notification-drawer.active {
      right: 0 !important;
    }
    .notification-drawer-overlay.active {
      opacity: 1 !important;
      pointer-events: all !important;
    }

    /* Order details pane active state */
    .order-details-pane.active {
      display: block !important;
    }
  </style>
</head>
<body>

<div class="dashboard-container">

  <!-- Sidebar (Desktop) -->
  <aside class="sidebar">
    <div class="brand">🌱 ECOALI</div>

    <div class="profile-card">
      <div class="avatar">🛒</div>
      <div class="info">
        <h4><?php echo htmlspecialchars($nombre . " " . $apellido); ?></h4>
        <p>Cliente Premium</p>
      </div>
    </div>

    <nav class="sidebar-menu">
      <button class="menu-btn active" onclick="switchTab('catalogo', this)">
        <span>▦</span> <span>Catálogo</span>
      </button>
      <button class="menu-btn" onclick="switchTab('pedidos', this)">
        <span>▤</span> <span>Mis Pedidos</span>
      </button>
      <button class="menu-btn" onclick="switchTab('regalias', this)">
        <span>◈</span> <span>Mis Regalías</span>
      </button>
      <button class="menu-btn" onclick="switchTab('perfil', this)">
        <span>👤</span> <span>Mi Perfil</span>
      </button>
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
        <h1 id="page-title">Catálogo de Productos</h1>
        <p id="page-subtitle">Frescura directa del campo a tu mesa.</p>
      </div>

      <div style="display:flex; gap: 15px; align-items:center;">
        <!-- Botón de Notificaciones (Requirement 5) -->
        <div onclick="toggleNotifications(true)" style="position:relative; background:white; border:1px solid var(--glass-border); width:50px; height:50px; border-radius:16px; display:grid; place-items:center; font-size:20px; cursor:pointer; box-shadow:var(--shadow-premium); transition:var(--transition-fast);">
          🔔
          <span id="notif-counter" style="position:absolute; top:-6px; right:-6px; background:#b02500; color:white; font-size:10px; font-weight:800; min-width:20px; height:20px; padding:0 5px; border-radius:50%; display:none; place-items:center; border:2px solid var(--bg-organic); box-shadow:0 5px 10px rgba(176,37,0,0.3);"><?php echo count($notificaciones); ?></span>
        </div>

        <!-- Botón de Carrito -->
        <div class="cart-trigger" onclick="toggleCart(true)" style="position:relative; background:white; border:1px solid var(--glass-border); width:50px; height:50px; border-radius:16px; display:grid; place-items:center; font-size:20px; cursor:pointer; box-shadow:var(--shadow-premium); transition:var(--transition-fast);">
          🛒
          <span id="cart-counter" style="position:absolute; top:-6px; right:-6px; background:var(--primary); color:white; font-size:10px; font-weight:800; min-width:20px; height:20px; padding:0 5px; border-radius:50%; display:grid; place-items:center; border:2px solid var(--bg-organic); box-shadow:0 5px 10px rgba(255,138,0,0.3);">0</span>
        </div>
      </div>
    </header>

    <!-- PESTAÑA: CATÁLOGO -->
    <section id="tab-catalogo" class="tab-pane active">
      <div class="catalog-search-filters" style="display:grid; grid-template-columns: 1fr auto; gap:20px; margin-bottom:30px;">
        <div style="position:relative; background:white; border-radius:20px; box-shadow:var(--shadow-premium); border:1px solid var(--glass-border); display:flex; align-items:center; padding:0 20px;">
          <span style="font-size:20px; margin-right:10px; color:var(--primary);">⌕</span>
          <input type="text" id="catalog-search" placeholder="Buscar huevos orgánicos..." onkeyup="filterProducts()" style="border:none; outline:none; width:100%; height:56px; font-size:14px; font-weight:600; color:var(--text-dark);">
        </div>

        <div style="display:flex; gap:10px; align-items:center;">
          <button class="filter-tag active" onclick="filterTag('todos', this)" style="background:white; border:1px solid var(--glass-border); color:var(--text-medium); padding:10px 20px; border-radius:999px; font-weight:700; font-size:13px; cursor:pointer;">Todos</button>
          <button class="filter-tag" onclick="filterTag('orgánico', this)" style="background:white; border:1px solid var(--glass-border); color:var(--text-medium); padding:10px 20px; border-radius:999px; font-weight:700; font-size:13px; cursor:pointer;">Orgánico</button>
          <button class="filter-tag" onclick="filterTag('tradicional', this)" style="background:white; border:1px solid var(--glass-border); color:var(--text-medium); padding:10px 20px; border-radius:999px; font-weight:700; font-size:13px; cursor:pointer;">Tradicional</button>
        </div>
      </div>

      <div class="product-grid" id="products-container" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:30px;">
        <?php if (!empty($productos)): ?>
          <?php foreach ($productos as $p): 
            $bgImg = "https://images.unsplash.com/photo-1582722872445-44dc5f7e3c8f?auto=format&fit=crop&w=1000&q=80"; // Default
            $hName = strtolower($p["nombre"]);
            if (strpos($hName, "blanco") !== false) {
                $bgImg = "https://images.unsplash.com/photo-1518569656558-1f25e69d93d7?auto=format&fit=crop&w=1000&q=80";
            } elseif (strpos($hName, "pasto") !== false || strpos($hName, "ecoló") !== false) {
                $bgImg = "https://images.unsplash.com/photo-1598965675045-45c5e72c7d05?auto=format&fit=crop&w=1000&q=80";
            } elseif (strpos($hName, "rubia") !== false || strpos($hName, "medio") !== false) {
                $bgImg = "https://images.unsplash.com/photo-1569288063643-5d29ad2b4c2c?auto=format&fit=crop&w=1000&q=80";
            }
            $stockTotal = (int)$p["stock_total"];
          ?>
            <article class="product-card" data-nombre="<?php echo htmlspecialchars(strtolower($p['nombre'])); ?>" data-tipo="<?php echo htmlspecialchars(strtolower($p['tipo_huevo'])); ?>" style="background:white; border-radius:28px; overflow:hidden; box-shadow:var(--shadow-premium); border:1px solid var(--glass-border); display:flex; flex-direction:column; transition:var(--transition-fast);">
              <div style="height:180px; position:relative; background-size:cover; background-position:center; background-image:url('<?php echo $bgImg; ?>');">
                <?php if ($stockTotal > 0): ?>
                  <span style="position:absolute; top:16px; right:16px; background:var(--secondary); color:white; font-size:10px; font-weight:800; padding:6px 12px; border-radius:999px;">● STOCK DISPONIBLE</span>
                <?php else: ?>
                  <span style="position:absolute; top:16px; right:16px; background:#b02500; color:white; font-size:10px; font-weight:800; padding:6px 12px; border-radius:999px;">● AGOTADO</span>
                <?php endif; ?>
              </div>
              
              <div style="padding:22px; display:flex; flex-direction:column; flex-grow:1;">
                <h3 style="margin:0; font-size:18px; font-weight:800; color:var(--text-dark);"><?php echo htmlspecialchars($p["nombre"]); ?></h3>
                
                <div style="margin:8px 0 20px; font-size:11px; font-weight:800; color:var(--text-medium); text-transform:uppercase; display:flex; gap:8px;">
                  <span style="background:#ffe3ca; padding:3px 8px; border-radius:6px;">Tipo: <?php echo htmlspecialchars($p["tipo_huevo"]); ?></span>
                  <span style="background:#ffe3ca; padding:3px 8px; border-radius:6px;">Tam: <?php echo htmlspecialchars($p["tamano"]); ?></span>
                </div>
                
                <div style="margin-top:auto; display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <span style="font-size:24px; font-weight:800; color:var(--secondary);">$<?php echo number_format($p["precio"], 2); ?></span>
                    <small style="display:block; font-size:10px; color:var(--text-medium); font-weight:700;"><?php echo $stockTotal > 0 ? "Stock: $stockTotal ud" : "Agotado"; ?></small>
                  </div>

                  <?php if ($stockTotal > 0): ?>
                    <button onclick="addToCart(<?php echo $p['id']; ?>, '<?php echo addslashes($p['nombre']); ?>', <?php echo $p['precio']; ?>, <?php echo $stockTotal; ?>)" style="width:42px; height:42px; border-radius:14px; border:none; background:var(--primary); color:white; font-size:20px; font-weight:600; cursor:pointer; display:grid; place-items:center; box-shadow:0 8px 16px rgba(255, 138, 0, 0.2); transition:var(--transition-fast);">
                      +
                    </button>
                  <?php else: ?>
                    <button disabled style="width:42px; height:42px; border-radius:14px; border:none; background:#ccc; color:white; font-size:20px; font-weight:600; cursor:not-allowed; display:grid; place-items:center;">
                      +
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="grid-column:1/-1; text-align:center; padding:50px; color:var(--text-medium); font-weight:700;">
            No hay productos cargados en el catálogo actualmente.
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- PESTAÑA: MIS PEDIDOS -->
    <section id="tab-pedidos" class="tab-pane">
      <div class="order-search-filters" style="display:grid; grid-template-columns: 1fr auto; gap:20px; margin-bottom:30px;">
        <div style="position:relative; background:white; border-radius:20px; box-shadow:var(--shadow-premium); border:1px solid var(--glass-border); display:flex; align-items:center; padding:0 20px; flex-grow:1;">
          <span style="font-size:20px; margin-right:10px; color:var(--primary);">⌕</span>
          <input type="text" id="orders-search" placeholder="Buscar por ID, total, método de pago, fecha o estado..." onkeyup="filterOrders()" style="border:none; outline:none; width:100%; height:56px; font-size:14px; font-weight:600; color:var(--text-dark); background:transparent;">
        </div>

        <div style="display:flex; gap:10px; align-items:center;">
          <button class="order-filter-tag active" onclick="filterOrderStatus('todos', this)">Todos</button>
          <button class="order-filter-tag" onclick="filterOrderStatus('pendiente', this)">Pendientes</button>
          <button class="order-filter-tag" onclick="filterOrderStatus('cancelado', this)">Cancelados</button>
          <button class="order-filter-tag" onclick="filterOrderStatus('entregado', this)">Entregados</button>
        </div>
      </div>

      <div class="orders-container" style="display:flex; flex-direction:column; gap:20px;">
        <?php if (!empty($pedidos)): ?>
          <?php foreach ($pedidos as $ped): 
            $estado = strtolower($ped["estado"]);
          ?>
            <article class="order-card" id="order-card-<?php echo $ped['id']; ?>" 
                     data-id="#ped-<?php echo str_pad($ped["id"], 3, "0", STR_PAD_LEFT); ?>"
                     data-total="<?php echo number_format($ped["total"], 2); ?>"
                     data-pago="<?php echo strtolower($ped["metodo_pago"] ?? "efectivo"); ?>"
                     data-estado="<?php echo $estado; ?>"
                     data-fecha="<?php echo strtolower(date("d M Y, h:i A", strtotime($ped["fecha_pedido"]))); ?>"
                     style="background:white; border-radius:24px; border:1px solid var(--glass-border); box-shadow:var(--shadow-premium); overflow:hidden;">
              <div class="order-card-header" style="padding:24px; background:rgba(213, 164, 112, 0.03); border-bottom:1px solid var(--glass-border); display:grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; align-items:center; gap:15px;">
                <div>
                  <h4 style="margin:0; font-size:12px; color:var(--text-medium); text-transform:uppercase;">ID Pedido</h4>
                  <p style="margin:4px 0 0; font-size:14px; font-weight:800;">#PED-<?php echo str_pad($ped["id"], 3, "0", STR_PAD_LEFT); ?></p>
                </div>
                <div>
                  <h4 style="margin:0; font-size:12px; color:var(--text-medium); text-transform:uppercase;">Fecha</h4>
                  <p style="margin:4px 0 0; font-size:13px; font-weight:600;"><?php echo date("d M Y, h:i A", strtotime($ped["fecha_pedido"])); ?></p>
                </div>
                <div>
                  <span class="badge-status <?php echo $estado; ?>"><?php echo $ped["estado"]; ?></span>
                </div>
                <div>
                  <span class="order-total" style="font-size:18px; font-weight:800; color:var(--secondary);">$<?php echo number_format($ped["total"], 2); ?></span>
                  <small style="display:block; font-size:10px; color:var(--text-medium); font-weight:700;"><?php echo strtoupper($ped["metodo_pago"] ?? "EFECTIVO"); ?> - <?php echo strtoupper($ped["pago_estado"] ?? "PENDIENTE"); ?></small>
                </div>
                <div style="display:flex; gap:10px;">
                  <button class="order-btn" onclick="toggleOrderDetails(<?php echo $ped['id']; ?>)" style="background:white; border:1px solid var(--glass-border); color:var(--text-medium); padding:10px 18px; border-radius:12px; font-size:12px; font-weight:700; cursor:pointer;">Detalles</button>
                  <?php if ($estado === "pendiente"): ?>
                    <button class="order-btn cancel" id="cancel-btn-<?php echo $ped['id']; ?>" onclick="confirmCancelOrder(<?php echo $ped['id']; ?>)" style="border:1px solid rgba(176,37,0,0.2); background:rgba(176,37,0,0.03); color:#b02500; padding:10px 18px; border-radius:12px; font-size:12px; font-weight:700; cursor:pointer;">Cancelar</button>
                  <?php endif; ?>
                </div>
              </div>

              <div class="order-details-pane" id="order-details-<?php echo $ped['id']; ?>" style="padding:24px; background:#fafaf8; border-top:1px solid var(--glass-border); display:none;">
                <p style="margin:0 0 16px; font-size:13px; font-weight:700; color:var(--text-medium);">
                  📦 Repartidor Asignado: <strong><?php echo htmlspecialchars($ped["repartidor_nombre"] ?? "Buscando conductor..."); ?></strong>
                </p>
                <table style="width:100%; border-collapse:collapse; text-align:left;">
                  <thead>
                    <tr style="border-bottom:2px solid var(--glass-border);">
                      <th style="padding:10px; font-size:11px; color:var(--text-medium);">PRODUCTO</th>
                      <th style="padding:10px; font-size:11px; color:var(--text-medium);">CANTIDAD</th>
                      <th style="padding:10px; font-size:11px; color:var(--text-medium);">PRECIO</th>
                      <th style="padding:10px; font-size:11px; color:var(--text-medium);">SUBTOTAL</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($ped["items"] as $it): ?>
                      <tr style="border-bottom:1px solid rgba(213,164,112,0.05);">
                        <td style="padding:12px 10px; font-size:13px; font-weight:600;"><?php echo htmlspecialchars($it["producto_nombre"]); ?> <small>(<?php echo htmlspecialchars($it["tipo_huevo"] . ' - ' . $it["tamano"]); ?>)</small></td>
                        <td style="padding:12px 10px; font-size:13px; font-weight:600;"><?php echo $it["cantidad"]; ?> ud</td>
                        <td style="padding:12px 10px; font-size:13px; font-weight:600;">$<?php echo number_format($it["precio_unitario"], 2); ?></td>
                        <td style="padding:12px 10px; font-size:13px; font-weight:800; color:var(--secondary);">$<?php echo number_format($it["subtotal"], 2); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="text-align:center; padding:50px; background:white; border-radius:24px; border:1px solid var(--glass-border); color:var(--text-medium); font-weight:700;">
            Aún no has registrado ningún pedido en el sistema.
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- PESTAÑA: MIS REGALÍAS -->
    <section id="tab-regalias" class="tab-pane">
      <div class="referrals-metrics" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:24px; margin-bottom:35px;">
        <div style="background:white; border-radius:24px; padding:24px; border:1px solid var(--glass-border); box-shadow:var(--shadow-premium);">
          <span style="font-size:12px; font-weight:800; color:var(--text-medium); text-transform:uppercase;">Pendiente</span>
          <div style="font-size:32px; font-weight:800; color:var(--primary); margin-top:10px;">$<?php echo number_format($comisionPendiente, 2); ?></div>
        </div>
        <div style="background:white; border-radius:24px; padding:24px; border:1px solid var(--glass-border); box-shadow:var(--shadow-premium);">
          <span style="font-size:12px; font-weight:800; color:var(--text-medium); text-transform:uppercase;">Cobrado</span>
          <div style="font-size:32px; font-weight:800; color:var(--secondary); margin-top:10px;">$<?php echo number_format($comisionPagada, 2); ?></div>
        </div>
        <div style="background:linear-gradient(135deg, var(--secondary), #2ea33c); border-radius:24px; padding:24px; color:white; box-shadow:var(--shadow-premium);">
          <span style="font-size:12px; font-weight:800; color:rgba(255,255,255,0.85); text-transform:uppercase;">Total Acumulado</span>
          <div style="font-size:32px; font-weight:800; margin-top:10px;">$<?php echo number_format($comisionTotal, 2); ?></div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:30px;">
        <div style="background:white; border-radius:28px; padding:30px; border:1px solid var(--glass-border); box-shadow:var(--shadow-premium);">
          <h3 style="margin:0 0 15px; font-size:20px; font-weight:800; color:var(--text-dark);">¡Invita a tus amigos y gana!</h3>
          <p style="font-size:14px; color:var(--text-medium); line-height:1.6; margin-bottom:20px;">
            Comparte tu código exclusivo de EcoAli. Cuando tus amigos completen su registro e ingresen tu código al realizar sus pedidos de deliciosos huevos frescos, **tú recibirás automáticamente el 10% de comisión** en regalías de por vida en todas sus compras.
          </p>
          <div style="background:rgba(255, 138, 0, 0.05); border:2px dashed rgba(255,138,0,0.3); border-radius:20px; padding:20px; text-align:center;">
            <small style="font-size:10px; font-weight:800; color:var(--primary);">TU CÓDIGO DE INVITACIÓN</small>
            <strong style="font-family:'Plus Jakarta Sans', sans-serif; font-size:32px; display:block; margin:8px 0; font-weight:800; letter-spacing:2px;"><?php echo $codigoReferido; ?></strong>
            <button onclick="copyReferralCode('<?php echo $codigoReferido; ?>')" style="background:var(--primary); color:white; border:none; padding:10px 20px; border-radius:12px; font-size:13px; font-weight:800; cursor:pointer;">Copiar Código</button>
          </div>
        </div>

        <div style="background:white; border-radius:28px; padding:30px; border:1px solid var(--glass-border); box-shadow:var(--shadow-premium); display:flex; flex-direction:column;">
          <h3 style="margin:0 0 20px; font-size:20px; font-weight:800;">Comisiones Recientes</h3>
          <div style="flex-grow:1; overflow-y:auto; max-height: 290px;">
            <?php if (!empty($listaRegalias)): ?>
              <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead>
                  <tr style="border-bottom:1px solid var(--glass-border);">
                    <th style="padding:10px; font-size:11px; color:var(--text-medium);">REFERIDO</th>
                    <th style="padding:10px; font-size:11px; color:var(--text-medium);">COMISIÓN</th>
                    <th style="padding:10px; font-size:11px; color:var(--text-medium);">ESTADO</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($listaRegalias as $reg): ?>
                    <tr style="border-bottom:1px solid rgba(213,164,112,0.06);">
                      <td style="padding:12px 10px; font-size:13px; font-weight:600; color:var(--text-dark);"><?php echo htmlspecialchars($reg["nombre_referido"]); ?></td>
                      <td style="padding:12px 10px; font-size:13px; font-weight:800; color:var(--secondary);">$<?php echo number_format($reg["monto"], 2); ?></td>
                      <td style="padding:12px 10px;">
                        <span style="font-size:10px; font-weight:800; padding:4px 8px; border-radius:6px; background:<?php echo $reg['estado'] === 'pendiente' ? '#ffe3ca' : '#d2f3d1'; ?>; color:<?php echo $reg['estado'] === 'pendiente' ? 'var(--primary)' : 'var(--secondary)'; ?>;">
                          <?php echo strtoupper($reg['estado']); ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <p style="text-align:center; padding:40px 0; font-weight:600; color:var(--text-medium);">
                Aún no tienes comisiones de referidos generadas.
              </p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- PESTAÑA: MI PERFIL -->
    <section id="tab-perfil" class="tab-pane">
      <div style="background:white; border-radius:28px; border:1px solid var(--glass-border); box-shadow:var(--shadow-premium); padding:40px; max-width:800px; margin:0 auto;">
        <h2>Información de tu Perfil</h2>
        <p style="font-size:13px; color:var(--text-medium); margin-bottom:30px;">Actualiza los datos de envío y contacto para garantizar entregas perfectas.</p>

        <form id="profile-form" onsubmit="saveProfile(event)">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
            <div style="display:flex; flex-direction:column; gap:8px;">
              <label style="font-size:11px; font-weight:800; color:var(--text-medium);">NOMBRE</label>
              <input type="text" id="prof-nombre" value="<?php echo htmlspecialchars($nombre); ?>" required style="height:52px; border-radius:14px; border:1px solid var(--glass-border); padding:0 16px; font-weight:600;">
            </div>
            <div style="display:flex; flex-direction:column; gap:8px;">
              <label style="font-size:11px; font-weight:800; color:var(--text-medium);">APELLIDO</label>
              <input type="text" id="prof-apellido" value="<?php echo htmlspecialchars($apellido); ?>" required style="height:52px; border-radius:14px; border:1px solid var(--glass-border); padding:0 16px; font-weight:600;">
            </div>
            <div style="display:flex; flex-direction:column; gap:8px;">
              <label style="font-size:11px; font-weight:800; color:var(--text-medium);">CORREO ELECTRÓNICO</label>
              <input type="email" id="prof-email" value="<?php echo htmlspecialchars($email); ?>" required style="height:52px; border-radius:14px; border:1px solid var(--glass-border); padding:0 16px; font-weight:600;">
            </div>
            <div style="display:flex; flex-direction:column; gap:8px;">
              <label style="font-size:11px; font-weight:800; color:var(--text-medium);">TELÉFONO</label>
              <input type="text" id="prof-telefono" value="<?php echo htmlspecialchars($telefono); ?>" style="height:52px; border-radius:14px; border:1px solid var(--glass-border); padding:0 16px; font-weight:600;">
            </div>
            <div style="grid-column:1/-1; display:flex; flex-direction:column; gap:8px;">
              <label style="font-size:11px; font-weight:800; color:var(--text-medium);">DIRECCIÓN DE ENVÍO PRINCIPAL</label>
              <input type="text" id="prof-direccion" value="<?php echo htmlspecialchars($direccion); ?>" placeholder="Calle, número, apto, barrio..." style="height:52px; border-radius:14px; border:1px solid var(--glass-border); padding:0 16px; font-weight:600;">
            </div>

            <button type="submit" class="btn-submit" style="grid-column:1/-1; height:54px; border-radius:14px; border:none; background:linear-gradient(135deg, var(--secondary), #2ea33c); color:white; font-size:15px; font-weight:800; cursor:pointer;">
              Guardar Cambios de Perfil
            </button>
          </div>
        </form>
      </div>
    </section>

  </main>

</div>

<!-- Centro de Notificaciones Drawer (Requirement 5) -->
<div class="notification-drawer-overlay" id="notif-overlay" onclick="toggleNotifications(false)"></div>
<div class="notification-drawer" id="notif-drawer">
  <div style="padding:24px; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center; background:white;">
    <h3 style="margin:0; font-size:20px; font-weight:800;">Centro de Notificaciones</h3>
    <button onclick="toggleNotifications(false)" style="background:none; border:none; font-size:26px; cursor:pointer; color:var(--text-medium);">×</button>
  </div>
  <div style="flex-grow:1; overflow-y:auto; padding:24px; display:flex; flex-direction:column; gap:16px;" id="notif-items-container">
    <!-- Cargado vía JavaScript -->
  </div>
  <div style="padding:16px 24px; background:white; border-top:1px solid var(--glass-border); display:flex; justify-content:center;">
    <button onclick="clearAllNotifications()" style="width:100%; height:46px; border-radius:12px; border:1px solid #b02500; background:transparent; color:#b02500; font-size:13px; font-weight:800; cursor:pointer; transition:var(--transition-fast);" onmouseover="this.style.background='#b02500'; this.style.color='white';" onmouseout="this.style.background='transparent'; this.style.color='#b02500';">
      🗑 Borrar Todas las Notificaciones
    </button>
  </div>
</div>

<!-- Carrito Drawer -->
<div class="cart-drawer-overlay" id="cart-overlay" onclick="toggleCart(false)" style="position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:100; opacity:0; pointer-events:none; transition:opacity 0.3s ease;"></div>
<div class="cart-drawer" id="cart-drawer" style="position:fixed; top:0; right:-420px; width:420px; height:100vh; background:var(--bg-organic); border-left:1px solid var(--glass-border); box-shadow:-15px 0 45px rgba(0,0,0,0.15); z-index:101; display:flex; flex-direction:column; transition:right 0.35s cubic-bezier(0.4, 0, 0.2, 1);">
  <div style="padding:24px; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center; background:white;">
    <h3 style="margin:0; font-size:20px; font-weight:800;">Tu Carrito</h3>
    <button onclick="toggleCart(false)" style="background:none; border:none; font-size:26px; cursor:pointer; color:var(--text-medium);">×</button>
  </div>

  <div class="cart-items-list" id="cart-items-container" style="flex-grow:1; overflow-y:auto; padding:24px; display:flex; flex-direction:column; gap:16px;">
    <!-- Cargado por JS -->
  </div>

  <div style="padding:24px; background:white; border-top:1px solid var(--glass-border); display:flex; flex-direction:column; gap:16px;">
    <div style="display:flex; justify-content:space-between; font-size:14px; font-weight:700; color:var(--text-medium);">
      <span>Subtotal</span>
      <span id="cart-subtotal">$0.00</span>
    </div>
    <div style="display:flex; justify-content:space-between; font-size:14px; font-weight:700; color:var(--text-medium);">
      <span>Envío</span>
      <span style="color:var(--secondary); font-weight:800;">GRATIS</span>
    </div>
    <div style="display:flex; justify-content:space-between; font-size:20px; font-weight:800; color:var(--text-dark); border-top:1px solid rgba(213,164,112,0.12); padding-top:12px;">
      <span>Total</span>
      <span id="cart-total" style="color:var(--secondary);">$0.00</span>
    </div>

    <button onclick="openCheckoutWizard()" style="height:52px; border-radius:14px; border:none; background:var(--primary); color:white; font-size:15px; font-weight:800; cursor:pointer; box-shadow:0 10px 20px rgba(255,138,0,0.2);">
      Proceder al Checkout ➜
    </button>
  </div>
</div>

<!-- Modal: Checkout Multi-Step con Pasarelas Simuladas (Requirement 2 & 3) -->
<div class="modal-overlay" id="checkout-modal">
  <div class="modal-container" style="max-width:540px; padding:30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
      <div style="font-size:20px; font-weight:800;" id="checkout-modal-title">Checkout de Compra</div>
      <button onclick="closeCheckoutWizard()" style="background:none; border:none; font-size:24px; cursor:pointer;">×</button>
    </div>

    <!-- PASO 1: Envío -->
    <div class="checkout-step-container active" id="chk-step-1">
      <h4 style="margin:0 0 10px; color:var(--text-dark); font-size:15px;">Paso 1: Dirección y Teléfono de Despacho</h4>
      <p style="font-size:13px; color:var(--text-medium); line-height:1.5; margin-bottom:20px;">Confirma dónde quieres que el repartidor entregue tus huevos frescos.</p>
      
      <div style="display:flex; flex-direction:column; gap:16px;">
        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:11px; font-weight:800; color:var(--text-medium);">DIRECCIÓN DE ENTREGA COMPLETA</label>
          <input type="text" id="chk-direccion" value="<?php echo htmlspecialchars($direccion); ?>" required style="height:48px; border-radius:12px; border:1px solid var(--glass-border); padding:0 12px;" placeholder="Calle, apto, barrio o localidad">
        </div>
        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:11px; font-weight:800; color:var(--text-medium);">TELÉFONO DE CONTACTO</label>
          <input type="text" id="chk-telefono" value="<?php echo htmlspecialchars($telefono); ?>" required style="height:48px; border-radius:12px; border:1px solid var(--glass-border); padding:0 12px;">
        </div>
        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:11px; font-weight:800; color:var(--text-medium);">CÓDIGO DE REFERIDO (OPCIONAL)</label>
          <input type="text" id="chk-referido" style="height:48px; border-radius:12px; border:1px solid var(--glass-border); padding:0 12px; text-transform:uppercase;" placeholder="Ej: DIEGO">
        </div>

        <button onclick="goToCheckoutStep(2)" style="background:var(--primary); color:white; border:none; height:48px; border-radius:12px; font-weight:800; cursor:pointer; margin-top:10px;">
          Elegir Método de Pago ➜
        </button>
      </div>
    </div>

    <!-- PASO 2: Selección Pasarela -->
    <div class="checkout-step-container" id="chk-step-2">
      <h4 style="margin:0 0 10px; color:var(--text-dark); font-size:15px;">Paso 2: Selecciona la Pasarela de Pago</h4>
      <p style="font-size:13px; color:var(--text-medium); line-height:1.5; margin-bottom:20px;">Todas las transacciones se realizan de forma segura y encriptada.</p>
      
      <div class="payment-methods-grid">
        <div class="pay-card-option active" id="pay-opt-stripe" onclick="selectPaymentMethod('stripe')">
          <div>💳</div>
          <span>Stripe</span>
        </div>
        <div class="pay-card-option" id="pay-opt-paypal" onclick="selectPaymentMethod('paypal')">
          <div>🅿️</div>
          <span>PayPal</span>
        </div>
        <div class="pay-card-option" id="pay-opt-mercado" onclick="selectPaymentMethod('mercado_pago')">
          <div>🟩</div>
          <span>Mercado Pago</span>
        </div>
      </div>

      <div style="display:flex; justify-content:space-between; margin-top:25px;">
        <button onclick="goToCheckoutStep(1)" style="background:rgba(213,164,112,0.1); color:var(--text-medium); border:none; height:48px; border-radius:12px; font-weight:800; padding:0 24px; cursor:pointer;">Atrás</button>
        <button onclick="goToCheckoutStep(3)" style="background:var(--primary); color:white; border:none; height:48px; border-radius:12px; font-weight:800; padding:0 24px; cursor:pointer;">Continuar ➜</button>
      </div>
    </div>

    <!-- PASO 3: Pasarela Interactiva / Simulación -->
    <div class="checkout-step-container" id="chk-step-3">
      
      <!-- SUB-PANE: STRIPE -->
      <div id="pane-pay-stripe" style="display:block;">
        <h4 style="margin:0 0 10px; color:var(--text-dark); font-size:15px;">Pagar con Stripe 💳</h4>
        
        <!-- Flipped Credit Card Preview -->
        <div class="flip-card" id="preview-credit-card">
          <div class="flip-card-inner">
            <div class="flip-card-front">
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:24px;">🪙</span>
                <strong style="font-size:14px; opacity:0.8; letter-spacing:1.5px;">STRIPE SECURE</strong>
              </div>
              <div style="font-size:20px; font-family:'Plus Jakarta Sans', monospace; letter-spacing:2px;" id="cc-num-view">•••• •••• •••• ••••</div>
              <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                <div>
                  <small style="font-size:9px; opacity:0.6; display:block;">CARDHOLDER</small>
                  <span style="font-size:13px; font-weight:800; text-transform:uppercase;" id="cc-name-view">NOMBRE APELLIDO</span>
                </div>
                <div>
                  <small style="font-size:9px; opacity:0.6; display:block;">EXPIRES</small>
                  <span style="font-size:13px; font-weight:800;" id="cc-exp-view">MM/YY</span>
                </div>
              </div>
            </div>
            <div class="flip-card-back">
              <div style="width:100%; height:35px; background:black; margin-top:10px; margin-left:-20px; margin-right:-20px;"></div>
              <div style="display:flex; justify-content:flex-end; align-items:center; margin-top:20px; background:#fff; padding:6px; border-radius:4px; color:#1d1d1d;">
                <small style="font-size:9px; color:#777; margin-right:10px;">SECURE CVV</small>
                <strong style="font-size:13px;" id="cc-cvv-view">•••</strong>
              </div>
              <div style="font-size:10px; text-align:center; opacity:0.5; margin-top:20px;">EcoAli Stripe Integrated Sandbox</div>
            </div>
          </div>
        </div>

        <form id="stripe-sim-form" onsubmit="submitCheckoutSimulated(event)">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div style="grid-column:1/-1; display:flex; flex-direction:column; gap:4px;">
              <label style="font-size:10px; font-weight:800; color:var(--text-medium);">NÚMERO DE TARJETA</label>
              <input type="text" id="cc-number" placeholder="4000 1234 5678 9010" required maxlength="19" onkeyup="updateCCFront()" style="height:44px; border-radius:10px; border:1px solid var(--glass-border); padding:0 12px;">
            </div>
            <div style="grid-column:1/-1; display:flex; flex-direction:column; gap:4px;">
              <label style="font-size:10px; font-weight:800; color:var(--text-medium);">NOMBRE EN TARJETA</label>
              <input type="text" id="cc-name" placeholder="Ej: JORGE LUIS" required onkeyup="updateCCFront()" style="height:44px; border-radius:10px; border:1px solid var(--glass-border); padding:0 12px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
              <label style="font-size:10px; font-weight:800; color:var(--text-medium);">EXPIRACIÓN</label>
              <input type="text" id="cc-exp" placeholder="MM/YY" required maxlength="5" onkeyup="updateCCFront()" style="height:44px; border-radius:10px; border:1px solid var(--glass-border); padding:0 12px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
              <label style="font-size:10px; font-weight:800; color:var(--text-medium);">CVV</label>
              <input type="text" id="cc-cvv" placeholder="123" required maxlength="3" onfocus="flipCC(true)" onblur="flipCC(false)" onkeyup="updateCCFront()" style="height:44px; border-radius:10px; border:1px solid var(--glass-border); padding:0 12px;">
            </div>
          </div>

          <div style="display:flex; justify-content:space-between; margin-top:25px;">
            <button type="button" onclick="goToCheckoutStep(2)" style="background:rgba(213,164,112,0.1); color:var(--text-medium); border:none; height:48px; border-radius:12px; font-weight:800; padding:0 24px; cursor:pointer;">Atrás</button>
            <button type="submit" id="btn-pay-submit" style="background:var(--secondary); color:white; border:none; height:48px; border-radius:12px; font-weight:800; padding:0 30px; cursor:pointer; display:flex; align-items:center; gap:8px;">
              Pagar de Forma Segura ➜
            </button>
          </div>
        </form>
      </div>

      <!-- SUB-PANE: PAYPAL -->
      <div id="pane-pay-paypal" style="display:none;">
        <h4 style="margin:0 0 10px; color:var(--text-dark); font-size:15px;">Pagar con PayPal 🅿️</h4>
        <p style="font-size:13px; color:var(--text-medium); line-height:1.6; margin-bottom:20px;">
          Haz clic en el botón de abajo para iniciar la pasarela simulada segura de PayPal. Serás redirigido a una simulación de inicio de sesión rápida para autorizar el cargo.
        </p>

        <div style="text-align:center; padding:30px 20px; background:#eff7ff; border-radius:20px; border:1px dashed #1786ba; margin:20px 0;">
          <div style="font-size:32px; margin-bottom:10px;">🅿️</div>
          <strong style="color:#1786ba; display:block; font-size:16px;">PayPal Sandbox Integration</strong>
          <span style="font-size:12px; color:var(--text-medium);">Total a pagar: <strong id="paypal-amount">$0.00</strong></span>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:25px;">
          <button onclick="goToCheckoutStep(2)" style="background:rgba(213,164,112,0.1); color:var(--text-medium); border:none; height:48px; border-radius:12px; font-weight:800; padding:0 24px; cursor:pointer;">Atrás</button>
          <button onclick="simulateThirdPartyPayment('paypal')" style="background:#ffc439; color:#012169; border:none; height:48px; border-radius:12px; font-weight:800; padding:0 30px; cursor:pointer; display:flex; align-items:center; gap:8px;">
            Pagar con PayPal 🅿️
          </button>
        </div>
      </div>

      <!-- SUB-PANE: MERCADO PAGO -->
      <div id="pane-pay-mercado_pago" style="display:none;">
        <h4 style="margin:0 0 10px; color:var(--text-dark); font-size:15px;">Pagar con Mercado Pago 🟩</h4>
        <p style="font-size:13px; color:var(--text-medium); line-height:1.6; margin-bottom:20px;">
          Mercado Pago te permite pagar de forma inmediata mediante PSE, Tarjetas locales o tu cuenta registrada. Haz clic abajo para simular tu pago seguro.
        </p>

        <div style="text-align:center; padding:30px 20px; background:#f0fff4; border-radius:20px; border:1px dashed #2ea33c; margin:20px 0;">
          <div style="font-size:32px; margin-bottom:10px;">🟩</div>
          <strong style="color:#2ea33c; display:block; font-size:16px;">Mercado Pago Checkout Pro</strong>
          <span style="font-size:12px; color:var(--text-medium);">Total a pagar: <strong id="mercado-amount">$0.00</strong></span>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:25px;">
          <button onclick="goToCheckoutStep(2)" style="background:rgba(213,164,112,0.1); color:var(--text-medium); border:none; height:48px; border-radius:12px; font-weight:800; padding:0 24px; cursor:pointer;">Atrás</button>
          <button onclick="simulateThirdPartyPayment('mercado_pago')" style="background:#00b1ea; color:white; border:none; height:48px; border-radius:12px; font-weight:800; padding:0 30px; cursor:pointer; display:flex; align-items:center; gap:8px;">
            Pagar con Mercado Pago 🟩
          </button>
        </div>
      </div>

    </div>

  </div>
</div>

<!-- Modal Universal de Alertas -->
<div class="modal-overlay" id="alert-modal">
  <div class="modal-container" style="max-width:400px; text-align:center; padding:40px 30px;">
    <div style="font-size:60px; color:var(--secondary); margin-bottom:20px;" id="alert-icon">✓</div>
    <h3 style="margin:0 0 10px; font-size:22px; font-weight:800;" id="alert-title">¡Éxito!</h3>
    <p style="margin:0 0 25px; font-size:14px; color:var(--text-medium); line-height:1.6;" id="alert-message">Operación exitosa.</p>
    <button onclick="closeAlertModal()" style="background:var(--text-dark); color:white; border:none; padding:12px 30px; border-radius:12px; font-weight:800; cursor:pointer;">Entendido</button>
  </div>
</div>

<!-- Modal de Confirmación de Cancelación Premium -->
<div class="modal-overlay" id="confirm-cancel-modal">
  <div class="modal-container" style="max-width:400px; text-align:center; padding:40px 30px;">
    <div style="font-size:60px; color:#b02500; margin-bottom:20px;">⚠</div>
    <h3 style="margin:0 0 10px; font-size:22px; font-weight:800; color:#b02500;">¿Cancelar Pedido?</h3>
    <p style="margin:0 0 25px; font-size:14px; color:var(--text-medium); line-height:1.6;">
      ¿Estás seguro de que deseas cancelar este pedido? Se liberará el stock de inmediato y se procesará la devolución.
    </p>
    <div style="display:flex; gap:12px; justify-content:center;">
      <button onclick="closeConfirmCancelModal()" style="background:rgba(70,40,0,0.06); color:#7a5427; border:none; padding:12px 24px; border-radius:12px; font-weight:800; cursor:pointer; font-family:inherit;">Atrás</button>
      <button onclick="executeCancelOrder()" style="background:#b02500; color:white; border:none; padding:12px 24px; border-radius:12px; font-weight:800; cursor:pointer; font-family:inherit; box-shadow:0 8px 20px rgba(176,37,0,0.15);">Sí, Cancelar</button>
    </div>
  </div>
</div>

<script>
  // Inyección de Notificaciones desde la base de datos
  var ecoaliNotifications = <?php echo json_encode($notificaciones); ?>;
  var ecoaliCart = JSON.parse(localStorage.getItem('ecoali_cart_' + <?php echo $cliente_id; ?>)) || [];
  var selectedPayment = 'stripe';

  // Gestión de estado local de notificaciones (leídas y borradas)
  var readNotifIds = JSON.parse(localStorage.getItem('ecoali_read_notifs_' + <?php echo $cliente_id; ?>)) || [];
  var deletedNotifIds = JSON.parse(localStorage.getItem('ecoali_deleted_notifs_' + <?php echo $cliente_id; ?>)) || [];

  document.addEventListener('DOMContentLoaded', () => {
      updateCartUI();
      renderNotifications();
      updateNotifBadge();
  });

  // Switch de Pestañas
  function switchTab(tabName, element) {
      document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
      const pane = document.getElementById('tab-' + tabName);
      if (pane) pane.classList.add('active');

      document.querySelectorAll('.sidebar-menu button, .mobile-nav-btn').forEach(btn => btn.classList.remove('active'));
      document.querySelectorAll('.sidebar-menu button, .mobile-nav-btn').forEach(btn => {
          if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(tabName)) {
              btn.classList.add('active');
          }
      });

      const titles = {
          'catalogo': { title: 'Catálogo de Productos', subtitle: 'Frescura directa del campo a tu mesa.' },
          'pedidos': { title: 'Tus Pedidos', subtitle: 'Monitorea el estado y el historial de tus entregas.' },
          'regalias': { title: 'Regalías y Referidos', subtitle: 'Gana comisiones invitando amigos a la red EcoAli.' },
          'perfil': { title: 'Tu Perfil', subtitle: 'Actualiza tus datos de contacto y facturación.' }
      };

      if (titles[tabName]) {
          document.getElementById('page-title').textContent = titles[tabName].title;
          document.getElementById('page-subtitle').textContent = titles[tabName].subtitle;
      }
  }

  // Búsqueda en tiempo real
  function filterProducts() {
      const q = document.getElementById('catalog-search').value.toLowerCase();
      document.querySelectorAll('.product-card').forEach(card => {
          const nom = card.dataset.nombre;
          card.style.display = nom.includes(q) ? 'flex' : 'none';
      });
  }

  // Filtrado por Tags
  function filterTag(tag, btn) {
      document.querySelectorAll('.filter-tag').forEach(t => t.classList.remove('active'));
      btn.classList.add('active');

      document.querySelectorAll('.product-card').forEach(card => {
          const tipo = card.dataset.tipo;
          card.style.display = (tag === 'todos' || tipo.includes(tag)) ? 'flex' : 'none';
      });
  }

  // Filtrado y Búsqueda en tiempo real de Pedidos
  let activeOrderStatusFilter = 'todos';

  function filterOrderStatus(status, btn) {
      document.querySelectorAll('.order-filter-tag').forEach(t => t.classList.remove('active'));
      btn.classList.add('active');
      activeOrderStatusFilter = status;
      filterOrders();
  }

  function filterOrders() {
      const query = document.getElementById('orders-search').value.toLowerCase().trim();
      const cards = document.querySelectorAll('.order-card');
      let visibleCount = 0;

      cards.forEach(card => {
          const id = card.getAttribute('data-id').toLowerCase();
          const total = card.getAttribute('data-total').toLowerCase();
          const pago = card.getAttribute('data-pago').toLowerCase();
          const estado = card.getAttribute('data-estado').toLowerCase();
          const fecha = card.getAttribute('data-fecha').toLowerCase();

          const matchesStatus = (activeOrderStatusFilter === 'todos' || estado === activeOrderStatusFilter);
          
          const matchesQuery = !query || 
              id.includes(query) || 
              total.includes(query) || 
              pago.includes(query) || 
              estado.includes(query) || 
              fecha.includes(query);

          if (matchesStatus && matchesQuery) {
              card.style.display = 'block';
              visibleCount++;
          } else {
              card.style.display = 'none';
          }
      });

      let noResultsMsg = document.getElementById('no-orders-results');
      if (visibleCount === 0) {
          if (!noResultsMsg) {
              const msg = document.createElement('div');
              msg.id = 'no-orders-results';
              msg.style.textAlign = 'center';
              msg.style.padding = '50px';
              msg.style.background = 'white';
              msg.style.borderRadius = '24px';
              msg.style.border = '1px solid var(--glass-border)';
              msg.style.color = 'var(--text-medium)';
              msg.style.fontWeight = '700';
              msg.textContent = 'No se encontraron pedidos que coincidan con tu búsqueda.';
              document.querySelector('.orders-container').appendChild(msg);
          }
      } else {
          if (noResultsMsg) {
              noResultsMsg.remove();
          }
      }
  }

  // Gestión de Notificaciones
  function toggleNotifications(open) {
      const drawer = document.getElementById('notif-drawer');
      const overlay = document.getElementById('notif-overlay');
      if (open) {
          drawer.classList.add('active');
          overlay.classList.add('active');
          
          // Al abrir las notificaciones se marcan todas las no borradas como leídas
          const activeNotifs = ecoaliNotifications.filter(n => !deletedNotifIds.includes(n.id));
          activeNotifs.forEach(n => {
              if (!readNotifIds.includes(n.id)) {
                  readNotifIds.push(n.id);
              }
          });
          localStorage.setItem('ecoali_read_notifs_' + <?php echo $cliente_id; ?>, JSON.stringify(readNotifIds));
          updateNotifBadge();
      } else {
          drawer.classList.remove('active');
          overlay.classList.remove('active');
      }
  }

  function getUnreadNotifications() {
      // Filtrar notificaciones que no estén borradas ni leídas
      const activeNotifs = ecoaliNotifications.filter(n => !deletedNotifIds.includes(n.id));
      return activeNotifs.filter(n => !readNotifIds.includes(n.id));
  }

  function updateNotifBadge() {
      const unread = getUnreadNotifications();
      const badge = document.getElementById('notif-counter');
      if (badge) {
          if (unread.length > 0) {
              badge.textContent = unread.length;
              badge.style.display = 'grid';
          } else {
              badge.style.display = 'none';
          }
      }
  }

  function renderNotifications() {
      const container = document.getElementById('notif-items-container');
      container.innerHTML = '';
      
      const activeNotifs = ecoaliNotifications.filter(n => !deletedNotifIds.includes(n.id));
      
      if (activeNotifs.length === 0) {
          container.innerHTML = `
            <div style="text-align:center; padding:50px 10px; color:var(--text-medium); font-weight:700;">
              <span style="font-size:36px; display:block; margin-bottom:12px;">🔔</span>
              No tienes notificaciones actualmente.
            </div>
          `;
          return;
      }

      activeNotifs.forEach(notif => {
          container.innerHTML += `
            <div class="notif-item ${notif.tipo || 'info'}">
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <strong style="font-size:13px; color:var(--text-dark);">${notif.titulo}</strong>
                <small style="font-size:9px; color:var(--text-medium); font-weight:800;">${notif.fecha}</small>
              </div>
              <p style="margin:0; font-size:12px; color:var(--text-medium); line-height:1.5;">${notif.mensaje}</p>
            </div>
          `;
      });
  }

  function clearAllNotifications() {
      if (!confirm('¿Estás seguro de que deseas borrar todas las notificaciones?')) return;
      
      ecoaliNotifications.forEach(n => {
          if (!deletedNotifIds.includes(n.id)) {
              deletedNotifIds.push(n.id);
          }
          if (!readNotifIds.includes(n.id)) {
              readNotifIds.push(n.id);
          }
      });
      localStorage.setItem('ecoali_deleted_notifs_' + <?php echo $cliente_id; ?>, JSON.stringify(deletedNotifIds));
      localStorage.setItem('ecoali_read_notifs_' + <?php echo $cliente_id; ?>, JSON.stringify(readNotifIds));
      
      renderNotifications();
      updateNotifBadge();
  }

  // Gestión del Carrito
  function toggleCart(open) {
      const drawer = document.getElementById('cart-drawer');
      const overlay = document.getElementById('cart-overlay');
      if (open) {
          drawer.classList.add('active');
          overlay.classList.add('active');
      } else {
          drawer.classList.remove('active');
          overlay.classList.remove('active');
      }
  }

  function addToCart(id, nombre, precio, maxStock) {
      const existing = ecoaliCart.find(it => it.producto_id === id);
      if (existing) {
          if (existing.cantidad < maxStock) {
              existing.cantidad += 1;
          } else {
              alert('Límite de stock disponible alcanzado para este producto.');
              return;
          }
      } else {
          ecoaliCart.push({ producto_id: id, nombre: nombre, precio: parseFloat(precio), cantidad: 1, maxStock: maxStock });
      }
      saveCart();
      updateCartUI();
      toggleCart(true);
  }

  function updateQty(id, change) {
      const item = ecoaliCart.find(it => it.producto_id === id);
      if (item) {
          item.cantidad += change;
          if (item.cantidad > item.maxStock) {
              item.cantidad = item.maxStock;
              alert('Límite de stock disponible alcanzado.');
          }
          if (item.cantidad <= 0) {
              ecoaliCart = ecoaliCart.filter(it => it.producto_id !== id);
          }
          saveCart();
          updateCartUI();
      }
  }

  function removeFromCart(id) {
      ecoaliCart = ecoaliCart.filter(it => it.producto_id !== id);
      saveCart();
      updateCartUI();
  }

  function saveCart() {
      localStorage.setItem('ecoali_cart_' + <?php echo $cliente_id; ?>, JSON.stringify(ecoaliCart));
  }

  function updateCartUI() {
      const container = document.getElementById('cart-items-container');
      const counter = document.getElementById('cart-counter');
      const subtotalEl = document.getElementById('cart-subtotal');
      const totalEl = document.getElementById('cart-total');

      container.innerHTML = '';
      let totalQty = 0;
      let subtotal = 0.0;

      if (ecoaliCart.length === 0) {
          container.innerHTML = `
            <div style="text-align:center; padding:40px 0; color:var(--text-medium); font-weight:700;">
              Tu carrito está vacío. ¡Agrega deliciosos huevos frescos del catálogo!
            </div>
          `;
      } else {
          ecoaliCart.forEach(item => {
              totalQty += item.cantidad;
              const sub = item.precio * item.cantidad;
              subtotal += sub;

              container.innerHTML += `
                <div class="cart-item">
                  <div class="cart-item-info">
                    <h4>${item.nombre}</h4>
                    <p>$${item.precio.toFixed(2)} x ${item.cantidad}</p>
                  </div>
                  <div class="cart-item-qty">
                    <button onclick="updateQty(${item.producto_id}, -1)">-</button>
                    <span>${item.cantidad}</span>
                    <button onclick="updateQty(${item.producto_id}, 1)">+</button>
                  </div>
                  <button class="cart-item-remove" onclick="removeFromCart(${item.producto_id})">🗑</button>
                </div>
              `;
          });
      }

      counter.textContent = totalQty;
      subtotalEl.textContent = '$' + subtotal.toFixed(2);
      totalEl.textContent = '$' + subtotal.toFixed(2);
  }

  // checkout Wizard
  function openCheckoutWizard() {
      if (ecoaliCart.length === 0) {
          showAlertModal('Carrito Vacío', 'Agrega algún producto al carrito antes de comprar.', '✗', '#b02500');
          return;
      }
      
      const total = document.getElementById('cart-total').textContent;
      document.getElementById('paypal-amount').textContent = total;
      document.getElementById('mercado-amount').textContent = total;

      document.getElementById('checkout-modal').classList.add('active');
      goToCheckoutStep(1);
  }

  function closeCheckoutWizard() {
      document.getElementById('checkout-modal').classList.remove('active');
  }

  function goToCheckoutStep(step) {
      document.querySelectorAll('.checkout-step-container').forEach(el => el.classList.remove('active'));
      document.getElementById('chk-step-' + step).classList.add('active');

      const titles = {
          1: 'Detalles de Despacho',
          2: 'Método de Pago',
          3: 'Confirmación e Ingreso de Datos'
      };
      document.getElementById('checkout-modal-title').textContent = titles[step];
  }

  function selectPaymentMethod(method) {
      selectedPayment = method;
      document.querySelectorAll('.pay-card-option').forEach(el => el.classList.remove('active'));
      
      const idMap = {
          'stripe': 'pay-opt-stripe',
          'paypal': 'pay-opt-paypal',
          'mercado_pago': 'pay-opt-mercado'
      };
      document.getElementById(idMap[method]).classList.add('active');

      // Mostrar/Ocultar páneles correspondientes del paso 3
      document.getElementById('pane-pay-stripe').style.display = method === 'stripe' ? 'block' : 'none';
      document.getElementById('pane-pay-paypal').style.display = method === 'paypal' ? 'block' : 'none';
      document.getElementById('pane-pay-mercado_pago').style.display = method === 'mercado_pago' ? 'block' : 'none';
  }

  // Stripe Flip Card Logic
  function flipCC(back) {
      const card = document.getElementById('preview-credit-card');
      if (back) {
          card.classList.add('flipped');
      } else {
          card.classList.remove('flipped');
      }
  }

  function updateCCFront() {
      const num = document.getElementById('cc-number').value || '•••• •••• •••• ••••';
      const name = document.getElementById('cc-name').value || 'NOMBRE APELLIDO';
      const exp = document.getElementById('cc-exp').value || 'MM/YY';
      const cvv = document.getElementById('cc-cvv').value || '•••';

      document.getElementById('cc-num-view').textContent = formatCCNumber(num);
      document.getElementById('cc-name-view').textContent = name.toUpperCase();
      document.getElementById('cc-exp-view').textContent = exp;
      document.getElementById('cc-cvv-view').textContent = cvv;
  }

  function formatCCNumber(value) {
      const v = value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
      const matches = v.match(/\d{4,16}/g);
      const match = matches && matches[0] || '';
      const parts = [];

      for (let i=0, len=match.length; i<len; i+=4) {
          parts.push(match.substring(i, i+4));
      }

      if (parts.length > 0) {
          return parts.join(' ');
      } else {
          return value;
      }
  }

  // Simular envío final (Checkout)
  function submitCheckoutSimulated(e) {
      e.preventDefault();
      
      const btn = document.getElementById('btn-pay-submit');
      const oldTxt = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = `<span style="display:inline-block; animation:spin 1s linear infinite; margin-right:8px;">⏳</span> Procesando Pago Stripe...`;

      setTimeout(() => {
          executeFinalPurchase('stripe', 'aprobado');
      }, 1500);
  }

  function simulateThirdPartyPayment(platform) {
      const label = platform === 'paypal' ? 'PayPal Secure Login...' : 'Mercado Pago Checkout...';
      const confirmMsg = platform === 'paypal' 
          ? '¿Simular ingreso de sesión y autorizar pago seguro con PayPal?' 
          : '¿Simular y autorizar débito PSE / Mercado Pago?';

      if (!confirm(confirmMsg)) return;

      showAlertModal('Procesando', `Conectando con la pasarela de ${platform}...`, '⏳', 'var(--primary)');
      
      setTimeout(() => {
          closeAlertModal();
          executeFinalPurchase(platform, 'aprobado');
      }, 1500);
  }

  function executeFinalPurchase(metodo, estado) {
      const dir = document.getElementById('chk-direccion').value;
      const tel = document.getElementById('chk-telefono').value;
      const ref = document.getElementById('chk-referido').value;

      const payload = {
          direccion: dir,
          telefono: tel,
          referido_por: ref,
          metodo_pago: metodo,
          pago_estado: estado,
          carrito: ecoaliCart
      };

      fetch('forms/procesar_pedido.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              ecoaliCart = [];
              saveCart();
              updateCartUI();
              closeCheckoutWizard();
              
              showAlertModal(
                  '¡Compra Exitosa!',
                  `Tu orden #PED-${String(data.pedido_id).padStart(3, '0')} por un total de $${parseFloat(data.total).toFixed(2)} se pagó con éxito vía ${metodo.toUpperCase()}. Pronto la despacharemos a tu dirección.`,
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
          showAlertModal('Error', 'No se pudo procesar tu transacción de forma segura.', '✗', '#b02500');
      });
  }

  // Lógica del Accordion de Pedidos
  function toggleOrderDetails(id) {
      const pane = document.getElementById('order-details-' + id);
      pane.classList.toggle('active');
  }

  // Cancelación segura de pedido (Premium Custom Modal Dialog)
  let activeCancelOrderId = null;

  function confirmCancelOrder(id) {
      activeCancelOrderId = id;
      document.getElementById('confirm-cancel-modal').classList.add('active');
  }

  function closeConfirmCancelModal() {
      document.getElementById('confirm-cancel-modal').classList.remove('active');
      activeCancelOrderId = null;
  }

  function executeCancelOrder() {
      if (!activeCancelOrderId) return;
      const id = activeCancelOrderId;
      closeConfirmCancelModal();

      fetch('forms/cancelar_pedido.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ pedido_id: id })
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              showAlertModal('Pedido Cancelado', data.message, '✓', 'var(--secondary)', true);
          } else {
              showAlertModal('Error', data.message, '✗', '#b02500');
          }
      })
      .catch(err => {
          console.error(err);
          showAlertModal('Error', 'No se pudo cancelar el pedido.', '✗', '#b02500');
      });
  }

  // Guardar Perfil del Cliente
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

      fetch('forms/actualizar_perfil_cliente.php', {
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
          showAlertModal('Error', 'Ocurrió un error al guardar los cambios.', '✗', '#b02500');
      });
  }

  function copyReferralCode(code) {
      navigator.clipboard.writeText(code).then(() => {
          alert('¡Código de referido copiado al portapapeles!');
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

<!-- Barra de Navegación Inferior Móvil (Requirement 1 & 2) -->
<nav class="mobile-nav" style="display:none; position:fixed; bottom:0; left:0; width:100%; height:50px; background:rgba(255, 255, 255, 0.98); box-shadow:0 -5px 20px rgba(70, 40, 0, 0.05); border-top:1px solid rgba(213, 164, 112, 0.15); z-index:999999; grid-template-columns:repeat(4, 1fr); align-items:center;">
  <button class="mobile-nav-btn active" onclick="switchTab('catalogo', this)" style="background:none; border:none; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; color:var(--text-medium); font-size:12px; font-weight:800; cursor:pointer;">
    <span style="font-size:15px; transition:transform 0.2s ease;">▦</span>
    <span>Catálogo</span>
  </button>
  <button class="mobile-nav-btn" onclick="switchTab('pedidos', this)" style="background:none; border:none; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; color:var(--text-medium); font-size:12px; font-weight:800; cursor:pointer;">
    <span style="font-size:15px; transition:transform 0.2s ease;">▤</span>
    <span>Pedidos</span>
  </button>
  <button class="mobile-nav-btn" onclick="switchTab('regalias', this)" style="background:none; border:none; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; color:var(--text-medium); font-size:12px; font-weight:800; cursor:pointer;">
    <span style="font-size:15px; transition:transform 0.2s ease;">◈</span>
    <span>Regalías</span>
  </button>
  <button class="mobile-nav-btn" onclick="switchTab('perfil', this)" style="background:none; border:none; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; color:var(--text-medium); font-size:12px; font-weight:800; cursor:pointer;">
    <span style="font-size:15px; transition:transform 0.2s ease;">👤</span>
    <span>Perfil</span>
  </button>
</nav>

<style>
  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
</style>

</body>
</html>