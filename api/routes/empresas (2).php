<?php
/**
 * api/routes/empresas.php  -  CRUD de Empresas (CORREGIDO v3)
 *
 *   GET    /empresas                       - Listado (con filtro ?query=)
 *   GET    /empresas/{id}                  - Detalle
 *   POST   /empresas                       - Crear
 *   PUT    /empresas/{id}                  - Actualizar  (FIX v3)
 *   DELETE /empresas/{id}                  - Dar de baja
 *   PATCH  /empresas/{id}/reactivar       - Reactivar
 *
 * CAMBIOS v3 (corrección de errores al editar empresa):
 *   - PUT siempre invoca sp_empresa_guardar (no más UPDATE directo)
 *   - PUT pasa id_usuario_modificacion para auditoría correcta
 *   - PUT consume result set + closeCursor() para que el OUTPUT param funcione
 *   - PUT maneja errores SQL específicos (50003/50004) devolviendo 422
 *   - PUT hace pre-validación de duplicados para respuesta clara
 *   - POST también pre-valida duplicados y maneja errores con try/catch
 *   - Detección dinámica del parámetro @id_usuario_modificacion para
 *     compatibilidad retroactiva (si el SP todavía no se actualiza)
 *
 * REQUIERE: aplicar el script SQL sp_empresa_guardar.sql (corregido) en la BD.
 */

 $method = $_SERVER['REQUEST_METHOD'];
 $id     = $segments[1] ?? null;
 $action = $segments[2] ?? null;

if ($method === 'GET') {
    Auth::requireAnyPermission([
        ['EMPRESAS', 'ver'], ['CONTACTOS', 'ver'], ['SUCURSALES', 'ver'], ['TICKETS', 'ver'],
    ]);
} else {
    Auth::requirePermission('EMPRESAS', 'ver');
}

 $pdo = Database::connection();

// Verificar si la tabla empresa tiene columnas de baja (activo, fecha_baja)
 $hasBajaColumns = Database::columnExists('empresa', 'activo')
    && Database::columnExists('empresa', 'fecha_baja');

// Verificar si el SP soporta @crear_sede_principal
 $hasSedePrincipalFlag = Database::spParamExists('sp_empresa_guardar', 'crear_sede_principal');

// FIX v3: verificar si el SP ya tiene el nuevo parámetro de auditoría
 $hasUsuarioModParam = Database::spParamExists('sp_empresa_guardar', 'id_usuario_modificacion');

// Query base con LEFT JOIN para sede_principal (evita N+1)
 $empresaSelect = "SELECT e.id_empresa, e.ruc, e.razon_social, e.nombre_comercial,
    e.correo, e.telefono, e.id_rubro, r.nombre AS rubro,
    e.id_grupo, g.nombre AS grupo, u.direccion, u.referencia,
    u.id_division, dbo.fn_pais_de_division(u.id_division) AS id_pais,
    dbo.fn_obtener_ruta_geografica(u.id_division) AS ubicacion_completa,
    e.activo, e.fecha_baja,
    CASE WHEN sede_cnt.total_principal > 0 THEN 1 ELSE 0 END AS tiene_sede_principal
    FROM dbo.empresa e WITH (NOLOCK)
    LEFT JOIN dbo.rubro r WITH (NOLOCK) ON r.id_rubro = e.id_rubro
    LEFT JOIN dbo.grupo_empresarial g WITH (NOLOCK) ON g.id_grupo = e.id_grupo
    LEFT JOIN dbo.ubicacion u WITH (NOLOCK) ON u.id_ubicacion = e.id_ubicacion
    LEFT JOIN (
        SELECT id_empresa, COUNT(*) AS total_principal
        FROM dbo.empresa_sucursal WITH (NOLOCK)
        WHERE es_principal = 1 AND activo = 1
        GROUP BY id_empresa
    ) sede_cnt ON sede_cnt.id_empresa = e.id_empresa";

