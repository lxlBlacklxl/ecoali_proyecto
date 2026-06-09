<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - GESTIÓN DE LOTES DEL PROVEEDOR
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

// 3. PROCESAR ACCIONES DE EDICIÓN O ELIMINACIÓN DESDE LOTES (Misma lógica de Sincronización)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";

    if ($accion === "editar") {
        $id = (int)($_POST["id"] ?? 0); // ID de inventario_huevos
        $granja_id = (int)($_POST["granja_id"] ?? 0);
        $producto_id = (int)($_POST["producto_id"] ?? 0);
        $cantidad = (int)($_POST["cantidad"] ?? 0);
        $fecha_postura = trim($_POST["fecha_postura"] ?? "");

        if ($id <= 0 || $granja_id <= 0 || $producto_id <= 0 || $cantidad <= 0 || empty($fecha_postura)) {
            $mensaje_error = "Todos los campos de edición son obligatorios.";
        } else {
            // Verificar pertenencia del lote
            $stmtLote = $conn->prepare("SELECT * FROM inventario_huevos WHERE id = ? AND proveedor_id = ?");
            $stmtLote->bind_param("ii", $id, $proveedor_id);
            $stmtLote->execute();
            $resLote = $stmtLote->get_result();
            if ($resLote->num_rows === 0) {
                $mensaje_error = "Lote no encontrado.";
            } else {
                $lote_data = $resLote->fetch_assoc();
                $codigo_lote = $lote_data["codigo_lote"];
                $old_cantidad = (int)$lote_data["cantidad_inicial"];
                $old_granja_id = (int)$lote_data["granja_id"];

                // Verificar si tiene solicitudes de entrega
                $stmtDeliv = $conn->prepare("SELECT COUNT(*) FROM detalle_entrega_cedis WHERE lote_id = ?");
                $stmtDeliv->bind_param("i", $id);
                $stmtDeliv->execute();
                $countDeliv = (int)($stmtDeliv->get_result()->fetch_row()[0] ?? 0);
                $stmtDeliv->close();

                if ($countDeliv > 0) {
                    $mensaje_error = "El lote '$codigo_lote' ya está asociado a una entrega al CEDIS y no se puede editar.";
                } else {
                    // Validar la granja de destino
                    $stmtG = $conn->prepare("SELECT nombre, stock_cartones FROM granjas WHERE id = ? AND proveedor_id = ?");
                    $stmtG->bind_param("ii", $granja_id, $proveedor_id);
                    $stmtG->execute();
                    $resG = $stmtG->get_result();
                    if ($resG->num_rows === 0) {
                        $mensaje_error = "La granja seleccionada no es válida.";
                    } else {
                        $granja_data = $resG->fetch_assoc();
                        $granja_nombre = $granja_data["nombre"];
                        $stock_cartones = (int)$granja_data["stock_cartones"];

                        $old_cartones = (int)ceil($old_cantidad / 30);
                        $new_cartones = (int)ceil($cantidad / 30);
                        
                        if ($old_granja_id !== $granja_id) {
                            if ($stock_cartones < $new_cartones) {
                                $mensaje_error = "Insumos insuficientes en la granja '$granja_nombre'. Se requieren $new_cartones cartones.";
                            } else {
                                $conn->begin_transaction();
                                try {
                                    $conn->query("UPDATE granjas SET stock_cartones = stock_cartones + $old_cartones WHERE id = $old_granja_id");
                                    $conn->query("UPDATE granjas SET stock_cartones = stock_cartones - $new_cartones WHERE id = $granja_id");
                                    
                                    $fecha_caducidad = date('Y-m-d', strtotime('+3 days', strtotime($fecha_postura)));
                                    
                                    $stmtUpI = $conn->prepare("UPDATE inventario_huevos SET producto_id = ?, cantidad_inicial = ?, cantidad = ?, fecha_postura = ?, fecha_caducidad = ?, granja_id = ? WHERE id = ?");
                                    $stmtUpI->bind_param("iiissii", $producto_id, $cantidad, $cantidad, $fecha_postura, $fecha_caducidad, $granja_id, $id);
                                    $stmtUpI->execute();
                                    $stmtUpI->close();

                                    $stmtUpP = $conn->prepare("UPDATE produccion SET producto_id = ?, granja_id = ?, cantidad = ?, fecha_produccion = ? WHERE codigo_lote = ?");
                                    $stmtUpP->bind_param("iiiss", $producto_id, $granja_id, $cantidad, $fecha_postura, $codigo_lote);
                                    $stmtUpP->execute();
                                    $stmtUpP->close();

                                    registrar_bitacora("Lote editado", "Inventario", "El proveedor editó el lote '$codigo_lote'. Nueva cantidad: $cantidad.");
                                    $conn->commit();
                                    $mensaje_exito = "¡El lote se actualizó correctamente!";
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $mensaje_error = "Error al editar: " . $e->getMessage();
                                }
                            }
                        } else {
                            $diferencia_cartones = $new_cartones - $old_cartones;
                            if ($diferencia_cartones > 0 && $stock_cartones < $diferencia_cartones) {
                                $mensaje_error = "Insumos insuficientes en '$granja_nombre'. Faltan $diferencia_cartones cartones adicionales.";
                            } else {
                                $conn->begin_transaction();
                                try {
                                    $conn->query("UPDATE granjas SET stock_cartones = stock_cartones - $diferencia_cartones WHERE id = $granja_id");
                                    
                                    $fecha_caducidad = date('Y-m-d', strtotime('+3 days', strtotime($fecha_postura)));
                                    
                                    $stmtUpI = $conn->prepare("UPDATE inventario_huevos SET producto_id = ?, cantidad_inicial = ?, cantidad = ?, fecha_postura = ?, fecha_caducidad = ? WHERE id = ?");
                                    $stmtUpI->bind_param("iiissi", $producto_id, $cantidad, $cantidad, $fecha_postura, $fecha_caducidad, $id);
                                    $stmtUpI->execute();
                                    $stmtUpI->close();

                                    $stmtUpP = $conn->prepare("UPDATE produccion SET producto_id = ?, cantidad = ?, fecha_produccion = ? WHERE codigo_lote = ?");
                                    $stmtUpP->bind_param("iiss", $producto_id, $cantidad, $fecha_postura, $codigo_lote);
                                    $stmtUpP->execute();
                                    $stmtUpP->close();

                                    registrar_bitacora("Lote editado", "Inventario", "El proveedor editó el lote '$codigo_lote'. Nueva cantidad: $cantidad.");
                                    $conn->commit();
                                    $mensaje_exito = "¡El lote se actualizó correctamente!";
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $mensaje_error = "Error al actualizar: " . $e->getMessage();
                                }
                            }
                        }
                    }
                    $stmtG->close();
                }
            }
            $stmtLote->close();
        }
    } elseif ($accion === "eliminar") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id <= 0) {
            $mensaje_error = "ID de lote inválido.";
        } else {
            $stmtLote = $conn->prepare("SELECT * FROM inventario_huevos WHERE id = ? AND proveedor_id = ?");
            $stmtLote->bind_param("ii", $id, $proveedor_id);
            $stmtLote->execute();
            $resLote = $stmtLote->get_result();
            if ($resLote->num_rows === 0) {
                $mensaje_error = "Lote no encontrado.";
            } else {
                $lote = $resLote->fetch_assoc();
                $codigo_lote = $lote["codigo_lote"];
                $cantidad = (int)$lote["cantidad_inicial"];
                $granja_id = (int)$lote["granja_id"];

                // Verificar si está asociada a entrega
                $stmtDeliv = $conn->prepare("SELECT COUNT(*) FROM detalle_entrega_cedis WHERE lote_id = ?");
                $stmtDeliv->bind_param("i", $id);
                $stmtDeliv->execute();
                $countDeliv = (int)($stmtDeliv->get_result()->fetch_row()[0] ?? 0);
                $stmtDeliv->close();

                if ($countDeliv > 0) {
                    $mensaje_error = "No se puede eliminar el lote '$codigo_lote' porque ya está en proceso de entrega al CEDIS.";
                } else {
                    $conn->begin_transaction();
                    try {
                        $cartones_devueltos = (int)ceil($cantidad / 30);
                        $conn->query("UPDATE granjas SET stock_cartones = stock_cartones + $cartones_devueltos WHERE id = $granja_id");

                        // Eliminar lote de inventario
                        $stmtDelI = $conn->prepare("DELETE FROM inventario_huevos WHERE id = ?");
                        $stmtDelI->bind_param("i", $id);
                        $stmtDelI->execute();
                        $stmtDelI->close();

                        // Eliminar producción
                        $stmtDelP = $conn->prepare("DELETE FROM produccion WHERE codigo_lote = ?");
                        $stmtDelP->bind_param("s", $codigo_lote);
                        $stmtDelP->execute();
                        $stmtDelP->close();

                        registrar_bitacora("Lote eliminado", "Inventario", "El proveedor eliminó permanentemente el lote '$codigo_lote'.");
                        $conn->commit();
                        $mensaje_exito = "¡Lote eliminado correctamente!";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $mensaje_error = "Error al eliminar lote: " . $e->getMessage();
                    }
                }
            }
            $stmtLote->close();
        }
    }
}

