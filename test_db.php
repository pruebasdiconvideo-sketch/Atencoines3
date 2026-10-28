<?php
/**
 * test_db.php - Diagnostico de conexion a SQL Server
 *
 * USO: http://localhost:8036/test_db.php
 * BORRAR despues de usar.
 */
header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Test DB</title>";
echo "<style>body{font-family:Consolas,monospace;padding:20px;background:#f5f5f5}";
echo ".ok{color:#155724;background:#d4edda;padding:8px;margin:4px 0;border-radius:4px}";
echo ".err{color:#721c24;background:#f8d7da;padding:8px;margin:4px 0;border-radius:4px}";
echo ".warn{color:#856404;background:#fff3cd;padding:8px;margin:4px 0;border-radius:4px}";
echo "</style></head><body>";
echo "<h1>Diagnostico de Conexion a SQL Server</h1>";

// 1. Version de PHP
echo "<h2>1. PHP</h2>";
echo "<p class='ok'>Version PHP: " . PHP_VERSION . "</p>";
echo "<p>SAPI: " . PHP_SAPI . "</p>";

// 2. Extensiones cargadas
echo "<h2>2. Extensiones cargadas</h2>";
$exts = ['pdo_sqlsrv', 'sqlsrv', 'pdo', 'openssl', 'mbstring'];
foreach ($exts as $e) {
    if (extension_loaded($e)) {
        echo "<p class='ok'>✓ $e cargada</p>";
    } else {
        echo "<p class='err'>✗ $e NO cargada</p>";
    }
}

// 3. Drivers PDO disponibles
echo "<h2>3. Drivers PDO disponibles</h2>";
$drivers = PDO::getAvailableDrivers();
echo "<p>Drivers: " . implode(', ', $drivers) . "</p>";
if (in_array('sqlsrv', $drivers)) {
    echo "<p class='ok'>✓ PDO driver sqlsrv disponible</p>";
} else {
    echo "<p class='err'>✗ PDO driver sqlsrv NO disponible. Instala MS Drivers for PHP for SQL Server.</p>";
}

// 4. Lista de configuraciones a probar
echo "<h2>4. Probando conexiones</h2>";

$tests = [
    // (descripcion, host, port, db, user, pass, trust_cert)
    ['Localhost default',       'localhost',      1433, 'MonosyncERP', 'sa',         '', true],
    ['Localhost sin puerto',   'localhost',      null, 'MonosyncERP', 'sa',         '', true],
    ['127.0.0.1',              '127.0.0.1',      1433, 'MonosyncERP', 'sa',         '', true],
    ['Local con instancia',    'localhost\SQLEXPRESS', null, 'MonosyncERP', 'sa', '', true],
    ['Local con instancia SQL2022', 'localhost\SQL2022', null, 'MonosyncERP', 'sa', '', true],
    ['IP local',                '127.0.0.1',      1433, 'MonosyncERP', 'sa',        '', true],
];

$success = false;
$lastError = '';

