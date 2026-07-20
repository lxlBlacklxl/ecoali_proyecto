<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - PORTAL CEDIS: PREPARACIÓN DE PEDIDOS
 * --------------------------------------------------------------------------------
 * Permite a los operadores empaquetar pedidos físicos de los clientes y
 * cambiarlos a estado 'preparado' para habilitar su asignación a transportistas.
 */

session_start();
require "forms/conexion.php";

// 1. Control de acceso
if (!isset($_SESSION["usuario_id"]) || ((int)$_SESSION["rol_id"] !== 5 && (int)$_SESSION["rol_id"] !== 1)) {
    header("Location: login.php");
    exit;
}

$cedis_usuario_id = $_SESSION["cedis_id"] ?? null;
$rol_actual = (int)$_SESSION["rol_id"];

if ($rol_actual === 5 && empty($cedis_usuario_id)) {
    die("Error: Tu usuario no tiene asignado ningún Centro de Distribución (CEDIS).");
}

// Cargar información del CEDIS
$cedis_nombre = "Todos los CEDIS (Modo Administrador)";
if (!empty($cedis_usuario_id)) {
    $stmtC = $conn->prepare("SELECT nombre FROM cedis WHERE id = ?");
    $stmtC->bind_param("i", $cedis_usuario_id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    if ($resC->num_rows > 0) {
        $cedis_nombre = $resC->fetch_assoc()["nombre"];
    }
}

$nombre_usuario = ($_SESSION["nombre"] ?? "Operador") . " " . ($_SESSION["apellido"] ?? "");

// Pedidos pendientes de preparación
$pedidosPendQuery = "SELECT p.id, p.total, p.fecha_pedido, p.metodo_pago, p.pago_estado, up.nombre, up.apellido 
                    FROM pedidos p
                    INNER JOIN usuario_perfil up ON p.cliente_id = up.usuario_id
                    WHERE p.estado = 'pendiente'
                    ORDER BY p.id ASC";
$pedidosPendRes = $conn->query($pedidosPendQuery);

// Pedidos ya preparados (Historial)
$pedidosPrepQuery = "SELECT p.id, p.total, p.fecha_pedido, p.metodo_pago, p.pago_estado, up.nombre, up.apellido 
                     FROM pedidos p
                     INNER JOIN usuario_perfil up ON p.cliente_id = up.usuario_id
                     WHERE p.estado = 'preparado'
                     ORDER BY p.id DESC LIMIT 25";
$pedidosPrepRes = $conn->query($pedidosPrepQuery);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preparación de Pedidos - ECOALI</title>
    
    <link rel="stylesheet" href="assets/css/globals.css">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark-mode: #121310;
            --card-bg-dark: rgba(30, 32, 28, 0.85);
            --border-dark: rgba(213, 164, 112, 0.15);
            --primary: #ff8a00;
            --primary-hover: #e07b00;
            --secondary: #176a21;
            --text-light: #fbf8f5;
            --text-muted: #a39585;
            --shadow-premium: 0 16px 40px rgba(0,0,0,0.3);
            --transition-fast: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            background-color: var(--bg-dark-mode);
            background-image: radial-gradient(circle at 10% 20%, rgba(255,138,0,0.05) 0%, transparent 40%),
                              radial-gradient(circle at 90% 80%, rgba(23,106,33,0.05) 0%, transparent 45%);
            color: var(--text-light);
            font-family: 'Manrope', sans-serif;
            min-height: 100vh;
            margin: 0;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Sidebar Desktop */
        .sidebar {
            width: 280px;
            background: var(--card-bg-dark);
            backdrop-filter: blur(20px);
            border-right: 1px solid var(--border-dark);
            display: flex;
            flex-direction: column;
            padding: 30px 24px;
            position: fixed;
            height: 100vh;
            z-index: 10;
            box-shadow: 10px 0 35px rgba(0,0,0,0.2);
            transition: var(--transition-fast);
            box-sizing: border-box;
        }

        .sidebar .brand {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 26px;
            font-weight: 800;
            color: var(--primary);
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
            border: 1px solid rgba(255, 138, 0, 0.15);
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
            color: var(--text-light);
        }

        .sidebar .profile-card .info p {
            margin: 2px 0 0;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .sidebar .nav-links {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .sidebar .nav-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            border-radius: 14px;
            transition: var(--transition-fast);
        }

        .sidebar .nav-links a:hover,
        .sidebar .nav-links a.active {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-light);
            border-left: 4px solid var(--primary);
        }

        .sidebar .nav-links a.active {
            background: rgba(255, 138, 0, 0.1);
            color: var(--primary);
        }

        .sidebar .logout-link {
            padding: 14px 18px;
            color: #ff4a4a;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            border-radius: 14px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: var(--transition-fast);
            margin-top: auto;
        }

        .sidebar .logout-link:hover {
            background: rgba(255, 74, 74, 0.1);
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 40px;
            width: calc(100% - 280px);
            min-height: 100vh;
            box-sizing: border-box;
            transition: var(--transition-fast);
        }

        /* Header */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 35px;
        }

        .dashboard-header h1 {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 28px;
            font-weight: 800;
            color: var(--text-light);
        }

        .dashboard-header .cedis-badge {
            background: rgba(23, 106, 33, 0.15);
            border: 1px solid rgba(23, 106, 33, 0.3);
            color: #39e55d;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 800;
        }

        /* Tab Toggles */
        .tab-toggle-container {
            display: flex;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-dark);
            border-radius: 15px;
            padding: 5px;
            width: fit-content;
            margin-bottom: 30px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(255, 138, 0, 0.3);
        }

        .section-card {
            background: var(--card-bg-dark);
            border: 1px solid var(--border-dark);
            border-radius: 24px;
            padding: 30px;
            box-sizing: border-box;
            margin-bottom: 30px;
        }

        .section-card h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 20px;
            font-weight: 800;
            color: var(--text-light);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 16px 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            font-size: 14px;
        }

        .data-table th {
            color: var(--text-muted);
            font-weight: 800;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        .data-table td {
            color: var(--text-light);
            font-weight: 500;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .badge-pendiente {
            background: rgba(255, 138, 0, 0.15);
            color: #ff9f29;
            border: 1px solid rgba(255, 138, 0, 0.25);
        }

        .badge-preparado {
            background: rgba(57, 229, 93, 0.15);
            color: #39e55d;
            border: 1px solid rgba(57, 229, 93, 0.25);
        }

        /* Action Buttons */
        .btn-action {
            background: var(--primary);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .btn-action:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
        }

        /* Modal styling */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            z-index: 10000;
            display: grid;
            place-items: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-content {
            background: #1e201c;
            border: 1px solid var(--border-dark);
            border-radius: 24px;
            width: 90%;
            max-width: 650px;
            padding: 30px;
            box-shadow: var(--shadow-premium);
            transform: scale(0.9);
            transition: transform 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-overlay.active .modal-content {
            transform: scale(1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-header h3 {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 20px;
            color: var(--text-light);
        }

        .modal-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
        }

        .meta-info-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 25px;
        }

        .meta-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .meta-group label {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 800;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }

        .meta-group span {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-light);
        }

        .order-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            background: rgba(255,255,255,0.01);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 12px;
            overflow: hidden;
        }

        .order-items-table th,
        .order-items-table td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .order-items-table th {
            background: rgba(255,255,255,0.03);
            color: var(--text-muted);
            font-weight: 800;
            text-transform: uppercase;
            font-size: 11px;
        }

        .order-items-table td {
            color: white;
            font-weight: 500;
        }

        .order-items-table tr:last-child td {
            border-bottom: none;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .btn-cancel {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--text-light);
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .btn-cancel:hover {
            background: rgba(255,255,255,0.1);
        }

        .btn-submit {
            background: var(--secondary);
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(23, 106, 33, 0.2);
            transition: var(--transition-fast);
        }

        .btn-submit:hover {
            background: #1e872c;
        }

        /* Responsive */
        @media (max-width: 991px) {
            .sidebar {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 20px 16px 80px !important;
            }
            .mobile-nav {
                display: grid !important;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                height: 60px !important;
                background: rgba(18, 19, 16, 0.96) !important;
                backdrop-filter: blur(15px) !important;
                border-top: 1px solid var(--border-dark) !important;
                grid-template-columns: repeat(4, 1fr);
                z-index: 9999;
                padding: 5px 0;
                box-sizing: border-box;
            }
            .mobile-nav-btn {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                color: var(--text-muted);
                text-decoration: none;
                font-size: 10px;
                font-weight: 700;
            }
            .mobile-nav-btn span {
                font-size: 18px;
                margin-bottom: 2px;
            }
            .mobile-nav-btn.active {
                color: var(--primary) !important;
            }
            .meta-info-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    
    <!-- Sidebar Desktop -->
    <aside class="sidebar">
        <div class="brand">
            <span>🥚</span> ECOALI CEDIS
        </div>
        
        <div class="profile-card">
            <div class="avatar"><?php echo strtoupper(substr($_SESSION["usuario"] ?? "O", 0, 1)); ?></div>
            <div class="info">
                <h4><?php echo htmlspecialchars($nombre_usuario); ?></h4>
                <p>Operador Logístico</p>
            </div>
        </div>
        
        <nav class="nav-links">
            <a href="dashboard_cedis.php">📊 Panel General</a>
            <a href="recepcion_cedis.php">🚚 Recepción Envíos</a>
            <a href="preparacion_cedis.php" class="active">📦 Preparar Pedidos</a>
            <a href="inventario_cedis.php">🥚 Inventario & Mermas</a>
        </nav>
        
        <a href="logout.php" class="logout-link">🚪 Cerrar Sesión</a>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div>
                <h1>Preparación de Pedidos</h1>
                <p style="color: var(--text-muted); margin: 5px 0 0 0;">Empaqueta físicamente los pedidos y márcalos listos para la cadena de reparto.</p>
            </div>
            <div class="cedis-badge">
                📍 <?php echo htmlspecialchars($cedis_nombre); ?>
            </div>
        </header>

        <!-- Tab toggles -->
        <div class="tab-toggle-container">
            <button class="tab-btn active" onclick="switchTab('pendientes')">Por Preparar</button>
            <button class="tab-btn" onclick="switchTab('preparados')">Historial Preparados</button>
        </div>

        <!-- Tab: Por Preparar -->
        <section id="tab-pendientes" class="section-card">
            <h2>📦 Pedidos Pendientes de Empaque</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Código Pedido</th>
                        <th>Cliente</th>
                        <th>Fecha de Compra</th>
                        <th>Pago</th>
                        <th>Monto Total</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pedidosPendRes && $pedidosPendRes->num_rows > 0): ?>
                        <?php while ($p = $pedidosPendRes->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#PED-<?php echo str_pad($p["id"], 3, "0", STR_PAD_LEFT); ?></strong></td>
                                <td><?php echo htmlspecialchars($p["nombre"] . " " . $p["apellido"]); ?></td>
                                <td><?php echo date("d/m/Y H:i", strtotime($p["fecha_pedido"])); ?></td>
                                <td>
                                    <span style="font-weight: 700; color: #a39585; font-size: 12px; text-transform: uppercase;">
                                        <?php echo htmlspecialchars($p["metodo_pago"]); ?>
                                    </span>
                                    <span style="font-size: 11px; margin-left: 5px; color: <?php echo ($p["pago_estado"] === 'pagado' || $p["pago_estado"] === 'aprobado') ? '#39e55d' : '#ff9f29'; ?>">
                                        (<?php echo htmlspecialchars($p["pago_estado"]); ?>)
                                    </span>
                                </td>
                                <td><strong>$<?php echo number_format($p["total"], 2); ?></strong></td>
                                <td>
                                    <button class="btn-action" onclick="abrirModalPreparar(<?php echo $p['id']; ?>)">
                                        📦 Detalle & Preparar
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">
                                ¡No hay pedidos pendientes por empaquetar! Todo al día.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- Tab: Historial Preparados -->
        <section id="tab-preparados" class="section-card" style="display: none;">
            <h2>📜 Historial Preparados (Últimos 25)</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Código Pedido</th>
                        <th>Cliente</th>
                        <th>Fecha de Compra</th>
                        <th>Método Pago</th>
                        <th>Monto Total</th>
                        <th>Estado Logístico</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pedidosPrepRes && $pedidosPrepRes->num_rows > 0): ?>
                        <?php while ($p = $pedidosPrepRes->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#PED-<?php echo str_pad($p["id"], 3, "0", STR_PAD_LEFT); ?></strong></td>
                                <td><?php echo htmlspecialchars($p["nombre"] . " " . $p["apellido"]); ?></td>
                                <td><?php echo date("d/m/Y H:i", strtotime($p["fecha_pedido"])); ?></td>
                                <td><?php echo htmlspecialchars($p["metodo_pago"]); ?> (<?php echo htmlspecialchars($p["pago_estado"]); ?>)</td>
                                <td><strong>$<?php echo number_format($p["total"], 2); ?></strong></td>
                                <td>
                                    <span class="badge badge-preparado">Preparado</span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">
                                Aún no has preparado ningún pedido en esta sesión.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

    </main>
</div>

<!-- Modal: Detalle & Preparación de Pedido -->
<div class="modal-overlay" id="modalPreparar">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modal-titulo">Preparar Pedido #PED-000</h3>
            <button class="modal-close" onclick="cerrarModalPreparar()">&times;</button>
        </div>
        
        <div class="meta-info-row">
            <div class="meta-group">
                <label>Cliente</label>
                <span id="modal_cliente_nombre">-</span>
            </div>
            <div class="meta-group">
                <label>Teléfono</label>
                <span id="modal_cliente_telefono">-</span>
            </div>
            <div class="meta-group" style="grid-column: span 2; margin-top: 10px;">
                <label>Dirección de Entrega</label>
                <span id="modal_cliente_direccion" style="line-height: 1.4;">-</span>
            </div>
        </div>

        <h4 style="margin: 0 0 12px 0; color: var(--text-light); font-family: 'Plus Jakarta Sans', sans-serif;">Detalle de Artículos a Empacar</h4>
        
        <table class="order-items-table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Tipo Huevo</th>
                    <th>Tamaño</th>
                    <th style="text-align: center;">Cantidad (Unidades)</th>
                </tr>
            </thead>
            <tbody id="modal_items_tbody">
                <!-- Se poblará dinámicamente -->
            </tbody>
        </table>
        
        <div class="modal-actions">
            <input type="hidden" id="modal_pedido_id" value="">
            <button type="button" class="btn-cancel" onclick="cerrarModalPreparar()">Cancelar</button>
            <button type="button" class="btn-submit" onclick="confirmarPedidoPreparado()">📦 Marcar como Preparado</button>
        </div>
    </div>
</div>

<!-- Mobile Navigation Bar -->
<nav class="mobile-nav" style="display: none;">
    <a href="dashboard_cedis.php" class="mobile-nav-btn">
        <span>📊</span>
        Panel
    </a>
    <a href="recepcion_cedis.php" class="mobile-nav-btn">
        <span>🚚</span>
        Recepción
    </a>
    <a href="preparacion_cedis.php" class="mobile-nav-btn active">
        <span>📦</span>
        Preparación
    </a>
    <a href="inventario_cedis.php" class="mobile-nav-btn">
        <span>🥚</span>
        Inventario
    </a>
</nav>

<script>
function switchTab(tabId) {
    document.getElementById('tab-pendientes').style.display = 'none';
    document.getElementById('tab-preparados').style.display = 'none';
    
    const btns = document.querySelectorAll('.tab-btn');
    btns.forEach(btn => btn.classList.remove('active'));
    
    document.getElementById('tab-' + tabId).style.display = 'block';
    event.currentTarget.classList.add('active');
}

function abrirModalPreparar(pedidoId) {
    document.getElementById('modal_pedido_id').value = pedidoId;
    document.getElementById('modal-titulo').innerText = 'Preparar Pedido #PED-' + String(pedidoId).padStart(3, '0');
    
    const tbody = document.getElementById('modal_items_tbody');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--text-muted);">Cargando productos...</td></tr>';
    
    document.getElementById('modalPreparar').classList.add('active');
    
    // Fetch details
    fetch('forms/cedis_acciones.php?accion=obtener_detalle_pedido&pedido_id=' + pedidoId)
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success') {
                const cab = res.cabecera;
                document.getElementById('modal_cliente_nombre').innerText = cab.nombre + ' ' + cab.apellido;
                document.getElementById('modal_cliente_telefono').innerText = cab.telefono || 'Sin teléfono';
                document.getElementById('modal_cliente_direccion').innerText = cab.direccion || 'Sin dirección';
                
                tbody.innerHTML = '';
                res.items.forEach(item => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><strong>${item.producto_nombre}</strong></td>
                        <td>${item.tipo_huevo}</td>
                        <td>${item.tamano}</td>
                        <td style="text-align: center; font-size: 16px; font-weight: 800; color: var(--primary);">${item.cantidad} cartones</td>
                    `;
                    tbody.appendChild(tr);
                    
                    // Renderizar sugerencia FIFO
                    if (item.lotes_sugeridos && item.lotes_sugeridos.length > 0) {
                        const trFifo = document.createElement('tr');
                        trFifo.style.background = 'rgba(255, 138, 0, 0.02)';
                        
                        let lotesHtml = '';
                        item.lotes_sugeridos.forEach((l, idx) => {
                            const fecha = new Date(l.fecha_postura);
                            const fechaFormateada = (isNaN(fecha) || l.fecha_postura === '0000-00-00') ? 'Sin fecha' : l.fecha_postura.split('-').reverse().join('/');
                            
                            // Resaltar el primer lote (más antiguo)
                            const esPrioritario = idx === 0;
                            const badgeColor = esPrioritario ? 'background: rgba(255, 138, 0, 0.12); border: 1px solid rgba(255, 138, 0, 0.4); color: #fff;' : 'background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07); color: var(--text-muted);';
                            const checkIcon = esPrioritario ? '⚠️' : '📦';
                            const prioritarioBadge = esPrioritario ? '<span style="background: var(--primary); color: #000; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 4px; margin-left: 8px;">DEPLECIÓN PRIORITARIA</span>' : '';
                            
                            lotesHtml += `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; margin-bottom: 6px; border-radius: 10px; ${badgeColor}">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="font-size: 14px;">${checkIcon}</span>
                                        <strong>${l.codigo_lote}</strong>
                                        <span style="font-size: 11px; opacity: 0.7;">Postura: ${fechaFormateada}</span>
                                        ${prioritarioBadge}
                                    </div>
                                    <div style="font-weight: 700; font-size: 13px;">
                                        Stock: ${l.cantidad} huevos
                                    </div>
                                </div>
                            `;
                        });
                        
                        trFifo.innerHTML = `
                            <td colspan="4" style="padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <div style="font-size: 11px; text-transform: uppercase; font-weight: 800; color: var(--text-muted); margin-bottom: 8px; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px;">
                                    <span>💡</span> RECOMENDACIÓN DE ROTACIÓN FIFO (DEPLECIÓN DE LOTES MÁS ANTIGUOS)
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    ${lotesHtml}
                                </div>
                            </td>
                        `;
                        tbody.appendChild(trFifo);
                    } else {
                        const trFifo = document.createElement('tr');
                        trFifo.style.background = 'rgba(255, 74, 74, 0.03)';
                        trFifo.innerHTML = `
                            <td colspan="4" style="padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <div style="color: #ff4a4a; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                                    <span>❌</span> No hay lotes disponibles en stock para este producto en este CEDIS.
                                </div>
                            </td>
                        `;
                        tbody.appendChild(trFifo);
                    }
                });
            } else {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: #ff4a4a;">${res.message}</td></tr>`;
            }
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #ff4a4a;">Error de conexión.</td></tr>';
        });
}

function cerrarModalPreparar() {
    document.getElementById('modalPreparar').classList.remove('active');
}

function confirmarPedidoPreparado() {
    const pedidoId = parseInt(document.getElementById('modal_pedido_id').value) || 0;
    if (pedidoId <= 0) return;
    
    const btn = document.querySelector('#modalPreparar .btn-submit');
    btn.disabled = true;
    btn.innerText = 'Guardando...';
    
    fetch('forms/cedis_acciones.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            accion: 'preparar',
            pedido_id: pedidoId
        })
    })
    .then(res => res.json())
    .then(res => {
        if (res.status === 'success') {
            alert(res.message);
            window.location.reload();
        } else {
            alert(res.message);
            btn.disabled = false;
            btn.innerText = 'Marcar como Preparado';
        }
    })
    .catch(err => {
        alert('Error de conexión.');
        btn.disabled = false;
        btn.innerText = 'Marcar como Preparado';
    });
}
</script>

</body>
</html>
