<?php
/**
 * api/routes/sucursales.php - CRUD de Sucursales
 */
$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;
$pdo = Database::connection();
$state = $_GET['filtro_estado'] ?? 'activas';
if (!in_array($state, ['activas', 'bajas', 'todas'], true)) {
    ApiResponse::validationError(['filtro_estado' => 'Debe ser activas, bajas o todas']);
}

// GET: flexible para ver sucursales desde empresas, tickets o sucursales
if ($method === 'GET') {
    Auth::requireAnyPermission([
        ['SUCURSALES', 'ver'],
        ['EMPRESAS', 'ver'],
        ['TICKETS', 'ver'],
    ]);
} else {
    Auth::requirePermission('SUCURSALES', 'ver');
}
$sucursalSelect = "SELECT s.id_sucursal, s.id_empresa, s.nombre, s.telefono, s.es_principal,
                          s.activo, s.fecha_baja, e.razon_social AS empresa,
                          u.direccion, u.referencia, u.id_division,
                     dbo.fn_pais_de_division(u.id_division) AS id_pais,
                     pais.nombre AS pais,
                          d.id_tipo_division,
                          d.id_division_padre,
                          p.id_division_padre AS id_padre_padre,
                     CASE WHEN d.id_tipo_division = 1 THEN d.nombre
                         WHEN p.id_tipo_division = 1 THEN p.nombre
                         WHEN gp.id_tipo_division = 1 THEN gp.nombre
                         WHEN ggp.id_tipo_division = 1 THEN ggp.nombre END AS departamento,
                     CASE WHEN d.id_tipo_division = 2 THEN d.nombre
                         WHEN p.id_tipo_division = 2 THEN p.nombre
                         WHEN gp.id_tipo_division = 2 THEN gp.nombre
                         WHEN ggp.id_tipo_division = 2 THEN ggp.nombre END AS provincia,
                     CASE WHEN d.id_tipo_division = 3 THEN d.nombre
                         WHEN p.id_tipo_division = 3 THEN p.nombre
                         WHEN gp.id_tipo_division = 3 THEN gp.nombre
                         WHEN ggp.id_tipo_division = 3 THEN ggp.nombre END AS distrito,
                     dbo.fn_obtener_ruta_geografica(u.id_division) AS ubicacion_completa
                   FROM dbo.empresa_sucursal s
                   INNER JOIN dbo.empresa e ON e.id_empresa = s.id_empresa
                INNER JOIN dbo.ubicacion u ON u.id_ubicacion = s.id_ubicacion
                LEFT JOIN dbo.division_geografica d ON d.id_division = u.id_division
                LEFT JOIN dbo.division_geografica p ON p.id_division = d.id_division_padre
                LEFT JOIN dbo.division_geografica gp ON gp.id_division = p.id_division_padre
                LEFT JOIN dbo.division_geografica ggp ON ggp.id_division = gp.id_division_padre
                LEFT JOIN dbo.pais pais ON pais.id_pais = dbo.fn_pais_de_division(u.id_division)";

