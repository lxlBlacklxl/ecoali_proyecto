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

// --- PROCESAR ACCIÓN DE VACIAR BITÁCORA ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"]) && $_POST["accion"] === "vaciar") {
    if ($conn->query("TRUNCATE TABLE bitacora")) {
        registrar_bitacora("Limpieza de bitácora", "Bitácora", "El administrador vació todos los registros de auditoría del sistema.");
        $_SESSION["mensaje_exito"] = "La bitácora de auditoría se ha limpiado correctamente y el historial ha sido reiniciado.";
    } else {
        $_SESSION["mensaje_error"] = "Error al intentar limpiar la bitácora: " . $conn->error;
    }
    header("Location: bitacora_admin.php");
    exit;
}

// Obtener registros de bitácora completos con Joins
$queryLog = "SELECT b.id, b.accion_realizada, b.modulo_afectado, b.descripcion, b.fecha, b.hora,
                    u.usuario, 
                    COALESCE(CONCAT(up.nombre, ' ', up.apellido), 'Admin del Sistema') AS nombre_operador,
                    CASE 
                        WHEN u.rol_id = 1 THEN 'Administrador'
                        WHEN u.rol_id = 2 THEN 'Cliente'
                        WHEN u.rol_id = 3 THEN 'Proveedor'
                        WHEN u.rol_id = 4 THEN 'Repartidor'
                        ELSE 'Operador'
                    END AS rol_nombre
             FROM bitacora b
             LEFT JOIN usuarios u ON b.usuario_id = u.id
             LEFT JOIN usuario_perfil up ON u.id = up.usuario_id
             ORDER BY b.id DESC";
