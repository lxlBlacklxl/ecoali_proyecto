<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - INVENTARIO CONSOLIDADO DEL PROVEEDOR
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

// 3. CONSULTAR MÉTRICAS GENERALES
// Total Huevos Registrados (Suma de cantidad_inicial de todos los lotes)
$stmtReg = $conn->prepare("SELECT SUM(cantidad_inicial) FROM inventario_huevos WHERE proveedor_id = ?");
$stmtReg->bind_param("i", $proveedor_id);
$stmtReg->execute();
$totalHuevosRegistrados = (int)($stmtReg->get_result()->fetch_row()[0] ?? 0);
$stmtReg->close();

// Total Huevos Disponibles (Suma de cantidad disponible en lotes activos/próximos)
$stmtDisp = $conn->prepare("SELECT SUM(cantidad) FROM inventario_huevos WHERE proveedor_id = ? AND estado IN ('activo', 'proximo_caducar', 'disponible', 'bajo_stock')");
$stmtDisp->bind_param("i", $proveedor_id);
$stmtDisp->execute();
$totalHuevosDisponibles = (int)($stmtDisp->get_result()->fetch_row()[0] ?? 0);
$stmtDisp->close();

// Lotes activos
$stmtLAct = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'activo' AND cantidad > 0");
$stmtLAct->bind_param("i", $proveedor_id);
$stmtLAct->execute();
$totalLotesActivos = (int)($stmtLAct->get_result()->fetch_row()[0] ?? 0);
$stmtLAct->close();

// Lotes próximos a caducar (≤ 1 día)
$stmtLProx = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'proximo_caducar' AND cantidad > 0");
$stmtLProx->bind_param("i", $proveedor_id);
$stmtLProx->execute();
$totalLotesProximos = (int)($stmtLProx->get_result()->fetch_row()[0] ?? 0);
$stmtLProx->close();

// Lotes caducados
$stmtLCad = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'caducado'");
$stmtLCad->bind_param("i", $proveedor_id);
$stmtLCad->execute();
$totalLotesCaducados = (int)($stmtLCad->get_result()->fetch_row()[0] ?? 0);
$stmtLCad->close();

// Lotes enviados al CEDIS
$stmtLEnv = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'enviado_cedis'");
$stmtLEnv->bind_param("i", $proveedor_id);
$stmtLEnv->execute();
$totalLotesEnviados = (int)($stmtLEnv->get_result()->fetch_row()[0] ?? 0);
$stmtLEnv->close();

// Lotes recibidos por ECOALI
$stmtLRec = $conn->prepare("SELECT COUNT(*) FROM inventario_huevos WHERE proveedor_id = ? AND estado = 'recibido_cedis'");
$stmtLRec->bind_param("i", $proveedor_id);
$stmtLRec->execute();
$totalLotesRecibidos = (int)($stmtLRec->get_result()->fetch_row()[0] ?? 0);
$stmtLRec->close();


