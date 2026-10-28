<?php
/**
 * api/routes/geografia.php - Paises, divisiones (jerarquia), rubros, grupos, motivos
 *
 *   GET /geografia                            - Paises + divisiones (todo)
 *   GET /geografia/paises                     - Lista de paises
 *   GET /geografia/divisiones?id_pais=X       - Divisiones por pais (todas)
 *   GET /geografia/divisiones/hijas?id_padre=X - Hijas de una division (por id_padre)
 *   GET /geografia/divisiones/nivel?nivel=X   - Divisiones de un nivel (1=Pais, 2=Departamento, 3=Provincia, 4=Distrito)
 *   GET /geografia/tipos-division              - Tipos de division
 *   GET /geografia/rubros                     - Rubros
 *   GET /geografia/grupos                     - Grupos empresariales
 *   GET /geografia/motivos                    - Motivos de contacto
 *   GET /geografia/roles                      - Roles
 *   GET /geografia/validate?tabla=X&campo=Y&valor=Z - Validar duplicados
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $segments[1] ?? null;
$id     = $segments[2] ?? null;
$extra  = $segments[3] ?? null;  // Para sub-recursos como /paises/{id}

if ($method === 'GET' && in_array($action, [
    null, 'all', 'paises', 'tipos-division', 'divisiones', 'validate', 'motivos',
    /* FIX: acciones en cascada agregadas para soportar filtros jerárquicos */
    'cascada', 'departamentos', 'provincias', 'distritos', 'ruta',
], true)) {
    Auth::requireAnyPermission([
        ['GEOGRAFIA', 'ver'], ['EMPRESAS', 'ver'], ['SUCURSALES', 'ver'], ['CONTACTOS', 'ver'], ['TICKETS', 'ver'],
    ]);
} elseif (in_array($method, ['POST', 'PUT', 'DELETE'], true) && in_array($action, ['divisiones', 'paises', 'motivos'], true)) {
    $permission = ['POST' => 'registrar', 'PUT' => 'modificar', 'DELETE' => 'eliminar'][$method];
    Auth::requireAnyPermission([['GEOGRAFIA', $permission], ['TICKETS', $permission]]);
} else {
    ApiResponse::error('method_not_allowed', 'Metodo no permitido para este recurso.', 405);
}

$pdo = Database::connection();

/* ---- GET /geografia (sin parametros o 'all') ---- */
if ($action === null || $action === 'all') {
    $rows = Database::callSP('sp_obtener_geografia', [$_GET['id_pais'] ?? null]);
    ApiResponse::success($rows, 200);
}

/* ---- POST /geografia/divisiones ---- */
if ($method === 'POST' && $action === 'divisiones' && $id === null) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim((string)($body['nombre'] ?? ''));
    $type = (int)($body['id_tipo_division'] ?? 0);
    if ($name === '') ApiResponse::validationError(['nombre' => 'Requerido']);
    if (!in_array($type, [1, 2, 3], true)) ApiResponse::validationError(['id_tipo_division' => 'Use 1 departamento, 2 provincia o 3 distrito']);

    $parentId = null;
    if ($type === 1) {
        $countryId = (int)($body['id_pais'] ?? 0);
        if ($countryId < 1) ApiResponse::validationError(['id_pais' => 'Seleccione un pais']);
        $country = $pdo->prepare('SELECT 1 FROM dbo.pais WHERE id_pais = ?');
        $country->execute([$countryId]);
        if (!$country->fetchColumn()) ApiResponse::validationError(['id_pais' => 'Pais no encontrado']);
    } else {
        $parentId = (int)($body['id_division_padre'] ?? 0);
        if ($parentId < 1) ApiResponse::validationError(['id_division_padre' => 'Seleccione el nivel padre']);
        $parent = $pdo->prepare('SELECT id_tipo_division FROM dbo.division_geografica WHERE id_division = ?');
        $parent->execute([$parentId]);
        if ((int)$parent->fetchColumn() !== $type - 1) {
            ApiResponse::validationError(['id_division_padre' => 'El padre debe pertenecer al nivel geografico anterior']);
        }
        $countryId = (int)$pdo->query('SELECT dbo.fn_pais_de_division(' . $parentId . ')')->fetchColumn();
    }

    $duplicate = $pdo->prepare('SELECT 1 FROM dbo.division_geografica WHERE id_tipo_division = ? AND nombre = ? AND ((id_division_padre = ?) OR (id_division_padre IS NULL AND ? IS NULL))');
    $duplicate->execute([$type, $name, $parentId, $parentId]);
    if ($duplicate->fetchColumn()) ApiResponse::validationError(['nombre' => 'Ya existe una division con ese nombre en ese nivel']);

    $stmt = $pdo->prepare('INSERT INTO dbo.division_geografica (nombre, id_tipo_division, id_division_padre, id_pais) OUTPUT INSERTED.id_division VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $type, $parentId, $type === 1 ? $countryId : null]);
    ApiResponse::success(['id_division' => (int)$stmt->fetchColumn()], 201, 'Division creada');
}

