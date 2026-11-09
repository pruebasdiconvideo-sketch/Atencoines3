<?php
/**
 * api/routes/auditoria_usuarios.php
 * ----------------------------------------------------------------------------
 * Endpoint para auditar acciones por usuario.
 *
 * GET /auditoria_usuarios
 *   Parámetros:
 *     ?id_usuario=1        Filtra por ID de usuario
 *     ?correo=admin@...    Filtra por correo
 *     ?modulo=COTIZACIONES Filtra por módulo
 *     ?fecha_desde=2026-01-01
 *     ?fecha_hasta=2026-12-31
 *     ?export=csv           Exporta a CSV
 *     ?export=xlsx          Exporta a XLSX (requiere PhpSpreadsheet)
 *     ?page=1&per_page=50   Paginación
 *
 * Devuelve lista de acciones del usuario con detalles.
 */

Auth::requirePermission('SEGURIDAD', 'ver');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    ApiResponse::error('method_not_allowed', 'Solo GET permitido', 405);
}

try {
    $pdo = Database::connection();

    $idUsuario    = !empty($_GET['id_usuario']) ? (int)$_GET['id_usuario'] : null;
    $correo       = !empty($_GET['correo']) ? $_GET['correo'] : null;
    $modulo       = !empty($_GET['modulo']) ? $_GET['modulo'] : null;
    $fechaDesde   = !empty($_GET['fecha_desde']) ? $_GET['fecha_desde'] : null;
    $fechaHasta   = !empty($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : null;
    $export       = !empty($_GET['export']) ? $_GET['export'] : null;
    $page         = max(1, (int)($_GET['page'] ?? 1));
    $perPage      = min(500, max(10, (int)($_GET['per_page'] ?? 50)));
    $offset       = ($page - 1) * $perPage;

    /* Construir WHERE */
    $where  = " WHERE 1=1 ";
    $params = [];

    if ($idUsuario) {
        $where .= " AND a.id_usuario = ? ";
        $params[] = $idUsuario;
    }
    if ($correo) {
        $where .= " AND a.correo_usuario LIKE ? ";
        $params[] = '%' . $correo . '%';
    }
    if ($modulo) {
        $where .= " AND a.modulo = ? ";
        $params[] = $modulo;
    }
    if ($fechaDesde) {
        $where .= " AND a.fecha_hora >= ? ";
        $params[] = $fechaDesde . ' 00:00:00';
    }
    if ($fechaHasta) {
        $where .= " AND a.fecha_hora <= ? ";
        $params[] = $fechaHasta . ' 23:59:59';
    }

    /* Total */
    $sqlCount = "SELECT COUNT(*) FROM dbo.auditoria_accion a " . $where;
    $stmtC = $pdo->prepare($sqlCount);
    foreach ($params as $i => $v) {
        $stmtC->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmtC->execute();
    $total = (int)$stmtC->fetchColumn();

    /* Datos */
    $sqlData = "SELECT a.id_auditoria_accion, a.id_usuario, a.correo_usuario,
                       a.modulo, a.accion, a.descripcion, a.tabla_afectada,
                       a.pk_registro, a.ip_address, a.hostname, a.resultado,
                       CONVERT(VARCHAR(19), a.fecha_hora, 120) AS fecha_hora,
                       p.nombres + ' ' + p.apellido_paterno AS nombre_completo
                FROM dbo.auditoria_accion a
                LEFT JOIN dbo.persona p ON a.id_usuario = p.id_persona "
             . $where . " ORDER BY a.fecha_hora DESC";

    /* Si es exportación, traer todos los registros (hasta 5000) */
    if ($export) {
        $sqlData .= " OFFSET 0 ROWS FETCH NEXT 5000 ROWS ONLY";
    } else {
        $sqlData .= " OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";
    }

    $stmtD = $pdo->prepare($sqlData);
    foreach ($params as $i => $v) {
        $stmtD->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmtD->execute();
    $rows = $stmtD->fetchAll();

    /* Exportación CSV */
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="auditoria_usuarios_' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Fecha','Usuario','Correo','Módulo','Acción','Descripción','Tabla','PK','IP','Host','Resultado']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id_auditoria_accion'],
                $r['fecha_hora'],
                $r['nombre_completo'] ?? '—',
                $r['correo_usuario'] ?? '—',
                $r['modulo'] ?? '',
                $r['accion'] ?? '',
                $r['descripcion'] ?? '',
                $r['tabla_afectada'] ?? '',
                $r['pk_registro'] ?? '',
                $r['ip_address'] ?? '',
                $r['hostname'] ?? '',
                $r['resultado'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    /* Exportación XLSX */
    if ($export === 'xlsx') {
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
            use PhpOffice\PhpSpreadsheet\Spreadsheet;
            use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Auditoría');

            $headers = ['ID','Fecha','Usuario','Correo','Módulo','Acción','Descripción','Tabla','PK','IP','Host','Resultado'];
            $col = 1;
            foreach ($headers as $h) {
                $sheet->setCellValueByColumnAndRow($col++, 1, $h);
            }
            $sheet->getStyle('A1:L1')->getFont()->setBold(true);

            $rowNum = 2;
            foreach ($rows as $r) {
                $sheet->setCellValueByColumnAndRow(1, $rowNum, $r['id_auditoria_accion']);
                $sheet->setCellValueByColumnAndRow(2, $rowNum, $r['fecha_hora']);
                $sheet->setCellValueByColumnAndRow(3, $rowNum, $r['nombre_completo'] ?? '—');
                $sheet->setCellValueByColumnAndRow(4, $rowNum, $r['correo_usuario'] ?? '—');
                $sheet->setCellValueByColumnAndRow(5, $rowNum, $r['modulo'] ?? '');
                $sheet->setCellValueByColumnAndRow(6, $rowNum, $r['accion'] ?? '');
                $sheet->setCellValueByColumnAndRow(7, $rowNum, $r['descripcion'] ?? '');
                $sheet->setCellValueByColumnAndRow(8, $rowNum, $r['tabla_afectada'] ?? '');
                $sheet->setCellValueByColumnAndRow(9, $rowNum, $r['pk_registro'] ?? '');
                $sheet->setCellValueByColumnAndRow(10, $rowNum, $r['ip_address'] ?? '');
                $sheet->setCellValueByColumnAndRow(11, $rowNum, $r['hostname'] ?? '');
                $sheet->setCellValueByColumnAndRow(12, $rowNum, $r['resultado'] ?? '');
                $rowNum++;
            }

            $sheet->getStyle('A1:L1')->getFont()->setBold(true);
            foreach (range('A', 'L') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="auditoria_usuarios_' . date('Y-m-d') . '.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
            exit;
        }
        /* Si no hay PhpSpreadsheet, caer a CSV */
        header('Location: /api/v1/auditoria_usuarios?' . http_build_query(array_merge($_GET, ['export' => 'csv'])));
        exit;
    }

    /* Lista de usuarios para el filtro */
    $stmtU = $pdo->query("SELECT DISTINCT a.id_usuario, a.correo_usuario,
                                 p.nombres + ' ' + p.apellido_paterno AS nombre
                          FROM dbo.auditoria_accion a
                          LEFT JOIN dbo.persona p ON a.id_usuario = p.id_persona
                          WHERE a.id_usuario IS NOT NULL
                          ORDER BY nombre");
    $usuarios = $stmtU->fetchAll();

    /* Lista de módulos para el filtro */
    $stmtM = $pdo->query("SELECT DISTINCT modulo FROM dbo.auditoria_accion WHERE modulo IS NOT NULL ORDER BY modulo");
    $modulos = $stmtM->fetchAll(PDO::FETCH_COLUMN, 0);

    ApiResponse::success([
        'rows'      => $rows,
        'page'      => $page,
        'per_page'  => $perPage,
        'total'     => $total,
        'pages'     => $total > 0 ? (int)ceil($total / $perPage) : 0,
        'usuarios'  => $usuarios,
        'modulos'   => $modulos,
    ], 200);

} catch (Throwable $e) {
    ApiResponse::error('auditoria_failed', $e->getMessage(), 500);
}
