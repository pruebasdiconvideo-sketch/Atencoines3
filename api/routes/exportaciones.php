<?php
/**
 * api/routes/exportaciones.php
 *
 * Trabajos de exportacion masiva asincronos.
 *   POST /exportaciones             - Crea un job (devuelve id_job)
 *   GET  /exportaciones             - Lista jobs (propios o de todos si admin)
 *   GET  /exportaciones/{id}        - Estado de un job especifico
 *   GET  /exportaciones/{id}/download - Descarga el archivo generado
 *
 * El procesamiento real lo hace un worker PHP CLI (ver modules/exportacion/worker.php)
 * que se ejecuta en segundo plano via Task Scheduler de Windows.
 */

Auth::requirePermission('REPORTES', 'exportar');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

/* ---- POST /exportaciones ---- */
if ($method === 'POST' && $id === null) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['modulo']) || empty($body['formato'])) {
        ApiResponse::validationError(['modulo' => 'Requerido', 'formato' => 'Requerido']);
    }
    $allowedFormats = ['XLSX','CSV','PDF','JSON'];
    if (!in_array(strtoupper($body['formato']), $allowedFormats, true)) {
        ApiResponse::validationError(['formato' => 'Formato invalido. Use: ' . implode(',', $allowedFormats)]);
    }
    $filtros = isset($body['filtros']) ? json_encode($body['filtros'], JSON_UNESCAPED_UNICODE) : null;
    $rows = Database::callSP('sp_exportacion_crear', [
        $user['id_persona'], $body['modulo'], strtoupper($body['formato']), $filtros
    ]);
    $idJob = (int)($rows[0]['id_job'] ?? 0);
    ApiResponse::success(['id_job' => $idJob, 'estado' => 'PENDIENTE'], 202, 'Job de exportacion creado');
}

/* ---- GET /exportaciones ---- */
if ($method === 'GET' && $id === null) {
    $estado = $_GET['estado'] ?? null;
    $rows = Database::callSP('sp_exportacion_listar', [
        // Si es admin, permite ver todos (pasando null)
        Auth::hasPermission('AUDITORIA', 'ver') ? null : $user['id_persona'],
        $estado,
        (int)($_GET['limit'] ?? 50)
    ]);
    ApiResponse::success($rows, 200);
}

/* ---- GET /exportaciones/{id} ---- */
if ($method === 'GET' && $id !== null && $action === null) {
    $rows = Database::callSP('sp_exportacion_listar', [$user['id_persona'], null, 1]);
    $job = null;
    foreach ($rows as $r) {
        if ((int)$r['id_job'] === (int)$id) {
            $job = $r; break;
        }
    }
    if (!$job) {
        ApiResponse::error('not_found', 'Job no encontrado o no le pertenece.', 404);
    }
    ApiResponse::success($job, 200);
}

/* ---- GET /exportaciones/{id}/download ---- */
if ($method === 'GET' && $action === 'download') {
    $rows = Database::callSP('sp_exportacion_listar', [$user['id_persona'], 'COMPLETADO', 500]);
    $job = null;
    foreach ($rows as $r) {
        if ((int)$r['id_job'] === (int)$id) {
            $job = $r; break;
        }
    }
    if (!$job || empty($job['ruta_archivo']) || !is_file($job['ruta_archivo'])) {
        ApiResponse::error('not_found', 'Archivo no disponible.', 404);
    }
    $mime = [
        'XLSX' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'CSV'  => 'text/csv; charset=utf-8',
        'PDF'  => 'application/pdf',
        'JSON' => 'application/json; charset=utf-8',
    ][$job['formato']] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="export_' . $job['id_job'] . '.' . strtolower($job['formato']) . '"');
    header('Content-Length: ' . filesize($job['ruta_archivo']));
    header('Cache-Control: private, no-cache');
    readfile($job['ruta_archivo']);
    exit;
}

ApiResponse::error('not_found', 'Sub-recurso de exportaciones no encontrado.', 404);
