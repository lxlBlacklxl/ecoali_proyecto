<?php
session_start();
require "forms/conexion.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

if ((int)$_SESSION["rol_id"] !== 1) {
    header("Location: login.php");
    exit;
}

$nombre = $_SESSION["nombre"] ?? "Admin";

// Filtros por defecto
$fecha_inicio = $_GET["fecha_inicio"] ?? date('Y-m-01'); // Primer día del mes
$fecha_fin = $_GET["fecha_fin"] ?? date('Y-m-d');
$tipo_reporte = $_GET["tipo_reporte"] ?? "ventas";

// Consultas dinámicas filtradas y globales
// 1. Ingresos Totales (Suma de ventas de pedidos entregados)
$ingresosRes = $conn->query("SELECT SUM(total) FROM pedidos WHERE estado = 'entregado' AND DATE(fecha_pedido) BETWEEN '$fecha_inicio' AND '$fecha_fin'");
$ingresosTotales = $ingresosRes && !is_null($row = $ingresosRes->fetch_row()) ? (float)$row[0] : 0.0;

// 2. Huevos Producidos (Suma de lotes del inventario)
$produccionRes = $conn->query("SELECT SUM(cantidad) FROM inventario_huevos WHERE DATE(fecha_postura) BETWEEN '$fecha_inicio' AND '$fecha_fin'");
$huevosProducidos = $produccionRes && !is_null($row = $produccionRes->fetch_row()) ? (int)$row[0] : 0;

// 3. Pedidos Completados (Pedidos entregados)
$completadosRes = $conn->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'entregado' AND DATE(fecha_pedido) BETWEEN '$fecha_inicio' AND '$fecha_fin'");
$pedidosCompletados = $completadosRes ? (int)$completadosRes->fetch_row()[0] : 0;

// Datos ficticios para rellenar los gráficos premium
$ventas_mensuales = [
    "Enero" => 4200, "Febrero" => 5100, "Marzo" => 6800, "Abril" => 7400, "Mayo" => 9200, "Junio" => 12500
];

