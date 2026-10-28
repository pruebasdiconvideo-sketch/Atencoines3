<?php
/**
 * includes/PermisoHelper.php
 *
 * Helper centralizado de permisos para backend.
 * Pensado para coexistir con includes/auth.php (no lo reemplaza):
 *
 *   - Auth::requirePermission()  -> aborta con 403 si no tiene permiso
 *   - PermisoHelper::puede()     -> bool, para condicionales
 *   - PermisoHelper::cargar()    -> matriz completa para serializar
 *
 * IMPORTANTE: cachea permisos en $_SESSION con TTL de 5 min, igual que
 * Auth. Si Auth::requirePermission ya cargó permisos, los reutiliza.
 *
 * Instalación:
 *   1. Copiar este archivo a /includes/PermisoHelper.php
 *   2. En api/index.php (o donde arranque la app), agregar:
 *         require_once __DIR__ . '/../includes/PermisoHelper.php';
 *   3. Usar en cualquier endpoint:
 *         if (!PermisoHelper::puede('TICKETS', 'eliminar')) {
 *             ApiResponse::error('forbidden', 'Sin permiso', 403);
 *         }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';

final class PermisoHelper
{
    /** @var array<string,array<string,int>>|null  modulo => accion => 0/1 */
    private static ?array $permisos = null;

    /** @var array|null  datos del usuario autenticado */
    private static ?array $usuario = null;

    /** @var bool */
    private static bool $esAdmin = false;

    /** Carga permisos del usuario actual. Idempotente (cachea en sesión). */
    public static function cargar(): void
    {
        if (self::$permisos !== null) return;

        if (session_status() === PHP_SESSION_NONE) {
            startAppSession();
        }

        // Requiere sesión iniciada (Auth::requireSession ya hace esto)
        if (empty($_SESSION['user']['id_persona'])) {
            self::$permisos = [];
            self::$usuario  = null;
            self::$esAdmin  = false;
            return;
        }

        $user = $_SESSION['user'];
        self::$usuario = $user;

        // Bypass para admin (mismo criterio que Auth::isSuperAdmin)
        $correo = strtolower(trim($user['correo'] ?? ''));
        $superAdmins = [
            'admin@soporte.com',
            'admin@atenciones.local',
            'admin@midominio.com',
        ];
        if (in_array($correo, $superAdmins, true)) {
            self::$esAdmin  = true;
            self::$permisos = self::cargarTodosModulosConTodosPermisos();
            return;
        }

        // Cache en sesión (5 min)
        $cacheKey   = 'perms_cache_' . ($user['id_persona'] ?? 0);
        $cacheTsKey = $cacheKey . '_ts';
        $cacheTtl   = 300;

        if (!empty($_SESSION[$cacheKey])
            && !empty($_SESSION[$cacheTsKey])
            && (time() - (int)$_SESSION[$cacheTsKey]) < $cacheTtl) {
            self::$permisos = $_SESSION[$cacheKey];
            return;
        }

        // Consultar BD
        try {
            $rows = Database::callSP('sp_usuario_permisos_get',
                                     [(int)$user['id_persona']]);
            // Result set 1: usuario+rol (lo ignoramos, ya lo tenemos)
            // Result set 2: permisos por módulo
            // PDO sqlsrv: callSP puede devolver ambos resultsets concatenados
            // o solo el último. Verificamos ambos casos.

            $permisos = [];
            foreach ($rows as $r) {
                if (isset($r['modulo_codigo'])) {
                    $permisos[$r['modulo_codigo']] = [
                        'ver'        => (int)($r['puede_ver']       ?? 0),
                        'registrar'  => (int)($r['puede_registrar'] ?? 0),
                        'modificar'  => (int)($r['puede_modificar'] ?? 0),
                        'eliminar'   => (int)($r['puede_eliminar']  ?? 0),
                        'aprobar'    => (int)($r['puede_aprobar']   ?? 0),
                        'exportar'   => (int)($r['puede_exportar']  ?? 0),
                        'imprimir'   => (int)($r['puede_imprimir']  ?? 0),
                    ];
                }
            }

            // Si el SP devolvió ambos resultsets y solo capturamos el primero,
            // hacer fallback a la consulta directa
            if (empty($permisos)) {
                $permisos = self::cargarDirecto((int)$user['id_persona']);
            }

            self::$permisos = $permisos;
            $_SESSION[$cacheKey]   = $permisos;
            $_SESSION[$cacheTsKey] = time();
        } catch (Throwable $e) {
            error_log('[PermisoHelper] error: ' . $e->getMessage());
            self::$permisos = [];
        }
    }

    /** ¿El usuario puede ejecutar la acción sobre el módulo? */
    public static function puede(string $modulo, string $accion): bool
    {
        self::cargar();
        if (self::$esAdmin) return true;
        if (!isset(self::$permisos[$modulo])) return false;
        return ((int)(self::$permisos[$modulo][$accion] ?? 0)) === 1;
    }

    /** ¿Puede ver el módulo? (acceso al menú/ruta) */
    public static function puedeVer(string $modulo): bool
    {
        return self::puede($modulo, 'ver');
    }

    /** ¿Es admin global? */
    public static function esAdmin(): bool
    {
        self::cargar();
        return self::$esAdmin;
    }

    /** Lista de códigos de módulos visibles para el usuario. */
    public static function modulosVisibles(): array
    {
        self::cargar();
        if (self::$esAdmin) return array_keys(self::$permisos);

        $out = [];
        foreach (self::$permisos as $codigo => $acc) {
            if (!empty($acc['ver'])) $out[] = $codigo;
        }
        return $out;
    }

    /** Matriz completa (para enviar al frontend por JSON). */
    public static function toArray(): array
    {
        self::cargar();
        return [
            'es_admin' => self::$esAdmin,
            'usuario'  => self::$usuario,
            'permisos' => self::$permisos,
        ];
    }

    /** Requiere permiso; aborta con 403 JSON si no lo tiene. */
    public static function require(string $modulo, string $accion): void
    {
        if (!self::puede($modulo, $accion)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error'   => 'forbidden',
                'modulo'  => $modulo,
                'accion'  => $accion,
                'mensaje' => 'No tiene permisos para realizar esta accion.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ----- Helpers internos -----

    /** Devuelve todos los módulos con todos los permisos en 1 (para admin). */
    private static function cargarTodosModulosConTodosPermisos(): array
    {
        $out = [];
        try {
            $pdo = Database::connection();
            $stmt = $pdo->query("SELECT codigo FROM dbo.modulo_catalogo WITH (NOLOCK) WHERE activo = 1");
            while ($codigo = $stmt->fetchColumn()) {
                $out[$codigo] = [
                    'ver' => 1, 'registrar' => 1, 'modificar' => 1,
                    'eliminar' => 1, 'aprobar' => 1,
                    'exportar' => 1, 'imprimir' => 1,
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $out;
    }

    /** Fallback: consulta role_permissions directo (sin SP). */
    private static function cargarDirecto(int $idPersona): array
    {
        $permisos = [];
        try {
            $pdo = Database::connection();

            // Rol principal
            $rows = Database::callSP('sp_persona_rol_get', [$idPersona]);
            if (empty($rows)) return [];
            $roleId = (int)($rows[0]['id_rol'] ?? 0);
            if ($roleId <= 0) return [];

            $stmt = $pdo->prepare("SELECT modulo, ver, registrar, modificar,
                                          eliminar, aprobar, exportar, imprimir
                                   FROM dbo.role_permissions
                                   WHERE id_rol = ?");
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
        } catch (Throwable $e) {
            error_log('[PermisoHelper] cargarDirecto error: ' . $e->getMessage());
        }
        return $permisos;
    }
}
