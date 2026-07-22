<?php
session_start();
require "forms/conexion.php";

if (!isset($_SESSION["admin_session"])) {
    if (isset($_SESSION["usuario_id"]) && (int)$_SESSION["rol_id"] === 1) {
        $_SESSION["admin_session"] = [
            "usuario_id" => $_SESSION["usuario_id"],
            "usuario" => $_SESSION["usuario"] ?? "admin",
            "rol_id" => $_SESSION["rol_id"],
            "nombre" => $_SESSION["nombre"] ?? "Admin",
            "apellido" => $_SESSION["apellido"] ?? "",
            "email" => $_SESSION["email"] ?? ""
        ];
    } else {
        header("Location: login.php");
        exit;
    }
}

$nombre = $_SESSION["admin_session"]["nombre"] ?? "Admin";

// --- INICIALIZACIÓN DE DATOS DE PRUEBA SI LA BD ESTÁ VACÍA ---

// 1. Verificar y precargar un cliente si no existe
$checkCliente = $conn->query("SELECT id FROM usuarios WHERE rol_id = 2 LIMIT 1");
if ($checkCliente && $checkCliente->num_rows === 0) {
    // Insertar usuario cliente
    $passHash = password_hash("cliente123", PASSWORD_BCRYPT);
    $conn->query("INSERT INTO usuarios (usuario, password_hash, rol_id, activo) VALUES ('elena_rivas', '$passHash', 2, 1)");
    $clienteId = $conn->insert_id;
    $conn->query("INSERT INTO usuario_perfil (usuario_id, nombre, apellido, direccion, telefono, email) 
                  VALUES ($clienteId, 'Elena', 'Rivas', 'Calle de Alcalá, 45, Madrid', '600123456', 'elena@rivas.com')");
}

// 2. Verificar y precargar repartidores si no existen
$checkRepartidor = $conn->query("SELECT id FROM usuarios WHERE rol_id = 4 LIMIT 1");
if ($checkRepartidor && $checkRepartidor->num_rows === 0) {
    // Insertar dos repartidores de prueba
    $passHash = password_hash("repartidor123", PASSWORD_BCRYPT);
    
    $conn->query("INSERT INTO usuarios (usuario, password_hash, rol_id, activo) VALUES ('carlos_repartidor', '$passHash', 4, 1)");
    $repId1 = $conn->insert_id;
    $conn->query("INSERT INTO usuario_perfil (usuario_id, nombre, apellido, direccion, telefono, email) 
                  VALUES ($repId1, 'Carlos', 'Gómez', 'Av. de la Libertad, 12, Sevilla', '600987654', 'carlos@ecoali.com')");
                  
    $conn->query("INSERT INTO usuarios (usuario, password_hash, rol_id, activo) VALUES ('ana_repartidor', '$passHash', 4, 1)");
    $repId2 = $conn->insert_id;
    $conn->query("INSERT INTO usuario_perfil (usuario_id, nombre, apellido, direccion, telefono, email) 
                  VALUES ($repId2, 'Ana', 'Belén', 'Plaza de Cataluña, 8, Barcelona', '600555555', 'ana@ecoali.com')");
}

// 3. Verificar y precargar pedidos iniciales de demostración
$checkPedidos = $conn->query("SELECT id FROM pedidos LIMIT 1");
if ($checkPedidos && $checkPedidos->num_rows === 0) {
    // Obtener ids de clientes y repartidores reales
    $cliRow = $conn->query("SELECT usuario_id FROM usuario_perfil WHERE email = 'elena@rivas.com'")->fetch_assoc();
    $repRow = $conn->query("SELECT usuario_id FROM usuario_perfil WHERE email = 'carlos@ecoali.com'")->fetch_assoc();
    $repRow2 = $conn->query("SELECT usuario_id FROM usuario_perfil WHERE email = 'ana@ecoali.com'")->fetch_assoc();
    
    $cliId = $cliRow ? $cliRow['usuario_id'] : 1;
    $repId = $repRow ? $repRow['usuario_id'] : null;
    $repId2 = $repRow2 ? $repRow2['usuario_id'] : null;
    
    $conn->query("INSERT INTO pedidos (cliente_id, repartidor_id, total, estado) VALUES ($cliId, NULL, 45.50, 'pendiente')");
    $conn->query("INSERT INTO pedidos (cliente_id, repartidor_id, total, estado) VALUES ($cliId, $repId, 120.00, 'en_ruta')");
    $conn->query("INSERT INTO pedidos (cliente_id, repartidor_id, total, estado) VALUES ($cliId, $repId2, 32.80, 'entregado')");
}

// --- CONSULTAS DINÁMICAS PARA LOGÍSTICA ---

// Pedidos Totales
$totPedRes = $conn->query("SELECT COUNT(*) FROM pedidos");
$pedidosTotales = $totPedRes ? $totPedRes->fetch_row()[0] : 0;

// Pedidos Pendientes
$pendPedRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente'");
$pedidosPendientes = $pendPedRes ? $pendPedRes->fetch_row()[0] : 0;

// Pedidos En Ruta
$rutaPedRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'en_ruta'");
$pedidosEnRuta = $rutaPedRes ? $rutaPedRes->fetch_row()[0] : 0;

// Pedidos Entregados
$entregPedRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'entregado'");
$pedidosEntregados = $entregPedRes ? $entregPedRes->fetch_row()[0] : 0;

// Obtener listas de clientes y repartidores para selectores
$clientesSelect = $conn->query("SELECT u.id, CONCAT(up.nombre, ' ', up.apellido) AS nombre FROM usuarios u INNER JOIN usuario_perfil up ON u.id = up.usuario_id WHERE u.rol_id = 2 ORDER BY up.nombre ASC");
$repartidoresSelect = $conn->query("SELECT u.id, CONCAT(up.nombre, ' ', up.apellido) AS nombre FROM usuarios u INNER JOIN usuario_perfil up ON u.id = up.usuario_id WHERE u.rol_id = 4 ORDER BY up.nombre ASC");

// Obtener listado de pedidos dinámicos con JOINS
// Los pedidos ATRASADOS (pendiente/en_ruta con más de 24h) aparecen primero
$sqlPedidosList = "SELECT p.*,
                          CONCAT(upc.nombre, ' ', upc.apellido) AS nombre_cliente,
                          upc.direccion AS direccion_cliente,
                          CONCAT(upr.nombre, ' ', upr.apellido) AS nombre_repartidor,
                          CASE
                            WHEN p.estado IN ('pendiente','preparado')
                              AND p.fecha_pedido < NOW() - INTERVAL 24 HOUR
                            THEN 1
                            ELSE 0
                          END AS es_atrasado
                   FROM pedidos p
                   LEFT JOIN usuario_perfil upc ON p.cliente_id = upc.usuario_id
                   LEFT JOIN usuario_perfil upr ON p.repartidor_id = upr.usuario_id
                   ORDER BY es_atrasado DESC, p.id DESC";
$resultPedidos = $conn->query($sqlPedidosList);
$countPedidos = $resultPedidos ? $resultPedidos->num_rows : 0;

// Fetch active delivery drivers as array for JavaScript use
$repartidoresJS = [];
$resRepsJS = $conn->query("SELECT u.id, CONCAT(up.nombre, ' ', up.apellido) AS nombre FROM usuarios u INNER JOIN usuario_perfil up ON u.id = up.usuario_id WHERE u.rol_id = 4 AND u.activo = 1 ORDER BY up.nombre ASC");
if ($resRepsJS) {
    while ($rr = $resRepsJS->fetch_assoc()) {
        $repartidoresJS[] = $rr;
    }
}
$repartidoresJSON = json_encode($repartidoresJS, JSON_HEX_APOS | JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Pedidos y Logística - ECOALI</title>

<link rel="stylesheet" href="assets/css/globals.css">
<link rel="stylesheet" href="assets/css/inventario_admin.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<!-- Leaflet Maps API -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="assets/js/admin_menu.js" defer></script>
<style>
  /* ── Inline repartidor select ── */
  .rep-select {
    width: 100%;
    min-width: 130px;
    height: 34px;
    border: 1px solid rgba(213, 164, 112, 0.4);
    border-radius: 10px;
    background: #fffaf7;
    color: #462800;
    font-family: 'Manrope', sans-serif;
    font-size: 12px;
    font-weight: 700;
    padding: 0 10px;
    cursor: pointer;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23996e3f'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    padding-right: 26px;
  }
  .rep-select:hover   { border-color: #ff8a00; background-color: #fff7ef; }
  .rep-select:focus   { border-color: #ff8a00; box-shadow: 0 0 0 3px rgba(255,138,0,.14); background-color: white; }
  .rep-select.saving  { border-color: #1786ba; background-color: #f0f8ff; pointer-events: none; opacity: .8; }
  .rep-select.saved   { border-color: #176a21; background-color: #f0fff3; }
  .rep-select.error-save { border-color: #b02500; background-color: #fff4f2; }

  /* ── Pedidos atrasados ── */
  .row-atrasado {
    background: linear-gradient(90deg, rgba(176,37,0,.07) 0%, rgba(255,76,28,.04) 100%) !important;
    border-left: 4px solid #b02500 !important;
    position: relative;
    animation: pulse-red-bg 2.5s ease-in-out infinite;
  }
  @keyframes pulse-red-bg {
    0%, 100% { background-color: rgba(176,37,0,.04); }
    50%       { background-color: rgba(176,37,0,.10); }
  }
  .badge-atrasado {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #b02500;
    color: white;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
    padding: 2px 7px;
    border-radius: 999px;
    margin-left: 6px;
    vertical-align: middle;
    animation: blink-badge 1.4s ease-in-out infinite;
    white-space: nowrap;
  }
  @keyframes blink-badge {
    0%, 100% { opacity: 1; }
    50%       { opacity: .45; }
  }
  /* Animación entrada de nueva fila inyectada por AJAX */
  @keyframes fadeInRow {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  /* Texto de la fila atrasada en rojo oscuro */
  .row-atrasado .text-10,
  .row-atrasado .text-11,
  .row-atrasado .text-12,
  .row-atrasado .text-14 {
    color: #7a1a00 !important;
  }

  /* ── Botón Atrasados: mantener texto rojo en ambos estados ── */
  .btn-atrasados { border-color: rgba(176,37,0,0.35) !important; }
  .btn-atrasados .text,
  .btn-atrasados .text-2 { color: #b02500 !important; font-weight: 800 !important; }
  .btn-atrasados.button  { background: rgba(176,37,0,0.10) !important; }

  /* ── Panel de asignación rápida (solo visible en filtro Atrasados) ── */
  #panel-asignar-atrasados {
    margin-bottom: 18px;
    background: linear-gradient(135deg, rgba(176,37,0,0.07) 0%, rgba(255,76,28,0.04) 100%);
    border: 1.5px solid rgba(176,37,0,0.30);
    border-radius: 16px;
    padding: 18px 24px;
    flex-direction: column;
    gap: 10px;
    animation: fadeInRow .3s ease-out;
  }
  .panel-atrasados-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 4px;
  }
  .panel-atrasados-title {
    font-size: 14px;
    font-weight: 800;
    color: #b02500;
    letter-spacing: .3px;
  }
  .panel-atrasados-desc {
    font-size: 12px;
    font-weight: 600;
    color: #7a1a00;
    line-height: 1.5;
    margin-bottom: 4px;
  }
  .panel-atrasados-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }
  .rep-select-masivo {
    min-width: 230px;
    height: 40px;
    border: 1.5px solid rgba(176,37,0,0.35);
    border-radius: 10px;
    background: #fff8f7;
    color: #462800;
    font-family: 'Manrope', sans-serif;
    font-size: 13px;
    font-weight: 700;
    padding: 0 14px;
    cursor: pointer;
    outline: none;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23b02500'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 30px;
    transition: border-color .2s, box-shadow .2s;
  }
  .rep-select-masivo:hover { border-color: #b02500; background-color: #fff4f2; }
  .rep-select-masivo:focus { border-color: #b02500; box-shadow: 0 0 0 3px rgba(176,37,0,.14); }
  .btn-asignar-masivo {
    height: 40px;
    padding: 0 22px;
    background: linear-gradient(90deg, #b02500, #ff4c1c);
    color: white;
    border: none;
    border-radius: 10px;
    font-family: 'Manrope', sans-serif;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: opacity .2s, transform .15s;
    box-shadow: 0 8px 20px rgba(176,37,0,0.22);
    white-space: nowrap;
  }
  .btn-asignar-masivo:hover  { opacity: .88; transform: translateY(-1px); }
  .btn-asignar-masivo:active { transform: translateY(0); }
  #panel-atrasados-feedback {
    font-size: 12px;
    font-weight: 700;
    padding: 7px 14px;
    border-radius: 8px;
    border: 1px solid;
  }
</style>
</head>

<body>
<div class="gestin-de-inventario">

  <div class="organic-background"></div>
  <div class="background-blur"></div>
  <div class="div"></div>

  <div class="aside">
    <div class="margin">
      <div class="container-13">
        <div class="div-4"><div class="text-27">ECOALI</div></div>
      </div>
    </div>

    <div class="margin-2">
      <div class="background-10">
        <div class="avatar"></div>
        <div class="div-4">
          <div class="div-2"><div class="text-28"><?php echo htmlspecialchars($nombre); ?></div></div>
          <div class="div-2"><div class="text-29">Gestión Pro</div></div>
        </div>
      </div>
    </div>

    <div class="nav">
      <?php
      $current_page = basename($_SERVER['PHP_SELF']);
      $menu_items = [
          'dashboard_admin.php' => 'Dashboard',
          'usuarios_admin.php' => 'Usuarios',
          'clientes_admin.php' => 'Clientes',
          'proveedores_admin.php' => 'Proveedores',
          'inventario_admin.php' => 'Inventario',
          'productos_admin.php' => 'Productos',
          'logistica_admin.php' => 'Logística',
          'reportes_admin.php' => 'Reportes',
          'regalias_admin.php' => 'Regalías',
          'bitacora_admin.php' => 'Bitácora',
          'cedis_admin.php' => 'CEDIS'
      ];

      foreach ($menu_items as $href => $label) {
          $link_href = $href;
          $active = ($current_page === $href);
          
          if ($active) {
              echo '
              <a class="link-active-state" href="' . $link_href . '">
                <div class="link-active-state-2"></div>
                <div class="div-4"><div class="text-31">' . $label . '</div></div>
              </a>';
          } else {
              echo '
              <a class="link" href="' . $link_href . '">
                <div class="div-4"><div class="text-30">' . $label . '</div></div>
              </a>';
          }
      }
      ?>
    </div>

    <div class="button-wrapper">
      <a href="logout.php" class="button-5">
        <div class="container-3"><div class="text-32">Cerrar Sesión</div></div>
      </a>
    </div>
  </div>

  <div class="header-topappbar">
    <div class="div-4"><div class="text-26">Gestión de Pedidos y Logística</div></div>

    <div style="display: flex; gap: 12px;">
      <button class="button-2" style="background: linear-gradient(90deg, #ff8a00, #ffb300); box-shadow: 0 18px 35px rgba(255, 138, 0, 0.15);" onclick="abrirModalImprimir()"><div class="text-3">Imprimir Pedidos</div></button>
      <button class="button-2" onclick="abrirModalCrear()"><div class="text-3">Asignar pedido</div></button>
    </div>
  </div>

  <div class="main-content">

    <!-- Alertas del sistema -->
    <?php if (isset($_SESSION["mensaje_exito"])): ?>
        <div class="alert-container">
            <div class="alert alert-success">
                <span>✓</span> <?php echo $_SESSION["mensaje_exito"]; unset($_SESSION["mensaje_exito"]); ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION["mensaje_error"])): ?>
        <div class="alert-container">
            <div class="alert alert-danger">
                <span>✗</span> <?php echo $_SESSION["mensaje_error"]; unset($_SESSION["mensaje_error"]); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="section-search">
      <div class="container">
        <div class="input">
          <div class="container">
            <input type="text" id="buscarPedido" placeholder="Buscar por ID de pedido, cliente o dirección..." onkeyup="filtrarTabla()" style="border:none; width:100%; outline:none; font-family:inherit; color:#462800; font-size:13px; font-weight:600;">
          </div>
        </div>
      </div>

      <div class="container-2">
        <div class="background-shadow">
          <button class="button" onclick="filtrarEstado('todos', this)"><div class="text">Todos</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('pendiente', this)"><div class="text-2">Pendiente</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('en_ruta', this)"><div class="text-2">En ruta</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('entregado', this)"><div class="text-2">Entregado</div></button>
          <button class="div-wrapper" onclick="filtrarEstado('cancelado', this)"><div class="text-2">Cancelado</div></button>
          <button class="div-wrapper btn-atrasados" onclick="filtrarEstado('atrasado', this)" style="border-color: rgba(176,37,0,0.35); position:relative;"><div class="text-2" style="color:#b02500;">⚠ Atrasados</div></button>
        </div>
      </div>
    </div>

    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
      <div class="background-border">
        <div class="container-5"><div class="text-wrapper-2">Pedidos Totales</div></div>
        <div class="div-2"><div class="text-wrapper-3" id="contador-totales"><?php echo $pedidosTotales; ?></div></div>
      </div>

      <div class="background-border-2">
        <div class="container-5"><div class="text-wrapper-2">Pedidos Pendientes</div></div>
        <div class="div-2"><div class="text-wrapper-3" id="contador-pendientes"><?php echo $pedidosPendientes; ?></div></div>
      </div>

      <div class="background-border-3">
        <div class="container-5"><div class="text-wrapper-2">Pedidos En Ruta</div></div>
        <div class="div-2"><div class="text-wrapper-3" id="contador-en-ruta"><?php echo $pedidosEnRuta; ?></div></div>
      </div>

      <div class="background-border-2" style="border-color: rgba(23, 106, 33, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #176a21;">Pedidos Entregados</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #176a21;" id="contador-entregados"><?php echo $pedidosEntregados; ?></div></div>
      </div>
    </div>

    <!-- ══ Panel de asignación rápida — solo visible con filtro Atrasados ══ -->
    <div id="panel-asignar-atrasados" style="display:none;">
      <div class="panel-atrasados-header">
        <span style="font-size:22px;">⚠️</span>
        <span class="panel-atrasados-title">Pedidos Atrasados — Asignación Rápida de Repartidor</span>
      </div>
      <div class="panel-atrasados-desc">
        Estos pedidos superaron las 24 horas sin ser atendidos. Selecciona un repartidor y asígnalo a todos de inmediato:
      </div>
      <div class="panel-atrasados-controls">
        <select id="rep-masivo-atrasados" class="rep-select-masivo">
          <option value="">🔍 Seleccionar repartidor...</option>
          <?php foreach ($repartidoresJS as $rr): ?>
            <option value="<?php echo $rr['id']; ?>"><?php echo htmlspecialchars($rr['nombre']); ?></option>
          <?php endforeach; ?>
        </select>
        <button onclick="asignarRepartidorAtrasados()" class="btn-asignar-masivo">📦 Asignar a todos los atrasados</button>
        <div id="panel-atrasados-feedback" style="display:none;"></div>
      </div>
    </div>

    <div class="inventory-table">
      <div class="header">
        <div class="row" style="grid-template-columns: 1fr 1.8fr 2.5fr 1.8fr 1.2fr 1.8fr 1.2fr 1.3fr;">
          <div><div class="text-7">ID PEDIDO</div></div>
          <div><div class="text-7">CLIENTE</div></div>
          <div><div class="text-7">DIRECCIÓN</div></div>
          <div><div class="text-8">REPARTIDOR</div></div>
          <div><div class="text-7">ESTADO</div></div>
          <div><div class="text-7">FECHA</div></div>
          <div><div class="text-7">TOTAL</div></div>
          <div><div class="text-9">ACCIONES</div></div>
        </div>
      </div>

      <div id="tablaCuerpo">
      <?php if ($countPedidos > 0): ?>
          <?php while ($row = $resultPedidos->fetch_assoc()): 
              $estado = strtolower($row["estado"]);
              $estadoClass = "overlay-10";
              $bgClass = "background-9";
              $estadoText = "Pendiente";

              if ($estado === "preparado") {
                  $estadoClass = "overlay-7";
                  $bgClass = "background-5";
                  $estadoText = "Preparado";
              } elseif ($estado === "en_ruta") {
                  $estadoClass = "overlay-7";
                  $bgClass = "background-5";
                  $estadoText = "En Ruta";
              } elseif ($estado === "entregado") {
                  $estadoClass = "overlay-6";
                  $bgClass = "background-3";
                  $estadoText = "Entregado";
              } elseif ($estado === "cancelado") {
                  $estadoClass = "overlay-9";
                  $bgClass = "background-7";
                  $estadoText = "Cancelado";
              }
          ?>
          <?php 
              $esAntiguo = strtotime($row['fecha_pedido']) < (time() - 24 * 3600);
              $esAtrasado = $esAntiguo && ($estado === 'pendiente' || $estado === 'preparado');
              $rowExtraClass = $esAtrasado ? ' row-atrasado' : '';
              $rowAtrasadoData = $esAtrasado ? ' data-atrasado="1"' : '';
              $rowAntiguoData = $esAntiguo ? ' data-antiguo="1"' : '';
          ?>
          <div class="div-3 row-pedido<?php echo $rowExtraClass; ?>" data-estado="<?php echo $estado; ?>" data-cliente-id="<?php echo $row['cliente_id']; ?>"<?php echo $rowAtrasadoData; ?><?php echo $rowAntiguoData; ?> style="grid-template-columns: 1fr 1.8fr 2.5fr 1.8fr 1.2fr 1.8fr 1.2fr 1.3fr; border-bottom: 1px solid rgba(213,164,112,.12);">
            <div><div class="text-10">
               #PED-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?>
               <span class="badge-atrasado" style="<?php echo $esAtrasado ? '' : 'display:none;'; ?>">⚠ ATRASADO</span>
            </div></div>
            <div><div class="text-11" style="font-weight: 700;"><?php echo htmlspecialchars($row["nombre_cliente"] ?? "Cliente Anónimo"); ?></div></div>
            <div><div class="text-12"><?php echo htmlspecialchars($row["direccion_cliente"] ?? "Sin dirección de entrega"); ?></div></div>
            <div>
              <?php
              $currentRepId = $row["repartidor_id"] ? (int)$row["repartidor_id"] : '';
              ?>
              <select
                class="rep-select"
                id="rep-select-<?php echo $row['id']; ?>"
                onchange="asignarRepartidor(<?php echo $row['id']; ?>, this)"
                title="Cambiar repartidor asignado"
                <?php echo ($estado === 'cancelado') ? 'disabled' : ''; ?>
              >
                <option value=""<?php echo $currentRepId === '' ? ' selected' : ''; ?>>— Sin asignar —</option>
                <?php foreach ($repartidoresJS as $rr): ?>
                  <option value="<?php echo $rr['id']; ?>"<?php echo ((int)$rr['id'] === $currentRepId) ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars($rr['nombre']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <div class="<?php echo $estadoClass; ?>">
                <div class="<?php echo $bgClass; ?>"></div>
                <div class="text-15" style="color: inherit;"><?php echo $estadoText; ?></div>
              </div>
            </div>
            <div><div class="text-14"><?php echo date("d M Y, H:i", strtotime($row["fecha_pedido"])); ?></div></div>
            <div>
              <div class="background-2">
                <div class="text-13">$<?php echo number_format($row["total"], 2); ?></div>
              </div>
            </div>
            <div>
              <div class="text-10" style="display: flex; gap: 8px;">
                <button class="action-btn action-btn-edit" title="Gestionar Pedido / Repartidor" onclick="abrirModalEditar(<?php echo $row['id']; ?>)">✎</button>
                <button class="action-btn action-btn-delete" title="Cancelar Pedido" onclick="confirmarEliminar(<?php echo $row['id']; ?>)">🗑</button>
              </div>
            </div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 30px;">
              <div class="text-11" style="color: #996e3f;">No hay pedidos en la base de datos de logística.</div>
          </div>
      <?php endif; ?>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p" id="paginacionTexto">MOSTRANDO <?php echo $countPedidos; ?> PEDIDOS</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

    <div class="inventory-forecast">
      <div class="overlay-11">
        <div class="heading"><div class="text-wrapper-4">Ruta de Reparto</div></div>
        <p class="text-25">Optimización en tiempo real de rutas, repartidores asignados y control de estado.</p>
      </div>

      <div class="overlay-12">
        <div class="heading"><div class="text-wrapper-5">Alertas de Reparto</div></div>
        <p class="text-25">Todos los repartidores activos están monitoreados. Recuerda asignar los pedidos pendientes con prioridad.</p>
      </div>
    </div>

  </div>
</div>

<!-- ==========================================
     MODALES (CREATE, EDIT, DELETE)
     ========================================== -->

<!-- Modal Opciones de Impresión -->
<div class="modal-overlay" id="modalImprimir">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title">Imprimir Registro de Pedidos</div>
      <button class="modal-close" onclick="cerrarModal('modalImprimir')">×</button>
    </div>
    <div style="display: flex; flex-direction: column; gap: 14px; margin-top: 10px; margin-bottom: 10px;">
      <p style="color: #7a5427; font-size: 13px; line-height: 1.5; margin-bottom: 8px;">
        Selecciona la categoría de pedidos que deseas imprimir o guardar en formato PDF:
      </p>
      
      <a href="imprimir_pedidos.php?filtro=todos" target="_blank" class="btn-submit" style="text-align: center; text-decoration: none; display: block; background: #8d4a00; color: white;" onclick="cerrarModal('modalImprimir')">
        🖨️ Imprimir Todos los Pedidos
      </a>
      
      <a href="imprimir_pedidos.php?filtro=entregados" target="_blank" class="btn-submit" style="text-align: center; text-decoration: none; display: block; background: #176a21; color: white;" onclick="cerrarModal('modalImprimir')">
        🖨️ Imprimir Pedidos Entregados
      </a>
      
      <a href="imprimir_pedidos.php?filtro=cancelados" target="_blank" class="btn-submit" style="text-align: center; text-decoration: none; display: block; background: #b02500; color: white;" onclick="cerrarModal('modalImprimir')">
        🖨️ Imprimir Pedidos Cancelados
      </a>
      
      <a href="imprimir_pedidos.php?filtro=no_asignados" target="_blank" class="btn-submit" style="text-align: center; text-decoration: none; display: block; background: #ff8a00; color: white;" onclick="cerrarModal('modalImprimir')">
        🖨️ Imprimir Pedidos No Asignados
      </a>
    </div>
    <div class="modal-actions" style="margin-top: 20px;">
      <button type="button" class="btn-cancel" onclick="cerrarModal('modalImprimir')">Cerrar</button>
    </div>
  </div>
</div>

<!-- Modal Crear/Asignar Pedido -->
<div class="modal-overlay" id="modalCrear">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Asignar / Crear Pedido</div>
      <button class="modal-close" onclick="cerrarModal('modalCrear')">×</button>
    </div>
    <form id="formCrearPedido">
      <input type="hidden" name="accion" value="crear">

      <div class="form-group">
        <label class="form-label">Cliente *</label>
        <select name="cliente_id" id="crear_cliente_id" class="form-select" required onchange="cargarPedidosCliente(this.value)">
          <option value="">Seleccione un cliente...</option>
          <?php if ($clientesSelect && $clientesSelect->num_rows > 0): ?>
              <?php while ($c = $clientesSelect->fetch_assoc()): ?>
                  <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option>
              <?php endwhile; ?>
          <?php endif; ?>
        </select>
        <!-- Alerta en tiempo real de pedidos activos del cliente -->
        <div id="cli-pedidos-info" style="
            display:none;
            margin-top:8px;
            padding:10px 14px;
            border-radius:10px;
            background:#fff7ef;
            border:1px solid rgba(255,138,0,0.22);
            font-size:12px;
            font-weight:700;
            color:#7a4500;
            display:flex;
            flex-direction:column;
            gap:6px;
        ">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Repartidor (Opcional)</label>
        <select name="repartidor_id" id="crear_repartidor_id" class="form-select"
                onchange="cargarPedidosRepartidor(this.value)">
          <option value="">— Sin asignar —</option>
          <?php if ($repartidoresSelect && $repartidoresSelect->num_rows > 0): ?>
              <?php while ($r = $repartidoresSelect->fetch_assoc()): ?>
                  <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['nombre']); ?></option>
              <?php endwhile; ?>
          <?php endif; ?>
        </select>
        <!-- Contador en tiempo real de pedidos activos del repartidor -->
        <div id="rep-pedidos-info" style="
            display:none;
            margin-top:8px;
            padding:10px 14px;
            border-radius:10px;
            background:#fff7ef;
            border:1px solid rgba(255,138,0,0.22);
            font-size:12px;
            font-weight:700;
            color:#7a4500;
            display:flex;
            align-items:center;
            gap:8px;
        ">
          <span style="font-size:18px;">📦</span>
          <span id="rep-pedidos-texto">...</span>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Estado de Logística</label>
        <select name="estado" id="crear_estado" class="form-select">
          <option value="pendiente" selected>Pendiente</option>
          <option value="preparado">Preparado</option>
          <option value="en_ruta">En Ruta</option>
          <option value="entregado">Entregado</option>
          <option value="cancelado">Cancelado</option>
        </select>
      </div>

      <div id="crear-msg" style="display:none; font-size:12px; font-weight:700; padding:8px 12px; border-radius:8px; margin-bottom:10px;"></div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalCrear')">Cancelar</button>
        <button type="submit" class="btn-submit" id="btn-crear-submit">Asignar Pedido</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar Pedido -->
<div class="modal-overlay" id="modalEditar">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-title">Editar Detalles del Pedido</div>
      <button class="modal-close" onclick="cerrarModal('modalEditar')">×</button>
    </div>
    <form action="forms/logistica_acciones.php" method="POST">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">
      
      <div class="form-group">
        <label class="form-label">Cliente *</label>
        <select name="cliente_id" id="edit_cliente_id" class="form-select" required>
          <?php 
          if ($clientesSelect) $clientesSelect->data_seek(0);
          while ($c = $clientesSelect->fetch_assoc()): ?>
              <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Repartidor Asignado</label>
        <select name="repartidor_id" id="edit_repartidor_id" class="form-select">
          <option value="">No asignado</option>
          <?php 
          if ($repartidoresSelect) $repartidoresSelect->data_seek(0);
          while ($r = $repartidoresSelect->fetch_assoc()): ?>
              <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['nombre']); ?></option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Total del Pedido ($) (Costo Fijo Inmutable) 🔒</label>
        <input type="number" step="0.01" name="total" id="edit_total" class="form-input" readonly style="background:#f9f6f0; color:#8c7864; cursor:not-allowed; font-weight:700; border-color:#e0d5c1;" required>
      </div>

      <div class="form-group">
        <label class="form-label">Estado de Logística</label>
        <select name="estado" id="edit_estado" class="form-select">
          <option value="pendiente">Pendiente</option>
          <option value="preparado">Preparado</option>
          <option value="en_ruta">En Ruta</option>
          <option value="entregado">Entregado</option>
          <option value="cancelado">Cancelado</option>
        </select>
      </div>

      <!-- Real-Time GPS Tracking Map for Admins -->
      <div class="form-group" id="admin-map-container" style="display: none; margin-top: 15px;">
        <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
          <span>📍 Ubicación del Repartidor (Tiempo Real)</span>
          <span id="admin-gps-status-time" style="font-size: 11px; color: var(--secondary); font-weight: 800;">Conectando...</span>
        </label>
        <div id="admin-tracking-map" style="height: 240px; width: 100%; border-radius: 12px; border: 1px solid rgba(213,164,112,0.22); background: #f3f3f0; z-index: 1;"></div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEditar')">Cancelar</button>
        <button type="submit" class="btn-submit">Guardar Cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Eliminar Pedido -->
<div class="modal-overlay" id="modalEliminar">
  <div class="modal-container" style="max-width: 440px;">
    <div class="modal-header">
      <div class="modal-title" style="color: #b02500;">Eliminar Pedido</div>
      <button class="modal-close" onclick="cerrarModal('modalEliminar')">×</button>
    </div>
    <form action="forms/logistica_acciones.php" method="POST">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" id="delete_id">
      
      <p style="color: #7a5427; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
        ¿Estás seguro de que deseas eliminar permanentemente el pedido <strong id="delete_pedido_text" style="color: #462800;"></strong>?<br>Esta acción eliminará de forma irreversible el pedido del registro de logística.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
        <button type="submit" class="btn-submit" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 10px 25px rgba(176, 37, 0, 0.15);">Eliminar Pedido</button>
      </div>
    </form>
  </div>
</div>

<!-- ==========================================
     SCRIPTS DE CONTROL INTERACTIVO
     ========================================== -->
<script>
function abrirModalImprimir() {
    document.getElementById('modalImprimir').classList.add('active');
}

function abrirModalCrear() {
    // Reset form
    document.getElementById('formCrearPedido').reset();
    const info = document.getElementById('rep-pedidos-info');
    info.style.display = 'none';
    const cliInfo = document.getElementById('cli-pedidos-info');
    if (cliInfo) cliInfo.style.display = 'none';
    const msg = document.getElementById('crear-msg');
    msg.style.display = 'none';
    document.getElementById('btn-crear-submit').disabled = false;
    document.getElementById('modalCrear').classList.add('active');
}

/** Carga en tiempo real: muestra solo pedidos PENDIENTES del cliente, atrasados primero */
function cargarPedidosCliente(clienteId) {
    const info = document.getElementById('cli-pedidos-info');
    if (!info) return;
    if (!clienteId) { info.style.display = 'none'; return; }

    info.style.display = 'flex';
    info.innerHTML = '<span style="color: #7a4500;">Consultando pedidos pendientes del cliente...</span>';
    
    const selectCli = document.getElementById('crear_cliente_id');
    const clienteNombre = selectCli.options[selectCli.selectedIndex].text;

    fetch('forms/logistica_acciones.php?accion=pedidos_cliente&cliente_id=' + clienteId)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'success' || res.count === 0) {
                // Sin pedidos pendientes
                info.innerHTML = `
                    <div style="display:flex; align-items:center; gap:8px; color:#176a21;">
                        <span style="font-size:16px;">✅</span>
                        <span>${clienteNombre} no tiene pedidos pendientes por asignar.</span>
                    </div>`;
                info.style.background = '#f0fff3';
                info.style.borderColor = 'rgba(23,106,33,0.25)';
                info.style.color = '#176a21';
                return;
            }

            // Separar atrasados y normales
            const atrasados = res.pedidos.filter(p => p.es_atrasado);
            const normales  = res.pedidos.filter(p => !p.es_atrasado);

            const headerColor  = atrasados.length > 0 ? '#b02500' : '#7a4500';
            const headerIcon   = atrasados.length > 0 ? '⚠️' : 'ℹ️';
            const headerBg     = atrasados.length > 0 ? '#fff4f2' : '#fffaf7';
            const headerBorder = atrasados.length > 0 ? 'rgba(176,37,0,0.25)' : 'rgba(213,164,112,0.25)';

            let html = `
                <div style="display:flex; align-items:center; gap:8px; color:${headerColor}; font-size:13px; margin-bottom:6px;">
                    <span style="font-size:18px;">${headerIcon}</span>
                    <span>
                        <strong>${clienteNombre}</strong> tiene
                        <strong>${res.count} pedido${res.count !== 1 ? 's' : ''} pendiente${res.count !== 1 ? 's' : ''}</strong>
                        ${atrasados.length > 0 ? `— <span style="color:#b02500; font-weight:800;">⚠ ${atrasados.length} ATRASADO${atrasados.length !== 1 ? 'S' : ''} (prioridad alta)</span>` : ''}
                    </span>
                </div>`;

            // Listar atrasados primero con fondo rojo
            if (atrasados.length > 0) {
                html += `<div style="font-size:11px; font-weight:800; color:#b02500; margin-bottom:3px; letter-spacing:.3px;">🔴 ATRASADOS — PRIORIDAD MÁXIMA</div>
                         <ul style="margin:0 0 6px 0; padding-left:18px; font-size:11px; color:#7a1a00;">`;
                atrasados.forEach(p => {
                    const totalFmt = parseFloat(p.total).toFixed(2);
                    const fechaF   = new Date(p.fecha_pedido).toLocaleDateString('es-MX',
                        {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'});
                    html += `<li style="margin-bottom:2px;">
                                <strong>#PED-${String(p.id).padStart(3,'0')}</strong>
                                <span style="background:#b02500;color:white;font-size:9px;font-weight:800;padding:1px 5px;border-radius:999px;margin:0 4px;">ATRASADO</span>
                                Fecha: ${fechaF} · $${totalFmt}
                             </li>`;
                });
                html += `</ul>`;
            }

            // Listar pendientes normales
            if (normales.length > 0) {
                html += `<div style="font-size:11px; font-weight:700; color:#7a4500; margin-bottom:3px;">🟡 PENDIENTES</div>
                         <ul style="margin:0 0 6px 0; padding-left:18px; font-size:11px; color:#7a4500;">`;
                normales.forEach(p => {
                    const totalFmt = parseFloat(p.total).toFixed(2);
                    const fechaF   = new Date(p.fecha_pedido).toLocaleDateString('es-MX',
                        {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'});
                    html += `<li style="margin-bottom:2px;">
                                <strong>#PED-${String(p.id).padStart(3,'0')}</strong>
                                Fecha: ${fechaF} · $${totalFmt}
                             </li>`;
                });
                html += `</ul>`;
            }

            // Botón para ver en tabla
            html += `
                <div style="margin-top:6px;">
                    <button type="button" onclick="verPedidosDeCliente('${clienteNombre.replace(/'/g, "\\'")}')" style="
                        background:#ff8a00; color:white; border:none; padding:5px 12px;
                        border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; transition:background .2s;"
                        onmouseover="this.style.background='#e07b00'" onmouseout="this.style.background='#ff8a00'">
                        🔍 Ver en tabla principal
                    </button>
                    ${atrasados.length > 0 ? `<button type="button" onclick="filtrarAtrasadosYCerrar()" style="
                        background:#b02500; color:white; border:none; padding:5px 12px;
                        border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; margin-left:6px; transition:background .2s;"
                        onmouseover="this.style.background='#8c1d00'" onmouseout="this.style.background='#b02500'">
                        ⚠ Ver solo atrasados
                    </button>` : ''}
                </div>`;

            info.innerHTML = html;
            info.style.background  = headerBg;
            info.style.borderColor = headerBorder;
            info.style.color       = headerColor;
        })
        .catch(() => {
            info.innerHTML = '<span style="color: #b02500;">Error al consultar pedidos del cliente.</span>';
        });
}

/** Cierra el modal y aplica filtro 'atrasado' en la tabla */
function filtrarAtrasadosYCerrar() {
    cerrarModal('modalCrear');
    // Activar botón de atrasados en la barra de filtros
    const btns = document.querySelectorAll('.background-shadow button');
    btns.forEach(b => {
        b.className = 'div-wrapper';
        if (b.querySelector('div')) b.querySelector('div').className = 'text-2';
    });
    const btnAtrasados = document.querySelector('.btn-atrasados');
    if (btnAtrasados) {
        btnAtrasados.className = 'button btn-atrasados';
        if (btnAtrasados.querySelector('div')) btnAtrasados.querySelector('div').className = 'text';
        btnAtrasados.querySelector('div').style.color = '#b02500';
    }
    filtroEstado = 'atrasado';
    paginaActual = 1;
    actualizarVista();
}

/** Cierra el modal y escribe el nombre del cliente en el input de búsqueda para filtrar */
function verPedidosDeCliente(clienteNombre) {
    cerrarModal('modalCrear');
    const buscarInput = document.getElementById('buscarPedido');
    if (buscarInput) {
        buscarInput.value = clienteNombre;
        filtrarTabla();
    }
}

/** Carga en tiempo real cuántos pedidos activos tiene el repartidor seleccionado */
function cargarPedidosRepartidor(repId) {
    const info  = document.getElementById('rep-pedidos-info');
    const texto = document.getElementById('rep-pedidos-texto');
    if (!repId) { info.style.display = 'none'; return; }

    texto.textContent = 'Consultando...';
    info.style.display = 'flex';

    fetch('forms/logistica_acciones.php?accion=pedidos_repartidor&rep_id=' + repId)
        .then(r => r.json())
        .then(res => {
            const n = res.count ?? 0;
            const repName = document.getElementById('crear_repartidor_id')
                              .options[document.getElementById('crear_repartidor_id').selectedIndex].text;
            if (n === 0) {
                texto.textContent = repName + ' no tiene pedidos activos asignados. ✅';
                info.style.background = '#f0fff3';
                info.style.borderColor = 'rgba(23,106,33,0.25)';
                info.style.color = '#176a21';
            } else {
                texto.textContent = repName + ' tiene ' + n + ' pedido' + (n !== 1 ? 's' : '') + ' activo' + (n !== 1 ? 's' : '') + ' por entregar.';
                info.style.background = '#fff7ef';
                info.style.borderColor = 'rgba(255,138,0,0.3)';
                info.style.color = '#7a4500';
            }
        })
        .catch(() => { texto.textContent = 'No se pudo consultar la carga del repartidor.'; });
}

/** Submit AJAX del modal Crear — inyecta la fila en la tabla sin recargar */
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('formCrearPedido').addEventListener('submit', function (e) {
        e.preventDefault();

        const btn = document.getElementById('btn-crear-submit');
        const msg = document.getElementById('crear-msg');
        btn.disabled = true;
        btn.textContent = 'Guardando...';
        msg.style.display = 'none';

        const body = new FormData(this);

        fetch('forms/logistica_acciones.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body
        })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.textContent = 'Asignar Pedido';

            if (res.status !== 'success') {
                msg.textContent = '⚠ ' + (res.message || 'Error al crear el pedido.');
                msg.style.display = 'block';
                msg.style.background = '#fff4f2';
                msg.style.color = '#b02500';
                msg.style.border = '1px solid rgba(176,37,0,0.2)';
                return;
            }

            // Cerrar modal y mostrar feedback
            cerrarModal('modalCrear');
            const p = res.pedido;

            // Construir nueva fila y prependerla al tablaCuerpo
            const padId = String(p.id).padStart(3, '0');
            const fecha = new Date(p.fecha_pedido).toLocaleDateString('es-MX', {
                day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'
            });
            const repSelect = document.getElementById('crear_repartidor_id');
            const repId     = repSelect.value;

            // Construir opciones del select de repartidores para la nueva fila usando el listado oficial de PHP
            const repartidores = <?php echo $repartidoresJSON; ?>;
            let repOptionsHTML = '<option value="">— Sin asignar —</option>';
            repartidores.forEach(rr => {
                const selectedAttr = (String(rr.id) === String(repId)) ? ' selected' : '';
                repOptionsHTML += `<option value="${rr.id}"${selectedAttr}>${rr.nombre}</option>`;
            });

            // Generar badge del estado dinámico
            const estado = (p.estado || "pendiente").toLowerCase();
            let estadoClass = "overlay-10";
            let bgClass = "background-9";
            let estadoText = "Pendiente";

            if (estado === "preparado") {
                estadoClass = "overlay-7";
                bgClass = "background-5";
                estadoText = "Preparado";
            } else if (estado === "en_ruta") {
                estadoClass = "overlay-7";
                bgClass = "background-5";
                estadoText = "En Ruta";
            } else if (estado === "entregado") {
                estadoClass = "overlay-6";
                bgClass = "background-3";
                estadoText = "Entregado";
            } else if (estado === "cancelado") {
                estadoClass = "overlay-9";
                bgClass = "background-7";
                estadoText = "Cancelado";
            }

            const formattedTotal = parseFloat(p.total || 0).toFixed(2);

            const newRow = document.createElement('div');
            newRow.className = 'div-3 row-pedido';
            newRow.setAttribute('data-estado', estado);
            newRow.setAttribute('data-cliente-id', p.cliente_id);
            newRow.style.cssText = 'grid-template-columns: 1fr 1.8fr 2.5fr 1.8fr 1.2fr 1.8fr 1.2fr 1.3fr; border-bottom: 1px solid rgba(213,164,112,.12); animation: fadeInRow .4s ease-out;';
            newRow.innerHTML = `
              <div><div class="text-10">#PED-${padId} <span class="badge-atrasado" style="display:none;">⚠ ATRASADO</span></div></div>
              <div><div class="text-11" style="font-weight:700;">${p.nombre_cliente || 'Cliente'}</div></div>
              <div><div class="text-12">${p.direccion_cliente || 'Sin dirección'}</div></div>
              <div>
                <select class="rep-select" id="rep-select-${p.id}"
                        onchange="asignarRepartidor(${p.id}, this)"
                        title="Cambiar repartidor asignado"
                        ${estado === 'cancelado' ? 'disabled' : ''}>
                  ${repOptionsHTML}
                </select>
              </div>
              <div>
                <div class="${estadoClass}">
                  <div class="${bgClass}"></div>
                  <div class="text-15" style="color:inherit;">${estadoText}</div>
                </div>
              </div>
              <div><div class="text-14">${fecha}</div></div>
              <div>
                <div class="background-2">
                  <div class="text-13">$${formattedTotal}</div>
                </div>
              </div>
              <div>
                <div class="text-10" style="display:flex; gap:8px;">
                  <button class="action-btn action-btn-edit" title="Gestionar Pedido"
                          onclick="abrirModalEditar(${p.id})">✎</button>
                  <button class="action-btn action-btn-delete" title="Cancelar Pedido"
                          onclick="confirmarEliminar(${p.id})">🗑</button>
                </div>
              </div>`;

            const cuerpo = document.getElementById('tablaCuerpo');
            cuerpo.insertBefore(newRow, cuerpo.firstChild);

            // Ajustar los contadores superiores de estado para los pedidos existentes de este cliente que van a cambiar
            let diffPendientes = 0;
            let diffEnRuta = 0;
            let diffEntregados = 0;

            document.querySelectorAll(`.row-pedido[data-cliente-id="${p.cliente_id}"]`).forEach(row => {
                if (row !== newRow) {
                    const oldEstado = row.getAttribute('data-estado');
                    if (oldEstado === 'cancelado') {
                        return; // Excluir pedidos que ya estaban cancelados
                    }
                    if (oldEstado !== estado) {
                        // Decrementar del estado anterior
                        if (oldEstado === 'pendiente') diffPendientes--;
                        else if (oldEstado === 'en_ruta') diffEnRuta--;
                        else if (oldEstado === 'entregado') diffEntregados--;

                        // Incrementar en el nuevo estado
                        if (estado === 'pendiente') diffPendientes++;
                        else if (estado === 'en_ruta') diffEnRuta++;
                        else if (estado === 'entregado') diffEntregados++;
                    }
                }
            });

            // Aplicar diferencias a los contadores en el DOM
            if (diffPendientes !== 0) {
                const el = document.getElementById('contador-pendientes');
                if (el) el.textContent = parseInt(el.textContent || 0) + diffPendientes;
            }
            if (diffEnRuta !== 0) {
                const el = document.getElementById('contador-en-ruta');
                if (el) el.textContent = parseInt(el.textContent || 0) + diffEnRuta;
            }
            if (diffEntregados !== 0) {
                const el = document.getElementById('contador-entregados');
                if (el) el.textContent = parseInt(el.textContent || 0) + diffEntregados;
            }

            // Actualizar todos los selects, atributos data-estado y badges de estado de este cliente en la tabla
            document.querySelectorAll(`.row-pedido[data-cliente-id="${p.cliente_id}"]`).forEach(row => {
                if (row !== newRow && row.getAttribute('data-estado') === 'cancelado') {
                    return; // Ignorar pedidos ya cancelados
                }

                // 1. Repartidor
                const selectEl = row.querySelector('.rep-select');
                if (selectEl) {
                    selectEl.value = repId;
                    if (estado === 'cancelado') {
                        selectEl.disabled = true; // Desactivar si el nuevo estado es cancelado
                    }
                }

                // 2. data-estado
                row.setAttribute('data-estado', estado);

                // 3. Badge
                const statusContainer = row.children[4];
                if (statusContainer) {
                    statusContainer.innerHTML = `
                        <div class="${estadoClass}">
                          <div class="${bgClass}"></div>
                          <div class="text-15" style="color:inherit;">${estadoText}</div>
                        </div>
                    `;
                }

                // 4. Actualizar estado "Atrasado" si es un pedido antiguo
                if (row.getAttribute('data-antiguo') === '1') {
                    const badgeAtrasado = row.querySelector('.badge-atrasado');
                    if (estado === 'pendiente' || estado === 'preparado') {
                        row.classList.add('row-atrasado');
                        row.setAttribute('data-atrasado', '1');
                        if (badgeAtrasado) badgeAtrasado.style.display = '';
                    } else {
                        row.classList.remove('row-atrasado');
                        row.removeAttribute('data-atrasado');
                        if (badgeAtrasado) badgeAtrasado.style.display = 'none';
                    }
                }
            });

            // Actualizar contadores superiores dinámicamente
            const totEl = document.getElementById('contador-totales');
            if (totEl) totEl.textContent = parseInt(totEl.textContent || 0) + 1;

            let contId = '';
            if (estado === 'pendiente') contId = 'contador-pendientes';
            else if (estado === 'en_ruta') contId = 'contador-en-ruta';
            else if (estado === 'entregado') contId = 'contador-entregados';

            if (contId) {
                const contEl = document.getElementById(contId);
                if (contEl) contEl.textContent = parseInt(contEl.textContent || 0) + 1;
            }

            // Actualizar el contador de pedidos mostrados
            const txt = document.getElementById('paginacionTexto');
            if (txt) {
                const current = parseInt(txt.textContent.match(/\d+/)?.[0] ?? 0);
                txt.textContent = 'MOSTRANDO ' + (current + 1) + ' PEDIDOS';
            }

            actualizarVista();
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Asignar Pedido';
            msg.textContent = '⚠ Error de comunicación con el servidor.';
            msg.style.display = 'block';
            msg.style.background = '#fff4f2';
            msg.style.color = '#b02500';
        });
    });
});

let adminMap = null;
let adminMarker = null;
let adminGpsInterval = null;

function detenerRastreoAdmin() {
    if (adminGpsInterval) {
        clearInterval(adminGpsInterval);
        adminGpsInterval = null;
    }
    if (adminMap) {
        adminMap.remove();
        adminMap = null;
        adminMarker = null;
    }
    const mapCont = document.getElementById('admin-map-container');
    if (mapCont) mapCont.style.display = 'none';
}

function iniciarRastreoAdmin(pedidoId) {
    detenerRastreoAdmin();

    const container = document.getElementById('admin-tracking-map');
    if (!container) return;

    document.getElementById('admin-map-container').style.display = 'block';

    const defaultLat = 20.6736;
    const defaultLng = -103.3440;

    adminMap = L.map('admin-tracking-map').setView([defaultLat, defaultLng], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap - EcoAli Logística'
    }).addTo(adminMap);

    const truckIcon = L.divIcon({
        className: 'custom-driver-icon',
        html: `<div style="background: #176a21; color: white; font-size: 16px; width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; border: 3px solid white; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">🚚</div>`,
        iconSize: [38, 38],
        iconAnchor: [19, 19]
    });

    function actualizarPosicion() {
        fetch('forms/obtener_ubicacion_pedido.php?pedido_id=' + pedidoId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && data.lat !== null && data.lng !== null) {
                    const newPos = [data.lat, data.lng];
                    if (!adminMarker) {
                        adminMarker = L.marker(newPos, { icon: truckIcon }).addTo(adminMap);
                    } else {
                        adminMarker.setLatLng(newPos);
                    }
                    adminMap.invalidateSize();
                    adminMap.setView(newPos, 15);
                    const statusEl = document.getElementById('admin-gps-status-time');
                    if (statusEl) {
                        statusEl.innerHTML = `🟢 Señal: <strong>${new Date().toLocaleTimeString()}</strong>`;
                    }
                } else {
                    const statusEl = document.getElementById('admin-gps-status-time');
                    if (statusEl) {
                        statusEl.innerHTML = `⚠️ Esperando señal GPS...`;
                    }
                }
            })
            .catch(e => console.log('Error de rastreo admin:', e));
    }

    actualizarPosicion();
    adminGpsInterval = setInterval(actualizarPosicion, 5000);
    setTimeout(() => { 
        if (adminMap) adminMap.invalidateSize(); 
    }, 300);
}

