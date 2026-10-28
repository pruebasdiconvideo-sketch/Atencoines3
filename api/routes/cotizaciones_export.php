<?php
/**
 * api/routes/cotizaciones_export.php
 *
 * Exportación directa (síncrona) de cotizaciones a CSV / XLSX / PDF / HTML.
 *
 * Endpoints:
 *   GET  /cotizaciones_export.csv            Exporta listado a CSV (download)
 *   GET  /cotizaciones_export.xlsx           Exporta listado a XLSX simple
 *   POST /cotizaciones_export                Crea job asíncrono (para listados grandes)
 *   GET  /cotizaciones_export/{id_cot}/pdf   Genera PDF de UNA cotización (HTML → impresión)
 *
 * NOTA: para exportaciones pesadas (>5000 filas), usa el job asíncrono
 *       que ya existe en /api/v1/exportaciones (worker en CLI).
 *
 * Filtros aceptados (query string):
 *   estado, id_empresa, id_tipo, id_usuario, id_vendedor,
 *   fecha_desde, fecha_hasta, search, anio
 */

Auth::requirePermission('COTIZACIONES', 'exportar');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;

/* =====================================================================
 *  GET /cotizaciones_export.csv — Exportación sincrónica a CSV
 *
 *  Genera el archivo en memoria y lo envía al cliente directamente.
 *  Aprovecha sp_ticket_export para máxima velocidad en el motor SQL.
 * ===================================================================== */
