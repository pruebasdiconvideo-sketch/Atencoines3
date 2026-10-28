<?php
/**
 * test_db_conn.php — Diagnóstico de conexión (puerto 1435)
 *
 * Escenario: PHP en servidor A, SQL Server en 192.168.100.1:1435
 * (puerto fijo de la instancia que tiene MonosyncERP).
 *
 * CÓMO USARLO:
 *   1. Sube este archivo y config/database.php al servidor APP
 *   2. Ábrelo: http://localhost:8036/test_db_conn.php
 *   3. Verás un informe completo.
 *
 * BORRAR DESPUÉS DE PROBAR (expone credenciales).
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

echo "================================================================\n";
echo "  DIAGNÓSTICO CONEXIÓN SQL SERVER (192.168.100.1:1435)\n";
echo "================================================================\n\n";

echo "[INFO] PHP versión: " . PHP_VERSION . "\n";
echo "[INFO] Hostname servidor PHP: " . (gethostname() ?: 'unknown') . "\n";
echo "[INFO] IP servidor PHP: " . ($_SERVER['SERVER_ADDR'] ?? php_uname('n')) . "\n";
echo "[INFO] pdo_sqlsrv: " . (extension_loaded('pdo_sqlsrv') ? phpversion('pdo_sqlsrv') : 'NO INSTALADO') . "\n";
echo "[INFO] sqlsrv:     " . (extension_loaded('sqlsrv')     ? phpversion('sqlsrv')     : 'NO INSTALADO') . "\n";

if (!extension_loaded('pdo_sqlsrv')) {
    echo "\n[FATAL] La extensión pdo_sqlsrv NO está instalada en PHP.\n";
    echo "        Descárgala de https://github.com/microsoft/msphpsql\n";
    echo "        Para PHP 8.x usa pdo_sqlsrv 5.12+\n";
    echo "        Agregar en php.ini:\n";
    echo "          extension=pdo_sqlsrv\n";
    echo "          extension=sqlsrv\n";
    exit(1);
}

echo "\n";

/* ============================================================
 * Credenciales (puerto fijo 1435)
 * ============================================================ */
$IP   = '192.168.100.1';
$PORT = 1435;
$USER = 'sa';
$PASS = 'WEB$VITEL1';
$DB   = 'MonosyncERP';

/* ============================================================
 * PRUEBA 0: Conectividad de red
 * ============================================================ */
echo "================================================================\n";
echo "PRUEBA 0: CONECTIVIDAD DE RED\n";
echo "================================================================\n";

// 0a. Ping
echo "\n--- 0a) Ping a $IP ---\n";
$ping = PHP_OS_FAMILY === 'Windows'
    ? "ping -n 2 -w 2000 " . escapeshellarg($IP)
    : "ping -c 2 -W 2 " . escapeshellarg($IP);
echo "Ejecutando: $ping\n";
$output = [];
exec($ping . " 2>&1", $output, $rc);
echo implode("\n", $output) . "\n";
echo "Exit code: $rc " . ($rc === 0 ? "(OK)" : "(FAIL)") . "\n\n";

// 0b. Test de puerto TCP 1435 (crítico)
echo "--- 0b) Puerto TCP $PORT en $IP ---\n";
$sock = @fsockopen($IP, $PORT, $errno, $errstr, 5);
if ($sock) {
    echo "[OK] Puerto $PORT ABIERTO en $IP\n";
    fclose($sock);
} else {
    echo "[FAIL] Puerto $PORT no accesible: $errstr ($errno)\n";
    echo "       CAUSAS POSIBLES:\n";
    echo "       1. Firewall del servidor BD (192.168.100.1) bloquea TCP $PORT\n";
    echo "       2. La instancia SQL que usa MonosyncERP NO está en este puerto\n";
    echo "       3. SQL Server no está corriendo\n";
    echo "       4. La IP $IP no es alcanzable desde el servidor APP\n\n";

    // Probar también otros puertos comunes para diagnóstico
    foreach ([1433, 1434, 1436, 49170] as $p) {
        $s = @fsockopen($IP, $p, $e, $es, 2);
        if ($s) {
            echo "       [INFO] Puerto $p sí está abierto (¿otra instancia?)\n";
            fclose($s);
        }
    }
    echo "\n";
}

/* ============================================================
 * PRUEBA 1: Configuración actual de config/database.php
 * ============================================================ */
echo "================================================================\n";
echo "PRUEBA 1: CONFIGURACIÓN DE config/database.php\n";
echo "================================================================\n";

if (is_file(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    try {
        $info = Database::test();
        echo "DSN: " . $info['config']['dsn'] . "\n";
        echo "User: " . $info['config']['user'] . "\n\n";

        if ($info['success']) {
            echo "[OK] CONEXIÓN EXITOSA!\n";
            if ($info['query_test']) {
                echo "  Server:  " . substr($info['query_test']['version'], 0, 80) . "...\n";
                echo "  DB:      " . $info['query_test']['db'] . "\n";
                echo "  User:    " . $info['query_test']['user_name'] . "\n";
                echo "  Host:    " . $info['query_test']['server_name'] . "\n";
            }
            echo "\n>>> YA FUNCIONA. Borra este archivo y prueba login. <<<\n";
            exit(0);
        } else {
            echo "[FAIL] " . $info['error'] . "\n\n";
        }
    } catch (Throwable $e) {
        echo "[FAIL] " . $e->getMessage() . "\n\n";
    }
} else {
    echo "[SKIP] No se encontró config/database.php\n\n";
}

/* ============================================================
 * Helper para probar DSN
 * ============================================================ */
function probar_dsn(string $label, string $dsn, string $user, string $pass): bool {
    echo "--- $label ---\n";
    echo "  DSN: $dsn\n";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
            PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 5,
        ]);
        $info = $pdo->query('SELECT @@VERSION AS v, DB_NAME() AS db, SUSER_NAME() AS u, @@SERVERNAME AS srv')->fetch();
        echo "  [OK] Conectado a '" . $info['db'] . "' en servidor '" . $info['srv'] . "' como '" . $info['u'] . "'\n";
        echo "  Versión: " . substr($info['v'], 0, 80) . "...\n\n";
        return true;
    } catch (Throwable $e) {
        echo "  [FAIL] " . $e->getMessage() . "\n\n";
        return false;
    }
}