document.addEventListener('DOMContentLoaded', function() {
    const selectEstado = document.getElementById('edit_estado');
    if (selectEstado) {
        selectEstado.addEventListener('change', function() {
            const estado = this.value;
            const pedidoId = document.getElementById('edit_id').value;
            if (estado === 'en_ruta') {
                iniciarRastreoAdmin(pedidoId);
            } else {
                detenerRastreoAdmin();
            }
        });
    }
});

function abrirModalEditar(id) {
    fetch('forms/logistica_acciones.php?accion=obtener&id=' + id)
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('edit_id').value = res.data.id;
                document.getElementById('edit_cliente_id').value = res.data.cliente_id;
                document.getElementById('edit_repartidor_id').value = res.data.repartidor_id || '';
                document.getElementById('edit_total').value = res.data.total;
                document.getElementById('edit_estado').value = res.data.estado;
                
                document.getElementById('modalEditar').classList.add('active');

                if (res.data.estado === 'en_ruta') {
                    setTimeout(() => {
                        iniciarRastreoAdmin(id);
                    }, 200);
                } else {
                    detenerRastreoAdmin();
                }
            } else {
                alert('Error al obtener datos del pedido: ' + res.message);
            }
        })
        .catch(err => {
            alert('Error en la comunicación con el servidor.');
        });
}

