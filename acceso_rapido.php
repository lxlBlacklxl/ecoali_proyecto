<?php
session_start();

if (isset($_GET['rol'])) {
    $rol = (int)$_GET['rol'];
    $_SESSION['usuario_id'] = 999; // ID de prueba temporaria
    $_SESSION['rol_id'] = $rol;
    
    switch ($rol) {
        case 1:
            $_SESSION['nombre'] = "Admin Demo";
            header("Location: dashboard_admin.php");
            exit;
        case 2:
            $_SESSION['nombre'] = "Cliente Demo";
            header("Location: dashboard_cliente.php");
            exit;
        case 3:
            $_SESSION['nombre'] = "Proveedor Demo";
            header("Location: dashboard_proveedor.php");
            exit;
        case 4:
            $_SESSION['nombre'] = "Repartidor Demo";
            header("Location: dashboard_repartidor.php");
            exit;
        default:
            $_SESSION['nombre'] = "Usuario Demo";
            header("Location: login.php");
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Rápido - Ecoali</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Manrope', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: rgba(255, 255, 255, 0.9);
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.15);
            backdrop-filter: blur(8px);
            max-width: 400px;
            width: 100%;
            text-align: center;
        }
        h1 {
            color: #2d3748;
            font-size: 24px;
            margin-bottom: 8px;
        }
        p {
            color: #718096;
            margin-bottom: 24px;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 12px;
            margin: 12px 0;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .btn-admin { background: #4a5568; color: white; }
        .btn-cliente { background: #48bb78; color: white; }
        .btn-proveedor { background: #ecc94b; color: #2d3748; }
        .btn-repartidor { background: #4299e1; color: white; }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            filter: brightness(1.05);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Acceso Temporal (Bypass)</h1>
        <p>Selecciona un rol para simular el inicio de sesión y acceder directamente a su panel:</p>
        <a href="?rol=1" class="btn btn-admin">Entrar como Administrador</a>
        <a href="?rol=2" class="btn btn-cliente">Entrar como Cliente</a>
        <a href="?rol=3" class="btn btn-proveedor">Entrar como Proveedor</a>
        <a href="?rol=4" class="btn btn-repartidor">Entrar como Repartidor</a>
    </div>
</body>
</html>