$resultLog = $conn->query($queryLog);
$countLog = $resultLog ? $resultLog->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bitácora de Auditoría - ECOALI</title>

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

  <!-- Menú lateral -->
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

  <!-- Header -->
  <div class="header-topappbar">
    <div class="div-4"><div class="text-26">Bitácora Global de Auditoría (Inmutable)</div></div>
    <div style="display:flex; align-items:center; gap: 12px;">
        <span style="font-size:12px; font-weight:700; color:#b02500; background:rgba(176,37,0,0.1); padding:4px 10px; border-radius:8px;">🔒 AUDITORÍA PROTEGIDA</span>
        <button class="button-2" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 18px 35px rgba(176, 37, 0, 0.15);" onclick="abrirModalVaciar()"><div class="text-3">Limpiar Bitácora</div></button>
    </div>
  </div>

  <div class="main-content">

    <!-- Alertas del sistema -->
    <?php if (isset($_SESSION["mensaje_exito"])): ?>
        <div class="alert-container" style="margin-bottom: 24px;">
            <div class="alert alert-success">
                <span>✓</span> <?php echo $_SESSION["mensaje_exito"]; unset($_SESSION["mensaje_exito"]); ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION["mensaje_error"])): ?>
        <div class="alert-container" style="margin-bottom: 24px;">
            <div class="alert alert-danger">
                <span>✗</span> <?php echo $_SESSION["mensaje_error"]; unset($_SESSION["mensaje_error"]); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Buscador y Filtros Interactivos -->
    <div class="section-search" style="padding: 15px 20px; background: rgba(255,255,255,0.45); border-radius: 16px; margin-bottom: 24px; border: 1px solid rgba(213, 164, 112, 0.15); display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
      
      <div style="display: flex; flex-direction: column; gap: 5px; flex: 2; min-width: 200px;">
        <label style="font-size: 11px; font-weight: 700; color: #7a5427; text-transform: uppercase;">Buscar Acción u Operador</label>
        <input type="text" id="buscarLog" placeholder="Filtrar por operador, acción, descripción..." onkeyup="filtrarBitacora()" style="padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(213, 164, 112, 0.3); outline: none; font-family: inherit; font-size: 13px; color: #462800; font-weight: 600; background: #fff;">
      </div>

      <div style="display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 140px;">
        <label style="font-size: 11px; font-weight: 700; color: #7a5427; text-transform: uppercase;">Módulo Afectado</label>
        <select id="filtrarModulo" onchange="filtrarBitacora()" style="padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(213, 164, 112, 0.3); outline: none; font-family: inherit; font-size: 13px; color: #462800; font-weight: 600; background: #fff;">
          <option value="todos">Todos los módulos</option>
          <option value="Dashboard">Dashboard</option>
          <option value="Usuarios">Usuarios</option>
          <option value="Clientes">Clientes</option>
          <option value="Proveedores">Proveedores</option>
          <option value="Inventario">Inventario</option>
          <option value="Productos">Productos</option>
          <option value="Logística">Logística</option>
          <option value="Regalías">Regalías</option>
        </select>
      </div>

      <div style="display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 140px;">
        <label style="font-size: 11px; font-weight: 700; color: #7a5427; text-transform: uppercase;">Filtrar por Fecha</label>
        <input type="date" id="filtrarFecha" onchange="filtrarBitacora()" style="padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(213, 164, 112, 0.3); outline: none; font-family: inherit; font-size: 13px; color: #462800; font-weight: 600; background: #fff;">
      </div>
      
      <div style="display: flex; align-items: flex-end; margin-top: 15px;">
        <button onclick="limpiarFiltros()" class="button-2" style="height: 38px; display: flex; align-items: center; justify-content: center; background: #7a5427;">
          <div class="text-3">Limpiar</div>
        </button>
      </div>

    </div>

    <!-- Tabla Cronológica de Bitácora -->
    <div class="inventory-table">
      <div class="header">
        <div class="row" style="grid-template-columns: 0.8fr 1.6fr 1.2fr 1.5fr 1.2fr 3.5fr 1.2fr 1fr;">
          <div><div class="text-7">ID LOG</div></div>
          <div><div class="text-7">OPERADOR</div></div>
          <div><div class="text-7">ROL</div></div>
          <div><div class="text-7">ACCIÓN</div></div>
          <div><div class="text-8">MÓDULO</div></div>
          <div><div class="text-7">DESCRIPCIÓN DE LA ACTIVIDAD</div></div>
          <div><div class="text-7">FECHA</div></div>
          <div><div class="text-7">HORA</div></div>
        </div>
      </div>

      <div id="tablaCuerpo">
      <?php if ($countLog > 0): ?>
          <?php while ($row = $resultLog->fetch_assoc()): ?>
          <div class="div-3 row-bitacora" data-modulo="<?php echo htmlspecialchars($row["modulo_afectado"]); ?>" data-fecha="<?php echo $row["fecha"]; ?>" style="grid-template-columns: 0.8fr 1.6fr 1.2fr 1.5fr 1.2fr 3.5fr 1.2fr 1fr; border-bottom: 1px solid rgba(213,164,112,.12); min-height: 50px;">
            <div><div class="text-10">#LOG-<?php echo str_pad($row["id"], 4, "0", STR_PAD_LEFT); ?></div></div>
            <div><div class="text-11" style="font-weight: 700;"><?php echo htmlspecialchars($row["nombre_operador"]); ?></div></div>
            <div><div class="text-12" style="font-weight: 600; color: #7a5427;"><?php echo htmlspecialchars($row["rol_nombre"]); ?></div></div>
            <div><div class="text-11" style="font-weight: 700; color: #176a21;"><?php echo htmlspecialchars($row["accion_realizada"]); ?></div></div>
            <div>
              <div class="background-2">
                <div class="text-13"><?php echo htmlspecialchars($row["modulo_afectado"]); ?></div>
              </div>
            </div>
            <div><div class="text-14" style="text-transform: none; font-size:12.5px; color:#462800; text-align: left; line-height: 1.4; padding-right:10px;"><?php echo htmlspecialchars($row["descripcion"]); ?></div></div>
            <div><div class="text-14"><?php echo date("d M Y", strtotime($row["fecha"])); ?></div></div>
            <div><div class="text-14" style="font-weight: 700;"><?php echo date("H:i:s", strtotime($row["hora"])); ?></div></div>
          </div>
          <?php endwhile; ?>
      <?php else: ?>
          <div class="div-3" style="grid-template-columns: 1fr; text-align: center; padding: 30px;">
              <div class="text-11" style="color: #996e3f;">La bitácora de auditoría del sistema está limpia.</div>
          </div>
      <?php endif; ?>
      </div>

      <div class="pagination-like">
        <div class="div-4"><p class="p" id="paginacionTexto">MOSTRANDO <?php echo $countLog; ?> REGISTROS DE SEGURIDAD</p></div>
        <div class="container-8">
          <button class="button-3"><div class="text-23">1</div></button>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal Confirmar Limpieza de Bitácora -->
