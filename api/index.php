<?php
/**
 * api/index.php v7 - Router SIMPLIFICADO y robusto
 *
 * Cambios vs version anterior:
 *   - Sin register_shutdown_function (causaba 503 en IIS FastCGI)
 *   - Sin DB logging en el router (se hace en BD via triggers si necesario)
 *   - Manejo de errores simple que no llama a exit en shutdown
 *   - Deteccion de URL multi-fuente (6 fuentes diferentes)
 */

declare(strict_types=1);

// Activar errores en desarrollo (quitar en produccion)
error_reporting(E_ALL);
ini_set('display_errors', '0');   // no mostrar al cliente
ini_set('log_errors', '1');

// Bootstrap
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

// Solo permitir metodos HTTP permitidos
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE','OPTIONS'], true)) {
    ApiResponse::error('method_not_allowed', "Metodo HTTP no soportado: $method", 405);
}

// CORS preflight
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// ============================================================================
// DETECCION DE URL - 6 fuentes diferentes (IIS FastCGI no envia REQUEST_URI)
// ============================================================================
$path = '';

// 1. HTTP_X_REWRITE_URL (URL Rewrite Module activo)
if (!empty($_SERVER['HTTP_X_REWRITE_URL'])) {
    $path = $_SERVER['HTTP_X_REWRITE_URL'];
}
// 2. HTTP_X_ORIGINAL_URL (alternativo)
elseif (!empty($_SERVER['HTTP_X_ORIGINAL_URL'])) {
    $path = $_SERVER['HTTP_X_ORIGINAL_URL'];
}
// 3. _path en query string (formato manual)
elseif (!empty($_GET['_path'])) {
    $path = $_GET['_path'];
}
// 4. REQUEST_URI (estandar CGI, rara vez en IIS)
elseif (!empty($_SERVER['REQUEST_URI'])) {
    $path = $_SERVER['REQUEST_URI'];
}
// 5. PATH_INFO
elseif (!empty($_SERVER['PATH_INFO'])) {
    $path = $_SERVER['PATH_INFO'];
}
// 6. ORIG_PATH_INFO (lo envia IIS, siempre tiene valor)
elseif (!empty($_SERVER['ORIG_PATH_INFO'])) {
    $path = $_SERVER['ORIG_PATH_INFO'];
}

// Limpiar query string
if (($pos = strpos($path, '?')) !== false) {
    $path = substr($path, 0, $pos);
}

// Quitar prefijos conocidos
$path = ltrim($path, '/');
if (strpos($path, 'api/v1/') === 0) {
    $path = substr($path, 7);
} elseif (strpos($path, 'api/') === 0) {
    $path = substr($path, 5);
} elseif (strpos($path, 'v1/') === 0) {
    $path = substr($path, 3);
}
// Quitar index.php/ si esta presente (cuando se usa PATH_INFO)
if (strpos($path, 'index.php/') === 0) {
    $path = substr($path, 10);
} elseif ($path === 'index.php') {
    $path = '';
}

$path = trim($path, '/');
$segments = $path ? explode('/', $path) : [];

// Si no hay segments, devolver info util (no 503)
if (empty($segments)) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'name' => 'Atenciones API v2',
        'version' => '1.0',
        'endpoints' => [
            'POST /api/v1/auth/login' => 'Login web',
            'POST /api/v1/auth/logout' => 'Cerrar sesion',
            'GET  /api/v1/auth/me' => 'Usuario actual',
            'GET  /api/v1/auth/vapid-public-key' => 'Clave VAPID publica',
            'POST /api/v1/auth/token' => 'Crear API token (admin)',
            'GET  /api/v1/dashboard/kpis' => 'KPIs principales',
            'GET  /api/v1/tickets' => 'Listado de tickets',
            'GET  /api/v1/notificaciones' => 'Listar notificaciones',
            'GET  /api/v1/encuestas/{id_ticket}' => 'Obtener encuesta',
            'GET  /api/v1/auditoria/cambios' => 'Auditoria avanzada',
            'POST /api/v1/exportaciones' => 'Crear exportacion',
        ],
        'debug' => [
            'method' => $method,
            'path_detected' => $path,
            'available_vars' => [
                'HTTP_X_REWRITE_URL' => $_SERVER['HTTP_X_REWRITE_URL'] ?? '(vacio)',
                'HTTP_X_ORIGINAL_URL' => $_SERVER['HTTP_X_ORIGINAL_URL'] ?? '(vacio)',
                '_path' => $_GET['_path'] ?? '(vacio)',
                'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '(vacio)',
                'PATH_INFO' => $_SERVER['PATH_INFO'] ?? '(vacio)',
                'ORIG_PATH_INFO' => $_SERVER['ORIG_PATH_INFO'] ?? '(vacio)',
                'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? '(vacio)',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$resource = $segments[0] ?? '';
$id       = $segments[1] ?? null;
$action   = $segments[2] ?? null;

// ============================================================================
// AUTENTICACION - endpoints publicos no requieren auth
// ============================================================================
$isPublicEndpoint = ($resource === 'auth')
    && in_array($id ?? '', ['login', 'logout', 'vapid-public-key'], true);

$user = null;
if (!$isPublicEndpoint) {
    try {
        $user = Auth::authenticateToken();
        if ($user === null) {
            // FIX CRÍTICO: requireSession() retorna el usuario, hay que asignarlo a $user.
            // Antes esto no se hacía, por lo que $user quedaba NULL para usuarios de sesión web,
            // causando que $user['id_persona'] fuera NULL en los endpoints y fallara todo INSERT
            // que usa id_usuario (atenciones, documentos, auditoría, etc.).
            $user = Auth::requireSession();
        }
        if (($user['auth_method'] ?? '') === 'api_token') {
            Auth::rateLimit($user['id_persona']);
        }
    } catch (Throwable $e) {
        error_log('[Auth] error: ' . $e->getMessage());
        ApiResponse::error('auth_failed', 'Error de autenticacion', 401);
    }
}

// ============================================================================
// DISPATCH - cargar el archivo de ruta
// ============================================================================
$routeFile = __DIR__ . "/routes/{$resource}.php";
if (!is_file($routeFile)) {
    ApiResponse::error('not_found', "Recurso '$resource' no existe. Disponibles: auth, dashboard, tickets, notificaciones, encuestas, auditoria, exportaciones", 404);
}

// Las variables $method, $segments, $resource, $id, $action, $user
// estan disponibles en el scope del archivo requerido
try {
    require $routeFile;
} catch (Throwable $e) {
    $traceId = Database::traceId();
    // Log enriquecido: incluye archivo, línea y tipo de excepción para que
    // sea fácil diagnosticar el error mirando el log de PHP/IIS.
    $logLine = sprintf(
        "[%s][API][%s] %s %s: %s [%s in %s:%d]\n",
        gmdate('c'),
        $traceId,
        $method,
        $path,
        $e->getMessage(),
        get_class($e),
        basename($e->getFile() ?: 'unknown'),
        $e->getLine()
    );
    error_log(rtrim($logLine));
    $logFile = __DIR__ . '/../storage/logs/api-errors.log';
    if (!is_dir(dirname($logFile))) {
        @mkdir(dirname($logFile), 0770, true);
    }
    if (is_dir(dirname($logFile))) {
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    ApiResponse::error(
        'internal_error',
        'Error interno del servidor. Referencia: ' . $traceId,
        500,
        ['trace_id' => $traceId]
    );
}

// Si el route file no termino con exit/ApiResponse, devolver 404
ApiResponse::error('not_found', "Endpoint no encontro handler. Method=$method, resource=$resource, id=$id, action=$action", 404);
