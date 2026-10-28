<?php
/**
 * includes/auth.php
 *
 * Autenticacion dual para el sistema:
 *   - Sesion web (PHP session para usuarios internos)
 *   - Bearer Token (API tokens almacenados en api_token, hash SHA-512)
 *
 * Tambien implementa validacion de permisos por modulo usando
 * role_permissions + modulo_catalogo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';

final class Auth
{
    /** Datos del usuario autenticado en el request actual */
    private static ?array $currentUser = null;
    private static ?array $currentPermissions = null;

    /**
     * Autentica con Bearer Token (Authorization: Bearer msa_xxx).
     * Llama a sp_api_token_validar que actualiza ultimo_uso y devuelve datos.
     */
    public static function authenticateToken(): ?array
    {
        $headers = self::getHeaders();
        $auth = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(msa_[a-f0-9]{67})$/i', $auth, $m)) {
            return null;
        }
        $token = $m[1];

        try {
            $rows = Database::callSP('sp_api_token_validar', [$token]);
            if (empty($rows) || !$rows[0]['activo']) {
                return null;
            }
            // Expirado?
            if (!empty($rows[0]['fecha_expiracion']) && strtotime($rows[0]['fecha_expiracion']) < time()) {
                return null;
            }
            self::$currentUser = [
                'id_persona' => (int)$rows[0]['id_usuario'],
                'scopes'     => $rows[0]['scopes'] ? json_decode($rows[0]['scopes'], true) : [],
                'auth_method'=> 'api_token',
            ];
            return self::$currentUser;
        } catch (Throwable $e) {
            error_log('[Auth] token error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verifica sesion web (usuarios internos). Debe llamarse despues de
     * validar usuario/password. Retorna el usuario o null.
     */
    public static function requireSession(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            startAppSession();
        }

        if (empty($_SESSION['user']['id_persona'])) {
            ApiResponse::error('unauthorized', 'Sesion expirada o no autenticada.', 401);
        }

        self::$currentUser = $_SESSION['user'];
        return self::$currentUser;
    }

    /**
     * Verifica un permiso especifico para el modulo actual.
     * Permisos posibles: ver, registrar, modificar, eliminar, aprobar, exportar, imprimir.
     *
     * EXCEPCION ADMIN: el usuario con correo admin@soporte.com tiene bypass total
     * para evitar bloqueos durante configuracion inicial.
     *
     * OPTIMIZACIÓN: los permisos se cachean en $_SESSION por 5 minutos para
     * evitar una consulta a sp_persona_rol_get + SELECT role_permissions en
     * CADA request. Con 5000 usuarios concurrentes, esto reduce ~2 queries por
     * request = ~10.000 queries/segundo menos sobre el servidor SQL.
     *
     * @throws RuntimeException si no tiene el permiso
     */
    public static function requirePermission(string $moduleCode, string $permission): void
    {
        $user = self::$currentUser ?? self::requireSession();

        // BYPASS para admin@soporte.com (super admin del sistema)
        if (self::isSuperAdmin($user['id_persona'])) {
            return;
        }

        if (self::$currentPermissions === null) {
            // Intentar cargar permisos desde la sesión (cache TTL 5 min)
            $cacheKey = 'perms_cache_' . ($user['id_persona'] ?? 0);
            $cacheTsKey = $cacheKey . '_ts';
            $cacheTtl = 300; // 5 minutos

            if (session_status() === PHP_SESSION_ACTIVE
                && !empty($_SESSION[$cacheKey])
                && !empty($_SESSION[$cacheTsKey])
                && (time() - (int)$_SESSION[$cacheTsKey]) < $cacheTtl) {
                // HIT: usar permisos cacheados en sesión
                self::$currentPermissions = $_SESSION[$cacheKey];
            } else {
                // MISS: consultar BD y cachear
                $roleId = self::getRoleId($user['id_persona']);

                // Si no tiene rol asignado, denegar
                if ($roleId === 0) {
                    ApiResponse::error('forbidden', "Usuario sin rol asignado.", 403);
                }

                // Cargar permisos del rol
                try {
                    $stmt = Database::connection()->query("
                        SELECT modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir
                        FROM role_permissions WHERE id_rol = " . (int)$roleId);
                    self::$currentPermissions = [];
                    while ($row = $stmt->fetch()) {
                        self::$currentPermissions[$row['modulo']] = $row;
                    }
                    // Cachear en sesión por 5 minutos
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $_SESSION[$cacheKey]    = self::$currentPermissions;
                        $_SESSION[$cacheTsKey]  = time();
                    }
                } catch (Throwable $e) {
                    error_log('[Auth] error cargando permisos: ' . $e->getMessage());
                    ApiResponse::error('forbidden', "Error al cargar permisos.", 403);
                }
            }
        }

        $mod = self::$currentPermissions[$moduleCode] ?? null;
        if (!$mod || (int)$mod[$permission] !== 1) {
            ApiResponse::error(
                'forbidden',
                "Permiso '$permission' sobre modulo '$moduleCode' denegado.",
                403
            );
        }
    }

    /** Permite una operación si el usuario tiene cualquiera de los permisos indicados. */
    public static function requireAnyPermission(array $requirements): void
    {
        $user = self::$currentUser ?? self::requireSession();
        if (self::isSuperAdmin((int)$user['id_persona'])) return;

        $allowedPermissions = ['ver', 'registrar', 'modificar', 'eliminar', 'aprobar', 'exportar', 'imprimir'];
        try {
            $roleId = self::getRoleId((int)$user['id_persona']);
            if ($roleId > 0) {
                $pdo = Database::connection();
                foreach ($requirements as $requirement) {
                    if (!is_array($requirement) || count($requirement) !== 2) continue;
                    [$moduleCode, $permission] = $requirement;
                    if (!preg_match('/^[A-Z0-9_]+$/', (string)$moduleCode)
                        || !in_array($permission, $allowedPermissions, true)) continue;
                    $stmt = $pdo->prepare("SELECT $permission FROM role_permissions WHERE id_rol = ? AND modulo = ?");
                    $stmt->execute([$roleId, $moduleCode]);
                    if ((int)$stmt->fetchColumn() === 1) return;
                }
            }
        } catch (Throwable $e) {
            error_log('[Auth] error comprobando permisos alternativos: ' . $e->getMessage());
        }

        ApiResponse::error('forbidden', 'No tiene permiso para consultar este catalogo.', 403);
    }

    /**
     * Verifica si el usuario es super admin (admin@soporte.com).
     * Estos usuarios tienen bypass total de permisos.
     */
    private static function isSuperAdmin(int $idPersona): bool
    {
        // Cache simple en variable estatica para no consultar BD cada vez
        static $cache = [];
        if (isset($cache[$idPersona])) return $cache[$idPersona];

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare("SELECT correo FROM persona WITH (NOLOCK) WHERE id_persona = ?");
            $stmt->execute([$idPersona]);
            $correo = strtolower(trim($stmt->fetchColumn() ?? ''));

            // Lista de correos con bypass total
            $superAdmins = [
                'admin@soporte.com',
                'admin@atenciones.local',
                'admin@midominio.com',
            ];

            $isSuper = in_array($correo, $superAdmins, true);
            $cache[$idPersona] = $isSuper;
            return $isSuper;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Obtiene el id_rol principal del usuario */
    private static function getRoleId(int $idPersona): int
    {
        $rows = Database::callSP('sp_persona_rol_get', [$idPersona]);
        return !empty($rows) ? (int)$rows[0]['id_rol'] : 0;
    }

    /** Cabeceras normalizadas (case-insensitive) */
    private static function getHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return array_change_key_case(getallheaders(), CASE_UPPER);
        }
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = str_replace('_', '-', substr($k, 5));
                $out[strtoupper($name)] = $v;
            }
        }
        return $out;
    }

    /** Rate limiting simple por token + IP usando cache de archivo */
    public static function rateLimit(int $idToken, int $limitPerMinute = 120): void
    {
        $file = sys_get_temp_dir() . "/rl_{$idToken}_" . date('YmdHi');
        $count = is_file($file) ? (int)file_get_contents($file) : 0;
        if ($count >= $limitPerMinute) {
            header('Retry-After: 60');
            ApiResponse::error('rate_limited', 'Demasiadas solicitudes. Intente mas tarde.', 429);
        }
        file_put_contents($file, $count + 1);
    }

    /** Verifica permiso sin lanzar exception (para condicionales) */
    public static function hasPermission(string $moduleCode, string $permission): bool
    {
        try {
            self::requirePermission($moduleCode, $permission);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Devuelve el usuario autenticado actual */
    public static function currentUser(): ?array
    {
        return self::$currentUser;
    }
}
