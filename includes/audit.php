<?php
/**
 * includes/audit.php
 *
 * Helper para registrar acciones de usuario con IP, hostname y máquina.
 * Se usa en cada operación importante del sistema (crear, editar, eliminar,
 * cambiar estado, login, logout, etc.) para mantener auditoría completa.
 *
 * Tabla: dbo.auditoria_accion (ver scripts_sql/18_auditoria_acciones_usuario.sql)
 *
 * Uso:
 *   Audit::log('TICKETS', 'CREAR', 'Ticket 2026-00001 creado', 'ticket', '123');
 *   Audit::log('EMPRESAS', 'EDITAR', 'Empresa "Cafe Andino" modificada', 'empresa', '5');
 *   Audit::log('AUTH', 'LOGIN_EXITOSO', 'Inicio de sesión correcto', resultado: 'EXITO');
 *   Audit::log('AUTH', 'LOGIN_FALLIDO', 'Contraseña incorrecta', resultado: 'ERROR');
 *   Audit::log('TICKETS', 'ELIMINAR', 'Ticket eliminado (admin)', 'ticket', '45', resultado: 'EXITO');
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class Audit
{
    /**
     * Registra una acción de usuario en la tabla auditoria_accion.
     * NUNCA lanza excepción: si falla, lo loguea pero no rompe la operación principal.
     *
     * @param string      $modulo        Módulo del sistema (TICKETS, EMPRESAS, AUTH, etc.)
     * @param string      $accion        Acción realizada (CREAR, EDITAR, ELIMINAR, LOGIN_EXITOSO, etc.)
     * @param string|null $descripcion   Descripción legible de la acción
     * @param string|null $tabla         Tabla afectada (opcional)
     * @param string|null $pk            PK del registro afectado (opcional)
     * @param array|null  $datosExtra    Datos adicionales en formato JSON (opcional)
     * @param string      $resultado     EXITO | ERROR | DENEGADO
     * @param array|null  $userOverride  Si se pasa, usa este usuario en vez del actual
     */
    public static function log(
        string $modulo,
        string $accion,
        ?string $descripcion = null,
        ?string $tabla = null,
        ?string $pk = null,
        ?array $datosExtra = null,
        string $resultado = 'EXITO',
        ?array $userOverride = null
    ): void {
        try {
            // Datos del usuario (de sesión o override)
            $user = $userOverride ?? Auth::currentUser() ?? [];
            $idUsuario = (int)($user['id_persona'] ?? 0);
            $correo = (string)($user['correo'] ?? $user['email'] ?? 'anónimo');

            // Detectar IP del cliente (considera proxies)
            $ip = self::getClientIp();
            $hostname = self::getHostname();
            $userAgent = self::getUserAgent();

            // Datos adicionales en JSON
            $datosJson = null;
            if ($datosExtra !== null) {
                $datosJson = json_encode($datosExtra, JSON_UNESCAPED_UNICODE);
            }

            // Llamar al SP que registra la auditoría
            Database::callSP('sp_auditoria_accion_registrar', [
                $idUsuario > 0 ? $idUsuario : null,
                $correo,
                $modulo,
                $accion,
                $descripcion,
                $tabla,
                $pk,
                $ip,
                $hostname,
                $userAgent,
                $datosJson,
                $resultado,
            ]);
        } catch (Throwable $e) {
            // La auditoría NUNCA debe romper la operación principal
            error_log('[Audit] No se pudo registrar acción: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene la IP real del cliente, considerando proxies y load balancers.
     */
    public static function getClientIp(): string
    {
        // Orden de prioridad para detectar IP real detrás de proxies
        $keys = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',   // Proxy estándar
            'HTTP_X_REAL_IP',         // Nginx
            'HTTP_CLIENT_IP',         // Algunos proxies
            'REMOTE_ADDR',            // Directo
        ];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', (string)$_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return 'desconocida';
    }

    /**
     * Obtiene el nombre de la PC del cliente (reverse DNS de la IP).
     * En entornos corporativos puede resolver el hostname real.
     * Si no se puede resolver, devuelve 'no-resolvable'.
     */
    public static function getHostname(): string
    {
        $ip = self::getClientIp();
        if ($ip === 'desconocida' || $ip === '127.0.0.1' || $ip === '::1') {
            // En local, devolver el nombre de la máquina servidor
            return gethostname() ?: 'localhost';
        }
        // Intentar reverse DNS (puede ser lento, así que solo si no es local)
        $host = @gethostbyaddr($ip);
        return $host ?: 'no-resolvable';
    }

    /**
     * Obtiene el User-Agent del navegador del cliente.
     */
    public static function getUserAgent(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return mb_substr($ua, 0, 500);
    }

    /**
     * Devuelve info de la máquina del cliente para mostrar en la UI.
     */
    public static function getClientInfo(): array
    {
        return [
            'ip'         => self::getClientIp(),
            'hostname'   => self::getHostname(),
            'user_agent' => self::getUserAgent(),
        ];
    }
}
