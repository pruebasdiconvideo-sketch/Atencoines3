<?php
/**
 * diagnostico_completo.php — Diagnóstico profundo del 502 Bad Gateway
 *
 * Este script:
 *   1. Muestra TODOS los errores de PHP (no los oculta)
 *   2. Verifica que config/database.php esté bien cargado
 *   3. Verifica extensión pdo_sqlsrv
 *   4. Verifica que la conexión a BD funcione
 *   5. Simula exactamente lo que hace /api/v1/auth/login
 *   6. Muestra el error EXACTO si algo falla
 *
 * CÓMO USARLO:
 *   1. Sube este archivo a la raíz del proyecto (junto a index.php)
 *   2. Ábrelo: http://localhost:8036/diagnostico_completo.php
 *   3. Verás un informe completo con el error real.
 *
 * BORRAR DESPUÉS DE PROBAR.
 */

declare(strict_types=1);

// MOSTRAR TODOS LOS ERRORES — esto es clave para ver el 502
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');

header('Content-Type: text/plain; charset=UTF-8');

echo "================================================================\n";
echo "  DIAGNÓSTICO COMPLETO — 502 Bad Gateway\n";
echo "================================================================\n\n";

echo "[1] INFORMACIÓN DEL SERVIDOR\n";
echo "  PHP versión:       " . PHP_VERSION . "\n";
echo "  SAPI:              " . php_sapi_name() . "\n";
echo "  Hostname:          " . (gethostname() ?: '?') . "\n";
echo "  IP servidor:       " . ($_SERVER['SERVER_ADDR'] ?? php_uname('n')) . "\n";
echo "  Software servidor: " . ($_SERVER['SERVER_SOFTWARE'] ?? '?') . "\n";
echo "  Document root:     " . ($_SERVER['DOCUMENT_ROOT'] ?? '?') . "\n";
echo "  Script path:       " . __DIR__ . "\n";
echo "  php.ini loaded:    " . (php_ini_loaded_file() ?: 'NINGUNO') . "\n";
echo "  php.ini scanned:   " . (php_ini_scanned_files() ?: 'ninguno') . "\n\n";

echo "[2] EXTENSIONES PHP CRÍTICAS\n";
echo "  pdo:               " . (extension_loaded('pdo') ? 'OK (' . phpversion('pdo') . ')' : 'FALTA') . "\n";
echo "  pdo_sqlsrv:        " . (extension_loaded('pdo_sqlsrv') ? 'OK (' . phpversion('pdo_sqlsrv') . ')' : 'FALTA') . "\n";
echo "  sqlsrv:            " . (extension_loaded('sqlsrv') ? 'OK (' . phpversion('sqlsrv') . ')' : 'FALTA') . "\n";
echo "  openssl:           " . (extension_loaded('openssl') ? 'OK' : 'FALTA') . "\n";
echo "  mbstring:          " . (extension_loaded('mbstring') ? 'OK' : 'FALTA') . "\n";
echo "  json:              " . (extension_loaded('json') ? 'OK' : 'FALTA') . "\n";
echo "  session:           " . (extension_loaded('session') ? 'OK' : 'FALTA') . "\n\n";

if (!extension_loaded('pdo_sqlsrv')) {
    echo "[FATAL] pdo_sqlsrv NO está instalado. Esta es probablemente la causa del 502.\n";
    echo "        Descarga: https://github.com/microsoft/msphpsql/releases\n";
    echo "        Para PHP 8.x usa pdo_sqlsrv 5.12+\n";
    echo "        En php.ini agrega:\n";
    echo "          extension=pdo_sqlsrv\n";
    echo "          extension=sqlsrv\n";
    echo "        Luego: iisreset\n";
    exit(1);
}

echo "[3] ARCHIVOS CRÍTICOS DEL PROYECTO\n";
$files = [
    'config/database.php'   => 'Configuración BD',
    'includes/auth.php'     => 'Clase Auth',
    'includes/session.php'  => 'Sesión',
    'includes/api_response.php' => 'API response helper',
    'includes/audit.php'    => 'Auditoría',
    'api/index.php'         => 'Router principal',
    'api/routes/auth.php'   => 'Endpoints auth',
];
foreach ($files as $rel => $desc) {
    $full = __DIR__ . '/' . $rel;
    $exists = is_file($full);
    $readable = $exists && is_readable($full);
    $size = $exists ? filesize($full) : 0;
    echo "  " . ($readable ? '[OK]' : '[FALTA]') . " $rel ($desc) — $size bytes\n";
}
echo "\n";

