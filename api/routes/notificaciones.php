<?php
/**
 * api/routes/notificaciones.php
 *
 * Endpoints de notificaciones del sistema.
 *   GET  /notificaciones                    - Lista mis notificaciones (50 ultimas)
 *   GET  /notificaciones/unread-count       - Cantidad de no leidas
 *   POST /notificaciones/{id}/read          - Marca como leida
 *   POST /notificaciones/disparar            - Dispara una notificacion (permisos especiales)
 *   POST /notificaciones/push/subscribe     - Suscribe el navegador para Web Push (PWA)
 */

Auth::requirePermission('NOTIFICACIONES', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

/* ---- GET /notificaciones ---- */
if ($method === 'GET' && $id === null) {
    $soloNoLeidas = isset($_GET['unread']) ? 1 : 0;
    $rows = Database::callSP('sp_notificacion_listar', [
        $user['id_persona'], $soloNoLeidas, (int)($_GET['limit'] ?? 50)
    ]);
    ApiResponse::success($rows, 200);
}

/* ---- GET /notificaciones/unread-count ----
 *  OPTIMIZACIÓN 5000 usuarios: esta ruta se invita cada 90s por CADA usuario
 *  logueado. Con 5000 usuarios => ~55 req/s. Para reducir la carga del servidor:
 *    1. NOLOCK en la consulta (evita bloqueos de escritura).
 *    2. Cache-Control: private, max-age=30 — el navegador no volverá a pedirlo
 *       en menos de 30s incluso si el usuario abre varias pestañas.
 *    3. Query simplificada (sin JOINs).
 */
if ($method === 'GET' && $id === 'unread-count') {
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM notificacion_usuario WITH (NOLOCK)
         WHERE id_usuario = ? AND leida = 0");
    $stmt->execute([$user['id_persona']]);
    // Cabecera de caché privada de 30s: aligera la carga en pestañas múltiples.
    header('Cache-Control: private, max-age=30');
    ApiResponse::success(['total' => (int)$stmt->fetchColumn()], 200);
}

/* ---- POST /notificaciones/{id}/read ---- */
if ($method === 'POST' && $action === 'read') {
    Database::callSP('sp_notificacion_marcar_leida', [(int)$id, $user['id_persona']]);
    ApiResponse::success(null, 200, 'Notificacion marcada como leida');
}

/* ---- POST /notificaciones/disparar ---- */
if ($method === 'POST' && $id === 'disparar') {
    Auth::requirePermission('NOTIFICACIONES', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['codigo_plantilla']) || empty($body['id_usuarios'])) {
        ApiResponse::validationError([
            'codigo_plantilla' => 'Requerido',
            'id_usuarios'       => 'Array JSON requerido'
        ]);
    }
    $usuarios = is_array($body['id_usuarios']) ? json_encode($body['id_usuarios']) : $body['id_usuarios'];
    $valores  = isset($body['valores']) ? json_encode($body['valores']) : null;

    try {
        $pdo  = Database::connection();
        $stmt = $pdo->prepare("EXEC sp_notificacion_enviar ?, ?, ?, ?, ?, ?, ?");
        $stmt->bindValue(1, $body['codigo_plantilla'], PDO::PARAM_STR);
        $stmt->bindValue(2, $usuarios, PDO::PARAM_STR);
        $stmt->bindValue(3, $valores, PDO::PARAM_STR);
        $stmt->bindValue(4, $body['id_referencia'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(5, $body['modulo'] ?? null, PDO::PARAM_STR);
        $idNotif = null;
        $stmt->bindParam(6, $idNotif, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
        $row = $stmt->fetch();

        // Push asincrono a suscriptores PWA del destinatario
        require_once __DIR__ . '/../../modules/notificaciones/push_dispatcher.php';
        foreach (($body['id_usuarios'] ?? []) as $idUsuario) {
            PushDispatcher::enqueueForUser((int)$idUsuario, $row['titulo'] ?? '', $row['mensaje'] ?? '');
        }

        ApiResponse::success(['id_notificacion' => (int)($row['id_notificacion'] ?? $idNotif)], 201, 'Notificacion enviada');
    } catch (Throwable $e) {
        ApiResponse::error('notification_failed', $e->getMessage(), 500);
    }
}

/* ---- POST /notificaciones/push/subscribe ---- */
if ($method === 'POST' && $id === 'push' && $action === 'subscribe') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['endpoint','p256dh_key','auth_key'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        "IF EXISTS (SELECT 1 FROM pwa_subscription WHERE endpoint = ?)
            UPDATE pwa_subscription SET activa = 1, fecha_alta = GETDATE(),
                   p256dh_key = ?, auth_key = ?, user_agent = ?
             WHERE endpoint = ?;
         ELSE
            INSERT INTO pwa_subscription (id_usuario, endpoint, p256dh_key, auth_key, user_agent)
            VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $body['endpoint'], $body['p256dh_key'], $body['auth_key'], substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        $body['endpoint'],
        $user['id_persona'], $body['endpoint'], $body['p256dh_key'], $body['auth_key'],
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
    ApiResponse::success(null, 201, 'Suscripcion push registrada');
}

ApiResponse::error('not_found', 'Sub-recurso de notificaciones no encontrado.', 404);