/* ---- PUT /geografia/divisiones/{id} ---- */
if ($method === 'PUT' && $action === 'divisiones' && $id !== null) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim((string)($body['nombre'] ?? ''));
    if ($name === '') ApiResponse::validationError(['nombre' => 'Requerido']);
    $currentStmt = $pdo->prepare('SELECT id_tipo_division, id_division_padre, id_pais FROM dbo.division_geografica WHERE id_division = ?');
    $currentStmt->execute([(int)$id]);
    $current = $currentStmt->fetch();
    if (!$current) ApiResponse::error('not_found', 'Division no encontrada', 404);
    $type = (int)$current['id_tipo_division'];
    $parentId = $type === 1 ? null : (int)($body['id_division_padre'] ?? $current['id_division_padre']);
    if ($type > 1) {
        $parent = $pdo->prepare('SELECT id_tipo_division FROM dbo.division_geografica WHERE id_division = ?');
        $parent->execute([$parentId]);
        if ((int)$parent->fetchColumn() !== $type - 1) {
            ApiResponse::validationError(['id_division_padre' => 'El padre debe pertenecer al nivel geografico anterior']);
        }
    }
    if ((int)$id === $parentId) ApiResponse::validationError(['id_division_padre' => 'Una division no puede ser su propio padre']);
    $duplicate = $pdo->prepare('SELECT 1 FROM dbo.division_geografica WHERE id_tipo_division = ? AND nombre = ? AND id_division <> ? AND ((id_division_padre = ?) OR (id_division_padre IS NULL AND ? IS NULL))');
    $duplicate->execute([$type, $name, (int)$id, $parentId, $parentId]);
    if ($duplicate->fetchColumn()) ApiResponse::validationError(['nombre' => 'Ya existe una division con ese nombre en ese nivel']);

    if ($type === 1) {
        $countryId = (int)($body['id_pais'] ?? $current['id_pais'] ?? 0);
        $country = $pdo->prepare('SELECT 1 FROM dbo.pais WHERE id_pais = ?');
        $country->execute([$countryId]);
        if (!$country->fetchColumn()) ApiResponse::validationError(['id_pais' => 'Seleccione un pais valido']);
        $stmt = $pdo->prepare('UPDATE dbo.division_geografica SET nombre = ?, id_pais = ? WHERE id_division = ?');
        $stmt->execute([$name, $countryId, (int)$id]);
    } else {
        $stmt = $pdo->prepare('UPDATE dbo.division_geografica SET nombre = ?, id_division_padre = ? WHERE id_division = ?');
        $stmt->execute([$name, $parentId, (int)$id]);
    }
    ApiResponse::success(['id_division' => (int)$id], 200, 'Division actualizada');
}

