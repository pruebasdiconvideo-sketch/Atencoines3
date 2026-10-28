<?php
/**
 * api/routes/atenciones_v2.php
 *
 * REEMPLAZA a api/routes/atenciones.php.
 *
 * Cambios vs versión original:
 *
 * 1. PUT /atenciones/{id} ahora usa sp_atencion_edit (más eficiente).
 * 2. GET /atenciones/{id} ahora devuelve id_ticket_estado y datos extra.
 * 3. Agrega validación de permisos por operación.
 * 4. Auditoría con IP y hostname del cliente.
 *
 * Para activar: copia este archivo a api/routes/atenciones.php
 * (reemplazando el original).
 */

Auth::requirePermission('TICKETS', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

/* ====================================================================
 * GET /atenciones
 * ==================================================================== */
if ($method === 'GET' && $id === null) {
    $pdo = Database::connection();
    $where = "WHERE 1=1";
    $params = [];
    if (!empty($_GET['id_ticket'])) {
        $where .= " AND a.id_ticket = ?";
        $params[] = (int)$_GET['id_ticket'];
    }
    if (!empty($_GET['id_usuario'])) {
        $where .= " AND a.id_usuario = ?";
        $params[] = (int)$_GET['id_usuario'];
    }
    if (!empty($_GET['estado'])) {
        $where .= " AND a.estado = ?";
        $params[] = $_GET['estado'];
    }
    if (!empty($_GET['es_solucionadora'])) {
        $where .= " AND a.es_solucionadora = ?";
        $params[] = (int)$_GET['es_solucionadora'];
    }

    $sql = "SELECT TOP 200 a.id_atencion, a.id_ticket, a.id_usuario,
                   CONVERT(VARCHAR(10), a.fecha_inicio, 23) AS fecha_inicio,
                   CONVERT(VARCHAR(8),  a.hora_inicio, 108) AS hora_inicio,
                   CONVERT(VARCHAR(10), a.fecha_fin, 23)    AS fecha_fin,
                   CONVERT(VARCHAR(8),  a.hora_fin, 108)    AS hora_fin,
                   a.detalle_atencion, a.solucion_aplicada, a.estado,
                   a.es_solucionadora, a.diagnostico, a.acciones_ejecutadas, a.resultado,
                   p.nombres + ' ' + p.apellido_paterno AS tecnico_nombre,
                   at.duracion_minutos, at.duracion_formato,
                   t.nro_ticket, e.razon_social AS empresa_nombre,
                   t.estado AS ticket_estado
            FROM atencion a WITH (NOLOCK)
            INNER JOIN persona p WITH (NOLOCK) ON a.id_usuario = p.id_persona
            INNER JOIN ticket t WITH (NOLOCK) ON a.id_ticket = t.id_ticket
            LEFT JOIN empresa e WITH (NOLOCK) ON t.id_empresa = e.id_empresa
            LEFT JOIN atencion_tiempo at WITH (NOLOCK) ON a.id_atencion = at.id_atencion
            $where ORDER BY a.id_atencion DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Cargar documentos en una sola query (no N+1)
    $ticketIds = array_values(array_unique(array_map('intval', array_column($rows, 'id_ticket'))));
    if ($ticketIds) {
        $marks = implode(',', array_fill(0, count($ticketIds), '?'));
        $docStmt = $pdo->prepare("SELECT id_ticket, id_documento, nombre_original, tamano_bytes, id_atencion, tipo_documento
                                  FROM dbo.ticket_documento WHERE id_atencion IS NOT NULL AND id_ticket IN ($marks)
                                  ORDER BY id_documento");
        $docStmt->execute($ticketIds);
        $documents = [];
        foreach ($docStmt->fetchAll() as $document) {
            $key = (int)$document['id_atencion'];
            $documents[$key][] = $document;
        }
        foreach ($rows as &$row) {
            $row['documentos'] = $documents[(int)$row['id_atencion']] ?? [];
        }
        unset($row);
    }
    ApiResponse::success($rows, 200);
}

/* ====================================================================
 * GET /atenciones/{id}
 * ==================================================================== */
if ($method === 'GET' && $id !== null && $action === null) {
    $pdo = Database::connection();
    $stmt = $pdo->prepare("SELECT a.*, p.nombres + ' ' + p.apellido_paterno AS tecnico_nombre,
                                  at.duracion_minutos, at.duracion_formato, t.nro_ticket,
                                  e.razon_social AS empresa_nombre, t.estado AS ticket_estado
                           FROM atencion a
                           INNER JOIN persona p ON a.id_usuario = p.id_persona
                           INNER JOIN ticket t ON a.id_ticket = t.id_ticket
                           LEFT JOIN empresa e ON t.id_empresa = e.id_empresa
                           LEFT JOIN atencion_tiempo at ON a.id_atencion = at.id_atencion
                           WHERE a.id_atencion = ?");
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    if (!$row) ApiResponse::error('not_found', 'Atención no encontrada', 404);

    $docStmt = $pdo->prepare("SELECT id_documento, nombre_original, tamano_bytes, tipo_documento
                              FROM dbo.ticket_documento
                              WHERE id_ticket = ? AND (id_atencion = ? OR id_atencion IS NULL)
                              ORDER BY id_documento");
    $docStmt->execute([(int)$row['id_ticket'], (int)$id]);
    $row['documentos_atencion'] = [];
    $row['documentos_generales'] = [];
    foreach ($docStmt->fetchAll() as $doc) {
        if (!empty($doc['id_atencion']) && (int)$doc['id_atencion'] === (int)$id) {
            $row['documentos_atencion'][] = $doc;
        } else {
            $row['documentos_generales'][] = $doc;
        }
    }
    ApiResponse::success($row, 200);
}

/* ====================================================================
 * POST /atenciones
 * ==================================================================== */
if ($method === 'POST' && $id === null) {
    Auth::requirePermission('TICKETS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['id_ticket', 'detalle_atencion'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    $estado = $body['estado'] ?? 'EN_PROCESO';
    if (!in_array($estado, ['EN_PROCESO', 'COMPLETADO', 'PAUSADO'], true)) {
        ApiResponse::validationError(['estado' => 'Estado no válido']);
    }

    if (!empty($body['fecha_fin']) && !empty($body['hora_fin'])) {
        $ini = strtotime(($body['fecha_inicio'] ?? date('Y-m-d')) . ' ' . ($body['hora_inicio'] ?? date('H:i:s')));
        $fin = strtotime($body['fecha_fin'] . ' ' . $body['hora_fin']);
        if ($fin < $ini) {
            ApiResponse::validationError(['fecha_fin' => 'La fecha de finalización no puede ser anterior a la de inicio']);
        }
    }

    try {
        $pdo  = Database::connection();
        $idUsuarioActual = (int)($user['id_persona'] ?? 0);
        if ($idUsuarioActual <= 0) {
            ApiResponse::error('auth_required', 'Su sesión no tiene un usuario válido.', 401);
        }
        $stmt = $pdo->prepare("EXEC sp_atencion_create_v2 ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?");
        $stmt->bindValue(1, (int)$body['id_ticket'], PDO::PARAM_INT);
        $stmt->bindValue(2, $idUsuarioActual, PDO::PARAM_INT);
        $stmt->bindValue(3, $body['detalle_atencion'], PDO::PARAM_STR);
        $stmt->bindValue(4, $body['solucion_aplicada'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(5, $body['diagnostico'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(6, $body['acciones_ejecutadas'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(7, $body['resultado'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(8, $estado, PDO::PARAM_STR);
        $stmt->bindValue(9, $body['fecha_inicio'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(10, $body['hora_inicio'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(11, $body['fecha_fin'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(12, $body['hora_fin'] ?? null, PDO::PARAM_STR);
        $newId = null;
        $stmt->bindParam(13, $newId, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
        $row = $stmt->fetch();

        Audit::log('ATENCIONES', 'CREAR', 'Atención #' . ($row['id_atencion'] ?? $newId) . ' registrada para ticket #' . $body['id_ticket'],
            'atencion', (string)($row['id_atencion'] ?? $newId),
            ['id_ticket' => $body['id_ticket'], 'estado' => $estado]);

        ApiResponse::success(['id_atencion' => (int)($row['id_atencion'] ?? $newId)], 201, 'Atención creada');
    } catch (Throwable $e) {
        $errMsg = $e->getMessage();
        error_log('[Atenciones POST] Error: ' . $errMsg);
        ApiResponse::error('atencion_create_failed', 'No se pudo crear la atención: ' . $errMsg, 422);
    }
}

/* ====================================================================
 * PUT /atenciones/{id}  — editar usando SP sp_atencion_edit
 * ==================================================================== */
if ($method === 'PUT' && $id !== null && $action === null) {
    Auth::requirePermission('TICKETS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $detalle = trim((string)($body['detalle_atencion'] ?? ''));
    $estado  = (string)($body['estado'] ?? '');
    if ($detalle === '') ApiResponse::validationError(['detalle_atencion' => 'Requerido']);
    if (!in_array($estado, ['EN_PROCESO', 'COMPLETADO', 'PAUSADO'], true))
        ApiResponse::validationError(['estado' => 'Estado no válido']);

    try {
        $idUsuarioEditor = (int)($user['id_persona'] ?? 0);

        // Llamar al SP sp_atencion_edit (más eficiente que UPDATE directo)
        $stmt = $pdo->prepare("EXEC sp_atencion_edit
            @id_atencion = ?,
            @detalle_atencion = ?,
            @solucion_aplicada = ?,
            @diagnostico = ?,
            @acciones_ejecutadas = ?,
            @resultado = ?,
            @estado = ?,
            @fecha_fin = ?,
            @hora_fin = ?,
            @id_usuario_editor = ?");

        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(2, $detalle, PDO::PARAM_STR);
        $stmt->bindValue(3, trim((string)($body['solucion_aplicada'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(4, trim((string)($body['diagnostico'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(5, trim((string)($body['acciones_ejecutadas'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(6, trim((string)($body['resultado'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(7, $estado, PDO::PARAM_STR);
        $stmt->bindValue(8, !empty($body['fecha_fin']) ? $body['fecha_fin'] : null, PDO::PARAM_STR);
        $stmt->bindValue(9, !empty($body['hora_fin']) ? $body['hora_fin'] : null, PDO::PARAM_STR);
        $stmt->bindValue(10, $idUsuarioEditor, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        Audit::log('ATENCIONES', 'MODIFICAR',
            'Atención #' . $id . ' editada (estado: ' . ($row['estado_anterior'] ?? '?') . ' → ' . $estado . ')',
            'atencion', (string)$id,
            ['id_ticket' => $row['id_ticket'] ?? null, 'estado_anterior' => $row['estado_anterior'] ?? null, 'estado_nuevo' => $estado]);

        ApiResponse::success(['id_atencion' => (int)$id], 200, 'Atención actualizada');
    } catch (Throwable $e) {
        ApiResponse::error('atencion_edit_failed', $e->getMessage(), 422);
    }
}

/* ====================================================================
 * POST /atenciones/{id}/solucionadora
 * ==================================================================== */
if ($method === 'POST' && $id !== null && $action === 'solucionadora') {
    Auth::requirePermission('TICKETS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        $rows = Database::callSP('sp_atencion_marcar_solucionadora', [
            (int)$id,
            (int)$user['id_persona'],
            $body['motivo_solucion'] ?? null,
            $body['resultado'] ?? null,
        ]);
        ApiResponse::success($rows[0] ?? null, 200, 'Atención marcada como solucionadora del ticket');
    } catch (Throwable $e) {
        ApiResponse::error('validation_error', $e->getMessage(), 422);
    }
}

/* ====================================================================
 * DELETE /atenciones/{id}
 * ==================================================================== */
if ($method === 'DELETE' && $id !== null) {
    Auth::requirePermission('TICKETS', 'eliminar');
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare("SELECT es_solucionadora FROM dbo.atencion WHERE id_atencion = ?");
        $check->execute([(int)$id]);
        $atencion = $check->fetch();
        if (!$atencion) {
            $pdo->rollBack();
            ApiResponse::error('not_found', 'Atención no encontrada', 404);
        }
        if ((int)$atencion['es_solucionadora'] === 1) {
            $pdo->rollBack();
            ApiResponse::error('validation_error', 'No se puede eliminar la atención solucionadora del ticket. Revierta la solución primero.', 422);
        }
        $stmt = $pdo->prepare('DELETE FROM dbo.atencion_tiempo WHERE id_atencion = ?');
        $stmt->execute([(int)$id]);
        $stmt = $pdo->prepare("UPDATE dbo.ticket_documento SET id_atencion = NULL WHERE id_atencion = ?");
        $stmt->execute([(int)$id]);
        $stmt = $pdo->prepare('DELETE FROM dbo.atencion WHERE id_atencion = ?');
        $stmt->execute([(int)$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    ApiResponse::success(null, 200, 'Atención eliminada (sus documentos pasaron a generales del ticket)');
}

ApiResponse::error('not_found', 'Endpoint no encontrado', 404);
