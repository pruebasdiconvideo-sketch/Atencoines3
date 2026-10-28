<?php
/**
 * config/database.php v6.3 — ENTORNO DISTRIBUIDO + PUERTO FIJO 1435
 *
 * ESCENARIO:
 *   - Servidor A (APP): IIS + PHP + Atenciones
 *   - Servidor B (BD): 192.168.100.1 con varias instancias SQL Server
 *     La instancia con MonosyncERP usa el PUERTO FIJO 1435.
 *
 * CONFIGURACIÓN APLICADA:
 *   DB_HOST = '192.168.100.1'    (sin instancia, IP directa)
 *   DB_PORT = 1435               (puerto fijo de la instancia correcta)
 *
 * Esta es la configuración RECOMENDADA para producción distribuida
 * cuando hay varias instancias SQL en el mismo servidor, porque:
 *
 *   1. NO depende del SQL Server Browser Service (UDP 1434).
 *   2. NO requiere abrir el puerto UDP 1434 en el firewall.
 *   3. Es predecible: el driver sqlsrv va directo al TCP 1435.
 *   4. Funciona a través de firewalls/NAT sin problemas.
 *
 * Si en el futuro cambias de instancia, solo actualiza DB_PORT.
 */

declare(strict_types=1);

final class Database
{
    /* ============================================================
     * CREDENCIALES — SERVIDOR REMOTO (192.168.100.1:1435)
     * ============================================================
     * Host:     IP del servidor de BD (sin instancia, porque el
     *           puerto es fijo y conocido).
     * Port:     1435 — puerto fijo de la instancia que tiene
     *           MonosyncERP (hay varias instancias en este server).
     * ============================================================
     */
    private const DB_HOST    = 'DESKTOP-FOOD01U\\SQL2025';
    private const DB_PORT    = 1435;
    private const DB_NAME    = 'MonosyncERP';
    private const DB_USER    = 'sa';
    private const DB_PASS    = '123';
    private const DB_TIMEOUT = 15;

    /** Conexión cacheada POR REQUEST PHP. */
    private static ?PDO $pool = null;

    /** Ultimo error de conexion (para diagnostico) */
    private static ?string $lastError = null;