<div class="modal-overlay" id="modalVaciar">
  <div class="modal-container" style="max-width: 460px; border: 2px solid #b02500; box-shadow: 0 30px 70px rgba(176, 37, 0, 0.25);">
    <div class="modal-header" style="border-bottom: 1px solid rgba(176, 37, 0, 0.15); padding-bottom: 12px; margin-bottom: 16px;">
      <div class="modal-title" style="color: #b02500; display: flex; align-items: center; gap: 8px; font-weight: 800;">
        ⚠️ ¡ADVERTENCIA DE SEGURIDAD!
      </div>
      <button class="modal-close" onclick="cerrarModal('modalVaciar')" style="color: #b02500;">×</button>
    </div>
    
    <div style="margin-bottom: 24px;">
      <p style="color: #462800; font-size: 14px; line-height: 1.6; font-weight: 700; margin-bottom: 12px;">
        ¿Estás seguro de que deseas limpiar todo el historial de auditoría?
      </p>
      <p style="color: #7a5427; font-size: 13px; line-height: 1.5; background: rgba(176, 37, 0, 0.05); padding: 12px; border-radius: 12px; border-left: 4px solid #b02500;">
        Esta acción eliminará de forma permanente e irreversible todos los registros de actividades del sistema. <strong>Esta operación no se puede deshacer.</strong>
      </p>
    </div>

    <form action="bitacora_admin.php" method="POST">
      <input type="hidden" name="accion" value="vaciar">
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="cerrarModal('modalVaciar')">Cancelar y Volver</button>
        <button type="submit" class="btn-submit" style="background: linear-gradient(90deg, #b02500, #ff4c1c); box-shadow: 0 10px 25px rgba(176, 37, 0, 0.15);">Aceptar y Limpiar</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalVaciar() {
    document.getElementById('modalVaciar').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Filtrado interactivo combinado
function filtrarBitacora() {
    const textQuery = document.getElementById('buscarLog').value.toLowerCase();
    const modQuery = document.getElementById('filtrarModulo').value;
    const dateQuery = document.getElementById('filtrarFecha').value;
    
    const rows = document.querySelectorAll('#tablaCuerpo .row-bitacora');
    let visibleCount = 0;

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const rModulo = row.getAttribute('data-modulo');
        const rFecha = row.getAttribute('data-fecha');
        
        let matchText = text.includes(textQuery);
        let matchModulo = (modQuery === 'todos' || rModulo === modQuery);
        let matchFecha = (!dateQuery || rFecha === dateQuery);
        
        if (matchText && matchModulo && matchFecha) {
            row.style.display = 'grid';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('paginacionTexto').textContent = `MOSTRANDO ${visibleCount} DE ${rows.length} REGISTROS DE SEGURIDAD`;
}

function limpiarFiltros() {
    document.getElementById('buscarLog').value = '';
    document.getElementById('filtrarModulo').value = 'todos';
    document.getElementById('filtrarFecha').value = '';
    filtrarBitacora();
}
</script>
</body>
</html>
