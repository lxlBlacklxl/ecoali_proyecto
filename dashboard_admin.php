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

// --- CONSULTAS DINÁMICAS EN TIEMPO REAL ---

// 1. Clientes Activos (rol_id = 2)
$cliActRes = $conn->query("SELECT COUNT(*) FROM usuarios WHERE rol_id = 2 AND activo = 1");
$clientesActivos = $cliActRes ? $cliActRes->fetch_row()[0] : 0;

// 2. Usuarios Activos (todos los roles)
$usrActRes = $conn->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1");
$usuariosActivos = $usrActRes ? $usrActRes->fetch_row()[0] : 0;

// 3. Proveedores Activos (estado = 'activo')
$provActRes = $conn->query("SELECT COUNT(*) FROM proveedores WHERE estado = 'activo'");
$proveedoresActivos = $provActRes ? $provActRes->fetch_row()[0] : 0;

// 4. Ventas Totales (suma de pedidos)
$ventasTotRes = $conn->query("SELECT SUM(total) FROM pedidos WHERE estado != 'cancelado'");
$ventasTotRow = $ventasTotRes ? $ventasTotRes->fetch_row() : null;
$ventasTotales = $ventasTotRow && !is_null($ventasTotRow[0]) ? (float)$ventasTotRow[0] : 0.0;

// 5. Stock Total de Huevos (disponibles en inventario)
$stockTotRes = $conn->query("SELECT SUM(cantidad) FROM inventario_huevos WHERE estado IN ('disponible', 'bajo_stock')");
$stockTotRow = $stockTotRes ? $stockTotRes->fetch_row() : null;
$stockTotal = $stockTotRow && !is_null($stockTotRow[0]) ? (int)$stockTotRow[0] : 0;

// 6. Pedidos Pendientes
$pedPendRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente'");
$pedidosPendientes = $pedPendRes ? $pedPendRes->fetch_row()[0] : 0;

// 7. Pedidos En Ruta
$pedRutaRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'en_ruta'");
$pedidosEnRuta = $pedRutaRes ? $pedRutaRes->fetch_row()[0] : 0;

