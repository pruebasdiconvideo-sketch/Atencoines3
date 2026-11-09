<?php
/**
 * api/routes/cotizaciones_export_v8.php
 * ----------------------------------------------------------------------------
 * VERSIÓN 8 — Endpoints para exportar cotizaciones con LOGO incluido.
 *
 * Endpoints:
 *   GET /cotizaciones/{id}/export_v8/pdf    Exportar a PDF (HTML imprimible con logo)
 *   GET /cotizaciones/{id}/export_v8/html    Exportar a HTML (con logo embebido)
 *   GET /cotizaciones/{id}/export_v8/xlsx    Exportar a XLSX (con logo en celda A1)
 *   GET /cotizaciones/{id}/export_v8/csv     Exportar a CSV
 *
 * DIFERENCIA vs exportación original:
 *   - El logo de la empresa emisora se incluye SIEMPRE (si está cargado).
 *   - En PDF/HTML: el logo aparece en el encabezado, alineado a la izquierda.
 *   - En XLSX: el logo se inserta como imagen en la celda A1.
 *   - En CSV: no se puede embeber imágenes, pero se añade una nota.
 *   - Las 3 cuentas bancarias activas de la emisora aparecen siempre
 *     (no solo la cuenta principal).
 *   - NO se muestra el estado interno (APROBADA/RECHAZADA).
 */

if (!isset($userId) || $userId <= 0) {
    return;
}

