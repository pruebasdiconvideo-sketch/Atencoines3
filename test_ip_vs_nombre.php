<?php
/**
 * test_ip_vs_nombre.php — Demuestra que el problema es el nombre vs la IP
 *
 * Ejecuta 4 pruebas:
 *   1. SERVERDICON\SQL2025 (nombre actual — falla)
 *   2. SERVERDICON,1435 (nombre + puerto — probablemente falla)
 *   3. 192.168.100.1,1435 (IP + puerto — DEBE funcionar)
 *   4. 192.168.100.1\SQL2025 (IP + instancia — depende de SQL Browser)
 *
 * Sube este archivo a la raíz del proyecto y ábrelo en el navegador.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/plain; charset=UTF-8');

echo "================================================================\n";
echo "  TEST: Nombre vs IP (diagnóstico del timeout)\n";
echo "================================================================\n\n";

echo "Servidor APP (donde corre PHP): " . gethostname() . "\n";
echo "PHP versión: " . PHP_VERSION . "\n";
echo "pdo_sqlsrv: " . (extension_loaded('pdo_sqlsrv') ? phpversion('pdo_sqlsrv') : 'NO') . "\n\n";

$USER = 'sa';
$PASS = 'WEB$VITEL1';
$DB   = 'MonosyncERP';

// Verificar resolución DNS del nombre SERVERDICON
echo "--- Resolución DNS de 'SERVERDICON' ---\n";
$ip = @gethostbyname('SERVERDICON');
if ($ip === 'SERVERDICON') {
    echo "[FAIL] El servidor APP NO puede resolver el nombre 'SERVERDICON'\n";
    echo "       Esta es la causa raíz del timeout y del 502.\n";
    echo "       Solución: usar la IP 192.168.100.1 directamente.\n\n";
} else {
    echo "[OK] 'SERVERDICON' resuelve a: $ip\n\n";
}

// Ping al nombre
echo "--- Ping a 'SERVERDICON' ---\n";
exec("ping -n 2 -w 2000 SERVERDICON 2>&1", $out, $rc);
echo implode("\n", $out) . "\n";
echo "Exit code: $rc\n\n";

// Ping a la IP
echo "--- Ping a '192.168.100.1' ---\n";
exec("ping -n 2 -w 2000 192.168.100.1 2>&1", $out2, $rc2);
echo implode("\n", $out2) . "\n";
echo "Exit code: $rc2 " . ($rc2 === 0 ? "(OK)" : "(FAIL)") . "\n\n";

// Test puerto 1435 contra la IP
echo "--- Test TCP puerto 1435 en 192.168.100.1 ---\n";
$sock = @fsockopen('192.168.100.1', 1435, $errno, $errstr, 5);
if ($sock) {
    echo "[OK] Puerto 1435 ABIERTO en 192.168.100.1\n";
    fclose($sock);
} else {
    echo "[FAIL] Puerto 1435 no accesible: $errstr ($errno)\n";
}
echo "\n";

// Probar las 4 variantes de conexión DSN
function probar($label, $dsn, $user, $pass) {
    echo "--- $label ---\n";
    echo "  DSN: $dsn\n";
    $start = microtime(true);
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 5,
        ]);
        $elapsed = round((microtime(true) - $start) * 1000);
        $row = $pdo->query('SELECT DB_NAME() AS db, @@SERVERNAME AS srv')->fetch();
        echo "  [OK] Conectado en {$elapsed}ms — DB: " . $row['db'] . " srv: " . $row['srv'] . "\n\n";
        return true;
    } catch (Throwable $e) {
        $elapsed = round((microtime(true) - $start) * 1000);
        echo "  [FAIL] tras {$elapsed}ms — " . $e->getMessage() . "\n\n";
        return false;
    }
}

echo "================================================================\n";
echo "PRUEBAS DE CONEXIÓN\n";
echo "================================================================\n\n";

$r1 = probar(
    "1) SERVERDICON\\SQL2025 (config ACTUAL — rota)",
    "sqlsrv:Server=SERVERDICON\\SQL2025;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=8",
    $USER, $PASS
);

$r2 = probar(
    "2) SERVERDICON,1435 (nombre + puerto)",
    "sqlsrv:Server=SERVERDICON,1435;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=8",
    $USER, $PASS
);

$r3 = probar(
    "3) 192.168.100.1,1435 (IP + puerto — FIX)",
    "sqlsrv:Server=192.168.100.1,1435;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=8",
    $USER, $PASS
);

$r4 = probar(
    "4) 192.168.100.1\\SQL2025 (IP + instancia)",
    "sqlsrv:Server=192.168.100.1\\SQL2025;Database=$DB;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=8",
    $USER, $PASS
);

echo "================================================================\n";
echo "RESUMEN\n";
echo "================================================================\n";
echo "  1) SERVERDICON\\SQL2025:  " . ($r1 ? 'OK' : 'FAIL') . " ← tu config actual\n";
echo "  2) SERVERDICON,1435:      " . ($r2 ? 'OK' : 'FAIL') . "\n";
echo "  3) 192.168.100.1,1435:    " . ($r3 ? 'OK' : 'FAIL') . " ← FIX recomendado\n";
echo "  4) 192.168.100.1\\SQL2025: " . ($r4 ? 'OK' : 'FAIL') . "\n\n";

if ($r3 && !$r1) {
    echo ">>> CONFIRMADO: el problema es el nombre 'SERVERDICON'. <<<\n";
    echo ">>> El FIX es usar la IP 192.168.100.1 + puerto 1435. <<<\n";
    echo ">>> Reemplaza config/database.php por el archivo corregido. <<<\n";
} elseif ($r3) {
    echo ">>> La IP funciona. Aplica el FIX de todos modos. <<<\n";
} else {
    echo ">>> FALLO TOTAL: ni con IP conecta. <<<\n";
    echo ">>> Revisa firewall del servidor BD (192.168.100.1): <<<\n";
    echo ">>>   New-NetFirewallRule -DisplayName 'SQL 1435' `\n";
    echo ">>>     -Direction Inbound -Protocol TCP -LocalPort 1435 -Action Allow\n";
}

echo "\n*** BORRA ESTE ARCHIVO DESPUÉS DE PROBAR ***\n";
