<?php
/**
 * api/routes/cotizaciones_seguimiento.php
 * ----------------------------------------------------------------------------
 * Endpoints NUEVOS para el seguimiento comercial de cotizaciones.
 *
 * Este archivo debe ser incluido DESPUÉS de cargar api/routes/cotizaciones.php
 * para añadir los endpoints faltantes sin modificar el archivo original.
 *
 * Endpoints:
 *   GET    /cotizaciones/{id}/seguimientos          Listar seguimientos
 *   GET    /cotizaciones/{id}/seguimientos/count    Contador y próxima fecha
 *   POST   /cotizaciones/{id}/seguimientos          Registrar seguimiento
 *   GET    /cotizaciones/preview_codigo             Previsualizar código
 *          ?id_empresa=1&id_tipo=1
 *   GET    /cotizaciones/{id}/cuentas_bancarias     Cuentas bancarias
 *                                                    (3 predeterminadas)
 *
 * SPs USADOS (todos creados en los scripts SQL 02-04):
 *   - sp_cotizacion_list_seguimientos
 *   - sp_cotizacion_count_seguimientos
 *   - sp_cotizacion_add_comunicacion_v2
 *   - sp_cotizacion_preview_codigo
 */

/* Solo procesar si el módulo cotizaciones ya se cargó */
if (!isset($userId) || $userId <= 0) {
    return; // el control lo tiene el archivo principal
}

$method = $_SERVER['REQUEST_METHOD'];

/* ============================================================================
 * GET /cotizaciones/preview_codigo?id_empresa=&id_tipo=
 * Devuelve el código que se generaría si se creara la cotización ahora.
 * No modifica nada. Solo lectura.
 * ==========================================================================*/