if ($method === 'GET' && is_numeric($id) && $action === 'export_v8') {

    $formato = $segments[3] ?? 'pdf';  // pdf | html | xlsx | csv

    Auth::requirePermission('COTIZACIONES', 'imprimir');

    try {
        $pdo = Database::connection();

        /* Obtener datos de la cotización */
        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_get @id_cotizacion = ?");
        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->execute();

        $cab = $stmt->fetch();
        if (!$cab) ApiResponse::error('not_found', 'Cotización no encontrada', 404);

        $stmt->nextRowset(); $detalle        = $stmt->fetchAll();
        $stmt->nextRowset(); $historial      = $stmt->fetchAll();
        $stmt->nextRowset(); $comunicaciones = $stmt->fetchAll();
        $stmt->nextRowset(); $versiones      = $stmt->fetchAll();
        $stmt->nextRowset(); $documentos     = $stmt->fetchAll();

        /* Obtener LOGO de la emisora (fallback robusto) */
        $logoSrc = null;
        if (!empty($cab['id_emisora'])) {
            $stmtLogo = $pdo->prepare("SELECT logo_base64 FROM dbo.cotizacion_empresa_emisora WHERE id_emisora = ?");
            $stmtLogo->bindValue(1, (int)$cab['id_emisora'], PDO::PARAM_INT);
            $stmtLogo->execute();
            $logoBase64 = $stmtLogo->fetchColumn();
            if (!empty($logoBase64)) {
                $logoSrc = $logoBase64;
            }
        }
        if (empty($logoSrc) && !empty($cab['emisora_logo'])) {
            $logoSrc = $cab['emisora_logo'];
        }

        /* Obtener TODAS las cuentas bancarias activas de la emisora */
        $cuentasBancarias = [];
        if (!empty($cab['id_emisora'])) {
            $stmtCuentas = $pdo->prepare(
                "SELECT id_cuenta, banco, numero_cuenta, cci, moneda, titular, orden
                 FROM dbo.cotizacion_cuenta_bancaria
                 WHERE id_emisora = ?
                   AND activo = 1
                 ORDER BY orden, id_cuenta"
            );
            $stmtCuentas->bindValue(1, (int)$cab['id_emisora'], PDO::PARAM_INT);
            $stmtCuentas->execute();
            $cuentasBancarias = $stmtCuentas->fetchAll();
        }

        /* Helpers */
        $fmtMoneda = function($val, $moneda = 'PEN') {
            $simbolo = ['PEN' => 'S/', 'USD' => '$', 'EUR' => '€'];
            $s = $simbolo[$moneda] ?? $moneda . ' ';
            return $s . ' ' . number_format((float)$val, 2, '.', ',');
        };
        $esc = function($s) {
            return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
        };

        /* ===== Generar HTML con LOGO para PDF y vista HTML ===== */
        if ($formato === 'pdf' || $formato === 'html') {

            ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= $esc($cab['titulo_documento'] ?: 'Cotización') ?> · <?= $esc($cab['codigo']) ?></title>
<style>
  @page { size: A4; margin: 14mm 12mm; }
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color: #2c3e50; margin: 0; padding: 0; font-size: 12px; line-height: 1.45; }
  .wrap { max-width: 210mm; margin: 0 auto; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #2c3e50; padding-bottom: 10px; margin-bottom: 14px; gap: 20px; }
  .emisora { display: flex; gap: 14px; align-items: flex-start; }
  .logo-box { width: 160px; height: 80px; border: 1px solid #bdc3c7; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fff; }
  .logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
  .logo-box .sin-logo { color: #bdc3c7; font-size: 10px; text-align: center; }
  .emisora-info strong { font-size: 14px; }
  .doc-meta { text-align: right; }
  .doc-meta h1 { margin: 0 0 4px 0; font-size: 18px; color: #2c3e50; }
  .doc-meta .codigo { font-size: 12px; }
  .doc-meta .meta { font-size: 11px; color: #7f8c8d; margin-top: 2px; }
  .cliente, .condiciones { border: 1px solid #ecf0f1; padding: 10px 12px; border-radius: 4px; margin-bottom: 12px; }
  .cliente h3, .condiciones h3, .cuentas-section h3 { margin: 0 0 8px 0; font-size: 12px; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #ecf0f1; padding-bottom: 4px; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 14px; font-size: 11px; }
  .grid2 span { color: #7f8c8d; }
  .grid2 strong { color: #2c3e50; }
  .detalle { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 4px; }
  .detalle thead th { background: #2c3e50; color: #fff; padding: 7px 6px; text-align: left; font-weight: 600; }
  .detalle thead th.num { text-align: right; }
  .detalle tbody td { padding: 6px; border-bottom: 1px solid #ecf0f1; }
  .detalle tbody td.num { text-align: right; }
  .totales-wrap { display: flex; justify-content: flex-end; margin-top: 10px; }
  .totales { width: 280px; font-size: 11px; }
  .totales .linea { display: flex; justify-content: space-between; padding: 3px 0; }
  .totales .gran-total { border-top: 2px solid #2c3e50; margin-top: 4px; padding-top: 6px; font-size: 14px; font-weight: 700; }
  .cuentas-section { margin-top: 12px; border: 1px solid #ecf0f1; border-radius: 4px; padding: 10px 12px; background: #fafbfc; }
  .cuentas-lista { display: grid; gap: 6px; font-size: 11px; }
  .cuenta-item { display: grid; grid-template-columns: 110px 1fr 90px; gap: 6px; padding: 5px 8px; background: #fff; border: 1px solid #ecf0f1; border-radius: 3px; }
  .cuenta-item .banco { font-weight: 600; }
  .cuenta-item .numero { font-family: 'Consolas', monospace; color: #2c3e50; }
  .cuenta-item .moneda { text-align: right; color: #7f8c8d; }
  .firma-area { margin-top: 30px; display: flex; justify-content: space-between; gap: 30px; }
  .firma { flex: 1; text-align: center; }
  .firma .line { border-top: 1px solid #2c3e50; padding-top: 4px; font-size: 11px; }
  .pie { margin-top: 20px; padding-top: 8px; border-top: 1px solid #ecf0f1; font-size: 10px; color: #95a5a6; text-align: center; }
  .acciones { margin-top: 20px; text-align: center; }
  .acciones button { background: #007bff; color: #fff; border: 0; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-size: 12px; }
  @media print { .acciones { display: none; } }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <div class="emisora">
      <div class="logo-box">
        <?php if ($logoSrc): ?>
          <img src="<?= $esc($logoSrc) ?>" alt="Logo" />
        <?php else: ?>
          <span class="sin-logo">(Sin logo)</span>
        <?php endif; ?>
      </div>
      <div class="emisora-info">
        <strong><?= $esc($cab['emisora_razon_social']) ?></strong><br>
        <?php if (!empty($cab['emisora_ruc'])): ?>RUC: <?= $esc($cab['emisora_ruc']) ?><br><?php endif; ?>
        <?php if (!empty($cab['emisora_direccion'])): ?><?= $esc($cab['emisora_direccion']) ?><br><?php endif; ?>
        <?php if (!empty($cab['emisora_telefono']) || !empty($cab['emisora_correo'])): ?>
          <?= $esc($cab['emisora_telefono']) ?><?= !empty($cab['emisora_correo']) ? ' · ' . $esc($cab['emisora_correo']) : '' ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="doc-meta">
      <h1><?= $esc($cab['titulo_documento'] ?: 'Cotización') ?></h1>
      <div class="codigo">Código: <strong><?= $esc($cab['codigo']) ?></strong></div>
      <div class="meta">Versión <?= (int)$cab['version_numero'] ?> · Emitida: <?= date('d/m/Y', strtotime($cab['fecha_creacion'])) ?></div>
    </div>
  </div>

  <div class="cliente">
    <h3>Cliente</h3>
    <div class="grid2">
      <div><span>Empresa:</span> <strong><?= $esc($cab['empresa_razon_social']) ?></strong></div>
      <div><span>RUC:</span> <strong><?= $esc($cab['empresa_ruc']) ?></strong></div>
      <?php if (!empty($cab['empresa_nombre_comercial'])): ?><div><span>Nombre comercial:</span> <?= $esc($cab['empresa_nombre_comercial']) ?></div><?php endif; ?>
      <?php if (!empty($cab['empresa_correo'])): ?><div><span>Correo:</span> <?= $esc($cab['empresa_correo']) ?></div><?php endif; ?>
      <?php if (!empty($cab['empresa_telefono'])): ?><div><span>Teléfono:</span> <?= $esc($cab['empresa_telefono']) ?></div><?php endif; ?>
      <?php if (!empty($cab['contacto_nombres'])): ?><div><span>Contacto:</span> <?= $esc($cab['contacto_nombres'] . ' ' . $cab['contacto_apellidos']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="condiciones">
    <h3>Condiciones comerciales</h3>
    <div class="grid2">
      <div><span>Moneda:</span> <strong><?= $esc($cab['moneda']) ?></strong></div>
      <div><span>IGV:</span> <strong><?= number_format((float)$cab['igv_pct'], 2) ?>%</strong></div>
      <?php if (!empty($cab['snapshot_forma_pago'])): ?><div><span>Forma de pago:</span> <strong><?= $esc($cab['snapshot_forma_pago']) ?></strong></div><?php endif; ?>
      <?php if (!empty($cab['snapshot_validez'])): ?><div><span>Validez:</span> <strong><?= $esc($cab['snapshot_validez']) ?></strong></div><?php endif; ?>
      <?php if (!empty($cab['snapshot_plazo_entrega'])): ?><div><span>Plazo de entrega:</span> <strong><?= $esc($cab['snapshot_plazo_entrega']) ?></strong></div><?php endif; ?>
      <?php if (!empty($cab['snapshot_garantia'])): ?><div><span>Garantía:</span> <strong><?= $esc($cab['snapshot_garantia']) ?></strong></div><?php endif; ?>
    </div>
  </div>

  <div class="cuentas-section">
    <h3>Cuentas bancarias</h3>
    <div class="cuentas-lista">
      <?php if (empty($cuentasBancarias)): ?>
        <div style="font-size:11px;color:#95a5a6;text-align:center;padding:8px;">(Sin cuentas bancarias configuradas)</div>
      <?php else: ?>
        <?php foreach ($cuentasBancarias as $cu): ?>
          <div class="cuenta-item">
            <div><div class="banco"><?= $esc($cu['banco']) ?></div><?php if (!empty($cu['titular'])): ?><div style="font-size:10px;color:#7f8c8d;"><?= $esc($cu['titular']) ?></div><?php endif; ?></div>
            <div><div class="numero">Cuenta: <?= $esc($cu['numero_cuenta']) ?></div><?php if (!empty($cu['cci'])): ?><div style="font-size:10px;color:#95a5a6;">CCI: <?= $esc($cu['cci']) ?></div><?php endif; ?></div>
            <div class="moneda"><?= $esc($cu['moneda']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <table class="detalle">
    <thead><tr><th>#</th><th>Código</th><th>Descripción</th><th class="num">Cant.</th><th class="num">P. Unit.</th><th class="num">Desc.%</th><th class="num">Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($detalle as $i => $d): ?>
        <tr><td><?= ($i + 1) ?></td><td style="font-family:monospace;color:#7f8c8d;"><?= $esc($d['codigo']) ?></td><td><?= nl2br($esc($d['descripcion'])) ?></td><td class="num"><?= number_format((float)$d['cantidad'], 2) ?></td><td class="num"><?= $fmtMoneda($d['precio_unitario'], $cab['moneda']) ?></td><td class="num"><?= number_format((float)$d['descuento_pct'], 2) ?></td><td class="num"><?= $fmtMoneda($d['subtotal'], $cab['moneda']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (empty($detalle)): ?><tr><td colspan="7" style="text-align:center;color:#95a5a6;padding:14px;">(Sin items)</td></tr><?php endif; ?>
    </tbody>
  </table>

  <div class="totales-wrap"><div class="totales">
    <div class="linea"><span>Subtotal</span><span><?= $fmtMoneda($cab['subtotal'], $cab['moneda']) ?></span></div>
    <div class="linea"><span>Descuento (<?= number_format((float)$cab['descuento_global_pct'], 2) ?>%)</span><span>- <?= $fmtMoneda($cab['descuento_global_monto'], $cab['moneda']) ?></span></div>
    <div class="linea"><span>Base imponible</span><span><?= $fmtMoneda($cab['base_imponible'], $cab['moneda']) ?></span></div>
    <div class="linea"><span>IGV (<?= number_format((float)$cab['igv_pct'], 2) ?>%)</span><span><?= $fmtMoneda($cab['igv_monto'], $cab['moneda']) ?></span></div>
    <div class="linea gran-total"><span>TOTAL</span><span><?= $fmtMoneda($cab['total'], $cab['moneda']) ?></span></div>
  </div></div>

  <?php if (!empty($cab['observaciones'])): ?><div style="margin-top:14px;padding:8px 12px;background:#fff8e1;border-left:3px solid #ffc107;font-size:11px;"><strong>Observaciones:</strong><br><?= nl2br($esc($cab['observaciones'])) ?></div><?php endif; ?>

  <div class="firma-area">
    <div class="firma"><div class="line">Aceptado por el Cliente</div><div style="font-size:10px;color:#95a5a6;">Nombre, firma y sello</div></div>
    <div class="firma"><div class="line"><?= $esc($cab['emisora_razon_social']) ?></div><div style="font-size:10px;color:#95a5a6;">Vendedor: <?= $esc($cab['vendedor_nombre'] ?? $cab['usuario_creador_nombre'] ?? '—') ?></div></div>
  </div>

  <div class="pie">Esta cotización tiene carácter informativo y no constituye una oferta vinculante hasta su aceptación formal por parte de <strong><?= $esc($cab['emisora_razon_social']) ?></strong>.<br>Generado el <?= date('d/m/Y H:i:s') ?> · Sistema MonosyncERP</div>

  <div class="acciones"><button onclick="window.print()">🖨 Imprimir / Guardar PDF</button></div>
</div>
</body>
</html>
<?php
            $html = ob_get_clean();

            if ($formato === 'pdf') {
                /* Para "PDF" devolvemos HTML imprimible (el navegador lo convierte) */
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: private, no-cache');
                http_response_code(200);
                echo $html;
                exit;
            } else {
                /* HTML para descarga */
                header('Content-Type: text/html; charset=utf-8');
                header('Content-Disposition: attachment; filename="cotizacion_' . $cab['codigo'] . '.html"');
                echo $html;
                exit;
            }
        }

        /* ===== XLSX con LOGO ===== */
        if ($formato === 'xlsx') {
            /* Requiere PhpSpreadsheet */
            $autoload = __DIR__ . '/../../vendor/autoload.php';
            if (!is_file($autoload)) {
                ApiResponse::error('xlsx_unavailable', 'PhpSpreadsheet no está instalado', 503);
            }
            require_once $autoload;

            use PhpOffice\PhpSpreadsheet\Spreadsheet;
            use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
            use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Cotización');

            /* Insertar el LOGO como imagen en A1 */
            if ($logoSrc) {
                /* Parsear data URL: data:image/png;base64,XXXX */
                if (preg_match('#^data:(image/([a-z+]+));base64,(.+)$#i', $logoSrc, $m)) {
                    $mimeImg = $m[1];
                    $extImg  = $m[2] === 'jpeg' ? 'jpg' : $m[2];
                    $binImg  = base64_decode($m[3]);
                    if ($binImg) {
                        $tempFile = tempnam(sys_get_temp_dir(), 'logo_') . '.' . $extImg;
                        file_put_contents($tempFile, $binImg);

                        $drawing = new Drawing();
                        $drawing->setName('Logo');
                        $drawing->setDescription('Logo de la empresa');
                        $drawing->setPath($tempFile);
                        $drawing->setHeight(60);
                        $drawing->setCoordinates('A1');
                        $drawing->setWorksheet($sheet);
                    }
                }
            }

            /* Datos de la emisora */
            $sheet->setCellValue('A8', $cab['emisora_razon_social']);
            $sheet->setCellValue('A9', 'RUC: ' . $cab['emisora_ruc']);
            $sheet->setCellValue('A10', $cab['emisora_direccion'] ?? '');
            $sheet->setCellValue('A11', 'Tel: ' . ($cab['emisora_telefono'] ?? '') . ' Email: ' . ($cab['emisora_correo'] ?? ''));

            /* Datos del documento */
            $sheet->setCellValue('E8', 'COTIZACIÓN');
            $sheet->setCellValue('E9', 'Código: ' . $cab['codigo']);
            $sheet->setCellValue('E10', 'Fecha: ' . date('d/m/Y', strtotime($cab['fecha_creacion'])));
            $sheet->setCellValue('E11', 'Versión: ' . $cab['version_numero']);

            /* Cliente */
            $sheet->setCellValue('A13', 'CLIENTE');
            $sheet->setCellValue('A14', 'Empresa:');
            $sheet->setCellValue('B14', $cab['empresa_razon_social']);
            $sheet->setCellValue('A15', 'RUC:');
            $sheet->setCellValue('B15', $cab['empresa_ruc']);

            /* Detalle */
            $sheet->setCellValue('A17', '#');
            $sheet->setCellValue('B17', 'Código');
            $sheet->setCellValue('C17', 'Descripción');
            $sheet->setCellValue('D17', 'Cant.');
            $sheet->setCellValue('E17', 'P. Unit.');
            $sheet->setCellValue('F17', 'Desc.%');
            $sheet->setCellValue('G17', 'Subtotal');

            $row = 18;
            foreach ($detalle as $i => $d) {
                $sheet->setCellValue('A' . $row, $i + 1);
                $sheet->setCellValue('B' . $row, $d['codigo']);
                $sheet->setCellValue('C' . $row, $d['descripcion']);
                $sheet->setCellValue('D' . $row, (float)$d['cantidad']);
                $sheet->setCellValue('E' . $row, (float)$d['precio_unitario']);
                $sheet->setCellValue('F' . $row, (float)$d['descuento_pct']);
                $sheet->setCellValue('G' . $row, (float)$d['subtotal']);
                $row++;
            }

            /* Totales */
            $row += 1;
            $sheet->setCellValue('F' . $row, 'Subtotal:');
            $sheet->setCellValue('G' . $row, (float)$cab['subtotal']);
            $row++;
            $sheet->setCellValue('F' . $row, 'IGV (' . $cab['igv_pct'] . '%):');
            $sheet->setCellValue('G' . $row, (float)$cab['igv_monto']);
            $row++;
            $sheet->setCellValue('F' . $row, 'TOTAL:');
            $sheet->setCellValue('G' . $row, (float)$cab['total']);
            $sheet->getStyle('F' . $row . ':G' . $row)->getFont()->setBold(true);

            /* Cuentas bancarias */
            $row += 2;
            $sheet->setCellValue('A' . $row, 'CUENTAS BANCARIAS');
            $row++;
            foreach ($cuentasBancarias as $cu) {
                $sheet->setCellValue('A' . $row, $cu['banco']);
                $sheet->setCellValue('B' . $row, 'Cuenta: ' . $cu['numero_cuenta']);
                $sheet->setCellValue('D' . $row, $cu['cci'] ? 'CCI: ' . $cu['cci'] : '');
                $sheet->setCellValue('F' . $row, $cu['moneda']);
                $row++;
            }

            /* Enviar al navegador */
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="cotizacion_' . $cab['codigo'] . '.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
            exit;
        }

        /* ===== CSV ===== */
        if ($formato === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="cotizacion_' . $cab['codigo'] . '.csv"');
            echo "\xEF\xBB\xBF"; // BOM UTF-8

            $out = fopen('php://output', 'w');
            fputcsv($out, ['COTIZACIÓN', $cab['codigo']]);
            fputcsv($out, ['Empresa emisora', $cab['emisora_razon_social']]);
            fputcsv($out, ['RUC', $cab['emisora_ruc']]);
            fputcsv($out, ['Fecha', date('d/m/Y', strtotime($cab['fecha_creacion']))]);
            fputcsv($out, ['Versión', $cab['version_numero']]);
            fputcsv($out, ['Logo cargado', $logoSrc ? 'SÍ (ver PDF/HTML/XLSX)' : 'NO']);
            fputcsv($out, []);
            fputcsv($out, ['CLIENTE']);
            fputcsv($out, ['Empresa', $cab['empresa_razon_social']]);
            fputcsv($out, ['RUC', $cab['empresa_ruc']]);
            fputcsv($out, []);
            fputcsv($out, ['DETALLE']);
            fputcsv($out, ['#', 'Código', 'Descripción', 'Cant.', 'P. Unit.', 'Desc.%', 'Subtotal']);
            foreach ($detalle as $i => $d) {
                fputcsv($out, [$i + 1, $d['codigo'], $d['descripcion'], $d['cantidad'], $d['precio_unitario'], $d['descuento_pct'], $d['subtotal']]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Subtotal', $cab['subtotal']]);
            fputcsv($out, ['IGV (' . $cab['igv_pct'] . '%)', $cab['igv_monto']]);
            fputcsv($out, ['TOTAL', $cab['total']]);
            fputcsv($out, []);
            fputcsv($out, ['CUENTAS BANCARIAS']);
            foreach ($cuentasBancarias as $cu) {
                fputcsv($out, [$cu['banco'], 'Cuenta: ' . $cu['numero_cuenta'], $cu['cci'] ? 'CCI: ' . $cu['cci'] : '', $cu['moneda']]);
            }
            fclose($out);
            exit;
        }

        ApiResponse::error('invalid_format', 'Formato no válido. Use pdf, html, xlsx o csv.', 400);

    } catch (Throwable $e) {
        error_log('[Export V8] Error: ' . $e->getMessage());
        ApiResponse::error('export_failed', $e->getMessage(), 500);
    }
}