if ($method === 'GET' && $id === null) {
    $sql = $sucursalSelect . ' WHERE 1 = 1';
    $params = [];
    if ($state !== 'todas') {
        $sql .= ' AND s.activo = ?';
        $params[] = $state === 'activas' ? 1 : 0;
    }
    if (!empty($_GET['id_empresa'])) {
        $sql .= ' AND s.id_empresa = ?';
        $params[] = (int)$_GET['id_empresa'];
    }
    if (!empty($_GET['query'])) {
        $sql .= ' AND (s.nombre LIKE ? OR e.razon_social LIKE ? OR u.direccion LIKE ? OR s.telefono LIKE ?)';
        $term = '%' . trim((string)$_GET['query']) . '%';
        array_push($params, $term, $term, $term, $term);
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY e.razon_social, s.es_principal DESC, s.nombre');
    $stmt->execute($params);
    ApiResponse::success($stmt->fetchAll(), 200);
}

if ($method === 'GET' && $id !== null && $action === null) {
    $stmt = $pdo->prepare($sucursalSelect . ' WHERE s.id_sucursal = ?');
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    if (!$row) ApiResponse::error('not_found', 'Sede no encontrada', 404);
    ApiResponse::success($row, 200);
}

if ($method === 'POST' && $id === null) {
    Auth::requirePermission('SUCURSALES', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['id_empresa', 'nombre', 'direccion', 'id_division'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    $pdo = Database::connection();
    $stmt = $pdo->prepare("EXEC sp_sucursal_guardar ?, ?, ?, ?, ?, ?, ?, ?, ?");
    $newId = 0;
    $stmt->bindParam(1, $newId, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
    $stmt->bindValue(2, (int)$body['id_empresa'], PDO::PARAM_INT);
    $stmt->bindValue(3, $body['nombre'], PDO::PARAM_STR);
    $stmt->bindValue(4, $body['telefono'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(5, $body['es_principal'] ?? 0, PDO::PARAM_INT);
    $stmt->bindValue(6, $body['direccion'], PDO::PARAM_STR);
    $stmt->bindValue(7, $body['referencia'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(8, (int)$body['id_division'], PDO::PARAM_INT);
    $stmt->bindValue(9, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    ApiResponse::success(['id_sucursal' => (int)($row['id_sucursal'] ?? $newId)], 201, 'Sucursal creada');
}

if ($method === 'PUT' && $id !== null) {
    Auth::requirePermission('SUCURSALES', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['id_empresa', 'nombre', 'direccion', 'id_division'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    $pdo = Database::connection();
    $stmt = $pdo->prepare("EXEC sp_sucursal_guardar ?, ?, ?, ?, ?, ?, ?, ?, ?");
    $savedId = (int)$id;
    $stmt->bindParam(1, $savedId, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
    $stmt->bindValue(2, (int)$body['id_empresa'], PDO::PARAM_INT);
    $stmt->bindValue(3, $body['nombre'], PDO::PARAM_STR);
    $stmt->bindValue(4, $body['telefono'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(5, $body['es_principal'] ?? 0, PDO::PARAM_INT);
    $stmt->bindValue(6, $body['direccion'], PDO::PARAM_STR);
    $stmt->bindValue(7, $body['referencia'] ?? null, PDO::PARAM_STR);
    $stmt->bindValue(8, (int)$body['id_division'], PDO::PARAM_INT);
    $stmt->bindValue(9, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
    $stmt->execute();
    ApiResponse::success(['id_sucursal' => (int)$id], 200, 'Sucursal actualizada');
}

if ($method === 'PATCH' && $id !== null && $action === 'reactivar') {
    Auth::requirePermission('SUCURSALES', 'modificar');
    $stmt = $pdo->prepare('UPDATE dbo.empresa_sucursal SET activo = 1, fecha_baja = NULL WHERE id_sucursal = ?');
    $stmt->execute([(int)$id]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM dbo.empresa_sucursal WHERE id_sucursal = ?');
        $check->execute([(int)$id]);
        if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Sede no encontrada', 404);
    }
    ApiResponse::success(['id_sucursal' => (int)$id], 200, 'Sede reactivada');
}

if ($method === 'DELETE' && $id !== null) {
    Auth::requirePermission('SUCURSALES', 'eliminar');
    if (($_GET['permanente'] ?? '') === '1') {
        $status = $pdo->prepare('SELECT activo FROM dbo.empresa_sucursal WHERE id_sucursal = ?');
        $status->execute([(int)$id]);
        $active = $status->fetchColumn();
        if ($active === false) ApiResponse::error('not_found', 'Sede no encontrada', 404);
        if ((int)$active === 1) ApiResponse::error('must_be_inactive', 'Primero debe dar de baja la sede', 409);
        $contact = $pdo->prepare('SELECT 1 FROM dbo.empresa_contacto WHERE id_sucursal = ?');
        $contact->execute([(int)$id]);
        if ($contact->fetchColumn()) ApiResponse::error('has_dependencies', 'Desasocie o elimine definitivamente sus contactos antes de eliminar la sede', 409);
        try {
            $rows = Database::callSP('sp_sucursal_eliminar', [(int)$id, $user['correo'] ?? 'Sistema']);
        } catch (Throwable $e) {
            ApiResponse::error('delete_failed', $e->getMessage(), 409);
        }
        ApiResponse::success($rows[0] ?? ['id_sucursal' => (int)$id], 200, 'Sede eliminada definitivamente');
    }
    $stmt = $pdo->prepare('UPDATE dbo.empresa_sucursal SET activo = 0, fecha_baja = GETDATE() WHERE id_sucursal = ? AND activo = 1');
    $stmt->execute([(int)$id]);
    if ($stmt->rowCount() === 0) ApiResponse::error('not_found', 'Sede activa no encontrada', 404);
    ApiResponse::success(['id_sucursal' => (int)$id], 200, 'Sede dada de baja');
}

ApiResponse::error('not_found', 'Endpoint no encontrado', 404);
