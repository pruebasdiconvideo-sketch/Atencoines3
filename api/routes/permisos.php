<?php
/**
 * api/routes/permisos.php - Roles y permisos por modulo
 *
 *   GET    /permisos                - Lista todos los roles
 *   GET    /permisos/roles          - Alias de roles
 *   GET    /permisos/{id_rol}        - Modulos + permisos actuales del rol
 *   POST   /permisos/{id_rol}        - Guardar permisos del rol (JSON)
 *   POST   /permisos/roles           - Crear nuevo rol
 *   PUT    /permisos/roles/{id_rol}  - Actualizar rol
 *   DELETE /permisos/roles/{id_rol} - Eliminar rol
 *   GET    /permisos/modulos         - Lista de modulos del catalogo
 */
Auth::requirePermission('SEGURIDAD', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

/* ---- GET /permisos/modulos ---- */
if ($method === 'GET' && $id === 'modulos') {
    $pdo = Database::connection();
    $rows = $pdo->query("SELECT id_modulo, codigo, nombre, descripcion, orden
                         FROM modulo_catalogo WHERE activo = 1 ORDER BY orden")->fetchAll();
    ApiResponse::success($rows, 200);
}

/* ---- GET /permisos/roles o GET /permisos (sin id) ---- */
if ($method === 'GET' && ($id === null || $id === 'roles') && $action === null) {
    $pdo = Database::connection();
    $rows = $pdo->query("
        SELECT r.id_rol, r.nombre, r.descripcion,
               (SELECT COUNT(*) FROM persona_rol_asignacion pra WHERE pra.id_rol = r.id_rol) AS usuarios_count
        FROM persona_rol r ORDER BY r.nombre")->fetchAll();
    ApiResponse::success($rows, 200);
}

/* ---- POST /permisos/roles ---- */
if ($method === 'POST' && $id === 'roles') {
    Auth::requirePermission('SEGURIDAD', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nombre'])) ApiResponse::validationError(['nombre' => 'Requerido']);
    $pdo = Database::connection();
    $stmt = $pdo->prepare("INSERT INTO persona_rol (nombre, descripcion) OUTPUT INSERTED.id_rol VALUES (?, ?)");
    $stmt->execute([$body['nombre'], $body['descripcion'] ?? null]);
    $newId = (int)$stmt->fetchColumn();
    ApiResponse::success(['id_rol' => $newId], 201, 'Rol creado');
}

/* ---- PUT /permisos/roles/{id_rol} ---- */
if ($method === 'PUT' && $id === 'roles' && $action !== null) {
    Auth::requirePermission('SEGURIDAD', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nombre'])) ApiResponse::validationError(['nombre' => 'Requerido']);
    $pdo = Database::connection();
    $pdo->prepare("UPDATE persona_rol SET nombre = ?, descripcion = ? WHERE id_rol = ?")
        ->execute([$body['nombre'], $body['descripcion'] ?? null, (int)$action]);
    ApiResponse::success(['id_rol' => (int)$action], 200, 'Rol actualizado');
}

/* ---- DELETE /permisos/roles/{id_rol} ---- */
if ($method === 'DELETE' && $id === 'roles' && $action !== null) {
    Auth::requirePermission('SEGURIDAD', 'eliminar');
    $pdo = Database::connection();
    // Verificar que no tenga usuarios asignados
    $count = $pdo->query("SELECT COUNT(*) FROM persona_rol_asignacion WHERE id_rol = " . (int)$action)->fetchColumn();
    if ((int)$count > 0) {
        ApiResponse::error('rol_con_usuarios', "No se puede eliminar: tiene $count usuario(s) asignado(s)", 400);
    }
    $pdo->prepare("DELETE FROM role_permissions WHERE id_rol = ?")->execute([(int)$action]);
    $pdo->prepare("DELETE FROM persona_rol WHERE id_rol = ?")->execute([(int)$action]);
    ApiResponse::success(['success' => 1], 200, 'Rol eliminado');
}

/* ---- GET /permisos/{id_rol} ---- */
if ($method === 'GET' && $id !== null && $id !== 'roles' && $id !== 'modulos') {
    $rows = Database::callSP('sp_permisos_get', [(int)$id]);
    // Primer result set: modulos
    $modulos = $rows;
    // Segundo result set: permisos del rol
    $stmt = Database::connection()->query("SELECT modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir
                                            FROM role_permissions WHERE id_rol = " . (int)$id);
    $perms = [];
    while ($r = $stmt->fetch()) {
        $perms[$r['modulo']] = $r;
    }
    ApiResponse::success([
        'modulos'    => $modulos,
        'permisos'   => $perms,
        'id_rol'     => (int)$id,
    ], 200);
}

/* ---- POST /permisos/{id_rol} ---- */
if ($method === 'POST' && $id !== null && $id !== 'roles' && $id !== 'modulos') {
    Auth::requirePermission('SEGURIDAD', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['permisos'])) ApiResponse::validationError(['permisos' => 'Requerido']);
    $permJson = json_encode($body['permisos'], JSON_UNESCAPED_UNICODE);
    $rows = Database::callSP('sp_permisos_save_v2', [(int)$id, $permJson, $user['id_persona'] ?? null]);
    ApiResponse::success($rows[0] ?? ['success' => 1], 200, 'Permisos guardados');
}

ApiResponse::error('not_found', 'Endpoint no encontrado', 404);