/* ---- DELETE /geografia/divisiones/{id} ---- */
if ($method === 'DELETE' && $action === 'divisiones' && $id !== null) {
    $check = $pdo->prepare('SELECT 1 FROM dbo.division_geografica WHERE id_division = ?');
    $check->execute([(int)$id]);
    if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Division no encontrada', 404);
    $children = $pdo->prepare('SELECT 1 FROM dbo.division_geografica WHERE id_division_padre = ?');
    $children->execute([(int)$id]);
    if ($children->fetchColumn()) ApiResponse::error('has_children', 'No se puede eliminar: tiene divisiones hijas', 409);
    $used = $pdo->prepare('SELECT 1 FROM dbo.ubicacion WHERE id_division = ?');
    $used->execute([(int)$id]);
    if ($used->fetchColumn()) ApiResponse::error('in_use', 'No se puede eliminar: existen empresas o sedes en esta ubicacion', 409);
    $pdo->prepare('DELETE FROM dbo.division_geografica WHERE id_division = ?')->execute([(int)$id]);
    ApiResponse::success(['id_division' => (int)$id], 200, 'Division eliminada');
}

/* ---- GET /geografia/paises ---- */
if ($action === 'paises' && $method === 'GET') {
    $rows = $pdo->query("SELECT id_pais, nombre, codigo_iso FROM pais ORDER BY nombre")->fetchAll();
    ApiResponse::success($rows, 200);
}

/* ---- POST /geografia/paises (crear nuevo país) ---- */
if ($action === 'paises' && $method === 'POST' && $id === null) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $nombre = trim((string)($body['nombre'] ?? ''));
    $codigoIso = strtoupper(trim((string)($body['codigo_iso'] ?? '')));
    if ($nombre === '') ApiResponse::validationError(['nombre' => 'Requerido']);
    if ($codigoIso === '' || !preg_match('/^[A-Z]{2,3}$/', $codigoIso)) {
        ApiResponse::validationError(['codigo_iso' => 'Código ISO inválido (2-3 letras)']);
    }
    // Validar duplicados
    $dup = $pdo->prepare('SELECT 1 FROM dbo.pais WHERE nombre = ? OR codigo_iso = ?');
    $dup->execute([$nombre, $codigoIso]);
    if ($dup->fetchColumn()) {
        ApiResponse::validationError(['nombre' => 'Ya existe un país con ese nombre o código ISO']);
    }
    $stmt = $pdo->prepare('INSERT INTO dbo.pais (nombre, codigo_iso) OUTPUT INSERTED.id_pais VALUES (?, ?)');
    $stmt->execute([$nombre, $codigoIso]);
    $newId = (int)$stmt->fetchColumn();
    ApiResponse::success(['id_pais' => $newId], 201, 'País creado');
}

/* ---- PUT /geografia/paises/{id} (actualizar país) ---- */
if ($action === 'paises' && $method === 'PUT' && $id !== null) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $nombre = trim((string)($body['nombre'] ?? ''));
    $codigoIso = strtoupper(trim((string)($body['codigo_iso'] ?? '')));
    if ($nombre === '') ApiResponse::validationError(['nombre' => 'Requerido']);
    if ($codigoIso === '' || !preg_match('/^[A-Z]{2,3}$/', $codigoIso)) {
        ApiResponse::validationError(['codigo_iso' => 'Código ISO inválido (2-3 letras)']);
    }
    // Validar duplicados excluyendo el propio registro
    $dup = $pdo->prepare('SELECT 1 FROM dbo.pais WHERE (nombre = ? OR codigo_iso = ?) AND id_pais <> ?');
    $dup->execute([$nombre, $codigoIso, (int)$id]);
    if ($dup->fetchColumn()) {
        ApiResponse::validationError(['nombre' => 'Ya existe otro país con ese nombre o código ISO']);
    }
    $stmt = $pdo->prepare('UPDATE dbo.pais SET nombre = ?, codigo_iso = ? WHERE id_pais = ?');
    $stmt->execute([$nombre, $codigoIso, (int)$id]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM dbo.pais WHERE id_pais = ?');
        $check->execute([(int)$id]);
        if (!$check->fetchColumn()) ApiResponse::error('not_found', 'País no encontrado', 404);
    }
    ApiResponse::success(['id_pais' => (int)$id], 200, 'País actualizado');
}

