<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
startAppSession();
if (!empty($_SESSION['user']['id_persona'])) {
    // Si todavía hay sesión activa, el usuario NO debería estar aquí.
    // Esto puede pasar si el logout falló o si alguien navega manualmente a
    // /login.php estando logueado. Redirigimos al dashboard.
    header('Location: /');
    exit;
}
$errorMsg = $_GET['error'] ?? '';
$logoutOk = isset($_GET['logout']);
// Evitar caché del navegador — crítico para que después del logout el botón
// "Atrás" no sirva una versión cacheada del dashboard.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/assets/img/icon-192.png">
    <title>Iniciar Sesion - Atenciones</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?: time() ?>">
</head>
<body>
    <main class="login-page">
        <div class="login-card">
            <h1>Atenciones</h1>
            <p>Sistema de gestion de tickets - MonosyncERP</p>

            <?php if ($errorMsg === 'expired'): ?>
                <div style="background:#fbe5e3;color:#955650;padding:10px;border-radius:4px;margin-bottom:16px;font-size:14px">
                    Su sesion ha expirado. Ingrese nuevamente.
                </div>
            <?php elseif ($logoutOk): ?>
                <div style="background:#e3f3e5;color:#3c7148;padding:10px;border-radius:4px;margin-bottom:16px;font-size:14px">
                    Sesion cerrada correctamente. Puede iniciar sesion con otra cuenta.
                </div>
            <?php endif; ?>

            <form id="loginForm">
                <div class="form-group">
                    <label class="form-label" for="correo">Correo electronico <span class="req">*</span></label>
                    <input type="email" id="correo" name="correo" class="form-input" required
                           autocomplete="email" placeholder="admin@soporte.com">
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Contrasena <span class="req">*</span></label>
                    <input type="password" id="password" name="password" class="form-input" required
                           autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary w-full" style="justify-content:center">
                    Ingresar
                </button>
            </form>

            <p class="text-muted text-sm mt-4 text-center">
                Sistema seguro. Solo personal autorizado.
            </p>
        </div>
    </main>

    <div id="toast-container"></div>
    <!-- NO cargar app.js aquí - causa demora y errores en la página de login -->
    <script>
        // Helper mínimo de toast (sin cargar todo app.js)
        window.App = window.App || { toast: function(msg, type, duration) {
            var container = document.getElementById('toast-container');
            if (!container) return;
            var t = document.createElement('div');
            t.className = 'toast toast-' + (type || 'info');
            t.textContent = msg;
            container.appendChild(t);
            setTimeout(function() {
                t.style.opacity = '0';
                setTimeout(function() { t.remove(); }, 200);
            }, duration || 4000);
        }};

        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const correo = document.getElementById('correo').value;
            const password = document.getElementById('password').value;
            const btn = e.target.querySelector('button[type="submit"]');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<div class="spinner"></div> Ingresando...';
            try {
                const r = await fetch('/api/v1/auth/login', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ correo, password })
                });
                const j = await r.json();
                if (j.ok) {
                    // Redirigir al sistema principal con cache-buster para evitar caché
                    window.location.href = '/?t=' + Date.now();
                } else {
                    App.toast(j.message || 'Credenciales invalidas', 'error');
                    btn.disabled = false;
                    btn.innerHTML = orig;
                }
            } catch (err) {
                App.toast('Error de conexion', 'error');
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    </script>
</body>
</html>
