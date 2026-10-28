<?php
/**
 * login_debug.php — Simula el login paso a paso con TODOS los errores visibles
 *
 * CÓMO USARLO:
 *   1. Sube este archivo a la raíz del proyecto
 *   2. Ábrelo: http://localhost:8036/login_debug.php
 *   3. Verás exactamente dónde se rompe el login
 *
 * BORRAR DESPUÉS DE PROBAR.
 */

declare(strict_types=1);

// Mostrar TODOS los errores
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

header('Content-Type: text/plain; charset=UTF-8');

echo "================================================================\n";
echo "  LOGIN DEBUG — Paso a paso\n";
echo "================================================================\n\n";

// Datos de prueba
$correo  = $_GET['correo']  ?? 'soporte@dicon.com.pe';
$password = $_GET['pass']   ?? 'WEB$VITEL1';

echo "Correo a probar: $correo\n";
echo "Password length: " . strlen($password) . " chars\n\n";

// Paso 1: cargar includes
echo "[PASO 1] Cargar includes\n";
try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/session.php';
    require_once __DIR__ . '/includes/api_response.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/audit.php';
    echo "  [OK] Todos los includes cargaron\n\n";
} catch (Throwable $e) {
    echo "  [FAIL] Error cargando includes: " . $e->getMessage() . "\n";
    echo "  Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    exit(1);
}

// Paso 2: conectar a BD
echo "[PASO 2] Conectar a BD\n";
try {
    $pdo = Database::connection();
    echo "  [OK] Conexión obtenida\n\n";
} catch (Throwable $e) {
    echo "  [FAIL] No se pudo conectar a BD: " . $e->getMessage() . "\n";
    echo "  Este es EL error que causa el 502 en el login.\n\n";
    exit(1);
}

