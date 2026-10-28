<?php
/**
 * api/routes/tickets.php  —  PARCHE de la sección POST /tickets/{id}/files
 *
 * Ubicación en tu proyecto:
 *   api/routes/tickets.php
 *
 * Qué corregir:
 *   Buscar el bloque que empieza en:
 *       if ($id !== null && $action === 'files' && $method === 'POST' && $fileId === null) {
 *   (es la sección que sube documentos al ticket).
 *
 *   Reemplazar TODO el bloque (desde el `if` hasta su llave de cierre `}`)
 *   por el código siguiente. Solo cambian 3 cosas concretas:
 *     1) bindValue del base64 ahora pasa la longitud explícita (4º arg).
 *        Esto evita que pdo_sqlsrv trunque a 4000 chars (límite por defecto
 *        de NVARCHAR), bug que rompe cualquier archivo > ~3 KB.
 *     2) El parámetro OUTPUT @id_documento se inicializa en 0 (no en null).
 *     3) Se agrega SET NOCOUNT ON implícito en la llamada y se captura
 *        el mensaje SQL real para diagnóstico.
 */

// =================== INICIO BLOQUE A REEMPLAZAR ===================
if ($id !== null && $action === 'files' && $method === 'POST' && $fileId === null) {
    Auth::requirePermission('TICKETS', 'modificar');

    $file = $_FILES['documento'] ?? null;
    if (!$file || !isset($file['error'])) {
        ApiResponse::validationError(['documento' => 'No se recibió el archivo.']);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        ApiResponse::validationError(['documento' => 'Error de subida código ' . $file['error']]);
    }
    if ($file['size'] < 1 || $file['size'] > 100 * 1024 * 1024) {
        ApiResponse::validationError(['documento' => 'Tamaño inválido (1 B – 100 MB).']);
    }

    $originalName = mb_substr(basename(str_replace('\\', '/', (string)$file['name'])), 0, 255);
    $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime         = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    $allowedTypes = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'],
        'txt'  => ['text/plain'], 'csv' => ['text/csv', 'text/plain'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'zip'  => ['application/zip'], 'rar' => ['application/x-rar-compressed'],
        'mp4'  => ['video/mp4'], 'mp3' => ['audio/mpeg'], 'wav' => ['audio/wav'],
        'msg'  => ['application/vnd.ms-outlook'], 'eml' => ['message/rfc822'],
        'html' => ['text/html'], 'json' => ['application/json'], 'xml' => ['application/xml'],
    ];
    if ($originalName === '' || !isset($allowedTypes[$extension]) || !in_array($mime, $allowedTypes[$extension], true)) {
        ApiResponse::validationError(['documento' => 'Formato no permitido.']);
    }

    $pdo = Database::connection();
    $check = $pdo->prepare('SELECT 1 FROM dbo.ticket WHERE id_ticket = ?');
    $check->execute([(int)$id]);
    if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Ticket no encontrado.', 404);

    $idAtencion    = !empty($_POST['id_atencion']) ? (int)$_POST['id_atencion'] : null;
    $tipoDocumento = $_POST['tipo_documento'] ?? 'EVIDENCIA_ADICIONAL';
    $idUsuarioActual = (int)($user['id_persona'] ?? 0);
    if ($idUsuarioActual <= 0) {
        ApiResponse::error('auth_required', 'Su sesión no tiene usuario válido.', 401);
    }

    if ($idAtencion !== null) {
        $stmt = $pdo->prepare("SELECT 1 FROM dbo.atencion WHERE id_atencion = ? AND id_ticket = ?");
        $stmt->execute([$idAtencion, (int)$id]);
        if (!$stmt->fetchColumn()) ApiResponse::validationError(['id_atencion' => 'Atención no válida.']);
    }

    $hasContenidoCol = Database::columnExists('ticket_documento', 'contenido');
    $fileKey   = bin2hex(random_bytes(24));
    $binario   = file_get_contents($file['tmp_name']);
    if ($binario === false) ApiResponse::error('upload_failed', 'No se pudo leer el archivo.', 500);

    try {
        if ($hasContenidoCol) {
            $base64 = base64_encode($binario);

            $stmt = $pdo->prepare("SET NOCOUNT ON;
                EXEC dbo.sp_ticket_documento_save
                    @id_ticket        = :id_ticket,
                    @id_usuario       = :id_usuario,
                    @id_atencion      = :id_atencion,
                    @nombre_original  = :nombre,
                    @archivo_key      = :key,
                    @tipo_mime        = :mime,
                    @tamano_bytes     = :tamano,
                    @tipo_documento   = :tipo,
                    @contenido_bin    = NULL,
                    @base64_contenido = :b64,
                    @id_documento     = :id_out");

            $stmt->bindValue(':id_ticket',  (int)$id, PDO::PARAM_INT);
            $stmt->bindValue(':id_usuario',  $idUsuarioActual, PDO::PARAM_INT);
            $stmt->bindValue(':id_atencion',
                $idAtencion === null ? null : $idAtencion,
                $idAtencion === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':nombre',  $originalName, PDO::PARAM_STR);
            $stmt->bindValue(':key',     $fileKey, PDO::PARAM_STR);
            $stmt->bindValue(':mime',    $mime, PDO::PARAM_STR);
            $stmt->bindValue(':tamano',  (int)$file['size'], PDO::PARAM_INT);
            $stmt->bindValue(':tipo',    $tipoDocumento, PDO::PARAM_STR);

            // ===== FIX #1: longitud explícita =====
            // Sin el 4º argumento, pdo_sqlsrv asume NVARCHAR(4000) y trunca
            // el base64, causando que el SP falle o que se guarde un contenido
            // corrupto. Pasamos strlen($base64) para indicar NVARCHAR(MAX).
            $stmt->bindValue(':b64', $base64, PDO::PARAM_STR, strlen($base64));

            // ===== FIX #2: OUTPUT inicializado en 0 (no null) =====
            $idDocumentoOut = 0;
            $stmt->bindParam(':id_out', $idDocumentoOut,
                              PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 10);

            $stmt->execute();
            $spRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($spRow && !empty($spRow['id_documento'])) {
                $documentId = (int)$spRow['id_documento'];
            } else {
                $documentId = (int)$idDocumentoOut;
            }
            $stmt->closeCursor();
        } else {
            // Fallback filesystem (schema sin columna contenido)
            $path = $documentStorage . '/' . $fileKey;
            if (!move_uploaded_file($file['tmp_name'], $path)) {
                ApiResponse::error('upload_failed', 'No se pudo guardar.', 500);
            }
            $hasIdAtencionCol = Database::columnExists('ticket_documento', 'id_atencion');
            $hasTipoDocCol    = Database::columnExists('ticket_documento', 'tipo_documento');
            if ($hasIdAtencionCol && $hasTipoDocCol) {
                $stmt = $pdo->prepare("INSERT INTO dbo.ticket_documento
                    (id_ticket, id_atencion, nombre_original, archivo_key, tipo_mime, tamano_bytes, id_usuario, tipo_documento)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([(int)$id, $idAtencion, $originalName, $fileKey,
                                $mime, (int)$file['size'], $idUsuarioActual, $tipoDocumento]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO dbo.ticket_documento
                    (id_ticket, nombre_original, archivo_key, tipo_mime, tamano_bytes, id_usuario)
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([(int)$id, $originalName, $fileKey,
                                $mime, (int)$file['size'], $idUsuarioActual]);
            }
            $documentId = (int)$pdo->lastInsertId();
        }

        // Historial (opcional - no rompe si falla)
        try {
            $stmt = $pdo->prepare("INSERT INTO dbo.ticket_historial_estado
                (id_ticket, estado_anterior, estado_nuevo, id_usuario, motivo, fecha_cambio, tipo_evento, id_atencion, origen_evento)
                VALUES (?, (SELECT estado FROM dbo.ticket WHERE id_ticket = ?),
                        (SELECT estado FROM dbo.ticket WHERE id_ticket = ?),
                        ?, ?, SYSDATETIME(), 'ADJUNTO', ?, 'MANUAL')");
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->bindValue(2, (int)$id, PDO::PARAM_INT);
            $stmt->bindValue(3, (int)$id, PDO::PARAM_INT);
            $stmt->bindValue(4, $idUsuarioActual, PDO::PARAM_INT);
            $stmt->bindValue(5, 'Documento adjuntado: ' . $originalName, PDO::PARAM_STR);
            $stmt->bindValue(6, $idAtencion,
                             $idAtencion === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();
        } catch (Throwable $histErr) {
            error_log('[Tickets files POST] Historial omitido: ' . $histErr->getMessage());
        }
    } catch (Throwable $e) {
        if (isset($path) && is_file($path)) @unlink($path);
        error_log('[Tickets files POST] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        ApiResponse::error('upload_failed', 'No se pudo guardar el documento: ' . $e->getMessage(), 422);
    }

    ApiResponse::success(['id_documento' => $documentId], 201, 'Documento adjuntado.');
}
// =================== FIN BLOQUE A REEMPLAZAR ===================
