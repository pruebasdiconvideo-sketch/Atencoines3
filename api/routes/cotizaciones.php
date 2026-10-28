<?php
/**
 * api/routes/cotizaciones.php v1.0
 * ----------------------------------------------------------------------------
 * Módulo de Cotizaciones Comerciales — API REST
 *
 * Requiere permiso COTIZACIONES:ver (o ser super-admin).
 *
 * Endpoints:
 *   GET    /cotizaciones                            Listado paginado con filtros
 *   POST   /cotizaciones                            Crear cotización (con detalle JSON)
 *   GET    /cotizaciones/catalogos                  Devuelve todos los catálogos
 *   GET    /cotizaciones/productos                  Catálogo de productos (con filtros)
 *   GET    /cotizaciones/{id}                       Detalle completo (6 result sets)
 *   PATCH  /cotizaciones/{id}                       Actualizar cabecera + detalle
 *   PATCH  /cotizaciones/{id}/status                Cambiar estado
 *   POST   /cotizaciones/{id}/comunicaciones         Agregar comunicación
 *   POST   /cotizaciones/{id}/versiones              Crear nueva versión (.2, .3)
 *   GET    /cotizaciones/{id}/export/pdf             Exportar a PDF
 *   GET    /cotizaciones/{id}/export/xlsx            Exportar a Excel
 *   GET    /cotizaciones/{id}/export/csv             Exportar a CSV
 *   POST   /cotizaciones/{id}/files                  Subir documento adjunto
 *   GET    /cotizaciones/{id}/files                 Listar documentos
 *   GET    /cotizaciones/{id}/files/{fileId}         Descargar documento
 *   DELETE /cotizaciones/{id}                        Soft-delete (admin)
 *   POST   /cotizaciones/export/job                  Encolar exportación masiva (job)
 *
 * Reglas de seguridad:
 *   - El id_usuario SIEMPRE se toma de $_SESSION['user']['id_persona'] o del
 *     token Bearer. NUNCA del cuerpo JSON.
 *   - Solo el admin puede eliminar.
 *   - Solo roles con permiso COTIZACIONES:aprobar pueden aprobar.
 *   - Permisos se validan con Auth::requirePermission('COTIZACIONES', $accion).
 */

// Carga condicional del CotizacionExporter (solo se necesita en export PDF/Excel/CSV).
// Si el archivo no existe o tiene error de sintaxis, los endpoints de exportación
// devolverán un error claro, pero los endpoints de listado/CRUD seguirán funcionando.
$__cotizacionExporterPath = __DIR__ . '/../../includes/CotizacionExporter.php';
if (is_file($__cotizacionExporterPath)) {
    try {
        require_once $__cotizacionExporterPath;
    } catch (\Throwable $__e) {
        error_log('[Cotizaciones] No se pudo cargar CotizacionExporter.php: ' . $__e->getMessage());
    }
}