// Paso 3: buscar usuario
echo "[PASO 3] Buscar usuario en BD\n";
try {
    $stmt = $pdo->prepare(
        "SELECT TOP 1 id_persona, correo, nombres, apellido_paterno,
                tipo_documento, numero_documento, telefono, sexo,
                contrasena, bloqueado, estado, activo
         FROM persona WITH (NOLOCK)
         WHERE correo = ? AND activo = 1"
    );
    $stmt->execute([$correo]);
    $u = $stmt->fetch();
    if (!$u) {
        echo "  [FAIL] Usuario NO encontrado con correo=$correo y activo=1\n";
        echo "  ¿El usuario existe? Verifica en la BD:\n";
        echo "    SELECT id_persona, correo, activo FROM persona WHERE correo = '$correo'\n\n";
        exit(1);
    }
    echo "  [OK] Usuario encontrado:\n";
    echo "    id_persona:    " . $u['id_persona'] . "\n";
    echo "    correo:        " . $u['correo'] . "\n";
    echo "    nombres:       " . $u['nombres'] . "\n";
    echo "    estado:        " . ($u['estado'] ? 'activo' : 'INACTIVO') . "\n";
    echo "    bloqueado:     " . ($u['bloqueado'] ? 'SÍ' : 'no') . "\n";
    echo "    hash (primeros 60 chars): " . substr($u['contrasena'], 0, 60) . "\n\n";
} catch (Throwable $e) {
    echo "  [FAIL] Error en query: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Paso 4: verificar password
echo "[PASO 4] Verificar password\n";
$valid = password_verify($password, $u['contrasena']);
echo "  password_verify('$password', hash) = " . ($valid ? 'TRUE' : 'FALSE') . "\n";
if (!$valid) {
    echo "  [FAIL] Password NO coincide con el hash guardado.\n";
    echo "  ¿El usuario sabe su contraseña real?\n";
    echo "  ¿La contraseña fue hasheada con password_hash() de PHP?\n";
    echo "  Hash actual: " . $u['contrasena'] . "\n\n";
    echo "  Para resetear la contraseña a 'WEB\$VITEL1' ejecuta:\n";
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    echo "    UPDATE persona SET contrasena = '$newHash' WHERE correo = '$correo'\n\n";
    exit(1);
}
echo "  [OK] Password correcto\n\n";

// Paso 5: verificar estado/bloqueo
echo "[PASO 5] Verificar estado y bloqueo\n";
if (!$u['estado']) {
    echo "  [FAIL] Usuario INACTIVO (estado=0)\n";
    exit(1);
}
if ($u['bloqueado']) {
    echo "  [FAIL] Usuario BLOQUEADO (bloqueado=1)\n";
    exit(1);
}
echo "  [OK] Usuario activo y no bloqueado\n\n";

// Paso 6: iniciar sesión
echo "[PASO 6] Iniciar sesión PHP\n";
try {
    startAppSession();
    $_SESSION['user'] = [
        'id_persona'        => (int)$u['id_persona'],
        'correo'            => $u['correo'],
        'nombres'           => $u['nombres'] ?? '',
        'apellido_paterno'  => $u['apellido_paterno'] ?? '',
        'nombre'            => ($u['nombres'] ?? '') . ' ' . ($u['apellido_paterno'] ?? ''),
    ];
    session_regenerate_id(true);
    echo "  [OK] Sesión iniciada, session_id=" . session_id() . "\n\n";
} catch (Throwable $e) {
    echo "  [FAIL] Error iniciando sesión: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Paso 7: obtener rol
echo "[PASO 7] Obtener rol del usuario\n";
try {
    $rolStmt = $pdo->prepare(
        "SELECT TOP 1 pr.nombre AS rol
         FROM persona_rol_asignacion pra
         INNER JOIN persona_rol pr ON pra.id_rol = pr.id_rol
         WHERE pra.id_persona = ?
         ORDER BY pra.fecha_asignacion DESC"
    );
    $rolStmt->execute([(int)$u['id_persona']]);
    $rolRow = $rolStmt->fetch();
    $rol = $rolRow ? $rolRow['rol'] : '(sin rol)';
    echo "  [OK] Rol: $rol\n\n";
} catch (Throwable $e) {
    echo "  [FAIL] Error obteniendo rol: " . $e->getMessage() . "\n\n";
}

// Paso 8: auditoría
echo "[PASO 8] Registrar auditoría\n";
try {
    Audit::log('AUTH', 'LOGIN_EXITOSO', 'Login debug exitoso',
        'persona', (string)$u['id_persona'],
        ['correo' => $u['correo']], 'EXITO',
        ['id_persona' => $u['id_persona'], 'correo' => $u['correo']]);
    echo "  [OK] Auditoría registrada\n\n";
} catch (Throwable $e) {
    echo "  [WARN] Auditoría falló (no es crítico): " . $e->getMessage() . "\n\n";
}

echo "================================================================\n";
echo "  LOGIN SIMULADO OK — TODOS LOS PASOS PASARON\n";
echo "================================================================\n";
echo "\n";
echo "Si este script pasó todos los pasos pero /api/v1/auth/login devuelve\n";
echo "502, el problema NO es de BD ni de credenciales. Es de IIS:\n";
echo "\n";
echo "Posibles causas IIS:\n";
echo "  1. App Pool detenido → IIS Manager → reinicia el pool\n";
echo "  2. PHP crashed → iisreset\n";
echo "  3. URL Rewrite Module mal configurado → revisa web.config\n";
echo "  4. Memoria insuficiente en el worker process\n";
echo "  5. Permiso NTFS: el app pool no puede leer algún archivo\n";
echo "\n";
echo "Diagnóstico IIS:\n";
echo "  - Abre Event Viewer → Windows Logs → Application\n";
echo "    Busca errores con origen 'IIS W3SVC' o 'PHP'\n";
echo "  - Revisa C:\\inetpub\\logs\\LogFiles\\W3SVC1\\u_ex*.log\n";
echo "    Busca la línea con tu request 502\n";
echo "    El campo sc-win32-status y sc-substatus dirán la causa\n";
echo "\n";
echo "Sub-códigos 502 comunes:\n";
echo "  502.2 - Bad Gateway: script error (PHP error)\n";
echo "  502.3 - Bad Gateway: connection error con FastCGI\n";
echo "  502.5 - Bad Gateway: PHP process crash\n";
echo "  502.7 - Bad Gateway: FastCGI timeout\n";
echo "\n";
echo "*** BORRA ESTE ARCHIVO DESPUÉS DE PROBAR ***\n";
