<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - PORTAL CEDIS: RECEPCIÓN DE ENVÍOS
 * --------------------------------------------------------------------------------
 * Permite auditar y recibir físicamente la mercancía enviada por los proveedores.
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

// Envíos pendientes de recepcionar
if ($rol_actual === 1) {
    $enviosPendRes = $conn->query("SELECT e.id, e.fecha_recoleccion, e.estado, p.nombre_empresa, c.nombre AS cedis_nombre 
                                  FROM entregas_cedis e
                                  INNER JOIN proveedores p ON e.proveedor_id = p.id
                                  INNER JOIN cedis c ON e.cedis_id = c.id
                                  WHERE e.estado IN ('pendiente', 'en_ruta')
                                  ORDER BY e.id DESC");
} else {
    $enviosPendRes = $conn->prepare("SELECT e.id, e.fecha_recoleccion, e.estado, p.nombre_empresa, c.nombre AS cedis_nombre 
                                    FROM entregas_cedis e
                                    INNER JOIN proveedores p ON e.proveedor_id = p.id
                                    INNER JOIN cedis c ON e.cedis_id = c.id
                                    WHERE e.cedis_id = ? AND e.estado IN ('pendiente', 'en_ruta')
                                    ORDER BY e.id DESC");
    $enviosPendRes->bind_param("i", $cedis_usuario_id);
    $enviosPendRes->execute();
    $enviosPendRes = $enviosPendRes->get_result();
}

// Historial de envíos recibidos
if ($rol_actual === 1) {
    $enviosRecRes = $conn->query("SELECT e.id, e.fecha_recoleccion, e.fecha_recepcion, e.estado, p.nombre_empresa, c.nombre AS cedis_nombre 
                                 FROM entregas_cedis e
                                 INNER JOIN proveedores p ON e.proveedor_id = p.id
                                 INNER JOIN cedis c ON e.cedis_id = c.id
                                 WHERE e.estado = 'recibido'
                                 ORDER BY e.fecha_recepcion DESC LIMIT 25");
} else {
    $enviosRecRes = $conn->prepare("SELECT e.id, e.fecha_recoleccion, e.fecha_recepcion, e.estado, p.nombre_empresa, c.nombre AS cedis_nombre 
                                   FROM entregas_cedis e
                                   INNER JOIN proveedores p ON e.proveedor_id = p.id
                                   INNER JOIN cedis c ON e.cedis_id = c.id
                                   WHERE e.cedis_id = ? AND e.estado = 'recibido'
                                   ORDER BY e.fecha_recepcion DESC LIMIT 25");
    $enviosRecRes->bind_param("i", $cedis_usuario_id);
    $enviosRecRes->execute();
    $enviosRecRes = $enviosRecRes->get_result();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recepción de Envíos - ECOALI</title>
    
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
            max-width: 700px;
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

        .modal-lotes-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 25px;
        }

        .lote-item-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 16px;
            padding: 16px;
        }

        .lote-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            padding-bottom: 8px;
        }

        .lote-header strong {
            color: var(--primary);
        }

        .lote-header span {
            color: var(--text-muted);
            font-size: 13px;
        }

        .inputs-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            align-items: center;
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .input-group label {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 800;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }

        .input-field {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 8px 12px;
            color: white;
            font-weight: 700;
            outline: none;
            width: 100%;
            box-sizing: border-box;
        }

        .input-field:focus {
            border-color: var(--primary);
        }

        .viable-badge {
            background: rgba(23, 106, 33, 0.1);
            border: 1px solid rgba(23, 106, 33, 0.25);
            color: #39e55d;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            font-weight: 800;
            font-size: 15px;
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
            background: var(--primary);
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(255,138,0,0.2);
            transition: var(--transition-fast);
        }

        .btn-submit:hover {
            background: var(--primary-hover);
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
            .inputs-row {
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
            <a href="recepcion_cedis.php" class="active">🚚 Recepción Envíos</a>
            <a href="preparacion_cedis.php">📦 Preparar Pedidos</a>
            <a href="inventario_cedis.php">🥚 Inventario & Mermas</a>
        </nav>
        
        <a href="logout.php" class="logout-link">🚪 Cerrar Sesión</a>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div>
                <h1>Recepción de Envíos</h1>
                <p style="color: var(--text-muted); margin: 5px 0 0 0;">Verifica y audita los envíos de producción que llegan del campo.</p>
            </div>
            <div class="cedis-badge">
                📍 <?php echo htmlspecialchars($cedis_nombre); ?>
            </div>
        </header>

        <!-- Tab toggles -->
        <div class="tab-toggle-container">
            <button class="tab-btn active" onclick="switchTab('por_recibir')">Envíos por Recibir</button>
            <button class="tab-btn" onclick="switchTab('historial')">Historial de Recibidos</button>
        </div>

        <!-- Tab: Envíos por Recibir -->
        <section id="tab-por_recibir" class="section-card">
            <h2>🚚 Pendientes en Muelle</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Proveedor</th>
                        <th>Fecha de Recolección</th>
                        <th>CEDIS Destino</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($enviosPendRes && $enviosPendRes->num_rows > 0): ?>
                        <?php while ($e = $enviosPendRes->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#ENT-<?php echo str_pad($e["id"], 3, "0", STR_PAD_LEFT); ?></strong></td>
                                <td><?php echo htmlspecialchars($e["nombre_empresa"]); ?></td>
                                <td><?php echo date("d/m/Y", strtotime($e["fecha_recoleccion"])); ?></td>
                                <td><?php echo htmlspecialchars($e["cedis_nombre"]); ?></td>
                                <td>
                                    <?php 
                                    $lbl = $e["estado"];
                                    $class = "badge-pendiente";
                                    if ($lbl === "en_ruta") { $class = "badge-ruta"; $lbl = "En Ruta"; }
                                    ?>
                                    <span class="badge <?php echo $class; ?>"><?php echo $lbl; ?></span>
                                </td>
                                <td>
                                    <button class="btn-action" onclick="abrirModalRecepcion(<?php echo $e['id']; ?>)">
                                        📥 Registrar Recepción
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">
                                No hay envíos pendientes por recibir en muelle.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- Tab: Historial -->
        <section id="tab-historial" class="section-card" style="display: none;">
            <h2>📜 Historial Recibidos (Últimos 25)</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Proveedor</th>
                        <th>Fecha Recolección</th>
                        <th>Fecha Recepción</th>
                        <th>CEDIS Destino</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($enviosRecRes && $enviosRecRes->num_rows > 0): ?>
                        <?php while ($e = $enviosRecRes->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#ENT-<?php echo str_pad($e["id"], 3, "0", STR_PAD_LEFT); ?></strong></td>
                                <td><?php echo htmlspecialchars($e["nombre_empresa"]); ?></td>
                                <td><?php echo date("d/m/Y", strtotime($e["fecha_recoleccion"])); ?></td>
                                <td><?php echo date("d/m/Y H:i", strtotime($e["fecha_recepcion"])); ?></td>
                                <td><?php echo htmlspecialchars($e["cedis_nombre"]); ?></td>
                                <td>
                                    <span class="badge badge-recibido">Recibido</span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">
                                Aún no has recibido ningún envío.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

    </main>
</div>

<!-- Modal: Auditar Recepción -->
<div class="modal-overlay" id="modalRecepcion">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modal-titulo">Auditar Envío #ENT-000</h3>
            <button class="modal-close" onclick="cerrarModalRecepcion()">&times;</button>
        </div>
        
        <form id="formRecepcion" onsubmit="procesarRecepcion(event)">
            <input type="hidden" id="modal_entrega_id" value="">
            
            <div class="modal-lotes-list" id="modal_lotes_cuerpo">
                <!-- Se poblará dinámicamente -->
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="cerrarModalRecepcion()">Cancelar</button>
                <button type="submit" class="btn-submit">Confirmar Ingreso a Almacén</button>
            </div>
        </form>
    </div>
</div>

<!-- Mobile Navigation Bar -->
<nav class="mobile-nav" style="display: none;">
    <a href="dashboard_cedis.php" class="mobile-nav-btn">
        <span>📊</span>
        Panel
    </a>
    <a href="recepcion_cedis.php" class="mobile-nav-btn active">
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

<script>
function switchTab(tabId) {
    // Esconder todas las secciones
    document.getElementById('tab-por_recibir').style.display = 'none';
    document.getElementById('tab-historial').style.display = 'none';
    
    // Quitar active a todos los botones
    const btns = document.querySelectorAll('.tab-btn');
    btns.forEach(btn => btn.classList.remove('active'));
    
    // Mostrar pestaña seleccionada
    document.getElementById('tab-' + tabId).style.display = 'block';
    
    // Poner active al botón clickeado
    event.currentTarget.classList.add('active');
}

function abrirModalRecepcion(entregaId) {
    document.getElementById('modal_entrega_id').value = entregaId;
    document.getElementById('modal-titulo').innerText = 'Auditar Envío #ENT-' + String(entregaId).padStart(3, '0');
    
    const cuerpoLotes = document.getElementById('modal_lotes_cuerpo');
    cuerpoLotes.innerHTML = '<p style="color: var(--text-muted); text-align: center;">Cargando detalles de lotes...</p>';
    
    document.getElementById('modalRecepcion').classList.add('active');
    
    // Fetch lot details
    fetch('forms/cedis_acciones.php?accion=obtener_detalle_entrega&entrega_id=' + entregaId)
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success') {
                cuerpoLotes.innerHTML = '';
                
                res.data.forEach(l => {
                    const card = document.createElement('div');
                    card.className = 'lote-item-card';
                    card.dataset.loteId = l.lote_id;
                    card.dataset.total = l.cantidad;
                    
                    card.innerHTML = `
                        <div class="lote-header">
                            <strong>Lote: ${l.codigo_lote}</strong>
                            <span>${l.tipo_huevo} (${l.tamano})</span>
                        </div>
                        <div class="inputs-row">
                            <div class="input-group">
                                <label>Cantidad Enviada</label>
                                <div class="viable-badge" style="background: rgba(255,255,255,0.02); color: var(--text-light); border-color: rgba(255,255,255,0.1)">
                                    ${l.cantidad} huevos
                                </div>
                            </div>
                            <div class="input-group">
                                <label>Mermas (Roto)</label>
                                <input type="number" class="input-field val-merma" value="0" min="0" max="${l.cantidad}" oninput="recalcularViables(this)">
                            </div>
                            <div class="input-group">
                                <label>No Viables</label>
                                <input type="number" class="input-field val-no-viable" value="0" min="0" max="${l.cantidad}" oninput="recalcularViables(this)">
                            </div>
                        </div>
                        <div style="margin-top: 12px; display: flex; justify-content: flex-end;">
                            <div class="viable-badge val-resultado" style="padding: 6px 12px; font-size: 13px;">
                                Viable: ${l.cantidad} huevos
                            </div>
                        </div>
                    `;
                    cuerpoLotes.appendChild(card);
                });
            } else {
                cuerpoLotes.innerHTML = `<p style="color: #ff4a4a; text-align: center;">${res.message}</p>`;
            }
        })
        .catch(err => {
            cuerpoLotes.innerHTML = '<p style="color: #ff4a4a; text-align: center;">Error de conexión.</p>';
        });
}

function cerrarModalRecepcion() {
    document.getElementById('modalRecepcion').classList.remove('active');
}

function recalcularViables(input) {
    const card = input.closest('.lote-item-card');
    const total = parseInt(card.dataset.total) || 0;
    
    const valMermaInput = card.querySelector('.val-merma');
    const valNoViableInput = card.querySelector('.val-no-viable');
    
    let merma = parseInt(valMermaInput.value) || 0;
    let noViable = parseInt(valNoViableInput.value) || 0;
    
    // Evitar que excedan el total
    if (merma + noViable > total) {
        // Reducir el valor ingresado actual
        if (input.classList.contains('val-merma')) {
            input.value = total - noViable;
            merma = total - noViable;
        } else {
            input.value = total - merma;
            noViable = total - merma;
        }
    }
    
    const viable = total - merma - noViable;
    const badge = card.querySelector('.val-resultado');
    badge.innerText = `Viable: ${viable} huevos`;
    
    // Cambiar color si hay pérdidas
    if (viable < total) {
        badge.style.background = 'rgba(255, 138, 0, 0.1)';
        badge.style.borderColor = 'rgba(255, 138, 0, 0.25)';
        badge.style.color = '#ff9f29';
    } else {
        badge.style.background = 'rgba(23, 106, 33, 0.1)';
        badge.style.borderColor = 'rgba(23, 106, 33, 0.25)';
        badge.style.color = '#39e55d';
    }
}

function procesarRecepcion(e) {
    e.preventDefault();
    
    const entregaId = parseInt(document.getElementById('modal_entrega_id').value) || 0;
    const cards = document.querySelectorAll('.lote-item-card');
    
    const lotesData = [];
    cards.forEach(card => {
        const loteId = parseInt(card.dataset.loteId);
        const merma = parseInt(card.querySelector('.val-merma').value) || 0;
        const noViable = parseInt(card.querySelector('.val-no-viable').value) || 0;
        
        lotesData.push({
            lote_id: loteId,
            merma: merma,
            no_viable: noViable
        });
    });
    
    const btnSubmit = e.target.querySelector('button[type="submit"]');
    btnSubmit.disabled = true;
    btnSubmit.innerText = 'Procesando...';
    
    fetch('forms/cedis_acciones.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            accion: 'recepcion',
            entrega_id: entregaId,
            lotes: lotesData
        })
    })
    .then(res => res.json())
    .then(res => {
        if (res.status === 'success') {
            alert(res.message);
            window.location.reload();
        } else {
            alert(res.message);
            btnSubmit.disabled = false;
            btnSubmit.innerText = 'Confirmar Ingreso a Almacén';
        }
    })
    .catch(err => {
        alert('Error de conexión.');
        btnSubmit.disabled = false;
        btnSubmit.innerText = 'Confirmar Ingreso a Almacén';
    });
}
</script>

</body>
</html>
