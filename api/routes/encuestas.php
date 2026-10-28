<?php
/**
 * api/routes/encuestas.php
 *
 * Endpoints de encuestas de satisfaccion.
 *   GET  /encuestas/{id_ticket}            - Obtiene encuesta pendiente
 *   POST /encuestas/{id_ticket}/responder  - Guarda respuestas
 *   GET  /encuestas/reporte                - Reporte de satisfaccion (permisos)
 */

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

/* ---- GET /encuestas/reporte ---- */
if ($method === 'GET' && $id === 'reporte') {
    Auth::requirePermission('ENCUESTAS', 'ver');
    $rows = Database::callSP('sp_encuesta_reporte', [
        $_GET['desde'] ?? null, $_GET['hasta'] ?? null
    ]);
    ApiResponse::success($rows, 200);
}

/* ---- GET /encuestas/{id_ticket} ---- */
if ($method === 'GET' && $id !== null && $action === null) {
    // Cualquier usuario puede ver la encuesta de su ticket
    $rows = Database::callSP('sp_encuesta_obtener', [(int)$id]);
    // Primer result set: disponibilidad + metadatos
    $info = $rows[0] ?? null;
    if (!$info || (int)$info['disponible'] !== 1) {
        ApiResponse::success(['disponible' => false, 'mensaje' => $info['mensaje'] ?? 'No disponible'], 200);
    }
    // Segundo result set: preguntas
    $stmt = Database::connection()->query(
        "SELECT ep.id_pregunta, ep.texto, ep.tipo, ep.opciones_json, ep.obligatoria, ep.orden
         FROM encuesta_pregunta ep
         INNER JOIN encuesta e ON ep.id_encuesta = e.id_encuesta
         WHERE e.activa = 1 ORDER BY ep.orden");
    ApiResponse::success([
        'disponible'  => true,
        'id_encuesta' => (int)$info['id_encuesta'],
        'nombre'      => $info['nombre'],
        'preguntas'   => $stmt->fetchAll(),
    ], 200);
}

/* ---- POST /encuestas/{id_ticket}/responder ---- */
if ($method === 'POST' && $action === 'responder') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['respuestas']) || !is_array($body['respuestas'])) {
        ApiResponse::validationError(['respuestas' => 'Array requerido']);
    }
    $resp = json_encode($body['respuestas'], JSON_UNESCAPED_UNICODE);

    try {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare("EXEC sp_encuesta_responder ?, ?, ?");
        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(2, $user['id_persona'], PDO::PARAM_INT);
        $stmt->bindValue(3, $resp, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();
        ApiResponse::success($row, 201, 'Encuesta enviada. Gracias por su feedback.');
    } catch (Throwable $e) {
        ApiResponse::error('survey_failed', $e->getMessage(), 500);
    }
}

ApiResponse::error('not_found', 'Sub-recurso de encuestas no encontrado.', 404);
