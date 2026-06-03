<?php
session_start();
require "forms/conexion.php";

// 1. Control de acceso: Solo administradores
if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 1) {
    die("Acceso no autorizado.");
}

$nombre = $_SESSION["nombre"] ?? "Admin";
$filtro = $_GET["filtro"] ?? "todos";

// 2. Construir consulta según el filtro
$sql = "SELECT p.*, 
               CONCAT(upc.nombre, ' ', upc.apellido) AS nombre_cliente, 
               upc.direccion AS direccion_cliente,
               CONCAT(upr.nombre, ' ', upr.apellido) AS nombre_repartidor
        FROM pedidos p
        LEFT JOIN usuario_perfil upc ON p.cliente_id = upc.usuario_id
        LEFT JOIN usuario_perfil upr ON p.repartidor_id = upr.usuario_id";

if ($filtro === "entregados") {
    $sql .= " WHERE p.estado = 'entregado'";
    $titulo_reporte = "Reporte de Pedidos Entregados";
    $color_tema = "#176a21";
} elseif ($filtro === "cancelados") {
    $sql .= " WHERE p.estado = 'cancelado'";
    $titulo_reporte = "Reporte de Pedidos Cancelados";
    $color_tema = "#b02500";
} elseif ($filtro === "no_asignados") {
    $sql .= " WHERE p.repartidor_id IS NULL OR p.repartidor_id = 0";
    $titulo_reporte = "Reporte de Pedidos Sin Asignar";
    $color_tema = "#ff8a00";
} else {
    $titulo_reporte = "Reporte General de Pedidos";
    $color_tema = "#8d4a00";
}

