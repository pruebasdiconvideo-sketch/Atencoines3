<?php
/**
 * api/routes/cotizaciones_print.php
 *
 * Generación de la vista de impresión de UNA cotización.
 *
 * Endpoints:
 *   GET  /cotizaciones_print/{id_cotizacion}             Vista HTML lista para imprimir
 *   GET  /cotizaciones_print/{id_cotizacion}/preview     Igual que GET, alias
 *
 *  - Devuelve HTML completo con CSS de impresión (@media print).
 *  - Incluye el LOGO de la empresa emisora (si lo tiene cargado).
 *  - Calcula totales y muestra detalle, condiciones comerciales y observaciones.
 *  - El usuario puede usar "Imprimir" del navegador (Ctrl+P) o "Guardar como PDF".
 *
 *  NOTA: este endpoint devuelve HTML, NO JSON. Está pensado para abrirse en una
 *  pestaña nueva o en un iframe oculto que se invoca con window.open().
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
        'Especifique el ID de la cotización: /cotizaciones_print/{id_cotizacion}', 400);
}

$idCot = (int)$idCot;
if ($idCot <= 0) {
    ApiResponse::error('invalid_id', 'ID inválido', 400);
}

try {
    $pdo = Database::connection();
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_get @id_cotizacion = ?");
    $stmt->bindValue(1, $idCot, PDO::PARAM_INT);
    $stmt->execute();

    $cab = $stmt->fetch();
    if (!$cab) {
        ApiResponse::error('not_found', 'Cotización no encontrada', 404);
    }

    $stmt->nextRowset();
    $detalle = $stmt->fetchAll();
    $stmt->nextRowset();
    $historial = $stmt->fetchAll();
    $stmt->nextRowset();
    $comunicaciones = $stmt->fetchAll();
    $stmt->nextRowset();
    $versiones = $stmt->fetchAll();
    $stmt->nextRowset();
    $documentos = $stmt->fetchAll();

    // Función helper
    $fmtMoneda = function($val, $moneda = 'PEN') {
        $simbolo = ['PEN' => 'S/', 'USD' => '$', 'EUR' => '€'];
        $s = $simbolo[$moneda] ?? $moneda . ' ';
        return $s . ' ' . number_format((float)$val, 2, '.', ',');
    };
    $fmtFecha = function($f) {
        if (empty($f)) return '—';
        try {
            $d = new DateTime($f);
            return $d->format('d/m/Y');
        } catch (Throwable $e) {
            return htmlspecialchars((string)$f);
        }
    };
    $esc = function($s) {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    };

    $logoSrc = !empty($cab['emisora_logo']) ? $cab['emisora_logo'] : null;

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
    $estadoColorTxt = [
        'EN_SEGUIMIENTO' => '#212529',
        'CANCELADA'      => '#fff',
        'CERRADA'        => '#fff',
    ];
    $colorEstado = $estadoColor[$cab['estado']] ?? '#6c757d';
    $colorTxtEstado = $estadoColorTxt[$cab['estado']] ?? '#fff';

    /* ============== Generar HTML ============== */
    ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= $esc($cab['codigo']) ?> - <?= $esc($cab['empresa_razon_social']) ?></title>
