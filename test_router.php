<?php
/**
 * test_router.php v5 - Diagnostico de routing SIN BD
 *
 * Este script NO llama al router completo (que requiere BD).
 * Solo muestra las variables de servidor disponibles.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Router Diag v5</title>";
echo "<style>
body{font-family:Consolas,monospace;padding:20px;background:#f5f5f5}
.box{background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);max-width:900px;margin:0 auto}
table{border-collapse:collapse;width:100%;font-size:13px;margin:8px 0}
td{padding:6px 10px;border:1px solid #ddd}
td:first-child{background:#e9ecef;font-weight:bold;width:50%}
code{background:#e9ecef;padding:2px 6px;border-radius:3px}
pre{background:#f1f3f5;padding:10px;border-radius:4px;overflow:auto;font-size:12px}
h1{color:#304753}h2{color:#2590c6;border-bottom:1px solid #dee2e6;padding-bottom:6px;margin-top:24px}
.ok{color:#155724;background:#d4edda;padding:10px;border-radius:4px;margin:10px 0}
.err{color:#721c24;background:#f8d7da;padding:10px;border-radius:4px;margin:10px 0}
.warn{color:#856404;background:#fff3cd;padding:10px;border-radius:4px;margin:10px 0}
</style></head><body><div class='box'>";

echo "<h1>Diagnostico de Routing v5</h1>";
echo "<p>URL solicitada: <code>" . htmlspecialchars($_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '')) . "</code></p>";

echo "<h2>1. TODAS las variables de servidor</h2>";
echo "<table>";
$allVars = $_SERVER;
ksort($allVars);
foreach ($allVars as $k => $v) {
    if (is_array($v)) $v = json_encode($v);
    if (strlen($v) > 200) $v = substr($v, 0, 200) . '...';
    echo "<tr><td>$k</td><td><code>" . htmlspecialchars($v) . "</code></td></tr>";
}
echo "</table>";

echo "<h2>2. Variables que el router usa para detectar la URL</h2>";
echo "<table>";
$routerVars = [
    'HTTP_X_REWRITE_URL',     // URL Rewrite Module
    'HTTP_X_ORIGINAL_URL',    // URL Rewrite alternativo
    '_path',                   // Query string manual
    'REQUEST_URI',             // Estándar CGI (rara vez en IIS FastCGI)
    'PATH_INFO',               // PHP path info
    'ORIG_PATH_INFO',          // IIS ExecuteURL
    'SCRIPT_NAME',             // Siempre presente
    'QUERY_STRING',            // Siempre presente
];
foreach ($routerVars as $v) {
    if ($v === '_path') {
        $val = $_GET['_path'] ?? '(vacio)';
    } else {
        $val = $_SERVER[$v] ?? '(vacio)';
    }
    $color = ($val === '(vacio)') ? 'red' : 'green';
    echo "<tr><td>$v</td><td style='color:$color'><code>" . htmlspecialchars($val) . "</code></td></tr>";
}
echo "</table>";

echo "<h2>3. Test de endpoints (probar cada uno)</h2>";
echo "<ul>";
echo "<li><a href='/ping.php' target='_blank'>/ping.php</a> - debe funcionar (test mínimo PHP)</li>";
echo "<li><a href='/ping.html' target='_blank'>/ping.html</a> - debe funcionar (test estático)</li>";
echo "<li><a href='/api/v1/auth/vapid-public-key' target='_blank'>/api/v1/auth/vapid-public-key</a> - debe dar JSON ok:true</li>";
echo "<li><a href='/api/index.php?_path=auth/vapid-public-key' target='_blank'>/api/index.php?_path=auth/vapid-public-key</a> - formato manual</li>";
echo "<li><a href='/api/index.php/auth/vapid-public-key' target='_blank'>/api/index.php/auth/vapid-public-key</a> - formato PATH_INFO</li>";
echo "</ul>";

echo "<h2>4. Estado de archivos criticos</h2>";
$projectRoot = dirname(__FILE__);
$files = [
    'web.config'              => $projectRoot . '/web.config',
    'api/index.php'           => $projectRoot . '/api/index.php',
    'api/routes/auth.php'     => $projectRoot . '/api/routes/auth.php',
    'config/database.php'     => $projectRoot . '/config/database.php',
    'includes/auth.php'        => $projectRoot . '/includes/auth.php',
    'includes/api_response.php'=> $projectRoot . '/includes/api_response.php',
];

echo "<table>";
foreach ($files as $name => $path) {
    $exists = is_file($path);
    $status = $exists ? '✓ existe' : '✗ NO existe';
    $color = $exists ? 'green' : 'red';
    echo "<tr><td>$name</td><td style='color:$color'>$status</td></tr>";
}
echo "</table>";

echo "<h2>5. Test de BD (separado)</h2>";
echo "<p>Ahora probemos la BD directamente sin pasar por Database class:</p>";

$DB_HOST = 'DESKTOP-FOOD01U\\SQL2025';
$DB_PORT = 1435;
$DB_NAME = 'MonosyncERP';
$DB_USER = 'sa';
$DB_PASS = '123';

$dsn = "sqlsrv:Server=$DB_HOST,$DB_PORT;Database=$DB_NAME;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=10";

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "<div class='ok'>✓ Conexion BD exitosa</div>";

    $r = $pdo->query("SELECT @@VERSION AS v, DB_NAME() AS db, SUSER_NAME() AS u")->fetch();
    echo "<p>BD: <code>" . htmlspecialchars($r['db']) . "</code> | Usuario: <code>" . htmlspecialchars($r['u']) . "</code></p>";

    // Verificar que la persona admin existe
    $stmt = $pdo->prepare("SELECT id_persona, correo, contrasena, estado, bloqueado, activo FROM persona WHERE correo = ?");
    $stmt->execute(['admin@soporte.com']);
    $user = $stmt->fetch();

    if ($user) {
        echo "<div class='ok'>✓ Usuario admin@soporte.com encontrado (ID: " . $user['id_persona'] . ")</div>";
        echo "<p>Estado: " . $user['estado'] . " | Bloqueado: " . $user['bloqueado'] . " | Activo: " . $user['activo'] . "</p>";
        echo "<p>Hash actual (primeros 30): <code>" . htmlspecialchars(substr($user['contrasena'], 0, 30)) . "...</code></p>";

        // Verificar si el hash es bcrypt
        if (password_verify('admin123', $user['contrasena'])) {
            echo "<div class='ok'>✓ password_verify('admin123', hash) = TRUE - Login funcionara</div>";
        } else {
            echo "<div class='err'>✗ password_verify('admin123', hash) = FALSE - Ejecuta cambiar_password.php de nuevo</div>";
        }
    } else {
        echo "<div class='err'>✗ Usuario admin@soporte.com NO existe</div>";
    }

} catch (PDOException $e) {
    echo "<div class='err'>✗ Error BD: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h2>6. Credenciales en config/database.php</h2>";
$configPath = $projectRoot . '/config/database.php';
if (is_file($configPath)) {
    $content = file_get_contents($configPath);
    // Extraer solo las constantes
    if (preg_match_all("/private const (DB_\w+) = ([^;]+);/", $content, $matches, PREG_SET_ORDER)) {
        echo "<table>";
        foreach ($matches as $m) {
            echo "<tr><td>" . $m[1] . "</td><td><code>" . htmlspecialchars(trim($m[2])) . "</code></td></tr>";
        }
        echo "</table>";
    }
} else {
    echo "<div class='err'>✗ config/database.php NO existe</div>";
}

echo "<h2>7. web.config</h2>";
$configPath = $projectRoot . '/web.config';
if (is_file($configPath)) {
    echo "<div class='ok'>✓ web.config existe</div>";
    echo "<details><summary>Ver contenido del web.config</summary>";
    echo "<pre>" . htmlspecialchars(file_get_contents($configPath)) . "</pre>";
    echo "</details>";
} else {
    echo "<div class='err'>✗ web.config NO existe</div>";
}

echo "</div></body></html>";
