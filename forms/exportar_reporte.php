<?php
/**
 * --------------------------------------------------------------------------------
 * ECOALI - MOTOR DE EXPORTACIÓN MAESTRO (CSV & PDF IMPRIMIBLE)
 * --------------------------------------------------------------------------------
 * Genera reportes reales en CSV/Excel y vistas de alta fidelidad premium para
 * guardado digital en PDF, filtrados por rango de fechas y tipo de reporte.
 */

session_start();
require "conexion.php";

// 1. CONTROL DE ACCESO - VALIDAR ADMINISTRADOR
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
        die("Acceso no autorizado.");
    }
}

$formato = trim($_GET["formato"] ?? "excel");
$tipo_reporte = trim($_GET["tipo_reporte"] ?? "ventas");
$fecha_inicio = trim($_GET["fecha_inicio"] ?? "");
$fecha_fin = trim($_GET["fecha_fin"] ?? "");

// Rango por defecto si está vacío
if (empty($fecha_inicio)) {
    $fecha_inicio = date("Y-m-d", strtotime("-30 days"));
}
if (empty($fecha_fin)) {
    $fecha_fin = date("Y-m-d");
}

$conn->set_charset("utf8mb4");

// 2. OBTENCIÓN DE DATOS DEPENDIENDO DEL REPORTE
$data = [];
$titulo_reporte = "";
$columnas = [];

if ($tipo_reporte === "ventas") {
    $titulo_reporte = "Reporte Consolidado de Ventas";
    $columnas = ["ID Pedido", "Fecha", "Cliente", "Total Pedido", "Descuento", "Estado"];
    
    $sql = "SELECT p.id, p.fecha_pedido, CONCAT(up.nombre, ' ', up.apellido) AS cliente, p.total, p.descuento, p.estado 
            FROM pedidos p 
            INNER JOIN usuario_perfil up ON p.cliente_id = up.usuario_id 
            WHERE DATE(p.fecha_pedido) BETWEEN ? AND ? 
            ORDER BY p.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            "ID Pedido" => "#PED-" . str_pad($row["id"], 3, "0", STR_PAD_LEFT),
            "Fecha" => date("d/m/Y H:i", strtotime($row["fecha_pedido"])),
            "Cliente" => $row["cliente"],
            "Total Pedido" => "$" . number_format($row["total"], 2),
            "Descuento" => "$" . number_format($row["descuento"], 2),
            "Estado" => ucfirst($row["estado"])
        ];
    }

} elseif ($tipo_reporte === "produccion") {
    $titulo_reporte = "Reporte General de Producción";
    $columnas = ["ID Registro", "Fecha", "Granja", "Cantidad (uds)", "Tipo Huevo", "Detalle"];
    
    $sql = "SELECT pr.id, pr.fecha_produccion, COALESCE(g.nombre, 'Almacén Central') AS granja, pr.cantidad, pr.tipo_huevo, pr.detalle 
            FROM produccion pr 
            LEFT JOIN granjas g ON pr.granja_id = g.id 
            WHERE pr.fecha_produccion BETWEEN ? AND ? 
            ORDER BY pr.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            "ID Registro" => "#PRD-" . str_pad($row["id"], 3, "0", STR_PAD_LEFT),
            "Fecha" => date("d/m/Y", strtotime($row["fecha_produccion"])),
            "Granja" => $row["granja"],
            "Cantidad (uds)" => $row["cantidad"] . " uds",
            "Tipo Huevo" => ucfirst($row["tipo_huevo"]),
            "Detalle" => $row["detalle"] ?? "Sin observaciones"
        ];
    }

} elseif ($tipo_reporte === "inventario") {
    $titulo_reporte = "Trazabilidad e Inventario de Huevos";
    $columnas = ["ID Lote", "Código Lote", "Granja", "Producto", "Stock Actual", "Fecha Postura", "Fecha Vencimiento", "Estado"];
    
    $sql = "SELECT inv.id, inv.codigo_lote, COALESCE(g.nombre, 'Externo') AS granja, p.nombre AS producto, inv.cantidad, inv.fecha_postura, inv.fecha_caducidad, inv.estado 
            FROM inventario_huevos inv 
            LEFT JOIN granjas g ON inv.granja_id = g.id 
            LEFT JOIN productos p ON inv.producto_id = p.id 
            WHERE inv.fecha_postura BETWEEN ? AND ? 
            ORDER BY inv.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            "ID Lote" => "#LOT-" . str_pad($row["id"], 3, "0", STR_PAD_LEFT),
            "Código Lote" => $row["codigo_lote"],
            "Granja" => $row["granja"],
            "Producto" => $row["producto"] ?? "Desconocido",
            "Stock Actual" => $row["cantidad"] . " uds",
            "Fecha Postura" => date("d/m/Y", strtotime($row["fecha_postura"])),
            "Fecha Vencimiento" => date("d/m/Y", strtotime($row["fecha_caducidad"])),
            "Estado" => strtoupper($row["estado"])
        ];
    }

} elseif ($tipo_reporte === "clientes") {
    $titulo_reporte = "Actividad y Consumo de Clientes";
    $columnas = ["ID Cliente", "Nombre Completo", "Correo Electrónico", "Dirección", "Teléfono", "Pedidos Realizados", "Total Gastado", "Estado"];
    
    $sql = "SELECT u.id, up.nombre, up.apellido, up.email, up.direccion, up.telefono, u.activo,
                   COUNT(p.id) AS total_pedidos,
                   COALESCE(SUM(p.total), 0) AS total_gastado
            FROM usuarios u
            INNER JOIN usuario_perfil up ON u.id = up.usuario_id
            LEFT JOIN pedidos p ON u.id = p.cliente_id
            WHERE u.rol_id = 2
            GROUP BY u.id
            ORDER BY total_gastado DESC";
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            "ID Cliente" => "#CLI-" . str_pad($row["id"], 3, "0", STR_PAD_LEFT),
            "Nombre Completo" => $row["nombre"] . " " . $row["apellido"],
            "Correo Electrónico" => $row["email"],
            "Dirección" => $row["direccion"] ?? "No registrada",
            "Teléfono" => $row["telefono"] ?? "No registrado",
            "Pedidos Realizados" => $row["total_pedidos"] . " ped.",
            "Total Gastado" => "$" . number_format($row["total_gastado"], 2),
            "Estado" => $row["activo"] === 1 ? "ACTIVO" : "INACTIVO"
        ];
    }
}

