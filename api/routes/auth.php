<?php
/**
 * api/routes/auth.php
 *
 * Endpoints de autenticacion:
 *   POST /auth/login     - Login web (correo + password) -> sesion
 *   POST /auth/logout    - Cierra sesion
 *   POST /auth/token      - Genera API token (requiere permiso SEGURIDAD)
 */

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;

if ($method === 'POST' && $id === 'login') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['correo']) || empty($body['password'])) {
        ApiResponse::validationError(['correo' => 'Requerido', 'password' => 'Requerido']);
    }

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT TOP 1 id_persona, correo, nombres, apellido_paterno, apellido_materno,
                    tipo_documento, numero_documento, telefono, sexo,
                    contrasena, bloqueado, estado, activo
             FROM persona WITH (NOLOCK)
             WHERE correo = ? AND activo = 1"
        );
        $stmt->execute([$body['correo']]);
        $u = $stmt->fetch();

        $valid = $u
            ? password_verify($body['password'], $u['contrasena'])
            : password_verify($body['password'], '$2y$10$dummyHashToPreventTimingAttackXXXXXXXXXXXXXXXXXXXXXXXXXX');

        if (!$u || !$valid || !$u['estado'] || $u['bloqueado']) {
            // Auditoría: login fallido con IP y hostname
            Audit::log('AUTH', 'LOGIN_FALLIDO', 'Intento de login fallido para: ' . $body['correo'],
                'persona', null, ['correo' => $body['correo']], 'ERROR',
                ['id_persona' => 0, 'correo' => $body['correo']]);
            ApiResponse::error('invalid_credentials', 'Credenciales invalidas.', 401);
        }

        startAppSession();
        // Obtener el rol del usuario
        $rolStmt = $pdo->prepare("SELECT TOP 1 pr.nombre AS rol
                                  FROM persona_rol_asignacion pra
                                  INNER JOIN persona_rol pr ON pra.id_rol = pr.id_rol
                                  WHERE pra.id_persona = ?
                                  ORDER BY pra.fecha_asignacion DESC");
        $rolStmt->execute([(int)$u['id_persona']]);
        $rolRow = $rolStmt->fetch();
        $rolUsuario = $rolRow ? $rolRow['rol'] : '';

        $_SESSION['user'] = [
            'id_persona'        => (int)$u['id_persona'],
            'correo'            => $u['correo'],
            'nombres'           => $u['nombres'] ?? '',
            'apellido_paterno'  => $u['apellido_paterno'] ?? '',
            'apellido_materno'  => $u['apellido_materno'] ?? '',
            'nombre'            => ($u['nombres'] ?? '') . ' ' . ($u['apellido_paterno'] ?? ''),
            'tipo_documento'   => $u['tipo_documento'] ?? null,
            'numero_documento' => $u['numero_documento'] ?? null,
            'telefono'          => $u['telefono'] ?? null,
            'sexo'              => $u['sexo'] ?? null,
            'rol'               => $rolUsuario,
            'login_at'          => gmdate('c'),
        ];
        session_regenerate_id(true);

        // Auditoría: login exitoso con IP, hostname y user_agent
        Audit::log('AUTH', 'LOGIN_EXITOSO', 'Inicio de sesión correcto',
            'persona', (string)$u['id_persona'],
            ['correo' => $u['correo']], 'EXITO',
            ['id_persona' => $u['id_persona'], 'correo' => $u['correo']]);

        ApiResponse::success([
            'id_persona' => (int)$u['id_persona'],
            'nombre'      => $u['nombres'] . ' ' . $u['apellido_paterno'],
            'correo'       => $u['correo'],
            'session'     => session_id(),
            'client_info' => Audit::getClientInfo(),
        ], 200, 'Login exitoso');
    } catch (Throwable $e) {
        error_log('[auth/login] ' . $e->getMessage());
        ApiResponse::error('server_error', 'Error interno. Intente mas tarde.', 500);
    }
}

if (($method === 'POST' || $method === 'GET') && $id === 'logout') {
    // IMPORTANTE: este endpoint está marcado como "público" en api/index.php,
    // por lo que el bootstrap NO llama a startAppSession() automáticamente.
    // Sin session_start(), session_status() es PHP_SESSION_NONE y
    // session_destroy() NO tiene efecto -> la sesión queda viva y el usuario
    // no puede cerrar sesión. Aquí lo arreglamos llamando startAppSession().
    startAppSession();

    // Auditoría: logout con IP y hostname
    if (!empty($_SESSION['user']['id_persona'])) {
        try {
            Audit::log(
                'AUTH', 'LOGOUT', 'Cierre de sesión',
                'persona', (string)$_SESSION['user']['id_persona'],
                null, 'EXITO',
                [
                    'id_persona' => $_SESSION['user']['id_persona'] ?? 0,
                    'correo'     => $_SESSION['user']['correo']     ?? '',
                ]
            );
        } catch (Throwable $e) {
            error_log('[auth/logout] Audit log falló: ' . $e->getMessage());
        }
    }

    // Vaciar el array $_SESSION
    $_SESSION = [];

    // Borrar la cookie de sesión del navegador (para que el navegador
    // deje de enviarla en la siguiente petición).
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path']     ?? '/',
            $params['domain']   ?? '',
            $params['secure']   ?? false,
            $params['httponly'] ?? true
        );
    }

    // Destruir la sesión del lado del servidor
    session_destroy();

    // Cabeceras anti-caché para que el navegador no sirva una versión cacheada
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Si es GET, redirigir al login; si es POST, devolver JSON
    if ($method === 'GET') {
        header('Location: /login.php?logout=1&t=' . time());
        exit;
    }
    ApiResponse::success(null, 200, 'Sesión cerrada');
}