// 4. OBTENER INFORMACIÓN DE VISTA
// Lista de Granjas
$granjas = [];
$stmtG = $conn->prepare("SELECT id, nombre FROM granjas WHERE proveedor_id = ? ORDER BY nombre ASC");
$stmtG->bind_param("i", $proveedor_id);
$stmtG->execute();
$resG = $stmtG->get_result();
while ($row = $resG->fetch_assoc()) {
    $granjas[] = $row;
}
$stmtG->close();

// Lista de Productos
$productos = [];
$resP = $conn->query("SELECT id, nombre, tamano FROM productos WHERE activo = 1 ORDER BY nombre ASC");
while ($row = $resP->fetch_assoc()) {
    $productos[] = $row;
}

// Historial de lotes con entregas e info relacionada
$lotesList = [];
$queryL = "SELECT ih.*, p.nombre AS producto_nombre, p.tamano AS producto_tamano, g.nombre AS granja_nombre,
                  (SELECT COUNT(*) FROM detalle_entrega_cedis WHERE lote_id = ih.id) AS en_entrega
           FROM inventario_huevos ih
           INNER JOIN productos p ON ih.producto_id = p.id
           LEFT JOIN granjas g ON ih.granja_id = g.id
           WHERE ih.proveedor_id = ?
           ORDER BY ih.fecha_postura DESC, ih.id DESC";