/* ==================================================================
 *  GET /empresas
 *  Filtros:
 *    ?query=     Búsqueda por RUC, razón social, nombre comercial o correo
 *    ?id_rubro=  Filtra por ID de rubro
 *    ?estado=baja Lista empresas dadas de baja (requiere columnas activo+fecha_baja)
 * ================================================================== */
if ($method === 'GET' && $id === null) {
    $query = $_GET['query'] ?? null;
    $idRubro = !empty($_GET['id_rubro']) ? (int)$_GET['id_rubro'] : null;
    $includeInactive = ($_GET['estado'] ?? '') === 'baja';

    if ($includeInactive) {
        if (!$hasBajaColumns) {
            ApiResponse::error('migration_required',
                'Ejecute scripts_sql/09_empresa_bajas.sql para consultar empresas dadas de baja.', 503);
        }
        $term = trim((string)$query);
        $sql = $empresaSelect . ' WHERE e.activo = 0';
        $params = [];
        if ($term !== '') {
            $sql .= ' AND (e.ruc LIKE ? OR e.razon_social LIKE ? OR e.correo LIKE ?)';
            $search = '%' . $term . '%';
            array_push($params, $search, $search, $search);
        }
        if ($idRubro !== null) {
            $sql .= ' AND e.id_rubro = ?';
            $params[] = $idRubro;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY e.razon_social');
        $stmt->execute($params);
        ApiResponse::success($stmt->fetchAll(), 200);
    }

    if ($hasBajaColumns) {
        $term = trim((string)$query);
        $sql = $empresaSelect . ' WHERE e.activo = 1';
        $params = [];
        if ($term !== '') {
            $sql .= ' AND (e.ruc LIKE ? OR e.razon_social LIKE ? OR e.nombre_comercial LIKE ? OR e.correo LIKE ?)';
            $search = '%' . $term . '%';
            array_push($params, $search, $search, $search, $search);
        }
        if ($idRubro !== null) {
            $sql .= ' AND e.id_rubro = ?';
            $params[] = $idRubro;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY e.razon_social');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } else {
        // Fallback al SP sin columnas de baja
        $rows = Database::callSP('sp_empresa_buscar_v2', [$query, $idRubro]);
        foreach ($rows as &$row) $row['activo'] = 1;
        unset($row);
    }
    ApiResponse::success($rows, 200);
}

/* ==================================================================
 *  GET /empresas/{id}  -  Detalle
 * ================================================================== */
if ($method === 'GET' && $id !== null) {
    $stmt = $pdo->prepare($empresaSelect . ' WHERE e.id_empresa = ?');
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    if (!$row) ApiResponse::error('not_found', 'Empresa no encontrada', 404);

    $statusSql = $hasBajaColumns
        ? 'SELECT activo, fecha_baja FROM dbo.empresa WHERE id_empresa = ?'
        : 'SELECT 1 AS activo FROM dbo.empresa WHERE id_empresa = ?';
    $statusStmt = $pdo->prepare($statusSql);
    $statusStmt->execute([(int)$id]);
    $status = $statusStmt->fetch() ?: [];
    $row['activo'] = isset($status['activo']) ? (int)$status['activo'] : 1;
    $row['fecha_baja'] = $status['fecha_baja'] ?? null;
    $row['id_pais'] = null;
    ApiResponse::success($row, 200);
}

/* ==================================================================
 *  POST /empresas  -  Crear
 * ================================================================== */
if ($method === 'POST' && $id === null) {
    Auth::requirePermission('EMPRESAS', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    foreach (['ruc', 'razon_social', 'correo', 'id_rubro', 'direccion', 'id_division'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    if (!preg_match('/^[0-9]{1,11}$/D', trim((string)$body['ruc']))) {
        ApiResponse::validationError(['ruc' => 'El RUC debe contener solo numeros y maximo 11 digitos']);
    }
    if (empty($body['crear_sede_principal']) && !$hasSedePrincipalFlag) {
        ApiResponse::error(
            'migration_required',
            'Aplique scripts_sql/10_empresa_sede_principal.sql para omitir la sede principal.',
            409
        );
    }

    // Pre-validar duplicados (respuesta 422 clara en vez de 500 del SP)
    $dup = $pdo->prepare('SELECT 1 FROM dbo.empresa WHERE ruc = ?');
    $dup->execute([trim((string)$body['ruc'])]);
    if ($dup->fetchColumn()) {
        ApiResponse::validationError(['ruc' => 'El RUC ya existe en otra empresa']);
    }
    $dup = $pdo->prepare('SELECT 1 FROM dbo.empresa WHERE correo = ?');
    $dup->execute([trim((string)$body['correo'])]);
    if ($dup->fetchColumn()) {
        ApiResponse::validationError(['correo' => 'El correo ya existe en otra empresa']);
    }

    try {
        // Determinar número de placeholders según si el SP tiene el nuevo parámetro
        $placeholdersCount = $hasUsuarioModParam ? 14 : 13;
        $placeholders = implode(',', array_fill(0, $placeholdersCount, '?'));
        $stmt = $pdo->prepare("EXEC sp_empresa_guardar $placeholders");

        $savedId = 0;
        $stmt->bindParam(1, $savedId, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->bindValue(2,  trim((string)$body['ruc']), PDO::PARAM_STR);
        $stmt->bindValue(3,  trim((string)$body['razon_social']), PDO::PARAM_STR);
        $stmt->bindValue(4,  trim((string)($body['nombre_comercial'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(5,  trim((string)($body['telefono'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(6,  trim((string)$body['correo']), PDO::PARAM_STR);
        $stmt->bindValue(7,  (int)$body['id_rubro'], PDO::PARAM_INT);
        $stmt->bindValue(8,  !empty($body['id_grupo']) ? (int)$body['id_grupo'] : null,
                              !empty($body['id_grupo']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(9,  trim((string)$body['direccion']), PDO::PARAM_STR);
        $stmt->bindValue(10, trim((string)($body['referencia'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(11, (int)$body['id_division'], PDO::PARAM_INT);
        $stmt->bindValue(12, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
        $stmt->bindValue(13, !empty($body['crear_sede_principal']), PDO::PARAM_BOOL);
        if ($hasUsuarioModParam) {
            $stmt->bindValue(14, $user['id_persona'] ?? null,
                                  isset($user['id_persona']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        }

        $stmt->execute();
        $row = $stmt->fetch();
        $stmt->closeCursor();
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Errores específicos del SP (RUC/correo duplicado)
        if (strpos($msg, '50001') !== false || strpos($msg, '50002') !== false) {
            ApiResponse::validationError(['ruc' => 'El RUC o correo ya existe en otra empresa']);
        }
        $traceId = Database::traceId();
        error_log(sprintf("[%s][POST /empresas] %s [%s in %s:%d]\n",
            $traceId, $msg, get_class($e),
            basename($e->getFile() ?: 'unknown'), $e->getLine()));
        ApiResponse::error(
            'internal_error',
            'Error interno del servidor al crear empresa. Referencia: ' . $traceId,
            500,
            ['trace_id' => $traceId]
        );
    }

    ApiResponse::success(['id_empresa' => (int)($row['id_empresa'] ?? $savedId)], 201, 'Empresa creada');
}

/* ==================================================================
 *  PUT /empresas/{id}  -  Actualizar  (FIX v3)
 *
 *  FIX: siempre invoca sp_empresa_guardar para que la direccion y la
 *  auditoria se actualicen de forma consistente, sin importar si la
 *  empresa tiene o no sede principal. Antes, cuando existia sede
 *  principal, se hacia un UPDATE directo que ignoraba la direccion,
 *  lo que producia datos inconsistentes y errores 500 al editar.
 * ================================================================== */
if ($method === 'PUT' && $id !== null) {
    Auth::requirePermission('EMPRESAS', 'modificar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    // Validar campos obligatorios (direccion e id_division AHORA son obligatorios)
    foreach (['ruc', 'razon_social', 'correo', 'id_rubro', 'direccion', 'id_division'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    if (!preg_match('/^[0-9]{1,11}$/D', trim((string)$body['ruc']))) {
        ApiResponse::validationError(['ruc' => 'El RUC debe contener solo numeros y maximo 11 digitos']);
    }

    /* FIX defensivo: normalizar id a entero positivo. Antes, si la URL traía
     * el id como '0' o vacío, las consultas de duplicados usaban id_empresa = 0
     * y NO excluían el registro actual, produciendo el falso positivo
     * "El RUC ya esta registrado" al editar. */
    $idEmpresa = (int)$id;
    if ($idEmpresa <= 0) {
        ApiResponse::error('invalid_id', 'ID de empresa invalido para actualizar', 400);
    }

    $pdo = Database::connection();

    // Pre-validar duplicados (respuesta 422 clara en vez de 500 del SP)
    $dup = $pdo->prepare('SELECT 1 FROM dbo.empresa WHERE ruc = ? AND id_empresa <> ?');
    $dup->execute([trim((string)$body['ruc']), $idEmpresa]);
    if ($dup->fetchColumn()) {
        ApiResponse::validationError(['ruc' => 'El RUC ya esta registrado en otra empresa']);
    }
    $dup = $pdo->prepare('SELECT 1 FROM dbo.empresa WHERE correo = ? AND id_empresa <> ?');
    $dup->execute([trim((string)$body['correo']), $idEmpresa]);
    if ($dup->fetchColumn()) {
        ApiResponse::validationError(['correo' => 'El correo ya esta registrado en otra empresa']);
    }

    try {
        // Determinar número de placeholders según si el SP tiene el nuevo parámetro
        $placeholdersCount = $hasUsuarioModParam ? 14 : 13;
        $placeholders = implode(',', array_fill(0, $placeholdersCount, '?'));
        $stmt = $pdo->prepare("EXEC sp_empresa_guardar $placeholders");

        $savedId = $idEmpresa;
        $stmt->bindParam(1,  $savedId, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->bindValue(2,  trim((string)$body['ruc']), PDO::PARAM_STR);
        $stmt->bindValue(3,  trim((string)$body['razon_social']), PDO::PARAM_STR);
        $stmt->bindValue(4,  trim((string)($body['nombre_comercial'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(5,  trim((string)($body['telefono'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(6,  trim((string)$body['correo']), PDO::PARAM_STR);
        $stmt->bindValue(7,  (int)$body['id_rubro'], PDO::PARAM_INT);
        $stmt->bindValue(8,  !empty($body['id_grupo']) ? (int)$body['id_grupo'] : null,
                              !empty($body['id_grupo']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(9,  trim((string)$body['direccion']), PDO::PARAM_STR);
        $stmt->bindValue(10, trim((string)($body['referencia'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(11, (int)$body['id_division'], PDO::PARAM_INT);
        $stmt->bindValue(12, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
        // @crear_sede_principal: en UPDATE se ignora; pasamos 0 (default seguro)
        $stmt->bindValue(13, 0, PDO::PARAM_BOOL);
        if ($hasUsuarioModParam) {
            $stmt->bindValue(14, $user['id_persona'] ?? null,
                                  isset($user['id_persona']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        }

        $stmt->execute();
        // Consumir el result set del SELECT @nuevo_id_empresa AS id_empresa
        // para que el driver libere el OUTPUT param correctamente
        $row = $stmt->fetch();
        $stmt->closeCursor();
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Errores específicos del SP (RUC/correo duplicado)
        if (strpos($msg, '50003') !== false) {
            ApiResponse::validationError(['ruc' => 'El RUC ya existe en otra empresa']);
        }
        if (strpos($msg, '50004') !== false) {
            ApiResponse::validationError(['correo' => 'El correo ya existe en otra empresa']);
        }
        $traceId = Database::traceId();
        error_log(sprintf("[%s][PUT /empresas/%d] %s [%s in %s:%d]\n",
            $traceId, $idEmpresa, $msg, get_class($e),
            basename($e->getFile() ?: 'unknown'), $e->getLine()));
        ApiResponse::error(
            'internal_error',
            'Error interno del servidor al actualizar empresa. Referencia: ' . $traceId,
            500,
            ['trace_id' => $traceId]
        );
    }

    ApiResponse::success(
        ['id_empresa' => $idEmpresa, 'ubicacion_actualizada' => true],
        200,
        'Empresa actualizada correctamente (datos + ubicacion)'
    );
}

/* ==================================================================
 *  PATCH /empresas/{id}/reactivar
 * ================================================================== */
if ($method === 'PATCH' && $id !== null && $action === 'reactivar') {
    Auth::requirePermission('EMPRESAS', 'modificar');
    $stmt = Database::connection()->prepare(
        'UPDATE dbo.empresa SET activo = 1, fecha_baja = NULL WHERE id_empresa = ?'
    );
    $stmt->execute([(int)$id]);
    if ($stmt->rowCount() === 0) {
        $check = Database::connection()->prepare('SELECT 1 FROM dbo.empresa WHERE id_empresa = ?');
        $check->execute([(int)$id]);
        if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Empresa no encontrada', 404);
    }
    ApiResponse::success(['id_empresa' => (int)$id], 200, 'Empresa reactivada');
}

/* ==================================================================
 *  DELETE /empresas/{id}  -  baja logica (o permanente con ?permanente=1)
 * ================================================================== */
if ($method === 'DELETE' && $id !== null) {
    Auth::requirePermission('EMPRESAS', 'eliminar');
    if (($_GET['permanente'] ?? '') === '1') {
        $status = $pdo->prepare('SELECT activo FROM dbo.empresa WHERE id_empresa = ?');
        $status->execute([(int)$id]);
        $active = $status->fetchColumn();
        if ($active === false) ApiResponse::error('not_found', 'Empresa no encontrada', 404);
        if ((int)$active === 1) ApiResponse::error('must_be_inactive', 'Primero debe dar de baja la empresa', 409);

        foreach ([
            ['dbo.ticket',           'id_empresa', 'La empresa tiene tickets asociados.'],
            ['dbo.empresa_sucursal', 'id_empresa', 'Elimine definitivamente sus sedes antes de eliminar la empresa.'],
            ['dbo.empresa_contacto', 'id_empresa', 'Elimine definitivamente sus contactos antes de eliminar la empresa.'],
        ] as [$table, $column, $message]) {
            $dependency = $pdo->prepare("SELECT 1 FROM $table WHERE $column = ?");
            $dependency->execute([(int)$id]);
            if ($dependency->fetchColumn()) ApiResponse::error('has_dependencies', $message, 409);
        }
        try {
            $rows = Database::callSP('sp_empresa_eliminar', [(int)$id, $user['correo'] ?? 'Sistema']);
        } catch (Throwable $e) {
            ApiResponse::error('delete_failed', $e->getMessage(), 409);
        }
        ApiResponse::success($rows[0] ?? ['id_empresa' => (int)$id], 200, 'Empresa eliminada definitivamente');
    }

    $stmt = Database::connection()->prepare(
        'UPDATE dbo.empresa SET activo = 0, fecha_baja = SYSUTCDATETIME() WHERE id_empresa = ? AND activo = 1'
    );
    $stmt->execute([(int)$id]);
    if ($stmt->rowCount() === 0) ApiResponse::error('not_found', 'Empresa activa no encontrada', 404);
    ApiResponse::success(['id_empresa' => (int)$id], 200, 'Empresa dada de baja');
}

ApiResponse::error('not_found', 'Endpoint no encontrado', 404);