Auth::requirePermission('COTIZACIONES', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;
$fileId = $segments[3] ?? null;
$userId = (int)($user['id_persona'] ?? 0);

if ($userId <= 0) ApiResponse::error('unauthorized', 'Sesión inválida.', 401);

/* ============================================================================
 * GET /cotizaciones/diagnostico — Verifica que todos los SPs y tablas existen
 *
 * Útil cuando el listado devuelve 500: este endpoint muestra exactamente
 * qué SP falta o qué tabla no existe, en lugar del error genérico.
 * ============================================================================
 */
if ($method === 'GET' && $id === 'diagnostico') {
    $pdo = Database::connection();
    $diagnostico = [
        'tablas' => [],
        'sps' => [],
        'catalogos' => [],
        'errores' => [],
        'recomendacion' => null,
    ];

    // Verificar tablas
    $tablasEsperadas = [
        'cotizacion_tipo_catalogo', 'cotizacion_empresa_emisora', 'cotizacion_zona_pricing',
        'cotizacion_producto_catalogo', 'cotizacion_producto_precio_zona', 'cotizacion_forma_pago',
        'cotizacion_garantia', 'cotizacion_plazo_entrega', 'cotizacion_validez',
        'cotizacion_cuenta_bancaria', 'cotizacion_correlativo_anual', 'cotizacion',
        'cotizacion_version', 'cotizacion_detalle', 'cotizacion_comunicacion',
        'cotizacion_historial_estado', 'cotizacion_documento',
    ];
    foreach ($tablasEsperadas as $t) {
        $stmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.$t','U') IS NOT NULL THEN 1 ELSE 0 END AS existe");
        $stmt->execute();
        $existe = (int)$stmt->fetchColumn();
        $diagnostico['tablas'][$t] = $existe === 1 ? 'OK' : 'FALTA';
        if ($existe !== 1) $diagnostico['errores'][] = "Falta tabla: dbo.$t";
    }

    // Verificar SPs
    $spsEsperados = [
        'sp_cotizacion_correlativo_siguiente', 'sp_cotizacion_calcular_totales',
        'sp_cotizacion_create', 'sp_cotizacion_get', 'sp_cotizacion_list_paged',
        'sp_cotizacion_update_status', 'sp_cotizacion_add_comunicacion',
        'sp_cotizacion_update', 'sp_cotizacion_documento_save',
        'sp_cotizacion_documento_get', 'sp_cotizacion_delete_admin',
        'sp_cotizacion_catalogos_listar', 'sp_cotizacion_producto_listar',
    ];
    foreach ($spsEsperados as $sp) {
        $stmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.$sp','P') IS NOT NULL THEN 1 ELSE 0 END AS existe");
        $stmt->execute();
        $existe = (int)$stmt->fetchColumn();
        $diagnostico['sps'][$sp] = $existe === 1 ? 'OK' : 'FALTA';
        if ($existe !== 1) $diagnostico['errores'][] = "Falta SP: dbo.$sp";
    }

    // Verificar catálogos con datos
    $catalogos = [
        'cotizacion_tipo_catalogo' => 'tipos',
        'cotizacion_empresa_emisora' => 'emisoras',
        'cotizacion_zona_pricing' => 'zonas',
        'cotizacion_producto_catalogo' => 'productos',
        'cotizacion_forma_pago' => 'formas_pago',
        'cotizacion_garantia' => 'garantias',
        'cotizacion_plazo_entrega' => 'plazos',
        'cotizacion_validez' => 'valideces',
        'cotizacion_cuenta_bancaria' => 'cuentas',
    ];
    foreach ($catalogos as $tabla => $label) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM dbo.$tabla");
            $n = (int)$stmt->fetchColumn();
            $diagnostico['catalogos'][$label] = $n;
            if ($n === 0) $diagnostico['errores'][] = "Catálogo vacío: dbo.$tabla (ejecuta 02_cotizaciones_seed_catalogos.sql)";
        } catch (Throwable $e) {
            $diagnostico['catalogos'][$label] = 'ERROR: ' . $e->getMessage();
            $diagnostico['errores'][] = "No se pudo leer dbo.$tabla: " . $e->getMessage();
        }
    }

    // Verificar permisos del usuario actual
    try {
        $stmt = $pdo->prepare("SELECT TOP 1 pra.id_rol, pr.nombre AS rol
                               FROM dbo.persona_rol_asignacion pra
                               INNER JOIN dbo.persona_rol pr ON pra.id_rol = pr.id_rol
                               WHERE pra.id_persona = ?
                               ORDER BY pra.fecha_asignacion DESC");
        $stmt->execute([$userId]);
        $rol = $stmt->fetch();
        $diagnostico['usuario'] = [
            'id_persona' => $userId,
            'rol' => $rol ? $rol['rol_nombre'] : 'SIN_ROL',
            'id_rol' => $rol ? (int)$rol['id_rol'] : null,
        ];
    } catch (Throwable $e) {
        $diagnostico['usuario'] = ['error' => $e->getMessage()];
    }

    // Verificar permisos del rol sobre COTIZACIONES
    try {
        $stmt = $pdo->prepare("SELECT ver, registrar, modificar, eliminar, aprobar, exportar, imprimir
                               FROM dbo.role_permissions
                               WHERE modulo = 'COTIZACIONES'
                                 AND id_rol = (SELECT TOP 1 pra.id_rol
                                               FROM dbo.persona_rol_asignacion pra
                                               WHERE pra.id_persona = ?
                                               ORDER BY pra.fecha_asignacion DESC)");
        $stmt->execute([$userId]);
        $perm = $stmt->fetch();
        $diagnostico['permisos_cotizaciones'] = $perm ?: 'SIN_PERMISOS';
    } catch (Throwable $e) {
        $diagnostico['permisos_cotizaciones'] = 'ERROR: ' . $e->getMessage();
    }

    // Recomendación final
    if (count($diagnostico['errores']) === 0) {
        $diagnostico['recomendacion'] = 'Todo OK. Si el listado sigue fallando, revisa storage/logs/api-errors.log';
    } else {
        $diagnostico['recomendacion'] = 'Ejecuta en SSMS en orden: 00_cotizaciones_drop_all.sql (limpia todo), luego 01 a 05. Verifica que sp_cotizacion_list_paged existe.';
    }

    ApiResponse::success($diagnostico, 200, 'Diagnóstico del módulo de cotizaciones');
}

/* ============================================================================
 * GET /cotizaciones/catalogos — Devuelve todos los catálogos en una llamada
 * ============================================================================
 */
if ($method === 'GET' && $id === 'catalogos') {
    Auth::requirePermission('COTIZACIONES', 'ver');

    $pdo = Database::connection();
    $stmt = $pdo->prepare('EXEC dbo.sp_cotizacion_catalogos_listar');
    $stmt->execute();

    $tipos        = $stmt->fetchAll();
    $stmt->nextRowset(); $emisoras      = $stmt->fetchAll();
    $stmt->nextRowset(); $zonas         = $stmt->fetchAll();
    $stmt->nextRowset(); $formasPago    = $stmt->fetchAll();
    $stmt->nextRowset(); $garantias     = $stmt->fetchAll();
    $stmt->nextRowset(); $plazos        = $stmt->fetchAll();
    $stmt->nextRowset(); $valideces     = $stmt->fetchAll();
    $stmt->nextRowset(); $cuentas       = $stmt->fetchAll();

    ApiResponse::success([
        'tipos'        => $tipos,
        'emisoras'     => $emisoras,
        'zonas'        => $zonas,
        'formas_pago'  => $formasPago,
        'garantias'    => $garantias,
        'plazos'       => $plazos,
        'valideces'    => $valideces,
        'cuentas'      => $cuentas,
    ], 200, 'Catálogos de cotizaciones');
}

/* ============================================================================
 * GET /cotizaciones/productos — Catálogo de productos con filtros
 * ============================================================================
 */
if ($method === 'GET' && $id === 'productos') {
    Auth::requirePermission('COTIZACIONES', 'ver');

    $rows = Database::callSP('sp_cotizacion_producto_listar', [
        $_GET['tipo_producto'] ?? null,
        $_GET['search'] ?? null,
        !empty($_GET['incluir_inactivos']) ? 1 : 0,
        (int)($_GET['limite'] ?? 500),
    ]);

    ApiResponse::success($rows, 200, 'Catálogo de productos');
}

/* ============================================================================
 * GET /cotizaciones — Listado paginado
 * ============================================================================
 */
if ($method === 'GET' && $id === null) {
    Auth::requirePermission('COTIZACIONES', 'ver');

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
    $incluirEliminados = (!empty($_GET['incluir_eliminados']) && Auth::hasPermission('COTIZACIONES', 'eliminar')) ? 1 : 0;

    // Verificación previa: ¿existe el SP?
    $pdo = Database::connection();
    $checkStmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_list_paged','P') IS NOT NULL THEN 1 ELSE 0 END AS existe");
    $checkStmt->execute();
    if ((int)$checkStmt->fetchColumn() !== 1) {
        ApiResponse::error(
            'sp_missing',
            'El Stored Procedure sp_cotizacion_list_paged NO existe. Ejecuta en SSMS: 00_cotizaciones_drop_all.sql y luego 05_cotizaciones_sps_parte2.sql. Verifica con GET /cotizaciones/diagnostico.',
            500,
            ['sp_faltante' => 'sp_cotizacion_list_paged']
        );
    }

    try {
        $stmt = $pdo->prepare(
            'EXEC dbo.sp_cotizacion_list_paged
                @estado = ?, @id_empresa = ?, @id_tipo = ?,
                @id_usuario_creador = ?, @id_vendedor = ?,
                @fecha_desde = ?, @fecha_hasta = ?, @search_term = ?,
                @incluir_eliminados = ?, @anio = ?,
                @page = ?, @per_page = ?'
        );

        $stmt->bindValue(1,  isset($_GET['estado'])     ? $_GET['estado']    : null, PDO::PARAM_STR);
        $stmt->bindValue(2,  isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : null, PDO::PARAM_INT);
        $stmt->bindValue(3,  isset($_GET['id_tipo'])    ? (int)$_GET['id_tipo']    : null, PDO::PARAM_INT);
        $stmt->bindValue(4,  isset($_GET['id_usuario']) ? (int)$_GET['id_usuario'] : null, PDO::PARAM_INT);
        $stmt->bindValue(5,  isset($_GET['id_vendedor'])? (int)$_GET['id_vendedor']: null, PDO::PARAM_INT);
        $stmt->bindValue(6,  isset($_GET['fecha_desde'])? $_GET['fecha_desde']    : null, PDO::PARAM_STR);
        $stmt->bindValue(7,  isset($_GET['fecha_hasta'])? $_GET['fecha_hasta']    : null, PDO::PARAM_STR);
        $stmt->bindValue(8,  isset($_GET['search'])     ? $_GET['search']     : null, PDO::PARAM_STR);
        $stmt->bindValue(9,  $incluirEliminados, PDO::PARAM_INT);
        $stmt->bindValue(10, isset($_GET['anio'])       ? (int)$_GET['anio']  : null, PDO::PARAM_INT);
        $stmt->bindValue(11, $page,    PDO::PARAM_INT);
        $stmt->bindValue(12, $perPage, PDO::PARAM_INT);
        $stmt->execute();

        $rows  = $stmt->fetchAll();
        $stmt->nextRowset();
        $totalRow = $stmt->fetch();
        $total = (int)($totalRow['total'] ?? count($rows));

        ApiResponse::paginated($rows, $total, $page, $perPage);
    } catch (Throwable $e) {
        // Fallback: consulta directa sin SP
        $msg = $e->getMessage();
        error_log('[Cotizaciones GET list] SP falló, usando fallback: ' . $msg);

        try {
            $where = " WHERE 1=1";
            if (!$incluirEliminados) $where .= " AND ISNULL(c.eliminado, 0) = 0";
            $params = [];
            if (!empty($_GET['estado']))     { $where .= " AND c.estado = ?"; $params[] = $_GET['estado']; }
            if (!empty($_GET['id_empresa'])) { $where .= " AND c.id_empresa = ?"; $params[] = (int)$_GET['id_empresa']; }
            if (!empty($_GET['id_tipo']))    { $where .= " AND c.id_tipo = ?"; $params[] = (int)$_GET['id_tipo']; }
            if (!empty($_GET['id_usuario'])) { $where .= " AND c.id_usuario_creador = ?"; $params[] = (int)$_GET['id_usuario']; }
            if (!empty($_GET['id_vendedor'])){ $where .= " AND c.id_vendedor = ?"; $params[] = (int)$_GET['id_vendedor']; }
            if (!empty($_GET['fecha_desde'])){ $where .= " AND CAST(c.fecha_creacion AS DATE) >= ?"; $params[] = $_GET['fecha_desde']; }
            if (!empty($_GET['fecha_hasta'])){ $where .= " AND CAST(c.fecha_creacion AS DATE) <= ?"; $params[] = $_GET['fecha_hasta']; }
            if (!empty($_GET['anio']))       { $where .= " AND YEAR(c.fecha_creacion) = ?"; $params[] = (int)$_GET['anio']; }
            if (!empty($_GET['search']))     {
                $where .= " AND (c.codigo LIKE ? OR c.titulo_documento LIKE ? OR e.razon_social LIKE ? OR e.ruc LIKE ?)";
                $search = '%' . $_GET['search'] . '%';
                $params[] = $search; $params[] = $search; $params[] = $search; $params[] = $search;
            }

            $sql = "SELECT c.id_cotizacion, c.codigo, c.version_numero, c.estado,
                    CONVERT(VARCHAR(10), c.fecha_creacion, 23) AS fecha_creacion,
                    c.fecha_envio, c.fecha_aprobacion, c.fecha_cancelacion, c.fecha_cierre,
                    c.fecha_proximo_seguimiento, c.titulo_documento, c.total, c.moneda,
                    c.id_empresa, c.id_tipo, c.id_vendedor, c.id_usuario_creador,
                    e.razon_social AS empresa_razon_social, e.nombre_comercial AS empresa_nombre_comercial,
                    e.ruc AS empresa_ruc, tc.codigo AS tipo_codigo, tc.nombre AS tipo_nombre,
                    p.nombres + ' ' + p.apellido_paterno AS vendedor_nombre,
                    pc.nombres + ' ' + pc.apellido_paterno AS creador_nombre
                    FROM dbo.cotizacion c
                    INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
                    INNER JOIN dbo.cotizacion_tipo_catalogo tc ON c.id_tipo = tc.id_tipo
                    LEFT JOIN dbo.persona p ON c.id_vendedor = p.id_persona
                    LEFT JOIN dbo.persona pc ON c.id_usuario_creador = pc.id_persona
                    $where
                    ORDER BY c.id_cotizacion DESC
                    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";

            $stmt2 = $pdo->prepare($sql);
            // IMPORTANTE: los placeholders ? aparecen en orden: primero los del
            // WHERE (uno por cada filtro activo), después OFFSET y FETCH.
            // Por eso bindiamos en ese mismo orden: WHERE params, luego OFFSET, luego FETCH.
            $bindIdx = 1;
            foreach ($params as $v) {
                $stmt2->bindValue($bindIdx++, $v);
            }
            $stmt2->bindValue($bindIdx++, ($page - 1) * $perPage, PDO::PARAM_INT);
            $stmt2->bindValue($bindIdx++, $perPage, PDO::PARAM_INT);
            $stmt2->execute();
            $rows = $stmt2->fetchAll();

            // Total
            $countSql = "SELECT COUNT(*) FROM dbo.cotizacion c INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa $where";
            $countStmt = $pdo->prepare($countSql);
            foreach ($params as $i => $v) {
                $countStmt->bindValue($i + 1, $v);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

            ApiResponse::paginated($rows, $total, $page, $perPage);
        } catch (Throwable $e2) {
            ApiResponse::error(
                'cotizacion_list_failed',
                'Error al listar cotizaciones: ' . $e2->getMessage() . '. Ejecuta script SQL 11_cotizaciones_fix_sp_list_paged.sql en SSMS.',
                500,
                ['sql_error' => $e2->getMessage()]
            );
        }
    }
}

/* ============================================================================
 * POST /cotizaciones — Crear cotización
 * ============================================================================
 */
if ($method === 'POST' && $id === null) {
    Auth::requirePermission('COTIZACIONES', 'registrar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    foreach (['id_empresa','id_tipo','id_emisora'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }

    $detalleJson = isset($body['detalle']) ? json_encode($body['detalle'], JSON_UNESCAPED_UNICODE) : null;

    // Cuentas adicionales (array de IDs): se convierten a JSON para pasarlas al SP
    $cuentasAdicionalesJson = null;
    if (!empty($body['cuentas_adicionales']) && is_array($body['cuentas_adicionales'])) {
        $ids = [];
        foreach ($body['cuentas_adicionales'] as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) $ids[] = $cid;
        }
        if (!empty($ids)) $cuentasAdicionalesJson = json_encode(array_values($ids));
    }

    $pdo = Database::connection();

    // Detectar cuál SP usar: v4 (fix FK), v3, v2 o v1
    $checkStmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_create_v4','P') IS NOT NULL THEN 1 ELSE 0 END AS existe");
    $checkStmt->execute();
    $usaV4 = (int)$checkStmt->fetchColumn() === 1;

    $checkStmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_create_v3','P') IS NOT NULL THEN 1 ELSE 0 END AS existe");
    $checkStmt->execute();
    $usaV3 = (int)$checkStmt->fetchColumn() === 1;

    $checkStmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_create_v2','P') IS NOT NULL THEN 1 ELSE 0 END AS existe");
    $checkStmt->execute();
    $usaV2 = (int)$checkStmt->fetchColumn() === 1;

    $spName = $usaV4 ? 'sp_cotizacion_create_v4' : ($usaV3 ? 'sp_cotizacion_create_v3' : ($usaV2 ? 'sp_cotizacion_create_v2' : 'sp_cotizacion_create'));

    $stmt = $pdo->prepare(
        'EXEC dbo.' . $spName . '
                @id_empresa = ?, @id_empresa_contacto = ?, @id_sucursal = ?,
                @id_tipo = ?, @id_emisora = ?,
                @id_forma_pago = ?, @id_garantia = ?, @id_plazo_entrega = ?,
                @id_validez = ?, @id_cuenta_bancaria = ?, @cuentas_adicionales_json = ?,
                @id_zona_pricing = ?,
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
    $stmt->bindValue(12, isset($body['id_zona_pricing'])   ? (int)$body['id_zona_pricing']   : null, PDO::PARAM_INT);
    $stmt->bindValue(13, $userId,                            PDO::PARAM_INT);
    $stmt->bindValue(14, isset($body['id_vendedor'])        ? (int)$body['id_vendedor'] : null, PDO::PARAM_INT);
    $stmt->bindValue(15, $body['titulo_documento'] ?? null,  PDO::PARAM_STR);
    $stmt->bindValue(16, $body['motivo'] ?? null,             PDO::PARAM_STR);
    $stmt->bindValue(17, $body['observaciones'] ?? null,      PDO::PARAM_STR);
    $stmt->bindValue(18, $body['moneda'] ?? 'PEN',            PDO::PARAM_STR);
    $stmt->bindValue(19, isset($body['igv_pct']) ? (float)$body['igv_pct'] : 18.00, PDO::PARAM_STR);
    $stmt->bindValue(20, isset($body['descuento_global_pct']) ? (float)$body['descuento_global_pct'] : 0.00, PDO::PARAM_STR);
    $stmt->bindValue(21, $detalleJson,                        PDO::PARAM_STR);

    $codigo = null;
    $idCot  = null;
    $stmt->bindParam(22, $codigo, PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 40);
    $stmt->bindParam(23, $idCot,  PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);

    try {
        $stmt->execute();
        // El SP llama internamente a sp_cotizacion_calcular_totales que devuelve un result set
        // con los totales. El result set con id_cotizacion puede estar en el segundo o tercer set.
        $row = $stmt->fetch();
        // Si el primer result set no tiene id_cotizacion, buscar en los siguientes
        if (!$row || !isset($row['id_cotizacion'])) {
            try { $stmt->nextRowset(); $row = $stmt->fetch(); } catch (Throwable $e2) {}
        }
        if (!$row || !isset($row['id_cotizacion'])) {
            try { $stmt->nextRowset(); $row = $stmt->fetch(); } catch (Throwable $e3) {}
        }
    } catch (Throwable $e) {
        error_log('[Cotizaciones POST] Error sp_cotizacion_create: ' . $e->getMessage());
        ApiResponse::error('cotizacion_create_failed', 'No se pudo crear: ' . $e->getMessage(), 422);
    }

    // Si todavía no tenemos id_cotizacion, intentar con SCOPE_IDENTITY o consulta directa
    $idNew = (int)($row['id_cotizacion'] ?? $idCot ?? 0);
    $codNew = $row['codigo'] ?? $codigo ?? '';

    // Fallback: si id_cotizacion es 0, intentar obtenerlo directamente
    if (!$idNew) {
        try {
            $lastStmt = $pdo->query("SELECT TOP 1 id_cotizacion, codigo FROM dbo.cotizacion ORDER BY id_cotizacion DESC");
            $lastRow = $lastStmt->fetch();
            if ($lastRow) {
                $idNew = (int)$lastRow['id_cotizacion'];
                $codNew = $lastRow['codigo'];
            }
        } catch (Throwable $e) {}
    }

    if (!$idNew) {
        ApiResponse::error('cotizacion_create_failed', 'El SP no devolvió id_cotizacion. Verifica que el SP v4 existe (ejecuta script 10).', 422);
    }

    Audit::log('COTIZACIONES', 'CREAR', 'Cotización ' . $codNew . ' creada',
        'cotizacion', (string)$idNew,
        ['codigo' => $codNew, 'id_empresa' => $body['id_empresa'], 'id_tipo' => $body['id_tipo']]);

    ApiResponse::success(['id_cotizacion' => $idNew, 'codigo' => $codNew], 201, 'Cotización creada');
}

/* ============================================================================
 * POST /cotizaciones/formas-pago — Agregar nueva forma de pago (catálogo)
 * ============================================================================
 */
if ($method === 'POST' && $id === 'formas-pago') {
    Auth::requirePermission('COTIZACIONES', 'modificar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['descripcion'])) {
        ApiResponse::validationError(['descripcion' => 'Requerido']);
    }

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'EXEC dbo.sp_cotizacion_forma_pago_add
                @descripcion = ?, @descuento_pct = ?, @id_usuario = ?,
                @id_forma_pago = ?'
        );
        $stmt->bindValue(1, $body['descripcion'], PDO::PARAM_STR);
        $stmt->bindValue(2, isset($body['descuento_pct']) ? (float)$body['descuento_pct'] : 0, PDO::PARAM_STR);
        $stmt->bindValue(3, $userId, PDO::PARAM_INT);
        $idForma = null;
        $stmt->bindParam(4, $idForma, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
        $row = $stmt->fetch();

        Audit::log('COTIZACIONES', 'CREAR_FORMA_PAGO',
            'Forma de pago creada: ' . $body['descripcion'],
            'cotizacion_forma_pago', (string)($row['id_forma_pago'] ?? $idForma));

        ApiResponse::success($row, 201, 'Forma de pago creada');
    } catch (Throwable $e) {
        ApiResponse::error('forma_pago_create_failed', $e->getMessage(), 422);
    }
}

/* ============================================================================
 * POST /cotizaciones/cuentas-bancarias — Agregar nueva cuenta bancaria
 * ============================================================================
 */
if ($method === 'POST' && $id === 'cuentas-bancarias') {
    Auth::requirePermission('COTIZACIONES', 'modificar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['id_emisora','banco','numero_cuenta'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'EXEC dbo.sp_cotizacion_cuenta_bancaria_add
                @id_emisora = ?, @banco = ?, @numero_cuenta = ?, @cci = ?,
                @moneda = ?, @titular = ?, @acepta_tarjeta = ?, @id_usuario = ?,
                @id_cuenta = ?'
        );
        $stmt->bindValue(1, (int)$body['id_emisora'], PDO::PARAM_INT);
        $stmt->bindValue(2, $body['banco'], PDO::PARAM_STR);
        $stmt->bindValue(3, $body['numero_cuenta'], PDO::PARAM_STR);
        $stmt->bindValue(4, $body['cci'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(5, $body['moneda'] ?? 'PEN', PDO::PARAM_STR);
        $stmt->bindValue(6, $body['titular'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(7, !empty($body['acepta_tarjeta']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(8, $userId, PDO::PARAM_INT);
        $idCuenta = null;
        $stmt->bindParam(9, $idCuenta, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
        $row = $stmt->fetch();

        Audit::log('COTIZACIONES', 'CREAR_CUENTA_BANCARIA',
            'Cuenta bancaria creada: ' . $body['banco'] . ' - ' . $body['numero_cuenta'],
            'cotizacion_cuenta_bancaria', (string)($row['id_cuenta'] ?? $idCuenta));

        ApiResponse::success($row, 201, 'Cuenta bancaria creada');
    } catch (Throwable $e) {
        ApiResponse::error('cuenta_bancaria_create_failed', $e->getMessage(), 422);
    }
}

/* ============================================================================
 * GET /cotizaciones/{id} — Detalle completo
 * ============================================================================
 */
if ($method === 'GET' && $id !== null && is_numeric($id) && $action === null) {
    Auth::requirePermission('COTIZACIONES', 'ver');

    $pdo = Database::connection();

    // Verificar primero si la cotización existe (consulta directa, sin SP)
    $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.cotizacion WHERE id_cotizacion = ?');
    $checkStmt->execute([(int)$id]);
    if ((int)$checkStmt->fetchColumn() === 0) {
        ApiResponse::error('not_found', 'Cotización #' . (int)$id . ' no encontrada en la base de datos.', 404);
    }

    // Verificar si el SP existe
    $spCheck = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_get','P') IS NOT NULL THEN 1 ELSE 0 END AS existe");
    $spCheck->execute();
    $spExists = (int)$spCheck->fetchColumn() === 1;

    if ($spExists) {
        try {
            $stmt = $pdo->prepare('EXEC dbo.sp_cotizacion_get @id_cotizacion = ?');
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->execute();

            $cabecera = $stmt->fetch();
            if (!$cabecera) {
                // El SP existe pero devolvió vacío. Consulta directa.
                $cabeceraStmt = $pdo->prepare('SELECT c.*,
                    e.razon_social AS empresa_razon_social,
                    e.nombre_comercial AS empresa_nombre_comercial,
                    e.ruc AS empresa_ruc,
                    e.correo AS empresa_correo,
                    e.telefono AS empresa_telefono,
                    ec.cargo AS contacto_cargo,
                    ec.telefono_empresa AS contacto_telefono,
                    ec.correo_empresa AS contacto_correo,
                    pp.nombres AS contacto_nombres,
                    pp.apellido_paterno AS contacto_apellidos,
                    s.nombre AS sucursal_nombre,
                    tc.codigo AS tipo_codigo,
                    tc.nombre AS tipo_nombre,
                    tc.titulo_documento AS tipo_titulo_default,
                    em.razon_social AS emisora_razon_social,
                    em.ruc AS emisora_ruc,
                    em.direccion AS emisora_direccion,
                    em.telefono AS emisora_telefono,
                    em.correo AS emisora_correo,
                    em.logo_base64 AS emisora_logo,
                    fp.descripcion AS forma_pago_descripcion,
                    fp.descuento_pct AS forma_pago_descuento_pct,
                    ga.descripcion AS garantia_descripcion,
                    pl.descripcion AS plazo_entrega_descripcion,
                    va.descripcion AS validez_descripcion,
                    va.dias AS validez_dias,
                    cb.banco AS cuenta_banco,
                    cb.numero_cuenta AS cuenta_numero,
                    cb.cci AS cuenta_cci,
                    zp.codigo AS zona_codigo,
                    zp.nombre AS zona_nombre,
                    p.nombres + N\' \' + p.apellido_paterno AS vendedor_nombre,
                    uc.nombres + N\' \' + uc.apellido_paterno AS usuario_creador_nombre,
                    uc.correo AS usuario_creador_correo
                    FROM dbo.cotizacion c
                    LEFT JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
                    LEFT JOIN dbo.empresa_contacto ec ON c.id_empresa_contacto = ec.id_empresa_contacto
                    LEFT JOIN dbo.persona pp ON ec.id_persona = pp.id_persona
                    LEFT JOIN dbo.empresa_sucursal s ON c.id_sucursal = s.id_sucursal
                    LEFT JOIN dbo.cotizacion_tipo_catalogo tc ON c.id_tipo = tc.id_tipo
                    LEFT JOIN dbo.cotizacion_empresa_emisora em ON c.id_emisora = em.id_emisora
                    LEFT JOIN dbo.cotizacion_forma_pago fp ON c.id_forma_pago = fp.id_forma_pago
                    LEFT JOIN dbo.cotizacion_garantia ga ON c.id_garantia = ga.id_garantia
                    LEFT JOIN dbo.cotizacion_plazo_entrega pl ON c.id_plazo_entrega = pl.id_plazo
                    LEFT JOIN dbo.cotizacion_validez va ON c.id_validez = va.id_validez
                    LEFT JOIN dbo.cotizacion_cuenta_bancaria cb ON c.id_cuenta_bancaria = cb.id_cuenta
                    LEFT JOIN dbo.cotizacion_zona_pricing zp ON c.id_zona_pricing = zp.id_zona
                    LEFT JOIN dbo.persona p ON c.id_vendedor = p.id_persona
                    LEFT JOIN dbo.persona uc ON c.id_usuario_creador = uc.id_persona
                    WHERE c.id_cotizacion = ?');
                $cabeceraStmt->execute([(int)$id]);
                $cabecera = $cabeceraStmt->fetch();
            }

            $detalle = [];
            $historial = [];
            $comunicaciones = [];
            $versiones = [];
            $documentos = [];
            try { $stmt->nextRowset(); $detalle = $stmt->fetchAll(); } catch (Throwable $e) {}
            try { $stmt->nextRowset(); $historial = $stmt->fetchAll(); } catch (Throwable $e) {}
            try { $stmt->nextRowset(); $comunicaciones = $stmt->fetchAll(); } catch (Throwable $e) {}
            try { $stmt->nextRowset(); $versiones = $stmt->fetchAll(); } catch (Throwable $e) {}
            try { $stmt->nextRowset(); $documentos = $stmt->fetchAll(); } catch (Throwable $e) {}
        } catch (Throwable $spEx) {
            error_log('[Cotizaciones GET] SP sp_cotizacion_get falló: ' . $spEx->getMessage());
            // Fallback: consulta directa con todos los JOINs que el JS espera
            $cabeceraStmt = $pdo->prepare('SELECT c.*,
                e.razon_social AS empresa_razon_social,
                e.nombre_comercial AS empresa_nombre_comercial,
                e.ruc AS empresa_ruc,
                e.correo AS empresa_correo,
                e.telefono AS empresa_telefono,
                ec.cargo AS contacto_cargo,
                ec.telefono_empresa AS contacto_telefono,
                ec.correo_empresa AS contacto_correo,
                pp.nombres AS contacto_nombres,
                pp.apellido_paterno AS contacto_apellidos,
                s.nombre AS sucursal_nombre,
                tc.codigo AS tipo_codigo,
                tc.nombre AS tipo_nombre,
                tc.titulo_documento AS tipo_titulo_default,
                em.razon_social AS emisora_razon_social,
                em.ruc AS emisora_ruc,
                em.direccion AS emisora_direccion,
                em.telefono AS emisora_telefono,
                em.correo AS emisora_correo,
                em.logo_base64 AS emisora_logo,
                fp.descripcion AS forma_pago_descripcion,
                fp.descuento_pct AS forma_pago_descuento_pct,
                ga.descripcion AS garantia_descripcion,
                pl.descripcion AS plazo_entrega_descripcion,
                va.descripcion AS validez_descripcion,
                va.dias AS validez_dias,
                cb.banco AS cuenta_banco,
                cb.numero_cuenta AS cuenta_numero,
                cb.cci AS cuenta_cci,
                zp.codigo AS zona_codigo,
                zp.nombre AS zona_nombre,
                p.nombres + N\' \' + p.apellido_paterno AS vendedor_nombre,
                uc.nombres + N\' \' + uc.apellido_paterno AS usuario_creador_nombre,
                uc.correo AS usuario_creador_correo
                FROM dbo.cotizacion c
                LEFT JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
                LEFT JOIN dbo.empresa_contacto ec ON c.id_empresa_contacto = ec.id_empresa_contacto
                LEFT JOIN dbo.persona pp ON ec.id_persona = pp.id_persona
                LEFT JOIN dbo.empresa_sucursal s ON c.id_sucursal = s.id_sucursal
                LEFT JOIN dbo.cotizacion_tipo_catalogo tc ON c.id_tipo = tc.id_tipo
                LEFT JOIN dbo.cotizacion_empresa_emisora em ON c.id_emisora = em.id_emisora
                LEFT JOIN dbo.cotizacion_forma_pago fp ON c.id_forma_pago = fp.id_forma_pago
                LEFT JOIN dbo.cotizacion_garantia ga ON c.id_garantia = ga.id_garantia
                LEFT JOIN dbo.cotizacion_plazo_entrega pl ON c.id_plazo_entrega = pl.id_plazo
                LEFT JOIN dbo.cotizacion_validez va ON c.id_validez = va.id_validez
                LEFT JOIN dbo.cotizacion_cuenta_bancaria cb ON c.id_cuenta_bancaria = cb.id_cuenta
                LEFT JOIN dbo.cotizacion_zona_pricing zp ON c.id_zona_pricing = zp.id_zona
                LEFT JOIN dbo.persona p ON c.id_vendedor = p.id_persona
                LEFT JOIN dbo.persona uc ON c.id_usuario_creador = uc.id_persona
                WHERE c.id_cotizacion = ?');
            $cabeceraStmt->execute([(int)$id]);
            $cabecera = $cabeceraStmt->fetch();
            $detalleStmt = $pdo->prepare('SELECT * FROM dbo.cotizacion_detalle WHERE id_cotizacion = ? ORDER BY orden');
            $detalleStmt->execute([(int)$id]);
            $detalle = $detalleStmt->fetchAll();
            $historialStmt = $pdo->prepare('SELECT * FROM dbo.cotizacion_historial_estado WHERE id_cotizacion = ? ORDER BY fecha_cambio DESC');
            $historialStmt->execute([(int)$id]);
            $historial = $historialStmt->fetchAll();
            $comunicaciones = [];
            $versiones = [];
            $documentos = [];
        }
    } else {
        // SP no existe, consulta directa con todos los JOINs
        $cabeceraStmt = $pdo->prepare('SELECT c.*,
            e.razon_social AS empresa_razon_social,
            e.nombre_comercial AS empresa_nombre_comercial,
            e.ruc AS empresa_ruc,
            e.correo AS empresa_correo,
            e.telefono AS empresa_telefono,
            ec.cargo AS contacto_cargo,
            ec.telefono_empresa AS contacto_telefono,
            ec.correo_empresa AS contacto_correo,
            pp.nombres AS contacto_nombres,
            pp.apellido_paterno AS contacto_apellidos,
            s.nombre AS sucursal_nombre,
            tc.codigo AS tipo_codigo,
            tc.nombre AS tipo_nombre,
            tc.titulo_documento AS tipo_titulo_default,
            em.razon_social AS emisora_razon_social,
            em.ruc AS emisora_ruc,
            em.direccion AS emisora_direccion,
            em.telefono AS emisora_telefono,
            em.correo AS emisora_correo,
            em.logo_base64 AS emisora_logo,
            fp.descripcion AS forma_pago_descripcion,
            fp.descuento_pct AS forma_pago_descuento_pct,
            ga.descripcion AS garantia_descripcion,
            pl.descripcion AS plazo_entrega_descripcion,
            va.descripcion AS validez_descripcion,
            va.dias AS validez_dias,
            cb.banco AS cuenta_banco,
            cb.numero_cuenta AS cuenta_numero,
            cb.cci AS cuenta_cci,
            zp.codigo AS zona_codigo,
            zp.nombre AS zona_nombre,
            p.nombres + N\' \' + p.apellido_paterno AS vendedor_nombre,
            uc.nombres + N\' \' + uc.apellido_paterno AS usuario_creador_nombre,
            uc.correo AS usuario_creador_correo
            FROM dbo.cotizacion c
            LEFT JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
            LEFT JOIN dbo.empresa_contacto ec ON c.id_empresa_contacto = ec.id_empresa_contacto
            LEFT JOIN dbo.persona pp ON ec.id_persona = pp.id_persona
            LEFT JOIN dbo.empresa_sucursal s ON c.id_sucursal = s.id_sucursal
            LEFT JOIN dbo.cotizacion_tipo_catalogo tc ON c.id_tipo = tc.id_tipo
            LEFT JOIN dbo.cotizacion_empresa_emisora em ON c.id_emisora = em.id_emisora
            LEFT JOIN dbo.cotizacion_forma_pago fp ON c.id_forma_pago = fp.id_forma_pago
            LEFT JOIN dbo.cotizacion_garantia ga ON c.id_garantia = ga.id_garantia
            LEFT JOIN dbo.cotizacion_plazo_entrega pl ON c.id_plazo_entrega = pl.id_plazo
            LEFT JOIN dbo.cotizacion_validez va ON c.id_validez = va.id_validez
            LEFT JOIN dbo.cotizacion_cuenta_bancaria cb ON c.id_cuenta_bancaria = cb.id_cuenta
            LEFT JOIN dbo.cotizacion_zona_pricing zp ON c.id_zona_pricing = zp.id_zona
            LEFT JOIN dbo.persona p ON c.id_vendedor = p.id_persona
            LEFT JOIN dbo.persona uc ON c.id_usuario_creador = uc.id_persona
            WHERE c.id_cotizacion = ?');
        $cabeceraStmt->execute([(int)$id]);
        $cabecera = $cabeceraStmt->fetch();
        $detalleStmt = $pdo->prepare('SELECT * FROM dbo.cotizacion_detalle WHERE id_cotizacion = ? ORDER BY orden');
        $detalleStmt->execute([(int)$id]);
        $detalle = $detalleStmt->fetchAll();
        $historialStmt = $pdo->prepare('SELECT * FROM dbo.cotizacion_historial_estado WHERE id_cotizacion = ? ORDER BY fecha_cambio DESC');
        $historialStmt->execute([(int)$id]);
        $historial = $historialStmt->fetchAll();
        $comunicaciones = [];
        $versiones = [];
        $documentos = [];
    }

    // FIX BUG #11: garantizar que cabecera tenga cuentas_adicionales_json.
    // El SP sp_cotizacion_get NO retorna esa columna (la lista explícita del SELECT
    // del SP la omite). La consulta fallback (SELECT c.*) SÍ la trae. Para que la
    // edición no borre las cuentas adicionales, agregamos la columna si falta.
    if (is_array($cabecera) && !array_key_exists('cuentas_adicionales_json', $cabecera)) {
        try {
            $adStmt = $pdo->prepare('SELECT cuentas_adicionales_json FROM dbo.cotizacion WHERE id_cotizacion = ?');
            $adStmt->execute([(int)$id]);
            $cabecera['cuentas_adicionales_json'] = $adStmt->fetchColumn() ?: null;
        } catch (Throwable $e) {}
    }

    if (!$cabecera) ApiResponse::error('not_found', 'Cotización no encontrada', 404);

    ApiResponse::success([
        'cabecera'        => $cabecera,
        'detalle'         => $detalle,
        'historial'       => $historial,
        'comunicaciones'  => $comunicaciones,
        'versiones'       => $versiones,
        'documentos'      => $documentos,
    ], 200, 'Detalle de cotización');
}

/* ============================================================================
 * PATCH /cotizaciones/{id} — Actualizar cabecera + detalle
 * ============================================================================
 */
if ($method === 'PATCH' && is_numeric($id) && $action === null) {
    Auth::requirePermission('COTIZACIONES', 'modificar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $detalleJson = isset($body['detalle']) ? json_encode($body['detalle'], JSON_UNESCAPED_UNICODE) : null;

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'EXEC dbo.sp_cotizacion_update
            @id_cotizacion = ?, @id_usuario = ?,
            @id_empresa_contacto = ?, @id_sucursal = ?, @id_tipo = ?,
            @id_forma_pago = ?, @id_garantia = ?, @id_plazo_entrega = ?,
            @id_validez = ?, @id_cuenta_bancaria = ?, @id_zona_pricing = ?,
            @id_vendedor = ?,
            @titulo_documento = ?, @motivo = ?, @observaciones = ?,
            @moneda = ?, @igv_pct = ?, @descuento_global_pct = ?,
            @detalle_json = ?, @forzar_version = ?'
    );

    $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, isset($body['id_empresa_contacto']) ? (int)$body['id_empresa_contacto'] : null, PDO::PARAM_INT);
    $stmt->bindValue(4, isset($body['id_sucursal']) ? (int)$body['id_sucursal'] : null, PDO::PARAM_INT);
    $stmt->bindValue(5, isset($body['id_tipo']) ? (int)$body['id_tipo'] : null, PDO::PARAM_INT);
    $stmt->bindValue(6, isset($body['id_forma_pago']) ? (int)$body['id_forma_pago'] : null, PDO::PARAM_INT);
    $stmt->bindValue(7, isset($body['id_garantia']) ? (int)$body['id_garantia'] : null, PDO::PARAM_INT);
    $stmt->bindValue(8, isset($body['id_plazo_entrega']) ? (int)$body['id_plazo_entrega'] : null, PDO::PARAM_INT);
    $stmt->bindValue(9, isset($body['id_validez']) ? (int)$body['id_validez'] : null, PDO::PARAM_INT);
    $stmt->bindValue(10, isset($body['id_cuenta_bancaria']) ? (int)$body['id_cuenta_bancaria'] : null, PDO::PARAM_INT);
    $stmt->bindValue(11, isset($body['id_zona_pricing']) ? (int)$body['id_zona_pricing'] : null, PDO::PARAM_INT);
    $stmt->bindValue(12, isset($body['id_vendedor']) ? (int)$body['id_vendedor'] : null, PDO::PARAM_INT);
    $stmt->bindValue(13, $body['titulo_documento'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(14, $body['motivo'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(15, $body['observaciones'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(16, $body['moneda'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(17, isset($body['igv_pct']) ? (float)$body['igv_pct'] : null, PDO::PARAM_STR);
    $stmt->bindValue(18, isset($body['descuento_global_pct']) ? (float)$body['descuento_global_pct'] : null, PDO::PARAM_STR);
    $stmt->bindValue(19, $detalleJson, PDO::PARAM_STR);
    $stmt->bindValue(20, !empty($body['forzar_version']) ? 1 : 0, PDO::PARAM_INT);

    try {
        $stmt->execute();
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        ApiResponse::error('cotizacion_update_failed', $e->getMessage(), 422);
    }

    Audit::log('COTIZACIONES', 'ACTUALIZAR', 'Cotización #' . $id . ' actualizada',
        'cotizacion', (string)$id, ['forzar_version' => !empty($body['forzar_version'])]);

    ApiResponse::success($row, 200, 'Cotización actualizada');
}

/* ============================================================================
 * PATCH /cotizaciones/{id}/status — Cambiar estado
 * ============================================================================
 */
if ($method === 'PATCH' && is_numeric($id) && $action === 'status') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if (!isset($body['estado']) || $body['estado'] === '')
        ApiResponse::validationError(['estado' => 'Requerido']);

    $estado = $body['estado'];
    $accionesRequeridas = [
        'APROBADA'  => 'aprobar',
        'RECHAZADA' => 'aprobar',
        'CANCELADA' => 'aprobar',
        'CERRADA'   => 'aprobar',
    ];
    if (isset($accionesRequeridas[$estado])) {
        Auth::requirePermission('COTIZACIONES', $accionesRequeridas[$estado]);
    } else {
        Auth::requirePermission('COTIZACIONES', 'modificar');
    }

    $rows = Database::callSP('sp_cotizacion_update_status', [
        (int)$id, $estado, $userId, $body['motivo'] ?? null
    ]);

    if (empty($rows) || !$rows[0]['success'])
        ApiResponse::error('status_change_failed', 'No se pudo cambiar el estado', 422);

    Audit::log('COTIZACIONES', 'CAMBIO_ESTADO',
        'Cotización #' . $id . ' → ' . $estado,
        'cotizacion', (string)$id, ['estado' => $estado, 'motivo' => $body['motivo'] ?? null]);

    ApiResponse::success($rows[0], 200, 'Estado actualizado');
}

/* ============================================================================
 * POST /cotizaciones/{id}/comunicaciones — Agregar comunicación
 * ============================================================================
 */
if ($method === 'POST' && is_numeric($id) && $action === 'comunicaciones') {
    Auth::requirePermission('COTIZACIONES', 'modificar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['medio','tipo_comunicacion','descripcion'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }

    $pdo = Database::connection();
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
    $stmt->bindValue(4, $body['tipo_comunicacion'], PDO::PARAM_STR);
    $stmt->bindValue(5, $body['descripcion'], PDO::PARAM_STR);
    $stmt->bindValue(6, $body['fecha_comunicacion'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(7, $body['respuesta_cliente'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(8, $body['resultado_compromiso'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(9, $body['fecha_proximo_seguimiento'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(10, $body['observaciones'] ?? null, PDO::PARAM_STR);
    $idCom = null;
    $stmt->bindParam(11, $idCom, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);

    try {
        $stmt->execute();
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        ApiResponse::error('comunicacion_failed', $e->getMessage(), 422);
    }

    Audit::log('COTIZACIONES', 'COMUNICACION',
        'Comunicación registrada en cotización #' . $id,
        'cotizacion', (string)$id,
        ['medio' => $body['medio'], 'tipo' => $body['tipo_comunicacion']]);

    ApiResponse::success(['id_comunicacion' => (int)($row['id_comunicacion'] ?? $idCom)],
        201, 'Comunicación registrada');
}

/* ============================================================================
 * POST /cotizaciones/{id}/versiones — Crear nueva versión
 * ============================================================================
 */
if ($method === 'POST' && is_numeric($id) && $action === 'versiones') {
    Auth::requirePermission('COTIZACIONES', 'modificar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    // Validar que la cotización existe y no esté en borrador
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT estado, version_numero, codigo FROM dbo.cotizacion WHERE id_cotizacion = ?');
    $stmt->execute([(int)$id]);
    $cot = $stmt->fetch();
    if (!$cot) ApiResponse::error('not_found', 'Cotización no encontrada', 404);
    if ($cot['estado'] === 'BORRADOR')
        ApiResponse::error('invalid_action', 'Las cotizaciones en BORRADOR no se versionan, se editan directamente', 422);

    // Tomar snapshot de la versión actual
    $snapshot = json_encode([
        'cabecera' => $pdo->query('SELECT * FROM dbo.cotizacion WHERE id_cotizacion = ' . (int)$id)->fetch(),
        'detalle'  => $pdo->query('SELECT * FROM dbo.cotizacion_detalle WHERE id_cotizacion = ' . (int)$id)->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);

    $nuevaVersion = (int)$cot['version_numero'] + 1;
    $codigoVersionado = $cot['codigo'] . '.' . $nuevaVersion;

    $stmt = $pdo->prepare(
        'INSERT INTO dbo.cotizacion_version (id_cotizacion, version_numero, codigo_versionado, id_usuario, motivo, snapshot_json, fecha_version)
         VALUES (?, ?, ?, ?, ?, ?, SYSDATETIME())'
    );
    $stmt->execute([(int)$id, $nuevaVersion, $codigoVersionado, $userId, $body['motivo'] ?? 'Nueva versión', $snapshot]);

    $stmt = $pdo->prepare('UPDATE dbo.cotizacion SET version_numero = ? WHERE id_cotizacion = ?');
    $stmt->execute([$nuevaVersion, (int)$id]);

    Audit::log('COTIZACIONES', 'NUEVA_VERSION',
        'Nueva versión ' . $codigoVersionado . ' de cotización #' . $id,
        'cotizacion', (string)$id, ['version' => $nuevaVersion]);

    ApiResponse::success([
        'id_cotizacion' => (int)$id,
        'version_numero' => $nuevaVersion,
        'codigo_versionado' => $codigoVersionado,
    ], 201, 'Versión creada');
}

/* ============================================================================
 * GET /cotizaciones/{id}/export/pdf — Exportar a PDF (réplica del Excel)
 * ============================================================================
 */
if ($method === 'GET' && is_numeric($id) && $action === 'export' && isset($segments[3]) && $segments[3] === 'pdf') {
    Auth::requirePermission('COTIZACIONES', 'exportar');

    if (!class_exists('CotizacionExporter')) {
        ApiResponse::error('exporter_missing', 'No se pudo cargar CotizacionExporter.php. Verifica que el archivo exista y no tenga errores de sintaxis.', 500);
    }
    $exporter = new CotizacionExporter();
    $exporter->outputPdf((int)$id);  // salida directa al navegador (exit)
}

/* ============================================================================
 * GET /cotizaciones/{id}/export/xlsx — Exportar a Excel
 * ============================================================================
 */
if ($method === 'GET' && is_numeric($id) && $action === 'export' && isset($segments[3]) && $segments[3] === 'xlsx') {
    Auth::requirePermission('COTIZACIONES', 'exportar');

    if (!class_exists('CotizacionExporter')) {
        ApiResponse::error('exporter_missing', 'No se pudo cargar CotizacionExporter.php. Verifica que el archivo exista y no tenga errores de sintaxis.', 500);
    }
    $exporter = new CotizacionExporter();
    $exporter->outputXlsx((int)$id);  // salida directa al navegador (exit)
}

/* ============================================================================
 * GET /cotizaciones/{id}/export/csv — Exportar a CSV
 * ============================================================================
 */
if ($method === 'GET' && is_numeric($id) && $action === 'export' && isset($segments[3]) && $segments[3] === 'csv') {
    Auth::requirePermission('COTIZACIONES', 'exportar');

    if (!class_exists('CotizacionExporter')) {
        ApiResponse::error('exporter_missing', 'No se pudo cargar CotizacionExporter.php. Verifica que el archivo exista y no tenga errores de sintaxis.', 500);
    }
    $exporter = new CotizacionExporter();
    $exporter->outputCsv((int)$id);
}

/* ============================================================================
 * POST /cotizaciones/{id}/files — Subir documento adjunto
 * ============================================================================
 */
if ($method === 'POST' && is_numeric($id) && $action === 'files') {
    Auth::requirePermission('COTIZACIONES', 'modificar');

    $nombreArchivo = $_FILES['archivo']['name'] ?? ($_POST['nombre_original'] ?? null);
    $tipoMime = $_FILES['archivo']['type'] ?? ($_POST['tipo_mime'] ?? 'application/octet-stream');
    $tamano   = $_FILES['archivo']['size'] ?? (int)($_POST['tamano_bytes'] ?? 0);
    $tipoDoc  = $_POST['tipo_documento'] ?? 'ADJUNTO_GENERAL';

    if (!$nombreArchivo || $tamano <= 0)
        ApiResponse::validationError(['archivo' => 'Archivo inválido o vacío']);

    if ($tamano > 25 * 1024 * 1024)
        ApiResponse::error('file_too_large', 'El archivo supera 25 MB', 413);

    $binario = file_get_contents($_FILES['archivo']['tmp_name'] ?? 'php://input');
    if ($binario === false)
        ApiResponse::error('read_failed', 'No se pudo leer el archivo', 422);

    $base64 = base64_encode($binario);

    $rows = Database::callSP('sp_cotizacion_documento_save', [
        (int)$id,
        $userId,
        null,  // id_comunicacion
        $nombreArchivo,
        null,  // archivo_key (lo genera el SP)
        $tipoMime,
        $tamano,
        $tipoDoc,
        $base64,
        null,  // contenido_bin
        null,  // id_documento OUTPUT (no se usa como output aquí)
    ]);

    Audit::log('COTIZACIONES', 'DOCUMENTO_UPLOAD',
        'Documento adjuntado a cotización #' . $id,
        'cotizacion_documento', (string)($rows[0]['id_documento'] ?? ''),
        ['nombre' => $nombreArchivo, 'tamano' => $tamano]);

    ApiResponse::success($rows[0] ?? [], 201, 'Documento adjuntado');
}

/* ============================================================================
 * GET /cotizaciones/{id}/files — Listar documentos
 * ============================================================================
 */
if ($method === 'GET' && is_numeric($id) && $action === 'files' && $fileId === null) {
    Auth::requirePermission('COTIZACIONES', 'ver');

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'SELECT id_documento, id_cotizacion, id_comunicacion, nombre_original,
                archivo_key, tipo_mime, tamano_bytes, tipo_documento, fecha_registro, id_usuario
         FROM dbo.cotizacion_documento
         WHERE id_cotizacion = ?
         ORDER BY fecha_registro DESC'
    );
    $stmt->execute([(int)$id]);
    $rows = $stmt->fetchAll();

    ApiResponse::success($rows, 200, 'Documentos de la cotización');
}

/* ============================================================================
 * GET /cotizaciones/{id}/files/{fileId} — Descargar documento
 * ============================================================================
 */
if ($method === 'GET' && is_numeric($id) && $action === 'files' && is_numeric($fileId)) {
    Auth::requirePermission('COTIZACIONES', 'ver');

    $rows = Database::callSP('sp_cotizacion_documento_get', [(int)$fileId]);
    if (empty($rows)) ApiResponse::error('not_found', 'Documento no encontrado', 404);

    $doc = $rows[0];
    if ((int)$doc['id_cotizacion'] !== (int)$id)
        ApiResponse::error('forbidden', 'El documento no pertenece a esta cotización', 403);

    $binario = $doc['contenido'] ?? null;
    if ($binario === null) ApiResponse::error('not_found', 'Contenido no disponible', 404);

    // $binario es un recurso stream de PDO para VARBINARY(MAX)
    if (is_resource($binario)) $binario = stream_get_contents($binario);

    header('Content-Type: ' . $doc['tipo_mime']);
    header('Content-Disposition: attachment; filename="' . $doc['nombre_original'] . '"');
    header('Content-Length: ' . $doc['tamano_bytes']);
    header('Cache-Control: private, no-cache, must-revalidate');
    echo $binario;
    exit;
}

/* ============================================================================
 * DELETE /cotizaciones/{id} — Soft-delete (admin)
 * ============================================================================
 */
if ($method === 'DELETE' && is_numeric($id) && $action === null) {
    Auth::requirePermission('COTIZACIONES', 'eliminar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['motivo']))
        ApiResponse::validationError(['motivo' => 'Requerido para eliminación']);

    $rows = Database::callSP('sp_cotizacion_delete_admin', [
        (int)$id, $userId, $body['motivo']
    ]);

    Audit::log('COTIZACIONES', 'ELIMINAR',
        'Cotización #' . $id . ' eliminada. Motivo: ' . $body['motivo'],
        'cotizacion', (string)$id, ['motivo' => $body['motivo']]);

    ApiResponse::success($rows[0] ?? [], 200, 'Cotización eliminada (soft delete)');
}

/* ============================================================================
 * POST /cotizaciones/export/job — Encolar exportación masiva (job)
 * ============================================================================
 */
if ($method === 'POST' && $id === 'export' && $action === 'job') {
    Auth::requirePermission('COTIZACIONES', 'exportar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $formato = strtolower($body['formato'] ?? 'xlsx');
    if (!in_array($formato, ['xlsx','csv','json','pdf'], true))
        ApiResponse::validationError(['formato' => 'Debe ser xlsx, csv, json o pdf']);

    $filtros = [
        'estado'        => $body['estado'] ?? null,
        'id_empresa'    => $body['id_empresa'] ?? null,
        'id_tipo'       => $body['id_tipo'] ?? null,
        'id_usuario'    => $body['id_usuario'] ?? null,
        'id_vendedor'   => $body['id_vendedor'] ?? null,
        'fecha_desde'   => $body['fecha_desde'] ?? null,
        'fecha_hasta'   => $body['fecha_hasta'] ?? null,
        'search'        => $body['search'] ?? null,
        'anio'          => $body['anio'] ?? null,
    ];

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'EXEC dbo.sp_exportacion_crear
            @id_usuario = ?, @modulo = ?, @formato = ?, @filtros_json = ?, @id_job = ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, 'COTIZACIONES', PDO::PARAM_STR);
    $stmt->bindValue(3, $formato, PDO::PARAM_STR);
    $stmt->bindValue(4, json_encode($filtros, JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
    $idJob = null;
    $stmt->bindParam(5, $idJob, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
    $stmt->execute();
    $row = $stmt->fetch();

    Audit::log('COTIZACIONES', 'EXPORT_JOB',
        'Exportación masiva encolada (formato ' . $formato . ')',
        'exportacion_job', (string)($row['id_job'] ?? $idJob),
        ['formato' => $formato, 'filtros' => $filtros]);

    ApiResponse::success(['id_job' => (int)($row['id_job'] ?? $idJob)],
        201, 'Exportación encolada. Descárguela en unos minutos desde /exportaciones');
}

/* ============================================================================
 * CRUD: ZONAS DE PRICING
 *   POST   /cotizaciones/zonas             Crear nueva zona
 *   PATCH  /cotizaciones/zonas/{id}         Actualizar zona
 *   DELETE /cotizaciones/zonas/{id}         Eliminar (soft) zona
 * ============================================================================
 */
if ($method === 'POST' && $id === 'zonas') {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['codigo','nombre'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    try {
        $rows = Database::callSP('sp_cotizacion_zona_add', [
            $body['codigo'], $body['nombre'], $body['descripcion'] ?? null
        ]);
        Audit::log('COTIZACIONES', 'CREAR_ZONA',
            'Zona creada: ' . $body['nombre'],
            'cotizacion_zona_pricing', (string)($rows[0]['id_zona'] ?? ''));
        ApiResponse::success($rows[0] ?? [], 201, 'Zona creada');
    } catch (Throwable $e) {
        ApiResponse::error('zona_create_failed', $e->getMessage(), 422);
    }
}

if ($method === 'PATCH' && $id === 'zonas' && is_numeric($action)) {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        $rows = Database::callSP('sp_cotizacion_zona_update', [
            (int)$action,
            $body['codigo'] ?? null,
            $body['nombre'] ?? null,
            $body['descripcion'] ?? null,
            isset($body['activo']) ? (int)$body['activo'] : 1
        ]);
        ApiResponse::success($rows[0] ?? [], 200, 'Zona actualizada');
    } catch (Throwable $e) {
        ApiResponse::error('zona_update_failed', $e->getMessage(), 422);
    }
}

if ($method === 'DELETE' && $id === 'zonas' && is_numeric($action)) {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    try {
        $rows = Database::callSP('sp_cotizacion_zona_delete', [(int)$action]);
        ApiResponse::success($rows[0] ?? [], 200, 'Zona desactivada');
    } catch (Throwable $e) {
        ApiResponse::error('zona_delete_failed', $e->getMessage(), 422);
    }
}

/* ============================================================================
 * CRUD: FORMAS DE PAGO (UPDATE y DELETE; POST ya existe arriba)
 *   PATCH  /cotizaciones/formas-pago/{id}   Actualizar forma de pago
 *   DELETE /cotizaciones/formas-pago/{id}   Eliminar forma de pago
 * ============================================================================
 */
if ($method === 'PATCH' && $id === 'formas-pago' && is_numeric($action)) {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        $rows = Database::callSP('sp_cotizacion_forma_pago_update', [
            (int)$action,
            $body['descripcion'] ?? null,
            isset($body['descuento_pct']) ? (float)$body['descuento_pct'] : null,
            isset($body['activo']) ? (int)$body['activo'] : 1
        ]);
        ApiResponse::success($rows[0] ?? [], 200, 'Forma de pago actualizada');
    } catch (Throwable $e) {
        ApiResponse::error('forma_pago_update_failed', $e->getMessage(), 422);
    }
}

if ($method === 'DELETE' && $id === 'formas-pago' && is_numeric($action)) {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    try {
        $rows = Database::callSP('sp_cotizacion_forma_pago_delete', [(int)$action]);
        ApiResponse::success($rows[0] ?? [], 200, 'Forma de pago eliminada');
    } catch (Throwable $e) {
        ApiResponse::error('forma_pago_delete_failed', $e->getMessage(), 422);
    }
}

/* ============================================================================
 * CRUD: CUENTAS BANCARIAS (UPDATE y DELETE; POST ya existe arriba)
 *   PATCH  /cotizaciones/cuentas-bancarias/{id}    Actualizar cuenta
 *   DELETE /cotizaciones/cuentas-bancarias/{id}    Eliminar cuenta
 * ============================================================================
 */
if ($method === 'PATCH' && $id === 'cuentas-bancarias' && is_numeric($action)) {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        $rows = Database::callSP('sp_cotizacion_cuenta_bancaria_update', [
            (int)$action,
            $body['banco'] ?? null,
            $body['numero_cuenta'] ?? null,
            $body['cci'] ?? null,
            $body['moneda'] ?? null,
            $body['titular'] ?? null,
            isset($body['acepta_tarjeta']) ? (int)$body['acepta_tarjeta'] : null,
            isset($body['activo']) ? (int)$body['activo'] : 1
        ]);
        ApiResponse::success($rows[0] ?? [], 200, 'Cuenta bancaria actualizada');
    } catch (Throwable $e) {
        ApiResponse::error('cuenta_update_failed', $e->getMessage(), 422);
    }
}

if ($method === 'DELETE' && $id === 'cuentas-bancarias' && is_numeric($action)) {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    try {
        $rows = Database::callSP('sp_cotizacion_cuenta_bancaria_delete', [(int)$action]);
        ApiResponse::success($rows[0] ?? [], 200, 'Cuenta bancaria eliminada');
    } catch (Throwable $e) {
        ApiResponse::error('cuenta_delete_failed', $e->getMessage(), 422);
    }
}

ApiResponse::error('not_found', "Endpoint no encontrado. Method=$method, resource=cotizaciones, id=$id, action=$action", 404);