$stmtL = $conn->prepare($queryL);
$stmtL->bind_param("i", $proveedor_id);
$stmtL->execute();
$resL = $stmtL->get_result();
while ($row = $resL->fetch_assoc()) {
    $lotesList[] = $row;
}
$stmtL->close();

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lotes de Huevo - ECOALI</title>
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
        <h1>Lotes de Huevo</h1>
        <p>Monitoreo de caducidad, niveles de stock y solicitudes logísticas.</p>
      </div>
      <a href="entregas_proveedor.php" class="header-btn" style="background: var(--secondary); box-shadow: 0 8px 20px rgba(23,106,33,0.25);">
        <span>🚚</span> Solicitar Entrega
      </a>
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

    <!-- Buscador e Interfaz de Filtros -->
    <div class="section-search">
      <div class="search-input-wrapper">
        <input type="text" id="buscarLote" placeholder="Buscar por código de lote, producto o granja..." onkeyup="filtrarLotes()">
      </div>
      
      <div class="filter-buttons">
        <button class="filter-btn active" onclick="filtrarEstado('todos', this)">Todos</button>
        <button class="filter-btn" onclick="filtrarEstado('activo', this)">Activos</button>
        <button class="filter-btn" onclick="filtrarEstado('proximo_caducar', this)">Próx. Vencer</button>
        <button class="filter-btn" onclick="filtrarEstado('caducado', this)">Caducados</button>
        <button class="filter-btn" onclick="filtrarEstado('enviado_cedis', this)">Enviados</button>
        <button class="filter-btn" onclick="filtrarEstado('recibido_cedis', this)">Recibidos</button>
      </div>
    </div>

    <!-- Tabla de Lotes -->
    <div class="card" style="padding: 24px;">
      <h3>Lotes en el Almacén del Granjero</h3>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Código Lote</th>
              <th>Granja</th>
              <th>Tipo de Huevo</th>
              <th>Cant. Inicial</th>
              <th>Cant. Disponible</th>
              <th>Fecha Postura</th>
              <th>Caducidad</th>
              <th>Estado</th>
              <th style="width: 170px; text-align: center;">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaCuerpo">
            <?php if (!empty($lotesList)): ?>
              <?php foreach ($lotesList as $row): 
                $estado = strtolower($row["estado"]);
                $editable = ((int)$row["en_entrega"] === 0);
              ?>
                <tr class="row-lote" data-estado="<?php echo $estado; ?>">
                  <td><strong style="color: var(--text-dark);"><?php echo htmlspecialchars($row["codigo_lote"]); ?></strong></td>
                  <td>🚜 <?php echo htmlspecialchars($row["granja_nombre"] ?? "N/A"); ?></td>
                  <td><?php echo htmlspecialchars($row["producto_nombre"] . " (" . $row["producto_tamano"] . ")"); ?></td>
                  <td><?php echo number_format($row["cantidad_inicial"]); ?> ud</td>
                  <td><strong style="color: var(--secondary);"><?php echo number_format($row["cantidad"]); ?> ud</strong></td>
                  <td><?php echo date("d/m/Y", strtotime($row["fecha_postura"])); ?></td>
                  <td><?php echo date("d/m/Y", strtotime($row["fecha_caducidad"])); ?></td>
                  <td><span class="badge-status <?php echo $estado; ?>"><?php echo htmlspecialchars($row["estado"]); ?></span></td>
                  <td>
                    <div style="display: flex; gap: 6px; justify-content: center;">
                      <?php if ($editable): ?>
                        <button class="action-btn action-btn-edit" title="Editar Lote" onclick='abrirModalEditar(<?php echo json_encode($row); ?>)'>✎</button>
                        <button class="action-btn action-btn-delete" title="Eliminar Lote" onclick="confirmarEliminar(<?php echo $row['id']; ?>, '<?php echo $row['codigo_lote']; ?>')">🗑</button>
                        <a href="entregas_proveedor.php?lote_id=<?php echo $row['id']; ?>" class="action-btn action-btn-view" title="Solicitar Entrega CEDIS" style="background:#eff8ff; color:#1786ba;">🚚</a>
                      <?php else: ?>
                        <button class="action-btn" disabled style="background: #f5f5f5; color: #bbb; cursor: not-allowed;" title="Lote ya asociado a entrega">🔒</button>
                      <?php endif; ?>
                      <a href="trazabilidad_proveedor.php?lote_code=<?php echo urlencode($row['codigo_lote']); ?>" class="action-btn action-btn-view" title="Ver Trazabilidad">🔀</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" style="text-align: center; padding: 30px; color: var(--text-medium);">No tienes lotes registrados en el inventario.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Paginación -->
      <div class="pagination-wrapper">
        <div class="pagination-text" id="paginacionTexto">MOSTRANDO LOTES</div>
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
    <a href="lotes_proveedor.php" class="mobile-nav-btn active">
      <span>▣</span>
      <span>Lotes</span>
    </a>
    <a href="entregas_proveedor.php" class="mobile-nav-btn">
      <span>🚚</span>
      <span>Entregas</span>
    </a>
    <a href="editar_perfil.php" class="mobile-nav-btn">
      <span>👤</span>
      <span>Perfil</span>
    </a>
  </nav>