// 8. Producción Semanal (últimos 7 días)
$prodSemRes = $conn->query("SELECT SUM(cantidad) FROM produccion WHERE fecha_produccion >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$prodSemRow = $prodSemRes ? $prodSemRes->fetch_row() : null;
$produccionSemanal = $prodSemRow && !is_null($prodSemRow[0]) ? (int)$prodSemRow[0] : 0;

// Si está en 0 por falta de datos, podemos calcular a partir del inventario creado en los últimos 7 días como fallback
if ($produccionSemanal === 0) {
    $prodSemRes = $conn->query("SELECT SUM(cantidad) FROM inventario_huevos WHERE creado_en >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $prodSemRow = $prodSemRes ? $prodSemRes->fetch_row() : null;
    $produccionSemanal = $prodSemRow && !is_null($prodSemRow[0]) ? (int)$prodSemRow[0] : 0;
}

// 9. Producción Mensual (últimos 30 días)
$prodMenRes = $conn->query("SELECT SUM(cantidad) FROM produccion WHERE fecha_produccion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$prodMenRow = $prodMenRes ? $prodMenRes->fetch_row() : null;
$produccionMensual = $prodMenRow && !is_null($prodMenRow[0]) ? (int)$prodMenRow[0] : 0;

if ($produccionMensual === 0) {
    $prodMenRes = $conn->query("SELECT SUM(cantidad) FROM inventario_huevos WHERE creado_en >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $prodMenRow = $prodMenRes ? $prodMenRes->fetch_row() : null;
    $produccionMensual = $prodMenRow && !is_null($prodMenRow[0]) ? (int)$prodMenRow[0] : 0;
}

// 10. Stock Bajo (lotes disponibles con menos de 100 huevos)
$stockBajoRes = $conn->query("SELECT COUNT(*) FROM inventario_huevos WHERE estado IN ('disponible', 'bajo_stock') AND cantidad < 100");
$stockBajoLotes = $stockBajoRes ? $stockBajoRes->fetch_row()[0] : 0;

// 11. Lotes Próximos a Caducar (en los próximos 7 días)
$proxCadRes = $conn->query("SELECT COUNT(*) FROM inventario_huevos WHERE estado IN ('disponible', 'bajo_stock') AND fecha_caducidad <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND fecha_caducidad >= CURDATE()");
$lotesProximosCaducar = $proxCadRes ? $proxCadRes->fetch_row()[0] : 0;

// --- DETECCIÓN DE PROBLEMAS RÁPIDOS (ALERTAS) ---

$alertas = [];

// A. Stock Bajo
if ($stockBajoLotes > 0) {
    $alertas[] = [
        "tipo" => "warning",
        "mensaje" => "Hay <strong>$stockBajoLotes lote(s)</strong> con bajo stock de huevos (menos de 100 unidades). Se recomienda reabastecer.",
        "url" => "inventario_admin.php"
    ];
}

// B. Lotes Caducados Activos (caducados hoy o antes que sigan listados como disponible/bajo_stock)
$caducadosRes = $conn->query("SELECT COUNT(*) FROM inventario_huevos WHERE estado IN ('disponible', 'bajo_stock') AND fecha_caducidad < CURDATE()");
$lotesCaducados = $caducadosRes ? $caducadosRes->fetch_row()[0] : 0;
if ($lotesCaducados > 0) {
    $alertas[] = [
        "tipo" => "danger",
        "mensaje" => "¡Atención! Se detectaron <strong>$lotesCaducados lote(s) caducados</strong> en almacén que requieren ser bloqueados.",
        "url" => "inventario_admin.php"
    ];
}

// C. Entregas Retrasadas (pedidos pendientes/preparados creados hace más de 24 horas)
$retrasadosRes = $conn->query(
    "SELECT estado, COUNT(*) AS total
     FROM pedidos
     WHERE estado IN ('pendiente','preparado')
       AND fecha_pedido < NOW() - INTERVAL 24 HOUR
     GROUP BY estado"
);
$retrasadosPorEstado = [];
$totalRetrasados = 0;
if ($retrasadosRes) {
    while ($rr = $retrasadosRes->fetch_assoc()) {
        $retrasadosPorEstado[$rr['estado']] = (int)$rr['total'];
        $totalRetrasados += (int)$rr['total'];
    }
}
if ($totalRetrasados > 0) {
    $desglose = [];
    if (!empty($retrasadosPorEstado['pendiente'])) {
        $n = $retrasadosPorEstado['pendiente'];
        $desglose[] = "<strong>$n pendiente" . ($n !== 1 ? 's' : '') . "</strong>";
    }
    if (!empty($retrasadosPorEstado['preparado'])) {
        $n = $retrasadosPorEstado['preparado'];
        $desglose[] = "<strong>$n preparado" . ($n !== 1 ? 's' : '') . "</strong>";
    }
    $desgloseTexto = implode(' y ', $desglose);
    $alertas[] = [
        "tipo"    => "danger",
        "mensaje" => "⚠ Tienes <strong>$totalRetrasados pedido" . ($totalRetrasados !== 1 ? 's' : '') . " atrasado" . ($totalRetrasados !== 1 ? 's' : '') . "</strong> con más de 24 h sin despachar: $desgloseTexto.",
        "url"     => "logistica_admin.php"
    ];
}

// D. Pedidos Pendientes Críticos
if ($pedidosPendientes > 0 && count($alertas) < 3) {
    $alertas[] = [
        "tipo" => "info",
        "mensaje" => "Hay <strong>$pedidosPendientes pedido(s) pendiente(s)</strong> esperando asignación de repartidor.",
        "url" => "logistica_admin.php"
    ];
}

// --- ÚLTIMOS PEDIDOS ---
$sqlUltPedidos = "SELECT p.id, CONCAT(up.nombre, ' ', up.apellido) AS nombre_cliente, p.fecha_pedido, p.total, p.estado 
                  FROM pedidos p
                  LEFT JOIN usuario_perfil up ON p.cliente_id = up.usuario_id
                  ORDER BY p.id DESC LIMIT 5";
$resultUltPedidos = $conn->query($sqlUltPedidos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panel de Control - ECOALI</title>

<link rel="stylesheet" href="assets/css/globals.css">
<link rel="stylesheet" href="assets/css/inventario_admin.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<script src="assets/js/admin_menu.js" defer></script>
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
          <div class="div-2"><div class="text-29">Administrador</div></div>
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
          'bitacora_admin.php' => 'Bitácora'
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
    <div class="div-4"><div class="text-26">¡Bienvenido de nuevo, <?php echo htmlspecialchars($nombre); ?>!</div></div>

    <div style="display:flex; gap:12px;">
      <a href="reportes_admin.php" class="button-2" style="text-decoration:none; display:flex; align-items:center; justify-content:center;"><div class="text-3">Exportar Reportes</div></a>
      <a href="bitacora_admin.php" class="button-2" style="text-decoration:none; background:linear-gradient(90deg, #ff8a00, #ffb300); display:flex; align-items:center; justify-content:center;"><div class="text-3">Ver Bitácora</div></a>
    </div>
  </div>

  <div class="main-content">

    <!-- Notificaciones y Alertas Críticas del Sistema -->
    <?php if (count($alertas) > 0): ?>
        <div class="alert-container" style="margin-bottom: 24px;">
            <?php foreach ($alertas as $alt): 
                $alertClass = "alert-success";
                $icon = "✓";
                if ($alt["tipo"] === "warning") {
                    $alertClass = "alert-warning-custom";
                    $icon = "⚠";
                } elseif ($alt["tipo"] === "danger") {
                    $alertClass = "alert-danger";
                    $icon = "✗";
                } elseif ($alt["tipo"] === "info") {
                    $alertClass = "alert-info-custom";
                    $icon = "ℹ";
                }
                $hasUrl = isset($alt["url"]);
                $tag = $hasUrl ? "a" : "div";
                $attr = $hasUrl ? 'href="' . htmlspecialchars($alt["url"]) . '" class="alert ' . $alertClass . ' alert-clickable" style="margin-bottom: 8px;"' : 'class="alert ' . $alertClass . '" style="margin-bottom: 8px;"';
            ?>
                <<?php echo $tag; ?> <?php echo $attr; ?>>
                    <span><?php echo $icon; ?></span> 
                    <div style="font-size: 13px; font-weight: 600; font-family: inherit; color: inherit;"><?php echo $alt["mensaje"]; ?></div>
                </<?php echo $tag; ?>>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Primer bloque de métricas: Resumen General -->
    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px;">
      <div class="background-border" style="padding: 24px; min-height: auto;">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:11px;">CLIENTES ACTIVOS</div></div>
        <div class="div-2" style="margin-top: 10px;"><div class="text-wrapper-3"><?php echo $clientesActivos; ?></div></div>
      </div>

      <div class="background-border-2" style="padding: 24px; min-height: auto;">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:11px;">USUARIOS TOTALES ACTIVADOS</div></div>
        <div class="div-2" style="margin-top: 10px;"><div class="text-wrapper-3"><?php echo $usuariosActivos; ?></div></div>
      </div>

      <div class="background-border-3" style="padding: 24px; min-height: auto;">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:11px;">PROVEEDORES ACTIVOS</div></div>
        <div class="div-2" style="margin-top: 10px;"><div class="text-wrapper-3"><?php echo $proveedoresActivos; ?></div></div>
      </div>

      <div class="background-border" style="padding: 24px; min-height: auto; border-color: rgba(255, 138, 0, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:11px; color:#ff8a00;">VENTAS TOTALES REGISTRADAS</div></div>
        <div class="div-2" style="margin-top: 10px;"><div class="text-wrapper-3" style="color:#ff8a00;">$<?php echo number_format($ventasTotales, 2); ?></div></div>
      </div>
    </div>

    <!-- Segundo bloque de métricas: Inventario, Producción y Logística -->
    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px;">
      <div class="background-border-3" style="padding: 20px; min-height: auto; border-color: rgba(23, 106, 33, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:10px;">STOCK TOTAL HUEVOS</div></div>
        <div class="div-2" style="margin-top: 8px;"><div class="text-wrapper-3" style="font-size:26px; color:#176a21;"><?php echo number_format($stockTotal); ?> ud</div></div>
      </div>

      <div class="background-border" style="padding: 20px; min-height: auto;">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:10px;">PRODUCCIÓN SEMANAL</div></div>
        <div class="div-2" style="margin-top: 8px;"><div class="text-wrapper-3" style="font-size:26px;"><?php echo number_format($produccionSemanal); ?> ud</div></div>
      </div>

      <div class="background-border-2" style="padding: 20px; min-height: auto;">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:10px;">PRODUCCIÓN MENSUAL</div></div>
        <div class="div-2" style="margin-top: 8px;"><div class="text-wrapper-3" style="font-size:26px;"><?php echo number_format($produccionMensual); ?> ud</div></div>
      </div>

      <div class="background-border" style="padding: 20px; min-height: auto; border-color: rgba(176, 37, 0, 0.2);">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:10px; color:#b02500;">PROBLEMAS DE STOCK</div></div>
        <div class="div-2" style="margin-top: 8px;">
          <div class="text-wrapper-3" style="font-size:13px; color:#b02500; font-weight:800; display:flex; flex-direction:column; gap:4px;">
            <span>Stock bajo: <?php echo $stockBajoLotes; ?> lotes</span>
            <span>Prox. caducar: <?php echo $lotesProximosCaducar; ?> lotes</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Tercer bloque de métricas: Pedidos y Distribución -->
    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
      <div class="background-border-2" style="padding: 20px; min-height: auto;">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:10px;">PEDIDOS PENDIENTES</div></div>
        <div class="div-2" style="margin-top: 8px;"><div class="text-wrapper-3" style="font-size:26px;"><?php echo $pedidosPendientes; ?></div></div>
      </div>

      <div class="background-border" style="padding: 20px; min-height: auto;">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:10px;">PEDIDOS EN RUTA</div></div>
        <div class="div-2" style="margin-top: 8px;"><div class="text-wrapper-3" style="font-size:26px;"><?php echo $pedidosEnRuta; ?></div></div>
      </div>

      <div class="background-border-3" style="padding: 20px; min-height: auto; border-color: rgba(176, 37, 0, 0.2);">
        <div class="container-5"><div class="text-wrapper-2" style="font-size:10px; color:#b02500;">ENTREGAS RETRASADAS</div></div>
        <div class="div-2" style="margin-top: 8px;"><div class="text-wrapper-3" style="font-size:26px; color:#b02500;"><?php echo $entregasRetrasadas; ?></div></div>
      </div>
    </div>

    <!-- Sección de Últimos Pedidos -->
    <div class="inventory-table">
      <div class="header">
        <div class="row" style="grid-template-columns: 1.5fr 2.5fr 2fr 2fr 1.5fr 1fr;">
          <div><div class="text-7">ÚLTIMOS PEDIDOS</div></div>
          <div><div class="text-7">CLIENTE</div></div>
          <div><div class="text-7">FECHA DE REGISTRO</div></div>
          <div><div class="text-8">TOTAL DEL PEDIDO</div></div>
          <div><div class="text-7">ESTADO</div></div>
          <div><div class="text-9">ACCIONES</div></div>
        </div>
      </div>

      <div id="tablaCuerpo">
      <?php if ($resultUltPedidos && $resultUltPedidos->num_rows > 0): ?>
          <?php while ($row = $resultUltPedidos->fetch_assoc()): 
              $estado = strtolower($row["estado"]);
              $estadoClass = "overlay-10";
              $bgClass = "background-9";
              $estadoText = "Pendiente";

              if ($estado === "en_ruta") {
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
          <div class="div-3" style="grid-template-columns: 1.5fr 2.5fr 2fr 2fr 1.5fr 1fr; border-bottom: 1px solid rgba(213,164,112,.12);">
            <div><div class="text-10">#PED-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?></div></div>
            <div><div class="text-11"><?php echo htmlspecialchars($row["nombre_cliente"] ?? "Cliente Invitado"); ?></div></div>
            <div><div class="text-14"><?php echo date("d M Y, h:i A", strtotime($row["fecha_pedido"])); ?></div></div>
            <div>
              <div class="background-2">
                <div class="text-13">$<?php echo number_format($row["total"], 2); ?></div>
              </div>
            </div>
            <div>
              <div class="<?php echo $estadoClass; ?>">
                <div class="<?php echo $bgClass; ?>"></div>
                <div class="text-15" style="color: inherit;"><?php echo $estadoText; ?></div>
              </div>
            </div>
            <div>
              <a href="logistica_admin.php?buscar=#PED-<?php echo str_pad($row["id"], 3, "0", STR_PAD_LEFT); ?>" class="text-10" style="color: #ff8a00; font-weight: 800; text-decoration: none;">Ver Pedido</a>
            </div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 24px;">
              <div class="text-11" style="color:#996e3f;">No hay pedidos registrados en el sistema.</div>
          </div>
      <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- Estilos extra específicos para alertas personalizadas en el Dashboard -->
<style>
.alert-warning-custom {
    background: rgba(255, 138, 0, 0.12);
    color: #8d4a00;
    border: 1px solid rgba(255, 138, 0, 0.35);
}
.alert-info-custom {
    background: rgba(23, 134, 186, 0.12);
    color: #115c80;
    border: 1px solid rgba(23, 134, 186, 0.35);
}
.alert-clickable {
    text-decoration: none;
    cursor: pointer;
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s cubic-bezier(0.16, 1, 0.3, 1), filter 0.25s ease;
}
.alert-clickable:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
    filter: brightness(0.97);
}
.alert-clickable:active {
    transform: translateY(0);
}
</style>
</body>
</html>