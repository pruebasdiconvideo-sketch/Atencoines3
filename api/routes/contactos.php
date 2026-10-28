<?php
/**
 * api/routes/contactos.php - CRUD de Contactos de Empresa
 *
 * Los contactos de empresas son clientes externos.
 * NO acceden al sistema (no se les crea usuario de acceso).
 * Se gestionan desde aquí y se visualizan en el detalle de cada empresa.
 */
$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;
$pdo = Database::connection();
$state = $_GET['filtro_estado'] ?? 'activas';
if (!in_array($state, ['activas', 'bajas', 'todas'], true)) {
    ApiResponse::validationError(['filtro_estado' => 'Debe ser activas, bajas o todas']);
}

// GET: cualquier usuario con permiso de ver en CONTACTOS, EMPRESAS o TICKETS
// (necesario para ver contactos al crear tickets y al ver detalle de empresa)
if ($method === 'GET') {
    Auth::requireAnyPermission([
        ['CONTACTOS', 'ver'],
        ['EMPRESAS', 'ver'],
        ['TICKETS', 'ver'],
    ]);
} else {
    Auth::requirePermission('CONTACTOS', 'ver');
}
$contactoSelect = "SELECT c.id_empresa_contacto, c.id_empresa, c.id_sucursal, c.id_persona,
                          c.cargo, c.telefono_empresa, c.correo_empresa, c.es_principal,
                          c.estado, c.activo, c.fecha_baja, e.razon_social AS empresa,
                          s.nombre AS sucursal, p.nombres, p.apellido_paterno, p.apellido_materno,
                          p.numero_documento, p.correo AS correo_personal
                   FROM dbo.empresa_contacto c
                   INNER JOIN dbo.empresa e ON e.id_empresa = c.id_empresa
                   INNER JOIN dbo.persona p ON p.id_persona = c.id_persona
                   LEFT JOIN dbo.empresa_sucursal s ON s.id_sucursal = c.id_sucursal";

