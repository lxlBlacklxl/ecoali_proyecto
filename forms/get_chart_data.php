<?php
session_start();
require_once "conexion.php";

header('Content-Type: application/json');

if (!isset($_SESSION["usuario_id"]) || (int)$_SESSION["rol_id"] !== 3) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$usuario_id = $_SESSION["usuario_id"];

// Obtener proveedor_id
$stmtProv = $conn->prepare("SELECT id FROM proveedores WHERE usuario_id = ?");
$stmtProv->bind_param("i", $usuario_id);
$stmtProv->execute();
$resProv = $stmtProv->get_result();
if ($resProv->num_rows === 0) {
    echo json_encode(['error' => 'Proveedor no encontrado']);
    exit;
}
$proveedor_id = (int)$resProv->fetch_assoc()["id"];
$stmtProv->close();

$filter = $_GET['filter'] ?? '7d';

$fechas = [];
$produccion = [];
$viables = 0;
$noViables = 0;
$mermas = 0;

if ($filter === '7d' || $filter === '30d') {
    $days = ($filter === '7d') ? 7 : 30;
    
    // Obtener datos de tendencia (diarios)
    for ($i = $days - 1; $i >= 0; $i--) {
        $fecha = date('Y-m-d', strtotime("-$i days"));
        $fechas[] = date('d/m', strtotime($fecha));
        
        $stmtG = $conn->prepare("SELECT SUM(cantidad) FROM produccion WHERE proveedor_id = ? AND fecha_produccion = ?");
        $stmtG->bind_param("is", $proveedor_id, $fecha);
        $stmtG->execute();
        $resG = $stmtG->get_result();
        $produccion[] = (int)($resG->fetch_row()[0] ?? 0);
        $stmtG->close();
    }
    
    // Obtener datos de calidad acumulados en el periodo
    $fecha_inicio = date('Y-m-d', strtotime("-".($days-1)." days"));
    $stmtQ = $conn->prepare("SELECT SUM(cantidad), SUM(no_viable), SUM(merma) FROM produccion WHERE proveedor_id = ? AND fecha_produccion >= ?");
    $stmtQ->bind_param("is", $proveedor_id, $fecha_inicio);
    $stmtQ->execute();
    $resQ = $stmtQ->get_result();
    $rowQ = $resQ->fetch_row();
    $viables = (int)($rowQ[0] ?? 0);
    $noViables = (int)($rowQ[1] ?? 0);
    $mermas = (int)($rowQ[2] ?? 0);
    $stmtQ->close();
    
} else if ($filter === '1y') {
    // Obtener datos de tendencia (mensuales)
    $meses_nombres = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    for ($i = 11; $i >= 0; $i--) {
        $mes_inicio = date('Y-m-01', strtotime("-$i months"));
        $mes_fin = date('Y-m-t', strtotime("-$i months"));
        
        $mes_idx = (int)date('n', strtotime($mes_inicio)) - 1;
        $fechas[] = $meses_nombres[$mes_idx] . ' ' . date('y', strtotime($mes_inicio));
        
        $stmtG = $conn->prepare("SELECT SUM(cantidad) FROM produccion WHERE proveedor_id = ? AND fecha_produccion >= ? AND fecha_produccion <= ?");
        $stmtG->bind_param("iss", $proveedor_id, $mes_inicio, $mes_fin);
        $stmtG->execute();
        $resG = $stmtG->get_result();
        $produccion[] = (int)($resG->fetch_row()[0] ?? 0);
        $stmtG->close();
    }
    
    // Obtener datos de calidad acumulados en el año
    $fecha_inicio = date('Y-m-01', strtotime("-11 months"));
    $stmtQ = $conn->prepare("SELECT SUM(cantidad), SUM(no_viable), SUM(merma) FROM produccion WHERE proveedor_id = ? AND fecha_produccion >= ?");
    $stmtQ->bind_param("is", $proveedor_id, $fecha_inicio);
    $stmtQ->execute();
    $resQ = $stmtQ->get_result();
    $rowQ = $resQ->fetch_row();
    $viables = (int)($rowQ[0] ?? 0);
    $noViables = (int)($rowQ[1] ?? 0);
    $mermas = (int)($rowQ[2] ?? 0);
    $stmtQ->close();
}

echo json_encode([
    'fechas' => $fechas,
    'produccion' => $produccion,
    'calidad' => [
        'viables' => $viables,
        'noViables' => $noViables,
        'mermas' => $mermas
    ]
]);
?>
