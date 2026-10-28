<?php
/**
 * api/routes/cotizaciones_override_v3.php
 * ----------------------------------------------------------------------------
 * VERSIÓN 3 — Endpoints PHP que garantizan:
 *   1. POST /cotizaciones use sp_cotizacion_create_v5 (formato SERV-2026-001.1)
 *   2. PATCH /cotizaciones/{id}/status devuelva mensaje claro (no 500)
 *   3. GET /cotizaciones/filtro_empresa — listado con filtros para seguimiento
 *
 * Este archivo debe cargarse desde api/index.php. Ver docs/INSTALACION_V3.md
 */

if (!isset($userId) || $userId <= 0) {
    return;
}

$method = $_SERVER['REQUEST_METHOD'];

/* ============================================================================
 * INTERCEPTAR: POST /cotizaciones — usar sp_cotizacion_create_v5
 * ==========================================================================*/
if ($method === 'POST' && ($id === null || $id === '')) {
    Auth::requirePermission('COTIZACIONES', 'registrar');

    $pdo = Database::connection();
    $chkStmt = $pdo->prepare(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_create_v5','P') IS NOT NULL THEN 1 ELSE 0 END AS existe"
    );
    $chkStmt->execute();
    $spV5Existe = (int)$chkStmt->fetchColumn();

    if (!$spV5Existe) {
        ApiResponse::error('sp_v5_no_existe',
            'El procedimiento sp_cotizacion_create_v5 no existe. Ejecute el script SQL 07_correcciones_v2.sql con un usuario db_owner.',
            503);
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

    /* Cuentas adicionales JSON */
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
        error_log('[Cotizaciones POST v5 v3] Error: ' . $e->getMessage());
        ApiResponse::error('cotizacion_create_failed', 'No se pudo crear: ' . $e->getMessage(), 422);
    }

    $idNew  = (int)($row['id_cotizacion']  ?? $idCot  ?? 0);
    $codNew = $row['codigo'] ?? $codigo ?? '';

    if (!$idNew) {
        ApiResponse::error('cotizacion_create_failed',
            'El SP v5 no devolvió id_cotizacion. Verifique que la empresa tenga codigo_empresa asignado.',
            422);
    }

    Audit::log('COTIZACIONES', 'CREAR', 'Cotización ' . $codNew . ' creada (v5)',
        'cotizacion', (string)$idNew,
        ['codigo' => $codNew, 'id_empresa' => $body['id_empresa'], 'id_tipo' => $body['id_tipo']]);

    ApiResponse::success(['id_cotizacion' => $idNew, 'codigo' => $codNew], 201, 'Cotización creada');
}

/* ============================================================================
 * PATCH /cotizaciones/{id}/status — Cambiar estado (manejo mejorado, sin 500)
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
        error_log('[Cotizaciones status v3] Error: ' . $e->getMessage());
        ApiResponse::error('status_failed', $e->getMessage(), 500);
    }
}

/* ============================================================================
 * GET /cotizaciones/filtro_empresa — Listado para seguimiento comercial
 * ==========================================================================*/
if ($method === 'GET' && $id === 'filtro_empresa') {
    Auth::requirePermission('COTIZACIONES', 'ver');

    try {
        $pdo = Database::connection();

        $idEmp       = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : null;
        $query       = isset($_GET['query']) ? trim($_GET['query']) : null;
        $estado      = isset($_GET['estado']) ? $_GET['estado'] : null;
        $page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage     = isset($_GET['per_page']) ? min(200, max(1, (int)$_GET['per_page'])) : 50;
        $offset      = ($page - 1) * $perPage;

        $where  = " WHERE ISNULL(c.eliminado, 0) = 0 ";
        $params = [];

        if ($idEmp) {
            $where .= " AND c.id_empresa = ? ";
            $params[] = $idEmp;
        }
        if ($estado) {
            $where .= " AND c.estado = ? ";
            $params[] = $estado;
        }
        if ($query) {
            $where .= " AND (c.codigo LIKE ? OR e.razon_social LIKE ? OR e.ruc LIKE ? OR e.nombre_comercial LIKE ?) ";
            $like = '%' . $query . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $sqlCount = "SELECT COUNT(*)
                      FROM dbo.cotizacion c
                      INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa "
                  . $where;
        $stmtC = $pdo->prepare($sqlCount);
        foreach ($params as $i => $v) {
            $stmtC->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmtC->execute();
        $total = (int)$stmtC->fetchColumn();

        $sqlPage = "SELECT c.id_cotizacion, c.codigo, c.version_numero, c.estado,
                           CONVERT(VARCHAR(10), c.fecha_creacion, 23) AS fecha_creacion,
                           CONVERT(VARCHAR(10), c.fecha_proximo_seguimiento, 23) AS fecha_proximo_seguimiento,
                           c.total, c.moneda,
                           c.id_empresa, e.razon_social AS empresa_razon_social,
                           e.ruc AS empresa_ruc, e.codigo_empresa,
                           tc.nombre AS tipo_nombre,
                           ISNULL(cnt.total_seguimientos, 0) AS total_seguimientos
                    FROM dbo.cotizacion c
                    INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
                    INNER JOIN dbo.cotizacion_tipo_catalogo tc ON c.id_tipo = tc.id_tipo
                    OUTER APPLY (
                        SELECT COUNT(*) AS total_seguimientos
                        FROM dbo.cotizacion_comunicacion cc
                        WHERE cc.id_cotizacion = c.id_cotizacion
                    ) cnt "
                  . $where
                  . " ORDER BY c.id_cotizacion DESC "
                  . " OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

        $stmtP = $pdo->prepare($sqlPage);
        foreach ($params as $i => $v) {
            $stmtP->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmtP->execute();
        $rows = $stmtP->fetchAll();

        ApiResponse::success([
            'rows'     => $rows,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ], 200);
    } catch (Throwable $e) {
        ApiResponse::error('filtro_empresa_failed', $e->getMessage(), 500);
    }
}

/* ============================================================================
 * GET /cotizaciones/preview_codigo — Previsualizar código (formato nuevo)
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
                'El procedimiento sp_cotizacion_preview_codigo no existe. Ejecute el script SQL 07.',
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