function confirmarEliminar(id) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_pedido_text').textContent = "#PED-" + String(id).padStart(3, '0');
    document.getElementById('modalEliminar').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
    if (id === 'modalEditar') {
        detenerRastreoAdmin();
    }
}

/**
 * Asigna un repartidor activo al pedido de forma inmediata vía AJAX.
 * Muestra feedback visual en el propio select (azul → guardando, verde → OK, rojo → error).
 */
function asignarRepartidor(pedidoId, selectEl) {
    const repartidorId = selectEl.value;

    // Feedback visual: guardando
    selectEl.classList.remove('saved', 'error-save');
    selectEl.classList.add('saving');
    selectEl.disabled = true;

    const body = new FormData();
    body.append('accion', 'asignar_repartidor');
    body.append('id', pedidoId);
    body.append('repartidor_id', repartidorId);

    fetch('forms/logistica_acciones.php', { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            selectEl.classList.remove('saving');
            selectEl.disabled = false;
            if (res.status === 'success') {
                selectEl.classList.add('saved');
                setTimeout(() => selectEl.classList.remove('saved'), 2000);
            } else {
                selectEl.classList.add('error-save');
                setTimeout(() => selectEl.classList.remove('error-save'), 3000);
                alert('Error al asignar repartidor: ' + res.message);
            }
        })
        .catch(() => {
            selectEl.classList.remove('saving');
            selectEl.disabled = false;
            selectEl.classList.add('error-save');
            setTimeout(() => selectEl.classList.remove('error-save'), 3000);
            alert('Error de comunicación con el servidor.');
        });
}

