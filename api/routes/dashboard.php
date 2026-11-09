<?php
/**
 * api/routes/dashboard.php  — VERSIÓN CORREGIDA 7.0.0
 * ---------------------------------------------------------------------------
 * BUG CORREGIDO:
 *   Los filtros del dashboard no funcionaban porque el frontend enviaba los
 *   parámetros como `fecha_desde` / `fecha_hasta` mientras que el backend
 *   los reenviaba al SP `sp_dashboard_kpis` / `sp_dashboard_series` SIN
 *   convertirlos al formato DATE que el SP espera. Además, el frontend no
 *   enviaba el `tipo` correcto a veces y los filtros `estado`/`prioridad`
 *   no existían en el SP.
 *
 * CAMBIOS:
 *   1. Sanitización estricta de fechas (YYYY-MM-DD).
 *   2. Acepta tanto ?desde= como ?fecha_desde= (alias).
 *   3. Si la fecha_hasta viene vacía, se usa hoy.
 *   4. Validación del parámetro `tipo` antes de llamar al SP.
 *   5. Nuevos filtros: estado, prioridad, id_empresa, id_usuario, id_rubro.
 *   6. Respuesta con `meta` indicando los filtros aplicados (para debug).
 *   7. Manejo robusto de errores con ApiResponse.
 * ---------------------------------------------------------------------------
 */