/* ---- DELETE /geografia/paises/{id} (eliminar país) ---- */
if ($action === 'paises' && $method === 'DELETE' && $id !== null) {
    // Verificar que no tenga divisiones asociadas
    $hasDivisions = $pdo->prepare('SELECT 1 FROM dbo.division_geografica WHERE id_pais = ?');
    $hasDivisions->execute([(int)$id]);
    if ($hasDivisions->fetchColumn()) {
        ApiResponse::error('in_use', 'No se puede eliminar: el país tiene divisiones geográficas asociadas. Elimine primero las divisiones.', 409);
    }
    $stmt = $pdo->prepare('DELETE FROM dbo.pais WHERE id_pais = ?');
    $stmt->execute([(int)$id]);
    if ($stmt->rowCount() === 0) {
        ApiResponse::error('not_found', 'País no encontrado', 404);
    }
    ApiResponse::success(['id_pais' => (int)$id], 200, 'País eliminado');
}

/* ---- GET /geografia/tipos-division ---- */
if ($action === 'tipos-division') {
    $rows = $pdo->query("SELECT id_tipo_division, nombre FROM tipo_division ORDER BY id_tipo_division")->fetchAll();
    ApiResponse::success($rows, 200);
}

/* ---- GET /geografia/divisiones (todas las de un pais) ---- */
if ($action === 'divisiones' && $id === null) {
    $idPais = $_GET['id_pais'] ?? null;
    $sql = "SELECT d.id_division, d.nombre, d.id_tipo_division, d.id_division_padre,
                   COALESCE(d.id_pais, dbo.fn_pais_de_division(d.id_division)) AS id_pais,
                   pais.nombre AS pais, padre.nombre AS padre
            FROM dbo.division_geografica d
            LEFT JOIN dbo.division_geografica padre ON padre.id_division = d.id_division_padre
            LEFT JOIN dbo.pais pais ON pais.id_pais = COALESCE(d.id_pais, dbo.fn_pais_de_division(d.id_division))";
    if ($idPais) $sql .= ' WHERE COALESCE(d.id_pais, dbo.fn_pais_de_division(d.id_division)) = ?';
    $sql .= ' ORDER BY d.id_tipo_division, pais.nombre, padre.nombre, d.nombre';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($idPais ? [(int)$idPais] : []);
    $rows = $stmt->fetchAll();
    ApiResponse::success($rows, 200);
}

