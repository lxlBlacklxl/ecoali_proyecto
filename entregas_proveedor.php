<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - SOLICITUDES DE ENTREGA AL CEDIS
 * --------------------------------------------------------------------------------
 */

session_start();
require_once "forms/conexion.php";

// 1. CONTROL DE ACCESO
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 3) {
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION["usuario_id"];

// 2. OBTENER PROVEEDOR_ID
$stmtProv = $conn->prepare("SELECT id, nombre_empresa FROM proveedores WHERE usuario_id = ?");
$stmtProv->bind_param("i", $usuario_id);
$stmtProv->execute();
$resProv = $stmtProv->get_result();
if ($resProv->num_rows === 0) {
    die("Error: Su usuario no está vinculado a ningún proveedor.");
}
$provRow = $resProv->fetch_assoc();
$proveedor_id = (int)$provRow["id"];
$nombre_empresa = $provRow["nombre_empresa"];
$stmtProv->close();

$mensaje_exito = "";
$mensaje_error = "";

// 3. PROCESAR SOLICITUD DE ENTREGA (POST)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";

    if ($accion === "crear_entrega") {
        $cedis_id = (int)($_POST["cedis_id"] ?? 0);
        $fecha_recoleccion = trim($_POST["fecha_recoleccion"] ?? "");
        $observaciones = trim($_POST["observaciones"] ?? "");
        $cantidades_lote = $_POST["lotes_cantidad"] ?? []; // Array of lote_id => cantidad

        // Filtrar solo cantidades > 0
        $lotes_a_entregar = [];
        foreach ($cantidades_lote as $l_id => $cant) {
            $cant = (int)$cant;
            if ($cant > 0) {
                $lotes_a_entregar[$l_id] = $cant;
            }
        }

        if ($cedis_id <= 0 || empty($fecha_recoleccion) || empty($lotes_a_entregar)) {
            $mensaje_error = "Debe seleccionar un CEDIS, una fecha de recolección y al menos un lote con cantidad mayor a cero.";
        } else {
            // Iniciar transacción
            $conn->begin_transaction();
            try {
                // Insertar cabecera de entrega
                $stmtEnt = $conn->prepare("INSERT INTO entregas_cedis (proveedor_id, cedis_id, fecha_recoleccion, estado, observaciones) VALUES (?, ?, ?, 'pendiente', ?)");
                $stmtEnt->bind_param("iiss", $proveedor_id, $cedis_id, $fecha_recoleccion, $observaciones);
                $stmtEnt->execute();
                $entrega_id = $conn->insert_id;
                $stmtEnt->close();

                // Procesar cada lote
                foreach ($lotes_a_entregar as $lote_id => $cant) {
                    // Validar stock disponible del lote y pertenencia
                    $stmtL = $conn->prepare("SELECT cantidad, codigo_lote FROM inventario_huevos WHERE id = ? AND proveedor_id = ? FOR UPDATE");
                    $stmtL->bind_param("ii", $lote_id, $proveedor_id);
                    $stmtL->execute();
                    $resL = $stmtL->get_result();
                    if ($resL->num_rows === 0) {
                        throw new Exception("Uno de los lotes seleccionados no es válido o no pertenece a su cuenta.");
                    }
                    $lote_data = $resL->fetch_assoc();
                    $stock_disponible = (int)$lote_data["cantidad"];
                    $codigo_lote = $lote_data["codigo_lote"];
                    $stmtL->close();

                    if ($stock_disponible < $cant) {
                        throw new Exception("Stock insuficiente en el lote '$codigo_lote': solicita entregar $cant pero solo quedan $stock_disponible disponibles.");
                    }

                    // Insertar detalle
                    $stmtDet = $conn->prepare("INSERT INTO detalle_entrega_cedis (entrega_id, lote_id, cantidad) VALUES (?, ?, ?)");
                    $stmtDet->bind_param("iii", $entrega_id, $lote_id, $cant);
                    $stmtDet->execute();
                    $stmtDet->close();

                    // Descontar del lote
                    $nuevo_stock = $stock_disponible - $cant;
                    
                    // Si se agota el lote o se solicita para entrega, actualizamos su estado
                    // Lote queda en 'pendiente_entrega' si se solicitó
                    $nuevo_estado = ($nuevo_stock === 0) ? 'pendiente_entrega' : 'activo';
                    
                    $stmtUpL = $conn->prepare("UPDATE inventario_huevos SET cantidad = ?, estado = ? WHERE id = ?");
                    $stmtUpL->bind_param("isi", $nuevo_stock, $nuevo_estado, $lote_id);
                    $stmtUpL->execute();
                    $stmtUpL->close();
                }

                // Registrar en bitácora
                registrar_bitacora("Entrega solicitada", "Logística", "El proveedor solicitó la entrega #ENT-" . str_pad($entrega_id, 3, "0", STR_PAD_LEFT) . " para el CEDIS ID $cedis_id.");

                $conn->commit();
                $mensaje_exito = "¡Solicitud de entrega #ENT-" . str_pad($entrega_id, 3, "0", STR_PAD_LEFT) . " creada con éxito!";
            } catch (Exception $e) {
                $conn->rollback();
                $mensaje_error = "Error al procesar la entrega: " . $e->getMessage();
            }
        }
    } elseif ($accion === "cancelar_entrega") {
        $entrega_id = (int)($_POST["entrega_id"] ?? 0);

        if ($entrega_id <= 0) {
            $mensaje_error = "ID de entrega inválido.";
        } else {
            // Verificar propiedad y estado 'pendiente'
            $stmtCheck = $conn->prepare("SELECT estado FROM entregas_cedis WHERE id = ? AND proveedor_id = ?");
            $stmtCheck->bind_param("ii", $entrega_id, $proveedor_id);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            if ($resCheck->num_rows === 0) {
                $mensaje_error = "Solicitud de entrega no encontrada.";
            } else {
                $estado = $resCheck->fetch_assoc()["estado"];
                if ($estado !== "pendiente") {
                    $mensaje_error = "No se puede cancelar esta entrega porque su estado actual es: " . strtoupper($estado);
                } else {
                    $conn->begin_transaction();
                    try {
                        // Devolver stock a los lotes correspondientes
                        $stmtDet = $conn->prepare("SELECT lote_id, cantidad FROM detalle_entrega_cedis WHERE entrega_id = ?");
                        $stmtDet->bind_param("i", $entrega_id);
                        $stmtDet->execute();
                        $resDet = $stmtDet->get_result();
                        
                        while ($det = $resDet->fetch_assoc()) {
                            $lote_id = (int)$det["lote_id"];
                            $cant_devuelta = (int)$det["cantidad"];

                            // Devolver stock y restablecer estado a 'activo' (o 'proximo_caducar' si aplica)
                            $conn->query("UPDATE inventario_huevos 
                                          SET cantidad = cantidad + $cant_devuelta, 
                                              estado = IF(DATEDIFF(fecha_caducidad, CURDATE()) <= 1, 'proximo_caducar', 'activo') 
                                          WHERE id = $lote_id");
                        }
                        $stmtDet->close();

                        // Cancelar la entrega
                        $stmtCancel = $conn->prepare("UPDATE entregas_cedis SET estado = 'cancelado' WHERE id = ?");
                        $stmtCancel->bind_param("i", $entrega_id);
                        $stmtCancel->execute();
                        $stmtCancel->close();

                        // Registrar en bitácora
                        registrar_bitacora("Entrega cancelada", "Logística", "El proveedor canceló la entrega #ENT-" . str_pad($entrega_id, 3, "0", STR_PAD_LEFT) . ".");

                        $conn->commit();
                        $mensaje_exito = "¡La solicitud de entrega ha sido cancelada y el stock devuelto a su inventario!";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $mensaje_error = "Error al cancelar la entrega: " . $e->getMessage();
                    }
                }
            }
            $stmtCheck->close();
        }
    }
}

// 4. OBTENER INFORMACIÓN DE VISTAS
// A. Centros de Distribución (CEDIS)
$cedisList = [];
$resC = $conn->query("SELECT id, nombre, direccion FROM cedis WHERE activo = 1 ORDER BY nombre ASC");
while ($row = $resC->fetch_assoc()) {
    $cedisList[] = $row;
}

// B. Lotes disponibles para entregar
$lotesDisponibles = [];
$stmtLotes = $conn->prepare("SELECT ih.*, pr.nombre AS producto_nombre, pr.tamano AS producto_tamano 
                             FROM inventario_huevos ih 
                             INNER JOIN productos pr ON ih.producto_id = pr.id 
                             WHERE ih.proveedor_id = ? AND ih.cantidad > 0 AND ih.estado IN ('activo', 'proximo_caducar', 'disponible', 'bajo_stock')
                             ORDER BY ih.codigo_lote ASC");
$stmtLotes->bind_param("i", $proveedor_id);
$stmtLotes->execute();
$resLotes = $stmtLotes->get_result();
while ($row = $resLotes->fetch_assoc()) {
    $lotesDisponibles[] = $row;
}
$stmtLotes->close();

// C. Lista de solicitudes hechas por este proveedor
$entregasList = [];
$queryEnt = "SELECT e.*, c.nombre AS cedis_nombre, c.direccion AS cedis_direccion,
                    up.nombre AS rep_nombre, up.apellido AS rep_apellido, up.telefono AS rep_tel,
                    (SELECT SUM(cantidad) FROM detalle_entrega_cedis WHERE entrega_id = e.id) AS total_huevos,
                    (SELECT GROUP_CONCAT(CONCAT(d.cantidad, 'x ', p.codigo_lote) SEPARATOR ', ')
                     FROM detalle_entrega_cedis d
                     INNER JOIN inventario_huevos p ON d.lote_id = p.id
                     WHERE d.entrega_id = e.id) AS detalle_lotes
             FROM entregas_cedis e
             INNER JOIN cedis c ON e.cedis_id = c.id
             LEFT JOIN usuarios u ON e.repartidor_id = u.id
             LEFT JOIN usuario_perfil up ON u.id = up.usuario_id
             WHERE e.proveedor_id = ?
             ORDER BY e.id DESC";
$stmtList = $conn->prepare($queryEnt);
$stmtList->bind_param("i", $proveedor_id);
$stmtList->execute();
$resList = $stmtList->get_result();
while ($row = $resList->fetch_assoc()) {
    $entregasList[] = $row;
}
$stmtList->close();

$preselected_lote_id = (int)($_GET["lote_id"] ?? 0);

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Entregas al CEDIS - ECOALI</title>
  <link rel="stylesheet" href="assets/css/globals.css">
  <link rel="stylesheet" href="assets/css/proveedor.css?v=<?php echo time(); ?>">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
</head>
<body>

<div class="provider-container">
  
  <!-- Sidebar -->
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
      <a href="dashboard_proveedor.php" class="menu-link <?php echo ($current_page === 'dashboard_proveedor.php') ? 'active' : ''; ?>">
        <span>▦</span> <span>Dashboard</span>
      </a>
      <a href="produccion_proveedor.php" class="menu-link <?php echo ($current_page === 'produccion_proveedor.php') ? 'active' : ''; ?>">
        <span>🚜</span> <span>Producción</span>
      </a>
      <a href="lotes_proveedor.php" class="menu-link <?php echo ($current_page === 'lotes_proveedor.php') ? 'active' : ''; ?>">
        <span>▣</span> <span>Lotes</span>
      </a>
      <a href="inventario_proveedor.php" class="menu-link <?php echo ($current_page === 'inventario_proveedor.php') ? 'active' : ''; ?>">
        <span>📦</span> <span>Inventario</span>
      </a>
      <a href="entregas_proveedor.php" class="menu-link <?php echo ($current_page === 'entregas_proveedor.php') ? 'active' : ''; ?>">
        <span>🚚</span> <span>Entregas al CEDIS</span>
      </a>
      <a href="trazabilidad_proveedor.php" class="menu-link <?php echo ($current_page === 'trazabilidad_proveedor.php') ? 'active' : ''; ?>">
        <span>🔀</span> <span>Trazabilidad</span>
      </a>
      <a href="reportes_proveedor.php" class="menu-link <?php echo ($current_page === 'reportes_proveedor.php') ? 'active' : ''; ?>">
        <span>📊</span> <span>Reportes</span>
      </a>
      <a href="editar_perfil.php" class="menu-link <?php echo ($current_page === 'editar_perfil.php') ? 'active' : ''; ?>">
        <span>👤</span> <span>Mi perfil</span>
      </a>
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
        <h1>Entregas al CEDIS</h1>
        <p>Programe solicitudes de envío de huevo a los Centros de Distribución de ECOALI.</p>
      </div>
      <button class="header-btn" onclick="abrirModalCrear()">
        <span>🚚</span> Solicitar Recolección
      </button>
    </header>

    <!-- Notificaciones -->
    <?php if (!empty($mensaje_exito)): ?>
      <div class="alert-container">
        <div class="alert alert-success"><span>✓</span> <?php echo htmlspecialchars($mensaje_exito); ?></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($mensaje_error)): ?>
      <div class="alert-container">
        <div class="alert alert-danger"><span>✗</span> <?php echo htmlspecialchars($mensaje_error); ?></div>
      </div>
    <?php endif; ?>

    <!-- Buscador interactivo de Entregas -->
    <div class="section-search">
      <div class="search-input-wrapper">
        <input type="text" id="buscarEntrega" placeholder="Buscar por CEDIS, repartidor, lote o estado..." onkeyup="filtrarEntregas()">
      </div>
      
      <div class="filter-buttons">
        <button class="filter-btn active" onclick="filtrarEstadoTab('todos', this)">Todas</button>
        <button class="filter-btn" onclick="filtrarEstadoTab('pendiente', this)">Pendientes</button>
        <button class="filter-btn" onclick="filtrarEstadoTab('repartidor_asignado', this)">Asignadas</button>
        <button class="filter-btn" onclick="filtrarEstadoTab('en_ruta', this)">En Ruta</button>
        <button class="filter-btn" onclick="filtrarEstadoTab('recibido', this)">Recibidas</button>
        <button class="filter-btn" onclick="filtrarEstadoTab('rechazado', this)">Rechazadas</button>
      </div>
    </div>

    <!-- Tabla Historial de Entregas -->
    <div class="card" style="padding: 24px;">
      <h3>Historial de Entregas CEDIS</h3>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>CEDIS Destino</th>
              <th>Lotes e Items Incluidos</th>
              <th>Cantidad Total</th>
              <th>Fecha Solicitud</th>
              <th>Fecha Recolección</th>
              <th>Repartidor</th>
              <th>Estado</th>
              <th style="width: 120px; text-align: center;">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaCuerpo">
            <?php if (!empty($entregasList)): ?>
              <?php foreach ($entregasList as $row): 
                $estado = strtolower($row["estado"]);
                $cancellable = ($estado === "pendiente");
              ?>
                <tr class="row-entrega" data-estado="<?php echo $estado; ?>">
                  <td><strong>#ENT-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?></strong></td>
                  <td>
                    <strong>🏢 <?php echo htmlspecialchars($row["cedis_nombre"]); ?></strong><br>
                    <small style="color: var(--text-medium);"><?php echo htmlspecialchars($row["cedis_direccion"]); ?></small>
                  </td>
                  <td>
                    <span style="font-size: 12px; color: var(--text-dark); font-weight: bold;"><?php echo htmlspecialchars($row["detalle_lotes"] ?: "S/L"); ?></span>
                  </td>
                  <td><strong style="color: var(--secondary);"><?php echo number_format($row["total_huevos"]); ?> ud</strong></td>
                  <td><?php echo date("d/m/Y", strtotime($row["fecha_solicitud"])); ?></td>
                  <td><?php echo date("d/m/Y", strtotime($row["fecha_recoleccion"])); ?></td>
                  <td>
                    <?php if ($row["repartidor_id"]): ?>
                      🚚 <strong><?php echo htmlspecialchars($row["rep_nombre"] . ' ' . $row["rep_apellido"]); ?></strong><br>
                      <small style="color: var(--text-medium); font-size:11px;">Tlf: <?php echo htmlspecialchars($row["rep_tel"]); ?></small>
                    <?php else: ?>
                      <span style="color: var(--text-medium); font-style: italic;">Sin asignar</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge-status <?php echo $estado; ?>"><?php echo htmlspecialchars($row["estado"]); ?></span>
                    <?php if ($estado === 'rechazado' && !empty($row["motivo_rechazo"])): ?>
                      <div style="font-size: 10px; color:#b02500; font-weight:bold; margin-top:4px;">Motivo: <?php echo htmlspecialchars($row["motivo_rechazo"]); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="display: flex; gap: 8px; justify-content: center;">
                      <?php if ($cancellable): ?>
                        <button class="btn-submit btn-danger" style="height:32px; font-size:11px; padding:0 10px; border-radius:8px;" onclick="confirmarCancelacion(<?php echo $row['id']; ?>)">Cancelar</button>
                      <?php else: ?>
                        <button class="action-btn" disabled style="background: #f5f5f5; color:#bbb; cursor:not-allowed;" title="Ya está asignada, en ruta o procesada">🔒</button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" style="text-align: center; padding: 30px; color: var(--text-medium);">No se registran solicitudes de entrega al CEDIS.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Paginación -->
      <div class="pagination-wrapper">
        <div class="pagination-text" id="paginacionTexto">MOSTRANDO ENTREGAS</div>
        <div class="pagination-buttons" id="paginacionContenedor"></div>
      </div>
    </div>

  </main>

  <!-- Mobile Nav -->
  <nav class="mobile-nav">
    <a href="dashboard_proveedor.php" class="mobile-nav-btn">
      <span>▦</span>
      <span>Dashboard</span>
    </a>
    <a href="produccion_proveedor.php" class="mobile-nav-btn">
      <span>🚜</span>
      <span>Producción</span>
    </a>
    <a href="lotes_proveedor.php" class="mobile-nav-btn">
      <span>▣</span>
      <span>Lotes</span>
    </a>
    <a href="inventario_proveedor.php" class="mobile-nav-btn">
      <span>📦</span>
      <span>Inventario</span>
    </a>
    <a href="entregas_proveedor.php" class="mobile-nav-btn active">
      <span>🚚</span>
      <span>Entregas</span>
    </a>
  </nav>

</div>

<!-- ==========================================
     MODALES (SOLICITAR, CANCELAR)
     ========================================== -->

<!-- Modal Solicitar Entrega -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container" style="max-width: 600px;">
    <div class="modal-header">
      <div class="modal-title">Crear Solicitud de Entrega</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    
    <?php if (empty($lotesDisponibles)): ?>
      <div style="text-align: center; padding: 30px 20px;">
        <span style="font-size:48px;">🥚</span>
        <h4 style="margin: 15px 0; color: #b02500;">No hay lotes con stock</h4>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin-bottom: 24px;">
          Actualmente no cuenta con lotes de huevos con cantidad disponible en su inventario. Por favor registre postura primero.
        </p>
        <a href="produccion_proveedor.php" class="btn-submit" style="text-decoration:none;">Registrar Producción</a>
      </div>
    <?php else: ?>
      <form action="entregas_proveedor.php" method="POST">
        <input type="hidden" name="accion" value="crear_entrega">
        
        <div class="form-grid" style="grid-template-columns: 1fr;">
          <div class="form-grid" style="grid-template-columns: 1.2fr 1fr; gap:12px;">
            <div class="form-group">
              <label>CEDIS Destino *</label>
              <select name="cedis_id" required>
                <option value="">-- Seleccione CEDIS --</option>
                <?php foreach ($cedisList as $c): ?>
                  <option value="<?php echo $c['id']; ?>">🏢 <?php echo htmlspecialchars($c["nombre"]); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label>Fecha de Recolección Solicitada *</label>
              <input type="date" name="fecha_recoleccion" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" min="<?php echo date('Y-m-d'); ?>" required>
            </div>
          </div>

          <!-- Selección de Lotes y Cantidades -->
          <div class="form-group">
            <label style="margin-bottom:6px;">Selección de Lotes y Cantidad a Enviar *</label>
            <div style="max-height: 180px; overflow-y: auto; border: 1px solid rgba(213, 164, 112, 0.3); border-radius: 12px; padding: 12px; background:#fbfaf8; display:flex; flex-direction:column; gap:10px;">
              <?php foreach ($lotesDisponibles as $ld): 
                $preselected = ($preselected_lote_id === (int)$ld["id"]) ? 'checked' : '';
                $val = ($preselected_lote_id === (int)$ld["id"]) ? $ld["cantidad"] : '0';
              ?>
                <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; background:white; padding:8px 12px; border-radius:8px; border: 1px solid rgba(213, 164, 112, 0.15);">
                  <div style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="chk_lote_<?php echo $ld['id']; ?>" onchange="toggleLoteInput(<?php echo $ld['id']; ?>, <?php echo $ld['cantidad']; ?>)" <?php echo $preselected; ?>>
                    <label for="chk_lote_<?php echo $ld['id']; ?>" style="text-transform:none; font-weight:700; cursor:pointer; font-size:12px; color: var(--text-dark); margin-left:0;">
                      🥚 <?php echo htmlspecialchars($ld["codigo_lote"]); ?><br>
                      <small style="color:var(--text-medium); font-weight:500; font-size:10px;"><?php echo htmlspecialchars($ld["producto_nombre"]); ?> (Stock: <?php echo $ld["cantidad"]; ?> ud)</small>
                    </label>
                  </div>
                  <div>
                    <input type="number" name="lotes_cantidad[<?php echo $ld['id']; ?>]" id="cant_lote_<?php echo $ld['id']; ?>" value="<?php echo $val; ?>" min="0" max="<?php echo $ld['cantidad']; ?>" style="width: 80px; height: 32px; border-radius: 6px; border:1px solid #ccc; text-align:center; padding: 0 4px; font-size:13px;" <?php echo $preselected ? '' : 'disabled'; ?>>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group">
            <label>Observaciones o Indicaciones de Carga</label>
            <textarea name="observaciones" placeholder="Ej: Las bandejas están paletizadas. Solicitar camión con rampa."></textarea>
          </div>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrear')">Cancelar</button>
          <button type="submit" class="btn-submit">Solicitar Entrega</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Modal Cancelar Entrega -->
<div class="modal-overlay" id="modalCancelar">
  <div class="modal-container" style="max-width: 440px; text-align: center;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Cancelar Solicitud de Entrega</div>
      <button class="modal-close" onclick="cerrarModal('modalCancelar')">×</button>
    </div>
    <form action="entregas_proveedor.php" method="POST">
      <input type="hidden" name="accion" value="cancelar_entrega">
      <input type="hidden" name="entrega_id" id="cancel_id">
      
      <p style="color: var(--text-medium); font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas cancelar la solicitud de entrega <strong id="cancel_codigo" style="color: var(--text-dark);"></strong>?<br>
        Esta acción devolverá los huevos asignados al stock de su inventario.
      </p>

      <div class="modal-actions" style="justify-content: center;">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalCancelar')">Volver</button>
        <button type="submit" class="btn-submit btn-danger">Confirmar Cancelación</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalCrear() {
    document.getElementById('modalCrear').classList.add('active');
}

function toggleLoteInput(loteId, maxVal) {
    const chk = document.getElementById('chk_lote_' + loteId);
    const input = document.getElementById('cant_lote_' + loteId);
    if (chk.checked) {
        input.disabled = false;
        input.value = maxVal;
        input.focus();
    } else {
        input.disabled = true;
        input.value = 0;
    }
}

function confirmarCancelacion(id) {
    document.getElementById('cancel_id').value = id;
    document.getElementById('cancel_codigo').textContent = '#ENT-' + String(id).padStart(3, '0');
    document.getElementById('modalCancelar').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Búsqueda y Paginación
let paginaActual = 1;
const registrosPorPagina = 8;
let filtroQuery = "";
let filtroEstadoActivo = "todos";

function actualizarVistaPaginacion() {
    const rows = document.querySelectorAll('#tablaCuerpo .row-entrega');
    const matchingRows = [];

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const rEstado = row.getAttribute('data-estado');
        
        const matchesQuery = text.includes(filtroQuery);
        const matchesEstado = (filtroEstadoActivo === 'todos' || rEstado === filtroEstadoActivo);

        if (matchesQuery && matchesEstado) {
            matchingRows.push(row);
        } else {
            row.style.display = 'none';
        }
    });

    const totalRegistros = matchingRows.length;
    const totalPaginas = Math.ceil(totalRegistros / registrosPorPagina) || 1;

    if (paginaActual > totalPaginas) paginaActual = totalPaginas;
    if (paginaActual < 1) paginaActual = 1;

    const inicio = (paginaActual - 1) * registrosPorPagina;
    const fin = inicio + registrosPorPagina;

    matchingRows.forEach((row, index) => {
        if (index >= inicio && index < fin) {
            row.style.display = 'table-row';
        } else {
            row.style.display = 'none';
        }
    });

    const mostradosInicio = totalRegistros > 0 ? inicio + 1 : 0;
    const mostradosFin = Math.min(fin, totalRegistros);
    document.getElementById('paginacionTexto').textContent = 
        `MOSTRANDO ${mostradosInicio}-${mostradosFin} DE ${totalRegistros} ENTREGAS`;

    const contenedor = document.getElementById('paginacionContenedor');
    contenedor.innerHTML = "";
    for (let p = 1; p <= totalPaginas; p++) {
        const btn = document.createElement('button');
        btn.className = (p === paginaActual) ? 'page-btn active' : 'page-btn';
        btn.textContent = p;
        btn.onclick = () => {
            paginaActual = p;
            actualizarVistaPaginacion();
        };
        contenedor.appendChild(btn);
    }
}

function filtrarEntregas() {
    filtroQuery = document.getElementById('buscarEntrega').value.toLowerCase();
    paginaActual = 1;
    actualizarVistaPaginacion();
}

function filtrarEstadoTab(estado, btn) {
    const buttons = document.querySelectorAll('.filter-buttons .filter-btn');
    buttons.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    filtroEstadoActivo = estado.toLowerCase();
    paginaActual = 1;
    actualizarVistaPaginacion();
}

document.addEventListener("DOMContentLoaded", () => {
    // Si viene de preseleccionar lote, abrimos el modal
    <?php if ($preselected_lote_id > 0): ?>
        abrirModalCrear();
    <?php endif; ?>
    actualizarVistaPaginacion();
});
</script>

</body>
</html>
