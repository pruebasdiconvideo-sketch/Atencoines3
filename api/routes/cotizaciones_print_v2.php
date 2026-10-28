<?php
/**
 * api/routes/cotizaciones_print_v2.php
 * ----------------------------------------------------------------------------
 * VERSIÓN MEJORADA de la vista de impresión / PDF de cotizaciones.
 *
 * MEJORAS vs. cotizaciones_print.php:
 *   1. NO muestra el estado interno de aprobación (BORRADOR, APROBADA,
 *      RECHAZADA, etc.) en el documento generado. Esa información es
 *      interna del sistema y no debe exponerse al cliente.
 *   2. Muestra AUTOMÁTICAMENTE las 3 cuentas bancarias activas de la
 *      empresa emisora, sin necesidad de seleccionarlas en un combo.
 *   3. Mantiene el logo de la empresa emisora (si está cargado).
 *   4. Mantiene la estructura visual del documento original.
 *
 * ENDPOINT:
 *   GET  /cotizaciones_print_v2/{id_cotizacion}        Vista HTML imprimible
 *   GET  /cotizaciones_print_v2/{id_cotizacion}/pdf    Alias (mismo HTML)
 *
 * NOTA: Devuelve HTML, no JSON. El usuario puede imprimir (Ctrl+P) o
 *       "Guardar como PDF" desde el navegador.
 */

Auth::requirePermission('COTIZACIONES', 'imprimir');

$method = $_SERVER['REQUEST_METHOD'];
$idCot  = $segments[1] ?? null;
$action = $segments[2] ?? null;

if ($method !== 'GET') {
    ApiResponse::error('method_not_allowed', 'Solo GET permitido', 405);
}

if ($idCot === null) {
    ApiResponse::error('invalid_request',
        'Especifique el ID: /cotizaciones_print_v2/{id_cotizacion}', 400);
}

$idCot = (int)$idCot;
if ($idCot <= 0) ApiResponse::error('invalid_id', 'ID inválido', 400);

