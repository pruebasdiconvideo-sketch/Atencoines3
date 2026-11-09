<?php
/**
 * api/routes/cotizaciones_historial_endpoint.php
 * ---------------------------------------------------------------------------
 * NUEVO ENDPOINT para obtener el historial de estados de una cotización
 * de forma aislada (sin recargar toda la cotización).
 *
 * Endpoints añadidos:
 *   GET  /cotizaciones/{id}/historial       — Lista el historial legible
 *   GET  /cotizaciones/{id}/comunicaciones  — Lista las comunicaciones
 *   POST /cotizaciones/{id}/status          — Cambia estado (usa SP v2 que
 *                                              devuelve historial actualizado)
 *   POST /cotizaciones/{id}/comunicaciones  — Agrega comunicación (usa SP v3
 *                                              que devuelve historial actualizado)
 *
 * Estos endpoints se deben agregar al archivo principal api/routes/cotizaciones.php
 * o cargarse como un sub-routes mediante include_once.
 * ---------------------------------------------------------------------------
 */

// Nota: las variables $method, $id, $action, $userId ya están definidas
// por el archivo principal cotizaciones.php

/*============================================================================
  GET /cotizaciones/{id}/historial
  Devuelve SOLO el historial de estados de una cotización, en formato legible.
  Útil para refrescar el panel de historial sin recargar toda la cotización.
============================================================================*/
if ($method === 'GET' && $id !== null && is_numeric($id) && $action === 'historial') {
    Auth::requirePermission('COTIZACIONES', 'ver');
    try {
        $pdo = Database::connection();

        // Usar el SP nuevo si existe; si no, fallback a la vista
        $spCheck = $pdo->prepare(
            "SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_historial_listar','P') IS NOT NULL THEN 1 ELSE 0 END AS existe"
        );
        $spCheck->execute();
        $spExists = (int)$spCheck->fetchColumn() === 1;

        if ($spExists) {
            $stmt = $pdo->prepare('EXEC dbo.sp_cotizacion_historial_listar @id_cotizacion = ?');
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Fallback usando la vista legible
            $stmt = $pdo->prepare(
                "SELECT id_historial, id_cotizacion, estado_anterior, estado_nuevo,
                        estado_anterior_label, estado_nuevo_label, tipo_evento_label,
                        origen_evento_label, motivo, datos_adicionales, id_usuario,
                        usuario_nombre, usuario_correo, fecha_cambio
                 FROM dbo.vw_cotizacion_historial_legible
                 WHERE id_cotizacion = ?
                 ORDER BY fecha_cambio DESC, id_historial DESC"
            );
            $stmt->execute([(int)$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        ApiResponse::success([
            'data' => $rows,
            'meta' => [
                'id_cotizacion' => (int)$id,
                'total'         => count($rows),
            ],
        ], 200);
    } catch (Throwable $e) {
        error_log('[Cotizaciones historial] ' . $e->getMessage());
        ApiResponse::error('historial_failed',
            'Error al obtener historial: ' . $e->getMessage(), 500);
    }
}

/*============================================================================
  POST /cotizaciones/{id}/status
  Cambia el estado de una cotización.
  Usa el SP v2 que devuelve el historial actualizado como segundo result set.
============================================================================*/
if ($method === 'POST' && $id !== null && is_numeric($id) && $action === 'status') {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['estado'])) {
        ApiResponse::validationError(['estado' => 'Requerido']);
    }
    $nuevoEstado = strtoupper(trim($body['estado']));
    $motivo = trim($body['motivo'] ?? '');

    // Si el estado requiere aprobación, validar permiso extra
    if (in_array($nuevoEstado, ['APROBADA','RECHAZADA'], true)) {
        Auth::requirePermission('COTIZACIONES', 'aprobar');
    }

    try {
        $pdo = Database::connection();

        // Detectar si el SP v2 existe; si no, usar el v1
        $spCheck = $pdo->prepare(
            "SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_update_status_v2','P') IS NOT NULL THEN 1 ELSE 0 END AS existe"
        );
        $spCheck->execute();
        $spExists = (int)$spCheck->fetchColumn() === 1;

        if ($spExists) {
            $stmt = $pdo->prepare(
                'EXEC dbo.sp_cotizacion_update_status_v2
                 @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?'
            );
            $stmt->bindValue(1, (int)$id,        PDO::PARAM_INT);
            $stmt->bindValue(2, $nuevoEstado,    PDO::PARAM_STR);
            $stmt->bindValue(3, $userId,         PDO::PARAM_INT);
            $stmt->bindValue(4, $motivo ?: null, PDO::PARAM_STR);
            $stmt->execute();

            // Result set 1: confirmación
            $confirm = $stmt->fetch(PDO::FETCH_ASSOC);
            // Result set 2: historial actualizado
            $stmt->nextRowset();
            $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$confirm || (int)$confirm['success'] !== 1) {
                ApiResponse::error('transicion_invalida',
                    $confirm['mensaje'] ?? 'Transición no permitida', 422,
                    ['estado_anterior' => $confirm['estado_anterior'] ?? null,
                     'estado_nuevo'    => $confirm['estado_nuevo'] ?? null]);
            }

            // Auditoría
            Audit::log('COTIZACIONES', 'CAMBIAR_ESTADO',
                'Cotización #' . $id . ' → ' . $nuevoEstado,
                'cotizacion', (string)$id,
                ['estado_anterior' => $confirm['estado_anterior'],
                 'estado_nuevo'   => $confirm['estado_nuevo'],
                 'motivo'         => $motivo]);

            ApiResponse::success([
                'data' => [
                    'success'         => true,
                    'estado_anterior' => $confirm['estado_anterior'],
                    'estado_nuevo'    => $confirm['estado_nuevo'],
                    'tipo_evento'     => $confirm['tipo_evento'],
                    'fecha_cambio'    => $confirm['fecha_cambio'],
                    'historial'       => $historial,   // <-- historial actualizado
                ],
            ], 200, 'Estado actualizado correctamente');
        } else {
            // Fallback al SP v1 (sin historial en el result set)
            $stmt = $pdo->prepare(
                'EXEC dbo.sp_cotizacion_update_status
                 @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?'
            );
            $stmt->bindValue(1, (int)$id,        PDO::PARAM_INT);
            $stmt->bindValue(2, $nuevoEstado,    PDO::PARAM_STR);
            $stmt->bindValue(3, $userId,         PDO::PARAM_INT);
            $stmt->bindValue(4, $motivo ?: null, PDO::PARAM_STR);
            $stmt->execute();
            $confirm = $stmt->fetch(PDO::FETCH_ASSOC);

            ApiResponse::success([
                'data' => $confirm,
            ], 200, 'Estado actualizado (historial se debe refrescar por separado)');
        }
    } catch (Throwable $e) {
        error_log('[Cotizaciones status] ' . $e->getMessage());
        ApiResponse::error('status_failed',
            'Error al cambiar estado: ' . $e->getMessage(), 500);
    }
}

