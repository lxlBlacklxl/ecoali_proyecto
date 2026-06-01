<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - COMPROBANTE DE COMPRA Y FACTURA ELECTRÓNICA PREMIUM (PDF/PRINT)
 * --------------------------------------------------------------------------------
 * Genera la factura electrónica premium del cliente. Incluye logotipos orgánicos,
 * desglose de IVA, cupones aplicados, código QR oficial simulado, y lanza
 * automáticamente la ventana de impresión/guardado en PDF.
 */

session_start();
require "forms/conexion.php";

// 1. VALIDAR ACCESO (SESIÓN ACTIVA)
if (!isset($_SESSION["usuario_id"])) {
    die("Por favor inicia sesión para ver este comprobante.");
}

$pedido_id = (int)($_GET["id"] ?? 0);

if ($pedido_id <= 0) {
    die("ID de pedido no válido.");
}

$cliente_id = $_SESSION["usuario_id"];
$rol_id = (int)$_SESSION["rol_id"];

// 2. CONSULTAR CABECERA DEL PEDIDO Y PERFIL DEL CLIENTE
// Si es admin, puede ver cualquier factura. Si es cliente, solo la suya propia.
$sql = "SELECT p.*, 
               up.nombre AS cliente_nombre, up.apellido AS cliente_apellido, up.email AS cliente_email, up.telefono AS cliente_telefono, up.direccion AS cliente_direccion,
               CONCAT(rep.nombre, ' ', rep.apellido) AS repartidor_nombre
        FROM pedidos p
        INNER JOIN usuario_perfil up ON p.cliente_id = up.usuario_id
        LEFT JOIN usuario_perfil rep ON p.repartidor_id = rep.usuario_id
        WHERE p.id = ?";

if ($rol_id !== 1) {
    // Si no es admin, forzar que el cliente_id sea el suyo
    $sql .= " AND p.cliente_id = " . $cliente_id;
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error en la consulta: " . $conn->error);
}

$stmt->bind_param("i", $pedido_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    die("Comprobante de pedido no encontrado o no tiene autorización para visualizarlo.");
}

$pedido = $res->fetch_assoc();

// 3. CONSULTAR DETALLES DE PRODUCTOS DEL PEDIDO
$sqlDetalles = "SELECT dp.*, prod.nombre AS producto_nombre, prod.tipo_huevo, prod.tamano
                FROM detalle_pedido dp
                INNER JOIN productos prod ON dp.producto_id = prod.id
                WHERE dp.pedido_id = ?";
