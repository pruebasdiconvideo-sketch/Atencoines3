<?php
/**
 * api/routes/cotizaciones_documentos.php
 *
 * Gestión de documentos adjuntos a cotizaciones.
 *
 * Endpoints:
 *   GET    /cotizaciones_documentos/{id_cotizacion}                  Listar documentos
 *   POST   /cotizaciones_documentos/{id_cotizacion}                  Subir documento (multipart o base64)
 *   GET    /cotizaciones_documentos/{id_cotizacion}/{id_documento}   Descargar documento (stream binario)
 *   GET    /cotizaciones_documentos/{id_cotizacion}/{id_documento}/view  Ver documento inline (en navegador)
 *   DELETE /cotizaciones_documentos/{id_cotizacion}/{id_documento}   Eliminar documento
 *
 * SPs usados:
 *   - sp_cotizacion_documento_save
 *   - sp_cotizacion_documento_get
 */

Auth::requirePermission('COTIZACIONES', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$idCot  = $segments[1] ?? null;
$idDoc  = $segments[2] ?? null;
$action = $segments[3] ?? null;

if ($idCot === null) {
    ApiResponse::error('invalid_request',
        'URL inválida. Use: /cotizaciones_documentos/{id_cotizacion}', 400);
}
$idCot = (int)$idCot;

/* =====================================================================
 *  GET /cotizaciones_documentos/{id_cotizacion} — Listar
 * ===================================================================== */
if ($method === 'GET' && $idDoc === null) {
    Auth::requirePermission('COTIZACIONES', 'ver');

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT d.id_documento, d.id_cotizacion, d.id_comunicacion,
                    d.nombre_original, d.archivo_key, d.tipo_mime,
                    d.tamano_bytes, d.tipo_documento,
                    CONVERT(VARCHAR(19), d.fecha_registro, 120) AS fecha_registro,
                    p.nombres + ' ' + p.apellido_paterno AS usuario_nombre
             FROM dbo.cotizacion_documento d WITH (NOLOCK)
             LEFT JOIN dbo.persona p ON d.id_usuario = p.id_persona
             WHERE d.id_cotizacion = ?
             ORDER BY d.fecha_registro DESC"
        );
        $stmt->execute([$idCot]);
        $rows = $stmt->fetchAll();

        // Agregar campo "tamano_formato" para UI
        foreach ($rows as &$r) {
            $b = (int)$r['tamano_bytes'];
            $r['tamano_formato'] = $b < 1024
                ? "{$b} B"
                : ($b < 1048576
                    ? number_format($b/1024, 1).' KB'
                    : number_format($b/1048576, 2).' MB');
        }
        ApiResponse::success($rows, 200);
    } catch (Throwable $e) {
        ApiResponse::error('list_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  POST /cotizaciones_documentos/{id_cotizacion} — Subir documento
 * ===================================================================== */
if ($method === 'POST' && $idDoc === null) {
    Auth::requirePermission('COTIZACIONES', 'registrar');

    $maxBytes = 10 * 1024 * 1024; // 10 MB por archivo

    // Aceptar multipart/form-data o JSON con base64
    if (!empty($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['archivo'];
        if ($file['size'] > $maxBytes) {
            ApiResponse::error('file_too_large',
                'El archivo supera el tamaño máximo permitido (10 MB).', 413);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $bin = file_get_contents($file['tmp_name']);
        $nombre = $file['name'];
        $size   = (int)$file['size'];
        $tipoDoc = $_POST['tipo_documento'] ?? 'ADJUNTO_GENERAL';
    } else {
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (empty($body['nombre_original']) || empty($body['base64_contenido'])) {
            ApiResponse::validationError([
                'nombre_original'    => empty($body['nombre_original'])    ? 'Requerido' : null,
                'base64_contenido'   => empty($body['base64_contenido'])   ? 'Requerido' : null,
            ]);
        }

        $bin = base64_decode($body['base64_contenido'], true);
        if ($bin === false) {
            ApiResponse::error('invalid_base64', 'El contenido base64 no es válido.', 422);
        }
        if (strlen($bin) > $maxBytes) {
            ApiResponse::error('file_too_large',
                'El archivo supera el tamaño máximo permitido (10 MB).', 413);
        }

        $nombre  = $body['nombre_original'];
        $size    = strlen($bin);
        $mime    = $body['tipo_mime'] ?? (function($n) {
            $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
            return [
                'pdf'  => 'application/pdf',
                'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'png'  => 'image/png',  'gif'  => 'image/gif',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls'  => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'zip'  => 'application/zip',
            ][$ext] ?? 'application/octet-stream';
        })($nombre);
        $tipoDoc = $body['tipo_documento'] ?? 'ADJUNTO_GENERAL';
    }

    $idUsuario = (int)($_POST['id_usuario'] ?? ($body['id_usuario'] ?? $user['id_persona'] ?? 0));

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "EXEC dbo.sp_cotizacion_documento_save
                @id_cotizacion    = ?,
                @id_usuario       = ?,
                @id_comunicacion  = ?,
                @nombre_original  = ?,
                @tipo_mime        = ?,
                @tamano_bytes     = ?,
                @tipo_documento   = ?,
                @contenido_bin    = ?,
                @id_documento     = ?"
        );

        $idDocumento = null;
        $stmt->bindValue(1, $idCot, PDO::PARAM_INT);
        $stmt->bindValue(2, $idUsuario, PDO::PARAM_INT);
        $stmt->bindValue(3, isset($_POST['id_comunicacion']) ? (int)$_POST['id_comunicacion'] : null, PDO::PARAM_INT);
        $stmt->bindValue(4, $nombre, PDO::PARAM_STR);
        $stmt->bindValue(5, $mime,   PDO::PARAM_STR);
        $stmt->bindValue(6, $size,   PDO::PARAM_INT);
        $stmt->bindValue(7, $tipoDoc, PDO::PARAM_STR);
        $stmt->bindValue(8, $bin,    PDO::PARAM_LOB);
        $stmt->bindParam(9, $idDocumento, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
        $row = $stmt->fetch();

        $idNew = (int)($row['id_documento'] ?? $idDocumento);

        Audit::log('COTIZACIONES', 'DOCUMENTO_UPLOAD',
            'Documento adjuntado a cotización #' . $idCot,
            'cotizacion_documento', (string)$idNew,
            ['nombre' => $nombre, 'size' => $size, 'mime' => $mime]);

        ApiResponse::success($row ?: ['id_documento' => $idNew],
            201, 'Documento adjuntado');
    } catch (Throwable $e) {
        error_log('[Cotizaciones Documentos POST] error: ' . $e->getMessage());
        ApiResponse::error('upload_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  GET /cotizaciones_documentos/{id_cotizacion}/{id_documento} — Descargar
 *  GET /cotizaciones_documentos/{id_cotizacion}/{id_documento}/view — Inline
 * ===================================================================== */
if ($method === 'GET' && $idDoc !== null) {
    Auth::requirePermission('COTIZACIONES', 'ver');
    $idDocumento = (int)$idDoc;
    if ($idDocumento <= 0) ApiResponse::error('invalid_id', 'ID inválido', 400);

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_documento_get @id_documento = ?");
        $stmt->bindValue(1, $idDocumento, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_LAZY);

        if (!$row) {
            ApiResponse::error('not_found', 'Documento no encontrado', 404);
        }

        // Validar que pertenezca a la cotización indicada en la URL
        if ((int)$row['id_cotizacion'] !== $idCot) {
            ApiResponse::error('mismatch',
                'El documento no pertenece a la cotización indicada', 403);
        }

        $bin = $row['contenido'];
        if ($bin === null || strlen((string)$bin) === 0) {
            ApiResponse::error('empty', 'El documento está vacío', 404);
        }

        // Si es SQL Server, $bin puede ser un resource stream; leerlo a string
        if (is_resource($bin)) {
            $bin = stream_get_contents($bin);
        }

        $mime = $row['tipo_mime'];
        $nombre = $row['nombre_original'];
        $size = $row['tamano_bytes'];

        // Inline (ver en navegador) o attachment (descargar)
        $disposition = ($action === 'view') ? 'inline' : 'attachment';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bin));
        header('Content-Disposition: ' . $disposition . '; filename="' . $nombre . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        http_response_code(200);
        echo $bin;
        exit;
    } catch (Throwable $e) {
        ApiResponse::error('download_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  DELETE /cotizaciones_documentos/{id_cotizacion}/{id_documento} — Eliminar
 * ===================================================================== */
if ($method === 'DELETE' && $idDoc !== null) {
    Auth::requirePermission('COTIZACIONES', 'eliminar');
    $idDocumento = (int)$idDoc;

    try {
        $pdo = Database::connection();
        // Verificar pertenencia
        $chk = $pdo->prepare("SELECT id_cotizacion, nombre_original
                              FROM dbo.cotizacion_documento
                              WHERE id_documento = ?");
        $chk->execute([$idDocumento]);
        $row = $chk->fetch();
        if (!$row) ApiResponse::error('not_found', 'Documento no encontrado', 404);
        if ((int)$row['id_cotizacion'] !== $idCot) {
            ApiResponse::error('mismatch', 'El documento no pertenece a la cotización', 403);
        }

        $del = $pdo->prepare("DELETE FROM dbo.cotizacion_documento WHERE id_documento = ?");
        $del->execute([$idDocumento]);

        Audit::log('COTIZACIONES', 'DOCUMENTO_DELETE',
            'Documento eliminado de cotización #' . $idCot,
            'cotizacion_documento', (string)$idDocumento,
            ['nombre' => $row['nombre_original']]);

        ApiResponse::success(['success' => 1], 200, 'Documento eliminado');
    } catch (Throwable $e) {
        ApiResponse::error('delete_failed', $e->getMessage(), 500);
    }
}

ApiResponse::error('not_found',
    "Endpoint no encontrado. Method=$method, id_cot=$idCot, id_doc=$idDoc, action=$action",
    404
);
