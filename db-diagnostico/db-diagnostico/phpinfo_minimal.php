<?php
/**
 * phpinfo_minimal.php — Información PHP simplificada
 *
 * Muestra solo la información relevante para diagnosticar el 502.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/html; charset=UTF-8');

echo '<!DOCTYPE html><html><head><title>PHP Info Diagnóstico</title>';
echo '<style>
body { font-family: Consolas, monospace; padding: 20px; max-width: 1000px; margin: auto; }
table { border-collapse: collapse; width: 100%; }
td, th { border: 1px solid #ccc; padding: 6px; text-align: left; }
th { background: #f0f0f0; }
.ok { color: green; font-weight: bold; }
.fail { color: red; font-weight: bold; }
.warn { color: orange; font-weight: bold; }
</style></head><body>';

echo '<h1>Diagnóstico PHP — 502 Bad Gateway</h1>';

echo '<h2>1. PHP</h2>';
echo '<table>';
echo '<tr><th>Setting</th><th>Valor</th></tr>';
echo '<tr><td>Versión PHP</td><td>' . PHP_VERSION . '</td></tr>';
echo '<tr><td>SAPI</td><td>' . php_sapi_name() . '</td></tr>';
echo '<tr><td>Hostname</td><td>' . gethostname() . '</td></tr>';
echo '<tr><td>Server IP</td><td>' . ($_SERVER['SERVER_ADDR'] ?? '?') . '</td></tr>';
echo '<tr><td>Server Software</td><td>' . ($_SERVER['SERVER_SOFTWARE'] ?? '?') . '</td></tr>';
echo '<tr><td>Document Root</td><td>' . ($_SERVER['DOCUMENT_ROOT'] ?? '?') . '</td></tr>';
echo '<tr><td>Script Path</td><td>' . __DIR__ . '</td></tr>';
echo '<tr><td>php.ini loaded</td><td>' . (php_ini_loaded_file() ?: 'NINGUNO') . '</td></tr>';
echo '<tr><td>display_errors</td><td>' . ini_get('display_errors') . '</td></tr>';
echo '<tr><td>error_reporting</td><td>' . ini_get('error_reporting') . '</td></tr>';
echo '<tr><td>error_log</td><td>' . (ini_get('error_log') ?: '(default)') . '</td></tr>';
echo '<tr><td>memory_limit</td><td>' . ini_get('memory_limit') . '</td></tr>';
echo '<tr><td>max_execution_time</td><td>' . ini_get('max_execution_time') . 's</td></tr>';
echo '<tr><td>upload_max_filesize</td><td>' . ini_get('upload_max_filesize') . '</td></tr>';
echo '<tr><td>post_max_size</td><td>' . ini_get('post_max_size') . '</td></tr>';
echo '</table>';

echo '<h2>2. Extensiones críticas</h2>';
echo '<table>';
echo '<tr><th>Extensión</th><th>Estado</th><th>Versión</th></tr>';

$exts = ['pdo', 'pdo_sqlsrv', 'sqlsrv', 'openssl', 'mbstring', 'json',
         'session', 'fileinfo', 'curl', 'gd', 'xml'];
foreach ($exts as $e) {
    $loaded = extension_loaded($e);
    $ver = $loaded ? (phpversion($e) ?: 'OK') : '';
    $class = $loaded ? 'ok' : 'fail';
    $status = $loaded ? 'OK' : 'FALTA';
    echo "<tr><td>$e</td><td class=\"$class\">$status</td><td>$ver</td></tr>";
}
echo '</table>';

echo '<h2>3. Drivers PDO</h2>';
echo '<table>';
foreach (PDO::getAvailableDrivers() as $drv) {
    echo "<tr><td>$drv</td></tr>";
}
echo '</table>';

echo '<h2>4. sqlsrv_get_config (si está instalado)</h2>';
if (function_exists('sqlsrv_get_config')) {
    echo '<table>';
    foreach (['WPDatatypCompatibility', 'LogSubsystems', 'LogSeverity',
             'ClientBufferMaxKBSize', 'ConnectionPooling'] as $key) {
        $val = @sqlsrv_get_config($key);
        echo "<tr><td>$key</td><td>" . ($val !== null ? $val : 'n/a') . "</td></tr>";
    }
    echo '</table>';
} else {
    echo '<p class="fail">sqlsrv NO está instalado</p>';
}

echo '<h2>5. Sesión PHP</h2>';
echo '<table>';
echo '<tr><th>Setting</th><th>Valor</th></tr>';
echo '<tr><td>session.save_handler</td><td>' . ini_get('session.save_handler') . '</td></tr>';
echo '<tr><td>session.save_path</td><td>' . (ini_get('session.save_path') ?: '(default)') . '</td></tr>';
echo '<tr><td>session.save_path real</td><td>' . (session_save_path() ?: sys_get_temp_dir()) . '</td></tr>';
$sp = session_save_path() ?: sys_get_temp_dir();
$writable = is_writable($sp);
echo '<tr><td>save_path escribible</td><td class="' . ($writable ? 'ok' : 'fail') . '">' . ($writable ? 'SÍ' : 'NO') . '</td></tr>';
echo '<tr><td>session.auto_start</td><td>' . ini_get('session.auto_start') . '</td></tr>';
echo '<tr><td>session.cookie_path</td><td>' . ini_get('session.cookie_path') . '</td></tr>';
echo '</table>';

echo '<h2>6. Permisos archivos críticos</h2>';
echo '<table>';
echo '<tr><th>Archivo</th><th>Existe</th><th>Legible</th><th>Tamaño</th></tr>';

$files = [
    'config/database.php',
    'includes/auth.php',
    'includes/session.php',
    'includes/api_response.php',
    'includes/audit.php',
    'api/index.php',
    'api/routes/auth.php',
    'login.php',
    'index.php',
    'web.config',
    '.user.ini',
];
foreach ($files as $f) {
    $full = __DIR__ . '/' . $f;
    $exists = is_file($full);
    $readable = $exists && is_readable($full);
    $size = $exists ? filesize($full) : 0;
    $c1 = $exists ? 'ok' : 'fail';
    $c2 = $readable ? 'ok' : 'fail';
    echo "<tr><td>$f</td><td class=\"$c1\">" . ($exists ? 'SÍ' : 'NO') . "</td><td class=\"$c2\">" . ($readable ? 'SÍ' : 'NO') . "</td><td>$size</td></tr>";
}
echo '</table>';

echo '<h2>7. Test conexión BD (si config/database.php existe)</h2>';
if (is_file(__DIR__ . '/config/database.php')) {
    try {
        require_once __DIR__ . '/config/database.php';
        if (class_exists('Database') && method_exists('Database', 'test')) {
            $info = Database::test();
            echo '<table>';
            echo '<tr><th>Setting</th><th>Valor</th></tr>';
            foreach ($info['config'] as $k => $v) {
                echo "<tr><td>$k</td><td>$v</td></tr>";
            }
            echo '<tr><td>success</td><td class="' . ($info['success'] ? 'ok' : 'fail') . '">' . ($info['success'] ? 'TRUE' : 'FALSE') . '</td></tr>';
            if ($info['error']) {
                echo '<tr><td>error</td><td class="fail">' . htmlspecialchars($info['error']) . '</td></tr>';
            }
            if ($info['query_test']) {
                foreach ($info['query_test'] as $k => $v) {
                    echo "<tr><td>$k</td><td>" . htmlspecialchars(substr((string)$v, 0, 200)) . '</td></tr>';
                }
            }
            echo '</table>';
        } else {
            echo '<p class="warn">database.php cargado pero no tiene método test() — versión vieja</p>';
        }
    } catch (Throwable $e) {
        echo '<p class="fail">Error cargando database.php: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
} else {
    echo '<p class="fail">config/database.php NO existe</p>';
}

echo '<h2>8. Prueba conexión directa (sin Database class)</h2>';
$dsns = [
    'A: IP + puerto 1435' => 'sqlsrv:Server=192.168.100.1,1435;Database=MonosyncERP;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=10',
    'B: IP + instancia'   => 'sqlsrv:Server=192.168.100.1\SQL2025;Database=MonosyncERP;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=10',
    'C: IP + 1433'        => 'sqlsrv:Server=192.168.100.1,1433;Database=MonosyncERP;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=10',
];
echo '<table><tr><th>DSN</th><th>Estado</th></tr>';
foreach ($dsns as $label => $dsn) {
    try {
        $pdo = new PDO($dsn, 'sa', 'WEB$VITEL1', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $row = $pdo->query('SELECT DB_NAME() AS db, @@SERVERNAME AS srv')->fetch();
        echo "<tr><td>$label</td><td class=\"ok\">OK — DB: " . $row['db'] . ' srv: ' . $row['srv'] . '</td></tr>';
    } catch (Throwable $e) {
        echo '<tr><td>' . $label . '</td><td class="fail">FAIL: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
    }
}
echo '</table>';

echo '<h2>9. Errores PHP recientes</h2>';
$logs = [
    'C:\inetpub\logs\php-errors.log',
    'C:\PHP\logs\php_error.log',
    'C:\php\error.log',
    __DIR__ . '\storage\logs\api-errors.log',
    __DIR__ . '\storage\logs\php-errors.log',
    ini_get('error_log') ?: '',
];
$found_any = false;
foreach ($logs as $log) {
    if ($log && is_file($log) && is_readable($log)) {
        $found_any = true;
        echo "<h3>$log</h3>";
        $contents = file_get_contents($log);
        $tail = substr($contents, -5000);
        $lines = explode("\n", $tail);
        $relevant = array_filter($lines, function($l) {
            return preg_match('/error|fatal|warning|exception/i', $l);
        });
        if ($relevant) {
            echo '<pre style="background:#f5f5f5;padding:10px;border-left:4px solid red;overflow-x:auto;">';
            echo htmlspecialchars(implode("\n", array_slice($relevant, -15)));
            echo '</pre>';
        } else {
            echo '<p>(sin errores en las últimas líneas)</p>';
        }
    }
}
if (!$found_any) {
    echo '<p class="warn">No se encontró ningún log de errores PHP en las ubicaciones típicas.</p>';
    echo '<p>Posibles ubicaciones adicionales:</p>';
    echo '<ul>';
    echo '<li>Event Viewer → Windows Logs → Application</li>';
    echo '<li>IIS Manager → Logging → ver ruta configurada</li>';
    echo '</ul>';
}

echo '<h2>10. phpinfo() completo</h2>';
echo '<p>Si necesitas ver todo: <a href="?full=1">mostrar phpinfo() completo</a></p>';
if (isset($_GET['full'])) {
    phpinfo();
}

echo '<p><strong>BORRA ESTE ARCHIVO DESPUÉS DE PROBAR.</strong></p>';

echo '</body></html>';
