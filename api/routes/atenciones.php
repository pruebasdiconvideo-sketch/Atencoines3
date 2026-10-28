<?php
/**
 * api/routes/atenciones.php v2
 *
 *   GET    /atenciones                       Listado (filtros: id_ticket, id_usuario, estado)
 *   GET    /atenciones/{id_atencion}         Detalle (incluye documentos)
 *   POST   /atenciones                       Crear atención (valida coherencia fechas)
 *   PUT    /atenciones/{id_atencion}         Actualizar detalle/estado
 *   POST   /atenciones/{id_atencion}/solucionadora  Marcar como solucionadora del ticket
 *   DELETE /atenciones/{id_atencion}         Eliminar atención (solo si no es solucionadora)
 */
Auth::requirePermission('TICKETS', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

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
                   t.nro_ticket, e.razon_social AS empresa_nombre
            FROM atencion a WITH (NOLOCK)
            INNER JOIN persona p WITH (NOLOCK) ON a.id_usuario = p.id_persona
            INNER JOIN ticket t WITH (NOLOCK) ON a.id_ticket = t.id_ticket
            LEFT JOIN empresa e WITH (NOLOCK) ON t.id_empresa = e.id_empresa
            LEFT JOIN atencion_tiempo at WITH (NOLOCK) ON a.id_atencion = at.id_atencion
            $where ORDER BY a.id_atencion DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
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

    // Validar coherencia de fechas: fecha_fin >= fecha_inicio
    if (!empty($body['fecha_fin']) && !empty($body['hora_fin'])) {
        $ini = strtotime(($body['fecha_inicio'] ?? date('Y-m-d')) . ' ' . ($body['hora_inicio'] ?? date('H:i:s')));
        $fin = strtotime($body['fecha_fin'] . ' ' . $body['hora_fin']);
        if ($fin < $ini) {
            ApiResponse::validationError(['fecha_fin' => 'La fecha de finalización no puede ser anterior a la de inicio']);
        }
    }

    try {
        $pdo  = Database::connection();
        // FIX: Validar que el usuario esté autenticado y tenga id_persona válido.
        // La columna atencion.id_usuario es NOT NULL con FK a persona.id_persona.
        $idUsuarioActual = (int)($user['id_persona'] ?? 0);
        if ($idUsuarioActual <= 0) {
            ApiResponse::error(
                'auth_required',
                'Su sesión no tiene un usuario válido. Cierre sesión y vuelva a iniciar.',
                401
            );
        }
        // FIX: El SP tiene 13 parámetros (12 IN + 1 OUT), no 14.
        // Orden: id_ticket, id_usuario, detalle_atencion, solucion_aplicada,
        //        diagnostico, acciones_ejecutadas, resultado, estado,
        //        fecha_inicio, hora_inicio, fecha_fin, hora_fin, id_atencion(OUT)
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

        // Auditoría de la acción
        Audit::log('ATENCIONES', 'CREAR', 'Atención #' . ($row['id_atencion'] ?? $newId) . ' registrada para ticket #' . $body['id_ticket'],
            'atencion', (string)($row['id_atencion'] ?? $newId),
            ['id_ticket' => $body['id_ticket'], 'estado' => $estado]);

        ApiResponse::success(['id_atencion' => (int)($row['id_atencion'] ?? $newId)], 201, 'Atención creada');
    } catch (Throwable $e) {
        $errMsg = $e->getMessage();
        error_log('[Atenciones POST] Error sp_atencion_create_v2: ' . $errMsg);
        Audit::log('ATENCIONES', 'CREAR', 'Error al crear atención: ' . $errMsg,
            'atencion', null, ['error' => $errMsg, 'id_ticket' => $body['id_ticket'] ?? null], 'ERROR');
        ApiResponse::error(
            'atencion_create_failed',
            'No se pudo crear la atención: ' . $errMsg,
            422
        );
    }
}

if ($method === 'PUT' && $id !== null && $action === null) {
    Auth::requirePermission('TICKETS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $detalle = trim((string)($body['detalle_atencion'] ?? ''));
    $estado = (string)($body['estado'] ?? '');
    $errors = [];
    if ($detalle === '') $errors['detalle_atencion'] = 'Requerido';
    if (!in_array($estado, ['EN_PROCESO', 'COMPLETADO', 'PAUSADO'], true)) $errors['estado'] = 'Estado no válido';
    if ($errors) ApiResponse::validationError($errors);
    $pdo = Database::connection();
    $stmt = $pdo->prepare("UPDATE dbo.atencion
                           SET detalle_atencion = ?, solucion_aplicada = ?, estado = ?,
                               diagnostico = ?, acciones_ejecutadas = ?, resultado = ?
                           WHERE id_atencion = ?");
    $stmt->execute([
        $detalle,
        trim((string)($body['solucion_aplicada'] ?? '')) ?: null,
        $estado,
        trim((string)($body['diagnostico'] ?? '')) ?: null,
        trim((string)($body['acciones_ejecutadas'] ?? '')) ?: null,
        trim((string)($body['resultado'] ?? '')) ?: null,
        (int)$id,
    ]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM dbo.atencion WHERE id_atencion = ?');
        $check->execute([(int)$id]);
        if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Atención no encontrada', 404);
    }
    // Si se marca como COMPLETADO y tiene fecha_fin, recalcular duración
    if ($estado === 'COMPLETADO') {
        $stmt = $pdo->prepare("SELECT a.id_ticket, a.fecha_inicio, a.hora_inicio
                               FROM dbo.atencion a WHERE a.id_atencion = ?");
        $stmt->execute([(int)$id]);
        $atencion = $stmt->fetch();
        if ($atencion) {
            $ini = strtotime($atencion['fecha_inicio'] . ' ' . $atencion['hora_inicio']);
            $fin = time();
            if ($fin >= $ini) {
                $diffMin = (int)(($fin - $ini) / 60);
                $horas = round($diffMin / 60.0, 2);
                $formato = intdiv($diffMin, 60) . 'h ' . str_pad($diffMin % 60, 2, '0', STR_PAD_LEFT) . 'm';
                $upsert = $pdo->prepare("IF EXISTS (SELECT 1 FROM dbo.atencion_tiempo WHERE id_atencion = ?)
                                          UPDATE dbo.atencion_tiempo SET duracion_minutos=?, duracion_horas=?, duracion_formato=? WHERE id_atencion=?
                                         ELSE
                                          INSERT INTO dbo.atencion_tiempo (id_atencion, duracion_minutos, duracion_horas, duracion_formato) VALUES (?,?,?,?)");
                $upsert->execute([$id, $diffMin, $horas, $formato, $id, $id, $diffMin, $horas, $formato]);
            }
        }
    }
    ApiResponse::success(['id_atencion' => (int)$id], 200, 'Atención actualizada');
}

/* ---- POST /atenciones/{id}/solucionadora ---- */
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

if ($method === 'DELETE' && $id !== null) {
    Auth::requirePermission('TICKETS', 'eliminar');
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        // Verificar que NO sea la atención solucionadora de su ticket
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
        // Reasignar documentos de la atención a documentos generales del ticket
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