<style>
  /* ===== Reset mínimo ===== */
  * { box-sizing: border-box; }
  html, body {
    margin: 0; padding: 0;
    font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    font-size: 12px; color: #2c3e50;
    background: #f4f6f9;
  }

  /* ===== Contenedor del PDF ===== */
  .doc {
    background: #fff;
    max-width: 850px;
    margin: 24px auto;
    padding: 36px 48px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border-radius: 4px;
  }

  /* ===== Encabezado ===== */
  .header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 3px solid <?= $esc($cab['emisora_razon_social'] ? '#2c3e50' : '#999') ?>;
    padding-bottom: 18px;
    margin-bottom: 24px;
  }
  .header .logo { width: 220px; }
  .header .logo img { max-width: 100%; max-height: 90px; object-fit: contain; }
  .header .emisora-info { font-size: 11px; line-height: 1.55; color: #555; }
  .header .emisora-info strong { color: #2c3e50; font-size: 13px; }

  .doc-meta {
    text-align: right;
    min-width: 280px;
  }
  .doc-meta h1 {
    margin: 0 0 4px 0;
    font-size: 22px;
    color: #2c3e50;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .doc-meta .codigo { font-size: 14px; color: #7f8c8d; }
  .estado-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 8px;
    background: <?= $esc($colorEstado) ?>;
    color: <?= $esc($colorTxtEstado) ?>;
  }

  /* ===== Cliente ===== */
  .cliente, .condiciones {
    background: #f9fafc;
    padding: 14px 16px;
    border-radius: 4px;
    margin: 14px 0;
  }
  .cliente h3, .condiciones h3 {
    margin: 0 0 8px 0;
    font-size: 12px;
    text-transform: uppercase;
    color: #95a5a6;
    letter-spacing: 0.5px;
  }
  .cliente-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; font-size: 11.5px; }
  .cliente-grid div span { color: #95a5a6; }
  .cliente-grid div strong { color: #2c3e50; font-weight: 600; }

  /* ===== Tabla de detalle ===== */
  table.detalle { width: 100%; border-collapse: collapse; margin: 18px 0; font-size: 11.5px; }
  table.detalle thead th {
    background: #2c3e50;
    color: #fff;
    padding: 10px 8px;
    text-align: left;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }
  table.detalle thead th.num { text-align: right; }
  table.detalle tbody td {
    padding: 8px;
    border-bottom: 1px solid #eaeaea;
    vertical-align: top;
  }
  table.detalle tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
  table.detalle tbody tr:nth-child(even) td { background: #fafbfc; }
  table.detalle .codigo { font-family: "Courier New", monospace; color: #7f8c8d; font-size: 10.5px; }
  table.detalle .descripcion { color: #2c3e50; }

  /* ===== Totales ===== */
  .totales-wrap { display: flex; justify-content: flex-end; margin-top: 18px; }
  .totales {
    width: 320px;
    border-top: 2px solid #2c3e50;
    padding-top: 10px;
  }
  .totales .linea {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    font-size: 11.5px;
    color: #555;
  }
  .totales .linea.gran-total {
    margin-top: 8px;
    padding-top: 10px;
    border-top: 2px solid #2c3e50;
    font-size: 16px;
    font-weight: 700;
    color: #2c3e50;
  }

  /* ===== Condiciones comerciales ===== */
  .condiciones-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; font-size: 11.5px; }
  .condiciones-grid div span { color: #95a5a6; }
  .condiciones-grid div strong { color: #2c3e50; }

  /* ===== Pie ===== */
  .pie {
    margin-top: 32px;
    padding-top: 16px;
    border-top: 1px solid #eaeaea;
    font-size: 10.5px;
    color: #95a5a6;
    text-align: center;
    line-height: 1.6;
  }
  .pie strong { color: #2c3e50; }

  .firma-area {
    display: flex; justify-content: space-around;
    margin-top: 36px;
  }
  .firma {
    width: 240px;
    text-align: center;
    font-size: 11px;
    color: #555;
  }
  .firma .line { border-top: 1px solid #555; margin-top: 36px; padding-top: 6px; }

  .acciones {
    text-align: right;
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px dashed #ccc;
  }
  .acciones button {
    background: #2c3e50;
    color: #fff;
    border: 0;
    padding: 8px 18px;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    margin-left: 8px;
  }
  .acciones button:hover { background: #34495e; }

  /* ===== Print ===== */
  @media print {
    body { background: #fff; }
    .doc { box-shadow: none; margin: 0; padding: 0; max-width: 100%; }
    .acciones { display: none !important; }
    @page { margin: 1.5cm; }
  }
</style>
</head>
<body>
<div class="doc">

  <!-- ENCABEZADO -->
  <div class="header">
    <div class="logo">
      <?php if ($logoSrc): ?>
        <img src="<?= $esc($logoSrc) ?>" alt="Logo <?= $esc($cab['emisora_razon_social']) ?>" />
      <?php else: ?>
        <div style="font-size:18px;font-weight:700;color:#2c3e50;line-height:1.3;">
          <?= $esc($cab['emisora_razon_social']) ?>
        </div>
        <div style="font-size:10px;color:#95a5a6;margin-top:4px;">
          (Sin logo cargado)
        </div>
      <?php endif; ?>
      <div class="emisora-info" style="margin-top:8px;">
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
      <div style="font-size:11px;color:#7f8c8d;margin-top:2px;">Versión <?= (int)$cab['version_numero'] ?> · Emitida: <?= $fmtFecha($cab['fecha_creacion']) ?></div>
      <div class="estado-badge"><?= $esc($cab['estado']) ?></div>
    </div>
  </div>

  <!-- CLIENTE -->
  <div class="cliente">
    <h3>Cliente</h3>
    <div class="cliente-grid">
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
    <div class="condiciones-grid">
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
      <?php if (!empty($cab['snapshot_cuenta_bancaria'])): ?>
        <div style="grid-column: span 2;"><span>Cuenta bancaria:</span> <strong><?= nl2br($esc($cab['snapshot_cuenta_bancaria'])) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- DETALLE -->
  <table class="detalle">
    <thead>
      <tr>
        <th style="width:40px;">#</th>
        <th style="width:100px;">Código</th>
        <th>Descripción</th>
        <th class="num" style="width:60px;">Cant.</th>
        <th class="num" style="width:90px;">P. Unit.</th>
        <th class="num" style="width:60px;">Desc.%</th>
        <th class="num" style="width:100px;">Subtotal</th>
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
        <tr><td colspan="7" style="text-align:center;color:#95a5a6;padding:18px;">(Sin items en el detalle)</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- TOTALES -->
  <div class="totales-wrap">
    <div class="totales">
      <div class="linea"><span>Subtotal</span><span><?= $fmtMoneda($cab['subtotal'], $cab['moneda']) ?></span></div>
      <div class="linea"><span>Descuento global (<?= number_format((float)$cab['descuento_global_pct'], 2) ?>%)</span>
        <span>- <?= $fmtMoneda($cab['descuento_global_monto'], $cab['moneda']) ?></span></div>
      <div class="linea"><span>Base imponible</span><span><?= $fmtMoneda($cab['base_imponible'], $cab['moneda']) ?></span></div>
      <div class="linea"><span>IGV (<?= number_format((float)$cab['igv_pct'], 2) ?>%)</span><span><?= $fmtMoneda($cab['igv_monto'], $cab['moneda']) ?></span></div>
      <div class="linea gran-total"><span>TOTAL</span><span><?= $fmtMoneda($cab['total'], $cab['moneda']) ?></span></div>
    </div>
  </div>

  <?php if (!empty($cab['observaciones'])): ?>
    <div style="margin-top:20px;padding:10px 14px;background:#fff8e1;border-left:3px solid #ffc107;font-size:11px;">
      <strong style="color:#f57c00;">Observaciones:</strong><br>
      <?= nl2br($esc($cab['observaciones'])) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($cab['motivo'])): ?>
    <div style="margin-top:10px;font-size:11px;color:#555;">
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

  <!-- ACCIONES (solo pantalla, no se imprimen) -->
  <div class="acciones">
    <button onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
  </div>

</div>

<script>
  // Auto-abrir diálogo de impresión si viene con ?auto=1
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
    error_log('[Cotizaciones Print] error: ' . $e->getMessage());
    ApiResponse::error('print_failed', $e->getMessage(), 500);
}
