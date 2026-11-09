<?php
/**
 * api/routes/versiones.php
 * ---------------------------------------------------------------------------
 * Endpoints para consultar las versiones instaladas en la base de datos.
 *   GET /versiones                — Lista las versiones instaladas
 *   GET /versiones/actual         — Devuelve la versión más reciente
 *   GET /versiones/{id}           — Detalle de una versión
 *   GET /versiones/por-codigo/X   — Detalle por código de versión
 *
 * Requiere permiso CONFIGURACION:ver (o ser admin).
 * ---------------------------------------------------------------------------
 */

Auth::requirePermission('CONFIGURACION', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

/*============================================================================
  GET /versiones/actual
============================================================================*/
if ($method === 'GET' && $id === 'actual') {
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('EXEC dbo.sp_sistema_version_actual');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // No hay versiones registradas
            ApiResponse::success([
                'data' => [
                    'version_codigo' => '0.0.0',
                    'nombre_release' => 'Sin versiones registradas',
                    'mensaje' => 'Ejecuta el script 00_VERSIONES_TABLA.sql para inicializar el control de versiones',
                ],
            ], 200);
        }

        ApiResponse::success(['data' => $row], 200);
    } catch (Throwable $e) {
        error_log('[Versiones actual] ' . $e->getMessage());
        ApiResponse::error('version_actual_failed',
            'Error al obtener versión actual: ' . $e->getMessage(), 500);
    }
}

/*============================================================================
  GET /versiones/{id}  o  GET /versiones/por-codigo/{codigo}
============================================================================*/
if ($method === 'GET' && $id === 'por-codigo' && $action !== null) {
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('EXEC dbo.sp_sistema_version_detalle @version_codigo = ?');
        $stmt->bindValue(1, $action, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            ApiResponse::error('not_found', 'Versión "' . $action . '" no encontrada', 404);
        }

        // Decodificar JSON para que el frontend los reciba como objetos
        foreach (['scripts_sql_json','archivos_php_json','archivos_js_json','archivos_css_json','cambios_json'] as $k) {
            if (!empty($row[$k])) {
                $decoded = json_decode($row[$k], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row[$k] = $decoded;
                }
            } else {
                $row[$k] = [];
            }
        }

        ApiResponse::success(['data' => $row], 200);
    } catch (Throwable $e) {
        ApiResponse::error('version_detalle_failed',
            'Error: ' . $e->getMessage(), 500);
    }
}

if ($method === 'GET' && is_numeric($id)) {
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('EXEC dbo.sp_sistema_version_detalle @id_version = ?');
        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            ApiResponse::error('not_found', 'Versión #' . $id . ' no encontrada', 404);
        }

        foreach (['scripts_sql_json','archivos_php_json','archivos_js_json','archivos_css_json','cambios_json'] as $k) {
            if (!empty($row[$k])) {
                $decoded = json_decode($row[$k], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row[$k] = $decoded;
                }
            } else {
                $row[$k] = [];
            }
        }

        ApiResponse::success(['data' => $row], 200);
    } catch (Throwable $e) {
        ApiResponse::error('version_detalle_failed',
            'Error: ' . $e->getMessage(), 500);
    }
}

/*============================================================================
  GET /versiones  — Listado
============================================================================*/
if ($method === 'GET' && $id === null) {
    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('EXEC dbo.sp_sistema_version_listar @solo_instaladas = 1, @limite = 100');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::success([
            'data' => $rows,
            'meta' => ['total' => count($rows)],
        ], 200);
    } catch (Throwable $e) {
        error_log('[Versiones list] ' . $e->getMessage());
        ApiResponse::error('version_list_failed',
            'Error al listar versiones: ' . $e->getMessage(), 500);
    }
}

ApiResponse::error('not_found', 'Sub-recurso de versiones no encontrado.', 404);
