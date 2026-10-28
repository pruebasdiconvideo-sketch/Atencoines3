<?php
declare(strict_types=1);

function startAppSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ATN_SESSID');
    session_start();
}