</div>

<!-- ==========================================
     MODALES (EDITAR, ELIMINAR)
     ========================================== -->

<!-- Modal Editar -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Editar Lote de Huevo</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="lotes_proveedor.php" method="POST">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">

      <div class="form-grid" style="grid-template-columns: 1fr;">
        <div class="form-group">
          <label>Granja de Origen *</label>
          <select name="granja_id" id="edit_granja_id" required>
            <?php foreach ($granjas as $g): ?>
              <option value="<?php echo $g['id']; ?>">🚜 <?php echo htmlspecialchars($g["nombre"]); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>Tipo de Huevo *</label>
            <select name="producto_id" id="edit_producto_id" required>
              <?php foreach ($productos as $p): ?>
                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p["nombre"] . " (" . $p["tamano"] . ")"); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Cantidad Recolectada (Uds) *</label>
            <input type="number" name="cantidad" id="edit_cantidad" min="1" required>
          </div>
        </div>

        <div class="form-group">
          <label>Fecha de Postura *</label>
          <input type="date" name="fecha_postura" id="edit_fecha_postura" required>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditar')">Cancelar</button>
        <button type="submit" class="btn-submit">Guardar Cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Eliminar -->
<div class="modal-overlay" id="modalEliminar">
  <div class="modal-container" style="max-width: 440px; text-align: center;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Confirmar Eliminación de Lote</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="lotes_proveedor.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="delete_id">
      
      <p style="color: var(--text-medium); font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar permanentemente el lote <strong id="delete_lote" style="color: var(--text-dark);"></strong>?<br>
        Esta acción eliminará el stock disponible y liberará los cartones asignados a la granja.
      </p>

      <div class="modal-actions" style="justify-content: center;">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit btn-danger">Eliminar Lote</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalEditar(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_granja_id').value = data.granja_id;
    document.getElementById('edit_producto_id').value = data.producto_id;
    document.getElementById('edit_cantidad').value = data.cantidad_inicial;
    document.getElementById('edit_fecha_postura').value = data.fecha_postura;
    document.getElementById('modalEditar').classList.add('active');
}

function confirmarEliminar(id, loteCode) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_lote').textContent = loteCode;
    document.getElementById('modalEliminar').classList.add('active');
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
    const rows = document.querySelectorAll('#tablaCuerpo .row-lote');
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
        `MOSTRANDO ${mostradosInicio}-${mostradosFin} DE ${totalRegistros} LOTES`;

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

function filtrarLotes() {
    filtroQuery = document.getElementById('buscarLote').value.toLowerCase();
    paginaActual = 1;
    actualizarVistaPaginacion();
}

function filtrarEstado(estado, btn) {
    const buttons = document.querySelectorAll('.filter-buttons .filter-btn');
    buttons.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    filtroEstadoActivo = estado.toLowerCase();
    paginaActual = 1;
    actualizarVistaPaginacion();
}

document.addEventListener("DOMContentLoaded", () => {
    actualizarVistaPaginacion();
});
</script>

</body>
</html>