/*============================================================================
  POST /cotizaciones/{id}/comunicaciones
  Agrega una comunicación/seguimiento a una cotización.
  Usa el SP v3 que devuelve el historial actualizado.
============================================================================*/
if ($method === 'POST' && $id !== null && is_numeric($id) && $action === 'comunicaciones') {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if (empty($body['medio'])) {
        ApiResponse::validationError(['medio' => 'Requerido']);
    }
    $medio = strtoupper(trim($body['medio']));
    $allowedMedios = ['TELEFONO','EMAIL','WHATSAPP','CHAT','PRESENCIAL','REUNION_PRESENCIAL','REUNION_VIRTUAL','CORREO_ELECTRONICO','LLAMADA_TELEFONICA','SISTEMA','OTRO'];
    if (!in_array($medio, $allowedMedios, true)) {
        ApiResponse::validationError(['medio' => 'Medio inválido: ' . $medio]);
    }

    try {
        $pdo = Database::connection();

        // Detectar SP v3
        $spCheck = $pdo->prepare(
            "SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_add_comunicacion_v3','P') IS NOT NULL THEN 1 ELSE 0 END AS existe"
        );
        $spCheck->execute();
        $spExists = (int)$spCheck->fetchColumn() === 1;

        if ($spExists) {
            $stmt = $pdo->prepare(
                'EXEC dbo.sp_cotizacion_add_comunicacion_v3
                 @id_cotizacion = ?, @id_usuario = ?, @medio = ?,
                 @tipo_comunicacion = ?, @descripcion = ?,
                 @fecha_comunicacion = ?, @respuesta_cliente = ?,
                 @resultado_compromiso = ?, @fecha_proximo_seguimiento = ?,
                 @observaciones = ?, @persona_contactada = ?,
                 @resultado_comunicacion = ?, @compromiso_fecha = ?,
                 @id_comunicacion = ?'
            );
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->bindValue(2, $userId, PDO::PARAM_INT);
            $stmt->bindValue(3, $medio, PDO::PARAM_STR);
            $stmt->bindValue(4, $body['tipo_comunicacion'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(5, $body['descripcion'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(6, $body['fecha_comunicacion'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(7, $body['respuesta_cliente'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(8, $body['resultado_compromiso'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(9, $body['fecha_proximo_seguimiento'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(10, $body['observaciones'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(11, $body['persona_contactada'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(12, $body['resultado_comunicacion'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(13, $body['compromiso_fecha'] ?? null, PDO::PARAM_STR);
            $idCom = null;
            $stmt->bindParam(14, $idCom, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
            $stmt->execute();

            // Result set 1: id_comunicacion
            $row1 = $stmt->fetch(PDO::FETCH_ASSOC);
            // Result set 2: historial actualizado
            $stmt->nextRowset();
            $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Audit::log('COTIZACIONES', 'AGREGAR_COMUNICACION',
                'Comunicación agregada a cotización #' . $id . ' (' . $medio . ')',
                'cotizacion', (string)$id,
                ['id_comunicacion' => $row1['id_comunicacion'] ?? $idCom, 'medio' => $medio]);

            ApiResponse::success([
                'data' => [
                    'id_comunicacion' => (int)($row1['id_comunicacion'] ?? $idCom),
                    'historial'       => $historial,
                ],
            ], 201, 'Comunicación agregada');
        } else {
            // Fallback al SP v2
            $rows = Database::callSP('sp_cotizacion_add_comunicacion_v2', [
                (int)$id, $userId, $medio,
                $body['tipo_comunicacion'] ?? null,
                $body['descripcion'] ?? null,
                $body['fecha_comunicacion'] ?? null,
                $body['respuesta_cliente'] ?? null,
                $body['resultado_compromiso'] ?? null,
                $body['fecha_proximo_seguimiento'] ?? null,
                $body['observaciones'] ?? null,
                $body['persona_contactada'] ?? null,
                $body['resultado_comunicacion'] ?? null,
                $body['compromiso_fecha'] ?? null,
            ]);
            $idCom = $rows[0]['id_comunicacion'] ?? null;
            ApiResponse::success(['data' => ['id_comunicacion' => $idCom]], 201, 'Comunicación agregada');
        }
    } catch (Throwable $e) {
        error_log('[Cotizaciones comunicaciones] ' . $e->getMessage());
        ApiResponse::error('comunicacion_failed',
            'Error al agregar comunicación: ' . $e->getMessage(), 500);
    }
}

/*============================================================================
  GET /cotizaciones/{id}/comunicaciones
  Lista las comunicaciones de una cotización.
============================================================================*/
if ($method === 'GET' && $id !== null && is_numeric($id) && $action === 'comunicaciones') {
    Auth::requirePermission('COTIZACIONES', 'ver');
    try {
        $rows = Database::callSP('sp_cotizacion_list_seguimientos', [(int)$id]);
        ApiResponse::success(['data' => $rows[0] ?? $rows], 200);
    } catch (Throwable $e) {
        ApiResponse::error('comunicaciones_list_failed',
            'Error al listar comunicaciones: ' . $e->getMessage(), 500);
    }
}
