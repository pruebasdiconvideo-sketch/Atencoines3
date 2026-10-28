<?php
/**
 * api/routes/auditoria.php
 *
 * Endpoints de auditoria avanzada.
 *   GET  /auditoria/cambios             - Listado de cambios auditados
 *   GET  /auditoria/cambios/{tabla}/{pk} - Trazabilidad de un registro
 *   GET  /auditoria/accesos             - Auditoria de accesos (login)
 *   GET  /auditoria/operaciones          - Auditoria de operaciones
 */

Auth::requirePermission('AUDITORIA', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$extra  = $segments[2] ?? null;
$extra2 = $segments[3] ?? null;

/* ---- GET /auditoria/cambios ---- */
if ($method === 'GET' && $id === 'cambios' && $extra === null) {
    $rows = Database::callSP('sp_audit_cambio_listar', [
        $_GET['tabla']     ?? null,
        $_GET['pk']        ?? null,
        $_GET['operacion'] ?? null,
        $_GET['id_usuario'] ?? null,
        $_GET['desde']     ?? null,
        $_GET['hasta']     ?? null,
        (int)($_GET['limite'] ?? 200),
    ]);
    ApiResponse::success($rows, 200);
}

/* ---- GET /auditoria/cambios/{tabla}/{pk} ---- */
if ($method === 'GET' && $id === 'cambios' && $extra !== null && $extra2 !== null) {
    $rows = Database::callSP('sp_audit_cambio_listar', [
        $extra, $extra2, null, null, null, null, 500
    ]);
    ApiResponse::success($rows, 200);
}

/* ---- GET /auditoria/accesos ---- */
if ($method === 'GET' && $id === 'accesos') {
    $pdo  = Database::connection();
    $stmt = $pdo->prepare(
        "SELECT TOP 200 id_acceso, correo, fecha_hora, ip_address, estado, intentos_previos, hostname
         FROM acceso_auditoria WITH (NOLOCK)
         ORDER BY id_acceso DESC");
    $stmt->execute();
    ApiResponse::success($stmt->fetchAll(), 200);
}

/* ---- GET /auditoria/operaciones ---- */
if ($method === 'GET' && $id === 'operaciones') {
    $pdo  = Database::connection();
    $stmt = $pdo->prepare(
        "SELECT TOP 200 id_auditoria, usuario, fecha_hora, modulo, operacion,
                LEFT(detalles, 300) AS detalles, ip_address
         FROM auditoria_operacion WITH (NOLOCK)
         ORDER BY id_auditoria DESC");
    $stmt->execute();
    ApiResponse::success($stmt->fetchAll(), 200);
}

ApiResponse::error('not_found', 'Sub-recurso de auditoria no encontrado.', 404);