$produccion_mensual = [
    "Orgánico" => 55, "Blanco" => 25, "Rubio" => 12, "Ecológico" => 8
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reportes y Análisis - ECOALI</title>

<link rel="stylesheet" href="assets/css/globals.css">
<link rel="stylesheet" href="assets/css/inventario_admin.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<script src="assets/js/admin_menu.js" defer></script>
<style>
  .bar-chart {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      height: 180px;
      padding-top: 20px;
      gap: 12px;
  }
  .bar-item {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
  }
  .bar-visual {
      width: 100%;
      background: linear-gradient(180deg, #ff8a00, #462800);
      border-radius: 8px 8px 0 0;
      transition: height 0.5s ease-in-out;
      position: relative;
  }
  .bar-visual:hover::after {
      content: attr(data-value);
      position: absolute;
      top: -25px;
      left: 50%;
      transform: translateX(-50%);
      background: #462800;
      color: #fff;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 10px;
      font-weight: 700;
      white-space: nowrap;
  }
  .donut-chart {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-top: 15px;
  }
  .donut-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
  }
  .donut-label {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      color: #7a5427;
      font-weight: 600;
  }
  .donut-color {
      width: 12px;
      height: 12px;
      border-radius: 4px;
  }
  .donut-bar-outer {
      flex-grow: 1;
      height: 8px;
      background: rgba(213, 164, 112, 0.15);
      border-radius: 4px;
      margin: 0 12px;
      overflow: hidden;
  }
  .donut-bar-inner {
      height: 100%;
      border-radius: 4px;
  }
  .donut-value {
      font-size: 13px;
      font-weight: 700;
      color: #462800;
      width: 40px;
      text-align: right;
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

    <div class="nav" style="gap: 6px;">
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
          $active = ($current_page === $href);
          if ($active) {
              echo '
              <a class="link-active-state" href="' . $href . '">
                <div class="link-active-state-2"></div>
                <div class="div-4"><div class="text-31">' . $label . '</div></div>
              </a>';
          } else {
              echo '
              <a class="link" href="' . $href . '">
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
    <div class="div-4"><div class="text-26">Reportes y Análisis</div></div>

    <div style="display: flex; gap: 10px;">
        <button class="button-2" onclick="simularExportacion('excel')">
          <div class="text-3">Exportar Excel</div>
        </button>
        <button class="button-2" onclick="simularExportacion('pdf')" style="background: #b02500;">
          <div class="text-3">Exportar PDF</div>
        </button>
    </div>
  </div>

  <div class="main-content">

    <!-- Buscador y Filtros Interactivos -->
    <div class="section-search" style="padding: 15px 20px; background: rgba(255,255,255,0.45); border-radius: 16px; margin-bottom: 24px; border: 1px solid rgba(213, 164, 112, 0.15);">
      <form method="GET" style="display: flex; width: 100%; gap: 15px; align-items: center; flex-wrap: wrap;">
        
        <div style="display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 140px;">
          <label style="font-size: 11px; font-weight: 700; color: #7a5427; text-transform: uppercase;">Tipo de Reporte</label>
          <select name="tipo_reporte" style="padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(213, 164, 112, 0.3); outline: none; font-family: inherit; font-size: 13px; color: #462800; font-weight: 600; background: #fff;">
            <option value="ventas" <?php echo $tipo_reporte === "ventas" ? "selected" : ""; ?>>Reporte de Ventas</option>
            <option value="produccion" <?php echo $tipo_reporte === "produccion" ? "selected" : ""; ?>>Reporte de Producción</option>
            <option value="inventario" <?php echo $tipo_reporte === "inventario" ? "selected" : ""; ?>>Reporte de Inventario</option>
            <option value="clientes" <?php echo $tipo_reporte === "clientes" ? "selected" : ""; ?>>Actividad de Clientes</option>
          </select>
        </div>

        <div style="display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 140px;">
          <label style="font-size: 11px; font-weight: 700; color: #7a5427; text-transform: uppercase;">Fecha Inicio</label>
          <input type="date" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>" style="padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(213, 164, 112, 0.3); outline: none; font-family: inherit; font-size: 13px; color: #462800; font-weight: 600; background: #fff;">
        </div>

        <div style="display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 140px;">
          <label style="font-size: 11px; font-weight: 700; color: #7a5427; text-transform: uppercase;">Fecha Fin</label>
          <input type="date" name="fecha_fin" value="<?php echo $fecha_fin; ?>" style="padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(213, 164, 112, 0.3); outline: none; font-family: inherit; font-size: 13px; color: #462800; font-weight: 600; background: #fff;">
        </div>

        <div style="display: flex; align-items: flex-end; margin-top: 15px;">
          <button type="submit" class="button-2" style="height: 38px; display: flex; align-items: center; justify-content: center; background: #462800;">
            <div class="text-3">Filtrar Reporte</div>
          </button>
        </div>
      </form>
    </div>

    <!-- Indicadores de Reportes -->
    <div class="status-overviews" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
      <div class="background-border">
        <div class="container-5"><div class="text-wrapper-2">Ingresos Totales</div></div>
        <div class="div-2"><div class="text-wrapper-3">$<?php echo number_format($ingresosTotales, 2); ?></div></div>
      </div>

      <div class="background-border-2" style="border-color: rgba(23, 106, 33, 0.25);">
        <div class="container-5"><div class="text-wrapper-2" style="color: #176a21;">Huevos Producidos</div></div>
        <div class="div-2"><div class="text-wrapper-3" style="color: #176a21;"><?php echo number_format($huevosProducidos); ?> ud</div></div>
      </div>

      <div class="background-border-3">
        <div class="container-5"><div class="text-wrapper-2">Pedidos Completados</div></div>
        <div class="div-2"><div class="text-wrapper-3"><?php echo $pedidosCompletados; ?> pedidos</div></div>
      </div>
    </div>

    <!-- Gráficos Interactivos Premium HTML/CSS -->
    <div class="inventory-forecast" style="margin-top: 24px;">
      <div class="overlay-11" style="height: auto; min-height: 280px; padding: 24px;">
        <div class="heading"><div class="text-wrapper-4">Tendencia de Ventas (Mensual)</div></div>
        <p class="text-25" style="margin-bottom: 20px;">Comportamiento acumulado de transacciones cerradas de huevos en la plataforma.</p>
        
        <div class="bar-chart">
          <?php foreach ($ventas_mensuales as $mes => $valor): 
              $percent = ($valor / 13000) * 100;
          ?>
          <div class="bar-item">
            <div class="bar-visual" style="height: <?php echo $percent; ?>%;" data-value="$<?php echo number_format($valor); ?>"></div>
            <div style="font-size: 11px; font-weight: 700; color: #7a5427;"><?php echo $mes; ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="overlay-12" style="height: auto; min-height: 280px; padding: 24px;">
        <div class="heading"><div class="text-wrapper-5">Distribución de Producción</div></div>
        <p class="text-25" style="margin-bottom: 20px;">Porcentaje de huevos producidos en granjas clasificadas por tipo biológico.</p>
        
        <div class="donut-chart">
          <?php 
          $colores = ["#176a21", "#ff8a00", "#d5a470", "#462800"];
          $idx = 0;
          foreach ($produccion_mensual as $tipo => $pct): 
              $color = $colores[$idx++];
          ?>
          <div class="donut-item">
            <div class="donut-label">
              <div class="donut-color" style="background: <?php echo $color; ?>;"></div>
              <span><?php echo $tipo; ?></span>
            </div>
            <div class="donut-bar-outer">
              <div class="donut-bar-inner" style="width: <?php echo $pct; ?>%; background: <?php echo $color; ?>;"></div>
            </div>
            <div class="donut-value"><?php echo $pct; ?>%</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Historial de Descargas y Exportaciones -->
    <div class="inventory-table" style="margin-top: 24px;">
      <div class="header">
        <div class="row" style="grid-template-columns: 2fr 1.5fr 1.5fr 1.5fr 1fr;">
          <div><div class="text-7">TIPO DE REPORTE</div></div>
          <div><div class="text-7">FORMATO</div></div>
          <div><div class="text-7">FECHA DE GENERACIÓN</div></div>
          <div><div class="text-8">ESTADO</div></div>
          <div><div class="text-9">ACCIÓN</div></div>
        </div>
      </div>

      <div class="row-disponible" style="grid-template-columns: 2fr 1.5fr 1.5fr 1.5fr 1fr; border-bottom: 1px solid rgba(213,164,112,.12);">
        <div><div class="text-10" style="font-weight:700;">Reporte Consolidado de Ventas</div></div>
        <div><div class="text-11">PDF • 3.2 MB</div></div>
        <div><div class="text-14"><?php echo date("d M Y"); ?></div></div>
        <div><div class="overlay-6"><div class="background-3"></div><div class="text-15">Listo</div></div></div>
        <div><a href="#" onclick="simularDescarga('Ventas_Consolidado.pdf')" class="text-10" style="color: #176a21; font-weight:700; text-decoration:none;">Descargar</a></div>
      </div>

      <div class="div-3" style="grid-template-columns: 2fr 1.5fr 1.5fr 1.5fr 1fr; border-bottom: 1px solid rgba(213,164,112,.12);">
        <div><div class="text-10" style="font-weight:700;">Trazabilidad e Inventario General</div></div>
        <div><div class="text-11">XLSX • 1.8 MB</div></div>
        <div><div class="text-14"><?php echo date("d M Y", strtotime("-1 day")); ?></div></div>
        <div><div class="overlay-6"><div class="background-3"></div><div class="text-15">Listo</div></div></div>
        <div><a href="#" onclick="simularDescarga('Inventario_Trazabilidad.xlsx')" class="text-10" style="color: #176a21; font-weight:700; text-decoration:none;">Descargar</a></div>
      </div>

      <div class="div-3" style="grid-template-columns: 2fr 1.5fr 1.5fr 1.5fr 1fr; border-bottom: 1px solid rgba(213,164,112,.12);">
        <div><div class="text-10" style="font-weight:700;">Registro de Proveedores y Granja</div></div>
        <div><div class="text-11">CSV • 420 KB</div></div>
        <div><div class="text-14"><?php echo date("d M Y", strtotime("-3 days")); ?></div></div>
        <div><div class="overlay-6"><div class="background-3"></div><div class="text-15">Listo</div></div></div>
        <div><a href="#" onclick="simularDescarga('Proveedores_Granjas.csv')" class="text-10" style="color: #176a21; font-weight:700; text-decoration:none;">Descargar</a></div>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p">MOSTRANDO 3 REPORTES AUTOGENERADOS</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
function simularExportacion(formato) {
    alert('¡Excelente! El reporte de ' + '<?php echo $tipo_reporte; ?>' + ' en formato ' + formato.toUpperCase() + ' ha comenzado a compilarse.\nSe descargará automáticamente en tu dispositivo en unos segundos.');
}

function simularDescarga(nombreArchivo) {
    alert('Iniciando descarga segura de: ' + nombreArchivo + '\nConsistencia y trazabilidad verificada por ECOALI.');
}
</script>
</body>
</html>