if ($method === 'GET' && $id === null) {
    $sql = $contactoSelect . ' WHERE 1 = 1';
    $params = [];
    if ($state === 'activas') {
        // Incluir activos=1 y activos=NULL (por compatibilidad con datos legacy)
        $sql .= ' AND (c.activo = 1 OR c.activo IS NULL)';
    } elseif ($state === 'bajas') {
        $sql .= ' AND c.activo = 0';
    }
    // Si es 'todas', no se filtra por activo
    if (!empty($_GET['id_empresa'])) {
        $sql .= ' AND c.id_empresa = ?';
        $params[] = (int)$_GET['id_empresa'];
    }
    if (!empty($_GET['query'])) {
        $sql .= ' AND (p.nombres LIKE ? OR p.apellido_paterno LIKE ? OR p.numero_documento LIKE ? OR c.cargo LIKE ? OR e.razon_social LIKE ? OR c.correo_empresa LIKE ?)';
        $term = '%' . trim((string)$_GET['query']) . '%';
        array_push($params, $term, $term, $term, $term, $term, $term);
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY e.razon_social, p.apellido_paterno, p.nombres');
    $stmt->execute($params);
    ApiResponse::success($stmt->fetchAll(), 200);
}

if ($method === 'GET' && $id !== null && $action === null) {
    $stmt = $pdo->prepare($contactoSelect . ' WHERE c.id_empresa_contacto = ?');
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    if (!$row) ApiResponse::error('not_found', 'Contacto no encontrado', 404);
    ApiResponse::success($row, 200);
}

if ($method === 'POST' && $id === null) {
    Auth::requirePermission('CONTACTOS', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['id_empresa', 'nombres', 'apellido_paterno'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    $email = trim((string)($body['correo'] ?? ''));
    $email = $email === '' ? null : mb_strtolower($email);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ApiResponse::validationError(['correo' => 'Correo personal no valido']);
    }
    if ($email !== null) {
        $duplicate = $pdo->prepare('SELECT 1 FROM dbo.persona WHERE LOWER(correo) = ? AND activo = 1');
        $duplicate->execute([$email]);
        if ($duplicate->fetchColumn()) ApiResponse::validationError(['correo' => 'Ya existe una persona con este correo']);
    }
    if (!empty($body['numero_documento'])) {
        $duplicate = $pdo->prepare('SELECT 1 FROM dbo.persona WHERE numero_documento = ? AND activo = 1');
        $duplicate->execute([trim((string)$body['numero_documento'])]);
        if ($duplicate->fetchColumn()) ApiResponse::validationError(['numero_documento' => 'El documento ya esta registrado']);
    }

    $pdo->beginTransaction();
    try {
        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO dbo.persona
            (tipo_documento, numero_documento, nombres, apellido_paterno, apellido_materno,
             correo, telefono, direccion, sexo, estado, contrasena, bloqueado, id_empresa, id_sucursal, activo)
            OUTPUT INSERTED.id_persona
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, 1, ?, 1, ?, NULL, 1)");
        $stmt->execute([
            $body['tipo_documento'],
            trim((string)($body['numero_documento'] ?? '')) ?: null,
            trim((string)$body['nombres']),
            trim((string)$body['apellido_paterno']),
            trim((string)($body['apellido_materno'] ?? '')) ?: null,
            $email,
            trim((string)($body['telefono_empresa'] ?? '')) ?: null,
            $passwordHash,
            (int)$body['id_empresa'],
        ]);
        $personId = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('EXEC dbo.sp_contacto_guardar ?, ?, ?, ?, ?, ?, ?, ?, ?, ?');
        $contactId = 0;
        $stmt->bindParam(1, $contactId, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->bindValue(2, (int)$body['id_empresa'], PDO::PARAM_INT);
        $stmt->bindValue(3, null, PDO::PARAM_NULL);
        $stmt->bindValue(4, $personId, PDO::PARAM_INT);
        $stmt->bindValue(5, trim((string)($body['cargo'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(6, trim((string)($body['telefono_empresa'] ?? '')) ?: null, PDO::PARAM_STR);
        $corporateEmail = trim((string)($body['correo_empresa'] ?? ''));
        $corporateEmail = $corporateEmail === '' ? null : $corporateEmail;
        $stmt->bindValue(7, $corporateEmail, $corporateEmail === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(8, !empty($body['es_principal']), PDO::PARAM_BOOL);
        $stmt->bindValue(9, 1, PDO::PARAM_INT);
        $stmt->bindValue(10, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();
        $contactId = (int)($row['id_empresa_contacto'] ?? $contactId);
        $pdo->commit();
        ApiResponse::success([
            'id_empresa_contacto' => $contactId,
            'id_persona' => $personId,
        ], 201, 'Contacto creado como persona bloqueada para inicio de sesion.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

if ($method === 'PUT' && $id !== null) {
    Auth::requirePermission('CONTACTOS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['id_empresa', 'nombres', 'apellido_paterno'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    $contactStmt = $pdo->prepare('SELECT id_empresa, id_sucursal, id_persona FROM dbo.empresa_contacto WHERE id_empresa_contacto = ?');
    $contactStmt->execute([(int)$id]);
    $existing = $contactStmt->fetch();
    if (!$existing) ApiResponse::error('not_found', 'Contacto no encontrado', 404);
    $personId = (int)$existing['id_persona'];
    $email = trim((string)($body['correo'] ?? ''));
    $email = $email === '' ? null : mb_strtolower($email);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ApiResponse::validationError(['correo' => 'Correo personal no valido']);
    }
    if ($email !== null) {
        $duplicate = $pdo->prepare('SELECT 1 FROM dbo.persona WHERE LOWER(correo) = ? AND id_persona <> ? AND activo = 1');
        $duplicate->execute([$email, $personId]);
        if ($duplicate->fetchColumn()) ApiResponse::validationError(['correo' => 'Ya existe una persona con este correo']);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE dbo.persona
                       SET tipo_documento = ?, numero_documento = ?, nombres = ?, apellido_paterno = ?,
                           apellido_materno = ?, correo = ?, telefono = ?
                       WHERE id_persona = ?")
            ->execute([
                $body['tipo_documento'],
                trim((string)($body['numero_documento'] ?? '')) ?: null,
                trim((string)$body['nombres']),
                trim((string)$body['apellido_paterno']),
                trim((string)($body['apellido_materno'] ?? '')) ?: null,
                $email,
                trim((string)($body['telefono_empresa'] ?? '')) ?: null,
                $personId,
            ]);

        $stmt = $pdo->prepare('EXEC dbo.sp_contacto_guardar ?, ?, ?, ?, ?, ?, ?, ?, ?, ?');
        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$body['id_empresa'], PDO::PARAM_INT);
        $idSucursal = $body['id_sucursal'] ?? $existing['id_sucursal'];
        $stmt->bindValue(3, $idSucursal, $idSucursal === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(4, $personId, PDO::PARAM_INT);
        $stmt->bindValue(5, trim((string)($body['cargo'] ?? '')) ?: null, PDO::PARAM_STR);
        $stmt->bindValue(6, trim((string)($body['telefono_empresa'] ?? '')) ?: null, PDO::PARAM_STR);
        $corporateEmail = trim((string)($body['correo_empresa'] ?? ''));
        $corporateEmail = $corporateEmail === '' ? null : $corporateEmail;
        $stmt->bindValue(7, $corporateEmail, $corporateEmail === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(8, !empty($body['es_principal']), PDO::PARAM_BOOL);
        $stmt->bindValue(9, (int)($body['estado'] ?? 1), PDO::PARAM_INT);
        $stmt->bindValue(10, $user['correo'] ?? 'Sistema', PDO::PARAM_STR);
        $stmt->execute();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    ApiResponse::success(['id_empresa_contacto' => (int)$id], 200, 'Contacto actualizado');
}

if ($method === 'PATCH' && $id !== null && $action === 'reactivar') {
    Auth::requirePermission('CONTACTOS', 'modificar');
    $stmt = $pdo->prepare('UPDATE dbo.empresa_contacto SET activo = 1, fecha_baja = NULL WHERE id_empresa_contacto = ?');
    $stmt->execute([(int)$id]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM dbo.empresa_contacto WHERE id_empresa_contacto = ?');
        $check->execute([(int)$id]);
        if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Contacto no encontrado', 404);
    }
    ApiResponse::success(['id_empresa_contacto' => (int)$id], 200, 'Contacto reactivado');
}

if ($method === 'DELETE' && $id !== null) {
    Auth::requirePermission('CONTACTOS', 'eliminar');
    if (($_GET['permanente'] ?? '') === '1') {
        $contact = $pdo->prepare('SELECT activo, id_persona FROM dbo.empresa_contacto WHERE id_empresa_contacto = ?');
        $contact->execute([(int)$id]);
        $contactRow = $contact->fetch();
        if (!$contactRow) ApiResponse::error('not_found', 'Contacto no encontrado', 404);
        if ((int)$contactRow['activo'] === 1) ApiResponse::error('must_be_inactive', 'Primero debe dar de baja el contacto', 409);
        $hasRole = $pdo->prepare('SELECT 1 FROM dbo.persona_rol_asignacion WHERE id_persona = ?');
        $hasRole->execute([(int)$contactRow['id_persona']]);
        if ($hasRole->fetchColumn()) ApiResponse::error('protected_person', 'La persona tiene un rol de sistema y no puede eliminarse desde Contactos', 409);
        $isTechnician = $pdo->prepare('SELECT 1 FROM dbo.ticket WHERE id_usuario = ?');
        $isTechnician->execute([(int)$contactRow['id_persona']]);
        if ($isTechnician->fetchColumn()) ApiResponse::error('protected_person', 'La persona tiene actividad de sistema y no puede eliminarse desde Contactos', 409);
        try {
            $rows = Database::callSP('sp_contacto_eliminar', [(int)$id, $user['correo'] ?? 'Sistema']);
        } catch (Throwable $e) {
            ApiResponse::error('delete_failed', $e->getMessage(), 409);
        }
        ApiResponse::success($rows[0] ?? ['id_empresa_contacto' => (int)$id], 200, 'Contacto eliminado definitivamente');
    }
    $stmt = $pdo->prepare('UPDATE dbo.empresa_contacto SET activo = 0, fecha_baja = GETDATE() WHERE id_empresa_contacto = ? AND activo = 1');
    $stmt->execute([(int)$id]);
    if ($stmt->rowCount() === 0) ApiResponse::error('not_found', 'Contacto activo no encontrado', 404);
    ApiResponse::success(['id_empresa_contacto' => (int)$id], 200, 'Contacto dado de baja');
}

ApiResponse::error('not_found', 'Endpoint no encontrado', 404);