// 3. EXPORTACIÓN EN FORMATO EXCEL/CSV
if ($formato === "excel" || $formato === "csv") {
    $filename = str_replace(" ", "_", $titulo_reporte) . "_" . date("Ymd_His") . ".csv";
    
    // Set headers for download
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    // Output stream
    $output = fopen("php://output", "w");
    
    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Escribir cabecera
    fputcsv($output, $columnas);
    
    // Escribir filas
    foreach ($data as $fila) {
        fputcsv($output, array_values($fila));
    }
    
    fclose($output);
    auditar_accion("Reportes", "Reporte exportado CSV", "Se exportó el '$titulo_reporte' en formato CSV/Excel.");
    exit;
}

// 4. EXPORTACIÓN EN FORMATO PDF (VISTA DE IMPRESIÓN PREMIUM DE ALTA FIDELIDAD)
if ($formato === "pdf") {
    auditar_accion("Reportes", "Reporte exportado PDF", "Se generó el '$titulo_reporte' en formato PDF imprimible.");
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title><?php echo $titulo_reporte; ?> - ECOALI</title>
        <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Manrope', sans-serif;
                color: #322514;
                background-color: #fff;
                margin: 0;
                padding: 40px;
                line-height: 1.5;
            }
            .header-report {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 2px solid #176a21;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            .logo {
                font-family: 'Plus Jakarta Sans', sans-serif;
                font-size: 28px;
                font-weight: 800;
                color: #176a21;
                letter-spacing: -1px;
            }
            .report-title h1 {
                margin: 0;
                font-size: 22px;
                font-weight: 800;
                color: #462800;
            }
            .report-title p {
                margin: 5px 0 0;
                font-size: 13px;
                color: #705b44;
                font-weight: 600;
            }
            .metadata-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
                background: #fbf8f5;
                border: 1px solid rgba(213, 164, 112, 0.2);
                border-radius: 12px;
                padding: 15px 20px;
                margin-bottom: 30px;
            }
            .meta-item {
                font-size: 13px;
            }
            .meta-item strong {
                color: #462800;
                text-transform: uppercase;
                font-size: 11px;
                display: block;
                margin-bottom: 2px;
            }
            .report-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            .report-table th {
                background: #176a21;
                color: white;
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                padding: 12px;
                text-align: left;
            }
            .report-table td {
                padding: 12px;
                font-size: 13px;
                border-bottom: 1px solid rgba(213, 164, 112, 0.15);
                color: #322514;
            }
            .report-table tr:nth-child(even) {
                background: #fdfcfa;
            }
            .footer-report {
                margin-top: 50px;
                text-align: center;
                font-size: 11px;
                color: #705b44;
                border-top: 1px solid rgba(213, 164, 112, 0.2);
                padding-top: 20px;
            }
            .badge-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 6px;
                font-size: 10px;
                font-weight: 800;
            }
            @media print {
                body {
                    padding: 0;
                }
                .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        
        <div class="no-print" style="background:#fbf8f5; border:1px solid #176a21; padding:15px; border-radius:12px; margin-bottom:25px; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-size:13px; color:#176a21; font-weight:700;">✓ Vista de impresión premium generada con éxito. Listo para guardar como PDF.</div>
            <button onclick="window.print()" style="background:#176a21; color:#white; border:none; padding:8px 16px; border-radius:8px; font-weight:800; color:white; cursor:pointer;">Imprimir / Guardar PDF</button>
        </div>

        <div class="header-report">
            <div class="report-title">
                <h1><?php echo $titulo_reporte; ?></h1>
                <p>Consolidación oficial de datos corporativos de EcoAli</p>
            </div>
            <div class="logo">🌱 ECOALI</div>
        </div>

        <div class="metadata-grid">
            <div class="meta-item">
                <strong>Rango de Fechas</strong>
                <?php echo date("d/m/Y", strtotime($fecha_inicio)); ?> al <?php echo date("d/m/Y", strtotime($fecha_fin)); ?>
            </div>
            <div class="meta-item">
                <strong>Generado Por</strong>
                Administrador EcoAli (<?php echo htmlspecialchars($_SESSION["admin_session"]["nombre"] ?? $_SESSION["nombre"] ?? "Admin"); ?>)
            </div>
            <div class="meta-item">
                <strong>Fecha Emisión</strong>
                <?php echo date("d/m/Y H:i:s"); ?>
            </div>
        </div>

        <table class="report-table">
            <thead>
                <tr>
                    <?php foreach ($columnas as $col): ?>
                        <th><?php echo $col; ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data)): ?>
                    <tr>
                        <td colspan="<?php echo count($columnas); ?>" style="text-align:center; padding:30px; color:#705b44;">No se encontraron registros conciliados en el rango de fechas seleccionado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data as $fila): ?>
                        <tr>
                            <?php foreach ($fila as $key => $val): ?>
                                <td>
                                    <?php 
                                    if ($key === "Estado") {
                                        $bg = "rgba(213, 164, 112, 0.2)";
                                        $cl = "#705b44";
                                        if (in_array(strtolower($val), ["entregado", "activo", "disponible"])) {
                                            $bg = "rgba(23, 106, 33, 0.12)";
                                            $cl = "#176a21";
                                        } elseif (in_array(strtolower($val), ["cancelado", "inactivo", "caducado"])) {
                                            $bg = "rgba(176, 37, 0, 0.12)";
                                            $cl = "#b02500";
                                        }
                                        echo "<span class='badge-status' style='background:$bg; color:$cl;'>$val</span>";
                                    } else {
                                        echo htmlspecialchars($val);
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="footer-report">
            EcoAli S.A. de C.V. — Sistema Automatizado de Trazabilidad, Auditoría y Reportes (Requisito #25)<br>
            Este documento digital es una representación válida de los registros operativos de la base de datos de producción.
        </div>

        <script>
            window.onload = function() {
                // Auto trigger print dialogue for immediate PDF conversion
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
