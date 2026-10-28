<?php
/**
 * api/routes/cotizaciones_emisora.php
 *
 * CRUD de Empresas Emisoras (las que emiten las cotizaciones).
 * Incluye gestión del LOGO corporativo (upload / download / delete).
 *
 * Endpoints:
 *   GET    /cotizaciones_emisora                       Listar emisoras
 *   POST   /cotizaciones_emisora                       Crear emisora
 *   GET    /cotizaciones_emisora/{id}                  Detalle de una emisora (incluye logo)
 *   PUT    /cotizaciones_emisora/{id}                  Actualizar emisora
 *   DELETE /cotizaciones_emisora/{id}                  Desactivar emisora
 *   POST   /cotizaciones_emisora/{id}/logo             Subir/actualizar logo (multipart o base64)
 *   GET    /cotizaciones_emisora/{id}/logo             Descargar logo como imagen
 *   DELETE /cotizaciones_emisora/{id}/logo             Eliminar logo
 *
 * SPs usados:
 *   - sp_cotizacion_emisora_listar
 *   - sp_cotizacion_emisora_get
 *   - sp_cotizacion_emisora_save
 *   - sp_cotizacion_emisora_logo_save
 */

Auth::requirePermission('COTIZACIONES', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

/* =====================================================================
 *  GET /cotizaciones_emisora — Listar
 * ===================================================================== */
if ($method === 'GET' && $id === null) {
    Auth::requirePermission('COTIZACIONES', 'ver');

    $incluirInactivas = !empty($_GET['incluir_inactivas']) ? 1 : 0;
    $incluirLogo      = !empty($_GET['con_logo'])          ? 1 : 0;

    // Si solo quiere las activas (sin logo), puede hacer cualquiera con permiso ver
    // Si quiere ver inactivas, requiere permiso modificar (admin)
    if ($incluirInactivas === 1) {
        Auth::requirePermission('COTIZACIONES', 'modificar');
    }

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_emisora_listar @incluir_inactivas = ?, @incluir_logo = ?");
        $stmt->bindValue(1, $incluirInactivas, PDO::PARAM_INT);
        $stmt->bindValue(2, $incluirLogo,      PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        ApiResponse::success($rows, 200);
    } catch (Throwable $e) {
        ApiResponse::error('list_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  POST /cotizaciones_emisora — Crear
 * ===================================================================== */
if ($method === 'POST' && $id === null) {
    Auth::requirePermission('COTIZACIONES', 'registrar');

    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    foreach (['razon_social','ruc'] as $k) {
        if (empty($body[$k])) {
            ApiResponse::validationError([$k => 'Requerido']);
        }
    }

    $idUsuario = (int)($body['id_usuario'] ?? ($user['id_persona'] ?? 0));

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "EXEC dbo.sp_cotizacion_emisora_save
                @id_emisora = ?, @razon_social = ?, @nombre_comercial = ?, @ruc = ?,
                @direccion = ?, @telefono = ?, @correo = ?, @web = ?,
                @es_principal = ?, @activo = ?, @id_usuario = ?"
        );

        $idEmisora = null;
        $stmt->bindParam(1, $idEmisora, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->bindValue(2,  $body['razon_social'],                    PDO::PARAM_STR);
        $stmt->bindValue(3,  $body['nombre_comercial'] ?? null,        PDO::PARAM_STR);
        $stmt->bindValue(4,  $body['ruc'],                              PDO::PARAM_STR);
        $stmt->bindValue(5,  $body['direccion'] ?? null,                PDO::PARAM_STR);
        $stmt->bindValue(6,  $body['telefono']  ?? null,                PDO::PARAM_STR);
        $stmt->bindValue(7,  $body['correo']    ?? null,                PDO::PARAM_STR);
        $stmt->bindValue(8,  $body['web']       ?? null,                PDO::PARAM_STR);
        $stmt->bindValue(9,  !empty($body['es_principal']) ? 1 : 0,    PDO::PARAM_INT);
        $stmt->bindValue(10, isset($body['activo']) ? (int)(bool)$body['activo'] : 1, PDO::PARAM_INT);
        $stmt->bindValue(11, $idUsuario, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        $idNew = (int)($row['id_emisora'] ?? $idEmisora);

        Audit::log('COTIZACIONES', 'EMISORA_CREAR',
            'Empresa emisora creada: ' . $body['razon_social'],
            'cotizacion_empresa_emisora', (string)$idNew);

        ApiResponse::success(['id_emisora' => $idNew] + ($row ?: []),
            201, 'Empresa emisora creada');
    } catch (Throwable $e) {
        ApiResponse::error('emisora_create_failed', $e->getMessage(), 422);
    }
}

/* =====================================================================
 *  GET /cotizaciones_emisora/{id} — Detalle (incluye logo)
 * ===================================================================== */
if ($method === 'GET' && $id !== null && $action === null) {
    Auth::requirePermission('COTIZACIONES', 'ver');
    $idEmi = (int)$id;
    if ($idEmi <= 0) ApiResponse::error('invalid_id', 'ID inválido', 400);

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_emisora_get @id_emisora = ?");
        $stmt->bindValue(1, $idEmi, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) ApiResponse::error('not_found', 'Emisora no encontrada', 404);
        ApiResponse::success($row, 200);
    } catch (Throwable $e) {
        ApiResponse::error('get_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  PUT /cotizaciones_emisora/{id} — Actualizar
 * ===================================================================== */
if ($method === 'PUT' && $id !== null && $action === null) {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    $idEmi = (int)$id;

    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    foreach (['razon_social','ruc'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }

    $idUsuario = (int)($body['id_usuario'] ?? ($user['id_persona'] ?? 0));

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "EXEC dbo.sp_cotizacion_emisora_save
                @id_emisora = ?, @razon_social = ?, @nombre_comercial = ?, @ruc = ?,
                @direccion = ?, @telefono = ?, @correo = ?, @web = ?,
                @es_principal = ?, @activo = ?, @id_usuario = ?"
        );
        $stmt->bindValue(1,  $idEmi, PDO::PARAM_INT);
        $stmt->bindValue(2,  $body['razon_social'],                 PDO::PARAM_STR);
        $stmt->bindValue(3,  $body['nombre_comercial'] ?? null,     PDO::PARAM_STR);
        $stmt->bindValue(4,  $body['ruc'],                          PDO::PARAM_STR);
        $stmt->bindValue(5,  $body['direccion'] ?? null,           PDO::PARAM_STR);
        $stmt->bindValue(6,  $body['telefono']  ?? null,            PDO::PARAM_STR);
        $stmt->bindValue(7,  $body['correo']    ?? null,            PDO::PARAM_STR);
        $stmt->bindValue(8,  $body['web']       ?? null,            PDO::PARAM_STR);
        $stmt->bindValue(9,  !empty($body['es_principal']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(10, isset($body['activo']) ? (int)(bool)$body['activo'] : 1, PDO::PARAM_INT);
        $stmt->bindValue(11, $idUsuario, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        Audit::log('COTIZACIONES', 'EMISORA_UPDATE',
            'Empresa emisora actualizada ID ' . $idEmi,
            'cotizacion_empresa_emisora', (string)$idEmi);

        ApiResponse::success($row ?: ['success' => 1, 'id_emisora' => $idEmi], 200, 'Emisora actualizada');
    } catch (Throwable $e) {
        ApiResponse::error('emisora_update_failed', $e->getMessage(), 422);
    }
}

/* =====================================================================
 *  DELETE /cotizaciones_emisora/{id} — Desactivar (no borrar físicamente)
 * ===================================================================== */
if ($method === 'DELETE' && $id !== null && $action === null) {
    Auth::requirePermission('COTIZACIONES', 'eliminar');
    $idEmi = (int)$id;

    try {
        $pdo = Database::connection();
        // Verificar que no tenga cotizaciones activas
        $chk = $pdo->prepare("SELECT COUNT(*) FROM dbo.cotizacion
                              WHERE id_emisora = ? AND eliminado = 0
                                AND estado NOT IN ('CANCELADA','CERRADA')");
        $chk->execute([$idEmi]);
        if ((int)$chk->fetchColumn() > 0) {
            ApiResponse::error('has_active_quotes',
                'No se puede desactivar: tiene cotizaciones activas asociadas.', 409);
        }

        $stmt = $pdo->prepare("UPDATE dbo.cotizacion_empresa_emisora
                               SET activo = 0, fecha_modificacion = SYSDATETIME()
                               WHERE id_emisora = ?");
        $stmt->execute([$idEmi]);

        Audit::log('COTIZACIONES', 'EMISORA_DESACTIVAR',
            'Empresa emisora desactivada ID ' . $idEmi,
            'cotizacion_empresa_emisora', (string)$idEmi);

        ApiResponse::success(['success' => 1, 'id_emisora' => $idEmi], 200, 'Emisora desactivada');
    } catch (Throwable $e) {
        ApiResponse::error('deactivate_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  POST /cotizaciones_emisora/{id}/logo — Subir logo
 *
 *  Acepta 3 formatos:
 *    1) multipart/form-data con campo "logo" (file)
 *    2) JSON {"logo_base64": "data:image/png;base64,iVBOR..."}
 *    3) JSON {"logo_base64": "<solo-base64-sin-prefijo>", "mime": "image/png"}
 *
 *  Validaciones:
 *    - Extensiones permitidas: png, jpg, jpeg, gif, webp, svg+xml
 *    - Tamaño máximo: 2 MB
 *    - MIME type coherente con la extensión
 * ===================================================================== */
if ($method === 'POST' && $id !== null && $action === 'logo') {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    $idEmi = (int)$id;

    $maxBytes = 2 * 1024 * 1024; // 2 MB
    $allowedMimes = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    $logoDataUrl = null;
    $mimeDetectado = null;

    // CASO 1: multipart/form-data (input type=file)
    if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['logo'];
        if ($file['size'] > $maxBytes) {
            ApiResponse::error('logo_too_large',
                'El logo supera el tamaño máximo permitido (2 MB).', 413);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowedMimes[$mime])) {
            ApiResponse::error('logo_invalid_type',
                'Tipo de archivo no permitido. Use PNG, JPG, GIF, WebP o SVG.', 415);
        }

        $bin = file_get_contents($file['tmp_name']);
        $b64 = base64_encode($bin);
        $logoDataUrl = 'data:' . $mime . ';base64,' . $b64;
        $mimeDetectado = $mime;
    }
    // CASO 2/3: JSON con base64
    else {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (empty($body['logo_base64'])) {
            ApiResponse::validationError(['logo_base64' => 'Requerido (o suba un archivo con campo "logo")']);
        }

        $raw = trim($body['logo_base64']);

        // Detectar si viene con prefijo data:...
        if (preg_match('#^data:(image/[a-z+]+);base64,(.+)$#i', $raw, $m)) {
            $mimeDetectado = strtolower($m[1]);
            $b64 = $m[2];
        } else {
            $mimeDetectado = strtolower($body['mime'] ?? 'image/png');
            $b64 = $raw;
        }

        if (!isset($allowedMimes[$mimeDetectado])) {
            ApiResponse::error('logo_invalid_type',
                'MIME no permitido: ' . $mimeDetectado, 415);
        }

        // Validar que el base64 sea válido
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) === 0) {
            ApiResponse::error('logo_invalid_base64',
                'El contenido base64 no es válido.', 422);
        }
        if (strlen($bin) > $maxBytes) {
            ApiResponse::error('logo_too_large',
                'El logo supera el tamaño máximo permitido (2 MB).', 413);
        }

        $logoDataUrl = 'data:' . $mimeDetectado . ';base64,' . $b64;
    }

    $idUsuario = (int)($body['id_usuario'] ?? $user['id_persona'] ?? 0);

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "EXEC dbo.sp_cotizacion_emisora_logo_save
                @id_emisora = ?, @logo_base64 = ?, @id_usuario = ?"
        );
        $stmt->bindValue(1, $idEmi, PDO::PARAM_INT);
        $stmt->bindValue(2, $logoDataUrl, PDO::PARAM_STR);
        $stmt->bindValue(3, $idUsuario, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        Audit::log('COTIZACIONES', 'LOGO_UPLOAD',
            'Logo actualizado para emisora #' . $idEmi,
            'cotizacion_empresa_emisora', (string)$idEmi,
            ['mime' => $mimeDetectado, 'size_bytes' => strlen($bin ?? '')]);

        ApiResponse::success($row ?: ['success' => 1, 'id_emisora' => $idEmi],
            200, 'Logo actualizado correctamente');
    } catch (Throwable $e) {
        error_log('[Emisora Logo POST] error: ' . $e->getMessage());
        ApiResponse::error('logo_save_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  GET /cotizaciones_emisora/{id}/logo — Descargar logo (stream binario)
 * ===================================================================== */
if ($method === 'GET' && $id !== null && $action === 'logo') {
    // No requiere permisos especiales: el logo puede mostrarse públicamente en cotizaciones
    $idEmi = (int)$id;

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT logo_base64 FROM dbo.cotizacion_empresa_emisora WHERE id_emisora = ?");
        $stmt->execute([$idEmi]);
        $logo = $stmt->fetchColumn();

        if (!$logo) {
            ApiResponse::error('no_logo', 'La emisora no tiene logo cargado.', 404);
        }

        // Parsear data URL
        if (preg_match('#^data:(image/[a-z+]+);base64,(.+)$#i', $logo, $m)) {
            $mime = $m[1];
            $bin  = base64_decode($m[2]);
        } else {
            $mime = 'image/png';
            $bin  = base64_decode($logo);
        }

        if ($bin === false) {
            ApiResponse::error('logo_corrupt', 'El logo almacenado está corrupto.', 500);
        }

        // Devolver como imagen
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: private, max-age=86400, immutable');
        header('X-Content-Type-Options: nosniff');
        http_response_code(200);
        echo $bin;
        exit;
    } catch (Throwable $e) {
        ApiResponse::error('logo_get_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  DELETE /cotizaciones_emisora/{id}/logo — Eliminar logo
 * ===================================================================== */
if ($method === 'DELETE' && $id !== null && $action === 'logo') {
    Auth::requirePermission('COTIZACIONES', 'modificar');
    $idEmi = (int)$id;
    $idUsuario = (int)($user['id_persona'] ?? 0);

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("UPDATE dbo.cotizacion_empresa_emisora
                               SET logo_base64 = NULL,
                                   fecha_modificacion = SYSDATETIME(),
                                   id_usuario_modificacion = ?
                               WHERE id_emisora = ?");
        $stmt->execute([$idUsuario, $idEmi]);

        Audit::log('COTIZACIONES', 'LOGO_DELETE',
            'Logo eliminado para emisora #' . $idEmi,
            'cotizacion_empresa_emisora', (string)$idEmi);

        ApiResponse::success(['success' => 1, 'id_emisora' => $idEmi],
            200, 'Logo eliminado');
    } catch (Throwable $e) {
        ApiResponse::error('logo_delete_failed', $e->getMessage(), 500);
    }
}

ApiResponse::error('not_found',
    "Endpoint no encontrado. Method=$method, resource=cotizaciones_emisora, id=$id, action=$action",
    404
);