/* ---- GET /auth/me - Devuelve datos del usuario actual (sesion o token) ---- */
if ($method === 'GET' && $id === 'me') {
    $rows = Database::callSP('sp_usuario_actual', [$user['id_persona']]);
    if (empty($rows)) {
        ApiResponse::error('not_found', 'Usuario no encontrado.', 404);
    }
    ApiResponse::success($rows[0], 200);
}

/* ---- GET /auth/permissions - Matriz de permisos del usuario actual ----
 *  Devuelve un mapa { MODULO: { ver: 1, registrar: 0, ... } } listo para
 *  usar en el frontend. Incluye flag es_super_admin para bypass total.
 *  Es un endpoint PÚBLICO (no requiere permiso específico) porque cualquier
 *  usuario autenticado necesita saber qué puede hacer para que el frontend
 *  muestre/oculte opciones dinámicamente.
 */
if ($method === 'GET' && $id === 'permissions') {
    $user = Auth::currentUser() ?? Auth::requireSession();
    $userCorreo = strtolower($user['correo'] ?? '');
    $esSuperAdmin = in_array($userCorreo, ['admin@soporte.com','admin@atenciones.local','admin@midominio.com'], true);

    if ($esSuperAdmin) {
        // Bypass total: devolver todos los módulos con todos los permisos activos
        $pdo = Database::connection();
        $modulos = $pdo->query("SELECT codigo FROM dbo.modulo_catalogo WITH (NOLOCK) WHERE activo = 1")->fetchAll(PDO::FETCH_COLUMN);
        $permisos = [];
        foreach ($modulos as $codigo) {
            $permisos[$codigo] = ['ver' => 1, 'registrar' => 1, 'modificar' => 1, 'eliminar' => 1, 'aprobar' => 1, 'exportar' => 1, 'imprimir' => 1];
        }
        ApiResponse::success([
            'es_super_admin' => true,
            'permisos' => $permisos,
        ], 200);
        return; // No ejecutar nada más
    }

    // Usuario normal: cargar rol y permisos
    $roleId = 0;
    try {
        $rows = Database::callSP('sp_persona_rol_get', [(int)$user['id_persona']]);
        $roleId = !empty($rows) ? (int)$rows[0]['id_rol'] : 0;
    } catch (Throwable $e) { /* ignorar */ }

    $permisos = [];
    if ($roleId > 0) {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare("SELECT modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir
                                    FROM role_permissions WHERE id_rol = ?");
            $stmt->execute([$roleId]);
            while ($row = $stmt->fetch()) {
                $permisos[$row['modulo']] = [
                    'ver'        => (int)$row['ver'],
                    'registrar'  => (int)$row['registrar'],
                    'modificar'  => (int)$row['modificar'],
                    'eliminar'   => (int)$row['eliminar'],
                    'aprobar'    => (int)$row['aprobar'],
                    'exportar'   => (int)$row['exportar'],
                    'imprimir'   => (int)$row['imprimir'],
                ];
            }
        } catch (Throwable $e) { /* ignorar */ }
    }

    ApiResponse::success([
        'es_super_admin' => false,
        'rol_id' => $roleId,
        'permisos' => $permisos,
    ], 200);
}

/* ---- GET /auth/vapid-public-key - Clave publica VAPID para PWA push ---- */
if ($method === 'GET' && $id === 'vapid-public-key') {
    $path = __DIR__ . '/../../config/vapid.json';
    $vapid = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    ApiResponse::success([
        'publicKey' => $vapid['publicKey'] ?? '',
    ], 200);
}

