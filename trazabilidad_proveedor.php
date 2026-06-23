<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - TRAZABILIDAD Y CADENA DE CUSTODIA (VISTA DEL PROVEEDOR)
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

$lote_code = trim($_GET["lote_code"] ?? "");

// 3. OBTENER LISTA DE LOTES PARA EL DROPDOWN
$lotesDropdown = [];
$stmtLD = $conn->prepare("SELECT id, codigo_lote FROM inventario_huevos WHERE proveedor_id = ? ORDER BY id DESC");
$stmtLD->bind_param("i", $proveedor_id);
$stmtLD->execute();
$resLD = $stmtLD->get_result();
while ($row = $resLD->fetch_assoc()) {
    $lotesDropdown[] = $row;
}
$stmtLD->close();

// 4. OBTENER DETALLES DE TRAZABILIDAD DEL LOTE SELECCIONADO
$lote = null;
$entrega = null;

if (!empty($lote_code)) {
    // Info del Lote y Granja
    $stmtInfo = $conn->prepare("SELECT ih.*, pr.nombre AS producto_nombre, pr.tipo_huevo, pr.tamano, 
                                       g.nombre AS granja_nombre, g.identificacion AS granja_identificacion, g.ubicacion AS granja_ubicacion
                                FROM inventario_huevos ih
                                INNER JOIN productos pr ON ih.producto_id = pr.id
                                LEFT JOIN granjas g ON ih.granja_id = g.id
                                WHERE ih.codigo_lote = ? AND ih.proveedor_id = ?");
    $stmtInfo->bind_param("si", $lote_code, $proveedor_id);
    $stmtInfo->execute();
    $resInfo = $stmtInfo->get_result();
    if ($resInfo->num_rows > 0) {
        $lote = $resInfo->fetch_assoc();
        $lote_id = (int)$lote["id"];

        // Info de la Entrega y Repartidor
        $stmtEnt = $conn->prepare("SELECT det.cantidad AS cantidad_entregada, ec.id AS entrega_id, ec.estado AS entrega_estado, ec.fecha_solicitud, 
                                           ec.fecha_recoleccion, ec.fecha_recepcion, ec.observaciones AS entrega_obs, ec.motivo_rechazo,
                                           c.nombre AS cedis_nombre, c.direccion AS cedis_direccion,
                                           up.nombre AS rep_nombre, up.apellido AS rep_apellido, up.telefono AS rep_tel
                                    FROM detalle_entrega_cedis det
                                    INNER JOIN entregas_cedis ec ON det.entrega_id = ec.id
                                    INNER JOIN cedis c ON ec.cedis_id = c.id
                                    LEFT JOIN usuarios u ON ec.repartidor_id = u.id
                                    LEFT JOIN usuario_perfil up ON u.id = up.usuario_id
                                    WHERE det.lote_id = ?
                                    ORDER BY ec.id DESC LIMIT 1");
        $stmtEnt->bind_param("i", $lote_id);
        $stmtEnt->execute();
        $resEnt = $stmtEnt->get_result();
        if ($resEnt->num_rows > 0) {
            $entrega = $resEnt->fetch_assoc();
        }
        $stmtEnt->close();
    }
    $stmtInfo->close();
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trazabilidad del Producto - ECOALI</title>
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
        <h1>Origen y Calidad (Trazabilidad)</h1>
        <p>Revisa todo el camino que han seguido tus huevos: desde que las gallinas los pusieron en tu granja, hasta que llegaron a las bodegas de EcoAli.</p>
      </div>
    </header>

    <div class="card" style="max-width: 760px; margin: 0 auto;">
      <h3>🔀 Rastreador de Lote</h3>
      <p style="font-size: 13px; color: var(--text-medium); line-height: 1.5; margin: -10px 0 24px;">
        Seleccione un lote de la lista para verificar en qué etapa de la cadena de suministro se encuentra.
      </p>

      <form action="trazabilidad_proveedor.php" method="GET" style="margin-bottom: 30px;">
        <div class="form-group">
          <label>Código de Lote a Rastrear</label>
          <div style="display: flex; gap:12px;">
            <select name="lote_code" style="flex:1; height: 50px; font-size:15px;" required>
              <option value="">-- Seleccione un lote --</option>
              <?php foreach ($lotesDropdown as $ld): 
                $selected = ($ld["codigo_lote"] === $lote_code) ? 'selected' : '';
              ?>
                <option value="<?php echo htmlspecialchars($ld["codigo_lote"]); ?>" <?php echo $selected; ?>>🥚 Lote: <?php echo htmlspecialchars($ld["codigo_lote"]); ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-submit" style="width:120px; height:50px; margin-top:0;">Buscar 🔍</button>
          </div>
        </div>
      </form>

      <?php if (!empty($lote_code) && $lote): ?>
        <div style="border-top: 1px solid var(--glass-border); padding-top: 24px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <div>
              <h4 style="font-size: 18px; color: var(--text-dark); margin: 0;"><?php echo htmlspecialchars($lote["codigo_lote"]); ?></h4>
              <p style="font-size:13px; color:var(--text-medium); margin: 4px 0 0;">
                Huevo: <?php echo htmlspecialchars($lote["producto_nombre"] . ' [' . $lote["tipo_huevo"] . ' - ' . $lote["tamano"] . ']'); ?>
              </p>
            </div>
            <span class="badge-status <?php echo strtolower($lote["estado"]); ?>"><?php echo htmlspecialchars($lote["estado"]); ?></span>
          </div>

          <!-- Timeline Stepper -->
          <div class="stepper">
            
            <!-- Fase 1: Postura -->
            <div class="step active">
              <small>Fase 1: Postura y Cosecha</small>
              <h4>Origen: 🚜 <?php echo htmlspecialchars($lote["granja_nombre"] ?: "Granja No Asociada"); ?></h4>
              <p>
                Cosecha de <strong><?php echo number_format($lote["cantidad_inicial"]); ?> huevos</strong> recolectados en la fecha de postura: <strong><?php echo date("d/m/Y", strtotime($lote["fecha_postura"])); ?></strong>.<br>
                Ubicación de origen: <?php echo htmlspecialchars($lote["granja_ubicacion"] ?: "Sevilla, España"); ?> (Código Granja: <?php echo htmlspecialchars($lote["granja_identificacion"] ?: "N/A"); ?>).
              </p>
            </div>

            <!-- Fase 2: Empaque -->
            <div class="step active">
              <small>Fase 2: Clasificación y Calidad</small>
              <h4>Lote Empacado y Sellado</h4>
              <p>
                Los huevos fueron empaquetados consumiendo un total de <strong><?php echo (int)ceil($lote["cantidad_inicial"] / 30); ?> cartones</strong> de empaque oficial.<br>
                Fecha de caducidad calculada: <strong><?php echo date("d/m/Y", strtotime($lote["fecha_caducidad"])); ?></strong> (Consumo preferente: postura + 3 días).
              </p>
            </div>

            <!-- Fase 3: Tránsito Logístico -->
            <?php if ($entrega): 
              $ent_estado = strtolower($entrega["entrega_estado"]);
              $transit_active = in_array($ent_estado, ["repartidor_asignado", "recolectado", "en_ruta", "entregado_cedis", "recibido", "rechazado"]);
              $transit_class = $transit_active ? "active" : "";
              if ($ent_estado === 'cancelado') $transit_class = "danger";
            ?>
              <div class="step <?php echo $transit_class; ?>">
                <small>Fase 3: Tránsito Logístico</small>
                <h4>Envío CEDIS: #ENT-<?php echo str_pad($entrega["entrega_id"], 3, "0", STR_PAD_LEFT); ?> (<?php echo htmlspecialchars($entrega["entrega_estado"]); ?>)</h4>
                <p>
                  Solicitud de entrega creada el <?php echo date("d/m/Y", strtotime($entrega["fecha_solicitud"])); ?> para enviar <?php echo number_format($entrega["cantidad_entregada"]); ?> huevos al <strong>🏢 <?php echo htmlspecialchars($entrega["cedis_nombre"]); ?></strong>.<br>
                  <?php if ($entrega["rep_nombre"]): ?>
                    Repartidor Asignado: 🚚 <strong><?php echo htmlspecialchars($entrega["rep_nombre"] . ' ' . $entrega["rep_apellido"]); ?></strong> (Tlf: <?php echo htmlspecialchars($entrega["rep_tel"]); ?>).
                  <?php else: ?>
                    Estado: <span style="font-style: italic;">Esperando asignación de transportista por el administrador de ECOALI.</span>
                  <?php endif; ?>
                  <?php if (!empty($entrega["fecha_recoleccion"])): ?>
                    <br>Fecha de recolección programada: <?php echo date("d/m/Y", strtotime($entrega["fecha_recoleccion"])); ?>
                  <?php endif; ?>
                </p>
              </div>
            <?php else: ?>
              <div class="step">
                <small>Fase 3: Tránsito Logístico</small>
                <h4>Logística de Despacho</h4>
                <p style="font-style: italic; color: var(--text-medium);">
                  Lote guardado en almacén de granja. Aún no se ha solicitado la entrega de este lote al CEDIS de ECOALI.<br>
                  <a href="entregas_proveedor.php?lote_id=<?php echo $lote['id']; ?>" style="color: var(--secondary); font-weight: bold; text-decoration:none;">Solicitar envío al CEDIS ahora ➔</a>
                </p>
              </div>
            <?php endif; ?>

            <!-- Fase 4: CEDIS -->
            <?php if ($entrega && in_array(strtolower($entrega["entrega_estado"]), ["recibido", "rechazado", "entregado_cedis"])): 
              $cedis_state = strtolower($entrega["entrega_estado"]);
              $cedis_class = ($cedis_state === 'recibido') ? "active" : (($cedis_state === 'rechazado') ? "danger" : "warning");
            ?>
              <div class="step <?php echo $cedis_class; ?>">
                <small>Fase 4: Recepción y Auditoría CEDIS</small>
                <h4>ECOALI CEDIS - <?php echo htmlspecialchars($entrega["cedis_nombre"]); ?></h4>
                <p>
                  <?php if ($cedis_state === 'recibido'): ?>
                    <strong>✓ Recibido y Aprobado</strong>. Los huevos pasaron el control de calidad e higiene en las instalaciones de ECOALI el <?php echo date("d/m/Y H:i", strtotime($entrega["fecha_recepcion"])); ?>. Se agregaron al inventario comercial de la empresa.
                  <?php elseif ($cedis_state === 'rechazado'): ?>
                    <strong>✗ Rechazado</strong>. El lote no cumplió con las políticas de aceptación o frescura de ECOALI. <br>
                    <span style="color:#b02500; font-weight:bold;">Motivo del rechazo: <?php echo htmlspecialchars($entrega["motivo_rechazo"] ?: "No especificado"); ?></span>.
                  <?php else: ?>
                    <strong>El repartidor ha entregado físicamente el producto en el CEDIS</strong>. Esperando a que el personal administrativo audite el lote para agregarlo al stock.
                  <?php endif; ?>
                </p>
              </div>
            <?php else: ?>
              <div class="step">
                <small>Fase 4: Recepción y Auditoría CEDIS</small>
                <h4>Control de Entrada de Inventario</h4>
                <p style="font-style: italic; color: var(--text-medium);">
                  Lote pendiente de recepción física en el CEDIS de ECOALI y evaluación organoléptica.
                </p>
              </div>
            <?php endif; ?>

          </div>

        </div>
      <?php elseif (!empty($lote_code)): ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--text-medium);">
          <span style="font-size: 40px; display:block; margin-bottom:12px;">⚠️</span>
          El código de lote ingresado no pertenece a su inventario o no existe.
        </div>
      <?php else: ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--text-medium);">
          <span style="font-size: 40px; display:block; margin-bottom:12px;">🔍</span>
          Seleccione un lote del menú desplegable de arriba para ver su trazabilidad detallada en tiempo real.
        </div>
      <?php endif; ?>

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