    /** Devuelve una conexion PDO a SQL Server. Lanza excepcion si falla. */
    public static function connection(): PDO
    {
        if (self::$pool !== null) {
            return self::$pool;
        }

        // DSN con IP + puerto fijo. NO usamos instancia con nombre porque
        // ya sabemos el puerto (1435) y evitamos la dependencia del
        // SQL Server Browser Service.
        $dsn = sprintf(
            'sqlsrv:Server=%s,%d;Database=%s;Encrypt=no;TrustServerCertificate=yes;LoginTimeout=%d;MultipleActiveResultSets=true;ConnectionPooling=true',
            self::DB_HOST,
            self::DB_PORT,
            self::DB_NAME,
            self::DB_TIMEOUT
        );

        try {
            self::$pool = new PDO($dsn, self::DB_USER, self::DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
                PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 30,
            ]);
            self::$lastError = null;
            return self::$pool;

        } catch (PDOException $e) {
            self::$lastError = $e->getMessage();
            error_log('[DB] Conexion fallida (intento 1 con MARS): ' . $e->getMessage());

            // Fallback 1: sin MARS (algunas instancias remotas lo tienen deshabilitado)
            try {
                $dsnFallback = str_replace('MultipleActiveResultSets=true;', '', $dsn);
                self::$pool = new PDO($dsnFallback, self::DB_USER, self::DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
                    PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 30,
                ]);
                self::$lastError = null;
                error_log('[DB] Conexion OK en fallback sin MARS.');
                return self::$pool;
            } catch (PDOException $e2) {
                error_log('[DB] Conexion fallida (fallback sin MARS): ' . $e2->getMessage());

                // Fallback 2: sin ConnectionPooling (a veces el pool cachea
                // conexiones rotas con el server remoto)
                try {
                    $dsnFallback2 = str_replace(
                        ['MultipleActiveResultSets=true;', 'ConnectionPooling=true;'],
                        ['', 'ConnectionPooling=false;'],
                        $dsn
                    );
                    self::$pool = new PDO($dsnFallback2, self::DB_USER, self::DB_PASS, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
                        PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 30,
                    ]);
                    self::$lastError = null;
                    error_log('[DB] Conexion OK en fallback sin pooling.');
                    return self::$pool;
                } catch (PDOException $e3) {
                    self::$lastError = $e3->getMessage();
                    error_log('[DB] Conexion fallida (fallback sin pooling): ' . $e3->getMessage());
                    throw $e3;
                }
            }
        }
    }

    /**
     * Ejecuta un stored procedure con parametros y devuelve el primer result set.
     */
    public static function callSP(string $spName, array $params = []): array
    {
        $pdo  = self::connection();
        $placeholders = implode(',', array_fill(0, count($params), '?'));
        $stmt = $pdo->prepare("EXEC $spName $placeholders");
        foreach (array_values($params) as $i => $v) {
            $stmt->bindValue($i + 1, $v, self::inferType($v));
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Verifica si una columna existe en una tabla — con CACHE estático por request.
     */
    public static function columnExists(string $table, string $column): bool
    {
        $tableNorm = str_ireplace('dbo.', '', $table);
        if (isset(self::$columnCache[$tableNorm][$column])) {
            return self::$columnCache[$tableNorm][$column];
        }
        try {
            $pdo = self::connection();
            if (!isset(self::$columnCache[$tableNorm])) {
                $stmt = $pdo->prepare(
                    "SELECT name FROM sys.columns WHERE object_id = OBJECT_ID(N'dbo." . $tableNorm . "')"
                );
                $stmt->execute();
                self::$columnCache[$tableNorm] = [];
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $colName) {
                    self::$columnCache[$tableNorm][strtolower($colName)] = true;
                }
            }
            return isset(self::$columnCache[$tableNorm][strtolower($column)]);
        } catch (Throwable $e) {
            error_log('[DB] columnExists falló para ' . $tableNorm . '.' . $column . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica si un parámetro existe en un stored procedure — con CACHE estático.
     */
    public static function spParamExists(string $spName, string $paramName): bool
    {
        $key = $spName . '::' . $paramName;
        if (isset(self::$spParamCache[$key])) {
            return self::$spParamCache[$key];
        }
        try {
            $pdo = self::connection();
            $stmt = $pdo->prepare(
                "SELECT name FROM sys.parameters WHERE object_id = OBJECT_ID(N'dbo." . $spName . "')"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $p) {
                self::$spParamCache[$spName . '::' . $p] = true;
            }
            return isset(self::$spParamCache[$key]);
        } catch (Throwable $e) {
            error_log('[DB] spParamExists falló para ' . $key . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Caché de columnas por request */
    private static array $columnCache = [];

    /** Caché de parámetros de SP por request */
    private static array $spParamCache = [];

    /** Tipado de parametro PDO basado en el valor PHP */
    private static function inferType($v): int
    {
        if (is_int($v))   return PDO::PARAM_INT;
        if (is_bool($v))  return PDO::PARAM_BOOL;
        if (is_null($v))  return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }

    /** Genera un trace_id unico para logs */
    public static function traceId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** Devuelve el ultimo error de conexion (para diagnostico) */
    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /* ============================================================
     * MÉTODOS DE DIAGNÓSTICO
     * ============================================================ */

    public static function getDsn(): string
    {
        return sprintf(
            'sqlsrv:Server=%s,%d;Database=%s;Encrypt=no;TrustServerCertificate=yes',
            self::DB_HOST, self::DB_PORT, self::DB_NAME
        );
    }

    public static function getConfigInfo(): array
    {
        return [
            'host'     => self::DB_HOST,
            'port'     => self::DB_PORT,
            'database' => self::DB_NAME,
            'user'     => self::DB_USER,
            'timeout'  => self::DB_TIMEOUT,
            'dsn'      => self::getDsn(),
            'php_ver'  => PHP_VERSION,
            'server_hostname' => gethostname() ?: 'unknown',
            'server_ip_local' => $_SERVER['SERVER_ADDR'] ?? php_uname('n'),
            'pdo_sqlsrv' => extension_loaded('pdo_sqlsrv') ? phpversion('pdo_sqlsrv') : 'NO INSTALADO',
            'sqlsrv'     => extension_loaded('sqlsrv')     ? phpversion('sqlsrv')     : 'NO INSTALADO',
        ];
    }

    public static function test(): array
    {
        $info = [
            'success'  => false,
            'config'   => self::getConfigInfo(),
            'error'    => null,
            'server_info' => null,
            'query_test'  => null,
        ];

        try {
            $pdo = self::connection();
            $info['success'] = true;
            $info['server_info'] = $pdo->getAttribute(PDO::ATTR_SERVER_INFO)
                                  ?: $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $stmt = $pdo->query('SELECT @@VERSION AS version, DB_NAME() AS db, SUSER_NAME() AS user_name, @@SERVERNAME AS server_name');
            $info['query_test'] = $stmt->fetch();
        } catch (Throwable $e) {
            $info['error'] = $e->getMessage();
        }

        return $info;
    }
}
