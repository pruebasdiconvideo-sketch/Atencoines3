<?php
/**
 * api/routes/dashboard.php
 *
 * Endpoints de dashboard y KPIs.
 *   GET /dashboard/kpis                  - KPIs principales (ultimo mes)
 *   GET /dashboard/series?tipo=X          - Series para graficas
 *   GET /dashboard/saved-reports         - Reportes guardados del usuario
 *   POST /dashboard/saved-reports        - Guarda un reporte
 */

Auth::requirePermission('DASHBOARD', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;

/* ---- GET /dashboard/kpis ----
 * Acepta tanto ?desde=&hasta= como ?fecha_desde=&fecha_hasta= para flexibilidad.
 */
if ($method === 'GET' && $id === 'kpis') {
    $fecha_desde = $_GET['desde'] ?? $_GET['fecha_desde'] ?? null;
    $fecha_hasta = $_GET['hasta'] ?? $_GET['fecha_hasta'] ?? null;
    $rows = Database::callSP('sp_dashboard_kpis', [$fecha_desde, $fecha_hasta]);
    ApiResponse::success($rows[0] ?? [], 200);
}

/* ---- GET /dashboard/series ----
 * Acepta tanto ?desde=&hasta= como ?fecha_desde=&fecha_hasta= para flexibilidad.
 */
if ($method === 'GET' && $id === 'series') {
    $tipo = $_GET['tipo'] ?? '';
    $allowed = ['TICKETS_POR_DIA','TICKETS_POR_ESTADO','TICKETS_POR_PRIORIDAD',
                'TICKETS_POR_EMPRESA','ATENCIONES_POR_TECNICO','TIEMPOS_POR_DIA'];
    if (!in_array($tipo, $allowed, true)) {
        ApiResponse::validationError(['tipo' => 'Tipo de serie invalido']);
    }
    $fecha_desde = $_GET['desde'] ?? $_GET['fecha_desde'] ?? null;
    $fecha_hasta = $_GET['hasta'] ?? $_GET['fecha_hasta'] ?? null;
    $rows = Database::callSP('sp_dashboard_series', [
        $tipo, $fecha_desde, $fecha_hasta
    ]);
    ApiResponse::success($rows, 200);
}

/* ---- GET /dashboard/saved-reports ---- */
if ($method === 'GET' && $id === 'saved-reports') {
    $pdo  = Database::connection();
    $stmt = $pdo->prepare(
        "SELECT id_report, nombre, descripcion, tipo, modulo, config_json,
                CONVERT(VARCHAR(19), fecha_creacion, 120) AS fecha,
                CONVERT(VARCHAR(19), ultima_ejecucion, 120) AS ultima_ejecucion
         FROM saved_report WITH (NOLOCK)
         WHERE id_usuario = ? OR es_publico = 1
         ORDER BY fecha_creacion DESC");
    $stmt->execute([$user['id_persona']]);
    ApiResponse::success($stmt->fetchAll(), 200);
}

/* ---- POST /dashboard/saved-reports ---- */
if ($method === 'POST' && $id === 'saved-reports') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nombre']) || empty($body['config_json'])) {
        ApiResponse::validationError(['nombre' => 'Requerido', 'config_json' => 'Requerido']);
    }
    $pdo  = Database::connection();
    $stmt = $pdo->prepare(
        "INSERT INTO saved_report (id_usuario, nombre, descripcion, tipo, modulo, config_json, es_publico)
         OUTPUT INSERTED.id_report
         VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $user['id_persona'],
        substr($body['nombre'], 0, 100),
        substr($body['descripcion'] ?? '', 0, 500),
        $body['tipo'] ?? 'CUSTOM',
        $body['modulo'] ?? null,
        $body['config_json'],
        (int)($body['es_publico'] ?? 0),
    ]);
    $newId = $stmt->fetchColumn();
    ApiResponse::success(['id_report' => (int)$newId], 201, 'Reporte guardado');
}

ApiResponse::error('not_found', 'Sub-recurso de dashboard no encontrado.', 404);
