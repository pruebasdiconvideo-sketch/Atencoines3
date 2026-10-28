<?php
/**
 * cambiar_password.php v2 - Muestra errores detallados
 *
 * Cambia la contraseña de admin@soporte.com a 'admin123'
 * usando hash bcrypt compatible con password_verify().
 */

declare(strict_types=1);

// Activar mostrar errores (solo diagnostico)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>";
echo "<title>Cambiar contraseña admin v2</title>";
echo "<style>
body{font-family:Segoe UI,Arial,sans-serif;max-width:760px;margin:40px auto;padding:20px;background:#f5f5f5}
.box{background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
.ok{color:#155724;background:#d4edda;padding:12px;border-radius:4px;margin:10px 0}
.err{color:#721c24;background:#f8d7da;padding:12px;border-radius:4px;margin:10px 0}
.warn{color:#856404;background:#fff3cd;padding:12px;border-radius:4px;margin:10px 0}
code{background:#e9ecef;padding:2px 6px;border-radius:3px;font-family:Consolas,monospace}
pre{background:#f1f3f5;padding:12px;border-radius:4px;overflow:auto;font-family:Consolas,monospace;font-size:13px}
h1{color:#304753;margin-top:0}
h2{color:#2590c6;border-bottom:1px solid #dee2e6;padding-bottom:6px;margin-top:24px}
</style></head><body><div class='box'>";

echo "<h1>Cambiar contraseña admin v2</h1>";

// ============================================================================
// CONFIGURACION DIRECTA (sin depender de config/database.php)
// ============================================================================
$DB_HOST = 'DESKTOP-FOOD01U\\SQL2025';
$DB_PORT = 1435;
$DB_NAME = 'MonosyncERP';
$DB_USER = 'sa';
$DB_PASS = '123';

$correo_destino = 'admin@soporte.com';
$nueva_password = 'admin123';

echo "<p><b>Configuracion activa:</b></p>";
echo "<pre>";
echo "DB_HOST = $DB_HOST\n";
echo "DB_PORT = $DB_PORT\n";
echo "DB_NAME = $DB_NAME\n";
echo "DB_USER = $DB_USER\n";
echo "DB_PASS = ***\n";
echo "Correo objetivo = $correo_destino\n";
echo "Nueva contraseña = $nueva_password";
echo "</pre>";

// ============================================================================
// PASO 1: Probar conexion directa (sin usar Database class)
// ============================================================================
echo "<h2>Paso 1: Probar conexion PDO directa</h2>";

$dsn = "sqlsrv:Server=$DB_HOST,$DB_PORT;Database=$DB_NAME;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=10";
echo "<p>DSN: <code>$dsn</code></p>";

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "<div class='ok'>✓ Conexion PDO exitosa</div>";

    // Verificar servidor
    $r = $pdo->query("SELECT @@VERSION AS v, DB_NAME() AS db, SUSER_NAME() AS u")->fetch();
    echo "<p>SQL Server: <code>" . substr($r['v'], 0, 80) . "...</code></p>";
    echo "<p>BD: <code>" . $r['db'] . "</code> | Usuario: <code>" . $r['u'] . "</code></p>";

} catch (PDOException $e) {
    echo "<div class='err'>✗ Error de conexion PDO: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<h3>Codigo de error SQLSTATE:</h3>";
    echo "<pre>" . $e->getCode() . "</pre>";

    // Sugerencias segun el tipo de error
    $msg = $e->getMessage();
    echo "<h3>Posible solucion:</h3>";
    if (strpos($msg, 'Login failed') !== false) {
        echo "<div class='warn'>Credenciales incorrectas. Verifica DB_USER y DB_PASS.</div>";
    } elseif (strpos($msg, 'TCP Provider') !== false || strpos($msg, 'server didn\'t respond') !== false) {
        echo "<div class='warn'>No se pudo conectar al servidor. Verifica:
        <ul>
        <li>Que SQL Server este corriendo: <code>Get-Service -Name '*SQL*'</code></li>
        <li>Que el puerto $DB_PORT sea correcto</li>
        <li>Que TCP/IP este habilitado en SQL Server Configuration Manager</li>
        <li>Que el firewall permita el puerto $DB_PORT</li>
        </ul></div>";
    } elseif (strpos($msg, 'certificate') !== false || strpos($msg, 'SSL') !== false) {
        echo "<div class='warn'>Problema de certificado TLS. Ya deberia estar resuelto con TrustServerCertificate=yes.
        Si persiste, intenta sin cifrado:
        <code>Encrypt=no;TrustServerCertificate=yes</code></div>";
    }

    echo "</div></body></html>";
    exit;
}

// ============================================================================
// PASO 2: Verificar que el usuario admin@soporte.com existe
// ============================================================================
echo "<h2>Paso 2: Verificar usuario objetivo</h2>";

try {
    $stmt = $pdo->prepare("SELECT id_persona, correo, contrasena, estado, bloqueado, activo FROM persona WHERE correo = ?");
    $stmt->execute([$correo_destino]);
    $user = $stmt->fetch();

    if (!$user) {
        echo "<div class='err'>✗ No se encontro ninguna persona con correo <code>$correo_destino</code></div>";
        echo "<p>Verificando personas disponibles con correo similar:</p>";
        $stmt = $pdo->query("SELECT TOP 10 id_persona, correo FROM persona ORDER BY id_persona");
        echo "<ul>";
        while ($r = $stmt->fetch()) {
            echo "<li>ID " . $r['id_persona'] . ": " . htmlspecialchars($r['correo']) . "</li>";
        }
        echo "</ul>";
        echo "</div></body></html>";
        exit;
    }

    echo "<div class='ok'>✓ Usuario encontrado</div>";
    echo "<table style='width:100%;border-collapse:collapse;font-size:14px;margin:10px 0'>";
    echo "<tr><td style='padding:6px;border:1px solid #ddd'><b>ID</b></td><td style='padding:6px;border:1px solid #ddd'>" . $user['id_persona'] . "</td></tr>";
    echo "<tr><td style='padding:6px;border:1px solid #ddd'><b>Correo</b></td><td style='padding:6px;border:1px solid #ddd'>" . htmlspecialchars($user['correo']) . "</td></tr>";
    echo "<tr><td style='padding:6px;border:1px solid #ddd'><b>Estado</b></td><td style='padding:6px;border:1px solid #ddd'>" . $user['estado'] . " (1=activo)</td></tr>";
    echo "<tr><td style='padding:6px;border:1px solid #ddd'><b>Bloqueado</b></td><td style='padding:6px;border:1px solid #ddd'>" . $user['bloqueado'] . " (0=no)</td></tr>";
    echo "<tr><td style='padding:6px;border:1px solid #ddd'><b>Activo</b></td><td style='padding:6px;border:1px solid #ddd'>" . $user['activo'] . " (1=si)</td></tr>";
    echo "<tr><td style='padding:6px;border:1px solid #ddd'><b>Hash actual</b></td><td style='padding:6px;border:1px solid #ddd'><code>" . htmlspecialchars(substr($user['contrasena'], 0, 80)) . "</code></td></tr>";
    echo "</table>";

} catch (Throwable $e) {
    echo "<div class='err'>✗ Error al consultar persona: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "</div></body></html>";
    exit;
}

// ============================================================================
// PASO 3: Generar hash bcrypt
// ============================================================================
echo "<h2>Paso 3: Generar hash bcrypt</h2>";

if (!function_exists('password_hash')) {
    echo "<div class='err'>✗ La funcion password_hash() no existe. Necesitas PHP 5.5+ con extension openssl.</div>";
    echo "</div></body></html>";
    exit;
}

$hash = password_hash($nueva_password, PASSWORD_BCRYPT);
if ($hash === false) {
    echo "<div class='err'>✗ No se pudo generar el hash. Verifica configuracion de PHP.</div>";
    echo "</div></body></html>";
    exit;
}

echo "<div class='ok'>✓ Hash bcrypt generado</div>";
echo "<p>Hash: <code>" . htmlspecialchars($hash) . "</code></p>";

// ============================================================================
// PASO 4: Actualizar contraseña
// ============================================================================
echo "<h2>Paso 4: Actualizar contraseña en BD</h2>";

try {
    $stmt = $pdo->prepare("UPDATE persona SET contrasena = ? WHERE id_persona = ?");
    $stmt->execute([$hash, $user['id_persona']]);

    $rows = $stmt->rowCount();
    echo "<div class='ok'>✓ Contraseña actualizada. Filas afectadas: $rows</div>";

} catch (Throwable $e) {
    echo "<div class='err'>✗ Error al actualizar: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "</div></body></html>";
    exit;
}

// ============================================================================
// PASO 5: Verificar el cambio
// ============================================================================
echo "<h2>Paso 5: Verificar cambio</h2>";

$stmt = $pdo->prepare("SELECT contrasena FROM persona WHERE id_persona = ?");
$stmt->execute([$user['id_persona']]);
$final = $stmt->fetch();

$pwdValida = password_verify($nueva_password, $final['contrasena']);

if ($pwdValida) {
    echo "<div class='ok'>✓ password_verify('$nueva_password', hash) = TRUE</div>";
    echo "<div class='ok' style='font-size:18px;padding:20px;margin:20px 0'>";
    echo "<b>LOGIN LISTO!</b><br><br>";
    echo "<b>Correo:</b> <code>$correo_destino</code><br>";
    echo "<b>Contraseña:</b> <code>$nueva_password</code><br><br>";
    echo "<a href='/login.php' style='display:inline-block;padding:10px 20px;background:#2590c6;color:#fff;text-decoration:none;border-radius:4px'>Ir al login</a>";
    echo "</div>";
} else {
    echo "<div class='err'>✗ password_verify fallo. Algo salio mal con el hash.</div>";
    echo "<p>Hash en BD: <code>" . htmlspecialchars($final['contrasena']) . "</code></p>";
}

echo "<h2>Próximos pasos</h2>";
echo "<ol>";
echo "<li>Ir a <a href='/login.php'><code>/login.php</code></a></li>";
echo "<li>Ingresar con las credenciales de arriba</li>";
echo "<li><b style='color:#dc3545'>IMPORTANTE:</b> Borrar este archivo (<code>cambiar_password.php</code>) del servidor por seguridad</li>";
echo "</ol>";

echo "</div></body></html>";
