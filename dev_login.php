<?php
session_start();
require "forms/conexion.php";

$rol = isset($_GET['rol']) ? (int)$_GET['rol'] : 1;

if ($rol === 1) { // Admin
    $_SESSION["usuario_id"] = 7;
    $_SESSION["usuario"] = "JorgeL";
    $_SESSION["nombre"] = "Jorge";
    $_SESSION["apellido"] = "L";
    $_SESSION["rol_id"] = 1;
    header("Location: dashboard_admin.php");
} elseif ($rol === 2) { // Cliente
    $_SESSION["usuario_id"] = 11;
    $_SESSION["usuario"] = "jorgeluismarmolejovazquez799";
    $_SESSION["nombre"] = "Jorge";
    $_SESSION["apellido"] = "Luis";
    $_SESSION["rol_id"] = 2;
    header("Location: dashboard_cliente.php");
} elseif ($rol === 3) { // Vendedor/Proveedor
    $_SESSION["usuario_id"] = 3;
    $_SESSION["usuario"] = "Diego";
    $_SESSION["nombre"] = "Diego";
    $_SESSION["apellido"] = "M";
    $_SESSION["rol_id"] = 3;
    header("Location: dashboard_proveedor.php");
} elseif ($rol === 4) { // Repartidor
    $_SESSION["usuario_id"] = 2;
    $_SESSION["usuario"] = "Ingrid";
    $_SESSION["nombre"] = "Ingrid";
    $_SESSION["apellido"] = "P";
    $_SESSION["rol_id"] = 4;
    header("Location: dashboard_repartidor.php");
}
exit;