foreach ($tests as $i => $test) {
    [$desc, $host, $port, $db, $user, $pass, $trust] = $test;
    echo "<h3>Test " . ($i + 1) . ": $desc</h3>";
    echo "<p>Host: <code>$host</code>, Port: <code>" . ($port ?? 'null') . "</code>, DB: <code>$db</code>, User: <code>$user</code></p>";

    $server = $host;
    if ($port !== null) {
        $server .= ",$port";
    }

    $dsn = "sqlsrv:Server=$server;Database=$db;Encrypt=yes;TrustServerCertificate=" . ($trust ? 'yes' : 'no') . ";LoginTimeout=5";

    echo "<p>DSN: <code>$dsn</code></p>";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        echo "<p class='ok'>✓ CONEXION EXITOSA con: $desc</p>";

        // Probar una query simple
        $r = $pdo->query("SELECT @@VERSION AS version, DB_NAME() AS db_name, SUSER_NAME() AS user_name")->fetch();
        echo "<p class='ok'>SQL Server version: " . substr($r['version'] ?? '', 0, 80) . "...</p>";
        echo "<p class='ok'>BD conectada: " . $r['db_name'] . "</p>";
        echo "<p class='ok'>Usuario: " . $r['user_name'] . "</p>";

        // Probar query sobre persona
        $count = $pdo->query("SELECT COUNT(*) FROM persona")->fetchColumn();
        echo "<p class='ok'>Tabla persona tiene: $count registros</p>";

        // Probar SP
        try {
            $stmt = $pdo->query("SELECT TOP 1 id_persona, correo FROM persona ORDER BY id_persona");
            $row = $stmt->fetch();
            echo "<p class='ok'>Primer usuario en persona: ID=" . ($row['id_persona'] ?? 'N/A') . " - " . htmlspecialchars($row['correo'] ?? 'N/A') . "</p>";
        } catch (Throwable $e) {
            echo "<p class='warn'>Query de prueba fallo: " . $e->getMessage() . "</p>";
        }

        echo "<div style='background:#d1ecf1;padding:12px;margin:10px 0;border-radius:4px'>";
        echo "<b>Usa esta configuracion en config/database.php:</b><br>";
        echo "<pre style='margin:8px 0;background:#fff;padding:8px'>";
echo "private const DB_HOST = '" . addslashes($host) . "';
private const DB_PORT = " . ($port ?? 'null') . ";
private const DB_NAME = '$db';
private const DB_USER = '" . addslashes($user) . "';
private const DB_PASS = '" . addslashes($pass) . "';";
        echo "</pre></div>";

        $success = true;
        break;

    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $lastError = $msg;
        // Limpiar el mensaje para mostrarlo
        if (strpos($msg, 'Login failed') !== false) {
            echo "<p class='err'>✗ Credenciales incorrectas para usuario '$user'</p>";
        } elseif (strpos($msg, 'could not find driver') !== false) {
            echo "<p class='err'>✗ Driver PDO sqlsrv no cargado en PHP</p>";
            break;  // no seguir probando si falta el driver
        } elseif (strpos($msg, 'TCP Provider') !== false || strpos($msg, 'no such host') !== false) {
            echo "<p class='err'>✗ No se pudo conectar al host. Error: " . htmlspecialchars($msg) . "</p>";
        } elseif (strpos($msg, 'server didn\'t respond') !== false || strpos($msg, 'timed out') !== false) {
            echo "<p class='err'>✗ Timeout. Verifica que SQL Server este corriendo y TCP/IP habilitado.</p>";
        } else {
            echo "<p class='err'>✗ Error: " . htmlspecialchars($msg) . "</p>";
        }
    }
}

echo "<h2>5. Resumen</h2>";
if ($success) {
    echo "<p class='ok'>✓ Conexion exitosa. Usa la configuracion mostrada arriba en config/database.php</p>";
} else {
    echo "<p class='err'>✗ Ninguna conexion funciono. Ultimo error: " . htmlspecialchars($lastError) . "</p>";
    echo "<h3>Soluciones sugeridas:</h3>";
    echo "<ol>";
    echo "<li>Verifica que SQL Server este corriendo: <code>Get-Service -Name '*SQL*'</code></li>";
    echo "<li>Habilita TCP/IP en SQL Server Configuration Manager</li>";
    echo "<li>Verifica puerto 1433 en firewall (o desactiva firewall para pruebas)</li>";
    echo "<li>Confirma usuario y contraseña. En desarrollo puedes usar 'sa' con tu contraseña real.</li>";
    echo "<li>Si usas instancia con nombre (SQLEXPRESS, SQL2022), el puerto 1433 no aplica - usar nombre completo.</li>";
    echo "<li>Instala el driver pdo_sqlsrv si no esta cargado (paso 2 de arriba)</li>";
    echo "</ol>";
}

echo "</body></html>";
