<?php
session_start();
header("Content-Type: application/json");
echo json_encode($_SESSION, JSON_PRETTY_PRINT);
?>
