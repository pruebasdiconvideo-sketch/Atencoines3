<?php
/**
 * api/routes/cotizaciones_v5_patch.php
 * ----------------------------------------------------------------------------
 * Patch para que la API prefiera usar sp_cotizacion_create_v5 (formato
 * SERV-2026-001.1) en vez de sp_cotizacion_create_v4 (formato
 * SOP-EMP000001-2026-0001).
 *
 * Este archivo debe incluirse AL FINAL de api/routes/cotizaciones.php,
 * justo antes del último "ApiResponse::error('not_found'...".
 *
 * Estrategia:
 *   - El endpoint POST /cotizaciones ya existe en cotizaciones.php
 *   - Este patch intercepta la creación ANTES de que el archivo original
 *     ejecute el SP v4.
 *   - Si existe el SP v5, lo usa; si no, deja que el original siga su flujo.
 *
 * Endpoints adicionales que añade este patch:
 *   PATCH /cotizaciones/{id}/status    Cambiar estado (con manejo mejorado)
 *   GET   /cotizaciones/filtro_empresa Listado filtrado por empresa
 *                                      (para la sección comercial)
 */

if (!isset($userId) || $userId <= 0) {
    return;
}

$method = $_SERVER['REQUEST_METHOD'];

/* ============================================================================
 * INTERCEPTAR: POST /cotizaciones (crear) — usar v5 si existe
 *
 * Si el request es POST /cotizaciones (sin ID), interceptamos ANTES de que
 * el archivo original lo procese. Si existe sp_cotizacion_create_v5, lo
 * usamos. Si no, dejamos que el original siga.
 * ==========================================================================*/
if ($method === 'POST' && ($id === null || $id === '')) {

    Auth::requirePermission('COTIZACIONES', 'registrar');

    /* Verificar si existe el SP v5 */
    $pdo = Database::connection();
    $chkStmt = $pdo->prepare(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_create_v5','P') IS NOT NULL THEN 1 ELSE 0 END AS existe"
    );
    $chkStmt->execute();
    $spV5Existe = (int)$chkStmt->fetchColumn();

    if ($spV5Existe) {
        /* ===== Usar SP v5 con el nuevo formato SERV-2026-001.1 ===== */
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        /* Validaciones mínimas */
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

        /* Cuentas adicionales JSON — enviar las 3 cuentas automáticamente */
        $cuentasAdicionalesJson = null;
        if (!empty($body['cuentas_adicionales']) && is_array($body['cuentas_adicionales'])) {
            $cuentasAdicionalesJson = json_encode(array_values($body['cuentas_adicionales']));
        } elseif (!empty($body['cuentas_adicionales_json'])) {
            $cuentasAdicionalesJson = $body['cuentas_adicionales_json'];
        } elseif (!empty($body['id_cuenta_bancaria'])) {
            /* Si solo viene la cuenta principal, buscar las otras 2 automáticamente */
            try {
                $stmtCuentas = $pdo->prepare(
                    "SELECT id_cuenta FROM dbo.cotizacion_cuenta_bancaria
                     WHERE id_emisora = ? AND activo = 1 AND id_cuenta <> ?
                     ORDER BY orden, id_cuenta
                     OFFSET 0 ROWS FETCH NEXT 2 ROWS ONLY"
                );
                $stmtCuentas->bindValue(1, (int)$body['id_emisora'], PDO::PARAM_INT);
                $stmtCuentas->bindValue(2, (int)$body['id_cuenta_bancaria'], PDO::PARAM_INT);
                $stmtCuentas->execute();
                $adicionales = $stmtCuentas->fetchAll(PDO::FETCH_COLUMN, 0);
                if (!empty($adicionales)) {
                    $cuentasAdicionalesJson = json_encode($adicionales);
                }
            } catch (Throwable $e) {
                /* Si falla, continuar sin cuentas adicionales */
            }
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
            error_log('[Cotizaciones POST v5] Error: ' . $e->getMessage());
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
    /* Si no existe v5, el archivo original continúa su flujo con v4 */
}

/* ============================================================================
 * PATCH /cotizaciones/{id}/status — Cambiar estado (manejo mejorado)
 *
 * El SP sp_cotizacion_update_status ahora devuelve un result set con
 * success=0 y mensaje en caso de error, en vez de lanzar THROW (que
 * causaba error 500 genérico en PHP).
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
            /* El SP devolvió success=0 con un mensaje claro */
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
        error_log('[Cotizaciones status] Error: ' . $e->getMessage());
        ApiResponse::error('status_failed', $e->getMessage(), 500);
    }
}

/* ============================================================================
 * GET /cotizaciones/filtro_empresa — Listado para sección comercial
 *
 * Devuelve cotizaciones filtradas por empresa (con búsqueda por código,
 * razón social, etc.). Para la vista de "Seguimiento comercial por empresa".
 *
 * Parámetros:
 *   ?id_empresa=1            Filtra por empresa específica
 *   ?query=xxx               Búsqueda por código, razón social, RUC
 *   ?estado=APROBADA         Filtra por estado
 *   ?page=1&per_page=50      Paginación
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

        /* Construir consulta dinámica con parámetros */
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

        /* Total */
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

        /* Página actual */
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
