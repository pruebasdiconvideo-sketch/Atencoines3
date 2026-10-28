<?php
/**
 * api/routes/motivos.php - Listado de motivos de contacto
 */
Auth::requirePermission('GEOGRAFIA', 'ver');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    ApiResponse::error('method_not_allowed', 'Solo GET soportado', 405);
}

$rows = Database::callSP('sp_motivos_listar', []);
ApiResponse::success($rows, 200);