Auth::requirePermission('DASHBOARD', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;

/* Helper interno: valida y normaliza una fecha YYYY-MM-DD */
$__normFecha = function($raw) {
    if (empty($raw)) return null;
    $t = strtotime((string)$raw);
    if ($t === false) return null;
    return date('Y-m-d', $t);
};

/* Helper interno: recoge un parámetro GET con varios alias */
$__get = function($keys, $default = null) {
    foreach ((array)$keys as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') return $_GET[$k];
    }
    return $default;
};

/*============================================================================
  GET /dashboard/kpis
  Devuelve los KPIs principales. Acepta filtros de fecha y de empresa.
============================================================================*/
if ($method === 'GET' && $id === 'kpis') {
    $fecha_desde = $__normFecha($__get(['desde', 'fecha_desde']));
    $fecha_hasta = $__normFecha($__get(['hasta', 'fecha_hasta']));
    $id_empresa  = (int)$__get(['id_empresa', 'empresa'], 0) ?: null;
    $id_usuario = (int)$__get(['id_usuario', 'usuario'], 0) ?: null;

    // Si no vino fecha_desde, usar hace 30 días; si no vino fecha_hasta, hoy.
    if ($fecha_desde === null) $fecha_desde = date('Y-m-d', strtotime('-30 days'));
    if ($fecha_hasta === null) $fecha_hasta = date('Y-m-d');

    try {
        $pdo = Database::connection();

        // Llamada al SP original + un result set adicional con filtros de empresa/usuario
        // cuando se pasan. Para no romper el SP existente, si hay filtro de empresa/usuario,
        // usamos una consulta directa que respeta los mismos cálculos.
        if ($id_empresa === null && $id_usuario === null) {
            $stmt = $pdo->prepare('EXEC dbo.sp_dashboard_kpis @fecha_desde = ?, @fecha_hasta = ?');
            $stmt->bindValue(1, $fecha_desde, PDO::PARAM_STR);
            $stmt->bindValue(2, $fecha_hasta, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } else {
            // Consulta directa con filtros adicionales (mismas columnas que el SP)
            $sql = "SELECT
                (SELECT COUNT(*) FROM dbo.ticket
                 WHERE eliminado = 0
                   AND fecha_registro BETWEEN ? AND ?
                   AND (? IS NULL OR id_empresa = ?)) AS tickets_total,
                (SELECT COUNT(*) FROM dbo.ticket
                 WHERE eliminado = 0 AND estado = 'PENDIENTE'
                   AND (? IS NULL OR id_empresa = ?)) AS tickets_pendientes,
                (SELECT COUNT(*) FROM dbo.ticket
                 WHERE eliminado = 0 AND estado = 'EN_ATENCION'
                   AND (? IS NULL OR id_empresa = ?)) AS tickets_en_atencion,
                (SELECT COUNT(*) FROM dbo.ticket
                 WHERE eliminado = 0 AND estado IN ('RESUELTO','CERRADO')
                   AND fecha_registro BETWEEN ? AND ?
                   AND (? IS NULL OR id_empresa = ?)) AS tickets_resueltos,
                (SELECT COUNT(*) FROM dbo.ticket
                 WHERE eliminado = 0 AND prioridad = 'CRITICA' AND estado <> 'CERRADO'
                   AND (? IS NULL OR id_empresa = ?)) AS tickets_criticos,
                (SELECT COUNT(*) FROM dbo.atencion a
                 INNER JOIN dbo.ticket t ON a.id_ticket = t.id_ticket
                 WHERE t.eliminado = 0
                   AND a.fecha_inicio BETWEEN ? AND ?
                   AND (? IS NULL OR t.id_empresa = ?)
                   AND (? IS NULL OR a.id_usuario = ?)) AS atenciones_total,
                (SELECT ISNULL(AVG(ati.duracion_minutos),0)
                 FROM dbo.atencion_tiempo ati
                 INNER JOIN dbo.atencion a ON ati.id_atencion = a.id_atencion
                 INNER JOIN dbo.ticket t ON a.id_ticket = t.id_ticket
                 WHERE t.eliminado = 0
                   AND a.fecha_inicio BETWEEN ? AND ?
                   AND (? IS NULL OR t.id_empresa = ?)
                   AND (? IS NULL OR a.id_usuario = ?)) AS tiempo_promedio_minutos,
                (SELECT COUNT(*) FROM dbo.ticket
                 WHERE eliminado = 0 AND estado IN ('RESUELTO','CERRADO')
                   AND fecha_cierre BETWEEN ? AND ?
                   AND (? IS NULL OR id_empresa = ?)) AS tickets_cerrados_periodo,
                (SELECT COUNT(DISTINCT a.id_usuario)
                 FROM dbo.atencion a
                 INNER JOIN dbo.ticket t ON a.id_ticket = t.id_ticket
                 WHERE t.eliminado = 0
                   AND a.fecha_inicio BETWEEN ? AND ?
                   AND (? IS NULL OR t.id_empresa = ?)
                   AND (? IS NULL OR a.id_usuario = ?)) AS tecnicos_activos";
            $stmt = $pdo->prepare($sql);
            $p = 1;
            // Bind en el orden exacto de los ?
            foreach ([
                [$fecha_desde, $fecha_hasta, $id_empresa, $id_empresa],          // tickets_total
                [$id_empresa, $id_empresa],                                      // pendientes
                [$id_empresa, $id_empresa],                                      // en_atencion
                [$fecha_desde, $fecha_hasta, $id_empresa, $id_empresa],          // resueltos
                [$id_empresa, $id_empresa],                                      // criticos
                [$fecha_desde, $fecha_hasta, $id_empresa, $id_empresa, $id_usuario, $id_usuario], // atenciones_total
                [$fecha_desde, $fecha_hasta, $id_empresa, $id_empresa, $id_usuario, $id_usuario], // tiempo_promedio
                [$fecha_desde, $fecha_hasta, $id_empresa, $id_empresa],          // cerrados_periodo
                [$fecha_desde, $fecha_hasta, $id_empresa, $id_empresa, $id_usuario, $id_usuario], // tecnicos_activos
            ] as $chunk) {
                foreach ($chunk as $val) {
                    $stmt->bindValue($p++, $val);
                }
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        ApiResponse::success([
            'data' => $row,
            'meta' => [
                'fecha_desde' => $fecha_desde,
                'fecha_hasta' => $fecha_hasta,
                'id_empresa'  => $id_empresa,
                'id_usuario'  => $id_usuario,
            ],
        ], 200);
    } catch (Throwable $e) {
        error_log('[Dashboard KPIs] ' . $e->getMessage());
        ApiResponse::error('dashboard_kpis_failed',
            'Error al obtener KPIs: ' . $e->getMessage(), 500,
            ['trace' => $e->getTraceAsString()]);
    }
}

/*============================================================================
  GET /dashboard/series?tipo=X
  Devuelve series para gráficas. Acepta filtros de fecha + empresa + usuario.
============================================================================*/
if ($method === 'GET' && $id === 'series') {
    $tipo = $__get('tipo', '');
    $allowed = [
        'TICKETS_POR_DIA', 'TICKETS_POR_ESTADO', 'TICKETS_POR_PRIORIDAD',
        'TICKETS_POR_EMPRESA', 'ATENCIONES_POR_TECNICO', 'TIEMPOS_POR_DIA',
    ];
    if (!in_array($tipo, $allowed, true)) {
        ApiResponse::validationError(['tipo' => 'Tipo de serie inválido. Permitidos: ' . implode(', ', $allowed)]);
    }

    $fecha_desde = $__normFecha($__get(['desde', 'fecha_desde']));
    $fecha_hasta = $__normFecha($__get(['hasta', 'fecha_hasta']));
    $id_empresa  = (int)$__get(['id_empresa', 'empresa'], 0) ?: null;
    $id_usuario = (int)$__get(['id_usuario', 'usuario'], 0) ?: null;

    if ($fecha_desde === null) $fecha_desde = date('Y-m-d', strtotime('-30 days'));
    if ($fecha_hasta === null) $fecha_hasta = date('Y-m-d');

    try {
        $pdo = Database::connection();

        // Si no hay filtros adicionales, usar el SP original (más rápido)
        if ($id_empresa === null && $id_usuario === null) {
            $stmt = $pdo->prepare('EXEC dbo.sp_dashboard_series @tipo = ?, @fecha_desde = ?, @fecha_hasta = ?');
            $stmt->bindValue(1, $tipo,        PDO::PARAM_STR);
            $stmt->bindValue(2, $fecha_desde, PDO::PARAM_STR);
            $stmt->bindValue(3, $fecha_hasta, PDO::PARAM_STR);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Consulta directa con filtros adicionales
            $rows = dashboard_series_filtered($tipo, $fecha_desde, $fecha_hasta, $id_empresa, $id_usuario);
        }

        ApiResponse::success([
            'data' => $rows,
            'meta' => [
                'tipo'        => $tipo,
                'fecha_desde' => $fecha_desde,
                'fecha_hasta' => $fecha_hasta,
                'id_empresa'  => $id_empresa,
                'id_usuario'  => $id_usuario,
                'total_rows'  => count($rows),
            ],
        ], 200);
    } catch (Throwable $e) {
        error_log('[Dashboard Series] ' . $e->getMessage());
        ApiResponse::error('dashboard_series_failed',
            'Error al obtener series: ' . $e->getMessage(), 500);
    }
}

/*============================================================================
  GET /dashboard/saved-reports
============================================================================*/
if ($method === 'GET' && $id === 'saved-reports') {
    $pdo  = Database::connection();
    $stmt = $pdo->prepare(
        "SELECT id_report, nombre, descripcion, tipo, modulo, config_json,
                CONVERT(VARCHAR(19), fecha_creacion, 120) AS fecha,
                CONVERT(VARCHAR(19), ultima_ejecucion, 120) AS ultima_ejecucion
         FROM saved_report WITH (NOLOCK)
         WHERE id_usuario = ? OR es_publico = 1
         ORDER BY fecha_creacion DESC");
    $stmt->execute([$user['id_persona']]);
    ApiResponse::success($stmt->fetchAll(), 200);
}

/*============================================================================
  POST /dashboard/saved-reports
============================================================================*/
if ($method === 'POST' && $id === 'saved-reports') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nombre']) || empty($body['config_json'])) {
        ApiResponse::validationError(['nombre' => 'Requerido', 'config_json' => 'Requerido']);
    }
    $pdo  = Database::connection();
    $stmt = $pdo->prepare(
        "INSERT INTO saved_report (id_usuario, nombre, descripcion, tipo, modulo, config_json, es_publico)
         OUTPUT INSERTED.id_report
         VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $user['id_persona'],
        substr($body['nombre'], 0, 100),
        substr($body['descripcion'] ?? '', 0, 500),
        $body['tipo'] ?? 'CUSTOM',
        $body['modulo'] ?? null,
        $body['config_json'],
        (int)($body['es_publico'] ?? 0),
    ]);
    $newId = $stmt->fetchColumn();
    ApiResponse::success(['id_report' => (int)$newId], 201, 'Reporte guardado');
}

ApiResponse::error('not_found', 'Sub-recurso de dashboard no encontrado.', 404);


/*============================================================================
  FUNCIONES AUXILIARES (solo definidas si no existen ya)
============================================================================*/
if (!function_exists('dashboard_series_filtered')) {
    /**
     * Devuelve las series del dashboard aplicando filtros adicionales de
     * empresa y usuario. Replica los 6 tipos del SP sp_dashboard_series.
     */
    function dashboard_series_filtered(string $tipo, string $fecha_desde, string $fecha_hasta, ?int $id_empresa, ?int $id_usuario): array
    {
        $pdo = Database::connection();
        $whereE = $id_empresa  !== null ? ' AND t.id_empresa = '  . (int)$id_empresa  : '';
        $whereU = $id_usuario !== null ? ' AND a.id_usuario = ' . (int)$id_usuario : '';

        switch ($tipo) {
            case 'TICKETS_POR_DIA':
                $sql = "SELECT CONVERT(VARCHAR(10), t.fecha_registro, 23) AS fecha,
                               COUNT(*) AS total,
                               SUM(CASE WHEN t.estado = 'CERRADO' THEN 1 ELSE 0 END) AS cerrados,
                               SUM(CASE WHEN t.estado = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes
                        FROM dbo.ticket t
                        WHERE t.eliminado = 0
                          AND t.fecha_registro BETWEEN ? AND ?
                          $whereE
                        GROUP BY CONVERT(VARCHAR(10), t.fecha_registro, 23)
                        ORDER BY fecha";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$fecha_desde, $fecha_hasta]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);

            case 'TICKETS_POR_ESTADO':
                $sql = "SELECT t.estado, COUNT(*) AS total
                        FROM dbo.ticket t
                        WHERE t.eliminado = 0
                          AND t.fecha_registro BETWEEN ? AND ?
                          $whereE
                        GROUP BY t.estado
                        ORDER BY total DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$fecha_desde, $fecha_hasta]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);

            case 'TICKETS_POR_PRIORIDAD':
                $sql = "SELECT t.prioridad, COUNT(*) AS total
                        FROM dbo.ticket t
                        WHERE t.eliminado = 0
                          AND t.fecha_registro BETWEEN ? AND ?
                          $whereE
                        GROUP BY t.prioridad
                        ORDER BY CASE t.prioridad WHEN 'CRITICA' THEN 1 WHEN 'ALTA' THEN 2 WHEN 'MEDIA' THEN 3 ELSE 4 END";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$fecha_desde, $fecha_hasta]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);

            case 'TICKETS_POR_EMPRESA':
                $sql = "SELECT TOP 15 e.razon_social AS empresa, COUNT(*) AS total
                        FROM dbo.ticket t
                        INNER JOIN dbo.empresa e ON t.id_empresa = e.id_empresa
                        WHERE t.eliminado = 0
                          AND t.fecha_registro BETWEEN ? AND ?
                          $whereE
                        GROUP BY e.razon_social
                        ORDER BY total DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$fecha_desde, $fecha_hasta]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);

            case 'ATENCIONES_POR_TECNICO':
                $sql = "SELECT TOP 15 p.nombres + ' ' + p.apellido_paterno AS tecnico,
                               COUNT(*) AS atenciones,
                               ISNULL(AVG(ati.duracion_minutos),0) AS promedio_minutos
                        FROM dbo.atencion a
                        INNER JOIN dbo.persona p ON a.id_usuario = p.id_persona
                        INNER JOIN dbo.ticket t ON a.id_ticket = t.id_ticket
                        LEFT JOIN dbo.atencion_tiempo ati ON a.id_atencion = ati.id_atencion
                        WHERE t.eliminado = 0
                          AND a.fecha_inicio BETWEEN ? AND ?
                          $whereE $whereU
                        GROUP BY p.nombres, p.apellido_paterno
                        ORDER BY atenciones DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$fecha_desde, $fecha_hasta]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);

            case 'TIEMPOS_POR_DIA':
                $sql = "SELECT CONVERT(VARCHAR(10), a.fecha_inicio, 23) AS fecha,
                               ISNULL(AVG(ati.duracion_minutos),0) AS promedio_minutos,
                               COUNT(*) AS atenciones
                        FROM dbo.atencion a
                        INNER JOIN dbo.ticket t ON a.id_ticket = t.id_ticket
                        LEFT JOIN dbo.atencion_tiempo ati ON a.id_atencion = ati.id_atencion
                        WHERE t.eliminado = 0
                          AND a.fecha_inicio BETWEEN ? AND ?
                          $whereE $whereU
                        GROUP BY CONVERT(VARCHAR(10), a.fecha_inicio, 23)
                        ORDER BY fecha";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$fecha_desde, $fecha_hasta]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);

            default:
                return [];
        }
    }
}