/* ---- GET /geografia/divisiones/hijas?id_padre=X ---- */
if ($action === 'divisiones' && $id === 'hijas') {
    $idPadre = $_GET['id_padre'] ?? null;
    if (!$idPadre) {
        ApiResponse::validationError(['id_padre' => 'Requerido']);
    }
    $stmt = $pdo->prepare("SELECT d.id_division, d.nombre, d.id_tipo_division, d.id_division_padre
                           FROM division_geografica d
                           WHERE d.id_division_padre = ?
                           ORDER BY d.nombre");
    $stmt->execute([(int)$idPadre]);
    $rows = $stmt->fetchAll();
    ApiResponse::success($rows, 200);
}

/* ---- GET /geografia/divisiones/nivel?nivel=X ---- */
// Devuelve las divisiones raiz (sin padre) de un nivel especifico.
// nivel=1: Paises, nivel=2: Departamentos, nivel=3: Provincias, nivel=4: Distritos
if ($action === 'divisiones' && $id === 'nivel') {
    $nivel = (int)($_GET['nivel'] ?? 0);
    if ($nivel < 1 || $nivel > 10) {
        ApiResponse::validationError(['nivel' => 'Nivel invalido (1-10)']);
    }

    // Para nivel 2 (departamentos): divisiones cuyo tipo=2 y cuyo padre tiene tipo=1 (pais)
    // Para nivel 3 (provincias): divisiones cuyo tipo=3 y cuyo padre tiene tipo=2
    // Para nivel 4 (distritos): divisiones cuyo tipo=4 y cuyo padre tiene tipo=3
    if ($nivel === 1) {
        // Paises - no estan en division_geografica, estan en tabla pais
        $rows = $pdo->query("SELECT id_pais AS id_division, nombre, 1 AS id_tipo_division, NULL AS id_division_padre FROM pais ORDER BY nombre")->fetchAll();
    } else {
        // Divisiones cuyo tipo_division = nivel y cuyo padre tiene tipo_division = nivel-1
        $sql = "SELECT d.id_division, d.nombre, d.id_tipo_division, d.id_division_padre,
                       dbo.fn_pais_de_division(d.id_division) AS id_pais
                FROM division_geografica d
                WHERE d.id_tipo_division = ?
                ORDER BY d.nombre";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nivel]);
        $rows = $stmt->fetchAll();
    }
    ApiResponse::success($rows, 200);
}

/* ---- GET /geografia/rubros ---- */
if ($action === 'rubros') {
    $rows = Database::callSP('sp_rubro_buscar', [$_GET['query'] ?? null]);
    ApiResponse::success($rows, 200);
}

/* ---- GET /geografia/grupos ---- */
if ($action === 'grupos') {
    $rows = Database::callSP('sp_grupo_buscar', [$_GET['query'] ?? null]);
    ApiResponse::success($rows, 200);
}

/* ---- GET /geografia/motivos ---- */
if ($action === 'motivos') {
    $rows = Database::callSP('sp_motivos_listar', []);
    ApiResponse::success($rows, 200);
}

/* ---- GET /geografia/roles ---- */
if ($action === 'roles') {
    $rows = $pdo->query("SELECT id_rol, nombre, descripcion FROM persona_rol ORDER BY nombre")->fetchAll();
    ApiResponse::success($rows, 200);
}

/* ---- GET /geografia/validate ----
 * Valida si un valor ya existe en una tabla. Recibe:
 *   tabla=empresa|sucursal|persona|rubro|grupo
 *   campo=ruc|correo|nombre|numero_documento|...
 *   valor=valor a verificar
 *   id_excluir (opcional, int > 0): ID del registro que se está editando,
 *      para excluirlo de la búsqueda de duplicados. CRÍTICO: si se omite
 *      o es 0, el resultado será true si el registro existe (falso positivo
 *      al editar). El frontend lo envía siempre que se edita un registro.
 */
if ($action === 'validate') {
    $tabla = $_GET['tabla'] ?? '';
    $campo = $_GET['campo'] ?? '';
    $valor = $_GET['valor'] ?? '';

    /* FIX: Normalizar id_excluir a entero positivo. Antes, '0' (string)
     * pasaba el if ($idExcluir) (PHP: '0' es falsy, así que se excluía en
     * ese caso) pero otros valores como null o string vacío no se excluían
     * correctamente. Ahora solo se aplica la exclusión si es un entero > 0. */
    $idExcluirRaw = $_GET['id_excluir'] ?? null;
    $idExcluir = (is_numeric($idExcluirRaw) && (int)$idExcluirRaw > 0)
        ? (int)$idExcluirRaw
        : null;

    if (!$tabla || !$campo || !$valor) {
        ApiResponse::validationError(['tabla' => 'Requerido', 'campo' => 'Requerido', 'valor' => 'Requerido']);
    }

    $tablasPermitidas = ['empresa' => 'empresa', 'sucursal' => 'empresa_sucursal', 'persona' => 'persona', 'rubro' => 'rubro', 'grupo' => 'grupo_empresarial'];
    if (!isset($tablasPermitidas[$tabla])) {
        ApiResponse::error('invalid_table', 'Tabla no permitida para validacion', 400);
    }
    $tablaReal = $tablasPermitidas[$tabla];

    // Lista blanca de campos válidos por tabla para evitar inyección SQL
    $camposPermitidos = [
        'empresa'           => ['ruc', 'correo', 'razon_social', 'nombre_comercial'],
        'empresa_sucursal'  => ['nombre'],
        'persona'           => ['correo', 'numero_documento'],
        'rubro'             => ['nombre'],
        'grupo_empresarial' => ['nombre'],
    ];
    if (!isset($camposPermitidos[$tablaReal]) || !in_array($campo, $camposPermitidos[$tablaReal], true)) {
        ApiResponse::error('invalid_field', 'Campo no permitido para esta tabla', 400);
    }

    $campoExtra = '';
    $params = [$valor];
    if ($tabla === 'sucursal' && !empty($_GET['id_empresa'])) {
        $campoExtra = ' AND id_empresa = ?';
        $params[] = (int)$_GET['id_empresa'];
    }
    if ($idExcluir !== null) {
        $campoId = ($tabla === 'empresa') ? 'id_empresa' : ($tabla === 'persona' ? 'id_persona' : ($tabla === 'sucursal' ? 'id_sucursal' : ($tabla === 'rubro' ? 'id_rubro' : 'id_grupo')));
        $campoExtra .= " AND $campoId <> ?";
        $params[] = $idExcluir;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $tablaReal WHERE $campo = ? $campoExtra");
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();
    ApiResponse::success(['existe' => $count > 0, 'count' => $count], 200);
}

/* ============================================================================
 *  CRUD DE MOTIVOS DE CONTACTO
 *  POST   /geografia/motivos           - Crear motivo
 *  PUT    /geografia/motivos/{id}      - Actualizar motivo
 *  DELETE /geografia/motivos/{id}      - Eliminar motivo
 * ============================================================================ */

/* ---- POST /geografia/motivos ---- */
if ($method === 'POST' && $action === 'motivos' && $id === null) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $nombre = trim((string)($body['nombre'] ?? ''));
    $descripcion = trim((string)($body['descripcion'] ?? ''));
    $orden = (int)($body['orden'] ?? 0);
    if ($nombre === '') ApiResponse::validationError(['nombre' => 'Requerido']);
    $stmt = $pdo->prepare('INSERT INTO dbo.motivo_contacto (nombre, descripcion, activo, orden) OUTPUT INSERTED.id_motivo VALUES (?, ?, 1, ?)');
    $stmt->execute([$nombre, $descripcion ?: null, $orden]);
    $newId = (int)$stmt->fetchColumn();
    Audit::log('MOTIVOS', 'CREAR', 'Motivo creado: ' . $nombre, 'motivo_contacto', (string)$newId);
    ApiResponse::success(['id_motivo' => $newId], 201, 'Motivo creado');
}

/* ---- PUT /geografia/motivos/{id} ---- */
if ($method === 'PUT' && $action === 'motivos' && $id !== null) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $nombre = trim((string)($body['nombre'] ?? ''));
    $descripcion = trim((string)($body['descripcion'] ?? ''));
    $orden = (int)($body['orden'] ?? 0);
    if ($nombre === '') ApiResponse::validationError(['nombre' => 'Requerido']);
    $stmt = $pdo->prepare('UPDATE dbo.motivo_contacto SET nombre = ?, descripcion = ?, orden = ? WHERE id_motivo = ?');
    $stmt->execute([$nombre, $descripcion ?: null, $orden, (int)$id]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM dbo.motivo_contacto WHERE id_motivo = ?');
        $check->execute([(int)$id]);
        if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Motivo no encontrado', 404);
    }
    Audit::log('MOTIVOS', 'EDITAR', 'Motivo #' . $id . ' actualizado: ' . $nombre, 'motivo_contacto', (string)$id);
    ApiResponse::success(['id_motivo' => (int)$id], 200, 'Motivo actualizado');
}

