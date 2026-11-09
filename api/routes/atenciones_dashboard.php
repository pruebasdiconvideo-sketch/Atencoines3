<?php
/**
 * api/routes/atenciones_dashboard.php
 * ----------------------------------------------------------------------------
 * Endpoint para el dashboard de atenciones (tickets).
 *
 * GET /atenciones_dashboard
 *   Devuelve estadísticas de tickets/atenciones por estado, mes y año.
 *
 * Parámetros opcionales:
 *   ?anio=2026   Filtra por un año específico (default: año actual)
 *
 * Respuesta:
 *   {
 *     "por_estado": [...],
 *     "por_mes": [...],
 *     "por_prioridad": [...],
 *     "resumen": {
 *       "total_tickets": 25,
 *       "pendientes": 10,
 *       "en_atencion": 5,
 *       "resueltos": 7,
 *       "cerrados": 3,
 *       "tiempo_promedio_seg": 3600
 *     },
 *     "anios_disponibles": [2024, 2025, 2026]
 *   }
 */

Auth::requirePermission('TICKETS', 'ver');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    ApiResponse::error('method_not_allowed', 'Solo GET permitido', 405);
}

try {
    $pdo = Database::connection();
    $anioFiltro = !empty($_GET['anio']) ? (int)$_GET['anio'] : null;

    /* 1. Tickets por estado */
    $sqlEstado = "SELECT t.estado,
                         COUNT(*) AS total
                  FROM dbo.ticket t
                  WHERE ISNULL(t.eliminado, 0) = 0";
    $paramsEstado = [];
    if ($anioFiltro) {
        $sqlEstado .= " AND YEAR(t.fecha_registro) = ?";
        $paramsEstado[] = $anioFiltro;
    }
    $sqlEstado .= " GROUP BY t.estado ORDER BY total DESC";
    $stmtE = $pdo->prepare($sqlEstado);
    foreach ($paramsEstado as $i => $v) {
        $stmtE->bindValue($i + 1, $v, PDO::PARAM_INT);
    }
    $stmtE->execute();
    $porEstado = $stmtE->fetchAll();

    /* 2. Tickets por mes (del año seleccionado o actual) */
    $anioMes = $anioFiltro ?: (int)date('Y');
    $sqlMes = "SELECT MONTH(t.fecha_registro) AS mes,
                      COUNT(*) AS total,
                      SUM(CASE WHEN t.estado IN ('RESUELTO','CERRADO') THEN 1 ELSE 0 END) AS resueltos
               FROM dbo.ticket t
               WHERE ISNULL(t.eliminado, 0) = 0
                 AND YEAR(t.fecha_registro) = ?
               GROUP BY MONTH(t.fecha_registro)
               ORDER BY mes";
    $stmtM = $pdo->prepare($sqlMes);
    $stmtM->bindValue(1, $anioMes, PDO::PARAM_INT);
    $stmtM->execute();
    $rowsMes = $stmtM->fetchAll();

    /* Llenar los 12 meses */
    $nombresMeses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                     'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $porMes = [];
    $mesMap = [];
    foreach ($rowsMes as $r) {
        $mesMap[(int)$r['mes']] = $r;
    }
    for ($m = 1; $m <= 12; $m++) {
        $porMes[] = [
            'mes' => $m,
            'mes_nombre' => $nombresMeses[$m - 1],
            'total' => isset($mesMap[$m]) ? (int)$mesMap[$m]['total'] : 0,
            'resueltos' => isset($mesMap[$m]) ? (int)$mesMap[$m]['resueltos'] : 0,
        ];
    }

    /* 3. Tickets por prioridad */
    $sqlPrioridad = "SELECT t.prioridad,
                            COUNT(*) AS total
                     FROM dbo.ticket t
                     WHERE ISNULL(t.eliminado, 0) = 0";
    $paramsPrio = [];
    if ($anioFiltro) {
        $sqlPrioridad .= " AND YEAR(t.fecha_registro) = ?";
        $paramsPrio[] = $anioFiltro;
    }
    $sqlPrioridad .= " GROUP BY t.prioridad ORDER BY 
                       CASE t.prioridad WHEN 'CRITICA' THEN 1 WHEN 'ALTA' THEN 2 
                       WHEN 'MEDIA' THEN 3 WHEN 'BAJA' THEN 4 ELSE 5 END";
    $stmtP = $pdo->prepare($sqlPrioridad);
    foreach ($paramsPrio as $i => $v) {
        $stmtP->bindValue($i + 1, $v, PDO::PARAM_INT);
    }
    $stmtP->execute();
    $porPrioridad = $stmtP->fetchAll();

    /* 4. Resumen general */
    $sqlResumen = "SELECT
                        COUNT(*) AS total_tickets,
                        SUM(CASE WHEN t.estado = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes,
                        SUM(CASE WHEN t.estado = 'EN_ATENCION' THEN 1 ELSE 0 END) AS en_atencion,
                        SUM(CASE WHEN t.estado = 'RESUELTO' THEN 1 ELSE 0 END) AS resueltos,
                        SUM(CASE WHEN t.estado = 'CERRADO' THEN 1 ELSE 0 END) AS cerrados,
                        SUM(CASE WHEN t.estado = 'ANULADO' THEN 1 ELSE 0 END) AS anulados,
                        SUM(CASE WHEN t.prioridad = 'CRITICA' THEN 1 ELSE 0 END) AS criticos,
                        ISNULL(AVG(CASE WHEN t.tiempo_total_segundos IS NOT NULL 
                                        AND t.tiempo_total_segundos > 0 
                                   THEN t.tiempo_total_segundos ELSE NULL END), 0) AS tiempo_promedio_seg
                   FROM dbo.ticket t
                   WHERE ISNULL(t.eliminado, 0) = 0";
    $paramsResumen = [];
    if ($anioFiltro) {
        $sqlResumen .= " AND YEAR(t.fecha_registro) = ?";
        $paramsResumen[] = $anioFiltro;
    }
    $stmtR = $pdo->prepare($sqlResumen);
    foreach ($paramsResumen as $i => $v) {
        $stmtR->bindValue($i + 1, $v, PDO::PARAM_INT);
    }
    $stmtR->execute();
    $resumen = $stmtR->fetch();

    /* Convertir tiempo promedio a formato legible */
    $tiempoSeg = (int)($resumen['tiempo_promedio_seg'] ?? 0);
    $tiempoFormato = '';
    if ($tiempoSeg > 0) {
        $horas = floor($tiempoSeg / 3600);
        $minutos = floor(($tiempoSeg % 3600) / 60);
        $tiempoFormato = $horas . 'h ' . $minutos . 'm';
    } else {
        $tiempoFormato = '—';
    }

    $resumenFinal = [
        'total_tickets' => (int)($resumen['total_tickets'] ?? 0),
        'pendientes' => (int)($resumen['pendientes'] ?? 0),
        'en_atencion' => (int)($resumen['en_atencion'] ?? 0),
        'resueltos' => (int)($resumen['resueltos'] ?? 0),
        'cerrados' => (int)($resumen['cerrados'] ?? 0),
        'anulados' => (int)($resumen['anulados'] ?? 0),
        'criticos' => (int)($resumen['criticos'] ?? 0),
        'tiempo_promedio_seg' => $tiempoSeg,
        'tiempo_promedio_formato' => $tiempoFormato,
    ];

    /* 5. Años disponibles */
    $stmtA = $pdo->query("SELECT DISTINCT YEAR(fecha_registro) AS anio
                          FROM dbo.ticket
                          WHERE ISNULL(eliminado, 0) = 0
                          ORDER BY anio DESC");
    $anios = $stmtA->fetchAll(PDO::FETCH_COLUMN, 0);

    ApiResponse::success([
        'por_estado' => $porEstado,
        'por_mes' => $porMes,
        'por_prioridad' => $porPrioridad,
        'resumen' => $resumenFinal,
        'anios_disponibles' => $anios,
        'anio_filtrado' => $anioFiltro,
    ], 200);

} catch (Throwable $e) {
    ApiResponse::error('dashboard_failed', $e->getMessage(), 500);
}
