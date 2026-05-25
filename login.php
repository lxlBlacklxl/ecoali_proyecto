<?php
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>EcoAli - Iniciar Sesión</title>

  <link rel="stylesheet" href="assets/css/globals.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Outfit:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  
  <style>
    /* Estilos Premium Re-diseñados EcoAli Login */
    :root {
      --bg-organic: #fff8f3;
      --primary: #ff8a00;
      --primary-hover: #e07b00;
      --secondary: #176a21;
      --secondary-dark: #0f4a15;
      --text-dark: #3a2200;
      --text-medium: #70502b;
      --glass-bg: rgba(255, 255, 255, 0.88);
      --glass-border: rgba(213, 164, 112, 0.24);
      --shadow-premium: 0 30px 60px rgba(70, 40, 0, 0.08);
      --transition-fast: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Manrope', sans-serif;
      background-color: var(--bg-organic);
      color: var(--text-dark);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow-x: hidden;
    }

    /* Ambient Background Glows */
    .ambient-glows {
      position: absolute;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      overflow: hidden;
    }

    .glow-1 {
      position: absolute;
      top: -10%;
      left: -10%;
      width: 50%;
      height: 60%;
      background: radial-gradient(circle, rgba(255, 138, 0, 0.14) 0%, transparent 70%);
      animation: rotateGlow 25s infinite linear;
    }

    .glow-2 {
      position: absolute;
      bottom: -10%;
      right: -10%;
      width: 55%;
      height: 60%;
      background: radial-gradient(circle, rgba(23, 106, 33, 0.12) 0%, transparent 70%);
      animation: rotateGlow 30s infinite linear reverse;
    }

    @keyframes rotateGlow {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    /* Main Split Wrapper */
    .login-wrapper {
      display: flex;
      width: 90%;
      max-width: 1100px;
      min-height: 680px;
      background: var(--glass-bg);
      backdrop-filter: blur(20px);
      border: 1px solid var(--glass-border);
      border-radius: 36px;
      overflow: hidden;
      box-shadow: 0 40px 100px rgba(0, 0, 0, 0.12);
      position: relative;
      z-index: 1;
      animation: zoomIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes zoomIn {
      from { opacity: 0; transform: scale(0.96) translateY(20px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }

    /* Left Side: Visual Showcase */
    .showcase-side {
      flex: 1.1;
      background: linear-gradient(135deg, var(--secondary-dark), #176a21);
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 60px;
      color: white;
      overflow: hidden;
    }

    .showcase-side::after {
      content: "";
      position: absolute;
      inset: 0;
      background-image: radial-gradient(circle at 80% 20%, rgba(255, 138, 0, 0.18) 0%, transparent 50%);
      pointer-events: none;
    }

    .showcase-brand {
      font-family: 'Outfit', sans-serif;
      font-size: 28px;
      font-weight: 800;
      letter-spacing: -1px;
      display: flex;
      align-items: center;
      gap: 10px;
      animation: slideDown 0.8s ease;
    }

    .showcase-brand span {
      background: var(--primary);
      color: white;
      width: 40px;
      height: 40px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      font-size: 20px;
      box-shadow: 0 8px 20px rgba(255, 138, 0, 0.3);
    }

    .showcase-content {
      position: relative;
      z-index: 2;
      margin: auto 0;
    }

    .showcase-content h2 {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 42px;
      font-weight: 800;
      line-height: 1.2;
      margin-bottom: 20px;
      letter-spacing: -1px;
    }

    .showcase-content p {
      font-size: 15px;
      color: rgba(255, 255, 255, 0.8);
      line-height: 1.6;
      max-width: 420px;
    }

    .showcase-footer {
      font-size: 12px;
      color: rgba(255, 255, 255, 0.5);
      font-weight: 500;
      letter-spacing: 0.5px;
    }

    /* Abstract Decorative Elements on Left */
    .floating-egg {
      position: absolute;
      width: 140px;
      height: 180px;
      background: radial-gradient(circle at 30% 30%, #fff 0%, #ffedd5 60%, #ffd0a0 100%);
      border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
      right: -20px;
      bottom: 60px;
      opacity: 0.25;
      transform: rotate(25deg);
      filter: blur(1px);
      animation: floatEgg 6s ease-in-out infinite alternate;
    }

    @keyframes floatEgg {
      from { transform: rotate(25deg) translateY(0); }
      to { transform: rotate(20deg) translateY(-15px); }
    }

    /* Right Side: Form */
    .form-side {
      flex: 1;
      background: white;
      padding: 60px 50px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .form-header {
      margin-bottom: 35px;
    }

    .form-header h3 {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 26px;
      font-weight: 800;
      color: var(--text-dark);
      letter-spacing: -0.5px;
    }

    .form-header p {
      font-size: 13px;
      color: var(--text-medium);
      margin-top: 6px;
      font-weight: 500;
    }

    /* Form Inputs */
    .form-group {
      margin-bottom: 22px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .form-group label {
      font-size: 11px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding-left: 4px;
    }

    .input-container {
      position: relative;
    }

    .input-container input {
      width: 100%;
      height: 52px;
      border-radius: 16px;
      border: 1.5px solid rgba(213, 164, 112, 0.32);
      background: #fafafa;
      padding: 0 20px 0 46px;
      font-size: 14px;
      font-weight: 600;
      color: var(--text-dark);
      outline: none;
      transition: var(--transition-fast);
      font-family: inherit;
    }

    .input-container input:focus {
      border-color: var(--primary);
      background: white;
      box-shadow: 0 0 0 4px rgba(255, 138, 0, 0.1);
    }

    .input-icon {
      position: absolute;
      left: 18px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 16px;
      color: var(--text-medium);
      opacity: 0.7;
    }

    .form-options {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: -6px;
      margin-bottom: 26px;
      font-size: 12px;
    }

    .forgot-link {
      color: var(--primary);
      text-decoration: none;
      font-weight: 700;
      transition: var(--transition-fast);
    }

    .forgot-link:hover {
      color: var(--primary-hover);
    }

    /* Primary Submit Button */
    .btn-submit {
      width: 100%;
      height: 52px;
      border-radius: 16px;
      border: none;
      background: linear-gradient(135deg, var(--primary), var(--primary-hover));
      color: white;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 15px;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 10px 25px rgba(255, 138, 0, 0.22);
      transition: var(--transition-fast);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }

    .btn-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 14px 30px rgba(255, 138, 0, 0.32);
    }

    /* Divider */
    .divider {
      display: flex;
      align-items: center;
      gap: 14px;
      margin: 28px 0;
    }

    .divider-line {
      flex: 1;
      height: 1px;
      background: rgba(213, 164, 112, 0.2);
    }

    .divider-text {
      font-size: 10px;
      font-weight: 800;
      color: var(--text-medium);
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    /* Google Button Wrapper */
    .btn-google {
      width: 100%;
      height: 48px;
      border-radius: 16px;
      border: 1.5px solid rgba(213, 164, 112, 0.32);
      background: white;
      color: var(--text-dark);
      font-weight: 700;
      font-size: 13px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      box-shadow: 0 6px 15px rgba(70,40,0,0.02);
      transition: var(--transition-fast);
    }

    .btn-google:hover {
      background: #fafafa;
      border-color: rgba(213, 164, 112, 0.6);
      transform: translateY(-1px);
    }

    .btn-google img {
      width: 18px;
      height: 18px;
    }

    /* Register Prompt */
    .register-prompt {
      margin-top: 30px;
      text-align: center;
      font-size: 13px;
      color: var(--text-medium);
    }

    .register-prompt a {
      color: var(--secondary);
      text-decoration: none;
      font-weight: 800;
      transition: var(--transition-fast);
      margin-left: 4px;
    }

    .register-prompt a:hover {
      color: var(--secondary-dark);
      text-decoration: underline;
    }

    /* Message Bubbles */
    .mensaje-login {
      background: rgba(176, 37, 0, 0.08);
      border: 1.5px solid rgba(176, 37, 0, 0.18);
      color: #b02500;
      padding: 14px 18px;
      border-radius: 16px;
      font-size: 13px;
      font-weight: 700;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      gap: 10px;
      animation: shake 0.5s ease;
    }

    .mensaje-login.exito {
      background: rgba(23, 106, 33, 0.08);
      border-color: rgba(23, 106, 33, 0.18);
      color: var(--secondary);
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      20%, 60% { transform: translateX(-6px); }
      40%, 80% { transform: translateX(6px); }
    }

    /* Responsive */
    @media (max-width: 900px) {
      .login-wrapper {
        min-height: 580px;
      }
      .showcase-side {
        padding: 40px;
      }
      .form-side {
        padding: 40px 30px;
      }
    }

    @media (max-width: 768px) {
      .login-wrapper {
        flex-direction: column;
        max-width: 440px;
        min-height: auto;
      }

      .showcase-side {
        display: none;
      }

      .form-side {
        padding: 45px 30px;
      }
    }
  </style>
</head>
<body>

<div class="ambient-glows">
  <div class="glow-1"></div>
  <div class="glow-2"></div>
</div>

<div class="login-wrapper">

  <!-- Left Showcase Side -->
  <section class="showcase-side">
    <div class="showcase-brand">
      <span>🥚</span> EcoAli
    </div>

    <div class="showcase-content">
      <h2>Vitalidad Orgánica, Sabor Auténtico.</h2>
      <p>
        Accede a la cadena logística limpia y transparente de huevos frescos. Disfruta de una trazabilidad garantizada del campo directo a tu mesa.
      </p>
    </div>

    <div class="showcase-footer">
      © 2026 ECOALI. DEL CAMPO CON CONFIANZA.
    </div>

    <div class="floating-egg"></div>
  </section>

  <!-- Right Form Side -->
  <main class="form-side">
    <header class="form-header">
      <h3>Bienvenido de Nuevo</h3>
      <p>Inicia sesión para gestionar tus pedidos y suministros.</p>
    </header>

    <!-- Error/Success popups -->
    <?php if (isset($_SESSION["mensaje"])): ?>
      <div class="mensaje-login">
        <span>⚠</span>
        <?php
        echo $_SESSION["mensaje"];
        unset($_SESSION["mensaje"]);
        ?>
      </div>
    <?php endif; ?>

    <?php if (isset($_SESSION["mensaje_login"])): ?>
      <div class="mensaje-login exito">
        <span>✓</span>
        <?php
        echo $_SESSION["mensaje_login"];
        unset($_SESSION["mensaje_login"]);
        ?>
      </div>
    <?php endif; ?>

    <form action="forms/procesar_login.php" method="POST">

      <div class="form-group">
        <label for="input-usuario">Usuario</label>
        <div class="input-container">
          <span class="input-icon">👤</span>
          <input
            id="input-usuario"
            type="text"
            name="usuario"
            placeholder="Nombre de usuario"
            required
          />
        </div>
      </div>

      <div class="form-group">
        <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
          <label for="input-password">Contraseña</label>
          <a href="#" class="forgot-link" style="font-size:11px;">¿Olvidaste tu contraseña?</a>
        </div>
        <div class="input-container">
          <span class="input-icon">🔒</span>
          <input
            id="input-password"
            type="password"
            name="password"
            placeholder="••••••••"
            required
          />
        </div>
      </div>

      <button type="submit" class="btn-submit">
        Iniciar Sesión ➜
      </button>

    </form>

    <div class="divider">
      <div class="divider-line"></div>
      <span class="divider-text">O continúa con</span>
      <div class="divider-line"></div>
    </div>

    <div style="width: 100%; display: flex; justify-content: center; overflow: hidden; margin-top: 5px;">
      <div id="g_id_onload"
           data-client_id="137822436644-08aukfg18do9q93idftfe52769fq8lk7.apps.googleusercontent.com"
           data-context="signin"
           data-ux_mode="popup"
           data-callback="manejarAutenticacionGoogle"
           data-auto_prompt="false">
      </div>
      <div class="g_id_signin"
           data-type="standard"
           data-shape="rectangular"
           data-theme="outline"
           data-text="signin_with"
           data-size="large"
           data-logo_alignment="left"
           data-width="370">
      </div>
    </div>

    <script>
      function manejarAutenticacionGoogle(response) {
        // Generar un formulario temporal para enviar las credenciales encriptadas de forma segura por POST
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'forms/google_login.php';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'credential';
        input.value = response.credential;

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
      }
    </script>

    <p class="register-prompt">
      ¿No tienes una cuenta aún?
      <a href="register.php">Regístrate ahora</a>
    </p>
  </main>

</div>

</body>
</html>