$sql .= " ORDER BY p.id DESC";
$result = $conn->query($sql);
$pedidos = [];
$total_monto = 0.0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pedidos[] = $row;
        if (strtolower($row["estado"]) !== "cancelado") {
            $total_monto += (float)$row["total"];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $titulo_reporte; ?> - ECOALI</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<style>
  body {
    font-family: 'Manrope', sans-serif;
    color: #33200a;
    background-color: #fff;
    margin: 40px;
    padding: 0;
  }
  
  .print-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid <?php echo $color_tema; ?>;
    padding-bottom: 20px;
    margin-bottom: 30px;
  }
  
  .logo-brand {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 28px;
    font-weight: 800;
    color: #176a21;
  }
  
  .report-title {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 20px;
    font-weight: 800;
    color: <?php echo $color_tema; ?>;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-align: right;
  }
  
  .meta-info {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #665544;
    margin-bottom: 24px;
    background: #faf6f0;
    padding: 14px 20px;
    border-radius: 12px;
  }
  
  .table-report {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
  }
  
  .table-report th {
    background-color: #ffeedf;
    color: #7a5427;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px 14px;
    text-align: left;
    border-bottom: 2px solid #e0d5c1;
  }
  
  .table-report td {
    padding: 12px 14px;
    font-size: 13px;
    border-bottom: 1px solid #eee1d0;
    vertical-align: top;
  }
  
  .table-report tr:nth-child(even) td {
    background-color: #fffaf5;
  }
  
  .badge-status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
  }
  
  .status-pendiente { background: #ffe6d5; color: #8d4a00; }
  .status-preparado { background: #fff8d5; color: #6d5a00; }
  .status-en_ruta { background: #fff8d5; color: #6d5a00; }
  .status-entregado { background: #dffbd5; color: #176a21; }
  .status-cancelado { background: #ffd5d5; color: #b02500; }
  
  .order-total {
    font-weight: 700;
    color: #462800;
  }
  
  .report-summary {
    display: flex;
    justify-content: flex-end;
    margin-top: 30px;
    font-size: 14px;
  }
  
  .summary-card {
    background: #ffeedf;
    border: 1px solid rgba(213, 164, 112, 0.25);
    border-radius: 16px;
    padding: 20px;
    min-width: 250px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  
  .summary-row {
    display: flex;
    justify-content: space-between;
    font-weight: 600;
  }
  
  .summary-total {
    font-size: 18px;
    font-weight: 800;
    color: #8d4a00;
    border-top: 1px solid #e0d5c1;
    padding-top: 8px;
    margin-top: 4px;
  }
  
  .no-print-bar {
    background: #33200a;
    padding: 12px 40px;
    margin: -40px -40px 40px -40px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #fff;
  }
  
  .btn-print-action {
    background: #ff8a00;
    border: none;
    border-radius: 20px;
    padding: 8px 20px;
    color: white;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.2s ease;
  }
  .btn-print-action:hover {
    background: #ffb300;
  }
  
  @media print {
    .no-print-bar {
      display: none;
    }
    body {
      margin: 0;
    }
    .table-report th {
      background-color: #ffeedf !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .badge-status {
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .status-pendiente { background: #ffe6d5 !important; }
    .status-preparado { background: #fff8d5 !important; }
    .status-en_ruta { background: #fff8d5 !important; }
    .status-entregado { background: #dffbd5 !important; }
    .status-cancelado { background: #ffd5d5 !important; }
    .summary-card {
      background: #ffeedf !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
  }
</style>
</head>
<body>

<div class="no-print-bar">
  <span>Visualización de Impresión - EcoAli</span>
  <div style="display: flex; gap: 10px;">
    <button class="btn-print-action" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
    <button class="btn-print-action" style="background: rgba(255,255,255,0.15); color: #fff;" onclick="window.close()">Cerrar Vista</button>
  </div>
</div>

<div class="print-header">
  <div class="logo-brand">ECOALI</div>
  <div class="report-title"><?php echo $titulo_reporte; ?></div>
</div>

<div class="meta-info">
  <div>
    <strong>Generado por:</strong> Administrador (<?php echo htmlspecialchars($nombre); ?>)<br>
    <strong>Fecha de Emisión:</strong> <?php echo date("d/m/Y h:i A"); ?>
  </div>
  <div style="text-align: right;">
    <strong>Filtro Aplicado:</strong> <?php echo ucfirst(str_replace("_", " ", $filtro)); ?><br>
    <strong>Total Registros:</strong> <?php echo count($pedidos); ?>
  </div>
</div>

<table class="table-report">
  <thead>
    <tr>
      <th>ID Pedido</th>
      <th>Cliente</th>
      <th>Dirección de Entrega</th>
      <th>Repartidor</th>
      <th>Estado</th>
      <th>Fecha Registro</th>
      <th style="text-align: right;">Monto Total</th>
    </tr>
  </thead>
  <tbody>
    <?php if (count($pedidos) > 0): ?>
        <?php foreach ($pedidos as $p): 
            $estado = strtolower($p["estado"]);
            $estadoText = "Pendiente";
            if ($estado === "preparado") $estadoText = "Preparado";
            elseif ($estado === "en_ruta") $estadoText = "En Ruta";
            elseif ($estado === "entregado") $estadoText = "Entregado";
            elseif ($estado === "cancelado") $estadoText = "Cancelado";
        ?>
        <tr>
          <td style="font-weight: 700; color: #8d4a00;">#PED-<?php echo str_pad($p["id"], 3, "0", STR_PAD_LEFT); ?></td>
          <td style="font-weight: 600;"><?php echo htmlspecialchars($p["nombre_cliente"] ?? "Cliente Anónimo"); ?></td>
          <td><?php echo htmlspecialchars($p["direccion_cliente"] ?? "Sin dirección registrada"); ?></td>
          <td><?php echo htmlspecialchars($p["nombre_repartidor"] ?? "No asignado"); ?></td>
          <td>
            <span class="badge-status status-<?php echo $estado; ?>">
              <?php echo $estadoText; ?>
            </span>
          </td>
          <td><?php echo date("d M Y, H:i", strtotime($p["fecha_pedido"])); ?></td>
          <td class="order-total" style="text-align: right;">$<?php echo number_format($p["total"], 2); ?></td>
        </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
          <td colspan="7" style="text-align: center; color: #665544; padding: 30px;">
            No se encontraron pedidos con el filtro seleccionado.
          </td>
        </tr>
    <?php endif; ?>
  </tbody>
</table>

<div class="report-summary">
  <div class="summary-card">
    <div class="summary-row">
      <span>Pedidos Listados:</span>
      <span><?php echo count($pedidos); ?></span>
    </div>
    <div class="summary-row">
      <span>Pedidos Cancelados:</span>
      <span>
        <?php 
        $cancelados = array_filter($pedidos, function($p) { return strtolower($p["estado"]) === 'cancelado'; });
        echo count($cancelados);
        ?>
      </span>
    </div>
    <div class="summary-row summary-total">
      <span>Ingresos Estimados*:</span>
      <span>$<?php echo number_format($total_monto, 2); ?></span>
    </div>
    <span style="font-size: 9px; color: #8c7864; font-weight: 500; margin-top: 4px; display: block; text-align: right;">
      * Excluye pedidos cancelados.
    </span>
  </div>
</div>

<script>
// Lanzar el diálogo de impresión del navegador automáticamente tras cargar la página
window.onload = function() {
    setTimeout(function() {
        window.print();
    }, 500);
};
</script>

</body>
</html>
