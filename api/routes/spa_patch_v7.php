<?php
/**
 * api/routes/spa_patch_v7.php
 * ----------------------------------------------------------------------------
 * Patch PHP para la V7. Debe cargarse AL FINAL de api/index.php
 * (después del require $routeFile original).
 *
 * Garantiza:
 *   - POST /cotizaciones use sp_cotizacion_create_v5 (formato SERV-2026-001.1)
 *   - GET /cotizaciones/preview_codigo devuelva el código previsualizado
 *   - PATCH /cotizaciones/{id}/status devuelva mensaje claro (sin 500)
 *
 * IMPORTANTE: Este archivo intercepta las peticiones ANTES de que el archivo
 * original procese la lógica. Si el SP v5 existe, lo usa; si no, deja que
 * el original continúe.
 */

if (!isset($userId) || $userId <= 0) {
    return;
}

$method = $_SERVER['REQUEST_METHOD'];

/* ============================================================================
 * INTERCEPTAR: POST /cotizaciones (crear) — usar sp_cotizacion_create_v5
 * ==========================================================================*/
if ($method === 'POST' && ($id === null || $id === '') && $action === null) {

    Auth::requirePermission('COTIZACIONES', 'registrar');

    $pdo = Database::connection();

    /* Verificar si existe el SP v5 */
    $chkStmt = $pdo->prepare(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_create_v5','P') IS NOT NULL THEN 1 ELSE 0 END AS existe"
    );
    $chkStmt->execute();
    $spV5Existe = (int)$chkStmt->fetchColumn();

    if (!$spV5Existe) {
        /* Si no existe v5, dejar que el archivo original maneje la petición */
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    /* Validaciones */
    if (empty($body['id_empresa'])) ApiResponse::validationError(['id_empresa' => 'Requerido']);
    if (empty($body['id_tipo'])) ApiResponse::validationError(['id_tipo' => 'Requerido']);
    if (empty($body['id_emisora'])) ApiResponse::validationError(['id_emisora' => 'Requerido']);

    /* Detalle JSON */
    $detalleJson = null;
    if (!empty($body['detalle']) && is_array($body['detalle'])) {
        $detalleJson = json_encode($body['detalle']);
    } elseif (!empty($body['detalle_json'])) {
        $detalleJson = $body['detalle_json'];
    }

    /* Cuentas adicionales */
    $cuentasAdicionalesJson = null;
    if (!empty($body['cuentas_adicionales']) && is_array($body['cuentas_adicionales'])) {
        $cuentasAdicionalesJson = json_encode(array_values($body['cuentas_adicionales']));
    } elseif (!empty($body['cuentas_adicionales_json'])) {
        $cuentasAdicionalesJson = $body['cuentas_adicionales_json'];
    }

    /* Ejecutar SP v5 */
    $stmt = $pdo->prepare(
        'EXEC dbo.sp_cotizacion_create_v5
            @id_empresa = ?, @id_empresa_contacto = ?, @id_sucursal = ?,
            @id_tipo = ?, @id_emisora = ?,
            @id_forma_pago = ?, @id_garantia = ?, @id_plazo_entrega = ?,
            @id_validez = ?, @id_cuenta_bancaria = ?, @cuentas_adicionales_json = ?,
            @id_usuario_creador = ?, @id_vendedor = ?,
            @titulo_documento = ?, @motivo = ?, @observaciones = ?,
            @moneda = ?, @igv_pct = ?, @descuento_global_pct = ?,
            @detalle_json = ?,
            @codigo = ?, @id_cotizacion = ?'
    );

    $stmt->bindValue(1,  (int)$body['id_empresa'],            PDO::PARAM_INT);
    $stmt->bindValue(2,  isset($body['id_empresa_contacto']) ? (int)$body['id_empresa_contacto'] : null, PDO::PARAM_INT);
    $stmt->bindValue(3,  isset($body['id_sucursal']) ? (int)$body['id_sucursal'] : null, PDO::PARAM_INT);
    $stmt->bindValue(4,  (int)$body['id_tipo'],                PDO::PARAM_INT);
    $stmt->bindValue(5,  (int)$body['id_emisora'],             PDO::PARAM_INT);
    $stmt->bindValue(6,  isset($body['id_forma_pago'])      ? (int)$body['id_forma_pago']      : null, PDO::PARAM_INT);
    $stmt->bindValue(7,  isset($body['id_garantia'])        ? (int)$body['id_garantia']        : null, PDO::PARAM_INT);
    $stmt->bindValue(8,  isset($body['id_plazo_entrega'])  ? (int)$body['id_plazo_entrega']  : null, PDO::PARAM_INT);
    $stmt->bindValue(9,  isset($body['id_validez'])        ? (int)$body['id_validez']        : null, PDO::PARAM_INT);
    $stmt->bindValue(10, isset($body['id_cuenta_bancaria'])? (int)$body['id_cuenta_bancaria']: null, PDO::PARAM_INT);
    $stmt->bindValue(11, $cuentasAdicionalesJson,            PDO::PARAM_STR);
    $stmt->bindValue(12, $userId,                            PDO::PARAM_INT);
    $stmt->bindValue(13, isset($body['id_vendedor'])        ? (int)$body['id_vendedor'] : null, PDO::PARAM_INT);
    $stmt->bindValue(14, $body['titulo_documento'] ?? null,  PDO::PARAM_STR);
    $stmt->bindValue(15, $body['motivo'] ?? null,             PDO::PARAM_STR);
    $stmt->bindValue(16, $body['observaciones'] ?? null,      PDO::PARAM_STR);
    $stmt->bindValue(17, $body['moneda'] ?? 'PEN',            PDO::PARAM_STR);
    $stmt->bindValue(18, isset($body['igv_pct']) ? (float)$body['igv_pct'] : 18.00, PDO::PARAM_STR);
    $stmt->bindValue(19, isset($body['descuento_global_pct']) ? (float)$body['descuento_global_pct'] : 0.00, PDO::PARAM_STR);
    $stmt->bindValue(20, $detalleJson,                        PDO::PARAM_STR);

    $codigo = null;
    $idCot  = null;
    $stmt->bindParam(21, $codigo, PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 40);
    $stmt->bindParam(22, $idCot,  PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);

    try {
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || !isset($row['id_cotizacion'])) {
            try { $stmt->nextRowset(); $row = $stmt->fetch(); } catch (Throwable $e2) {}
        }
    } catch (Throwable $e) {
        error_log('[SPA Patch V7] POST /cotizaciones: ' . $e->getMessage());
        ApiResponse::error('cotizacion_create_failed', 'No se pudo crear: ' . $e->getMessage(), 422);
    }

    $idNew  = (int)($row['id_cotizacion']  ?? $idCot  ?? 0);
    $codNew = $row['codigo'] ?? $codigo ?? '';

    if (!$idNew) {
        ApiResponse::error('cotizacion_create_failed',
            'El SP v5 no devolvió id_cotizacion. ¿La empresa tiene codigo_empresa asignado?',
            422);
    }

    Audit::log('COTIZACIONES', 'CREAR', 'Cotización ' . $codNew . ' creada (v5)',
        'cotizacion', (string)$idNew,
        ['codigo' => $codNew, 'id_empresa' => $body['id_empresa']]);

    ApiResponse::success(['id_cotizacion' => $idNew, 'codigo' => $codNew], 201, 'Cotización creada');
}