/* ---- DELETE /geografia/motivos/{id} ---- */
if ($method === 'DELETE' && $action === 'motivos' && $id !== null) {
    // Verificar si el motivo está en uso
    $check = $pdo->prepare('SELECT COUNT(*) FROM dbo.ticket WHERE id_motivo_contacto = ?');
    $check->execute([(int)$id]);
    if ((int)$check->fetchColumn() > 0) {
        ApiResponse::error('in_use', 'No se puede eliminar: el motivo tiene tickets asociados', 409);
    }
    $stmt = $pdo->prepare('DELETE FROM dbo.motivo_contacto WHERE id_motivo = ?');
    $stmt->execute([(int)$id]);
    if ($stmt->rowCount() === 0) ApiResponse::error('not_found', 'Motivo no encontrado', 404);
    Audit::log('MOTIVOS', 'ELIMINAR', 'Motivo #' . $id . ' eliminado', 'motivo_contacto', (string)$id);
    ApiResponse::success(['id_motivo' => (int)$id], 200, 'Motivo eliminado');
}

/* ============================================================================
 * ENDPOINTS DE FILTROS GEOGRÁFICOS EN CASCADA
 *   GET /geografia/cascada?id_pais=X        - Deptos+Provincias+Distritos del país en 1 sola petición
 *   GET /geografia/departamentos?id_pais=X  - Solo departamentos del país
 *   GET /geografia/provincias?id_departamento=X - Solo provincias del departamento
 *   GET /geografia/distritos?id_provincia=X - Solo distritos de la provincia
 *   GET /geografia/ruta?id_division=X       - Ruta completa de una división
 *
 * ESQUEMA REAL DE TU BD (según diagnóstico):
 *   id_tipo_division = 1 → Departamento / Región (26 en Perú, hijo de nada/país)
 *   id_tipo_division = 2 → Provincia (196, hijas de tipo 1)
 *   id_tipo_division = 3 → Distrito (775, hijos de tipo 2)
 *   id_tipo_division = 4 → (no existe en tu BD)
 *
 * Las divisiones tipo 1 (Lima, Arequipa, Cusco, etc.) son los
 * departamentos, NO son países. El id_pais está en el campo id_pais
 * de la propia división tipo 1.
 *
 * JERARQUÍA:
 *   Pais (tabla dbo.pais)
 *     └→ División tipo 1 (Departamento) — id_pais lleno en la propia división
 *         └→ División tipo 2 (Provincia) — id_division_padre = id del depto
 *             └→ División tipo 3 (Distrito) — id_division_padre = id de la provincia
 * ========================================================================== */

