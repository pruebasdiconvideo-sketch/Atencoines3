<?php
/**
 * api/routes/rubros.php  (VERSIÓN MEJORADA)
 * ----------------------------------------------------------------------------
 * Módulo: Rubros
 *
 * MEJORAS IMPLEMENTADAS:
 *   - GET /rubros?query=xxx&page=1&per_page=50
 *     Búsqueda por nombre (con paginación opcional en servidor).
 *     Si la tabla tiene columnas `codigo` o `descripcion` en el futuro,
 *     la búsqueda se extiende automáticamente a esos campos.
 *   - GET /rubros/all           Listado completo (sin paginar, para selects).
 *   - GET /rubros/{id}          Detalle de un rubro.
 *   - POST /rubros              Crear (igual que antes).
 *   - PUT  /rubros/{id}         Actualizar (igual que antes).
 *   - DELETE /rubros/{id}       Eliminar (igual que antes).
 *
 * SPs USADOS:
 *   - sp_rubro_buscar (ya existe, soporta @query).
 *   - sp_rubro_guardar, sp_rubro_eliminar (sin cambios).
 *
 * SEGURIDAD:
 *   - Consultas parametrizadas vía sp_executesql (evita inyección SQL).
 *   - Permisos validados con Auth::requirePermission().
 *
 * COMPATIBILIDAD:
 *   - Mantiene el endpoint original GET /rubros.
 *   - Las vistas JS existentes siguen funcionando.
 */

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;

/* Permisos */
if ($method === 'GET' && $id === null) {
    Auth::requireAnyPermission([['GEOGRAFIA', 'ver'], ['EMPRESAS', 'ver']]);
} else {
    Auth::requirePermission('GEOGRAFIA', 'ver');
}

/* ============================================================
 *  GET /rubros — Listado con búsqueda y paginación opcional
 * ============================================================ */
