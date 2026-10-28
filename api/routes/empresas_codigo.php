<?php
/**
 * api/routes/empresas_codigo.php
 * ----------------------------------------------------------------------------
 * Endpoints NUEVOS para gestionar el `codigo_empresa` (campo agregado en
 * el script SQL 02) — usado para generar códigos de cotización tipo
 * SERV-2026-001.1.
 *
 * Este archivo debe ser incluido DESPUÉS de cargar api/routes/empresas.php
 * para añadir los endpoints sin modificar el archivo original.
 *
 * Endpoints:
 *   PATCH  /empresas/{id}/codigo_empresa      Asignar código identificador
 *   GET    /empresas/{id}/codigo_empresa      Consultar código asignado
 *
 * SPs usados (creados en script SQL 02):
 *   - sp_empresa_set_codigo
 */

if (!isset($userId) || $userId <= 0) {
    return;
}

$method = $_SERVER['REQUEST_METHOD'];

/* ============================================================================
 * PATCH /empresas/{id}/codigo_empresa
 * Body: { "codigo_empresa": "SERV" }
 * ==========================================================================*/
if ($method === 'PATCH' && is_numeric($id) && $action === 'codigo_empresa') {
    Auth::requirePermission('EMPRESAS', 'modificar');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['codigo_empresa'])) {
        ApiResponse::validationError(['codigo_empresa' => 'Requerido']);
    }

    $codigo = strtoupper(trim($body['codigo_empresa']));

    /* Validación de formato en backend */
    if (!preg_match('/^[A-Z0-9]{2,10}$/', $codigo)) {
        ApiResponse::validationError([
            'codigo_empresa' => 'Debe tener entre 2 y 10 caracteres alfanuméricos (A-Z, 0-9)'
        ]);
    }

    try {
        $pdo = Database::connection();

        /* Verificar que el SP existe */
        $chk = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_empresa_set_codigo','P') IS NOT NULL THEN 1 ELSE 0 END");
        if (!(int)$chk->fetchColumn()) {
            ApiResponse::error('sp_no_existe',
                'El procedimiento sp_empresa_set_codigo no existe. Ejecute el script SQL 02.',
                503);
        }

        $stmt = $pdo->prepare("EXEC dbo.sp_empresa_set_codigo
                                 @id_empresa = ?, @codigo_empresa = ?, @id_usuario = ?");
        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(2, $codigo, PDO::PARAM_STR);
        $stmt->bindValue(3, $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        Audit::log('EMPRESAS', 'CODIGO_EMPRESA_SET',
            'Código identificador ' . $codigo . ' asignado a empresa ' . $id,
            'empresa', (string)$id, ['codigo_empresa' => $codigo]);

        ApiResponse::success([
            'id_empresa'     => (int)$id,
            'codigo_empresa' => $codigo,
            'success'        => (int)($row['success'] ?? 1) === 1,
        ], 200, 'Código de empresa actualizado');
    } catch (Throwable $e) {
        ApiResponse::error('codigo_empresa_failed', $e->getMessage(), 422);
    }
}

/* ============================================================================
 * GET /empresas/{id}/codigo_empresa
 * ==========================================================================*/
if ($method === 'GET' && is_numeric($id) && $action === 'codigo_empresa') {
    Auth::requirePermission('EMPRESAS', 'ver');

    try {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare("SELECT id_empresa, razon_social, codigo_empresa
                               FROM dbo.empresa
                               WHERE id_empresa = ?");
        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) ApiResponse::error('not_found', 'Empresa no encontrada', 404);

        ApiResponse::success([
            'id_empresa'     => (int)$row['id_empresa'],
            'razon_social'   => $row['razon_social'],
            'codigo_empresa' => $row['codigo_empresa'],
            'tiene_codigo'   => !empty($row['codigo_empresa']),
        ], 200);
    } catch (Throwable $e) {
        ApiResponse::error('codigo_empresa_get_failed', $e->getMessage(), 500);
    }
}
