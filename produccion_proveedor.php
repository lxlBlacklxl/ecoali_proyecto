<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - GESTIÓN DE PRODUCCIÓN (POSTURA DIARIA) DEL PROVEEDOR
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

// 3. PROCESAR ACCIONES DE FORMULARIO (POST)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";

    if ($accion === "agregar") {
        $granja_id = (int)($_POST["granja_id"] ?? 0);
        $producto_id = (int)($_POST["producto_id"] ?? 0);
        $cantidad = (int)($_POST["cantidad"] ?? 0);
        $no_viable = max(0, (int)($_POST["no_viable"] ?? 0));
        $merma = max(0, (int)($_POST["merma"] ?? 0));
        $fecha_produccion = trim($_POST["fecha_produccion"] ?? "");
        $observaciones = trim($_POST["observaciones"] ?? "");

        if ($granja_id <= 0 || $producto_id <= 0 || $cantidad <= 0 || empty($fecha_produccion)) {
            $mensaje_error = "Todos los campos obligatorios deben completarse correctamente.";
        } else {
            // Validar propiedad de la granja y stock de cartones
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

                // 1 cartón = 30 huevos
                $cartones_necesarios = (int)ceil($cantidad / 30);
                if ($stock_cartones < $cartones_necesarios) {
                    $mensaje_error = "Insumos insuficientes: La granja '$granja_nombre' requiere $cartones_necesarios cartones de empaque para $cantidad huevos, pero solo cuenta con $stock_cartones disponibles.";
                } else {
                    // Iniciar transacción
                    $conn->begin_transaction();
                    try {
                        // Descontar cartones de la granja
                        $nuevo_stock = $stock_cartones - $cartones_necesarios;
                        $stmtUpG = $conn->prepare("UPDATE granjas SET stock_cartones = ? WHERE id = ?");
                        $stmtUpG->bind_param("ii", $nuevo_stock, $granja_id);
                        $stmtUpG->execute();
                        $stmtUpG->close();

                        // Generar código de lote único
                        $lote_hash = strtoupper(substr(md5(time() . rand(1, 100)), 0, 4));
                        $codigo_lote = "LOTE-P" . $producto_id . "-U" . $usuario_id . "-" . $lote_hash;

                        // Fecha de caducidad = postura + 3 días
                        $fecha_caducidad = date('Y-m-d', strtotime('+3 days', strtotime($fecha_produccion)));

                        // Insertar en produccion
                        $stmtInsP = $conn->prepare("INSERT INTO produccion (proveedor_id, producto_id, granja_id, cantidad, no_viable, merma, fecha_produccion, observaciones, codigo_lote) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmtInsP->bind_param("iiiiiisss", $proveedor_id, $producto_id, $granja_id, $cantidad, $no_viable, $merma, $fecha_produccion, $observaciones, $codigo_lote);
                        $stmtInsP->execute();
                        $stmtInsP->close();

                        // Insertar en inventario_huevos (lote)
                        $stmtInsI = $conn->prepare("INSERT INTO inventario_huevos (proveedor_id, producto_id, codigo_lote, cantidad_inicial, cantidad, no_viable, merma, fecha_postura, fecha_caducidad, estado, granja_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?)");
                        $stmtInsI->bind_param("iisiiiissi", $proveedor_id, $producto_id, $codigo_lote, $cantidad, $cantidad, $no_viable, $merma, $fecha_produccion, $fecha_caducidad, $granja_id);
                        $stmtInsI->execute();
                        $stmtInsI->close();

                        // Registrar auditoría
                        registrar_bitacora("Producción registrada", "Inventario", "El proveedor registró postura de $cantidad huevos viables ($no_viable no viables, $merma mermas) en la granja '$granja_nombre' generando el lote $codigo_lote.");

                        $conn->commit();
                        $mensaje_exito = "¡Producción y Lote $codigo_lote registrados correctamente!";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $mensaje_error = "Error en base de datos: " . $e->getMessage();
                    }
                }
            }
            $stmtG->close();
        }
    } elseif ($accion === "editar") {
        $id = (int)($_POST["id"] ?? 0);
        $granja_id = (int)($_POST["granja_id"] ?? 0);
        $producto_id = (int)($_POST["producto_id"] ?? 0);
        $cantidad = (int)($_POST["cantidad"] ?? 0);
        $no_viable = max(0, (int)($_POST["no_viable"] ?? 0));
        $merma = max(0, (int)($_POST["merma"] ?? 0));
        $fecha_produccion = trim($_POST["fecha_produccion"] ?? "");
        $observaciones = trim($_POST["observaciones"] ?? "");

        if ($id <= 0 || $granja_id <= 0 || $producto_id <= 0 || $cantidad <= 0 || empty($fecha_produccion)) {
            $mensaje_error = "Todos los campos de edición son obligatorios.";
        } else {
            // Verificar existencia y propiedad de la producción
            $stmtCheck = $conn->prepare("SELECT * FROM produccion WHERE id = ? AND proveedor_id = ?");
            $stmtCheck->bind_param("ii", $id, $proveedor_id);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            if ($resCheck->num_rows === 0) {
                $mensaje_error = "Registro de producción no encontrado.";
            } else {
                $old_prod = $resCheck->fetch_assoc();
                $codigo_lote = $old_prod["codigo_lote"];
                $old_cantidad = (int)$old_prod["cantidad"];
                $old_granja_id = (int)$old_prod["granja_id"];

                // Verificar si el lote ya tiene solicitudes de entrega
                $stmtDeliv = $conn->prepare("SELECT COUNT(*) FROM detalle_entrega_cedis d INNER JOIN inventario_huevos i ON d.lote_id = i.id WHERE i.codigo_lote = ?");
                $stmtDeliv->bind_param("s", $codigo_lote);
                $stmtDeliv->execute();
                $countDeliv = (int)($stmtDeliv->get_result()->fetch_row()[0] ?? 0);
                $stmtDeliv->close();

                if ($countDeliv > 0) {
                    $mensaje_error = "No se puede editar esta producción porque el lote '$codigo_lote' ya se encuentra en proceso de entrega al CEDIS.";
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

                        // Calcular cartones
                        $old_cartones = (int)ceil($old_cantidad / 30);
                        $new_cartones = (int)ceil($cantidad / 30);
                        
                        // Si cambiamos de granja, debemos reponer en la vieja y restar en la nueva
                        if ($old_granja_id !== $granja_id) {
                            // Validar stock en la nueva granja
                            if ($stock_cartones < $new_cartones) {
                                $mensaje_error = "Insumos insuficientes en la granja '$granja_nombre'. Se requieren $new_cartones cartones.";
                            } else {
                                $conn->begin_transaction();
                                try {
                                    // Devolver a la vieja granja
                                    $conn->query("UPDATE granjas SET stock_cartones = stock_cartones + $old_cartones WHERE id = $old_granja_id");
                                    // Restar en la nueva granja
                                    $conn->query("UPDATE granjas SET stock_cartones = stock_cartones - $new_cartones WHERE id = $granja_id");
                                    
                                    // Actualizar produccion e inventario
                                    $fecha_caducidad = date('Y-m-d', strtotime('+3 days', strtotime($fecha_produccion)));
                                    
                                    $stmtUpP = $conn->prepare("UPDATE produccion SET producto_id = ?, granja_id = ?, cantidad = ?, no_viable = ?, merma = ?, fecha_produccion = ?, observaciones = ? WHERE id = ?");
                                    $stmtUpP->bind_param("iiiiissi", $producto_id, $granja_id, $cantidad, $no_viable, $merma, $fecha_produccion, $observaciones, $id);
                                    $stmtUpP->execute();
                                    $stmtUpP->close();

                                    $stmtUpI = $conn->prepare("UPDATE inventario_huevos SET producto_id = ?, cantidad_inicial = ?, cantidad = ?, no_viable = ?, merma = ?, fecha_postura = ?, fecha_caducidad = ?, granja_id = ? WHERE codigo_lote = ?");
                                    $stmtUpI->bind_param("iiiiisisi", $producto_id, $cantidad, $cantidad, $no_viable, $merma, $fecha_produccion, $fecha_caducidad, $granja_id, $codigo_lote);
                                    $stmtUpI->execute();
                                    $stmtUpI->close();

                                    registrar_bitacora("Producción editada", "Inventario", "El proveedor editó el registro de producción del lote '$codigo_lote'. Nueva cantidad viables: $cantidad, no viables: $no_viable, mermas: $merma.");
                                    $conn->commit();
                                    $mensaje_exito = "¡La producción se actualizó correctamente!";
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $mensaje_error = "Error al editar: " . $e->getMessage();
                                }
                            }
                        } else {
                            // Misma granja, calcular diferencia
                            $diferencia_cartones = $new_cartones - $old_cartones;
                            if ($diferencia_cartones > 0 && $stock_cartones < $diferencia_cartones) {
                                $mensaje_error = "Insumos insuficientes en '$granja_nombre'. Faltan $diferencia_cartones cartones adicionales.";
                            } else {
                                $conn->begin_transaction();
                                try {
                                    // Descontar diferencia
                                    $conn->query("UPDATE granjas SET stock_cartones = stock_cartones - $diferencia_cartones WHERE id = $granja_id");
                                    
                                    $fecha_caducidad = date('Y-m-d', strtotime('+3 days', strtotime($fecha_produccion)));
                                    
                                    $stmtUpP = $conn->prepare("UPDATE produccion SET producto_id = ?, cantidad = ?, no_viable = ?, merma = ?, fecha_produccion = ?, observaciones = ? WHERE id = ?");
                                    $stmtUpP->bind_param("iiiiissi", $producto_id, $cantidad, $no_viable, $merma, $fecha_produccion, $observaciones, $id);
                                    $stmtUpP->execute();
                                    $stmtUpP->close();

                                    $stmtUpI = $conn->prepare("UPDATE inventario_huevos SET producto_id = ?, cantidad_inicial = ?, cantidad = ?, no_viable = ?, merma = ?, fecha_postura = ?, fecha_caducidad = ? WHERE codigo_lote = ?");
                                    $stmtUpI->bind_param("iiiiisss", $producto_id, $cantidad, $cantidad, $no_viable, $merma, $fecha_produccion, $fecha_caducidad, $codigo_lote);
                                    $stmtUpI->execute();
                                    $stmtUpI->close();

                                    registrar_bitacora("Producción editada", "Inventario", "El proveedor editó el registro del lote '$codigo_lote'. Nueva cantidad viables: $cantidad, no viables: $no_viable, mermas: $merma.");
                                    $conn->commit();
                                    $mensaje_exito = "¡La producción se actualizó correctamente!";
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
            $stmtCheck->close();
        }
    } elseif ($accion === "eliminar") {
        $id = (int)($_POST["id"] ?? 0);

        if ($id <= 0) {
            $mensaje_error = "ID de producción inválido.";
        } else {
            // Verificar propiedad
            $stmtCheck = $conn->prepare("SELECT * FROM produccion WHERE id = ? AND proveedor_id = ?");
            $stmtCheck->bind_param("ii", $id, $proveedor_id);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            if ($resCheck->num_rows === 0) {
                $mensaje_error = "Registro de producción no encontrado.";
            } else {
                $prod = $resCheck->fetch_assoc();
                $codigo_lote = $prod["codigo_lote"];
                $cantidad = (int)$prod["cantidad"];
                $granja_id = (int)$prod["granja_id"];

                // Verificar si está asociada a entrega al CEDIS
                $stmtDeliv = $conn->prepare("SELECT COUNT(*) FROM detalle_entrega_cedis d INNER JOIN inventario_huevos i ON d.lote_id = i.id WHERE i.codigo_lote = ?");
                $stmtDeliv->bind_param("s", $codigo_lote);
                $stmtDeliv->execute();
                $countDeliv = (int)($stmtDeliv->get_result()->fetch_row()[0] ?? 0);
                $stmtDeliv->close();

                if ($countDeliv > 0) {
                    $mensaje_error = "No se puede eliminar este registro porque el lote '$codigo_lote' ya se encuentra en proceso de entrega al CEDIS.";
                } else {
                    $conn->begin_transaction();
                    try {
                        // Devolver cartones
                        $cartones_devueltos = (int)ceil($cantidad / 30);
                        $conn->query("UPDATE granjas SET stock_cartones = stock_cartones + $cartones_devueltos WHERE id = $granja_id");

                        // Eliminar lote de inventario
                        $stmtDelI = $conn->prepare("DELETE FROM inventario_huevos WHERE codigo_lote = ? AND proveedor_id = ?");
                        $stmtDelI->bind_param("si", $codigo_lote, $proveedor_id);
                        $stmtDelI->execute();
                        $stmtDelI->close();

                        // Eliminar producción
                        $stmtDelP = $conn->prepare("DELETE FROM produccion WHERE id = ?");
                        $stmtDelP->bind_param("i", $id);
                        $stmtDelP->execute();
                        $stmtDelP->close();

                        registrar_bitacora("Producción eliminada", "Inventario", "El proveedor eliminó permanentemente el registro de postura del lote '$codigo_lote', liberando $cartones_devueltos cartones.");
                        $conn->commit();
                        $mensaje_exito = "¡Registro de producción y lote asociados eliminados con éxito!";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $mensaje_error = "Error al eliminar: " . $e->getMessage();
                    }
                }
            }
            $stmtCheck->close();
        }
    }
}

// 4. OBTENER INFORMACIÓN DE VISTA
// Lista de Granjas
$granjas = [];
$stmtG = $conn->prepare("SELECT id, nombre, identificacion, stock_cartones FROM granjas WHERE proveedor_id = ? ORDER BY nombre ASC");
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
usort($productos, function($a, $b) {
    $getWeight = function($p) {
        $tamano = strtolower($p['tamano']);
        if (strpos($tamano, 'chico') !== false) return 1;
        if (strpos($tamano, 'mediano') !== false) return 2;
        return 3;
    };
    return $getWeight($a) - $getWeight($b);
});

// Historial de Producción
$historial = [];
$queryH = "SELECT p.*, pr.nombre AS producto_nombre, pr.tamano AS producto_tamano, g.nombre AS granja_nombre,
                  (SELECT COUNT(*) FROM detalle_entrega_cedis d INNER JOIN inventario_huevos i ON d.lote_id = i.id WHERE i.codigo_lote = p.codigo_lote) AS en_entrega
           FROM produccion p
           INNER JOIN productos pr ON p.producto_id = pr.id
           LEFT JOIN granjas g ON p.granja_id = g.id
           WHERE p.proveedor_id = ?
           ORDER BY p.fecha_produccion DESC, p.id DESC";
$stmtH = $conn->prepare($queryH);
$stmtH->bind_param("i", $proveedor_id);
$stmtH->execute();
$resH = $stmtH->get_result();
while ($row = $resH->fetch_assoc()) {
    $historial[] = $row;
}
$stmtH->close();

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registro de Producción - ECOALI</title>
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
        <span>🚜</span> <span>Mi Resumen</span>
      </a>
      <a href="produccion_proveedor.php" class="menu-link <?php echo ($current_page === 'produccion_proveedor.php') ? 'active' : ''; ?>">
        <span>🥚</span> <span>Registrar Postura (Recolección)</span>
      </a>
      <a href="lotes_proveedor.php" class="menu-link <?php echo ($current_page === 'lotes_proveedor.php') ? 'active' : ''; ?>">
        <span>📦</span> <span>Mis Lotes de Huevos</span>
      </a>
      <a href="inventario_proveedor.php" class="menu-link <?php echo ($current_page === 'inventario_proveedor.php') ? 'active' : ''; ?>">
        <span>🧺</span> <span>Mi Almacén (Stock)</span>
      </a>
      <a href="entregas_proveedor.php" class="menu-link <?php echo ($current_page === 'entregas_proveedor.php') ? 'active' : ''; ?>">
        <span>🚚</span> <span>Enviar al CEDIS (Entregas)</span>
      </a>
      <a href="trazabilidad_proveedor.php" class="menu-link <?php echo ($current_page === 'trazabilidad_proveedor.php') ? 'active' : ''; ?>">
        <span>🔍</span> <span>Origen y Calidad</span>
      </a>
      <a href="reportes_proveedor.php" class="menu-link <?php echo ($current_page === 'reportes_proveedor.php') ? 'active' : ''; ?>">
        <span>📊</span> <span>Mis Reportes</span>
      </a>
      <a href="editar_perfil.php" class="menu-link <?php echo ($current_page === 'editar_perfil.php') ? 'active' : ''; ?>">
        <span>⚙️</span> <span>Mi Perfil y Granjas</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" style="text-decoration:none;">
        <button class="logout-btn">
          <span>🚪</span> Salir (Cerrar Sesión)
        </button>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">
    <header class="app-header">
      <div>
        <h1>Registrar Postura (Recolección Diaria)</h1>
        <p>Anota la cantidad de huevos frescos que has recolectado hoy en tus granjas.</p>
      </div>
      <button class="header-btn" onclick="abrirModalCrear()">
        <span>✚</span> Registrar Nueva Recolección
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

    <!-- Buscador interactivo -->
    <div class="section-search">
      <div class="search-input-wrapper">
        <input type="text" id="buscarProd" placeholder="Buscar por código de lote, granja o producto..." onkeyup="filtrarTabla()">
      </div>
    </div>

    <!-- Tabla Historial de Producción -->
    <div class="card" style="padding: 24px;">
      <h3>Historial de Postura Diaria</h3>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Lote</th>
              <th>Granja</th>
              <th>Tipo de Huevo</th>
              <th>Viables</th>
              <th>No Viables</th>
              <th>Mermas</th>
              <th>Fecha Postura</th>
              <th>Observaciones</th>
              <th style="width: 130px; text-align: center;">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaCuerpo">
            <?php if (!empty($historial)): ?>
              <?php foreach ($historial as $row): 
                $editable = ((int)$row["en_entrega"] === 0);
              ?>
                <tr class="row-produccion">
                  <td>#PD-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?></td>
                  <td><strong style="color: var(--primary);"><?php echo htmlspecialchars($row["codigo_lote"] ?? "N/A"); ?></strong></td>
                  <td>🚜 <?php echo htmlspecialchars($row["granja_nombre"] ?? "N/A"); ?></td>
                  <td><?php echo htmlspecialchars($row["producto_nombre"] . " (" . $row["producto_tamano"] . ")"); ?></td>
                  <td><strong style="color: var(--secondary);"><?php echo number_format($row["cantidad"]); ?> ud</strong></td>
                  <td><span style="color: #ff8a00; font-weight: 700;"><?php echo number_format($row["no_viable"]); ?> ud</span></td>
                  <td><span style="color: #b02500; font-weight: 700;"><?php echo number_format($row["merma"]); ?> ud</span></td>
                  <td><?php echo date("d/m/Y", strtotime($row["fecha_produccion"])); ?></td>
                  <td><span style="font-size:12px; color:var(--text-medium);"><?php echo htmlspecialchars($row["observaciones"] ?: "Sin observaciones"); ?></span></td>
                  <td>
                    <div style="display: flex; gap: 8px; justify-content: center;">
                      <?php if ($editable): ?>
                        <button class="action-btn action-btn-edit" title="Editar Producción" onclick='abrirModalEditar(<?php echo json_encode($row); ?>)'>✎</button>
                        <button class="action-btn action-btn-delete" title="Eliminar Producción" onclick="confirmarEliminar(<?php echo $row['id']; ?>, '<?php echo $row['codigo_lote']; ?>')">🗑</button>
                      <?php else: ?>
                        <button class="action-btn" disabled style="background: #f1edeb; color: #bbb; cursor: not-allowed;" title="Lote ya solicitado en entrega">🔒</button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="10" style="text-align: center; padding: 30px; color: var(--text-medium);">No tienes producciones registradas actualmente.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Paginación -->
      <div class="pagination-wrapper">
        <div class="pagination-text" id="paginacionTexto">MOSTRANDO HISTORIAL</div>
        <div class="pagination-buttons" id="paginacionContenedor"></div>
      </div>
    </div>

  </main>

  <!-- Mobile Nav -->
  <nav class="mobile-nav">
    <a href="dashboard_proveedor.php" class="mobile-nav-btn">
      <span>🚜</span>
      <span>Mi Resumen</span>
    </a>
    <a href="produccion_proveedor.php" class="mobile-nav-btn active">
      <span>🥚</span>
      <span>Postura</span>
    </a>
    <a href="inventario_proveedor.php" class="mobile-nav-btn">
      <span>🧺</span>
      <span>Almacén</span>
    </a>
    <a href="entregas_proveedor.php" class="mobile-nav-btn">
      <span>🚚</span>
      <span>Entregas</span>
    </a>
    <a href="editar_perfil.php" class="mobile-nav-btn">
      <span>⚙️</span>
      <span>Perfil</span>
    </a>
  </nav>

</div>

<!-- ==========================================
     MODALES (AGREGAR, EDITAR, ELIMINAR)
     ========================================== -->

<!-- Modal Agregar -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Registrar Nueva Postura</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    <?php if (empty($granjas)): ?>
      <div style="text-align: center; padding: 20px;">
        <span style="font-size:36px;">🚜</span>
        <h4 style="margin: 10px 0; color: #b02500;">Falta Registrar Granjas</h4>
        <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin-bottom: 20px;">Debe registrar al menos una granja en "Mi Perfil" antes de registrar producción por trazabilidad.</p>
        <a href="editar_perfil.php" class="btn-submit" style="text-decoration:none;">Ir a Mi Perfil</a>
      </div>
    <?php else: ?>
      <form action="produccion_proveedor.php" method="POST">
        <input type="hidden" name="accion" value="agregar">
        
        <div class="form-grid" style="grid-template-columns: 1fr;">
          <div class="form-group">
            <label>Granja de Origen *</label>
            <select name="granja_id" required>
              <option value="">-- Selecciona Granja --</option>
              <?php foreach ($granjas as $g): ?>
                <option value="<?php echo $g['id']; ?>">🚜 <?php echo htmlspecialchars($g["nombre"]); ?> (<?php echo $g["stock_cartones"]; ?> cartones)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label>Tipo de Huevo *</label>
              <select name="producto_id" required>
                <option value="">-- Selecciona --</option>
                <?php foreach ($productos as $p): 
                  if ($p['id'] == 2) continue; // Quitar Grande Tradicional
                  
                  $displayLabel = "";
                  if (strpos(strtolower($p['tamano']), 'chico') !== false) {
                      $displayLabel = "Chico";
                  } elseif (strpos(strtolower($p['tamano']), 'mediano') !== false) {
                      $displayLabel = "Mediano";
                  } else {
                      $displayLabel = "Grande";
                  }
                ?>
                  <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($displayLabel); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label>Total Huevos Recolectados *</label>
              <input type="number" id="add_total_recolectado" min="1" placeholder="Ej: 100" oninput="calculateViablesAdd()" required>
            </div>
          </div>

          <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label>No Viables (Pigmentación Irregular)</label>
              <input type="number" name="no_viable" id="add_no_viable" min="0" value="0" placeholder="Ej: 10" oninput="calculateViablesAdd()">
            </div>

            <div class="form-group">
              <label>Mermas (Rotos / Dañados)</label>
              <input type="number" name="merma" id="add_merma" min="0" value="0" placeholder="Ej: 5" oninput="calculateViablesAdd()">
            </div>
          </div>

          <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label>Huevos Viables / Aptos (Uds) *</label>
              <input type="number" name="cantidad" id="add_cantidad" min="0" readonly style="background: rgba(0,0,0,0.03); cursor: not-allowed;" placeholder="Se calcula automáticamente" required>
              <div style="font-size:12px; color:var(--secondary); font-weight:800; margin-top:4px;" id="calc_cartones">Se usarán aproximadamente 0 cartones de empaque.</div>
            </div>

            <div class="form-group">
              <label>Fecha de Postura *</label>
              <input type="date" name="fecha_produccion" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
          </div>

          <div class="form-group">
            <label>Observaciones de Trazabilidad</label>
            <textarea name="observaciones" placeholder="Ej: Recolección limpia, alimentación natural libre de jaula."></textarea>
          </div>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrear')">Cancelar</button>
          <button type="submit" class="btn-submit">Registrar Postura y Lote</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Modal Editar -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Editar Registro de Producción</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="produccion_proveedor.php" method="POST">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">

      <div class="form-grid" style="grid-template-columns: 1fr;">
        <div class="form-group">
          <label>Granja de Origen *</label>
          <select name="granja_id" id="edit_granja_id" required>
            <?php foreach ($granjas as $g): ?>
              <option value="<?php echo $g['id']; ?>">🚜 <?php echo htmlspecialchars($g["nombre"]); ?> (<?php echo $g["stock_cartones"]; ?> cartones)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>Tipo de Huevo *</label>
            <select name="producto_id" id="edit_producto_id" required>
              <?php foreach ($productos as $p): 
                if ($p['id'] == 2) continue; // Quitar Grande Tradicional
                
                $displayLabel = "";
                if (strpos(strtolower($p['tamano']), 'chico') !== false) {
                    $displayLabel = "Chico";
                } elseif (strpos(strtolower($p['tamano']), 'mediano') !== false) {
                    $displayLabel = "Mediano";
                } else {
                    $displayLabel = "Grande";
                }
              ?>
                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($displayLabel); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Total Huevos Recolectados *</label>
            <input type="number" id="edit_total_recolectado" min="1" placeholder="Ej: 100" oninput="calculateViablesEdit()" required>
          </div>
        </div>

        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>No Viables (Pigmentación Irregular)</label>
            <input type="number" name="no_viable" id="edit_no_viable" min="0" value="0" oninput="calculateViablesEdit()">
          </div>

          <div class="form-group">
            <label>Mermas (Rotos / Dañados)</label>
            <input type="number" name="merma" id="edit_merma" min="0" value="0" oninput="calculateViablesEdit()">
          </div>
        </div>

        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>Huevos Viables / Aptos (Uds) *</label>
            <input type="number" name="cantidad" id="edit_cantidad" min="0" readonly style="background: rgba(0,0,0,0.03); cursor: not-allowed;" placeholder="Se calcula automáticamente" required>
            <div style="font-size:12px; color:var(--secondary); font-weight:800; margin-top:4px;" id="edit_calc_cartones">Se usarán aproximadamente 0 cartones de empaque.</div>
          </div>

          <div class="form-group">
            <label>Fecha de Postura *</label>
            <input type="date" name="fecha_produccion" id="edit_fecha_produccion" required>
          </div>
        </div>

        <div class="form-group">
          <label>Observaciones de Trazabilidad</label>
          <textarea name="observaciones" id="edit_observaciones"></textarea>
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
      <div class="modal-title" style="color: #b02500;">Confirmar Eliminación</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="produccion_proveedor.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="delete_id">
      
      <p style="color: var(--text-medium); font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar este registro de producción?<br>
        Esta acción eliminará el lote <strong id="delete_lote" style="color: var(--text-dark);"></strong> de forma permanente y devolverá los cartones de empaque a la granja.
      </p>

      <div class="modal-actions" style="justify-content: center;">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit btn-danger">Eliminar Registro</button>
      </div>
    </form>
  </div>
</div>

<!-- Scripts de paginación e interactividad -->
<script>
function abrirModalCrear() {
    document.getElementById('add_total_recolectado').value = '';
    document.getElementById('add_no_viable').value = 0;
    document.getElementById('add_merma').value = 0;
    document.getElementById('add_cantidad').value = 0;
    updateCartonCalc(0, 'calc_cartones');
    document.getElementById('modalCrear').classList.add('active');
}

function calculateViablesAdd() {
    const totalInput = document.getElementById('add_total_recolectado');
    const noViableInput = document.getElementById('add_no_viable');
    const mermaInput = document.getElementById('add_merma');
    const cantidadInput = document.getElementById('add_cantidad');
    
    if (!totalInput || !cantidadInput) return;
    
    const total = parseInt(totalInput.value) || 0;
    const noViable = parseInt(noViableInput.value) || 0;
    const merma = parseInt(mermaInput.value) || 0;
    
    let viables = total - noViable - merma;
    if (viables < 0) viables = 0;
    
    cantidadInput.value = viables;
    updateCartonCalc(viables, 'calc_cartones');
}

function calculateViablesEdit() {
    const totalInput = document.getElementById('edit_total_recolectado');
    const noViableInput = document.getElementById('edit_no_viable');
    const mermaInput = document.getElementById('edit_merma');
    const cantidadInput = document.getElementById('edit_cantidad');
    
    if (!totalInput || !cantidadInput) return;
    
    const total = parseInt(totalInput.value) || 0;
    const noViable = parseInt(noViableInput.value) || 0;
    const merma = parseInt(mermaInput.value) || 0;
    
    let viables = total - noViable - merma;
    if (viables < 0) viables = 0;
    
    cantidadInput.value = viables;
    updateCartonCalc(viables, 'edit_calc_cartones');
}

function abrirModalEditar(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_granja_id').value = data.granja_id;
    document.getElementById('edit_producto_id').value = data.producto_id;
    document.getElementById('edit_cantidad').value = data.cantidad;
    document.getElementById('edit_no_viable').value = data.no_viable;
    document.getElementById('edit_merma').value = data.merma;
    
    const total = (parseInt(data.cantidad) || 0) + (parseInt(data.no_viable) || 0) + (parseInt(data.merma) || 0);
    document.getElementById('edit_total_recolectado').value = total;
    
    updateCartonCalc(data.cantidad, 'edit_calc_cartones');
    
    document.getElementById('edit_fecha_produccion').value = data.fecha_produccion;
    document.getElementById('edit_observaciones').value = data.observaciones;
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

function actualizarVistaPaginacion() {
    const rows = document.querySelectorAll('#tablaCuerpo .row-produccion');
    const matchingRows = [];

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(filtroQuery)) {
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
        `MOSTRANDO ${mostradosInicio}-${mostradosFin} DE ${totalRegistros} REGISTROS`;

    // Renderizar botones de paginación
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

function filtrarTabla() {
    filtroQuery = document.getElementById('buscarProd').value.toLowerCase();
    paginaActual = 1;
    actualizarVistaPaginacion();
}

function updateCartonCalc(val, elemId) {
    const qty = parseInt(val) || 0;
    const cartons = Math.ceil(qty / 30);
    document.getElementById(elemId).textContent = `Se usarán aproximadamente ${cartons} cartones de empaque.`;
}

document.addEventListener("DOMContentLoaded", () => {
    actualizarVistaPaginacion();
    // Initialize editing modal with carton calc
    const editCantInput = document.getElementById('edit_cantidad');
    if (editCantInput) {
        updateCartonCalc(editCantInput.value, 'edit_calc_cartones');
    }
});
</script>

<!-- ASISTENTE VIRTUAL ACCESIBLE: DOÑA ALI PARA GRANJEROS -->
<div id="dona-ali-container" style="position:fixed; bottom:80px; right:24px; z-index:99999; display:flex; flex-direction:column; align-items:flex-end; gap:12px; font-family:inherit;">
  
  <!-- Burbuja de Diálogo de Doña Ali -->
  <div id="dona-ali-bubble" style="display:none; width:300px; background:white; border-radius:20px; border:1px solid rgba(213, 164, 112, 0.25); box-shadow:0 10px 30px rgba(0,0,0,0.15); padding:20px; flex-direction:column; gap:12px; transition:all 0.3s ease;">
    <!-- Encabezado de la Burbuja -->
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(213,164,112,0.15); padding-bottom:8px;">
      <span style="font-weight:800; color:var(--text-dark); font-size:14px; display:inline-flex; align-items:center; gap:6px;">👵 Doña Ali Asistente</span>
      <button onclick="toggleDonaAliBubble()" style="background:none; border:none; font-size:18px; cursor:pointer; color:var(--text-medium); line-height:1;">×</button>
    </div>
    
    <!-- Texto de Respuesta -->
    <p id="dona-ali-text" style="margin:0; font-size:13px; color:var(--text-medium); line-height:1.6; font-weight:700;">¡Hola, granjero! Soy Doña Ali. Estoy aquí para ayudarte a manejar tus huevos y registros. Haz clic en una pregunta o cuéntame qué necesitas.</p>
    
    <!-- Opciones / Preguntas frecuentes -->
    <div id="dona-ali-options" style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
      <button onclick="askDonaAli('postura')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🥚 ¿Cómo registro recolección?</button>
      <button onclick="askDonaAli('lotes')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">📦 ¿Qué es un lote?</button>
      <button onclick="askDonaAli('envio')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🚚 ¿Cómo envío a la ciudad?</button>
      <button onclick="askDonaAli('insumos')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213,164,112,0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🚜 ¿No me deja guardar postura?</button>
    </div>

    <!-- Controles de Voz -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; border-top:1px solid rgba(213,164,112,0.1); padding-top:8px;">
      <button id="dona-ali-speak-btn" onclick="readDonaResponse()" style="background:none; border:none; cursor:pointer; font-size:12px; font-weight:800; color:var(--text-medium);" title="Escuchar respuesta">🔊 Escuchar</button>
      <button id="dona-ali-listen-btn" onclick="listenToUser()" style="background:none; border:none; cursor:pointer; font-size:12px; font-weight:800; color:#b02500;" title="Hablarle a Doña Ali">🎙️ Hablarle</button>
    </div>
  </div>

  <!-- Botón Circular Flotante (Trigger) -->
  <button onclick="toggleDonaAliBubble()" style="width:60px; height:60px; border-radius:50%; background:linear-gradient(135deg, var(--primary), #e07b00); border:none; color:white; font-size:28px; cursor:pointer; box-shadow:0 8px 25px rgba(255,138,0,0.35); display:grid; place-items:center; transition:transform 0.2s ease-in-out;" onmouseover="this.style.transform='scale(1.08)';" onmouseout="this.style.transform='scale(1)';">
    👵
  </button>
</div>

<script>
  let donaSpeechUtterance = null;
  let voiceRecognition = null;

  function toggleDonaAliBubble() {
      const bubble = document.getElementById('dona-ali-bubble');
      if (bubble.style.display === 'none' || bubble.style.display === '') {
          bubble.style.display = 'flex';
          speakText("Hola, granjero. Soy Doña Ali. ¿En qué te ayudo hoy con tus tareas del campo?");
      } else {
          bubble.style.display = 'none';
          if (window.speechSynthesis) {
              window.speechSynthesis.cancel();
          }
      }
  }

  function askDonaAli(topic) {
      const textEl = document.getElementById('dona-ali-text');
      let response = '';

      if (topic === 'postura') {
          response = 'Para registrar tus huevos recolectados del día, haz clic en el botón naranja que dice "Registrar Postura" en la esquina de arriba. Indica de qué granja provienen, el tipo de huevo, la cantidad y la fecha. El sistema calculará cuántos cartones utilizarás de forma automática.';
      } else if (topic === 'lotes') {
          response = 'Cada vez que registras una postura, el sistema crea un Lote de huevos de forma automática. Este lote tiene una etiqueta especial y una fecha de caducidad calculada de 3 días desde su postura para asegurar la frescura de los huevos.';
      } else if (topic === 'envio') {
          response = 'Ve a la pestaña "Enviar al CEDIS (Entregas)". Presiona "Solicitar Recolección", elige el centro de distribución de EcoAli al que quieres enviar y la fecha. Luego, marca las casillas de los lotes de tu almacén que vas a mandar e ingresa la cantidad de cada uno.';
      } else if (topic === 'insumos') {
          response = 'Para asegurar la calidad, cada postura debe registrarse empacada en cartones. Si tu granja tiene 0 o pocos cartones disponibles, no te dejará guardar. Puedes reabastecer cartones yendo a "Mi Perfil y Granjas" en la sección de tus granjas.';
      } else {
          response = 'Hola, hijo. Soy Doña Ali. Estoy aquí para ayudarte a manejar tus registros de postura y tus envíos.';
      }

      textEl.textContent = response;
      speakText(response);
  }

  let selectedFemaleVoice = null;
  function loadVoices() {
      if (!window.speechSynthesis) return;
      const voices = window.speechSynthesis.getVoices();
      if (!voices || voices.length === 0) return;
      const spanishVoices = voices.filter(v => v.lang.includes('es') || v.lang.includes('ES'));
      let found = spanishVoices.find(v => {
          const nameLower = v.name.toLowerCase();
          return nameLower.includes('sabina') || 
                 nameLower.includes('dalia') || 
                 nameLower.includes('yolanda') || 
                 nameLower.includes('helena') || 
                 nameLower.includes('laura') || 
                 nameLower.includes('hilda') || 
                 nameLower.includes('female') ||
                 nameLower.includes('zira') ||
                 nameLower.includes('dona') ||
                 nameLower.includes('mujer') ||
                 nameLower.includes('google');
      });
      if (!found) {
          found = spanishVoices.find(v => {
              const nameLower = v.name.toLowerCase();
              return !nameLower.includes('david') && 
                     !nameLower.includes('raul') && 
                     !nameLower.includes('carlos') && 
                     !nameLower.includes('jorge') && 
                     !nameLower.includes('male') && 
                     !nameLower.includes('hombre');
          });
      }
      if (!found && spanishVoices.length > 0) {
          found = spanishVoices[0];
      }
      selectedFemaleVoice = found;
  }
  if (window.speechSynthesis) {
      window.speechSynthesis.onvoiceschanged = loadVoices;
      loadVoices();
  }

  function speakText(text) {
      if (!window.speechSynthesis) return;
      window.speechSynthesis.cancel();
      
      donaSpeechUtterance = new SpeechSynthesisUtterance(text);
      donaSpeechUtterance.lang = 'es-MX';
      
      if (!selectedFemaleVoice) {
          loadVoices();
      }
      if (selectedFemaleVoice) {
          donaSpeechUtterance.voice = selectedFemaleVoice;
      }
      window.speechSynthesis.speak(donaSpeechUtterance);
  }

  function readDonaResponse() {
      const text = document.getElementById('dona-ali-text').textContent;
      speakText(text);
  }

  function listenToUser() {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SpeechRecognition) {
          alert("Tu navegador no soporta el reconocimiento de voz. Te recomiendo usar Google Chrome.");
          return;
      }

      const listenBtn = document.getElementById('dona-ali-listen-btn');
      listenBtn.textContent = "🎙️ Escuchando...";
      listenBtn.style.color = "var(--secondary)";

      voiceRecognition = new SpeechRecognition();
      voiceRecognition.lang = 'es-MX';
      voiceRecognition.interimResults = false;
      voiceRecognition.maxAlternatives = 1;

      voiceRecognition.start();

      voiceRecognition.onresult = function(event) {
          const phrase = event.results[0][0].transcript.toLowerCase();
          console.log("Usuario dijo: " + phrase);
          
          if (phrase.includes('postura') || phrase.includes('recolect') || phrase.includes('huevo')) {
              askDonaAli('postura');
          } else if (phrase.includes('lote') || phrase.includes('paquete')) {
              askDonaAli('lotes');
          } else if (phrase.includes('envio') || phrase.includes('enviar') || phrase.includes('cedis')) {
              askDonaAli('envio');
          } else if (phrase.includes('insumo') || phrase.includes('carton') || phrase.includes('no me deja')) {
              askDonaAli('insumos');
          } else {
              const textEl = document.getElementById('dona-ali-text');
              textEl.textContent = 'Te escuché: "' + phrase + '". ¿Me puedes preguntar de otra forma, por favor?';
              speakText(textEl.textContent);
          }
      };

      voiceRecognition.onspeechend = function() {
          listenBtn.textContent = "🎙️ Hablarle";
          listenBtn.style.color = "#b02500";
          voiceRecognition.stop();
      };

      voiceRecognition.onerror = function(event) {
          listenBtn.textContent = "🎙️ Hablarle";
          listenBtn.style.color = "#b02500";
          console.log("Error de reconocimiento: " + event.error);
      };
  }
</script>

</body>
</html>