// Búsqueda y filtros interactivos con paginación
let paginaActual = 1;
const registrosPorPagina = 5;
let filtroQuery = "";
let filtroEstado = "todos";

function actualizarVista() {
    const rows = document.querySelectorAll('#tablaCuerpo .row-pedido');
    const matchingRows = [];
    
    rows.forEach(row => {
        const text    = row.textContent.toLowerCase();
        const rEstado = row.getAttribute('data-estado');
        const esAtrasado = row.getAttribute('data-atrasado') === '1';
        
        const matchesQuery  = text.includes(filtroQuery);
        let   matchesEstado = false;

        if (filtroEstado === 'todos') {
            matchesEstado = true;
        } else if (filtroEstado === 'atrasado') {
            // Solo mostrar filas marcadas como atrasadas
            matchesEstado = esAtrasado;
        } else {
            matchesEstado = (rEstado === filtroEstado || (filtroEstado === 'en_ruta' && rEstado === 'en proceso'));
        }
        
        if (matchesQuery && matchesEstado) {
            matchingRows.push(row);
        } else {
            row.style.display = 'none';
        }
    });

    const totalRegistros = matchingRows.length;
    const totalPaginas = Math.ceil(totalRegistros / registrosPorPagina) || 1;

    // Asegurar rango válido de página
    if (paginaActual > totalPaginas) {
        paginaActual = totalPaginas;
    }
    if (paginaActual < 1) {
        paginaActual = 1;
    }

    const inicio = (paginaActual - 1) * registrosPorPagina;
    const fin = inicio + registrosPorPagina;

    matchingRows.forEach((row, index) => {
        if (index >= inicio && index < fin) {
            row.style.display = 'grid';
        } else {
            row.style.display = 'none';
        }
    });

    const mostradosInicio = totalRegistros > 0 ? inicio + 1 : 0;
    const mostradosFin = Math.min(fin, totalRegistros);
    document.getElementById('paginacionTexto').textContent = 
        `MOSTRANDO ${mostradosInicio}-${mostradosFin} DE ${totalRegistros} PEDIDOS`;

    const contenedorPaginacion = document.querySelector('.pagination-like .container-8');
    if (contenedorPaginacion) {
        contenedorPaginacion.innerHTML = "";
        
        for (let p = 1; p <= totalPaginas; p++) {
            const btnClass = (p === paginaActual) ? 'button-3' : 'button-4';
            const textClass = (p === paginaActual) ? 'text-23' : 'text-24';
            
            const btn = document.createElement('button');
            btn.className = btnClass;
            btn.style.cursor = 'pointer';
            btn.onclick = () => cambiarPagina(p);
            
            const divText = document.createElement('div');
            divText.className = textClass;
            divText.textContent = p;
            
            btn.appendChild(divText);
            contenedorPaginacion.appendChild(btn);
        }
    }
}