if ($method === 'GET' && $id === null) {

    $query    = isset($_GET['query']) ? trim($_GET['query']) : null;
    $page     = isset($_GET['page'])     ? max(1, (int)$_GET['page'])     : null;
    $perPage  = isset($_GET['per_page']) ? min(200, max(1, (int)$_GET['per_page'])) : null;

    /* Si NO se pide paginación, devolver todos (como antes, para selects) */
    if ($page === null && $perPage === null) {
        try {
            $rows = Database::callSP('sp_rubro_buscar', [$query]);
            ApiResponse::success($rows, 200);
        } catch (Throwable $e) {
            ApiResponse::error('rubros_list_failed', $e->getMessage(), 500);
        }
    }

    /* Si se pide paginación, hacerla en servidor */
    try {
        $pdo    = Database::connection();
        $offset = ($page - 1) * $perPage;

        /* Consulta parametrizada — el patrón LIKE se construye en PHP
           para evitar concatenar SQL crudo, y se pasa como parámetro. */
        $like   = $query !== null && $query !== ''
                  ? '%' . $query . '%'
                  : null;

        /* Detectar si la tabla rubro tiene las columnas codigo/descripcion
           para extender la búsqueda (preparado para futuras mejoras). */
        $hasCodigo      = Database::columnExists('rubro', 'codigo');
        $hasDescripcion = Database::columnExists('rubro', 'descripcion');

        $whereExtra = '';
        if ($like !== null) {
            $whereExtra = ' AND (nombre LIKE ?';
            $paramsWhere = [$like];
            if ($hasCodigo)      { $whereExtra .= ' OR codigo LIKE ?';      $paramsWhere[] = $like; }
            if ($hasDescripcion) { $whereExtra .= ' OR descripcion LIKE ?'; $paramsWhere[] = $like; }
            $whereExtra .= ')';
        } else {
            $paramsWhere = [];
        }

        /* Total de registros (con filtro) */
        $sqlCount = "SELECT COUNT(*) FROM dbo.rubro WHERE 1=1 {$whereExtra}";
        $stmtC = $pdo->prepare($sqlCount);
        foreach ($paramsWhere as $i => $v) {
            $stmtC->bindValue($i + 1, $v, PDO::PARAM_STR);
        }
        $stmtC->execute();
        $total = (int)$stmtC->fetchColumn();

        /* Página actual */
        $sqlPage = "SELECT id_rubro, nombre"
                 . ($hasCodigo ? ", codigo" : "")
                 . ($hasDescripcion ? ", descripcion" : "")
                 . ", CONVERT(VARCHAR(19), fecha_creacion, 120) AS fecha_creacion"
                 . " FROM dbo.rubro WHERE 1=1 {$whereExtra}"
                 . " ORDER BY nombre OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

        $stmtP = $pdo->prepare($sqlPage);
        foreach ($paramsWhere as $i => $v) {
            $stmtP->bindValue($i + 1, $v, PDO::PARAM_STR);
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
        ApiResponse::error('rubros_list_paged_failed', $e->getMessage(), 500);
    }
}

/* ============================================================
 *  GET /rubros/all — Listado completo sin paginar
 * ============================================================ */
if ($method === 'GET' && $id === 'all') {
    try {
        $rows = Database::callSP('sp_rubro_buscar', [null]);
        ApiResponse::success($rows, 200);
    } catch (Throwable $e) {
        ApiResponse::error('rubros_all_failed', $e->getMessage(), 500);
    }
}

/* ============================================================
 *  GET /rubros/{id} — Detalle de un rubro
 * ============================================================ */
if ($method === 'GET' && $id !== null && $id !== 'all' && is_numeric($id)) {
    try {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare("SELECT r.id_rubro, r.nombre,
                                      CONVERT(VARCHAR(19), r.fecha_creacion, 120) AS fecha_creacion,
                                      CONVERT(VARCHAR(19), r.fecha_modificacion, 120) AS fecha_modificacion
                               FROM dbo.rubro r
                               WHERE r.id_rubro = ?");
        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) ApiResponse::error('not_found', 'Rubro no encontrado', 404);
        ApiResponse::success($row, 200);
    } catch (Throwable $e) {
        ApiResponse::error('rubro_get_failed', $e->getMessage(), 500);
    }
}

/* ============================================================
 *  POST /rubros — Crear
 * ============================================================ */
if ($method === 'POST' && $id === null) {
    Auth::requirePermission('GEOGRAFIA', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nombre'])) ApiResponse::validationError(['nombre' => 'Requerido']);
    $pdo  = Database::connection();
    $stmt = $pdo->prepare("EXEC sp_rubro_guardar ?, ?, ?");
    $stmt->bindValue(1, null, PDO::PARAM_INT);
    $stmt->bindValue(2, $body['nombre'], PDO::PARAM_STR);
    $stmt->bindValue(3, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    ApiResponse::success(['id_rubro' => (int)($row['id_rubro'] ?? 0)], 201, 'Rubro creado');
}

/* ============================================================
 *  PUT /rubros/{id} — Actualizar
 * ============================================================ */
if ($method === 'PUT' && $id !== null) {
    Auth::requirePermission('GEOGRAFIA', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nombre'])) ApiResponse::validationError(['nombre' => 'Requerido']);
    $pdo  = Database::connection();
    $stmt = $pdo->prepare("EXEC sp_rubro_guardar ?, ?, ?");
    $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
    $stmt->bindValue(2, $body['nombre'], PDO::PARAM_STR);
    $stmt->bindValue(3, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
    $stmt->execute();
    ApiResponse::success(['id_rubro' => (int)$id], 200, 'Rubro actualizado');
}

/* ============================================================
 *  DELETE /rubros/{id} — Eliminar
 * ============================================================ */
if ($method === 'DELETE' && $id !== null) {
    Auth::requirePermission('GEOGRAFIA', 'eliminar');
    $rows = Database::callSP('sp_rubro_eliminar', [(int)$id, $user['correo'] ?? 'Sistema']);
    ApiResponse::success($rows[0] ?? ['success' => 1], 200, 'Rubro eliminado');
}

ApiResponse::error('not_found', 'Endpoint no encontrado', 404);
