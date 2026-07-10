<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - PORTAL CEDIS: INVENTARIO Y MERMAS
 * --------------------------------------------------------------------------------
 * Permite visualizar el stock físico disponible en el CEDIS y reportar incidencias
 * o mermas internas de producto dañado.
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

// Cargar lotes recibidos en este CEDIS
if ($rol_actual === 1) {
    $lotesQuery = "SELECT ih.id, ih.codigo_lote, ih.cantidad, ih.merma, ih.no_viable, ih.fecha_postura, ih.fecha_caducidad, ih.estado,
                          pr.tipo_huevo, pr.tamano, p.nombre_empresa, c.nombre AS cedis_nombre
                   FROM inventario_huevos ih
                   INNER JOIN detalle_entrega_cedis de_cedis ON ih.id = de_cedis.lote_id
                   INNER JOIN entregas_cedis ec ON de_cedis.entrega_id = ec.id
                   INNER JOIN cedis c ON ec.cedis_id = c.id
                   INNER JOIN productos pr ON ih.producto_id = pr.id
                   INNER JOIN proveedores p ON ih.proveedor_id = p.id
                   WHERE ec.estado = 'recibido'
                   ORDER BY ih.id DESC";
    $lotesRes = $conn->query($lotesQuery);
} else {
    $lotesQuery = "SELECT ih.id, ih.codigo_lote, ih.cantidad, ih.merma, ih.no_viable, ih.fecha_postura, ih.fecha_caducidad, ih.estado,
                          pr.tipo_huevo, pr.tamano, p.nombre_empresa, c.nombre AS cedis_nombre
                   FROM inventario_huevos ih
                   INNER JOIN detalle_entrega_cedis de_cedis ON ih.id = de_cedis.lote_id
                   INNER JOIN entregas_cedis ec ON de_cedis.entrega_id = ec.id
                   INNER JOIN cedis c ON ec.cedis_id = c.id
                   INNER JOIN productos pr ON ih.producto_id = pr.id
                   INNER JOIN proveedores p ON ih.proveedor_id = p.id
                   WHERE ec.cedis_id = ? AND ec.estado = 'recibido'
                   ORDER BY ih.id DESC";
    $lotesRes = $conn->prepare($lotesQuery);
    $lotesRes->bind_param("i", $cedis_usuario_id);
    $lotesRes->execute();
    $lotesRes = $lotesRes->get_result();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario & Mermas - ECOALI</title>
    
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

        .badge-disponible {
            background: rgba(57, 229, 93, 0.15);
            color: #39e55d;
            border: 1px solid rgba(57, 229, 93, 0.25);
        }

        .badge-bajostock {
            background: rgba(255, 138, 0, 0.15);
            color: #ff9f29;
            border: 1px solid rgba(255, 138, 0, 0.25);
        }

        /* Action Buttons */
        .btn-action {
            background: rgba(255, 74, 74, 0.15);
            border: 1px solid rgba(255, 74, 74, 0.3);
            color: #ff4a4a;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .btn-action:hover {
            background: rgba(255, 74, 74, 0.3);
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
            max-width: 500px;
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

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 20px;
        }

        .form-group label {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }

        .form-select,
        .input-field {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
            font-size: 14px;
            font-weight: 600;
            outline: none;
            width: 100%;
            box-sizing: border-box;
        }

        .form-select:focus,
        .input-field:focus {
            border-color: var(--primary);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 10px;
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
            background: #ff4a4a;
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(255, 74, 74, 0.2);
            transition: var(--transition-fast);
        }

        .btn-submit:hover {
            background: #e04040;
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
            <a href="preparacion_cedis.php">📦 Preparar Pedidos</a>
            <a href="inventario_cedis.php" class="active">🥚 Inventario & Mermas</a>
        </nav>
        
        <a href="logout.php" class="logout-link">🚪 Cerrar Sesión</a>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div>
                <h1>Inventario & Mermas</h1>
                <p style="color: var(--text-muted); margin: 5px 0 0 0;">Visualiza existencias por lote y reporta mermas internas de almacén.</p>
            </div>
            <div class="cedis-badge">
                📍 <?php echo htmlspecialchars($cedis_nombre); ?>
            </div>
        </header>

        <!-- Inventario Físico -->
        <section class="section-card">
            <h2>🥚 Lotes de Huevo en Almacén</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Código Lote</th>
                        <th>Proveedor</th>
                        <th>Tipo Huevo</th>
                        <th>Tamaño</th>
                        <th style="text-align: center;">Stock Disponible</th>
                        <th style="text-align: center;">Mermas</th>
                        <th style="text-align: center;">No Viables</th>
                        <th>Vencimiento</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($lotesRes && $lotesRes->num_rows > 0): ?>
                        <?php while ($l = $lotesRes->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($l["codigo_lote"]); ?></strong></td>
                                <td><?php echo htmlspecialchars($l["nombre_empresa"]); ?></td>
                                <td><?php echo htmlspecialchars($l["tipo_huevo"]); ?></td>
                                <td><?php echo htmlspecialchars($l["tamano"]); ?></td>
                                <td style="text-align: center; font-size: 15px; font-weight: 800; color: var(--primary);">
                                    <?php echo number_format($l["cantidad"]); ?>
                                </td>
                                <td style="text-align: center; color: #ff4a4a; font-weight: 700;">
                                    <?php echo number_format($l["merma"]); ?>
                                </td>
                                <td style="text-align: center; color: #a39585;">
                                    <?php echo number_format($l["no_viable"]); ?>
                                </td>
                                <td>
                                    <?php 
                                    $caduca = strtotime($l["fecha_caducidad"]);
                                    $diasRestantes = floor(($caduca - time()) / (60 * 60 * 24));
                                    $col = "white";
                                    if ($diasRestantes < 0) $col = "#ff4a4a";
                                    elseif ($diasRestantes <= 7) $col = "#ff9f29";
                                    ?>
                                    <span style="color: <?php echo $col; ?>; font-weight: 600;">
                                        <?php echo date("d/m/Y", $caduca); ?>
                                        <?php if ($diasRestantes < 0): ?> (Caducado)
                                        <?php elseif ($diasRestantes <= 7): ?> (Expira pronto)
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($l["cantidad"] > 0): ?>
                                        <button class="btn-action" onclick="abrirModalMerma(<?php echo $l['id']; ?>, '${l.codigo_lote}', <?php echo $l['cantidad']; ?>)">
                                            ⚠️ Registrar Merma
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px; font-weight: 700;">AGOTADO</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 45px;">
                                No hay existencias físicas registradas en este Centro de Distribución.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

    </main>
</div>

<!-- Modal: Reportar Merma Almacén -->
<div class="modal-overlay" id="modalMerma">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Registrar Merma Local</h3>
            <button class="modal-close" onclick="cerrarModalMerma()">&times;</button>
        </div>
        
        <form id="formMerma" onsubmit="procesarMermaLocal(event)">
            <input type="hidden" id="merma_lote_id" value="">
            
            <div class="form-group">
                <label>Código del Lote</label>
                <div class="input-field" id="lbl_merma_lote_codigo" style="background: rgba(255,255,255,0.02); border-color: rgba(255,255,255,0.05); color: var(--text-muted);">
                    -
                </div>
            </div>

            <div class="form-group">
                <label>Stock Disponible actual</label>
                <div class="input-field" id="lbl_merma_lote_stock" style="background: rgba(255,255,255,0.02); border-color: rgba(255,255,255,0.05); color: var(--primary);">
                    -
                </div>
            </div>

            <div class="form-group">
                <label>Cantidad de huevos rotos/dañados *</label>
                <input type="number" id="merma_cantidad" class="input-field" min="1" required>
            </div>

            <div class="form-group">
                <label>Motivo o Descripción *</label>
                <input type="text" id="merma_motivo" class="input-field" placeholder="Ej. Caída de caja durante estibado" required>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="cerrarModalMerma()">Cancelar</button>
                <button type="submit" class="btn-submit">Registrar Merma</button>
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
    <a href="recepcion_cedis.php" class="mobile-nav-btn">
        <span>🚚</span>
        Recepción
    </a>
    <a href="preparacion_cedis.php" class="mobile-nav-btn">
        <span>📦</span>
        Preparación
    </a>
    <a href="inventario_cedis.php" class="mobile-nav-btn active">
        <span>🥚</span>
        Inventario
    </a>
</nav>

<script>
let maxStockDisponible = 0;

function abrirModalMerma(loteId, codigoLote, stock) {
    // Si no viene el código del lote, buscarlo en la fila correspondiente
    if (codigoLote.includes('l.codigo_lote')) {
        // Encontrar la fila que corresponde al botón clickeado
        const tr = event.currentTarget.closest('tr');
        codigoLote = tr.cells[0].innerText;
    }
    
    document.getElementById('merma_lote_id').value = loteId;
    document.getElementById('lbl_merma_lote_codigo').innerText = codigoLote;
    document.getElementById('lbl_merma_lote_stock').innerText = stock + ' huevos';
    document.getElementById('merma_cantidad').max = stock;
    document.getElementById('merma_cantidad').value = '';
    document.getElementById('merma_motivo').value = '';
    maxStockDisponible = stock;
    
    document.getElementById('modalMerma').classList.add('active');
}

function cerrarModalMerma() {
    document.getElementById('modalMerma').classList.remove('active');
}

function procesarMermaLocal(e) {
    e.preventDefault();
    
    const loteId = parseInt(document.getElementById('merma_lote_id').value) || 0;
    const cantidad = parseInt(document.getElementById('merma_cantidad').value) || 0;
    const motivo = document.getElementById('merma_motivo').value.trim();
    
    if (loteId <= 0 || cantidad <= 0 || !motivo) {
        alert('Por favor, ingresa todos los campos.');
        return;
    }
    
    if (cantidad > maxStockDisponible) {
        alert('La cantidad de merma no puede ser mayor al stock disponible (' + maxStockDisponible + ' huevos).');
        return;
    }
    
    const btnSubmit = e.target.querySelector('button[type="submit"]');
    btnSubmit.disabled = true;
    btnSubmit.innerText = 'Registrando...';
    
    fetch('forms/cedis_acciones.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            accion: 'merma_local',
            lote_id: loteId,
            cantidad: cantidad,
            motivo: motivo
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
            btnSubmit.innerText = 'Registrar Merma';
        }
    })
    .catch(err => {
        alert('Error de conexión.');
        btnSubmit.disabled = false;
        btnSubmit.innerText = 'Registrar Merma';
    });
}
</script>

</body>
</html>
