<?php
/**
 * modules/exportacion/worker.php
 *
 * Worker CLI que procesa los jobs de exportacion pendientes.
 * Se ejecuta en segundo plano via Windows Task Scheduler cada 2 minutos:
 *
 *   php C:\inetpub\wwwroot\Atenciones\modules\exportacion\worker.php
 *
 * Genera archivos XLSX, CSV, JSON directamente en disco y actualiza el job.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

// Generador XLSX simple (requiere PhpSpreadsheet via composer)
// Como alternativa zero-deps, generamos CSV/JSON sin librerias.

final class ExportWorker
{
    private const OUTPUT_DIR = 'C:\\inetpub\\wwwroot\\Atenciones\\storage\\exports\\';

    public static function run(int $maxJobs = 5): int
    {
        if (!is_dir(self::OUTPUT_DIR)) {
            @mkdir(self::OUTPUT_DIR, 0775, true);
        }

        $pdo  = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT TOP ? * FROM exportacion_job WITH (READPAST, UPDLOCK)
             WHERE estado = 'PENDIENTE' ORDER BY fecha_solicitud");
        $stmt->bindValue(1, $maxJobs, PDO::PARAM_INT);
        $stmt->execute();
        $jobs = $stmt->fetchAll();

        $processed = 0;
        foreach ($jobs as $job) {
            try {
                // Marcar como PROCESANDO
                Database::callSP('sp_exportacion_actualizar', [
                    (int)$job['id_job'], 'PROCESANDO'
                ]);

                $rows     = self::fetchData($job);
                $filePath = self::writeFile($job, $rows);

                Database::callSP('sp_exportacion_actualizar', [
                    (int)$job['id_job'],
                    'COMPLETADO',
                    count($rows),
                    $filePath,
                    filesize($filePath),
                    null
                ]);
                $processed++;
            } catch (Throwable $e) {
                error_log("[export] job {$job['id_job']} failed: " . $e->getMessage());
                Database::callSP('sp_exportacion_actualizar', [
                    (int)$job['id_job'],
                    'FALLIDO',
                    null, null, null,
                    mb_substr($e->getMessage(), 0, 1000)
                ]);
            }
        }
        return $processed;
    }

    /** Obtiene los datos a exportar segun modulo + filtros */
    private static function fetchData(array $job): array
    {
        $filtros = json_decode($job['filtros_json'] ?? '{}', true) ?: [];
        switch (strtoupper($job['modulo'])) {
            case 'TICKETS':
                return Database::callSP('sp_ticket_list', [
                    $filtros['estado']           ?? null,
                    $filtros['prioridad']        ?? null,
                    $filtros['id_empresa']       ?? null,
                    $filtros['id_sucursal']      ?? null,
                    $filtros['id_rubro']         ?? null,
                    $filtros['id_contacto']      ?? null,
                    $filtros['id_usuario']       ?? null,
                    $filtros['fecha_desde']      ?? null,
                    $filtros['fecha_hasta']      ?? null,
                    $filtros['search']            ?? null,
                    $filtros['id_motivo']        ?? null,
                ]);
            case 'ATENCIONES':
                return Database::callSP('sp_audit_cambio_listar', [
                    'atencion', null, null, null, null, null, 5000
                ]);
            case 'AUDITORIA':
                return Database::callSP('sp_audit_cambio_listar', [
                    null, null, null, null,
                    $filtros['desde'] ?? null,
                    $filtros['hasta'] ?? null,
                    5000
                ]);
            case 'COTIZACIONES':
                // Exportación masiva de cotizaciones: usa sp_cotizacion_list_paged
                // con per_page=5000 para obtener hasta 5000 cotizaciones de una vez.
                $pdo = Database::connection();
                $stmt = $pdo->prepare(
                    'EXEC dbo.sp_cotizacion_list_paged
                        @estado = ?, @id_empresa = ?, @id_tipo = ?,
                        @id_usuario_creador = ?, @id_vendedor = ?,
                        @fecha_desde = ?, @fecha_hasta = ?, @search_term = ?,
                        @incluir_eliminados = ?, @anio = ?,
                        @page = 1, @per_page = 5000'
                );
                $stmt->bindValue(1, $filtros['estado'] ?? null, PDO::PARAM_STR);
                $stmt->bindValue(2, $filtros['id_empresa'] ?? null, PDO::PARAM_INT);
                $stmt->bindValue(3, $filtros['id_tipo'] ?? null, PDO::PARAM_INT);
                $stmt->bindValue(4, $filtros['id_usuario'] ?? null, PDO::PARAM_INT);
                $stmt->bindValue(5, $filtros['id_vendedor'] ?? null, PDO::PARAM_INT);
                $stmt->bindValue(6, $filtros['fecha_desde'] ?? null, PDO::PARAM_STR);
                $stmt->bindValue(7, $filtros['fecha_hasta'] ?? null, PDO::PARAM_STR);
                $stmt->bindValue(8, $filtros['search'] ?? null, PDO::PARAM_STR);
                $stmt->bindValue(9, 0, PDO::PARAM_INT);
                $stmt->bindValue(10, $filtros['anio'] ?? null, PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll();
            default:
                throw new RuntimeException("Modulo de exportacion no soportado: {$job['modulo']}");
        }
    }

    /** Escribe el archivo en el formato solicitado */
    private static function writeFile(array $job, array $rows): string
    {
        $ext  = strtolower($job['formato']);
        $name = sprintf('export_%d_%s.%s', $job['id_job'], date('Ymd_His'), $ext);
        $path = self::OUTPUT_DIR . $name;

        if ($ext === 'json') {
            file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } elseif ($ext === 'csv') {
            $fp = fopen($path, 'w');
            // BOM para que Excel reconozca UTF-8
            fputs($fp, "\xEF\xBB\xBF");
            if (!empty($rows)) {
                fputcsv($fp, array_keys($rows[0]));
                foreach ($rows as $r) fputcsv($fp, array_map('strval', $r));
            }
            fclose($fp);
        } elseif ($ext === 'xlsx') {
            // Si PhpSpreadsheet esta disponible, lo usa; si no, genera CSV como fallback
            if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
                $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sh = $ss->getActiveSheet();
                if (!empty($rows)) {
                    $sh->fromArray(array_keys($rows[0]), null, 'A1');
                    $rowIdx = 2;
                    foreach ($rows as $r) {
                        $sh->fromArray(array_map('strval', $r), null, 'rowIdx');
                        $rowIdx++;
                    }
                }
                $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx');
                $writer->save($path);
            } else {
                $csvPath = preg_replace('/\.xlsx$/', '.csv', $path);
                $fp = fopen($csvPath, 'w');
                fputs($fp, "\xEF\xBB\xBF");
                if (!empty($rows)) {
                    fputcsv($fp, array_keys($rows[0]));
                    foreach ($rows as $r) fputcsv($fp, array_map('strval', $r));
                }
                fclose($fp);
                $path = $csvPath;
            }
        } elseif ($ext === 'pdf') {
            // Genera PDF simple con tabla HTML -> TCPDF o Dompdf
            $html = '<html><head><meta charset="utf-8"></head><body>';
            $html .= '<h2>Exportacion ' . htmlspecialchars($job['modulo']) . ' #' . $job['id_job'] . '</h2>';
            $html .= '<table border="1" cellpadding="4" cellspacing="0">';
            if (!empty($rows)) {
                $html .= '<tr>';
                foreach (array_keys($rows[0]) as $col) {
                    $html .= '<th>' . htmlspecialchars($col) . '</th>';
                }
                $html .= '</tr>';
                foreach ($rows as $r) {
                    $html .= '<tr>';
                    foreach ($r as $v) $html .= '<td>' . htmlspecialchars((string)$v) . '</td>';
                    $html .= '</tr>';
                }
            }
            $html .= '</table></body></html>';
            file_put_contents($path . '.html', $html);
            // Aqui se invocaria Dompdf/TCPDF para convertir HTML -> PDF
            $path = $path . '.html';  // temporalmente
        }

        return $path;
    }
}

// Punto de entrada CLI
if (PHP_SAPI === 'cli') {
    $count = ExportWorker::run(5);
    echo "Jobs procesados: $count\n";
}