$stmtDet = $conn->prepare($sqlDetalles);
$stmtDet->bind_param("i", $pedido_id);
$stmtDet->execute();
$detalles = $stmtDet->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura Electrónica #PED-<?php echo str_pad($pedido_id, 3, "0", STR_PAD_LEFT); ?> - EcoAli</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #176a21;
            --primary-light: rgba(23, 106, 33, 0.08);
            --secondary: #ff8a00;
            --text-dark: #322514;
            --text-medium: #705b44;
            --border: rgba(213, 164, 112, 0.2);
            --bg-organic: #fdfcfa;
        }

        body {
            font-family: 'Manrope', sans-serif;
            color: var(--text-dark);
            background: #fff;
            margin: 0;
            padding: 40px;
            line-height: 1.5;
            -webkit-print-color-adjust: exact;
        }

        .invoice-card {
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px;
            background: var(--bg-organic);
            box-shadow: 0 10px 30px rgba(70, 40, 0, 0.04);
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 30px;
            margin-bottom: 30px;
        }

        .logo-area {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -1px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logo-area span {
            font-size: 24px;
        }

        .invoice-title {
            text-align: right;
        }

        .invoice-title h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            color: var(--text-dark);
        }

        .invoice-title p {
            margin: 5px 0 0;
            font-size: 13px;
            color: var(--text-medium);
            font-weight: 600;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 45px;
        }

        .meta-block h3 {
            margin: 0 0 12px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--primary);
            letter-spacing: 1px;
        }

        .meta-block p {
            margin: 4px 0;
            font-size: 14px;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .meta-block p strong {
            color: #000;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 35px;
        }

        .items-table th {
            background: var(--primary);
            color: white;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 12px 16px;
            text-align: left;
        }

        .items-table th:last-child {
            text-align: right;
        }

        .items-table td {
            padding: 16px;
            font-size: 13px;
            border-bottom: 1px solid var(--border);
            color: var(--text-dark);
            font-weight: 500;
        }

        .items-table td:last-child {
            text-align: right;
            font-weight: 700;
            color: var(--primary);
        }

        .items-table tr:nth-child(even) td {
            background: rgba(213, 164, 112, 0.03);
        }

        .financial-summary {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 40px;
            align-items: flex-start;
            margin-top: 20px;
        }

        .official-seal {
            border: 1.5px dashed var(--border);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
            background: #fff;
        }

        .qr-placeholder {
            width: 80px;
            height: 80px;
            background: repeating-conic-gradient(from 0deg, #322514 0deg 90deg, #fff 90deg 180deg) 0 0/10px 10px;
            border: 2px solid var(--text-dark);
            border-radius: 8px;
            flex-shrink: 0;
            opacity: 0.85;
        }

        .seal-text {
            font-size: 11px;
            color: var(--text-medium);
            line-height: 1.5;
        }

        .seal-text strong {
            color: var(--text-dark);
            display: block;
            font-size: 12px;
            margin-bottom: 4px;
        }

        .totals-block {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-medium);
        }

        .totals-row.discount {
            color: #b02500;
        }

        .totals-row.final {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-dark);
            border-top: 1px solid var(--border);
            padding-top: 10px;
            margin-top: 5px;
        }

        .totals-row.final span:last-child {
            color: var(--secondary);
        }

        .footer-note {
            margin-top: 60px;
            text-align: center;
            font-size: 11px;
            color: var(--text-medium);
            border-top: 1px solid var(--border);
            padding-top: 20px;
        }

        .btn-print-panel {
            background: #fbf8f5;
            border: 1px solid var(--primary);
            padding: 15px 25px;
            border-radius: 16px;
            max-width: 800px;
            margin: 0 auto 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn-print-action {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 800;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            transition: opacity 0.2s;
        }

        .btn-print-action:hover {
            opacity: 0.9;
        }

        @media print {
            body {
                padding: 0;
            }
            .btn-print-panel {
                display: none;
            }
            .invoice-card {
                border: none;
                box-shadow: none;
                padding: 0;
                background: transparent;
            }
        }
    </style>
</head>
<body>

    <div class="btn-print-panel">
        <div style="font-size:13px; color:var(--primary); font-weight:700;">✓ Comprobante cargado correctamente. Listo para imprimir o descargar en PDF.</div>
        <button onclick="window.print()" class="btn-print-action">Imprimir Factura</button>
    </div>

    <div class="invoice-card">
        
        <div class="header-section">
            <div class="logo-area">
                <span>🌱</span> ECOALI
            </div>
            <div class="invoice-title">
                <h1>Factura Electrónica</h1>
                <p>No. #PED-<?php echo str_pad($pedido_id, 3, "0", STR_PAD_LEFT); ?></p>
            </div>
        </div>

        <div class="meta-grid">
            <div class="meta-block">
                <h3>Emisor</h3>
                <p><strong>EcoAli S.A. de C.V.</strong></p>
                <p>RFC: ECA190529A10</p>
                <p>Matriz: Av. de la Granja 750, CDMX</p>
                <p>Contacto: soporte@ecoali.com | +52 55 1234 5678</p>
            </div>
            <div class="meta-block" style="text-align: right;">
                <h3>Cliente Receptor</h3>
                <p><strong><?php echo htmlspecialchars($pedido["cliente_nombre"] . " " . $pedido["cliente_apellido"]); ?></strong></p>
                <p>Dirección: <?php echo htmlspecialchars($pedido["cliente_direccion"] ?? "No registrada"); ?></p>
                <p>Teléfono: <?php echo htmlspecialchars($pedido["cliente_telefono"] ?? "No registrado"); ?></p>
                <p>Correo: <?php echo htmlspecialchars($pedido["cliente_email"]); ?></p>
            </div>
        </div>

        <div class="meta-grid" style="margin-bottom: 30px; background: var(--primary-light); padding: 18px 24px; border-radius: 16px;">
            <div class="meta-block">
                <h3>Detalles de la Orden</h3>
                <p>Fecha Compra: <?php echo date("d/m/Y H:i:s", strtotime($pedido["fecha_pedido"])); ?></p>
                <p>Método de Pago: <?php echo strtoupper($pedido["metodo_pago"] ?? "Efectivo"); ?></p>
            </div>
            <div class="meta-block" style="text-align: right;">
                <h3>Información Logística</h3>
                <p>Estado de Pago: <strong><?php echo strtoupper($pedido["pago_estado"] ?? "Pendiente"); ?></strong></p>
                <p>Distribuidor: <?php echo htmlspecialchars($pedido["repartidor_nombre"] ?? "Por asignar"); ?></p>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Detalle del Producto</th>
                    <th>Cantidad</th>
                    <th style="text-align: right;">Precio Unitario</th>
                    <th style="text-align: right;">Importe</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detalles as $it): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($it["producto_nombre"]); ?> 
                            <small style="display:block; color:var(--text-medium); font-size:10px; font-weight:600; text-transform:uppercase;">
                                Tipo: <?php echo htmlspecialchars($it["tipo_huevo"]); ?> • Tamaño: <?php echo htmlspecialchars($it["tamano"]); ?>
                            </small>
                        </td>
                        <td><?php echo $it["cantidad"]; ?> uds</td>
                        <td style="text-align: right;">$<?php echo number_format($it["precio_unitario"], 2); ?></td>
                        <td style="text-align: right;">$<?php echo number_format($it["subtotal"], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="financial-summary">
            
            <div class="official-seal">
                <div class="qr-placeholder"></div>
                <div class="seal-text">
                    <strong>Sello Digital EcoAli</strong>
                    Certificado No: 00001000000504465451<br>
                    Cadena Original: ||1.1|PED-<?php echo $pedido_id; ?>|<?php echo $pedido["fecha_pedido"]; ?>|<?php echo $pedido["total"]; ?>||<br>
                    Trazabilidad certificada en granja de origen.
                </div>
            </div>

            <div class="totals-block">
                <?php
                // Reconstrucción de subtotal bruto y descuento para comprobante consistente
                $subtotal_bruto = 0.0;
                foreach ($detalles as $it) {
                    $subtotal_bruto += (float)$it["subtotal"];
                }
                
                $descuento_aplicado = (float)($pedido["descuento"] ?? 0.00);
                $total_neto = (float)$pedido["total"];
                
                // Si la base y el IVA están en base de datos, usarlos
                $subtotal_base = (float)($pedido["subtotal"] ?? 0.00);
                $iva = (float)($pedido["iva"] ?? 0.00);
                
                if ($subtotal_base <= 0) {
                    $subtotal_base = round($total_neto / 1.16, 2);
                    $iva = round($total_neto - $subtotal_base, 2);
                }
                ?>
                <div class="totals-row">
                    <span>Subtotal Bruto</span>
                    <span>$<?php echo number_format($subtotal_bruto, 2); ?></span>
                </div>
                
                <?php if ($descuento_aplicado > 0): ?>
                    <div class="totals-row discount">
                        <span>Descuentos Aplicados <?php echo !empty($pedido["cupon_codigo"]) ? "(" . $pedido["cupon_codigo"] . ")" : ""; ?></span>
                        <span>-$<?php echo number_format($descuento_aplicado, 2); ?></span>
                    </div>
                <?php endif; ?>

                <div class="totals-row">
                    <span>Subtotal Gravable</span>
                    <span>$<?php echo number_format($subtotal_base, 2); ?></span>
                </div>
                
                <div class="totals-row">
                    <span>IVA (16%)</span>
                    <span>$<?php echo number_format($iva, 2); ?></span>
                </div>

                <div class="totals-row final">
                    <span>Total Pagado</span>
                    <span>$<?php echo number_format($total_neto, 2); ?></span>
                </div>
            </div>

        </div>

        <div class="footer-note">
            Este comprobante es una representación digital impresa de un Comprobante Fiscal Digital por Internet.<br>
            EcoAli — Alimentación y Logística Inteligente y Sustentable de Huevos Orgánicos.
        </div>

    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 600);
        }
    </script>
</body>
</html>
