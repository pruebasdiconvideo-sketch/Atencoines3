<?php
/**
 * api/routes/reportes.php
 * ----------------------------------------------------------------------------
 * Endpoints de Reportes.
 *   GET /reportes/series?tipo=X  - Series para gráficas de reportes.
 *
 * IMPORTANTE: NO requiere permiso DASHBOARD.ver. Cualquier usuario con
 * permiso REPORTES.ver puede consultar las series. Esto desacopla la vista
 * "Reportes" de la vista "Dashboard" para que usuarios con rol limitado
 * (sin acceso al Dashboard) puedan ver reportes.
 *
 * Reutiliza el mismo SP sp_dashboard_series del backend (que ya filtra
 * eliminado = 0) — solo cambia el permiso requerido.
 * ----------------------------------------------------------------------------
 */

Auth::requirePermission('REPORTES', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;

/* ---- GET /reportes/series ---- */
if ($method === 'GET' && $id === 'series') {
    $tipo = $_GET['tipo'] ?? '';
    $allowed = ['TICKETS_POR_DIA','TICKETS_POR_ESTADO','TICKETS_POR_PRIORIDAD',
                'TICKETS_POR_EMPRESA','ATENCIONES_POR_TECNICO','TIEMPOS_POR_DIA'];
    if (!in_array($tipo, $allowed, true)) {
        ApiResponse::validationError(['tipo' => 'Tipo de serie inválido']);
    }
    $fecha_desde = $_GET['desde'] ?? $_GET['fecha_desde'] ?? null;
    $fecha_hasta = $_GET['hasta'] ?? $_GET['fecha_hasta'] ?? null;
    $rows = Database::callSP('sp_dashboard_series', [
        $tipo, $fecha_desde, $fecha_hasta
    ]);
    ApiResponse::success($rows, 200);
}

ApiResponse::error('not_found', 'Sub-recurso de reportes no encontrado.', 404);