if ($method === 'POST' && $id === 'token') {
    Auth::requirePermission('SEGURIDAD', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nombre'])) {
        ApiResponse::validationError(['nombre' => 'Nombre descriptivo requerido']);
    }
    $scopes = $body['scopes'] ?? [];
    $dias   = (int)($body['dias_expiracion'] ?? 365);

    try {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare("EXEC sp_api_token_crear ?, ?, ?, ?, ?");
        $stmt->bindValue(1, $user['id_persona'], PDO::PARAM_INT);
        $stmt->bindValue(2, $body['nombre'],     PDO::PARAM_STR);
        $stmt->bindValue(3, json_encode($scopes, JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $stmt->bindValue(4, $dias, PDO::PARAM_INT);
        $token = null;
        $stmt->bindParam(5, $token, PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 128);
        $stmt->execute();
        $row = $stmt->fetch();

        ApiResponse::success([
            'token'    => $row['token'] ?? $token,
            'id_token' => (int)$row['id_token'],
            'scopes'   => $scopes,
            'expira'   => date('c', time() + $dias * 86400),
        ], 201, 'Token creado. Guardelo en lugar seguro - no se volvera a mostrar.');
    } catch (Throwable $e) {
        ApiResponse::error('token_creation_failed', $e->getMessage(), 500);
    }
}

/* ---- POST /auth/password - Cambiar MI contraseña (propio usuario) ----
 *  Requiere contraseña actual + nueva. Verifica con password_verify.
 *  No requiere permisos especiales (cualquier usuario autenticado puede
 *  cambiar su propia contraseña).
 */
if ($method === 'POST' && $id === 'password') {
    $user = Auth::currentUser() ?? Auth::requireSession();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['actual']) || empty($body['nueva'])) {
        ApiResponse::validationError(['actual' => 'Requerido', 'nueva' => 'Requerido']);
    }
    if (strlen($body['nueva']) < 8) {
        ApiResponse::validationError(['nueva' => 'La contraseña debe tener al menos 8 caracteres']);
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare("SELECT contrasena FROM persona WHERE id_persona = ? AND activo = 1");
    $stmt->execute([(int)$user['id_persona']]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($body['actual'], $hash)) {
        Audit::log('AUTH', 'PASSWORD_CHANGE_FAILED', 'Contraseña actual incorrecta',
            'persona', (string)$user['id_persona'], [], 'DENEGADO');
        ApiResponse::error('invalid_credentials', 'La contraseña actual es incorrecta.', 401);
    }

    $newHash = password_hash($body['nueva'], PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE persona SET contrasena = ? WHERE id_persona = ?")
        ->execute([$newHash, (int)$user['id_persona']]);

    Audit::log('AUTH', 'PASSWORD_CHANGE', 'Usuario cambió su propia contraseña',
        'persona', (string)$user['id_persona'], []);

    ApiResponse::success(null, 200, 'Contraseña actualizada correctamente');
}

/* ---- POST /auth/reset-password/{id_persona} - Restablecer contraseña de un trabajador ----
 * Solo administradores pueden restablecer contraseñas de otros trabajadores.
 * El trabajador afectado deberá cambiar su contraseña al siguiente login.
 */
if ($method === 'POST' && $id === 'reset-password') {
    $targetId = $segments[2] ?? null;
    if (!$targetId) ApiResponse::validationError(['id_persona' => 'Requerido']);

    // Verificar que el usuario actual sea administrador
    $user = Auth::currentUser() ?? Auth::requireSession();
    $userCorreo = strtolower($user['correo'] ?? '');
    $userRol = strtoupper($user['rol'] ?? '');
    $esAdmin = $userRol === 'ADMINISTRADOR' || $userRol === 'ADMIN' ||
               in_array($userCorreo, ['admin@soporte.com', 'admin@atenciones.local', 'admin@midominio.com'], true);

    if (!$esAdmin) {
        Audit::log('AUTH', 'RESET_PASSWORD_DENIED', 'Intento denegado de restablecer contraseña de persona #' . $targetId,
            'persona', (string)$targetId, ['solicitado_por' => $userCorreo], 'DENEGADO');
        ApiResponse::error('forbidden', 'Solo los administradores pueden restablecer contraseñas.', 403);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $newPassword = trim((string)($body['nueva_contrasena'] ?? ''));
    if (strlen($newPassword) < 4) {
        ApiResponse::validationError(['nueva_contrasena' => 'Mínimo 4 caracteres']);
    }

    $pdo = Database::connection();
    // Verificar que la persona existe y no es contacto de empresa
    $check = $pdo->prepare("SELECT p.id_persona, p.correo, p.nombres, p.apellido_paterno
                            FROM persona p
                            WHERE p.id_persona = ? AND p.activo = 1
                              AND NOT EXISTS (SELECT 1 FROM empresa_contacto ec WHERE ec.id_persona = p.id_persona)");
    $check->execute([(int)$targetId]);
    $target = $check->fetch();
    if (!$target) {
        ApiResponse::error('not_found', 'Trabajador no encontrado o es contacto de empresa.', 404);
    }

    // Hashear nueva contraseña
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);

    // Actualizar contraseña y desbloquear al usuario
    $pdo->prepare("UPDATE persona SET contrasena = ?, bloqueado = 0 WHERE id_persona = ?")
        ->execute([$hash, (int)$targetId]);

    // Auditoría
    Audit::log('AUTH', 'RESET_PASSWORD', 'Contraseña restablecida para: ' . $target['correo'],
        'persona', (string)$targetId,
        ['restablecido_por' => $userCorreo, 'correo_trabajador' => $target['correo']]);

    ApiResponse::success(null, 200, 'Contraseña restablecida para ' . $target['nombres'] . ' ' . $target['apellido_paterno']);
}

ApiResponse::error('not_found', 'Sub-recurso de auth no encontrado.', 404);
