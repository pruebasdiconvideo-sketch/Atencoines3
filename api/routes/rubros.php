<?php
/**
 * api/routes/rubros.php - CRUD Rubros
 *   GET    /rubros           - Listado
 *   POST   /rubros           - Crear
 *   PUT    /rubros/{id}      - Actualizar
 *   DELETE /rubros/{id}      - Eliminar
 */
$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;

if ($method === 'GET' && $id === null) {
    Auth::requireAnyPermission([['GEOGRAFIA', 'ver'], ['EMPRESAS', 'ver']]);
} else {
    Auth::requirePermission('GEOGRAFIA', 'ver');
}

if ($method === 'GET' && $id === null) {
    $rows = Database::callSP('sp_rubro_buscar', [$_GET['query'] ?? null]);
    ApiResponse::success($rows, 200);
}

if ($method === 'POST' && $id === null) {
    Auth::requirePermission('GEOGRAFIA', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nombre'])) ApiResponse::validationError(['nombre' => 'Requerido']);
    $pdo = Database::connection();
    $stmt = $pdo->prepare("EXEC sp_rubro_guardar ?, ?, ?");
    $stmt->bindValue(1, null, PDO::PARAM_INT);
    $stmt->bindValue(2, $body['nombre'], PDO::PARAM_STR);
    $stmt->bindValue(3, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    ApiResponse::success(['id_rubro' => (int)($row['id_rubro'] ?? 0)], 201, 'Rubro creado');
}

if ($method === 'PUT' && $id !== null) {
    Auth::requirePermission('GEOGRAFIA', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nombre'])) ApiResponse::validationError(['nombre' => 'Requerido']);
    $pdo = Database::connection();
    $stmt = $pdo->prepare("EXEC sp_rubro_guardar ?, ?, ?");
    $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
    $stmt->bindValue(2, $body['nombre'], PDO::PARAM_STR);
    $stmt->bindValue(3, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
    $stmt->execute();
    ApiResponse::success(['id_rubro' => (int)$id], 200, 'Rubro actualizado');
}

if ($method === 'DELETE' && $id !== null) {
    Auth::requirePermission('GEOGRAFIA', 'eliminar');
    $rows = Database::callSP('sp_rubro_eliminar', [(int)$id, $user['correo'] ?? 'Sistema']);
    ApiResponse::success($rows[0] ?? ['success' => 1], 200, 'Rubro eliminado');
}

ApiResponse::error('not_found', 'Endpoint no encontrado', 404);
