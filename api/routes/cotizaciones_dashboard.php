<?php
/**
 * api/routes/cotizaciones_dashboard.php
 * ----------------------------------------------------------------------------
 * Endpoint para el dashboard de cotizaciones.
 *
 * GET /cotizaciones_dashboard
 *   Devuelve estadísticas de cotizaciones por estado, mes y año.
 *
 * Parámetros opcionales:
 *   ?anio=2026   Filtra por un año específico (default: año actual)
 *
 * Respuesta:
 *   {
 *     "por_estado": [
 *       { "estado": "BORRADOR", "total": 5, "monto": 12500.00 },
 *       { "estado": "APROBADA", "total": 3, "monto": 45000.00 },
 *       ...
 *     ],
 *     "por_mes": [
 *       { "mes": 1, "mes_nombre": "Enero", "total": 2, "monto": 5000.00 },
 *       ...
 *     ],
 *     "resumen": {
 *       "total_cotizaciones": 25,
 *       "total_monto": 150000.00,
 *       "aprobadas": 5,
 *       "pendientes": 10,
 *       "rechazadas": 2,
 *       "tasa_aprobacion": 20.0
 *     },
 *     "anios_disponibles": [2024, 2025, 2026]
 *   }
 */

Auth::requirePermission('COTIZACIONES', 'ver');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    ApiResponse::error('method_not_allowed', 'Solo GET permitido', 405);
}

try {
    $pdo = Database::connection();
    $anioFiltro = !empty($_GET['anio']) ? (int)$_GET['anio'] : null;

    /* 1. Cotizaciones por estado */
    $sqlEstado = "SELECT c.estado,
                         COUNT(*) AS total,
                         ISNULL(SUM(c.total), 0) AS monto
                  FROM dbo.cotizacion c
                  WHERE ISNULL(c.eliminado, 0) = 0";
    $paramsEstado = [];
    if ($anioFiltro) {
        $sqlEstado .= " AND YEAR(c.fecha_creacion) = ?";
        $paramsEstado[] = $anioFiltro;
    }
    $sqlEstado .= " GROUP BY c.estado ORDER BY total DESC";
    $stmtE = $pdo->prepare($sqlEstado);
    foreach ($paramsEstado as $i => $v) {
        $stmtE->bindValue($i + 1, $v, PDO::PARAM_INT);
    }
    $stmtE->execute();
    $porEstado = $stmtE->fetchAll();

    /* 2. Cotizaciones por mes (del año seleccionado o actual) */
    $anioMes = $anioFiltro ?: (int)date('Y');
    $sqlMes = "SELECT MONTH(c.fecha_creacion) AS mes,
                      COUNT(*) AS total,
                      ISNULL(SUM(c.total), 0) AS monto
               FROM dbo.cotizacion c
               WHERE ISNULL(c.eliminado, 0) = 0
                 AND YEAR(c.fecha_creacion) = ?
               GROUP BY MONTH(c.fecha_creacion)
               ORDER BY mes";
    $stmtM = $pdo->prepare($sqlMes);
    $stmtM->bindValue(1, $anioMes, PDO::PARAM_INT);
    $stmtM->execute();
    $rowsMes = $stmtM->fetchAll();

    /* Llenar los 12 meses (incluso los que no tienen cotizaciones) */
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
            'monto' => isset($mesMap[$m]) ? (float)$mesMap[$m]['monto'] : 0,
        ];
    }

    /* 3. Resumen general */
    $sqlResumen = "SELECT
                        COUNT(*) AS total_cotizaciones,
                        ISNULL(SUM(c.total), 0) AS total_monto,
                        SUM(CASE WHEN c.estado = 'APROBADA' THEN 1 ELSE 0 END) AS aprobadas,
                        SUM(CASE WHEN c.estado IN ('BORRADOR','PENDIENTE_ENVIO','ENVIADA','EN_SEGUIMIENTO','EN_NEGOCIACION') THEN 1 ELSE 0 END) AS pendientes,
                        SUM(CASE WHEN c.estado = 'RECHAZADA' THEN 1 ELSE 0 END) AS rechazadas,
                        SUM(CASE WHEN c.estado = 'CERRADA' THEN 1 ELSE 0 END) AS cerradas,
                        SUM(CASE WHEN c.estado = 'CANCELADA' THEN 1 ELSE 0 END) AS canceladas
                   FROM dbo.cotizacion c
                   WHERE ISNULL(c.eliminado, 0) = 0";
    $paramsResumen = [];
    if ($anioFiltro) {
        $sqlResumen .= " AND YEAR(c.fecha_creacion) = ?";
        $paramsResumen[] = $anioFiltro;
    }
    $stmtR = $pdo->prepare($sqlResumen);
    foreach ($paramsResumen as $i => $v) {
        $stmtR->bindValue($i + 1, $v, PDO::PARAM_INT);
    }
    $stmtR->execute();
    $resumen = $stmtR->fetch();

    $totalCots = (int)($resumen['total_cotizaciones'] ?? 0);
    $aprobadas = (int)($resumen['aprobadas'] ?? 0);
    $tasaAprobacion = $totalCots > 0 ? round(($aprobadas / $totalCots) * 100, 1) : 0;

    $resumenFinal = [
        'total_cotizaciones' => $totalCots,
        'total_monto' => (float)($resumen['total_monto'] ?? 0),
        'aprobadas' => $aprobadas,
        'pendientes' => (int)($resumen['pendientes'] ?? 0),
        'rechazadas' => (int)($resumen['rechazadas'] ?? 0),
        'cerradas' => (int)($resumen['cerradas'] ?? 0),
        'canceladas' => (int)($resumen['canceladas'] ?? 0),
        'tasa_aprobacion' => $tasaAprobacion,
    ];

    /* 4. Años disponibles */
    $stmtA = $pdo->query("SELECT DISTINCT YEAR(fecha_creacion) AS anio
                          FROM dbo.cotizacion
                          WHERE ISNULL(eliminado, 0) = 0
                          ORDER BY anio DESC");
    $anios = $stmtA->fetchAll(PDO::FETCH_COLUMN, 0);

    ApiResponse::success([
        'por_estado' => $porEstado,
        'por_mes' => $porMes,
        'resumen' => $resumenFinal,
        'anios_disponibles' => $anios,
        'anio_filtrado' => $anioFiltro,
    ], 200);

} catch (Throwable $e) {
    ApiResponse::error('dashboard_failed', $e->getMessage(), 500);
}
