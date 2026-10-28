<?php
/**
 * logout.php - Cierre de sesión robusto del lado del servidor.
 *
 * Por qué existe este archivo:
 *   - El flujo JS anterior (fetch + window.location.href) tenía una race condition:
 *     el navegador navegaba a /login.php ANTES de que el fetch de logout llegara
 *     al servidor, así que la sesión PHP quedaba viva y /login.php redirigía de
 *     vuelta al dashboard.
 *   - Además, /api/v1/auth/logout estaba marcado como endpoint público y nunca
 *     se llamaba startAppSession() antes de session_destroy() -> el destroy
 *     no se ejecutaba porque session_status() era NONE.
 *
 * Esta página hace el cierre completo del lado del servidor:
 *   1. Inicia la sesión (para poder acceder a $_SESSION y destruirla).
 *   2. Registra auditoría (si el usuario estaba logueado).
 *   3. Limpia el array $_SESSION.
 *   4. Borra la cookie de sesión del navegador (setcookie con tiempo pasado).
 *   5. Llama session_destroy() para eliminar el archivo de sesión del disco.
 *   6. Envía cabeceras anti-caché para que el navegador no sirva una versión
 *      cacheada del dashboard al presionar "Atrás".
 *   7. Redirige a /login.php?logout=1 con un cache-buster.
 *
 * Cómo se invoca:
 *   - El botón "Salir" en index.php ahora es un <a href="/logout.php"> simple.
 *   - No se necesita JS para logout — el navegador hace la navegación HTTP
 *     normal, garantizando que el servidor complete el destroy ANTES de la
 *     redirección a /login.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit.php';

// 1) Iniciar la sesión ANTES de cualquier output.
//    Esto es crítico: si no se inicia, session_status() es NONE y
//    session_destroy() no tiene efecto.
startAppSession();

// 2) Registrar auditoría de logout (si hay usuario logueado).
if (!empty($_SESSION['user']['id_persona'])) {
    try {
        Audit::log(
            'AUTH',
            'LOGOUT',
            'Cierre de sesión',
            'persona',
            (string)($_SESSION['user']['id_persona'] ?? ''),
            null,
            'EXITO',
            [
                'id_persona' => $_SESSION['user']['id_persona'] ?? 0,
                'correo'     => $_SESSION['user']['correo']     ?? '',
            ]
        );
    } catch (Throwable $e) {
        // La auditoría no debe romper el logout.
        error_log('[logout.php] Audit log falló: ' . $e->getMessage());
    }
}

// 3) Vaciar el array $_SESSION (libera la memoria y previene re-uso).
$_SESSION = [];

// 4) Borrar la cookie de sesión del navegador.
//    Esto es CRÍTICO: si solo hacemos session_destroy(), el archivo de sesión
//    se borra del disco pero el navegador sigue enviando la cookie ATN_SESSID.
//    En la siguiente petición, PHP crea un nuevo archivo de sesión vacío con
//    ese ID (que sigue siendo válido). Al borrar la cookie con tiempo pasado,
//    el navegador deja de enviarla y la sesión se cierra de verdad.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),     // 'ATN_SESSID'
        '',                 // valor vacío
        time() - 42000,     // tiempo en el pasado -> el navegador la borra
        $params['path']     ?? '/',
        $params['domain']   ?? '',
        $params['secure']   ?? false,
        $params['httponly'] ?? true
    );
}

// 5) Si PHP configuró un cookie de sesión con SameSite, también lo limpiamos.
//    session_get_cookie_params() no incluye SameSite en versiones antiguas de
//    PHP, así que lo intentamos de forma defensiva.
if (PHP_VERSION_ID >= 70300) {
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path']     ?? '/',
            'domain'   => $params['domain']   ?? '',
            'secure'   => $params['secure']   ?? false,
            'httponly' => $params['httponly'] ?? true,
            'samesite' => 'Lax',
        ]
    );
}

// 6) Destruir la sesión del lado del servidor (borra el archivo en disco).
session_destroy();

// 7) Cabeceras anti-caché para que el navegador NO sirva una versión cacheada
//    del dashboard al presionar "Atrás" después del logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Clear-Site-Data: "cache", "cookies"');  // Limpia caché y cookies del sitio

// 8) Redirigir al login con un cache-buster (para que el navegador no use
//    una versión cacheada de login.php).
//    Si el logout se disparó por inactividad (viene de app.js), mostrar
//    mensaje "Su sesión ha expirado" en login.php.
$reason = $_GET['reason'] ?? '';
$loginUrl = '/login.php?'
    . ($reason === 'inactivity' ? 'error=expired&' : 'logout=1&')
    . 't=' . time();
header('Location: ' . $loginUrl);
exit;
