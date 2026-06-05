<?php
require "forms/conexion.php";
$res = $conn->query("SELECT * FROM usuarios");
while($row = $res->fetch_assoc()) {
    echo "ID: " . $row['id'] . " - User: " . $row['usuario'] . " - Rol: " . $row['rol_id'] . "\n";
}
?>
