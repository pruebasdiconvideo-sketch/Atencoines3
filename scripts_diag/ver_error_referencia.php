<?php
/**
 * scripts_diag/ver_error_referencia.php
 *
 * Busca en el log de errores (storage/logs/api-errors.log) el error específico
 * asociado a una referencia (trace_id) que devuelve el frontend.
 *
 * USO:
 *   1. Sube este archivo a la raíz del proyecto (junto a /api, /assets, etc).
 *   2. Ábrelo en el navegador:
 *        https://tu-servidor/scripts_diag/ver_error_referencia.php
 *      O bien con referencia específica:
 *        https://tu-servidor/scripts_diag/ver_error_referencia.php?ref=e8ad0f94888d4507
 *   3. Borra este archivo después de diagnosticar (no debe quedar en producción).
 *
 * REQUIERE: estar autenticado en la sesión web (comprueba que el usuario esté logueado).
 */
session_start();
$requiredFiles = [
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../includes/auth.php',
    __DIR__ . '/../includes/api_response.php',
];
$missing = [];
foreach ($requiredFiles as $f) {
    if (!is_file($f)) $missing[] = $f;
}
if ($missing) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No se encontraron archivos requeridos:\n" . implode("\n", $missing);
    exit;
}
require_once $requiredFiles[0];

// Verificar sesión autenticada
try {
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
        $userEmail = $_SESSION['user_email'] ?? $_SESSION['correo'] ?? 'desconocido';
    } else {
        // fallback: Auth::requireSession()
        $u = Auth::requireSession();
        $userEmail = $u['correo'] ?? 'desconocido';
    }
} catch (Throwable $e) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Debe iniciar sesión para usar este script.\n";
    exit;
}

$ref = trim((string)($_GET['ref'] ?? ''));
$logFile = __DIR__ . '/../storage/logs/api-errors.log';
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Diagnóstico de error - Referencia <?= htmlspecialchars($ref) ?></title>
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 20px; background: #f8f9fa; color: #1a1a1a; }
    .card { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
    h1 { margin-top: 0; color: #0d6efd; }
    h2 { margin-top: 0; color: #495057; border-bottom: 1px solid #dee2e6; padding-bottom: 8px; }
    .ref-input { font-size: 14px; padding: 8px; width: 320px; }
    .btn { background: #0d6efd; color: #fff; border: 0; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; }
    pre { background: #f8f9fa; padding: 12px; border-radius: 4px; overflow-x: auto; border-left: 4px solid #0d6efd; white-space: pre-wrap; word-break: break-word; }
    .alert { padding: 12px 16px; border-radius: 4px; margin: 12px 0; }
    .alert-warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #664d03; }
    .alert-danger  { background: #f8d7da; border: 1px solid #f1aeb5; color: #58151c; }
    .alert-success { background: #d1e7dd; border: 1px solid #a3d9a5; color: #0a3622; }
    .muted { color: #6c757d; font-size: 13px; }
    code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; font-family: Consolas, monospace; }
</style>
</head>
<body>
<div class="card">
    <h1>Diagnóstico de error</h1>
    <p class="muted">Conectado como: <strong><?= htmlspecialchars($userEmail) ?></strong></p>

    <form method="get" action="">
        <label for="ref">Referencia del error (trace_id):</label><br>
        <input class="ref-input" type="text" id="ref" name="ref" value="<?= htmlspecialchars($ref) ?>" placeholder="ej: e8ad0f94888d4507">
        <button class="btn" type="submit">Buscar</button>
    </form>
</div>

<?php
if ($ref === '') {
    echo '<div class="card"><h2>Últimos 20 errores</h2>';
} else {
    echo '<div class="card"><h2>Buscando referencia: ' . htmlspecialchars($ref) . '</h2>';
}

if (!is_file($logFile)) {
    echo '<div class="alert alert-warning">No se encontró el archivo de log en: ' . htmlspecialchars($logFile) . '<br>
          Esto significa que <em>no se ha producido ningún error 500</em> desde que se instaló el sistema,
          o que la carpeta <code>storage/logs/</code> no tiene permisos de escritura.</div>';
    echo '</div>';
    exit;
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$lines) {
    echo '<div class="alert alert-warning">El archivo de log está vacío.</div>';
    echo '</div>';
    exit;
}

// Filtrar por referencia si se especificó
$matched = [];
foreach ($lines as $line) {
    if ($ref !== '' && stripos($line, $ref) === false) continue;
    $matched[] = $line;
}

if (empty($matched)) {
    echo '<div class="alert alert-danger">No se encontró ningún error con la referencia <code>' . htmlspecialchars($ref) . '</code>.<br>
          Posibles causas:
          <ul>
            <li>El error fue anterior a la rotación de logs (revisa logs anteriores en la misma carpeta).</li>
            <li>La referencia es incorrecta (cópiala tal cual aparece en el toast).</li>
            <li>El error fue cacheado por el navegador (prueba con Ctrl+F5).</li>
          </ul>
          Mostrando los últimos 20 errores en su lugar:</div>';
    $matched = array_slice($lines, -20);
} else {
    echo '<div class="alert alert-success">Se encontraron ' . count($matched) . ' coincidencias:</div>';
}

echo '<pre>';
foreach ($matched as $line) {
    // Resaltar la línea que coincide con la referencia
    if ($ref !== '' && stripos($line, $ref) !== false) {
        echo '<strong style="color:#dc3545">' . htmlspecialchars($line) . '</strong>' . "\n";
    } else {
        echo htmlspecialchars($line) . "\n";
    }
}
echo '</pre>';
echo '</div>';
?>

<div class="card">
    <h2>Cómo interpretar el log</h2>
    <p>Cada línea del log tiene este formato:</p>
    <pre>[2026-10-19T15:30:00+00:00][API][e8ad0f94888d4507] PUT empresas/5: El RUC ya existe en otra empresa. [PDOException in empresas.php:235]</pre>
    <p>Los campos son:</p>
    <ul>
        <li><strong>Fecha ISO</strong>: cuándo ocurrió el error.</li>
        <li><strong>[API]</strong>: etiqueta del log.</li>
        <li><strong>[e8ad0f94888d4507]</strong>: la referencia (trace_id) que viste en el frontend.</li>
        <li><strong>PUT empresas/5</strong>: método HTTP y ruta que se estaba procesando.</li>
        <li><strong>El RUC ya existe en otra empresa.</strong>: el mensaje REAL de la excepción.</li>
        <li><strong>[PDOException in empresas.php:235]</strong>: tipo de excepción, archivo y línea.</li>
    </ul>
    <p class="muted">Después de diagnosticar, <strong>borra este archivo</strong> (scripts_diag/ver_error_referencia.php) para evitar exposición de información sensible en producción.</p>
</div>

</body>
</html>