/* ---- GET /geografia/cascada?id_pais=X ----
 * Devuelve la jerarquía completa de un país en una sola petición.
 * Estructura: { pais_division, departamentos, provincias, distritos }
 */
if ($method === 'GET' && $action === 'cascada') {
    $idPais = (int)($_GET['id_pais'] ?? 0);

    // 1. Departamentos: tipo 1 cuyo id_pais = X
    $stmtDeptos = $pdo->prepare("
        SELECT id_division, nombre, id_pais
        FROM dbo.division_geografica
        WHERE id_tipo_division = 1
          AND id_pais = ?
        ORDER BY nombre
    ");
    $stmtDeptos->execute([$idPais]);
    $departamentos = $stmtDeptos->fetchAll();

    // Lista de IDs de departamentos
    $deptoIds = array_map(fn($d) => (int)$d['id_division'], $departamentos);
    $deptoIdsList = empty($deptoIds) ? '0' : implode(',', $deptoIds);

    // 2. Provincias: tipo 2 cuyo padre está en la lista de departamentos
    $sqlProvs = "
        SELECT d.id_division, d.nombre, d.id_division_padre AS id_departamento,
               (SELECT nombre FROM dbo.division_geografica WHERE id_division = d.id_division_padre) AS departamento_nombre
        FROM dbo.division_geografica d
        WHERE d.id_tipo_division = 2
          AND d.id_division_padre IN ($deptoIdsList)
        ORDER BY d.nombre
    ";
    $provs = $pdo->query($sqlProvs);
    $provincias = $provs ? $provs->fetchAll() : [];

    // Lista de IDs de provincias
    $provIds = array_map(fn($p) => (int)$p['id_division'], $provincias);
    $provIdsList = empty($provIds) ? '0' : implode(',', $provIds);

    // 3. Distritos: tipo 3 cuyo padre está en la lista de provincias
    $sqlDists = "
        SELECT d.id_division, d.nombre, d.id_division_padre AS id_provincia,
               (SELECT nombre FROM dbo.division_geografica WHERE id_division = d.id_division_padre) AS provincia_nombre
        FROM dbo.division_geografica d
        WHERE d.id_tipo_division = 3
          AND d.id_division_padre IN ($provIdsList)
        ORDER BY d.nombre
    ";
    $dists = $pdo->query($sqlDists);
    $distritos = $dists ? $dists->fetchAll() : [];

    ApiResponse::success([
        'pais_division'  => null,  // No hay división tipo "país" en tu esquema
        'departamentos'  => $departamentos,
        'provincias'     => $provincias,
        'distritos'      => $distritos,
    ], 200);
}

/* ---- GET /geografia/departamentos?id_pais=X ---- */
if ($method === 'GET' && $action === 'departamentos') {
    $idPais = (int)($_GET['id_pais'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT d.id_division, d.nombre, d.id_pais,
               p.nombre AS pais_nombre
        FROM dbo.division_geografica d
        LEFT JOIN dbo.pais p ON d.id_pais = p.id_pais
        WHERE d.id_tipo_division = 1
          AND d.id_pais = ?
        ORDER BY d.nombre
    ");
    $stmt->execute([$idPais]);
    ApiResponse::success($stmt->fetchAll(), 200);
}

/* ---- GET /geografia/provincias?id_departamento=X ----
 * Devuelve las provincias (tipo 2) hijas del departamento (tipo 1).
 */
if ($method === 'GET' && $action === 'provincias') {
    $idDepto = (int)($_GET['id_departamento'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT d.id_division, d.nombre, d.id_division_padre AS id_departamento,
               (SELECT nombre FROM dbo.division_geografica WHERE id_division = d.id_division_padre) AS departamento_nombre
        FROM dbo.division_geografica d
        WHERE d.id_tipo_division = 2
          AND d.id_division_padre = ?
        ORDER BY d.nombre
    ");
    $stmt->execute([$idDepto]);
    ApiResponse::success($stmt->fetchAll(), 200);
}

/* ---- GET /geografia/distritos?id_provincia=X ----
 * Devuelve los distritos (tipo 3) hijos de la provincia (tipo 2).
 */
if ($method === 'GET' && $action === 'distritos') {
    $idProv = (int)($_GET['id_provincia'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT d.id_division, d.nombre, d.id_division_padre AS id_provincia,
               (SELECT nombre FROM dbo.division_geografica WHERE id_division = d.id_division_padre) AS provincia_nombre
        FROM dbo.division_geografica d
        WHERE d.id_tipo_division = 3
          AND d.id_division_padre = ?
        ORDER BY d.nombre
    ");
    $stmt->execute([$idProv]);
    ApiResponse::success($stmt->fetchAll(), 200);
}

/* ---- GET /geografia/ruta?id_division=X ---- */
if ($method === 'GET' && $action === 'ruta') {
    $idDiv = (int)($_GET['id_division'] ?? 0);

    $ruta = [];
    $current = $idDiv;
    $depth = 0;
    while ($current && $depth < 10) {
        $stmt = $pdo->prepare("
            SELECT d.id_division, d.nombre, d.id_tipo_division,
                   d.id_division_padre, d.id_pais,
                   p.nombre AS pais_nombre
            FROM dbo.division_geografica d
            LEFT JOIN dbo.pais p ON d.id_pais = p.id_pais
            WHERE d.id_division = ?
        ");
        $stmt->execute([$current]);
        $row = $stmt->fetch();
        if (!$row) break;
        $ruta[] = $row;
        $current = $row['id_division_padre'];
        $depth++;
    }

    $ruta = array_reverse($ruta);

    $resultado = ['ruta' => $ruta, 'pais' => null, 'departamento' => null,
                  'provincia' => null, 'distrito' => null];
    foreach ($ruta as $r) {
        $tipo = (int)$r['id_tipo_division'];
        if ($tipo === 1)      $resultado['departamento'] = $r;
        elseif ($tipo === 2)  $resultado['provincia']    = $r;
        elseif ($tipo === 3)  $resultado['distrito']     = $r;
    }

    ApiResponse::success($resultado, 200);
}

ApiResponse::error('not_found', 'Endpoint no encontrado. Disponibles: paises, tipos-division, divisiones, divisiones/hijas, divisiones/nivel, cascada, departamentos, provincias, distritos, ruta, rubros, grupos, motivos, roles, validate', 404);
