<?php
/**
 * api/routes/cotizaciones_config_logo.php
 * ----------------------------------------------------------------------------
 * Endpoints para gestión del LOGO desde el módulo de Configuraciones.
 *
 * Endpoints:
 *   GET    /cotizaciones_config_logo                Listar emisoras con estado de logo
 *   GET    /cotizaciones_config_logo/{id_emisora}   Detalle de la emisora + logo
 *   POST   /cotizaciones_config_logo/{id_emisora}   Subir/reemplazar logo (JSON base64)
 *   DELETE /cotizaciones_config_logo/{id_emisora}   Eliminar logo
 *
 * Requiere permiso COTIZACIONES:modificar o ADMIN:modificar.
 */

Auth::requireAnyPermission([
    ['COTIZACIONES', 'ver'],
    ['SEGURIDAD', 'ver'],
]);

$method = $_SERVER['REQUEST_METHOD'];
$idEmi  = isset($segments[1]) ? (int)$segments[1] : null;

if ($idEmi !== null && $idEmi <= 0) {
    ApiResponse::error('invalid_id', 'ID de emisora inválido', 400);
}

try {
    $pdo = Database::connection();

    /* ====================================================================
     * GET /cotizaciones_config_logo — Listar emisoras con estado de logo
     * ====================================================================*/
    if ($method === 'GET' && $idEmi === null) {
        $stmt = $pdo->prepare(
            "SELECT id_emisora, razon_social, nombre_comercial, ruc,
                    CASE WHEN logo_base64 IS NULL THEN 0 ELSE 1 END AS tiene_logo,
                    activo, es_principal,
                    CONVERT(VARCHAR(19), fecha_modificacion, 120) AS fecha_modificacion
             FROM dbo.cotizacion_empresa_emisora
             WHERE activo = 1
             ORDER BY es_principal DESC, razon_social"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        ApiResponse::success($rows, 200);
    }

    /* ====================================================================
     * GET /cotizaciones_config_logo/{id} — Detalle + logo
     * ====================================================================*/
    if ($method === 'GET' && $idEmi !== null) {
        $stmt = $pdo->prepare(
            "SELECT id_emisora, razon_social, nombre_comercial, ruc,
                    direccion, telefono, correo, web, logo_base64,
                    CASE WHEN logo_base64 IS NULL THEN 0 ELSE 1 END AS tiene_logo,
                    activo, es_principal,
                    CONVERT(VARCHAR(19), fecha_modificacion, 120) AS fecha_modificacion
             FROM dbo.cotizacion_empresa_emisora
             WHERE id_emisora = ?"
        );
        $stmt->bindValue(1, $idEmi, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) ApiResponse::error('not_found', 'Emisora no encontrada', 404);

        ApiResponse::success($row, 200);
    }

    /* ====================================================================
     * POST /cotizaciones_config_logo/{id} — Subir/reemplazar logo
     *
     * Body (JSON):
     *   { "logo_base64": "data:image/png;base64,XXXX..." }
     *   o
     *   { "logo_base64": "<solo-base64-sin-prefijo>", "mime": "image/png" }
     *
     * También soporta multipart/form-data con campo "logo" (file).
     * ====================================================================*/
    if ($method === 'POST' && $idEmi !== null) {
        Auth::requirePermission('COTIZACIONES', 'modificar');

        /* Verificar que la emisora existe */
        $stmt = $pdo->prepare("SELECT id_emisora FROM dbo.cotizacion_empresa_emisora WHERE id_emisora = ?");
        $stmt->bindValue(1, $idEmi, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetch()) {
            ApiResponse::error('not_found', 'Emisora no encontrada', 404);
        }

        $logoDataUrl = null;

        /* Caso 1: multipart/form-data con campo "logo" */
        if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo'];

            /* Validar tamaño (máx 2 MB) */
            if ($file['size'] > 2 * 1024 * 1024) {
                ApiResponse::error('logo_too_large',
                    'El archivo excede el máximo permitido de 2 MB', 413);
            }

            /* Validar tipo MIME */
            $allowedMimes = ['image/png','image/jpeg','image/jpg','image/svg+xml','image/gif','image/webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!in_array($mime, $allowedMimes, true)) {
                ApiResponse::error('logo_invalid_type',
                    'Tipo de archivo no permitido: ' . $mime . '. Use PNG, JPG, SVG, GIF o WebP.', 415);
            }

            $content = file_get_contents($file['tmp_name']);
            $logoDataUrl = 'data:' . $mime . ';base64,' . base64_encode($content);

        } else {
            /* Caso 2: JSON con base64 */
            $body = json_decode(file_get_contents('php://input'), true) ?: [];

            if (empty($body['logo_base64'])) {
                ApiResponse::validationError(['logo_base64' => 'Requerido (o suba un archivo con campo "logo")']);
            }

            $raw = trim($body['logo_base64']);

            /* Si viene con prefijo data:, validar mime */
            if (strpos($raw, 'data:') === 0) {
                if (!preg_match('#^data:image/(png|jpe?g|svg\+xml|gif|webp);base64,#i', $raw)) {
                    ApiResponse::validationError(['logo_base64' => 'El formato no es una imagen válida (PNG, JPG, SVG, GIF, WebP)']);
                }
                $logoDataUrl = $raw;
            } else {
                /* Sin prefijo — añadirlo con el mime indicado o PNG por defecto */
                $mime = !empty($body['mime']) ? $body['mime'] : 'image/png';
                if (!in_array($mime, ['image/png','image/jpeg','image/jpg','image/svg+xml','image/gif','image/webp'], true)) {
                    ApiResponse::validationError(['mime' => 'MIME no permitido']);
                }
                $logoDataUrl = 'data:' . $mime . ';base64,' . $raw;
            }

            /* Validar tamaño total del base64 (≈ 2.7M chars = 2 MB) */
            if (strlen($logoDataUrl) > 3000000) {
                ApiResponse::error('logo_too_large',
                    'El logo excede el máximo permitido de 2 MB', 413);
            }
        }

        /* Guardar en BD */
        $stmt = $pdo->prepare(
            "EXEC dbo.sp_cotizacion_emisora_logo_save
                @id_emisora = ?, @logo_base64 = ?, @id_usuario = ?"
        );
        $stmt->bindValue(1, $idEmi, PDO::PARAM_INT);
        $stmt->bindValue(2, $logoDataUrl, PDO::PARAM_STR);
        $stmt->bindValue(3, $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        Audit::log('COTIZACIONES', 'LOGO_UPDATE',
            'Logo actualizado para emisora ' . $idEmi,
            'cotizacion_empresa_emisora', (string)$idEmi, []);

        ApiResponse::success([
            'id_emisora'  => $idEmi,
            'tiene_logo'  => 1,
            'success'     => (int)($row['success'] ?? 1) === 1,
        ], 200, 'Logo actualizado correctamente');
    }

    /* ====================================================================
     * DELETE /cotizaciones_config_logo/{id} — Eliminar logo
     * ====================================================================*/
    if ($method === 'DELETE' && $idEmi !== null) {
        Auth::requirePermission('COTIZACIONES', 'modificar');

        $stmt = $pdo->prepare(
            "UPDATE dbo.cotizacion_empresa_emisora
             SET logo_base64 = NULL,
                 fecha_modificacion = SYSDATETIME(),
                 id_usuario_modificacion = ?
             WHERE id_emisora = ?"
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $idEmi, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            ApiResponse::error('not_found', 'Emisora no encontrada', 404);
        }

        Audit::log('COTIZACIONES', 'LOGO_DELETE',
            'Logo eliminado de emisora ' . $idEmi,
            'cotizacion_empresa_emisora', (string)$idEmi, []);

        ApiResponse::success(['id_emisora' => $idEmi, 'tiene_logo' => 0], 200, 'Logo eliminado');
    }

    ApiResponse::error('not_found', 'Endpoint no encontrado', 404);

} catch (Throwable $e) {
    error_log('[cotizaciones_config_logo] Error: ' . $e->getMessage());
    ApiResponse::error('config_logo_failed', $e->getMessage(), 500);
}