/* ============================================================
 * PRUEBAS DE VARIANTES
 * ============================================================ */
echo "================================================================\n";
echo "PRUEBAS DE VARIANTES DE DSN\n";
echo "================================================================\n\n";

$r1 = probar_dsn(
    "1) IP + puerto 1435 (CONFIGURACIÓN RECOMENDADA)",
    "sqlsrv:Server=$IP,$PORT;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=10",
    $USER, $PASS
);

$r2 = probar_dsn(
    "2) IP + puerto 1435 SIN MARS (algunos servers no lo soportan)",
    "sqlsrv:Server=$IP,$PORT;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=10;MultipleActiveResultSets=false",
    $USER, $PASS
);

$r3 = probar_dsn(
    "3) IP + instancia + puerto (no estándar pero a veces funciona)",
    "sqlsrv:Server=$IP\\SQL2025,$PORT;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=10",
    $USER, $PASS
);

$r4 = probar_dsn(
    "4) IP + instancia SIN puerto (depende de SQL Browser UDP 1434)",
    "sqlsrv:Server=$IP\\SQL2025;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=10",
    $USER, $PASS
);

$r5 = probar_dsn(
    "5) IP + puerto default 1433 (por si esta instancia usa el default)",
    "sqlsrv:Server=$IP,1433;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=10",
    $USER, $PASS
);

/* ============================================================
 * RESUMEN
 * ============================================================ */
echo "================================================================\n";
echo "RESUMEN\n";
echo "================================================================\n";
echo "  1) IP + 1435 (RECOMENDADA):     " . ($r1 ? 'OK' : 'FAIL') . "\n";
echo "  2) IP + 1435 sin MARS:          " . ($r2 ? 'OK' : 'FAIL') . "\n";
echo "  3) IP + instancia + 1435:       " . ($r3 ? 'OK' : 'FAIL') . "\n";
echo "  4) IP + instancia sin puerto:   " . ($r4 ? 'OK' : 'FAIL') . "\n";
echo "  5) IP + 1433:                   " . ($r5 ? 'OK' : 'FAIL') . "\n";

if ($r1) {
    echo "\n>>> CONFIGURACIÓN CORRECTA: IP + puerto 1435 <<<\n";
    echo ">>> Esta es la que ya está en config/database.php <<<\n";
    echo ">>> Borra este archivo y prueba login en la app. <<<\n";
} elseif ($r2 || $r3 || $r4 || $r5) {
    echo "\n[INFO] La config recomendada falló pero otra funcionó.\n";
    echo "       Ajusta config/database.php según la que dio OK.\n";
} else {
    echo "\n[FATAL] Ninguna variante conectó.\n";
    echo "\nDIAGNÓSTICO:\n\n";
    echo "1. Verifica que el puerto TCP 1435 esté abierto en el firewall\n";
    echo "   del servidor 192.168.100.1:\n";
    echo "   - En el servidor BD abre PowerShell como admin:\n";
    echo "       Test-NetConnection -ComputerName 192.168.100.1 -Port 1435\n";
    echo "   - Si dice 'TcpTestSucceeded: False', abre el puerto:\n";
    echo "       New-NetFirewallRule -DisplayName 'SQL 1435' `\n";
    echo "         -Direction Inbound -Protocol TCP -LocalPort 1435 -Action Allow\n\n";
    echo "2. Verifica que la instancia SQL que tiene MonosyncERP use el puerto 1435:\n";
    echo "   - En el servidor BD abre SQL Server Configuration Manager\n";
    echo "   - SQL Server Network Configuration → Protocols for SQL2025\n";
    echo "   - TCP/IP → Properties → IP Addresses → IPAll\n";
    echo "   - 'TCP Port' debe decir 1435 (o 'TCP Dynamic Ports' debe estar vacío\n";
    echo "     y 'TCP Port' = 1435)\n\n";
    echo "3. Verifica que el servicio SQL Server (SQL2025) esté corriendo:\n";
    echo "   - services.msc → 'SQL Server (SQL2025)' → Running\n\n";
    echo "4. Verifica credenciales SQL:\n";
    echo "   - 'sa' habilitado (SSMS → Security → Logins → sa → Status → Enabled)\n";
    echo "   - Server Properties → Security → 'SQL Server and Windows Authentication mode'\n";
    echo "   - sa con permisos sobre MonosyncERP (db_owner)\n\n";
    echo "5. Desde el servidor BD, verifica localmente:\n";
    echo "   - sqlcmd -S localhost,1435 -U sa -P 'WEB\$VITEL1' -Q 'SELECT DB_NAME()'\n";
    echo "   - Si esto funciona, el problema es de red/firewall\n";
    echo "   - Si esto falla, el problema es de configuración SQL\n";
}

echo "\n*** BORRA ESTE ARCHIVO DESPUÉS DE PROBAR ***\n";
