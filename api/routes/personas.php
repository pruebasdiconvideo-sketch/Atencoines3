<?php
/**
 * api/routes/personas.php - CRUD de Personas/Trabajadores
 *
 * IMPORTANTE: Este endpoint devuelve SOLO trabajadores (usuarios del sistema).
 * Los contactos de empresas (clientes externos) se excluyen automáticamente
 * porque NO acceden al sistema. Se filtra eliminando las personas que
 * aparecen en la tabla empresa_contacto.
 */
$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

if ($method === 'GET' && $id === null) {
    Auth::requireAnyPermission([['PERSONAS', 'ver'], ['CONTACTOS', 'ver']]);
} else {
    Auth::requirePermission('PERSONAS', 'ver');
}

if ($method === 'GET' && $id === null) {
    $pdo = Database::connection();
    $search = $_GET['query'] ?? '';
    // FILTRO CRÍTICO: Excluir contactos de empresas (clientes externos).
    // Se excluyen por dos criterios:
    // 1) La persona existe en empresa_contacto (es contacto de una empresa)
    // 2) El rol asignado es "Cliente" o contiene "Contacto empresarial"
    // Solo se muestran trabajadores (usuarios que acceden al sistema).
    $stmt = $pdo->prepare("
        SELECT TOP 500 p.id_persona, p.tipo_documento, p.numero_documento, p.nombres,
               p.apellido_paterno, p.apellido_materno, p.correo, p.telefono, p.estado,
               p.bloqueado, p.activo, p.id_empresa, p.id_sucursal,
               pra.id_rol, pr.nombre AS rol_nombre
        FROM persona p WITH (NOLOCK)
        LEFT JOIN persona_rol_asignacion pra WITH (NOLOCK) ON p.id_persona = pra.id_persona
        LEFT JOIN persona_rol pr WITH (NOLOCK) ON pra.id_rol = pr.id_rol
        WHERE p.activo = 1
          AND NOT EXISTS (
              SELECT 1 FROM empresa_contacto ec WITH (NOLOCK)
              WHERE ec.id_persona = p.id_persona
          )
          AND (
              pr.nombre IS NULL
              OR (
                  pr.nombre NOT LIKE '%Cliente%'
                  AND pr.nombre NOT LIKE '%Contacto%'
                  AND pr.nombre NOT LIKE '%CLIENTE%'
                  AND pr.nombre NOT LIKE '%CONTACTO%'
              )
          )
          AND (
              ? = '' OR
              p.nombres LIKE '%' + ? + '%' OR
              p.apellido_paterno LIKE '%' + ? + '%' OR
              p.numero_documento LIKE '%' + ? + '%' OR
              p.correo LIKE '%' + ? + '%'
          )
        ORDER BY p.apellido_paterno, p.nombres");
    $stmt->execute([$search, $search, $search, $search, $search]);
    ApiResponse::success($stmt->fetchAll(), 200);
}

if ($method === 'GET' && $id !== null) {
    $pdo = Database::connection();
    $stmt = $pdo->prepare("
        SELECT p.id_persona, p.tipo_documento, p.numero_documento, p.nombres,
               p.apellido_paterno, p.apellido_materno, p.fecha_nacimiento,
               p.telefono, p.correo, p.direccion, p.sexo, p.estado, p.bloqueado,
               p.activo, p.id_empresa, p.id_sucursal,
               pra.id_rol, pr.nombre AS rol_nombre
        FROM persona p WITH (NOLOCK)
        LEFT JOIN persona_rol_asignacion pra WITH (NOLOCK) ON p.id_persona = pra.id_persona
        LEFT JOIN persona_rol pr WITH (NOLOCK) ON pra.id_rol = pr.id_rol
        WHERE p.id_persona = ?");
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    if (!$row) ApiResponse::error('not_found', 'Persona no encontrada', 404);
    ApiResponse::success($row, 200);
}

if ($method === 'POST' && $id === null) {
    Auth::requirePermission('PERSONAS', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['tipo_documento', 'nombres', 'apellido_paterno', 'correo'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }

    // Hash bcrypt de la contrasena si viene, sino usar DEFAULT_PASS
    $hash = !empty($body['contrasena']) ? password_hash($body['contrasena'], PASSWORD_BCRYPT) : null;

    $pdo = Database::connection();
    // Insertar directamente porque el SP usa 'DEFAULT_PASS' literal
    $stmt = $pdo->prepare("
        INSERT INTO persona (tipo_documento, numero_documento, nombres, apellido_paterno,
                             apellido_materno, correo, telefono, direccion, sexo, estado,
                             contrasena, bloqueado, activo)
        OUTPUT INSERTED.id_persona
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, 1)");
    $stmt->execute([
        $body['tipo_documento'],
        $body['numero_documento'] ?? null,
        $body['nombres'],
        $body['apellido_paterno'],
        $body['apellido_materno'] ?? null,
        $body['correo'],
        $body['telefono'] ?? null,
        $body['direccion'] ?? null,
        $body['sexo'] ?? null,
        $hash ?? 'DEFAULT_PASS',
    ]);
    $newId = (int)$stmt->fetchColumn();

    // Asignar rol si viene
    if (!empty($body['id_rol'])) {
        $pdo->prepare("INSERT INTO persona_rol_asignacion (id_persona, id_rol, asignado_por) VALUES (?, ?, ?)")
            ->execute([$newId, (int)$body['id_rol'], $user['id_persona'] ?? null]);
    }

    // Auditoría
    Audit::log('PERSONAS', 'CREAR', 'Trabajador creado: ' . $body['nombres'] . ' ' . ($body['apellido_paterno'] ?? ''),
        'persona', (string)$newId, ['correo' => $body['correo'], 'id_rol' => $body['id_rol'] ?? null]);

    ApiResponse::success(['id_persona' => $newId], 201, 'Trabajador creado');
}

if ($method === 'PUT' && $id !== null) {
    Auth::requirePermission('PERSONAS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $pdo = Database::connection();
    $updates = [];
    $params = [];
    $fields = ['tipo_documento', 'numero_documento', 'nombres', 'apellido_paterno',
               'apellido_materno', 'correo', 'telefono', 'direccion', 'sexo', 'estado'];

    foreach ($fields as $f) {
        if (array_key_exists($f, $body)) {
            $updates[] = "$f = ?";
            $params[] = $body[$f];
        }
    }

    // Cambio de contrasena si viene
    if (!empty($body['contrasena'])) {
        $hash = password_hash($body['contrasena'], PASSWORD_BCRYPT);
        $updates[] = "contrasena = ?";
        $params[] = $hash;
    }

    // Cambio de bloqueado si viene
    if (array_key_exists('bloqueado', $body)) {
        $updates[] = "bloqueado = ?";
        $params[] = (int)$body['bloqueado'];
    }

    if (empty($updates)) ApiResponse::validationError(['campos' => 'Nada que actualizar']);

    $params[] = (int)$id;
    $sql = "UPDATE persona SET " . implode(', ', $updates) . " WHERE id_persona = ?";
    $pdo->prepare($sql)->execute($params);

    // Actualizar rol si viene
    if (!empty($body['id_rol'])) {
        $pdo->prepare("DELETE FROM persona_rol_asignacion WHERE id_persona = ?")->execute([(int)$id]);
        $pdo->prepare("INSERT INTO persona_rol_asignacion (id_persona, id_rol, asignado_por) VALUES (?, ?, ?)")
            ->execute([(int)$id, (int)$body['id_rol'], $user['id_persona'] ?? null]);
    }

    // Auditoría
    Audit::log('PERSONAS', 'EDITAR', 'Trabajador #' . $id . ' actualizado',
        'persona', (string)$id, ['campos' => array_keys($body)]);

    ApiResponse::success(['id_persona' => (int)$id], 200, 'Trabajador actualizado');
}

/* ---- PATCH /personas/{id}/bloqueo - Bloquear/desbloquear acceso ---- */
if ($method === 'PATCH' && $id !== null && $action === 'bloqueo') {
    Auth::requirePermission('PERSONAS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $bloquear = (int)($body['bloqueado'] ?? -1);
    if ($bloquear !== 0 && $bloquear !== 1) {
        ApiResponse::validationError(['bloqueado' => 'Debe ser 0 o 1']);
    }
    $pdo = Database::connection();
    $stmt = $pdo->prepare("UPDATE persona SET bloqueado = ? WHERE id_persona = ?");
    $stmt->execute([$bloquear, (int)$id]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM persona WHERE id_persona = ?');
        $check->execute([(int)$id]);
        if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Persona no encontrada', 404);
    }

    // Auditoría
    Audit::log('PERSONAS', $bloquear ? 'BLOQUEAR' : 'DESBLOQUEAR',
        'Trabajador #' . $id . ($bloquear ? ' bloqueado' : ' desbloqueado'),
        'persona', (string)$id);

    ApiResponse::success(['id_persona' => (int)$id, 'bloqueado' => $bloquear], 200,
        $bloquear ? 'Usuario bloqueado' : 'Usuario desbloqueado');
}

if ($method === 'DELETE' && $id !== null) {
    Auth::requirePermission('PERSONAS', 'eliminar');
    // Soft delete
    $pdo = Database::connection();
    $pdo->prepare("UPDATE persona SET activo = 0, fecha_baja = GETDATE() WHERE id_persona = ?")
        ->execute([(int)$id]);

    // Auditoría
    Audit::log('PERSONAS', 'DESACTIVAR', 'Trabajador #' . $id . ' desactivado',
        'persona', (string)$id);

    ApiResponse::success(['success' => 1], 200, 'Trabajador desactivado');
}

ApiResponse::error('not_found', 'Endpoint no encontrado', 404);
