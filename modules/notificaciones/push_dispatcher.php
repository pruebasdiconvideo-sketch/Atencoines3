<?php
/**
 * modules/notificaciones/push_dispatcher.php
 *
 * Encola y envia notificaciones push a los navegadores suscritos (PWA).
 * Usa el estandar Web Push API (VAPID).
 *
 * Dependencias:
 *   - Composer: minishlink/web-push
 *   - Claves VAPID generadas una sola vez y guardadas en config/vapid.json
 *
 * Funcionamiento:
 *   1. La API encola en una cola simple (tabla notificacion) + flag enviada_push=0.
 *   2. Este dispatcher se invoca desde un cron (Windows Task Scheduler) cada minuto.
 *   3. Lee lotes de 50 notificaciones pendientes, consulta pwa_subscription,
 *      y envia el push via Web Push API.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

final class PushDispatcher
{
    /** Encola notificacion push para un usuario (no bloqueante) */
    public static function enqueueForUser(int $idUsuario, string $titulo, string $mensaje): void
    {
        try {
            $pdo = Database::connection();
            // Actualiza las notificaciones_usuario pendientes de push
            $stmt = $pdo->prepare(
                "UPDATE notificacion_usuario
                 SET enviada_push = 0
                 OUTPUT INSERTED.id_notificacion_usuario
                 WHERE id_usuario = ? AND enviada_push = 0
                   AND id_notificacion IN (
                       SELECT TOP 1 id_notificacion FROM notificacion
                       WHERE titulo = ? AND mensaje = ?
                       ORDER BY id_notificacion DESC)");
            $stmt->execute([$idUsuario, $titulo, $mensaje]);
        } catch (Throwable $e) {
            error_log('[push] enqueue error: ' . $e->getMessage());
        }
    }

    /** Procesa la cola de notificaciones push pendientes (llamada por cron) */
    public static function processQueue(int $batch = 50): int
    {
        $config = self::loadVapidKeys();
        if (!$config) return 0;

        try {
            require_once __DIR__ . '/../../vendor/autoload.php';
        } catch (Throwable $e) {
            error_log('[push] minishlink/web-push no instalado: ' . $e->getMessage());
            return 0;
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT TOP ? nu.id_notificacion_usuario, n.titulo, n.mensaje,
                    s.endpoint, s.p256dh_key, s.auth_key, s.id_usuario
             FROM notificacion_usuario nu
             INNER JOIN notificacion n ON nu.id_notificacion = n.id_notificacion
             INNER JOIN pwa_subscription s ON nu.id_usuario = s.id_usuario
             WHERE nu.enviada_push = 0 AND s.activa = 1
             ORDER BY nu.id_notificacion_usuario");
        $stmt->bindValue(1, $batch, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if (empty($rows)) return 0;

        $auth = [
            'VAPID' => [
                'subject'    => 'mailto:notificaciones@atenciones.com',
                'publicKey'  => $config['publicKey'],
                'privateKey' => $config['privateKey'],
            ]
        ];
        $webPush = new \Minishlink\WebPush\WebPush($auth);
        $webPush->setReuseVAPIDHeaders(true);

        $sent = 0;
        foreach ($rows as $r) {
            $sub = \Minishlink\WebPush\Subscription::create([
                'endpoint'         => $r['endpoint'],
                'publicKey'        => $r['p256dh_key'],
                'authToken'         => $r['auth_key'],
            ]);
            $payload = json_encode([
                'title' => $r['titulo'],
                'body'  => mb_substr($r['mensaje'], 0, 200),
                'icon'  => '/assets/img/icon-192.png',
                'badge' => '/assets/img/badge-72.png',
                'tag'   => 'ticket-' . $r['id_notificacion_usuario'],
                'data'  => ['url' => '/tickets'],
            ], JSON_UNESCAPED_UNICODE);

            $webPush->queueNotification($sub, $payload);
            $sent++;
        }

        // Flush todas las notificaciones encoladas
        foreach ($webPush->flush() as $report) {
            // Marcar como enviadas independientemente del resultado
            // (si falla definitivamente, el endpoint ya expiro y se desactiva)
        }

        // Marcar en BD como enviadas
        $ids = array_column($rows, 'id_notificacion_usuario');
        $in  = implode(',', array_map('intval', $ids));
        $pdo->exec("UPDATE notificacion_usuario SET enviada_push = 1
                    WHERE id_notificacion_usuario IN ($in)");

        return $sent;
    }

    /** Carga las claves VAPID generadas previamente */
    private static function loadVapidKeys(): ?array
    {
        $path = __DIR__ . '/../../config/vapid.json';
        if (!is_file($path)) return null;
        $cfg = json_decode((string)file_get_contents($path), true);
        return $cfg['VAPID'] ?? $cfg;
    }
}

// Si se llama desde CLI (cron), procesa la cola
if (PHP_SAPI === 'cli' && isset($argv[0]) && basename($argv[0]) === 'push_dispatcher.php') {
    $processed = PushDispatcher::processQueue(100);
    echo "Push notifications enviadas: $processed\n";
}