function cambiarPagina(pagina) {
    paginaActual = pagina;
    actualizarVista();
}

function filtrarTabla() {
    filtroQuery = document.getElementById('buscarPedido').value.toLowerCase();
    paginaActual = 1;
    actualizarVista();
}

function filtrarEstado(estado, btn) {
    const buttons = document.querySelectorAll('.background-shadow button');
    buttons.forEach(b => {
        // Preservar la clase btn-atrasados al resetear
        const esAtrasadosBtn = b.classList.contains('btn-atrasados');
        b.className = esAtrasadosBtn ? 'div-wrapper btn-atrasados' : 'div-wrapper';
        if (b.querySelector('div')) {
            b.querySelector('div').className = 'text-2';
        }
    });

    const esAtrasadoActivo = btn.classList.contains('btn-atrasados');
    btn.className = esAtrasadoActivo ? 'button btn-atrasados' : 'button';
    if (btn.querySelector('div')) {
        btn.querySelector('div').className = 'text';
    }

    // Mostrar/ocultar panel exclusivo de atrasados
    const panelAtrasados = document.getElementById('panel-asignar-atrasados');
    if (panelAtrasados) {
        panelAtrasados.style.display = estado === 'atrasado' ? 'flex' : 'none';
        // Resetear feedback al cambiar de filtro
        const fb = document.getElementById('panel-atrasados-feedback');
        if (fb) fb.style.display = 'none';
        // Resetear select
        const repSel = document.getElementById('rep-masivo-atrasados');
        if (repSel) repSel.value = '';
    }

    filtroEstado = estado;
    paginaActual = 1;
    actualizarVista();
}