/* ============================================================================
 * GET /cotizaciones/preview_codigo — Previsualizar código
 * ==========================================================================*/
if ($method === 'GET' && $id === 'preview_codigo'
    && isset($_GET['id_empresa']) && isset($_GET['id_tipo'])) {

    Auth::requirePermission('COTIZACIONES', 'ver');

    try {
        $pdo = Database::connection();
        $chk = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_preview_codigo','P') IS NOT NULL THEN 1 ELSE 0 END");
        $spExiste = (int)$chk->fetchColumn();

        if (!$spExiste) {
            ApiResponse::error('sp_no_existe',
                'El procedimiento sp_cotizacion_preview_codigo no existe. Ejecute el script SQL 09.',
                503);
        }

        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_preview_codigo
                                 @id_empresa = ?, @id_tipo = ?");
        $stmt->bindValue(1, (int)$_GET['id_empresa'], PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$_GET['id_tipo'],    PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        ApiResponse::success([
            'codigo_proximo'        => $row['codigo_proximo'] ?? null,
            'codigo_empresa'        => $row['codigo_empresa'] ?? null,
            'anio'                  => $row['anio'] ?? null,
            'proximo_correlativo'   => $row['proximo_correlativo'] ?? null,
            'advertencia'           => $row['advertencia'] ?? null,
        ], 200);
    } catch (Throwable $e) {
        ApiResponse::error('preview_failed', $e->getMessage(), 500);
    }
}

/* ============================================================================
 * PATCH /cotizaciones/{id}/status — Cambiar estado (manejo mejorado)
 * ==========================================================================*/
if ($method === 'PATCH' && is_numeric($id) && $action === 'status') {
    Auth::requirePermission('COTIZACIONES', 'aprobar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['estado'])) {
        ApiResponse::validationError(['estado' => 'Requerido']);
    }

    try {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            'EXEC dbo.sp_cotizacion_update_status
                @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?'
        );
        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(2, $body['estado'], PDO::PARAM_STR);
        $stmt->bindValue(3, $userId, PDO::PARAM_INT);
        $stmt->bindValue(4, $body['motivo'] ?? null, PDO::PARAM_STR);

        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            ApiResponse::error('status_failed', 'No se recibió respuesta del SP', 500);
        }

        if ((int)($row['success'] ?? 0) === 1) {
            Audit::log('COTIZACIONES', 'CAMBIO_ESTADO',
                'Cotización #' . $id . ': ' . ($row['estado_anterior'] ?? '?') . ' → ' . ($row['estado_nuevo'] ?? '?'),
                'cotizacion', (string)$id,
                ['estado_anterior' => $row['estado_anterior'] ?? null,
                 'estado_nuevo' => $row['estado_nuevo'] ?? null]);

            ApiResponse::success([
                'id_cotizacion'     => (int)$id,
                'estado_anterior'   => $row['estado_anterior'] ?? null,
                'estado_nuevo'      => $row['estado_nuevo'] ?? null,
                'tipo_evento'       => $row['tipo_evento'] ?? null,
            ], 200, 'Estado actualizado');
        } else {
            ApiResponse::error(
                'transicion_invalida',
                $row['mensaje'] ?? $row['error'] ?? 'No se pudo cambiar el estado',
                422,
                [
                    'estado_anterior' => $row['estado_anterior'] ?? null,
                    'estado_nuevo'    => $row['estado_nuevo'] ?? null,
                ]
            );
        }
    } catch (Throwable $e) {
        error_log('[SPA Patch V7] PATCH status: ' . $e->getMessage());
        ApiResponse::error('status_failed', $e->getMessage(), 500);
    }
}
