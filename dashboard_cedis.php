<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - PORTAL CEDIS: PANEL DE CONTROL
 * --------------------------------------------------------------------------------
 * Muestra el estado operativo del Centro de Distribución asignado al operador,
 * incluyendo métricas en tiempo real de inventario, envíos y preparación.
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

// Si es operador y no tiene CEDIS, advertir
if ($rol_actual === 5 && empty($cedis_usuario_id)) {
    die("Error: Tu usuario no tiene asignado ningún Centro de Distribución (CEDIS). Contacta al administrador del sistema.");
}

// Cargar información del CEDIS
$cedis_nombre = "Todos los CEDIS (Modo Administrador)";
if (!empty($cedis_usuario_id)) {
    $stmtC = $conn->prepare("SELECT nombre, direccion FROM cedis WHERE id = ?");
    $stmtC->bind_param("i", $cedis_usuario_id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    if ($resC->num_rows > 0) {
        $cData = $resC->fetch_assoc();
        $cedis_nombre = $cData["nombre"];
    }
}

$nombre_usuario = ($_SESSION["nombre"] ?? "Operador") . " " . ($_SESSION["apellido"] ?? "");

// --- CONSULTAS MÉTRICAS ---

// 1. Stock total de huevos en este CEDIS
if ($rol_actual === 1) {
    // Admin ve todo el stock en CEDIS
    $stockRes = $conn->query("SELECT SUM(ih.cantidad) FROM inventario_huevos ih 
                             INNER JOIN detalle_entrega_cedis de_cedis ON ih.id = de_cedis.lote_id
                             INNER JOIN entregas_cedis ec ON de_cedis.entrega_id = ec.id
                             WHERE ec.estado = 'recibido'");
} else {
    $stockRes = $conn->prepare("SELECT SUM(ih.cantidad) FROM inventario_huevos ih 
                                INNER JOIN detalle_entrega_cedis de_cedis ON ih.id = de_cedis.lote_id
                                INNER JOIN entregas_cedis ec ON de_cedis.entrega_id = ec.id
                                WHERE ec.cedis_id = ? AND ec.estado = 'recibido'");
    $stockRes->bind_param("i", $cedis_usuario_id);
    $stockRes->execute();
    $stockRes = $stockRes->get_result();
}
$stockRow = $stockRes ? $stockRes->fetch_row() : null;
$stockTotal = $stockRow && !is_null($stockRow[0]) ? (int)$stockRow[0] : 0;

// 2. Envíos pendientes o en ruta
if ($rol_actual === 1) {
    $enviosRes = $conn->query("SELECT COUNT(*) FROM entregas_cedis WHERE estado IN ('pendiente', 'en_ruta')");
} else {
    $enviosRes = $conn->prepare("SELECT COUNT(*) FROM entregas_cedis WHERE cedis_id = ? AND estado IN ('pendiente', 'en_ruta')");
    $enviosRes->bind_param("i", $cedis_usuario_id);
    $enviosRes->execute();
    $enviosRes = $enviosRes->get_result();
}
$enviosPendientes = $enviosRes ? $enviosRes->fetch_row()[0] : 0;

// 3. Pedidos por preparar (Global)
$pedidosRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente'");
$pedidosPendientes = $pedidosRes ? $pedidosRes->fetch_row()[0] : 0;

// 4. Mermas acumuladas en este CEDIS
if ($rol_actual === 1) {
    $mermasRes = $conn->query("SELECT SUM(ih.merma) FROM inventario_huevos ih
                              INNER JOIN detalle_entrega_cedis de_cedis ON ih.id = de_cedis.lote_id
                              INNER JOIN entregas_cedis ec ON de_cedis.entrega_id = ec.id
                              WHERE ec.estado = 'recibido'");
} else {
    $mermasRes = $conn->prepare("SELECT SUM(ih.merma) FROM inventario_huevos ih
                                INNER JOIN detalle_entrega_cedis de_cedis ON ih.id = de_cedis.lote_id
                                INNER JOIN entregas_cedis ec ON de_cedis.entrega_id = ec.id
                                WHERE ec.cedis_id = ? AND ec.estado = 'recibido'");
    $mermasRes->bind_param("i", $cedis_usuario_id);
    $mermasRes->execute();
    $mermasRes = $mermasRes->get_result();
}
$mermasRow = $mermasRes ? $mermasRes->fetch_row() : null;
$mermasTotal = $mermasRow && !is_null($mermasRow[0]) ? (int)$mermasRow[0] : 0;

// --- LISTADOS ---

// Envíos recientes
if ($rol_actual === 1) {
    $enviosListRes = $conn->query("SELECT e.id, e.fecha_recoleccion, e.estado, p.nombre_empresa 
                                  FROM entregas_cedis e
                                  INNER JOIN proveedores p ON e.proveedor_id = p.id
                                  ORDER BY e.id DESC LIMIT 5");
} else {
    $enviosListRes = $conn->prepare("SELECT e.id, e.fecha_recoleccion, e.estado, p.nombre_empresa 
                                    FROM entregas_cedis e
                                    INNER JOIN proveedores p ON e.proveedor_id = p.id
                                    WHERE e.cedis_id = ?
                                    ORDER BY e.id DESC LIMIT 5");
    $enviosListRes->bind_param("i", $cedis_usuario_id);
    $enviosListRes->execute();
    $enviosListRes = $enviosListRes->get_result();
}

// Pedidos recientes por preparar
$pedidosListRes = $conn->query("SELECT p.id, p.fecha_pedido, p.total, up.nombre, up.apellido
                               FROM pedidos p
                               INNER JOIN usuario_perfil up ON p.cliente_id = up.usuario_id
                               WHERE p.estado = 'pendiente'
                               ORDER BY p.id ASC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard CEDIS - ECOALI</title>
    
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

        /* Metrics grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 40px;
        }

        .metric-card {
            background: var(--card-bg-dark);
            border: 1px solid var(--border-dark);
            border-radius: 24px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: var(--transition-fast);
        }

        .metric-card:hover {
            transform: translateY(-5px);
            border-color: rgba(255, 138, 0, 0.3);
            box-shadow: var(--shadow-premium);
        }

        .metric-card .icon {
            font-size: 28px;
            margin-bottom: 15px;
            background: rgba(255, 255, 255, 0.05);
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: grid;
            place-items: center;
        }

        .metric-card .value {
            font-size: 32px;
            font-weight: 800;
            margin: 5px 0;
            color: var(--text-light);
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .metric-card .label {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Dashboard content tables */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .section-card {
            background: var(--card-bg-dark);
            border: 1px solid var(--border-dark);
            border-radius: 24px;
            padding: 30px;
            box-sizing: border-box;
        }

        .section-card h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 20px;
            font-weight: 800;
            color: var(--text-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-card h2 a {
            font-size: 13px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            transition: var(--transition-fast);
        }

        .section-card h2 a:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 14px 10px;
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

        .badge-ruta {
            background: rgba(57, 229, 93, 0.15);
            color: #39e55d;
            border: 1px solid rgba(57, 229, 93, 0.25);
        }

        .badge-recibido {
            background: rgba(0, 150, 255, 0.15);
            color: #33b5e5;
            border: 1px solid rgba(0, 150, 255, 0.25);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 991px) {
            .sidebar {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 20px 16px 80px !important;
            }
            .content-grid {
                grid-template-columns: 1fr;
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
            <a href="dashboard_cedis.php" class="active">📊 Panel General</a>
            <a href="recepcion_cedis.php">🚚 Recepción Envíos</a>
            <a href="preparacion_cedis.php">📦 Preparar Pedidos</a>
            <a href="inventario_cedis.php">🥚 Inventario & Mermas</a>
        </nav>
        
        <a href="logout.php" class="logout-link">🚪 Cerrar Sesión</a>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div>
                <h1>Hola, <?php echo htmlspecialchars($_SESSION["nombre"] ?? "Operador"); ?></h1>
                <p style="color: var(--text-muted); margin: 5px 0 0 0;">Panel de control y despacho logístico.</p>
            </div>
            <div class="cedis-badge">
                📍 <?php echo htmlspecialchars($cedis_nombre); ?>
            </div>
        </header>

        <!-- Metrics Grid -->
        <section class="metrics-grid">
            <div class="metric-card">
                <div class="icon" style="color: #ff8a00;">🥚</div>
                <div class="value"><?php echo number_format($stockTotal); ?></div>
                <div class="label">Stock Huevos (Unds)</div>
            </div>
            <div class="metric-card">
                <div class="icon" style="color: #39e55d;">🚚</div>
                <div class="value"><?php echo $enviosPendientes; ?></div>
                <div class="label">Envíos Pendientes</div>
            </div>
            <div class="metric-card">
                <div class="icon" style="color: #0096ff;">📦</div>
                <div class="value"><?php echo $pedidosPendientes; ?></div>
                <div class="label">Pedidos por Preparar</div>
            </div>
            <div class="metric-card">
                <div class="icon" style="color: #ff4a4a;">⚠️</div>
                <div class="value"><?php echo number_format($mermasTotal); ?></div>
                <div class="label">Mermas de Almacén</div>
            </div>
        </section>

        <!-- Content Grid -->
        <section class="content-grid">
            
            <!-- Envíos Recientes -->
            <div class="section-card">
                <h2>
                    <span>🚚 Envíos Recientes</span>
                    <a href="recepcion_cedis.php">Ver todo →</a>
                </h2>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Proveedor</th>
                            <th>Recolección</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($enviosListRes && $enviosListRes->num_rows > 0): ?>
                            <?php while ($e = $enviosListRes->fetch_assoc()): ?>
                                <tr>
                                    <td><strong>#ENT-<?php echo str_pad($e["id"], 3, "0", STR_PAD_LEFT); ?></strong></td>
                                    <td><?php echo htmlspecialchars($e["nombre_empresa"]); ?></td>
                                    <td><?php echo date("d/m/Y", strtotime($e["fecha_recoleccion"])); ?></td>
                                    <td>
                                        <?php 
                                        $lbl = $e["estado"];
                                        $class = "badge-pendiente";
                                        if ($lbl === "en_ruta") { $class = "badge-ruta"; $lbl = "En Ruta"; }
                                        elseif ($lbl === "recibido") { $class = "badge-recibido"; $lbl = "Recibido"; }
                                        else { $lbl = "Pendiente"; }
                                        ?>
                                        <span class="badge <?php echo $class; ?>"><?php echo $lbl; ?></span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                    No hay envíos registrados para este CEDIS.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pedidos Pendientes de Preparación -->
            <div class="section-card">
                <h2>
                    <span>📦 Pedidos Pendientes</span>
                    <a href="preparacion_cedis.php">Ver todo →</a>
                </h2>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Cliente</th>
                            <th>Fecha Pedido</th>
                            <th>Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pedidosListRes && $pedidosListRes->num_rows > 0): ?>
                            <?php while ($p = $pedidosListRes->fetch_assoc()): ?>
                                <tr>
                                    <td><strong>#PED-<?php echo str_pad($p["id"], 3, "0", STR_PAD_LEFT); ?></strong></td>
                                    <td><?php echo htmlspecialchars($p["nombre"] . " " . $p["apellido"]); ?></td>
                                    <td><?php echo date("d/m/Y H:i", strtotime($p["fecha_pedido"])); ?></td>
                                    <td>$<?php echo number_format($p["total"], 2); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                    ¡Excelente! No hay pedidos pendientes por empaquetar.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </section>
    </main>
</div>

<!-- Mobile Navigation Bar -->
<nav class="mobile-nav" style="display: none;">
    <a href="dashboard_cedis.php" class="mobile-nav-btn active">
        <span>📊</span>
        Panel
    </a>
    <a href="recepcion_cedis.php" class="mobile-nav-btn">
        <span>🚚</span>
        Recepción
    </a>
    <a href="preparacion_cedis.php" class="mobile-nav-btn">
        <span>📦</span>
        Preparación
    </a>
    <a href="inventario_cedis.php" class="mobile-nav-btn">
        <span>🥚</span>
        Inventario
    </a>
</nav>

</body>
</html>
