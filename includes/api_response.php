<?php
/**
 * includes/api_response.php
 *
 * Helpers para respuestas JSON estandarizadas y manejo de errores HTTP.
 * Todos los endpoints REST deben usar estas funciones.
 */

declare(strict_types=1);

final class ApiResponse
{
    /** Cabeceras CORS y de seguridad */
    public static function sendHeaders(int $httpCode = 200, array $extra = []): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        // Las respuestas de la API son dinámicas: no cachear por defecto.
        // Las rutas que SÍ son cacheables (catálogos) deben sobrescribir
        // este header con Cache-Control: private, max-age=N.
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        // Politica CSP basica para endpoints API
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        // OPTIMIZACIÓN 5000 usuarios: la compresión gzip se controla vía
        // .user.ini (zlib.output_compression=On) y/o IIS httpCompression en
        // web.config. NO activar aquí con ini_set() porque en IIS FastCGI
        // puede causar "headers already sent" al mezclar con el output buffer.
        foreach ($extra as $k => $v) {
            header("$k: $v");
        }
    }

    /** Respuesta exitosa */
    public static function success($data, int $code = 200, ?string $message = null): void
    {
        self::sendHeaders($code);
        echo json_encode([
            'ok'       => true,
            'data'     => $data,
            'message'  => $message,
            'ts'       => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Respuesta paginada estandar */
    public static function paginated(array $rows, int $total, int $page, int $perPage): void
    {
        self::sendHeaders(200);
        echo json_encode([
            'ok'    => true,
            'data'  => $rows,
            'pagination' => [
                'total'       => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'total_pages'  => (int)ceil($total / max(1, $perPage)),
            ],
            'ts' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Respuesta de error (no exponer detalles internos en produccion) */
    public static function error(string $code, string $message, int $httpCode = 400, array $extra = []): void
    {
        self::sendHeaders($httpCode);
        echo json_encode(array_merge([
            'ok'      => false,
            'error'   => $code,
            'message' => $message,
            'ts'      => gmdate('c'),
        ], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Validacion de entrada - errores 422 */
    public static function validationError(array $errors): void
    {
        self::sendHeaders(422);
        echo json_encode([
            'ok'     => false,
            'error'  => 'validation_failed',
            'errors' => $errors,
            'ts'     => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