// 4. CONSULTAS DESGLOSADAS
// Cantidad por producto
$productosRes = [];
$stmtProd = $conn->prepare("SELECT pr.nombre, pr.tamano, SUM(ih.cantidad_inicial) as inicial, SUM(ih.cantidad) as disponible, COUNT(*) as lotes_count
                            FROM inventario_huevos ih
                            INNER JOIN productos pr ON ih.producto_id = pr.id
                            WHERE ih.proveedor_id = ?
                            GROUP BY ih.producto_id
                            ORDER BY disponible DESC");
$stmtProd->bind_param("i", $proveedor_id);
$stmtProd->execute();
$resProd = $stmtProd->get_result();
while ($row = $resProd->fetch_assoc()) {
    $productosRes[] = $row;
}
$stmtProd->close();

// Cantidad por granja
$granjasRes = [];
$stmtGranja = $conn->prepare("SELECT g.nombre as granja_nombre, g.identificacion, SUM(ih.cantidad_inicial) as inicial, SUM(ih.cantidad) as disponible, COUNT(*) as lotes_count
                              FROM inventario_huevos ih
                              LEFT JOIN granjas g ON ih.granja_id = g.id
                              WHERE ih.proveedor_id = ?
                              GROUP BY ih.granja_id
                              ORDER BY disponible DESC");
$stmtGranja->bind_param("i", $proveedor_id);
$stmtGranja->execute();
$resGranja = $stmtGranja->get_result();
while ($row = $resGranja->fetch_assoc()) {
    $granjasRes[] = $row;
}
$stmtGranja->close();

// Lista completa de lotes
$lotesList = [];
$stmtList = $conn->prepare("SELECT ih.*, pr.nombre AS producto_nombre, pr.tamano AS producto_tamano, g.nombre AS granja_nombre
                            FROM inventario_huevos ih
                            INNER JOIN productos pr ON ih.producto_id = pr.id
                            LEFT JOIN granjas g ON ih.granja_id = g.id
                            WHERE ih.proveedor_id = ?
                            ORDER BY ih.fecha_postura DESC");
$stmtList->bind_param("i", $proveedor_id);
$stmtList->execute();
$resList = $stmtList->get_result();
while ($row = $resList->fetch_assoc()) {
    $lotesList[] = $row;
}
$stmtList->close();

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mi Inventario - ECOALI</title>
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
        <h1>Mi Almacén (Stock de Huevos)</h1>
        <p>Revisa la cantidad de huevos empacados y listos que tienes guardados en tu granja.</p>
      </div>
    </header>

    <!-- Tarjetas de Métricas -->
    <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
      <div class="metric-card success">
        <span class="label">Total Recolectado 🥚</span>
        <span class="value"><?php echo number_format($totalHuevosRegistrados); ?> ud</span>
      </div>
      <div class="metric-card success">
        <span class="label">Listos en Almacén 🧺</span>
        <span class="value"><?php echo number_format($totalHuevosDisponibles); ?> ud</span>
      </div>
      <div class="metric-card">
        <span class="label">Paquetes Activos 📦</span>
        <span class="value"><?php echo $totalLotesActivos; ?> lotes</span>
      </div>
      <div class="metric-card warn">
        <span class="label">Por Vencer Pronto ⚠️</span>
        <span class="value"><?php echo $totalLotesProximos; ?> lotes</span>
      </div>
      <div class="metric-card danger">
        <span class="label">Lotes Vencidos ❌</span>
        <span class="value"><?php echo $totalLotesCaducados; ?> lotes</span>
      </div>
      <div class="metric-card">
        <span class="label">Enviados CEDIS 🚚</span>
        <span class="value"><?php echo $totalLotesEnviados; ?> lotes</span>
      </div>
      <div class="metric-card success">
        <span class="label">Recibidos ECOALI 🎉</span>
        <span class="value"><?php echo $totalLotesRecibidos; ?> lotes</span>
      </div>
    </div>

    <!-- Desglose por Producto y Granja -->
    <div class="dashboard-layout" style="margin-bottom: 30px;">
      <!-- Izquierda: Desglose por Producto -->
      <div class="card" style="margin-bottom:0;">
        <h3>Stock por Tipo de Huevo</h3>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Producto</th>
                <th>Tamaño</th>
                <th>Lotes</th>
                <th>Ingresado</th>
                <th>Disponible</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($productosRes)): ?>
                <?php foreach ($productosRes as $pr): ?>
                  <tr>
                    <td><strong><?php echo htmlspecialchars($pr["nombre"]); ?></strong></td>
                    <td><?php echo htmlspecialchars($pr["tamano"]); ?></td>
                    <td><?php echo $pr["lotes_count"]; ?></td>
                    <td><?php echo number_format($pr["inicial"]); ?> ud</td>
                    <td><strong style="color: var(--secondary);"><?php echo number_format($pr["disponible"]); ?> ud</strong></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" style="text-align:center; color: var(--text-medium); padding: 15px;">No hay registros de productos.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Derecha: Desglose por Granja -->
      <div class="card" style="margin-bottom:0;">
        <h3>Stock por Granja de Origen</h3>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Granja</th>
                <th>Lotes</th>
                <th>Ingresado</th>
                <th>Disponible</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($granjasRes)): ?>
                <?php foreach ($granjasRes as $gr): ?>
                  <tr>
                    <td><strong><?php echo htmlspecialchars($gr["granja_nombre"] ?? "N/A"); ?></strong><br><small style="color: var(--text-medium); font-size:10px;"><?php echo htmlspecialchars($gr["identificacion"]); ?></small></td>
                    <td><?php echo $gr["lotes_count"]; ?></td>
                    <td><?php echo number_format($gr["inicial"]); ?> ud</td>
                    <td><strong style="color: var(--secondary);"><?php echo number_format($gr["disponible"]); ?> ud</strong></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="4" style="text-align:center; color: var(--text-medium); padding: 15px;">No hay registros de granjas.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Buscador e Inventario Completo -->
    <div class="section-search">
      <div class="search-input-wrapper">
        <input type="text" id="buscarInv" placeholder="Buscar por código de lote, tipo de huevo, granja o fecha..." onkeyup="filtrarInventario()">
      </div>
      
      <div class="filter-buttons">
        <button class="filter-btn active" onclick="filtrarEstado('todos', this)">Todo</button>
        <button class="filter-btn" onclick="filtrarEstado('activo', this)">Activos</button>
        <button class="filter-btn" onclick="filtrarEstado('proximo_caducar', this)">Próx. Caducar</button>
        <button class="filter-btn" onclick="filtrarEstado('caducado', this)">Caducados</button>
        <button class="filter-btn" onclick="filtrarEstado('pendiente_entrega', this)">Pend. Entrega</button>
        <button class="filter-btn" onclick="filtrarEstado('enviado_cedis', this)">Enviados CEDIS</button>
        <button class="filter-btn" onclick="filtrarEstado('recibido_cedis', this)">Recibidos</button>
        <button class="filter-btn" onclick="filtrarEstado('rechazado', this)">Rechazados</button>
      </div>
    </div>

    <div class="card" style="padding: 24px;">
      <h3>Inventario Completo de Lotes</h3>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Código Lote</th>
              <th>Granja</th>
              <th>Producto</th>
              <th>Ingresado</th>
              <th>Disponible</th>
              <th>Postura</th>
              <th>Vencimiento</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody id="tablaCuerpo">
            <?php if (!empty($lotesList)): ?>
              <?php foreach ($lotesList as $row): 
                $estado = strtolower($row["estado"]);
              ?>
                <tr class="row-inventario" data-estado="<?php echo $estado; ?>">
                  <td><strong style="color: var(--text-dark);"><?php echo htmlspecialchars($row["codigo_lote"]); ?></strong></td>
                  <td>🚜 <?php echo htmlspecialchars($row["granja_nombre"] ?? "N/A"); ?></td>
                  <td><?php echo htmlspecialchars($row["producto_nombre"] . " (" . $row["producto_tamano"] . ")"); ?></td>
                  <td><?php echo number_format($row["cantidad_inicial"]); ?> ud</td>
                  <td><strong style="color: var(--secondary);"><?php echo number_format($row["cantidad"]); ?> ud</strong></td>
                  <td><?php echo date("d/m/Y", strtotime($row["fecha_postura"])); ?></td>
                  <td><?php echo date("d/m/Y", strtotime($row["fecha_caducidad"])); ?></td>
                  <td><span class="badge-status <?php echo $estado; ?>"><?php echo htmlspecialchars($row["estado"]); ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="8" style="text-align: center; padding: 30px; color: var(--text-medium);">No tienes lotes en el inventario.</td>
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
      <span>🚜</span>
      <span>Mi Resumen</span>
    </a>
    <a href="produccion_proveedor.php" class="mobile-nav-btn">
      <span>🥚</span>
      <span>Postura</span>
    </a>
    <a href="inventario_proveedor.php" class="mobile-nav-btn active">
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

<script>
// Paginación y Filtrado
let paginaActual = 1;
const registrosPorPagina = 8;
let filtroQuery = "";
let filtroEstadoActivo = "todos";

function actualizarVistaPaginacion() {
    const rows = document.querySelectorAll('#tablaCuerpo .row-inventario');
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

function filtrarInventario() {
    filtroQuery = document.getElementById('buscarInv').value.toLowerCase();
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
      <button onclick="askDonaAli('envio')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213, 164, 112, 0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🚚 ¿Cómo envío a la ciudad?</button>
      <button onclick="askDonaAli('insumos')" style="text-align:left; background:#faf7f3; border:1px solid rgba(213, 164, 112, 0.2); padding:10px 14px; border-radius:10px; font-size:12px; font-weight:800; color:var(--text-dark); cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#f0e8dd';" onmouseout="this.style.background='#faf7f3';">🚜 ¿No me deja guardar postura?</button>
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