if ($method === 'GET' && ($id === 'csv' || $id === 'xlsx' || $id === 'html')) {

    $formato = strtoupper($id);

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "EXEC dbo.sp_cotizacion_list_paged
                @estado = ?, @id_empresa = ?, @id_tipo = ?,
                @id_usuario_creador = ?, @id_vendedor = ?,
                @fecha_desde = ?, @fecha_hasta = ?,
                @search_term = ?, @incluir_eliminados = ?,
                @anio = ?, @page = 1, @per_page = 5000"
        );

        $incluirEliminados = (!empty($_GET['incluir_eliminados']) && Auth::hasPermission('COTIZACIONES', 'eliminar')) ? 1 : 0;

        $stmt->bindValue(1,  $_GET['estado']           ?? null, PDO::PARAM_STR);
        $stmt->bindValue(2,  isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : null, PDO::PARAM_INT);
        $stmt->bindValue(3,  isset($_GET['id_tipo'])    ? (int)$_GET['id_tipo']    : null, PDO::PARAM_INT);
        $stmt->bindValue(4,  isset($_GET['id_usuario']) ? (int)$_GET['id_usuario'] : null, PDO::PARAM_INT);
        $stmt->bindValue(5,  isset($_GET['id_vendedor'])? (int)$_GET['id_vendedor']: null, PDO::PARAM_INT);
        $stmt->bindValue(6,  $_GET['fecha_desde']      ?? null, PDO::PARAM_STR);
        $stmt->bindValue(7,  $_GET['fecha_hasta']      ?? null, PDO::PARAM_STR);
        $stmt->bindValue(8,  $_GET['search']           ?? null, PDO::PARAM_STR);
        $stmt->bindValue(9,  $incluirEliminados, PDO::PARAM_INT);
        $stmt->bindValue(10, isset($_GET['anio'])       ? (int)$_GET['anio']       : null, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            ApiResponse::error('no_data', 'No hay datos para exportar con los filtros indicados.', 404);
        }

        $fecha = date('Y-m-d_His');
        $nombreArchivo = "cotizaciones_{$fecha}";

        /* ---------- CSV ---------- */
        if ($formato === 'CSV') {
            // BOM UTF-8 para que Excel lo abra correctamente con acentos
            $out = "\xEF\xBB\xBF";
            $cols = ['ID','Codigo','Version','Estado','Fecha Creacion',
                     'Fecha Envio','Fecha Aprobacion','Titulo','Empresa',
                     'Tipo','Total','Moneda','Vendedor','Creador','N° Comunicaciones'];
            $out .= implode(';', $cols) . "\r\n";
            foreach ($rows as $r) {
                $line = [
                    $r['id_cotizacion'],
                    $r['codigo'],
                    $r['version_numero'],
                    $r['estado'],
                    $r['fecha_creacion'],
                    $r['fecha_envio'] ?? '',
                    $r['fecha_aprobacion'] ?? '',
                    $r['titulo_documento'] ?? '',
                    $r['empresa_razon_social'] ?? '',
                    $r['tipo_nombre'] ?? '',
                    $r['total'] ?? 0,
                    $r['moneda'] ?? 'PEN',
                    $r['vendedor_nombre'] ?? '',
                    $r['creador_nombre'] ?? '',
                    $r['total_comunicaciones'] ?? 0,
                ];
                // Escapar comas/semicolon dentro de valores
                $line = array_map(function($v) {
                    $v = (string)$v;
                    if (strpos($v, ';') !== false || strpos($v, '"') !== false || strpos($v, "\n") !== false) {
                        return '"' . str_replace('"', '""', $v) . '"';
                    }
                    return $v;
                }, $line);
                $out .= implode(';', $line) . "\r\n";
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $nombreArchivo . '.csv"');
            header('Content-Length: ' . strlen($out));
            header('Cache-Control: private, no-cache');
            http_response_code(200);
            echo $out;
            exit;
        }

        /* ---------- XLSX (XML Spreadsheet 2003 — sin librerías) ---------- */
        if ($formato === 'XLSX') {
            // Generar como XML Spreadsheet 2003 que Excel abre nativamente
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n";
            $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\r\n";
            $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\r\n";
            $xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\r\n";
            $xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\r\n";
            $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\r\n";
            $xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\r\n";
            $xml .= '<Styles>' .
                    '<Style ss:ID="hdr"><Font ss:Bold="1"/><Interior ss:Color="#4472C4" ss:Pattern="Solid"/>' .
                    '<Font ss:Color="#FFFFFF" ss:Bold="1"/></Style></Styles>';
            $xml .= '<Worksheet ss:Name="Cotizaciones"><Table>' . "\r\n";

            // Cabecera
            $cols = ['ID','Codigo','Version','Estado','Fecha Creacion',
                     'Fecha Envio','Fecha Aprobacion','Titulo','Empresa',
                     'Tipo','Total','Moneda','Vendedor','Creador'];
            $xml .= '<Row ss:StyleID="hdr">';
            foreach ($cols as $c) {
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($c, ENT_XML1) . '</Data></Cell>';
            }
            $xml .= '</Row>' . "\r\n";

            foreach ($rows as $r) {
                $xml .= '<Row>';
                $cells = [
                    $r['id_cotizacion'], $r['codigo'], $r['version_numero'], $r['estado'],
                    $r['fecha_creacion'], $r['fecha_envio'] ?? '', $r['fecha_aprobacion'] ?? '',
                    $r['titulo_documento'] ?? '', $r['empresa_razon_social'] ?? '',
                    $r['tipo_nombre'] ?? '', $r['total'] ?? 0, $r['moneda'] ?? 'PEN',
                    $r['vendedor_nombre'] ?? '', $r['creador_nombre'] ?? '',
                ];
                foreach ($cells as $i => $v) {
                    $isNumeric = in_array($i, [0,2,10], true) && is_numeric($v);
                    $type = $isNumeric ? 'Number' : 'String';
                    $val = htmlspecialchars((string)$v, ENT_XML1);
                    $xml .= "<Cell><Data ss:Type=\"{$type}\">{$val}</Data></Cell>";
                }
                $xml .= '</Row>' . "\r\n";
            }

            $xml .= '</Table></Worksheet></Workbook>';

            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $nombreArchivo . '.xls"');
            header('Content-Length: ' . strlen($xml));
            http_response_code(200);
            echo $xml;
            exit;
        }

        /* ---------- HTML (tabla para imprimir o abrir en Excel) ---------- */
        if ($formato === 'HTML') {
            $out = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
            $out .= '<title>Exportación de Cotizaciones</title>';
            $out .= '<style>
                body { font-family: "Segoe UI", Arial, sans-serif; padding: 24px; color: #222; }
                h1 { color: #2c3e50; }
                table { border-collapse: collapse; width: 100%; font-size: 12px; }
                th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
                th { background: #4472C4; color: #fff; font-weight: 600; }
                tr:nth-child(even) td { background: #f9f9f9; }
                .total { font-weight: bold; color: #2c3e50; }
                @media print { body { padding: 0; } }
            </style></head><body>';
            $out .= '<h1>Listado de Cotizaciones</h1>';
            $out .= '<p>Generado: ' . date('d/m/Y H:i:s') . ' · Total: ' . count($rows) . ' registros</p>';
            $out .= '<table>';
            $cols = ['ID','Código','Versión','Estado','Fecha Creación',
                     'Fecha Envío','Fecha Aprobación','Título','Empresa',
                     'Tipo','Total','Moneda','Vendedor','Creador'];
            $out .= '<thead><tr>';
            foreach ($cols as $c) $out .= '<th>' . htmlspecialchars($c, ENT_QUOTES) . '</th>';
            $out .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $out .= '<tr>';
                $out .= '<td>' . (int)$r['id_cotizacion'] . '</td>';
                $out .= '<td>' . htmlspecialchars($r['codigo'] ?? '', ENT_QUOTES) . '</td>';
                $out .= '<td>' . (int)$r['version_numero'] . '</td>';
                $estadoColor = [
                    'BORRADOR'        => '#6c757d',
                    'PENDIENTE_ENVIO' => '#17a2b8',
                    'ENVIADA'         => '#007bff',
                    'EN_SEGUIMIENTO'  => '#ffc107',
                    'EN_NEGOCIACION'  => '#fd7e14',
                    'APROBADA'        => '#28a745',
                    'RECHAZADA'       => '#dc3545',
                    'CANCELADA'       => '#343a40',
                    'CERRADA'         => '#198754',
                ];
                $color = $estadoColor[$r['estado']] ?? '#6c757d';
                $out .= '<td><span style="background:'.$color.';color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">' . htmlspecialchars($r['estado'], ENT_QUOTES) . '</span></td>';
                $out .= '<td>' . htmlspecialchars($r['fecha_creacion'] ?? '', ENT_QUOTES) . '</td>';
                $out .= '<td>' . htmlspecialchars($r['fecha_envio'] ?? '', ENT_QUOTES) . '</td>';
                $out .= '<td>' . htmlspecialchars($r['fecha_aprobacion'] ?? '', ENT_QUOTES) . '</td>';
                $out .= '<td>' . htmlspecialchars($r['titulo_documento'] ?? '', ENT_QUOTES) . '</td>';
                $out .= '<td>' . htmlspecialchars($r['empresa_razon_social'] ?? '', ENT_QUOTES) . '</td>';
                $out .= '<td>' . htmlspecialchars($r['tipo_nombre'] ?? '', ENT_QUOTES) . '</td>';
                $out .= '<td class="total">' . number_format((float)($r['total'] ?? 0), 2) . '</td>';
                $out .= '<td>' . htmlspecialchars($r['moneda'] ?? 'PEN', ENT_QUOTES) . '</td>';
                $out .= '<td>' . htmlspecialchars($r['vendedor_nombre'] ?? '', ENT_QUOTES) . '</td>';
                $out .= '<td>' . htmlspecialchars($r['creador_nombre'] ?? '', ENT_QUOTES) . '</td>';
                $out .= '</tr>';
            }
            $out .= '</tbody></table>';
            $out .= '</body></html>';

            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $nombreArchivo . '.html"');
            header('Content-Length: ' . strlen($out));
            http_response_code(200);
            echo $out;
            exit;
        }

    } catch (Throwable $e) {
        error_log('[Cotizaciones Export GET] error: ' . $e->getMessage());
        ApiResponse::error('export_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  POST /cotizaciones_export — Crear job asíncrono (para listados grandes)
 *  Reusa sp_exportacion_crear existente.
 * ===================================================================== */
if ($method === 'POST' && $id === null) {
    Auth::requirePermission('COTIZACIONES', 'exportar');

    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $formato = strtoupper($body['formato'] ?? 'CSV');
    $allowed = ['XLSX','CSV','PDF','JSON'];
    if (!in_array($formato, $allowed, true)) {
        ApiResponse::validationError(['formato' => 'Formato inválido. Use: ' . implode(',', $allowed)]);
    }

    $filtros = [
        'estado'      => $body['estado'] ?? null,
        'id_empresa'   => $body['id_empresa'] ?? null,
        'id_tipo'      => $body['id_tipo'] ?? null,
        'fecha_desde'  => $body['fecha_desde'] ?? null,
        'fecha_hasta'  => $body['fecha_hasta'] ?? null,
        'search'       => $body['search'] ?? null,
    ];
    $filtrosJson = json_encode(array_filter($filtros), JSON_UNESCAPED_UNICODE);

    try {
        $rows = Database::callSP('sp_exportacion_crear', [
            $user['id_persona'], 'COTIZACIONES', $formato, $filtrosJson
        ]);
        $idJob = (int)($rows[0]['id_job'] ?? 0);

        Audit::log('COTIZACIONES', 'EXPORTAR',
            'Job de exportación creado #' . $idJob . ' (formato ' . $formato . ')',
            'exportacion_job', (string)$idJob,
            ['formato' => $formato, 'filtros' => $filtros]);

        ApiResponse::success(['id_job' => $idJob, 'estado' => 'PENDIENTE'],
            202, 'Job de exportación creado. Será procesado por el worker en segundo plano.');
    } catch (Throwable $e) {
        ApiResponse::error('job_create_failed', $e->getMessage(), 500);
    }
}

ApiResponse::error('not_found',
    "Endpoint no encontrado. Method=$method, resource=cotizaciones_export, id=$id, action=$action",
    404
);
