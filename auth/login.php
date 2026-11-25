<?php
session_start();

if (isset($_SESSION['id_usuario'])) {
    header('Location: ../empresa/dashboard.php');
    exit;
}

$error = isset($_GET['error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . '/../css/estilos.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-body">
    <!-- Fondos de Iluminación Ambiental Ejecutiva -->
    <div class="auth-ambient-glow glow-1"></div>
    <div class="auth-ambient-glow glow-2"></div>
    <div class="auth-ambient-glow glow-3"></div>

    <div class="login-wrapper">
        <main class="login-card">
            <!-- Encabezado Institucional -->
            <header class="login-header">
                <div class="brand-panel-login">
                    <div class="brand-mark-login">
                        <span>CEA</span>
                    </div>
                    <div class="brand-text">
                        <span class="brand-eyebrow">GROUP_CEA</span>
                        <h1 class="brand-title">Portal Institucional</h1>
                    </div>
                </div>
                <p class="login-subtitle">
                    Sistema Integral de Gestión Jurídica, Tesis Académica y Operaciones Empresariales.
                </p>
            </header>

            <!-- Alerta de Error -->
            <?php if ($error): ?>
                <div class="login-alert" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <div>
                        <strong>Acceso denegado</strong>
                        <span>Verifica tus credenciales e inténtalo de nuevo.</span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Formulario de Autenticación -->
            <form action="validar_login.php" method="POST" class="login-form" id="loginForm" autocomplete="on">
                <div class="form-field-group">
                    <label for="usuario" class="field-label">Usuario o Correo Institucional</label>
                    <div class="field-input-wrap">
                        <svg class="field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <input 
                            type="text" 
                            id="usuario" 
                            name="usuario" 
                            class="login-input" 
                            placeholder="Ingresa tu usuario o correo"
                            required 
                            autocomplete="username"
                            autofocus
                        >
                    </div>
                </div>

                <div class="form-field-group">
                    <div class="field-label-row">
                        <label for="password" class="field-label">Contraseña de Seguridad</label>
                    </div>
                    <div class="field-input-wrap">
                        <svg class="field-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="login-input" 
                            placeholder="Ingresa tu clave de acceso" 
                            required 
                            autocomplete="current-password"
                        >
                        <button type="button" class="btn-toggle-password" id="btnTogglePassword" aria-label="Mostrar contraseña" title="Mostrar/Ocultar contraseña">
                            <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg class="eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" hidden>
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember-checkbox">
                        <input type="checkbox" name="recordar" id="recordar" checked>
                        <span>Recordar en este equipo</span>
                    </label>
                </div>

                <button type="submit" class="btn-login-submit" id="btnSubmit">
                    <span>Ingresar al Sistema</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </form>

            <!-- Pie de Seguridad Institucional -->
            <footer class="login-footer-security">
                <div class="security-seal">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <span>Conexión cifrada institucional y sesión autenticada</span>
                </div>
                <small class="login-copyright">
                    &copy; <?= date('Y') ?> GROUP_CEA. Todos los derechos reservados.
                </small>
            </footer>
        </main>
    </div>

    <!-- Script de Interactividad del Login -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const userInput = document.getElementById('usuario');
        const passInput = document.getElementById('password');
        const toggleBtn = document.getElementById('btnTogglePassword');
        const eyeOpen = toggleBtn.querySelector('.eye-open');
        const eyeClosed = toggleBtn.querySelector('.eye-closed');
        const form = document.getElementById('loginForm');
        const submitBtn = document.getElementById('btnSubmit');

        // Toggle Mostrar/Ocultar contraseña
        toggleBtn.addEventListener('click', () => {
            const isPassword = passInput.getAttribute('type') === 'password';
            passInput.setAttribute('type', isPassword ? 'text' : 'password');
            eyeOpen.hidden = isPassword;
            eyeClosed.hidden = !isPassword;
            passInput.focus();
        });

        // Indicador de carga en submit
        form.addEventListener('submit', () => {
            submitBtn.disabled = true;
            submitBtn.classList.add('btn-loading');
            submitBtn.innerHTML = `
                <span class="btn-spinner"></span>
                <span>Autenticando...</span>
            `;
        });
    });
    </script>
</body>
</html>