if ($method === 'GET'
    && $id === 'preview_codigo'
    && isset($_GET['id_empresa'])
    && isset($_GET['id_tipo'])) {

    Auth::requirePermission('COTIZACIONES', 'ver');

    try {
        $pdo = Database::connection();

        /* Detectar si existe el SP v5 */
        $chk = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_preview_codigo','P') IS NOT NULL THEN 1 ELSE 0 END");
        $spExiste = (int)$chk->fetchColumn();

        if (!$spExiste) {
            ApiResponse::error('sp_no_existe',
                'El procedimiento sp_cotizacion_preview_codigo no existe. Ejecute el script SQL 02.',
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
 * GET /cotizaciones/{id}/seguimientos — Listar historial completo
 * ==========================================================================*/
if ($method === 'GET' && is_numeric($id) && $action === 'seguimientos') {
    Auth::requirePermission('COTIZACIONES', 'ver');

    try {
        $pdo = Database::connection();

        /* Si existe el SP nuevo, usarlo; si no, usar el SP de comunicaciones viejo */
        $chk = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_list_seguimientos','P') IS NOT NULL THEN 1 ELSE 0 END");
        $spNuevo = (int)$chk->fetchColumn();

        if ($spNuevo) {
            $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_list_seguimientos @id_cotizacion = ?");
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } else {
            /* Fallback: usar el result set 4 de sp_cotizacion_get (comunicaciones) */
            $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_get @id_cotizacion = ?");
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->execute();
            $stmt->nextRowset(); // detalle
            $stmt->nextRowset(); // historial
            $stmt->nextRowset(); // comunicaciones (lo que queremos)
            $rows = $stmt->fetchAll();
        }

        ApiResponse::success($rows, 200);
    } catch (Throwable $e) {
        ApiResponse::error('seguimientos_list_failed', $e->getMessage(), 500);
    }
}

/* ============================================================================
 * GET /cotizaciones/{id}/seguimientos/count — Contador rápido
 * ==========================================================================*/
if ($method === 'GET' && is_numeric($id) && $action === 'seguimientos'
    && isset($segments[3]) && $segments[3] === 'count') {

    Auth::requirePermission('COTIZACIONES', 'ver');

    try {
        $pdo = Database::connection();
        $chk = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_count_seguimientos','P') IS NOT NULL THEN 1 ELSE 0 END");
        $spExiste = (int)$chk->fetchColumn();

        if ($spExiste) {
            $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_count_seguimientos @id_cotizacion = ?");
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS total_seguimientos,
                                          MAX(fecha_registro) AS ultima_comunicacion,
                                          MAX(fecha_proximo_seguimiento) AS proximo_seguimiento
                                   FROM dbo.cotizacion_comunicacion
                                   WHERE id_cotizacion = ?");
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
        }

        ApiResponse::success([
            'total'                => (int)($row['total_seguimientos'] ?? 0),
            'ultima_comunicacion'  => $row['ultima_comunicacion'] ?? null,
            'proximo_seguimiento'  => $row['proximo_seguimiento'] ?? null,
        ], 200);
    } catch (Throwable $e) {
        ApiResponse::error('seguimientos_count_failed', $e->getMessage(), 500);
    }
}

/* ============================================================================
 * POST /cotizaciones/{id}/seguimientos — Registrar seguimiento
 *
 * Body:
 *   {
 *     "medio": "LLAMADA_TELEFONICA",
 *     "resultado_comunicacion": "CLIENTE_INTERESADO",
 *     "descripcion": "Cliente revisó la propuesta...",
 *     "persona_contactada": "Juan Pérez",
 *     "fecha_comunicacion": "2026-10-28T10:00:00",   // opcional, default now()
 *     "fecha_proximo_seguimiento": "2026-11-02",      // opcional
 *     "compromiso_fecha": null,
 *     "observaciones": "..."
 *   }
 * ==========================================================================*/
if ($method === 'POST' && is_numeric($id) && $action === 'seguimientos') {
    Auth::requirePermission('COTIZACIONES', 'modificar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['medio'])) {
        ApiResponse::validationError(['medio' => 'Requerido']);
    }

    try {
        $pdo = Database::connection();

        /* Detectar SP v2 (con nuevos campos) */
        $chk = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_add_comunicacion_v2','P') IS NOT NULL THEN 1 ELSE 0 END");
        $spV2 = (int)$chk->fetchColumn();

        if ($spV2) {
            /* SP nuevo con todos los campos */
            $stmt = $pdo->prepare(
                'EXEC dbo.sp_cotizacion_add_comunicacion_v2
                    @id_cotizacion = ?, @id_usuario = ?,
                    @medio = ?, @tipo_comunicacion = ?, @descripcion = ?,
                    @fecha_comunicacion = ?, @respuesta_cliente = ?, @resultado_compromiso = ?,
                    @fecha_proximo_seguimiento = ?, @observaciones = ?,
                    @persona_contactada = ?, @resultado_comunicacion = ?, @compromiso_fecha = ?,
                    @id_comunicacion = ?'
            );
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->bindValue(2, $userId, PDO::PARAM_INT);
            $stmt->bindValue(3, $body['medio'], PDO::PARAM_STR);
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
            $row = $stmt->fetch();
        } else {
            /* SP viejo: usar campos existentes */
            $stmt = $pdo->prepare(
                'EXEC dbo.sp_cotizacion_add_comunicacion
                    @id_cotizacion = ?, @id_usuario = ?,
                    @medio = ?, @tipo_comunicacion = ?, @descripcion = ?,
                    @fecha_comunicacion = ?, @respuesta_cliente = ?, @resultado_compromiso = ?,
                    @fecha_proximo_seguimiento = ?, @observaciones = ?,
                    @id_comunicacion = ?'
            );
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->bindValue(2, $userId, PDO::PARAM_INT);
            $stmt->bindValue(3, $body['medio'], PDO::PARAM_STR);
            $stmt->bindValue(4, $body['tipo_comunicacion'] ?? $body['resultado_comunicacion'] ?? 'SEGUIMIENTO', PDO::PARAM_STR);
            $stmt->bindValue(5, $body['descripcion'] ?? ('Seguimiento: ' . ($body['resultado_comunicacion'] ?? '')), PDO::PARAM_STR);
            $stmt->bindValue(6, $body['fecha_comunicacion'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(7, $body['respuesta_cliente'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(8, $body['resultado_compromiso'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(9, $body['fecha_proximo_seguimiento'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(10, $body['observaciones'] ?? null, PDO::PARAM_STR);
            $idCom = null;
            $stmt->bindParam(11, $idCom, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
            $stmt->execute();
            $row = $stmt->fetch();
        }

        Audit::log('COTIZACIONES', 'SEGUIMIENTO',
            'Seguimiento registrado en cotización #' . $id,
            'cotizacion', (string)$id,
            ['medio' => $body['medio'],
             'resultado' => $body['resultado_comunicacion'] ?? null]);

        ApiResponse::success(
            ['id_comunicacion' => (int)($row['id_comunicacion'] ?? $idCom)],
            201,
            'Seguimiento registrado'
        );
    } catch (Throwable $e) {
        ApiResponse::error('seguimiento_failed', $e->getMessage(), 422);
    }
}

/* ============================================================================
 * GET /cotizaciones/{id}/cuentas_bancarias — 3 cuentas predeterminadas
 *
 * Devuelve las cuentas bancarias activas de la empresa emisora de la
 * cotización, ordenadas por el campo `orden`. El frontend las muestra
 * automáticamente sin necesidad de un combo box.
 * ==========================================================================*/
if ($method === 'GET' && is_numeric($id) && $action === 'cuentas_bancarias') {
    Auth::requirePermission('COTIZACIONES', 'ver');

    try {
        $pdo = Database::connection();

        /* Obtener id_emisora de la cotización */
        $stmtEmi = $pdo->prepare("SELECT id_emisora, id_cuenta_bancaria, cuentas_adicionales_json
                                  FROM dbo.cotizacion WHERE id_cotizacion = ?");
        $stmtEmi->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmtEmi->execute();
        $cot = $stmtEmi->fetch();

        if (!$cot) {
            ApiResponse::error('not_found', 'Cotización no encontrada', 404);
        }

        $idEmisora = (int)$cot['id_emisora'];

        /* Listar TODAS las cuentas activas de la emisora, ordenadas por `orden` */
        $stmt = $pdo->prepare(
            "SELECT id_cuenta, id_emisora, banco, numero_cuenta, cci, moneda,
                    titular, acepta_tarjeta, orden, activo
             FROM dbo.cotizacion_cuenta_bancaria
             WHERE id_emisora = ?
               AND activo = 1
             ORDER BY orden, id_cuenta"
        );
        $stmt->bindValue(1, $idEmisora, PDO::PARAM_INT);
        $stmt->execute();
        $cuentas = $stmt->fetchAll();

        ApiResponse::success([
            'cuentas'                => $cuentas,
            'id_cuenta_principal'    => $cot['id_cuenta_bancaria'] ?? null,
            'cuentas_adicionales'    => $cot['cuentas_adicionales_json'] ?? null,
            'total_cuentas'          => count($cuentas),
        ], 200);
    } catch (Throwable $e) {
        ApiResponse::error('cuentas_failed', $e->getMessage(), 500);
    }
}