echo "[4] CARGAR config/database.php\n";
try {
    require_once __DIR__ . '/config/database.php';
    echo "  [OK] config/database.php cargado\n";
    echo "  Clase Database: " . (class_exists('Database') ? 'OK' : 'NO EXISTE') . "\n";
    if (class_exists('Database') && method_exists('Database', 'getConfigInfo')) {
        $info = Database::getConfigInfo();
        echo "  DB_HOST: " . $info['host'] . "\n";
        echo "  DB_PORT: " . $info['port'] . "\n";
        echo "  DB_NAME: " . $info['database'] . "\n";
        echo "  DB_USER: " . $info['user'] . "\n";
        echo "  DSN:     " . $info['dsn'] . "\n\n";
    } else {
        echo "  [WARN] El database.php no tiene getConfigInfo() — versión vieja\n\n";
    }
} catch (Throwable $e) {
    echo "  [FAIL] Error cargando database.php: " . $e->getMessage() . "\n";
    echo "  " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    exit(1);
}

echo "[5] PROBAR CONEXIÓN A BASE DE DATOS\n";
try {
    $pdo = Database::connection();
    echo "  [OK] Conexión PDO obtenida\n";
    $stmt = $pdo->query('SELECT @@VERSION AS v, DB_NAME() AS db, SUSER_NAME() AS u, @@SERVERNAME AS srv');
    $row = $stmt->fetch();
    echo "  Server:  " . substr($row['v'], 0, 100) . "\n";
    echo "  DB:      " . $row['db'] . "\n";
    echo "  User:    " . $row['u'] . "\n";
    echo "  Host:    " . $row['srv'] . "\n\n";
} catch (Throwable $e) {
    echo "  [FAIL] Conexión falló: " . $e->getMessage() . "\n";
    echo "  Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "  Trace:\n" . $e->getTraceAsString() . "\n\n";

    echo "[5b] PROBAR CONEXIÓN DIRECTA (sin usar Database class)\n";
    $IP   = '192.168.100.1';
    $PORT = 1435;
    $USER = 'sa';
    $PASS = 'WEB$VITEL1';
    $DB   = 'MonosyncERP';

    $dsn = "sqlsrv:Server=$IP,$PORT;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=10";
    echo "  DSN directo: $dsn\n";
    try {
        $pdo2 = new PDO($dsn, $USER, $PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        echo "  [OK] Conexión directa funcionó\n\n";
    } catch (Throwable $e2) {
        echo "  [FAIL] Conexión directa también falló: " . $e2->getMessage() . "\n\n";
        echo "[5c] VERIFICACIÓN DE PUERTO TCP\n";
        $sock = @fsockopen($IP, $PORT, $errno, $errstr, 5);
        if ($sock) {
            echo "  [OK] Puerto $PORT abierto\n";
            fclose($sock);
        } else {
            echo "  [FAIL] Puerto $PORT no accesible: $errstr ($errno)\n";
            echo "         El firewall del servidor $IP bloquea TCP $PORT\n";
            echo "         o el servicio SQL Server no está corriendo en ese puerto\n";
        }
        exit(1);
    }
}

echo "[6] SIMULAR LOGIN (lo mismo que /api/v1/auth/login)\n";
try {
    $correo = 'soporte@dicon.com.pe';
    echo "  Buscando usuario: $correo\n";
    $stmt = $pdo->prepare(
        "SELECT TOP 1 id_persona, correo, nombres, apellido_paterno,
                contrasena, bloqueado, estado, activo
         FROM persona WITH (NOLOCK)
         WHERE correo = ? AND activo = 1"
    );
    $stmt->execute([$correo]);
    $u = $stmt->fetch();
    if ($u) {
        echo "  [OK] Usuario encontrado: id=" . $u['id_persona'] . "\n";
        echo "  Correo:     " . $u['correo'] . "\n";
        echo "  Nombre:     " . $u['nombres'] . ' ' . $u['apellido_paterno'] . "\n";
        echo "  Estado:     " . ($u['estado'] ? 'activo' : 'inactivo') . "\n";
        echo "  Bloqueado:  " . ($u['bloqueado'] ? 'sí' : 'no') . "\n";
        echo "  Hash pass:  " . substr($u['contrasena'], 0, 30) . "...\n";
        echo "  password_verify('WEB\$VITEL1', hash): " . (password_verify('WEB$VITEL1', $u['contrasena']) ? 'OK' : 'NO coincide') . "\n\n";

        if (!$u['estado']) {
            echo "  [WARN] Usuario INACTIVO — login fallará.\n";
        }
        if ($u['bloqueado']) {
            echo "  [WARN] Usuario BLOQUEADO — login fallará.\n";
        }
    } else {
        echo "  [FAIL] Usuario NO existe en la BD con correo=$correo y activo=1\n";
        echo "  Verifica el correo o el flag activo\n\n";
    }
} catch (Throwable $e) {
    echo "  [FAIL] Error: " . $e->getMessage() . "\n\n";
}

echo "[7] OTROS ERRORES COMUNES QUE CAUSAN 502\n";

// 7a. session.save_path
echo "  session.save_path: " . (ini_get('session.save_path') ?: '(default)') . "\n";
$savePath = session_save_path() ?: sys_get_temp_dir();
if (!is_writable($savePath)) {
    echo "  [WARN] session.save_path NO es escribible: $savePath\n";
} else {
    echo "  [OK] session.save_path escribible: $savePath\n";
}

// 7b. upload_tmp_dir
echo "  upload_tmp_dir: " . (ini_get('upload_tmp_dir') ?: '(default)') . "\n";

// 7c. memory_limit
echo "  memory_limit: " . ini_get('memory_limit') . "\n";

// 7d. max_execution_time
echo "  max_execution_time: " . ini_get('max_execution_time') . "s\n";

// 7e. error_log
echo "  error_log: " . (ini_get('error_log') ?: '(default)') . "\n\n";

echo "[8] LOG DE ERRORES RECIENTES DE PHP\n";
$logs = [
    'C:\inetpub\logs\php-errors.log',
    'C:\PHP\logs\php_error.log',
    __DIR__ . '\storage\logs\api-errors.log',
    __DIR__ . '\storage\logs\php-errors.log',
    ini_get('error_log') ?: '',
    sys_get_temp_dir() . '\php-errors.log',
];
foreach ($logs as $log) {
    if ($log && is_file($log) && is_readable($log)) {
        echo "  Log encontrado: $log\n";
        $contents = file_get_contents($log);
        if ($contents) {
            // Últimos 3000 chars
            $tail = substr($contents, -3000);
            echo "  --- Últimas líneas ---\n";
            // Solo líneas con error/fatal/warning
            $lines = explode("\n", $tail);
            $relevant = array_filter($lines, function($l) {
                return preg_match('/error|fatal|warning|exception/i', $l);
            });
            echo implode("\n", array_slice($relevant, -20)) . "\n";
        }
        echo "\n";
    }
}

echo "[9] SIMULAR CARGA COMPLETA DEL ROUTER /api/v1/auth/login\n";
// Capturar cualquier output que haga el router
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/v1/auth/login';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';

// Simular body
$body = json_encode(['correo' => 'soporte@dicon.com.pe', 'password' => 'WEB$VITEL1']);
$tmpFile = tempnam(sys_get_temp_dir(), 'diagbody');
file_put_contents($tmpFile, $body);

// Redirigir php://input
// (PHP no permite cambiar php://input directamente, pero podemos test
// el handler manualmente)

echo "  Body simulado: $body\n";
echo "  Archivo temporal: $tmpFile\n\n";

// Cargar auth.php para verificar
try {
    require_once __DIR__ . '/includes/session.php';
    require_once __DIR__ . '/includes/api_response.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/audit.php';
    echo "  [OK] Todos los includes cargaron sin error\n\n";
} catch (Throwable $e) {
    echo "  [FAIL] Error en includes: " . $e->getMessage() . "\n";
    echo "  " . $e->getFile() . ":" . $e->getLine() . "\n\n";
}

@unlink($tmpFile);

echo "================================================================\n";
echo "  DIAGNÓSTICO COMPLETADO\n";
echo "================================================================\n";
echo "\n";
echo "Si llegaste hasta aquí SIN ningún [FAIL], entonces la BD y PHP están bien.\n";
echo "El 502 podría ser del IIS propiamente dicho. Cosas a revisar:\n";
echo "\n";
echo "  1. En IIS Manager → Application Pools → click en el pool de la app\n";
echo "     → Advanced Settings:\n";
echo "       - .NET CLR version: 'No Managed Code'\n";
echo "       - Identity: 'ApplicationPoolIdentity' o 'NetworkService'\n";
echo "       - Load User Profile: True\n";
echo "       - Process Model → Idle Time-out (minutes): 0\n";
echo "\n";
echo "  2. En IIS Manager → Sites → tu sitio → Handler Mappings:\n";
echo "     Verifica que PHP existe y apunta a php-cgi.exe correcto\n";
echo "\n";
echo "  3. Ejecuta en cmd como admin: iisreset\n";
echo "\n";
echo "  4. Verifica el log de IIS en:\n";
echo "     C:\\inetpub\\logs\\LogFiles\\W3SVC1\\u_ex*.log\n";
echo "     Busca líneas con '502' para ver el sub-código.\n";
echo "\n";
echo "  5. Si el sub-código es 502.5 = PHP process crash\n";
echo "     Si es 502.3 = connection error con FastCGI\n";
echo "     Si es 502.7 = FastCGI timeout\n";
echo "\n";
echo "*** BORRA ESTE ARCHIVO DESPUÉS DE PROBAR ***\n";