/** Asigna el repartidor seleccionado a todos los pedidos atrasados visibles,
 *  cambia su estado a En Ruta y elimina la advertencia de atrasado */
function asignarRepartidorAtrasados() {
    const selectEl = document.getElementById('rep-masivo-atrasados');
    const repId    = selectEl ? selectEl.value : '';
    const feedback = document.getElementById('panel-atrasados-feedback');

    if (!repId) {
        feedback.textContent = '⚠ Selecciona un repartidor primero.';
        feedback.style.cssText = 'display:block; background:#fff4f2; color:#b02500; border-color:rgba(176,37,0,0.2);';
        return;
    }

    const atrasadoRows = Array.from(document.querySelectorAll('#tablaCuerpo .row-pedido[data-atrasado="1"]'));
    if (atrasadoRows.length === 0) {
        feedback.textContent = 'No hay pedidos atrasados visibles para asignar.';
        feedback.style.cssText = 'display:block; background:#fff7ef; color:#7a4500; border-color:rgba(213,164,112,0.2);';
        return;
    }

    const repNombre = selectEl.options[selectEl.selectedIndex].text;
    feedback.textContent = `Asignando ${repNombre} a ${atrasadoRows.length} pedido(s)...`;
    feedback.style.cssText = 'display:block; background:#f0f8ff; color:#1786ba; border-color:rgba(23,134,186,0.2);';

    const promises = [];
    atrasadoRows.forEach(row => {
        const repSelect = row.querySelector('.rep-select');
        if (!repSelect) return;
        const pedidoId = repSelect.id.replace('rep-select-', '');

        repSelect.classList.remove('saved', 'error-save');
        repSelect.classList.add('saving');
        repSelect.disabled = true;

        const body = new FormData();
        body.append('accion', 'asignar_repartidor');
        body.append('id', pedidoId);
        body.append('repartidor_id', repId);
        body.append('estado', 'en_ruta'); // Cambiar automáticamente a En Ruta

        promises.push(
            fetch('forms/logistica_acciones.php', { method: 'POST', body })
                .then(r => r.json())
                .then(res => {
                    repSelect.classList.remove('saving');
                    repSelect.disabled = false;

                    if (res.status === 'success') {
                        repSelect.value = repId;
                        repSelect.classList.add('saved');
                        setTimeout(() => repSelect.classList.remove('saved'), 2500);

                        // ── Actualizar DOM de la fila inmediatamente ───────────────
                        // 1. Quitar estilos y atributos de atrasado
                        row.classList.remove('row-atrasado');
                        row.removeAttribute('data-atrasado');
                        row.removeAttribute('data-antiguo');

                        // 2. Ocultar badge ⚠ ATRASADO
                        const badge = row.querySelector('.badge-atrasado');
                        if (badge) badge.style.display = 'none';

                        // 3. Actualizar data-estado a en_ruta
                        row.setAttribute('data-estado', 'en_ruta');

                        // 4. Reemplazar badge de estado visual por "En Ruta"
                        const statusContainer = row.children[4];
                        if (statusContainer) {
                            statusContainer.innerHTML = `
                                <div class="overlay-7">
                                  <div class="background-5"></div>
                                  <div class="text-15" style="color:inherit;">En Ruta</div>
                                </div>`;
                        }

                        // 5. Ajustar contadores superiores: pendiente -1, en_ruta +1
                        const elPend = document.getElementById('contador-pendientes');
                        const elRuta = document.getElementById('contador-en-ruta');
                        if (elPend) elPend.textContent = Math.max(0, parseInt(elPend.textContent || 0) - 1);
                        if (elRuta) elRuta.textContent = parseInt(elRuta.textContent || 0) + 1;

                    } else {
                        repSelect.classList.add('error-save');
                        setTimeout(() => repSelect.classList.remove('error-save'), 3000);
                    }
                })
                .catch(() => {
                    repSelect.classList.remove('saving');
                    repSelect.disabled = false;
                    repSelect.classList.add('error-save');
                    setTimeout(() => repSelect.classList.remove('error-save'), 3000);
                })
        );
    });

    Promise.all(promises)
        .then(() => {
            feedback.textContent = `✓ ${repNombre} asignado · ${atrasadoRows.length} pedido(s) cambiados a "En Ruta" y advertencias eliminadas.`;
            feedback.style.cssText = 'display:block; background:#f0fff3; color:#176a21; border-color:rgba(23,106,33,0.2);';
            // Re-aplicar vista: las filas ya no tienen data-atrasado, desaparecen del filtro "Atrasados"
            actualizarVista();
        })
        .catch(() => {
            feedback.textContent = '⚠ Ocurrió un error al asignar algunos pedidos.';
            feedback.style.cssText = 'display:block; background:#fff4f2; color:#b02500; border-color:rgba(176,37,0,0.2);';
        });
}

// Inicializar la vista con paginación al cargar el documento
document.addEventListener("DOMContentLoaded", () => {
    actualizarVista();
});
</script>
</body>
</html>