<?php
session_start();
require "forms/conexion.php";

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

// 2. Obtener productos activos de la BD
$productosRes = $conn->query("SELECT * FROM productos WHERE activo = 1 ORDER BY id DESC");
$productos = [];
if ($productosRes) {
    while ($row = $productosRes->fetch_assoc()) {
        $productos[] = $row;
    }
}

// 3. Obtener Historial de Pedidos del Cliente con Detalles
$pedidosQuery = "SELECT p.id, p.total, p.estado, p.fecha_pedido, r.nombre AS repartidor_nombre 
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

$stmtPag = $conn->prepare("SELECT SUM(monto) FROM regalias WHERE usuario_beneficiado_id = ? AND estado = 'pagated' OR (usuario_beneficiado_id = ? AND estado = 'pagado')");
$stmtPag->bind_param("ii", $cliente_id, $cliente_id);
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
$codigoReferido = strtoupper($_SESSION["usuario"]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel de Cliente - ECOALI</title>

  <link rel="stylesheet" href="assets/css/globals.css">
  <link rel="stylesheet" href="assets/css/cliente.css?v=<?php echo time(); ?>">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <style>
    /* Estilos Premium Adicionales Integrados */
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

    .dashboard-container {
      display: flex;
      min-height: 100vh;
      position: relative;
    }

    /* Menú Lateral */
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

    /* Contenedor Principal */
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

    .cart-trigger {
      position: relative;
      background: white;
      border: 1px solid var(--glass-border);
      width: 50px;
      height: 50px;
      border-radius: 16px;
      display: grid;
      place-items: center;
      font-size: 20px;
      cursor: pointer;
      box-shadow: var(--shadow-premium);
      transition: var(--transition-fast);
    }

    .cart-trigger:hover {
      transform: scale(1.05);
      border-color: var(--primary);
    }

    .cart-trigger span {
      position: absolute;
      top: -6px;
      right: -6px;
      background: var(--primary);
      color: white;
      font-size: 10px;
      font-weight: 800;
      min-width: 20px;
      height: 20px;
      padding: 0 5px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      border: 2px solid var(--bg-organic);
      box-shadow: 0 5px 10px rgba(255, 138, 0, 0.3);
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

    /* --- PESTAÑA: CATÁLOGO --- */
    .catalog-search-filters {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 20px;
      margin-bottom: 30px;
    }

    .search-input-wrapper {
      position: relative;
      background: white;
      border-radius: 20px;
      box-shadow: var(--shadow-premium);
      border: 1px solid var(--glass-border);
      display: flex;
      align-items: center;
      padding: 0 20px;
    }

    .search-input-wrapper span {
      color: var(--primary);
      font-size: 20px;
      margin-right: 14px;
    }

    .search-input-wrapper input {
      border: none;
      outline: none;
      width: 100%;
      height: 56px;
      font-size: 14px;
      font-weight: 600;
      color: var(--text-dark);
    }

    .search-input-wrapper input::placeholder {
      color: #c79b6d;
    }

    .filter-tags {
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .filter-tag {
      background: white;
      border: 1px solid var(--glass-border);
      color: var(--text-medium);
      padding: 10px 20px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 13px;
      cursor: pointer;
      box-shadow: 0 5px 15px rgba(70,40,0,0.02);
      transition: var(--transition-fast);
    }

    .filter-tag:hover {
      background: rgba(213, 164, 112, 0.1);
      color: var(--text-dark);
    }

    .filter-tag.active {
      background: var(--secondary);
      border-color: var(--secondary);
      color: white;
      box-shadow: 0 8px 16px rgba(23, 106, 33, 0.2);
    }

    .product-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 30px;
    }

    .product-card {
      background: white;
      border-radius: 28px;
      overflow: hidden;
      box-shadow: var(--shadow-premium);
      border: 1px solid var(--glass-border);
      transition: var(--transition-fast);
      display: flex;
      flex-direction: column;
    }

    .product-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 25px 50px rgba(70, 40, 0, 0.12);
    }

    .product-card .img-container {
      height: 180px;
      position: relative;
      background-size: cover;
      background-position: center;
      background-color: #ffdcb5;
    }

    .product-card .badge-stock {
      position: absolute;
      top: 16px;
      right: 16px;
      background: var(--secondary);
      color: white;
      font-size: 10px;
      font-weight: 800;
      padding: 6px 12px;
      border-radius: 999px;
      letter-spacing: 0.5px;
      box-shadow: 0 5px 10px rgba(23, 106, 33, 0.25);
    }

    .product-card .card-content {
      padding: 22px;
      display: flex;
      flex-direction: column;
      flex-grow: 1;
    }

    .product-card h3 {
      margin: 0;
      font-size: 18px;
      font-weight: 800;
      color: var(--text-dark);
      line-height: 1.3;
    }

    .product-card .specs {
      margin: 8px 0 20px;
      font-size: 11px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: flex;
      gap: 8px;
    }

    .product-card .specs span {
      background: #ffe3ca;
      padding: 3px 8px;
      border-radius: 6px;
    }

    .product-card .bottom-row {
      margin-top: auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .product-card .price {
      font-size: 24px;
      font-weight: 800;
      color: var(--secondary);
    }

    .product-card .add-btn {
      width: 42px;
      height: 42px;
      border-radius: 14px;
      border: none;
      background: var(--primary);
      color: white;
      font-size: 20px;
      font-weight: 600;
      cursor: pointer;
      display: grid;
      place-items: center;
      box-shadow: 0 8px 16px rgba(255, 138, 0, 0.25);
      transition: var(--transition-fast);
    }

    .product-card .add-btn:hover {
      background: var(--primary-hover);
      transform: scale(1.05);
    }

    /* --- PESTAÑA: PEDIDOS --- */
    .orders-container {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .order-card {
      background: white;
      border-radius: 24px;
      border: 1px solid var(--glass-border);
      box-shadow: var(--shadow-premium);
      overflow: hidden;
    }

    .order-card-header {
      padding: 24px;
      background: rgba(213, 164, 112, 0.05);
      border-bottom: 1px solid var(--glass-border);
      display: grid;
      grid-template-columns: 1.5fr 1.5fr 1fr 1fr auto;
      align-items: center;
      gap: 15px;
    }

    .order-card-header h4 {
      margin: 0;
      font-size: 16px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .order-card-header p {
      margin: 2px 0 0;
      font-size: 12px;
      color: var(--text-medium);
      font-weight: 600;
    }

    .badge-status {
      padding: 8px 16px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      text-align: center;
      width: fit-content;
    }

    .badge-status.pendiente { background: rgba(255, 138, 0, 0.12); color: var(--primary); border: 1px solid rgba(255, 138, 0, 0.2); }
    .badge-status.preparado { background: rgba(23, 134, 186, 0.12); color: #1786ba; border: 1px solid rgba(23, 134, 186, 0.2); }
    .badge-status.en_ruta { background: rgba(141, 74, 0, 0.12); color: #8d4a00; border: 1px solid rgba(141, 74, 0, 0.2); }
    .badge-status.entregado { background: rgba(23, 106, 33, 0.12); color: var(--secondary); border: 1px solid rgba(23, 106, 33, 0.2); }
    .badge-status.cancelado { background: rgba(176, 37, 0, 0.12); color: #b02500; border: 1px solid rgba(176, 37, 0, 0.2); }

    .order-total {
      font-size: 18px;
      font-weight: 800;
      color: var(--secondary);
    }

    .order-actions {
      display: flex;
      gap: 12px;
    }

    .order-btn {
      background: white;
      border: 1px solid var(--glass-border);
      color: var(--text-medium);
      padding: 10px 18px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      transition: var(--transition-fast);
    }

    .order-btn:hover {
      background: rgba(213, 164, 112, 0.08);
      color: var(--text-dark);
    }

    .order-btn.cancel {
      border-color: rgba(176, 37, 0, 0.25);
      background: rgba(176, 37, 0, 0.04);
      color: #b02500;
    }

    .order-btn.cancel:hover {
      background: #b02500;
      color: white;
      box-shadow: 0 5px 12px rgba(176, 37, 0, 0.2);
    }

    .order-details-pane {
      padding: 24px;
      background: #fafaf8;
      border-top: 1px solid var(--glass-border);
      display: none;
    }

    .order-details-pane.active {
      display: block;
    }

    .order-details-table {
      width: 100%;
      border-collapse: collapse;
    }

    .order-details-table th {
      text-align: left;
      padding: 10px 16px;
      font-size: 11px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      border-bottom: 2px solid var(--glass-border);
    }

    .order-details-table td {
      padding: 14px 16px;
      font-size: 13px;
      font-weight: 600;
      color: var(--text-dark);
      border-bottom: 1px solid rgba(213, 164, 112, 0.08);
    }

    /* --- PESTAÑA: REGALÍAS --- */
    .referrals-metrics {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 24px;
      margin-bottom: 35px;
    }

    .ref-card {
      background: white;
      border-radius: 24px;
      padding: 24px;
      border: 1px solid var(--glass-border);
      box-shadow: var(--shadow-premium);
      display: flex;
      flex-direction: column;
    }

    .ref-card .label {
      font-size: 12px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .ref-card .value {
      font-size: 32px;
      font-weight: 800;
      color: var(--text-dark);
      margin-top: 10px;
    }

    .ref-card.highlight {
      background: linear-gradient(135deg, var(--secondary), #2ea33c);
      color: white;
      border: none;
    }

    .ref-card.highlight .label { color: rgba(255,255,255,0.75); }
    .ref-card.highlight .value { color: white; }

    .referrals-layout {
      display: grid;
      grid-template-columns: 1.2fr 1fr;
      gap: 30px;
    }

    .referral-box {
      background: white;
      border-radius: 28px;
      padding: 30px;
      border: 1px solid var(--glass-border);
      box-shadow: var(--shadow-premium);
    }

    .referral-box h3 {
      margin: 0 0 20px;
      font-size: 20px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .referral-box p {
      font-size: 14px;
      line-height: 1.6;
      color: var(--text-medium);
      margin-bottom: 24px;
    }

    .referral-code-wrapper {
      background: rgba(255, 138, 0, 0.06);
      border: 2px dashed rgba(255, 138, 0, 0.35);
      border-radius: 20px;
      padding: 24px;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
    }

    .referral-code-wrapper small {
      font-size: 11px;
      font-weight: 800;
      color: var(--primary);
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .referral-code-wrapper strong {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 34px;
      font-weight: 800;
      color: var(--text-dark);
      letter-spacing: 2px;
    }

    .copy-code-btn {
      background: var(--primary);
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 12px;
      font-size: 13px;
      font-weight: 800;
      cursor: pointer;
      transition: var(--transition-fast);
      box-shadow: 0 8px 16px rgba(255, 138, 0, 0.2);
    }

    .copy-code-btn:hover {
      background: var(--primary-hover);
      transform: translateY(-1px);
    }

    /* --- PESTAÑA: PERFIL --- */
    .profile-card-large {
      background: white;
      border-radius: 28px;
      border: 1px solid var(--glass-border);
      box-shadow: var(--shadow-premium);
      padding: 40px;
      max-width: 800px;
      margin: 0 auto;
    }

    .profile-card-large h2 {
      margin: 0 0 8px;
      font-size: 24px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .profile-card-large p {
      margin: 0 0 30px;
      font-size: 14px;
      color: var(--text-medium);
    }

    .profile-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px 24px;
    }

    .profile-form-grid .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .profile-form-grid .form-group.full-width {
      grid-column: 1 / -1;
    }

    .profile-form-grid label {
      font-size: 12px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      margin-left: 8px;
    }

    .profile-form-grid input {
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
      box-shadow: 0 8px 20px rgba(70, 40, 0, 0.02);
    }

    .profile-form-grid input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(255, 138, 0, 0.1);
    }

    .profile-save-btn {
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

    .profile-save-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 16px 28px rgba(23, 106, 33, 0.32);
    }

    /* --- CARRITO LATERAL (DRAWER) --- */
    .cart-drawer-overlay {
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

    .cart-drawer-overlay.active {
      opacity: 1;
      pointer-events: all;
    }

    .cart-drawer {
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

    .cart-drawer.active {
      right: 0;
    }

    .cart-drawer-header {
      padding: 24px;
      border-bottom: 1px solid var(--glass-border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: white;
    }

    .cart-drawer-header h3 {
      margin: 0;
      font-size: 20px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .close-drawer-btn {
      background: none;
      border: none;
      font-size: 26px;
      cursor: pointer;
      color: var(--text-medium);
      transition: var(--transition-fast);
    }

    .close-drawer-btn:hover {
      color: var(--primary);
    }

    .cart-items-list {
      flex-grow: 1;
      overflow-y: auto;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .cart-item {
      background: white;
      border-radius: 16px;
      padding: 16px;
      border: 1px solid var(--glass-border);
      display: flex;
      gap: 14px;
      align-items: center;
    }

    .cart-item-info {
      flex-grow: 1;
    }

    .cart-item-info h4 {
      margin: 0;
      font-size: 14px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .cart-item-info p {
      margin: 4px 0 0;
      font-size: 12px;
      color: var(--secondary);
      font-weight: 700;
    }

    .cart-item-qty {
      display: flex;
      align-items: center;
      gap: 10px;
      background: #ffe3ca;
      border-radius: 999px;
      padding: 4px 10px;
    }

    .cart-item-qty button {
      background: none;
      border: none;
      font-size: 14px;
      font-weight: 800;
      color: var(--text-dark);
      cursor: pointer;
      width: 20px;
      height: 20px;
      display: grid;
      place-items: center;
    }

    .cart-item-qty span {
      font-size: 13px;
      font-weight: 800;
      color: var(--text-dark);
      min-width: 14px;
      text-align: center;
    }

    .cart-item-remove {
      background: none;
      border: none;
      color: #b02500;
      font-size: 18px;
      cursor: pointer;
      transition: var(--transition-fast);
    }

    .cart-item-remove:hover {
      transform: scale(1.1);
    }

    .cart-drawer-footer {
      padding: 24px;
      background: white;
      border-top: 1px solid var(--glass-border);
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .cart-summary-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 14px;
      font-weight: 700;
      color: var(--text-medium);
    }

    .cart-summary-row.total {
      font-size: 20px;
      font-weight: 800;
      color: var(--text-dark);
      border-top: 1px solid rgba(213, 164, 112, 0.12);
      padding-top: 12px;
    }

    .cart-summary-row.total span {
      color: var(--secondary);
    }

    .checkout-btn {
      height: 52px;
      border-radius: 14px;
      border: none;
      background: var(--primary);
      color: white;
      font-size: 15px;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 10px 20px rgba(255, 138, 0, 0.2);
      transition: var(--transition-fast);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }

    .checkout-btn:hover {
      background: var(--primary-hover);
      box-shadow: 0 14px 24px rgba(255, 138, 0, 0.3);
    }

    /* --- MODALES --- */
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
      max-width: 480px;
      padding: 30px;
      box-shadow: 0 25px 55px rgba(0,0,0,0.15);
      border: 1px solid var(--glass-border);
      position: relative;
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .modal-title {
      font-size: 20px;
      font-weight: 800;
      color: var(--text-dark);
    }

    .modal-close {
      background: none;
      border: none;
      font-size: 24px;
      cursor: pointer;
      color: var(--text-medium);
    }

    .modal-close:hover {
      color: var(--primary);
    }

    .modal-body {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 24px;
    }

    .modal-actions button {
      padding: 12px 24px;
      border-radius: 12px;
      font-size: 13px;
      font-weight: 800;
      cursor: pointer;
      transition: var(--transition-fast);
      border: none;
    }

    .btn-cancel {
      background: rgba(213, 164, 112, 0.1);
      color: var(--text-medium);
    }

    .btn-cancel:hover {
      background: rgba(213, 164, 112, 0.2);
    }

    .btn-submit {
      background: var(--primary);
      color: white;
      box-shadow: 0 6px 12px rgba(255, 138, 0, 0.2);
    }

    .btn-submit:hover {
      background: var(--primary-hover);
    }

    /* Pestaña móvil inferior */
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

    /* Estilos Responsivos */
    @media (max-width: 991px) {
      .admin-hamburger, .admin-menu-overlay {
        display: flex;
      }

      .sidebar {
        position: fixed !important;
        top: 0 !important;
        left: -280px !important;
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
        padding: 85px 20px 24px !important;
        width: 100% !important;
      }
    }

    @media (max-width: 767px) {
      .mobile-nav {
        display: grid;
      }

      .main-content {
        padding: 85px 16px 84px !important;
        margin-left: 0;
      }

      .catalog-search-filters {
        grid-template-columns: 1fr;
      }

      .referrals-layout {
        grid-template-columns: 1fr;
      }

      .order-card-header {
        grid-template-columns: 1fr 1fr;
      }

      .profile-form-grid {
        grid-template-columns: 1fr;
      }

      .cart-drawer {
        width: 100%;
        right: -100%;
      }
    }
  </style>
</head>
<body>

<div class="dashboard-container">

  <!-- Sidebar / Barra lateral (Desktop) -->
  <aside class="sidebar">
    <div class="brand">☰ ECOALI</div>

    <div class="profile-card">
      <div class="avatar"><?php echo strtoupper(substr($nombre, 0, 1)); ?></div>
      <div class="info">
        <h4><?php echo htmlspecialchars($nombre . " " . $apellido); ?></h4>
        <p>Cliente EcoAli</p>
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

  <!-- Contenido Principal -->
  <main class="main-content">

    <header class="app-header">
      <div>
        <h1 id="page-title">Catálogo de Productos</h1>
        <p id="page-subtitle">Frescura directo del campo a tu hogar.</p>
      </div>

      <div class="cart-trigger" onclick="toggleCart(true)">
        🛒
        <span id="cart-counter">0</span>
      </div>
    </header>

    <!-- PESTAÑA: CATÁLOGO -->
    <section id="tab-catalogo" class="tab-pane active">
      <div class="catalog-search-filters">
        <div class="search-input-wrapper">
          <span>⌕</span>
          <input type="text" id="catalog-search" placeholder="Buscar productos frescos..." onkeyup="filterProducts()">
        </div>

        <div class="filter-tags">
          <button class="filter-tag active" onclick="filterTag('todos', this)">Todos</button>
          <button class="filter-tag" onclick="filterTag('orgánico', this)">Orgánico</button>
          <button class="filter-tag" onclick="filterTag('tradicional', this)">Tradicional</button>
          <button class="filter-tag" onclick="filterTag('pasto', this)">De Pasto</button>
        </div>
      </div>

      <div class="product-grid" id="products-container">
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
          ?>
            <article class="product-card" data-nombre="<?php echo htmlspecialchars(strtolower($p['nombre'])); ?>" data-tipo="<?php echo htmlspecialchars(strtolower($p['tipo_huevo'])); ?>">
              <div class="img-container" style="background-image: url('<?php echo $bgImg; ?>')">
                <span class="badge-stock">● STOCK DISPONIBLE</span>
              </div>
              <div class="card-content">
                <h3><?php echo htmlspecialchars($p["nombre"]); ?></h3>
                <div class="specs">
                  <span>Tipo: <?php echo htmlspecialchars($p["tipo_huevo"]); ?></span>
                  <span>Tamaño: <?php echo htmlspecialchars($p["tamano"]); ?></span>
                </div>
                <div class="bottom-row">
                  <span class="price">$<?php echo number_format($p["precio"], 2); ?></span>
                  <button class="add-btn" onclick="addToCart(<?php echo $p['id']; ?>, '<?php echo addslashes($p['nombre']); ?>', <?php echo $p['precio']; ?>)">+</button>
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
      <div class="orders-container">
        <?php if (!empty($pedidos)): ?>
          <?php foreach ($pedidos as $ped): 
            $estado = strtolower($ped["estado"]);
          ?>
            <article class="order-card" id="order-card-<?php echo $ped['id']; ?>">
              <div class="order-card-header">
                <div>
                  <h4>ID del Pedido</h4>
                  <p>#PED-<?php echo str_pad($ped["id"], 3, "0", STR_PAD_LEFT); ?></p>
                </div>
                <div>
                  <h4>Fecha</h4>
                  <p><?php echo date("d M Y, h:i A", strtotime($ped["fecha_pedido"])); ?></p>
                </div>
                <div>
                  <span class="badge-status <?php echo $estado; ?>" id="order-status-badge-<?php echo $ped['id']; ?>"><?php echo $ped["estado"]; ?></span>
                </div>
                <div>
                  <span class="order-total">$<?php echo number_format($ped["total"], 2); ?></span>
                </div>
                <div class="order-actions">
                  <button class="order-btn" onclick="toggleOrderDetails(<?php echo $ped['id']; ?>)">Detalles</button>
                  <?php if ($estado === "pendiente"): ?>
                    <button class="order-btn cancel" id="cancel-btn-<?php echo $ped['id']; ?>" onclick="confirmCancelOrder(<?php echo $ped['id']; ?>)">Cancelar</button>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Desglose de Pedido (Accordion) -->
              <div class="order-details-pane" id="order-details-<?php echo $ped['id']; ?>">
                <p style="margin: 0 0 16px; font-size: 13px; font-weight: 700; color: var(--text-medium);">
                  📦 Repartidor asignado: <strong><?php echo htmlspecialchars($ped["repartidor_nombre"] ?? "Pendiente de asignación"); ?></strong>
                </p>
                <table class="order-details-table">
                  <thead>
                    <tr>
                      <th>Producto</th>
                      <th>Tipo</th>
                      <th>Tamaño</th>
                      <th>Cantidad</th>
                      <th>Precio Unitario</th>
                      <th>Subtotal</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($ped["items"] as $it): ?>
                      <tr>
                        <td><?php echo htmlspecialchars($it["producto_nombre"]); ?></td>
                        <td><?php echo htmlspecialchars($it["tipo_huevo"]); ?></td>
                        <td><?php echo htmlspecialchars($it["tamano"]); ?></td>
                        <td><?php echo $it["cantidad"]; ?> ud</td>
                        <td>$<?php echo number_format($it["precio_unitario"], 2); ?></td>
                        <td>$<?php echo number_format($it["subtotal"], 2); ?></td>
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
      <div class="referrals-metrics">
        <div class="ref-card">
          <span class="label">Comisiones Pendientes</span>
          <span class="value">$<?php echo number_format($comisionPendiente, 2); ?></span>
        </div>
        <div class="ref-card">
          <span class="label">Comisiones Cobradas</span>
          <span class="value" style="color:var(--secondary);">$<?php echo number_format($comisionPagada, 2); ?></span>
        </div>
        <div class="ref-card highlight">
          <span class="label">Comisiones Totales</span>
          <span class="value">$<?php echo number_format($comisionTotal, 2); ?></span>
        </div>
      </div>

      <div class="referrals-layout">
        <!-- Código de Invitación -->
        <div class="referral-box">
          <h3>¡Invita a tus amigos y gana!</h3>
          <p>
            Comparte tu código exclusivo de EcoAli. Cuando tus amigos completen su registro e ingresen tu código al realizar sus pedidos de deliciosos huevos frescos, **tú recibirás automáticamente el 10% de comisión** en regalías de por vida en todas sus compras.
          </p>

          <div class="referral-code-wrapper">
            <small>Tu Código de Referido</small>
            <strong><?php echo $codigoReferido; ?></strong>
            <button class="copy-code-btn" onclick="copyReferralCode('<?php echo $codigoReferido; ?>')">Copiar Código</button>
          </div>
        </div>

        <!-- Lista de comisiones generadas -->
        <div class="referral-box" style="display:flex; flex-direction:column;">
          <h3>Comisiones Recientes</h3>
          <div style="flex-grow:1; overflow-y:auto; max-height: 290px;">
            <?php if (!empty($listaRegalias)): ?>
              <table style="width:100%; border-collapse:collapse;">
                <thead>
                  <tr style="border-bottom:1px solid var(--glass-border); text-align:left;">
                    <th style="padding:10px; font-size:11px; color:var(--text-medium); font-weight:800;">REFERIDO</th>
                    <th style="padding:10px; font-size:11px; color:var(--text-medium); font-weight:800;">COMISIÓN</th>
                    <th style="padding:10px; font-size:11px; color:var(--text-medium); font-weight:800;">ESTADO</th>
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
      <div class="profile-card-large">
        <h2>Información de tu Perfil</h2>
        <p>Mantén tu información de contacto actualizada para facilitar tus despachos.</p>

        <form id="profile-form" onsubmit="saveProfile(event)">
          <div class="profile-form-grid">
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
              <label>Dirección de Envío Principal</label>
              <input type="text" id="prof-direccion" value="<?php echo htmlspecialchars($direccion); ?>" placeholder="Calle, número, apto., barrio o localidad">
            </div>

            <button type="submit" class="profile-save-btn">Guardar Cambios</button>
          </div>
        </form>
      </div>
    </section>

  </main>

  <!-- Mobile Bottom Nav -->
  <nav class="mobile-nav">
    <button class="mobile-nav-btn active" onclick="switchTab('catalogo', this)">
      <span>▦</span>
      <span>Catálogo</span>
    </button>
    <button class="mobile-nav-btn" onclick="switchTab('pedidos', this)">
      <span>▤</span>
      <span>Pedidos</span>
    </button>
    <button class="mobile-nav-btn" onclick="switchTab('regalias', this)">
      <span>◈</span>
      <span>Regalías</span>
    </button>
    <button class="mobile-nav-btn" onclick="switchTab('perfil', this)">
      <span>♙</span>
      <span>Perfil</span>
    </button>
  </nav>

</div>

<!-- Carrito de Compras (Drawer deslizable) -->
<div class="cart-drawer-overlay" id="cart-overlay" onclick="toggleCart(false)"></div>
<div class="cart-drawer" id="cart-drawer">
  <div class="cart-drawer-header">
    <h3>Tu Carrito</h3>
    <button class="close-drawer-btn" onclick="toggleCart(false)">×</button>
  </div>

  <div class="cart-items-list" id="cart-items-container">
    <!-- Carga dinámica por JS -->
  </div>

  <div class="cart-drawer-footer">
    <div class="cart-summary-row">
      <span>Subtotal</span>
      <span id="cart-subtotal">$0.00</span>
    </div>
    <div class="cart-summary-row">
      <span>Envío</span>
      <span style="color:var(--secondary);">GRATIS</span>
    </div>
    <div class="cart-summary-row total">
      <span>Total</span>
      <span id="cart-total">$0.00</span>
    </div>

    <button class="checkout-btn" onclick="openCheckoutModal()">
      Proceder a Compra ➜
    </button>
  </div>
</div>

<!-- Modal: Confirmar Checkout -->
<div class="modal-overlay" id="checkout-modal">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Detalles de Entrega</div>
      <button class="modal-close" onclick="closeCheckoutModal()">×</button>
    </div>

    <form id="checkout-form" onsubmit="submitOrder(event)">
      <div class="modal-body">
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: 0 0 10px;">
          Confirma la información de contacto donde el repartidor se comunicará al llegar a tu domicilio.
        </p>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:11px; font-weight:800; color:var(--text-medium);">DIRECCIÓN DE ENTREGA</label>
          <input type="text" id="chk-direccion" value="<?php echo htmlspecialchars($direccion); ?>" required style="height:46px; border-radius:10px; border:1px solid var(--glass-border); padding:0 12px; outline:none; font-family:inherit;" placeholder="Calle, apto, barrio...">
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:11px; font-weight:800; color:var(--text-medium);">TELÉFONO DE CONTACTO</label>
          <input type="text" id="chk-telefono" value="<?php echo htmlspecialchars($telefono); ?>" required style="height:46px; border-radius:10px; border:1px solid var(--glass-border); padding:0 12px; outline:none; font-family:inherit;">
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:11px; font-weight:800; color:var(--text-medium);">CÓDIGO DE REFERIDO (OPCIONAL)</label>
          <input type="text" id="chk-referido" style="height:46px; border-radius:10px; border:1px solid var(--glass-border); padding:0 12px; outline:none; font-family:inherit; text-transform:uppercase;" placeholder="Ej: DIEGO">
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeCheckoutModal()">Cancelar</button>
        <button type="submit" class="btn-submit">Realizar Pedido</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Éxito o Alertas -->
<div class="modal-overlay" id="alert-modal">
  <div class="modal-container" style="max-width: 400px; text-align: center; padding: 40px 30px;">
    <div style="font-size: 60px; color: var(--secondary); margin-bottom: 20px;" id="alert-icon">✓</div>
    <h3 style="margin: 0 0 10px; font-size: 22px; font-weight: 800;" id="alert-title">¡Éxito!</h3>
    <p style="margin: 0 0 25px; font-size: 14px; color: var(--text-medium); line-height: 1.6;" id="alert-message">
      Tu operación se procesó de forma segura.
    </p>
    <button onclick="closeAlertModal()" style="background: var(--text-dark); color: white; border: none; padding: 12px 30px; border-radius: 12px; font-weight: 800; cursor: pointer;">
      Entendido
    </button>
  </div>
</div>

<script>
  // Control de Estado del Carrito en LocalStorage
  let cart = JSON.parse(localStorage.getItem('ecoali_cart_' + <?php echo $cliente_id; ?>)) || [];

  document.addEventListener('DOMContentLoaded', () => {
      updateCartUI();
  });

  // Switch de Pestañas dinámico sin recargar
  function switchTab(tabName, element) {
      // Ocultar todas las pestañas
      document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
      
      // Mostrar pestaña seleccionada
      document.getElementById('tab-' + tabName).classList.add('active');

      // Actualizar estados activos de los botones
      document.querySelectorAll('.sidebar-menu button, .mobile-nav-btn').forEach(btn => btn.classList.remove('active'));
      
      // Buscar y activar botones correspondientes
      document.querySelectorAll('.sidebar-menu button, .mobile-nav-btn').forEach(btn => {
          if (btn.outerHTML.includes(tabName)) {
              btn.classList.add('active');
          }
      });

      // Modificar Header dinámicamente
      const titles = {
          'catalogo': { title: 'Catálogo de Productos', subtitle: 'Frescura directo del campo a tu hogar.' },
          'pedidos': { title: 'Tus Pedidos', subtitle: 'Monitorea el estado y el historial de tus entregas.' },
          'regalias': { title: 'Regalías y Referidos', subtitle: 'Gana comisiones invitando amigos a la red EcoAli.' },
          'perfil': { title: 'Tu Perfil', subtitle: 'Actualiza tus datos de contacto y facturación.' }
      };

      if (titles[tabName]) {
          document.getElementById('page-title').textContent = titles[tabName].title;
          document.getElementById('page-subtitle').textContent = titles[tabName].subtitle;
      }
  }

  // Búsqueda en tiempo real del catálogo
  function filterProducts() {
      const query = document.getElementById('catalog-search').value.toLowerCase();
      const cards = document.querySelectorAll('.product-card');

      cards.forEach(card => {
          const nombre = card.dataset.nombre;
          if (nombre.includes(query)) {
              card.style.display = 'flex';
          } else {
              card.style.display = 'none';
          }
      });
  }

  // Filtrado de productos por tags
  function filterTag(tag, btn) {
      document.querySelectorAll('.filter-tag').forEach(t => t.classList.remove('active'));
      btn.classList.add('active');

      const cards = document.querySelectorAll('.product-card');
      cards.forEach(card => {
          const tipo = card.dataset.tipo;
          if (tag === 'todos' || tipo.includes(tag)) {
              card.style.display = 'flex';
          } else {
              card.style.display = 'none';
          }
      });
  }

  // Lógica del Accordion de Pedidos
  function toggleOrderDetails(id) {
      const pane = document.getElementById('order-details-' + id);
      pane.classList.toggle('active');
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

  function addToCart(id, nombre, precio) {
      const existing = cart.find(item => item.producto_id === id);
      if (existing) {
          existing.cantidad += 1;
      } else {
          cart.push({ producto_id: id, nombre: nombre, precio: parseFloat(precio), cantidad: 1 });
      }
      saveCart();
      updateCartUI();
      toggleCart(true);
  }

  function updateQty(id, change) {
      const item = cart.find(item => item.producto_id === id);
      if (item) {
          item.cantidad += change;
          if (item.cantidad <= 0) {
              cart = cart.filter(item => item.producto_id !== id);
          }
          saveCart();
          updateCartUI();
      }
  }

  function removeFromCart(id) {
      cart = cart.filter(item => item.producto_id !== id);
      saveCart();
      updateCartUI();
  }

  function saveCart() {
      localStorage.setItem('ecoali_cart_' + <?php echo $cliente_id; ?>, JSON.stringify(cart));
  }

  function updateCartUI() {
      const container = document.getElementById('cart-items-container');
      const counter = document.getElementById('cart-counter');
      const subtotalEl = document.getElementById('cart-subtotal');
      const totalEl = document.getElementById('cart-total');

      container.innerHTML = '';
      
      let totalQty = 0;
      let subtotal = 0.0;

      if (cart.length === 0) {
          container.innerHTML = `
            <div style="text-align:center; padding:40px 0; color:var(--text-medium); font-weight:700;">
              Tu carrito está vacío. ¡Agrega deliciosos huevos del catálogo!
            </div>
          `;
      } else {
          cart.forEach(item => {
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

  // Checkout modal
  function openCheckoutModal() {
      if (cart.length === 0) {
          showAlertModal('Carrito vacío', 'Agrega algún producto antes de proceder al pago.', '✗', '#b02500');
          return;
      }
      document.getElementById('checkout-modal').classList.add('active');
  }

  function closeCheckoutModal() {
      document.getElementById('checkout-modal').classList.remove('active');
  }

  // Realizar Pedido (AJAX Checkout)
  function submitOrder(e) {
      e.preventDefault();
      
      const dir = document.getElementById('chk-direccion').value;
      const tel = document.getElementById('chk-telefono').value;
      const ref = document.getElementById('chk-referido').value;

      const payload = {
          direccion: dir,
          telefono: tel,
          referido_por: ref,
          carrito: cart
      };

      fetch('forms/procesar_pedido.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
          if (data.status === 'success') {
              // Limpiar carrito
              cart = [];
              saveCart();
              updateCartUI();
              closeCheckoutModal();
              toggleCart(false);

              // Modal de éxito
              showAlertModal(
                  '¡Pedido Realizado!',
                  `Tu orden #PED-${String(data.pedido_id).padStart(3, '0')} se registró correctamente por un total de $${parseFloat(data.total).toFixed(2)}. Pronto despacharemos tu envío.`,
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
          showAlertModal('Error de Servidor', 'Ocurrió un error inesperado al procesar el pedido.', '✗', '#b02500');
      });
  }

  // Cancelación segura de pedido
  function confirmCancelOrder(id) {
      if (confirm('¿Estás seguro de que deseas cancelar este pedido? Esta acción es irreversible.')) {
          fetch('forms/cancelar_pedido.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ pedido_id: id })
          })
          .then(res => res.json())
          .then(data => {
              if (data.status === 'success') {
                  // Actualizar la interfaz
                  const badge = document.getElementById('order-status-badge-' + id);
                  if (badge) {
                      badge.className = 'badge-status cancelado';
                      badge.textContent = 'cancelado';
                  }
                  
                  const btn = document.getElementById('cancel-btn-' + id);
                  if (btn) btn.remove();

                  showAlertModal('Pedido Cancelado', data.message, '✓', 'var(--secondary)');
              } else {
                  showAlertModal('Error', data.message, '✗', '#b02500');
              }
          })
          .catch(err => {
              console.error(err);
              showAlertModal('Error de Servidor', 'Ocurrió un error inesperado.', '✗', '#b02500');
          });
      }
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
              showAlertModal('Perfil Actualizado', data.message, '✓', 'var(--secondary)', true);
          } else {
              showAlertModal('Error', data.message, '✗', '#b02500');
          }
      })
      .catch(err => {
          console.error(err);
          showAlertModal('Error', 'Ocurrió un error al guardar los cambios.', '✗', '#b02500');
      });
  }

  // Copiar código de referido
  function copyReferralCode(code) {
      navigator.clipboard.writeText(code).then(() => {
          alert('¡Código de referido copiado al portapapeles!');
      });
  }

  // Modales de alertas genéricas
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