try {
    $pdo = Database::connection();
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_get @id_cotizacion = ?");
    $stmt->bindValue(1, $idCot, PDO::PARAM_INT);
    $stmt->execute();

    $cab = $stmt->fetch();
    if (!$cab) ApiResponse::error('not_found', 'Cotización no encontrada', 404);

    $stmt->nextRowset(); $detalle        = $stmt->fetchAll();
    $stmt->nextRowset(); $historial      = $stmt->fetchAll();
    $stmt->nextRowset(); $comunicaciones = $stmt->fetchAll();
    $stmt->nextRowset(); $versiones      = $stmt->fetchAll();
    $stmt->nextRowset(); $documentos     = $stmt->fetchAll();

    /* ---- Cargar las 3 cuentas bancarias activas de la emisora ---- */
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

    /* ---- Helpers ---- */
    $fmtMoneda = function($val, $moneda = 'PEN') {
        $simbolo = ['PEN' => 'S/', 'USD' => '$', 'EUR' => '€'];
        $s = $simbolo[$moneda] ?? $moneda . ' ';
        return $s . ' ' . number_format((float)$val, 2, '.', ',');
    };
    $fmtFecha = function($f) {
        if (empty($f)) return '—';
        try { return (new DateTime($f))->format('d/m/Y'); }
        catch (Throwable $e) { return htmlspecialchars((string)$f); }
    };
    $esc = function($s) {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    };

    $logoSrc = !empty($cab['emisora_logo']) ? $cab['emisora_logo'] : null;

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
  body {
    font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
    color: #2c3e50; margin: 0; padding: 0;
    font-size: 12px; line-height: 1.45;
  }
  .wrap { max-width: 210mm; margin: 0 auto; padding: 0; }
  .header {
    display: flex; justify-content: space-between;
    align-items: flex-start; border-bottom: 2px solid #2c3e50;
    padding-bottom: 10px; margin-bottom: 14px; gap: 20px;
  }
  .emisora { display: flex; gap: 12px; align-items: flex-start; }
  .logo-box {
    width: 120px; height: 70px; border: 1px dashed #bdc3c7;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
  }
  .logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
  .emisora-info strong { font-size: 14px; }
  .doc-meta { text-align: right; }
  .doc-meta h1 { margin: 0 0 4px 0; font-size: 18px; color: #2c3e50; }
  .doc-meta .codigo { font-size: 12px; }
  .doc-meta .meta { font-size: 11px; color: #7f8c8d; margin-top: 2px; }
  /* NO se muestra estado de aprobación en el documento */

  .cliente, .condiciones {
    border: 1px solid #ecf0f1; padding: 10px 12px;
    border-radius: 4px; margin-bottom: 12px;
  }
  .cliente h3, .condiciones h3 {
    margin: 0 0 8px 0; font-size: 12px; color: #2c3e50;
    text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 1px solid #ecf0f1; padding-bottom: 4px;
  }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 14px; font-size: 11px; }
  .grid2 span { color: #7f8c8d; }
  .grid2 strong { color: #2c3e50; }

  .detalle {
    width: 100%; border-collapse: collapse;
    font-size: 11px; margin-top: 4px;
  }
  .detalle thead th {
    background: #2c3e50; color: #fff; padding: 7px 6px;
    text-align: left; font-weight: 600;
  }
  .detalle thead th.num { text-align: right; }
  .detalle tbody td { padding: 6px; border-bottom: 1px solid #ecf0f1; }
  .detalle tbody td.num { text-align: right; }
  .detalle tbody td.codigo { font-family: 'Consolas', monospace; color: #7f8c8d; }
  .detalle tbody td.descripcion { line-height: 1.35; }

  .totales-wrap { display: flex; justify-content: flex-end; margin-top: 10px; }
  .totales { width: 280px; font-size: 11px; }
  .totales .linea { display: flex; justify-content: space-between; padding: 3px 0; }
  .totales .gran-total {
    border-top: 2px solid #2c3e50; margin-top: 4px; padding-top: 6px;
    font-size: 14px; font-weight: 700;
  }

  /* ---- Cuentas bancarias: lista vertical de las 3 ---- */
  .cuentas-bancarias {
    margin-top: 12px; border: 1px solid #ecf0f1; border-radius: 4px;
    padding: 10px 12px; background: #fafbfc;
  }
  .cuentas-bancarias h3 {
    margin: 0 0 8px 0; font-size: 12px; color: #2c3e50;
    text-transform: uppercase; letter-spacing: 0.5px;
  }
  .cuentas-lista { display: grid; gap: 6px; font-size: 11px; }
  .cuenta-item {
    display: grid; grid-template-columns: 110px 1fr 90px;
    gap: 6px; padding: 4px 6px; background: #fff;
    border: 1px solid #ecf0f1; border-radius: 3px;
  }
  .cuenta-item .banco { font-weight: 600; }
  .cuenta-item .numero { font-family: 'Consolas', monospace; color: #2c3e50; }
  .cuenta-item .moneda { text-align: right; color: #7f8c8d; }
  .cuenta-item .cci { color: #95a5a6; font-size: 10px; margin-top: 2px; }

  .firma-area {
    margin-top: 30px; display: flex; justify-content: space-between;
    gap: 30px;
  }
  .firma { flex: 1; text-align: center; }
  .firma .line {
    border-top: 1px solid #2c3e50; padding-top: 4px;
    font-size: 11px; color: #2c3e50;
  }

  .pie {
    margin-top: 20px; padding-top: 8px;
    border-top: 1px solid #ecf0f1; font-size: 10px; color: #95a5a6;
    text-align: center;
  }

  .acciones { margin-top: 20px; text-align: center; }
  .acciones button {
    background: #007bff; color: #fff; border: 0;
    padding: 8px 18px; border-radius: 4px; cursor: pointer;
    font-size: 12px;
  }
  @media print {
    .acciones { display: none; }
    body { font-size: 11px; }
  }
</style>
</head>
<body>
<div class="wrap">

  <!-- ENCABEZADO (sin estado de aprobación) -->
  <div class="header">
    <div class="emisora">
      <?php if ($logoSrc): ?>
        <div class="logo-box"><img src="<?= $esc($logoSrc) ?>" alt="logo"></div>
      <?php else: ?>
        <div class="logo-box"><span style="color:#bdc3c7;font-size:10px;">(Sin logo)</span></div>
      <?php endif; ?>
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
      <div class="meta">Versión <?= (int)$cab['version_numero'] ?> · Emitida: <?= $fmtFecha($cab['fecha_creacion']) ?></div>
      <!-- NO se muestra el estado interno (APROBADA, RECHAZADA, etc.) -->
    </div>
  </div>

  <!-- CLIENTE -->
  <div class="cliente">
    <h3>Cliente</h3>
    <div class="grid2">
      <div><span>Empresa:</span> <strong><?= $esc($cab['empresa_razon_social']) ?></strong></div>
      <div><span>RUC:</span> <strong><?= $esc($cab['empresa_ruc']) ?></strong></div>
      <?php if (!empty($cab['empresa_nombre_comercial'])): ?>
        <div><span>Nombre comercial:</span> <?= $esc($cab['empresa_nombre_comercial']) ?></div>
      <?php endif; ?>
      <?php if (!empty($cab['empresa_correo'])): ?>
        <div><span>Correo:</span> <?= $esc($cab['empresa_correo']) ?></div>
      <?php endif; ?>
      <?php if (!empty($cab['empresa_telefono'])): ?>
        <div><span>Teléfono:</span> <?= $esc($cab['empresa_telefono']) ?></div>
      <?php endif; ?>
      <?php if (!empty($cab['contacto_nombres'])): ?>
        <div><span>Contacto:</span> <?= $esc($cab['contacto_nombres'] . ' ' . $cab['contacto_apellidos']) ?></div>
      <?php endif; ?>
      <?php if (!empty($cab['contacto_correo'])): ?>
        <div><span>Correo contacto:</span> <?= $esc($cab['contacto_correo']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- CONDICIONES COMERCIALES -->
  <div class="condiciones">
    <h3>Condiciones comerciales</h3>
    <div class="grid2">
      <div><span>Moneda:</span> <strong><?= $esc($cab['moneda']) ?></strong></div>
      <div><span>IGV:</span> <strong><?= number_format((float)$cab['igv_pct'], 2) ?>%</strong></div>
      <?php if (!empty($cab['snapshot_forma_pago'])): ?>
        <div><span>Forma de pago:</span> <strong><?= $esc($cab['snapshot_forma_pago']) ?></strong></div>
      <?php endif; ?>
      <?php if (!empty($cab['snapshot_validez'])): ?>
        <div><span>Validez:</span> <strong><?= $esc($cab['snapshot_validez']) ?></strong></div>
      <?php endif; ?>
      <?php if (!empty($cab['snapshot_plazo_entrega'])): ?>
        <div><span>Plazo de entrega:</span> <strong><?= $esc($cab['snapshot_plazo_entrega']) ?></strong></div>
      <?php endif; ?>
      <?php if (!empty($cab['snapshot_garantia'])): ?>
        <div><span>Garantía:</span> <strong><?= $esc($cab['snapshot_garantia']) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- CUENTAS BANCARIAS (las 3 automáticamente) -->
  <div class="cuentas-bancarias">
    <h3>Cuentas bancarias</h3>
    <div class="cuentas-lista">
      <?php if (empty($cuentasBancarias)): ?>
        <div style="font-size:11px;color:#95a5a6;text-align:center;padding:8px;">
          (Sin cuentas bancarias configuradas para esta emisora)
        </div>
      <?php else: ?>
        <?php foreach ($cuentasBancarias as $i => $cu): ?>
          <div class="cuenta-item">
            <div>
              <div class="banco"><?= $esc($cu['banco']) ?></div>
              <?php if (!empty($cu['titular'])): ?>
                <div style="font-size:10px;color:#7f8c8d;"><?= $esc($cu['titular']) ?></div>
              <?php endif; ?>
            </div>
            <div>
              <div class="numero">Cuenta: <?= $esc($cu['numero_cuenta']) ?></div>
              <?php if (!empty($cu['cci'])): ?>
                <div class="cci">CCI: <?= $esc($cu['cci']) ?></div>
              <?php endif; ?>
            </div>
            <div class="moneda"><?= $esc($cu['moneda']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- DETALLE -->
  <table class="detalle">
    <thead>
      <tr>
        <th style="width:30px;">#</th>
        <th style="width:90px;">Código</th>
        <th>Descripción</th>
        <th class="num" style="width:55px;">Cant.</th>
        <th class="num" style="width:80px;">P. Unit.</th>
        <th class="num" style="width:50px;">Desc.%</th>
        <th class="num" style="width:95px;">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($detalle as $i => $d): ?>
        <tr>
          <td><?= ($i + 1) ?></td>
          <td class="codigo"><?= $esc($d['codigo']) ?></td>
          <td class="descripcion"><?= nl2br($esc($d['descripcion'])) ?>
            <?php if (!empty($d['unidad_medida'])): ?>
              <div style="font-size:10px;color:#95a5a6;">Unidad: <?= $esc($d['unidad_medida']) ?></div>
            <?php endif; ?>
          </td>
          <td class="num"><?= number_format((float)$d['cantidad'], 2) ?></td>
          <td class="num"><?= $fmtMoneda($d['precio_unitario'], $cab['moneda']) ?></td>
          <td class="num"><?= number_format((float)$d['descuento_pct'], 2) ?></td>
          <td class="num"><?= $fmtMoneda($d['subtotal'], $cab['moneda']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($detalle)): ?>
        <tr><td colspan="7" style="text-align:center;color:#95a5a6;padding:14px;">(Sin items)</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- TOTALES -->
  <div class="totales-wrap">
    <div class="totales">
      <div class="linea"><span>Subtotal</span><span><?= $fmtMoneda($cab['subtotal'], $cab['moneda']) ?></span></div>
      <div class="linea"><span>Descuento (<?= number_format((float)$cab['descuento_global_pct'], 2) ?>%)</span>
        <span>- <?= $fmtMoneda($cab['descuento_global_monto'], $cab['moneda']) ?></span></div>
      <div class="linea"><span>Base imponible</span><span><?= $fmtMoneda($cab['base_imponible'], $cab['moneda']) ?></span></div>
      <div class="linea"><span>IGV (<?= number_format((float)$cab['igv_pct'], 2) ?>%)</span><span><?= $fmtMoneda($cab['igv_monto'], $cab['moneda']) ?></span></div>
      <div class="linea gran-total"><span>TOTAL</span><span><?= $fmtMoneda($cab['total'], $cab['moneda']) ?></span></div>
    </div>
  </div>

  <?php if (!empty($cab['observaciones'])): ?>
    <div style="margin-top:14px;padding:8px 12px;background:#fff8e1;border-left:3px solid #ffc107;font-size:11px;">
      <strong style="color:#f57c00;">Observaciones:</strong><br>
      <?= nl2br($esc($cab['observaciones'])) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($cab['motivo'])): ?>
    <div style="margin-top:8px;font-size:11px;color:#555;">
      <strong>Motivo:</strong> <?= $esc($cab['motivo']) ?>
    </div>
  <?php endif; ?>

  <!-- FIRMAS -->
  <div class="firma-area">
    <div class="firma">
      <div class="line">Aceptado por el Cliente</div>
      <div style="font-size:10px;color:#95a5a6;">Nombre, firma y sello</div>
    </div>
    <div class="firma">
      <div class="line"><?= $esc($cab['emisora_razon_social']) ?></div>
      <div style="font-size:10px;color:#95a5a6;">Vendedor: <?= $esc($cab['vendedor_nombre'] ?? $cab['usuario_creador_nombre'] ?? '—') ?></div>
    </div>
  </div>

  <!-- PIE -->
  <div class="pie">
    Esta cotización tiene carácter informativo y no constituye una oferta vinculante hasta su
    aceptación formal por parte de <strong><?= $esc($cab['emisora_razon_social']) ?></strong>.<br>
    Generado el <?= date('d/m/Y H:i:s') ?> por <strong><?= $esc($cab['usuario_creador_nombre'] ?? '—') ?></strong>
    · Sistema MonosyncERP
  </div>

  <div class="acciones">
    <button onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
  </div>
</div>

<script>
  if (new URLSearchParams(location.search).get('auto') === '1') {
    window.addEventListener('load', function() {
      setTimeout(function() { window.print(); }, 300);
    });
  }
</script>
</body>
</html>
<?php
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-cache');
    http_response_code(200);
    echo $html;
    exit;
} catch (Throwable $e) {
    ApiResponse::error('print_failed', $e->getMessage(), 500);
}
