<?php
/**
 * nueva_cotizacion_v3.php — Página INDEPENDIENTE para crear cotizaciones
 * ----------------------------------------------------------------------------
 * Acceder directamente: http://su-servidor/nueva_cotizacion_v3.php
 *
 * CARACTERÍSTICAS:
 *   - Sin Zona de Pricing (eliminado del formulario)
 *   - Cuentas bancarias como CHECKBOXES múltiples (las 3 marcadas por defecto)
 *   - Solo 2 tipos: SERV (Soporte) y VENT (Ventas)
 *   - Código automático SERV-2026-001.1 (sin EMP)
 *   - Logo de la emisora visible en el encabezado del formulario
 *   - No depende del router SPA
 */

declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
startAppSession();

if (empty($_SESSION['user']['id_persona'])) {
    header('Location: /login.php');
    exit;
}

$userId = (int)$_SESSION['user']['id_persona'];
$pdo = Database::connection();

// Procesar POST (crear cotización)
$mensaje = '';
$mensajeTipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear') {
    try {
        $idEmpresa       = (int)$_POST['id_empresa'];
        $idTipo          = (int)$_POST['id_tipo'];
        $idEmisora       = (int)$_POST['id_emisora'];
        $idFormaPago     = !empty($_POST['id_forma_pago']) ? (int)$_POST['id_forma_pago'] : null;
        $idGarantia      = !empty($_POST['id_garantia']) ? (int)$_POST['id_garantia'] : null;
        $idPlazoEntrega  = !empty($_POST['id_plazo_entrega']) ? (int)$_POST['id_plazo_entrega'] : null;
        $idValidez       = !empty($_POST['id_validez']) ? (int)$_POST['id_validez'] : null;
        $tituloDocumento = !empty($_POST['titulo_documento']) ? $_POST['titulo_documento'] : null;
        $motivo          = !empty($_POST['motivo']) ? $_POST['motivo'] : null;
        $observaciones   = !empty($_POST['observaciones']) ? $_POST['observaciones'] : null;
        $moneda          = $_POST['moneda'] ?? 'PEN';
        $igvPct          = (float)($_POST['igv_pct'] ?? 18.00);
        $descGlobal      = (float)($_POST['descuento_global_pct'] ?? 0.00);

        // Cuentas bancarias: checkboxes múltiples
        $cuentasSeleccionadas = $_POST['cuentas'] ?? [];
        $cuentasSeleccionadas = array_map('intval', $cuentasSeleccionadas);
        if (empty($cuentasSeleccionadas)) {
            throw new RuntimeException('Debe seleccionar al menos una cuenta bancaria.');
        }
        $idCuentaPrincipal = $cuentasSeleccionadas[0];
        $cuentasAdicionales = array_slice($cuentasSeleccionadas, 1);
        $cuentasAdicionalesJson = !empty($cuentasAdicionales) ? json_encode($cuentasAdicionales) : null;

        // Detalle
        $descripciones = $_POST['descripcion'] ?? [];
        $cantidades    = $_POST['cantidad'] ?? [];
        $precios       = $_POST['precio_unitario'] ?? [];
        $descuentos    = $_POST['descuento_pct'] ?? [];

        $detalle = [];
        for ($i = 0; $i < count($descripciones); $i++) {
            $desc = trim($descripciones[$i] ?? '');
            if (empty($desc)) continue;
            $detalle[] = [
                'descripcion' => $desc,
                'cantidad' => (float)($cantidades[$i] ?? 1),
                'precio_unitario' => (float)($precios[$i] ?? 0),
                'descuento_pct' => (float)($descuentos[$i] ?? 0),
                'unidad_medida' => 'UND',
                'afecto_igv' => 1,
            ];
        }
        if (empty($detalle)) {
            throw new RuntimeException('Debe agregar al menos un item al detalle.');
        }
        $detalleJson = json_encode($detalle);

        // Verificar que existe el SP v5
        $chk = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_create_v5','P') IS NOT NULL THEN 1 ELSE 0 END");
        if (!(int)$chk->fetchColumn()) {
            throw new RuntimeException('El SP sp_cotizacion_create_v5 no existe. Ejecute el script SQL 09 con un usuario db_owner.');
        }

        // Ejecutar SP v5
        $stmt = $pdo->prepare(
            'EXEC dbo.sp_cotizacion_create_v5
                @id_empresa = ?, @id_empresa_contacto = ?, @id_sucursal = ?,
                @id_tipo = ?, @id_emisora = ?,
                @id_forma_pago = ?, @id_garantia = ?, @id_plazo_entrega = ?,
                @id_validez = ?, @id_cuenta_bancaria = ?, @cuentas_adicionales_json = ?,
                @id_usuario_creador = ?, @id_vendedor = ?,
                @titulo_documento = ?, @motivo = ?, @observaciones = ?,
                @moneda = ?, @igv_pct = ?, @descuento_global_pct = ?,
                @detalle_json = ?,
                @codigo = ?, @id_cotizacion = ?'
        );

        $stmt->bindValue(1, $idEmpresa, PDO::PARAM_INT);
        $stmt->bindValue(2, null, PDO::PARAM_INT); // contacto
        $stmt->bindValue(3, null, PDO::PARAM_INT); // sucursal
        $stmt->bindValue(4, $idTipo, PDO::PARAM_INT);
        $stmt->bindValue(5, $idEmisora, PDO::PARAM_INT);
        $stmt->bindValue(6, $idFormaPago, PDO::PARAM_INT);
        $stmt->bindValue(7, $idGarantia, PDO::PARAM_INT);
        $stmt->bindValue(8, $idPlazoEntrega, PDO::PARAM_INT);
        $stmt->bindValue(9, $idValidez, PDO::PARAM_INT);
        $stmt->bindValue(10, $idCuentaPrincipal, PDO::PARAM_INT);
        $stmt->bindValue(11, $cuentasAdicionalesJson, PDO::PARAM_STR);
        $stmt->bindValue(12, $userId, PDO::PARAM_INT);
        $stmt->bindValue(13, $userId, PDO::PARAM_INT); // vendedor = usuario actual
        $stmt->bindValue(14, $tituloDocumento, PDO::PARAM_STR);
        $stmt->bindValue(15, $motivo, PDO::PARAM_STR);
        $stmt->bindValue(16, $observaciones, PDO::PARAM_STR);
        $stmt->bindValue(17, $moneda, PDO::PARAM_STR);
        $stmt->bindValue(18, $igvPct, PDO::PARAM_STR);
        $stmt->bindValue(19, $descGlobal, PDO::PARAM_STR);
        $stmt->bindValue(20, $detalleJson, PDO::PARAM_STR);

        $codigo = null;
        $idCot  = null;
        $stmt->bindParam(21, $codigo, PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 40);
        $stmt->bindParam(22, $idCot,  PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);

        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || !isset($row['id_cotizacion'])) {
            try { $stmt->nextRowset(); $row = $stmt->fetch(); } catch (Throwable $e2) {}
        }

        $idNueva = (int)($row['id_cotizacion'] ?? $idCot ?? 0);
        $codigoNuevo = $row['codigo'] ?? $codigo ?? '';

        if ($idNueva) {
            $mensaje = "✓ Cotización creada correctamente. Código: <strong>" . htmlspecialchars($codigoNuevo) . "</strong>";
            $mensajeTipo = 'success';
        } else {
            throw new RuntimeException('El SP no devolvió id_cotizacion. ¿La empresa tiene codigo_empresa asignado?');
        }
    } catch (Throwable $e) {
        $mensaje = 'Error: ' . $e->getMessage();
        $mensajeTipo = 'error';
    }
}

// Cargar catálogos
$tipos = $pdo->query("SELECT id_tipo, codigo, nombre, prefijo_codigo FROM dbo.cotizacion_tipo_catalogo WHERE activo = 1 ORDER BY orden")->fetchAll();
$emisoras = $pdo->query("SELECT id_emisora, razon_social, nombre_comercial, ruc, logo_base64 FROM dbo.cotizacion_empresa_emisora WHERE activo = 1 ORDER BY es_principal DESC, razon_social")->fetchAll();
$empresas = $pdo->query("SELECT id_empresa, ruc, razon_social, codigo_empresa FROM dbo.empresa WHERE activo = 1 ORDER BY razon_social")->fetchAll();
$formasPago = $pdo->query("SELECT id_forma_pago, descripcion FROM dbo.cotizacion_forma_pago WHERE activo = 1 ORDER BY orden")->fetchAll();
$garantias = $pdo->query("SELECT id_garantia, descripcion FROM dbo.cotizacion_garantia WHERE activo = 1 ORDER BY orden")->fetchAll();
$plazos = $pdo->query("SELECT id_plazo, descripcion FROM dbo.cotizacion_plazo_entrega WHERE activo = 1 ORDER BY orden")->fetchAll();
$valideces = $pdo->query("SELECT id_validez, descripcion, dias FROM dbo.cotizacion_validez WHERE activo = 1 ORDER BY orden")->fetchAll();

// Cuentas bancarias por emisora
$cuentasPorEmisora = [];
$stmtC = $pdo->query("SELECT id_cuenta, id_emisora, banco, numero_cuenta, cci, moneda, titular, orden
                      FROM dbo.cotizacion_cuenta_bancaria
                      WHERE activo = 1
                      ORDER BY id_emisora, orden, id_cuenta");
foreach ($stmtC->fetchAll() as $c) {
    $cuentasPorEmisora[$c['id_emisora']][] = $c;
}

// Codificar cuentas por emisora como JSON para JS
$cuentasPorEmisoraJson = json_encode($cuentasPorEmisora);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nueva Cotización V3</title>
<style>
* { box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; padding: 0; background: #f5f5f5; color: #333; margin: 0; }
.container { max-width: 1100px; margin: 0 auto; padding: 20px; }
.header { background: #1976d2; color: #fff; padding: 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; }
.header h1 { margin: 0; font-size: 22px; }
.header a { color: #fff; text-decoration: none; padding: 8px 14px; background: rgba(255,255,255,0.2); border-radius: 4px; }
.card { background: #fff; padding: 20px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
fieldset { margin-bottom: 18px; border: 1px solid #e0e0e0; padding: 14px; border-radius: 6px; }
legend { padding: 0 6px; font-size: 13px; font-weight: 600; color: #2c3e50; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
.form-group { margin-bottom: 8px; }
.form-group label { display: block; font-size: 11px; color: #95a5a6; margin-bottom: 4px; }
.form-input, .form-select { width: 100%; padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }
.btn { display: inline-block; padding: 8px 16px; background: #1976d2; color: #fff; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; font-size: 13px; }
.btn:hover { background: #1565c0; }
.btn-primary { background: #1976d2; }
.btn-success { background: #28a745; }
.btn-danger { background: #dc3545; }
.btn-secondary { background: #6c757d; }
.alert { padding: 12px; border-radius: 4px; margin-bottom: 16px; }
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.banner-codigo { padding: 14px 18px; background: linear-gradient(to right, #e3f2fd, #f3f9ff); border-left: 5px solid #1976d2; border-radius: 6px; margin-bottom: 18px; }
.banner-codigo .label { font-size: 10px; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
.banner-codigo .value { font-family: 'Consolas', monospace; font-size: 22px; font-weight: 700; color: #1976d2; letter-spacing: 1px; margin-top: 4px; }
.banner-codigo .warning { font-size: 11px; color: #f57c00; margin-top: 6px; background: #fff8e1; padding: 6px 10px; border-radius: 3px; border-left: 3px solid #ffc107; }
.cuentas-container { display: grid; gap: 8px; }
.cuenta-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: #fff; border: 1px solid #dee2e6; border-radius: 6px; cursor: pointer; transition: all 0.2s; }
.cuenta-item:hover { border-color: #1976d2; box-shadow: 0 1px 3px rgba(25, 118, 210, 0.1); }
.cuenta-item input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.cuenta-item.principal { border-color: #28a745; background: #f0fff4; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; }
.badge-success { background: #28a745; color: #fff; }
.menu-superior { background: #2c3e50; padding: 8px 20px; display: flex; gap: 8px; flex-wrap: wrap; }
.menu-superior a { color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; font-size: 13px; }
.menu-superior a:hover { background: rgba(255,255,255,0.1); }
.menu-superior a.active { background: #1976d2; }
table.detalle { width: 100%; border-collapse: collapse; font-size: 12px; }
table.detalle th { background: #f9fafc; padding: 6px; text-align: left; border-bottom: 1px solid #e0e0e0; }
table.detalle td { padding: 6px; border-bottom: 1px solid #ecf0f1; }
table.detalle input { padding: 4px 6px; border: 1px solid #ccc; border-radius: 3px; font-size: 12px; }
.totales { margin-top: 14px; padding: 12px; background: #f8f9fa; border-radius: 6px; font-size: 13px; }
.totales .linea { display: flex; justify-content: space-between; padding: 3px 0; }
.totales .gran-total { border-top: 2px solid #2c3e50; margin-top: 6px; padding-top: 6px; font-size: 15px; font-weight: 700; }
.logo-emisora { max-width: 150px; max-height: 60px; object-fit: contain; margin-right: 10px; vertical-align: middle; }
</style>
</head>
<body>

<div class="menu-superior">
    <a href="/index.php">🏠 Inicio</a>
    <a href="test_v3.php">🔧 Diagnóstico</a>
    <a href="nueva_cotizacion_v3.php" class="active">📄 Nueva Cotización</a>
    <a href="config_logo_v3.php">🖼️ Configurar Logo</a>
    <a href="seguimiento_comercial_v3.php">📋 Seguimiento</a>
</div>

<div class="container">
    <div class="header">
        <h1>📄 Nueva Cotización V3</h1>
        <a href="/index.php">← Volver al sistema</a>
    </div>

    <div class="card">
        <?php if ($mensaje): ?>
            <div class="alert alert-<?= $mensajeTipo === 'success' ? 'success' : 'error' ?>">
                <?= $mensaje ?>
            </div>
        <?php endif; ?>

        <form method="post" id="formCotizacion">
            <input type="hidden" name="action" value="crear">

            <!-- BANNER DE CÓDIGO AUTOMÁTICO -->
            <div class="banner-codigo">
                <div class="label">Código que se generará automáticamente:</div>
                                <div class="value" id="codigoPreview">(Seleccione empresa y tipo para ver el código)</div>
                <div class="warning" id="codigoWarning" style="display:none;"></div>
            </div>

            <!-- TIPO Y EMPRESAS -->
            <fieldset>
                <legend>Cliente y tipo</legend>
                <div class="grid">
                    <div class="form-group">
                        <label>Tipo de cotización *</label>
                        <select class="form-select" id="id_tipo" name="id_tipo" required onchange="actualizarPreview()">
                            <?php foreach ($tipos as $t): ?>
                                <option value="<?= $t['id_tipo'] ?>"><?= htmlspecialchars($t['nombre']) ?> (<?= htmlspecialchars($t['prefijo_codigo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Empresa emisora *</label>
                        <select class="form-select" id="id_emisora" name="id_emisora" required onchange="cargarCuentas()">
                            <?php foreach ($emisoras as $e): ?>
                                <option value="<?= $e['id_emisora'] ?>" data-logo="<?= !empty($e['logo_base64']) ? '1' : '0' ?>">
                                    <?= htmlspecialchars($e['razon_social']) ?> (RUC: <?= htmlspecialchars($e['ruc']) ?>)
                                    <?= !empty($e['logo_base64']) ? ' ✓' : ' ⚠ sin logo' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Empresa cliente *</label>
                        <select class="form-select" id="id_empresa" name="id_empresa" required onchange="actualizarPreview()">
                            <option value="">(seleccione)</option>
                            <?php foreach ($empresas as $e): ?>
                                <option value="<?= $e['id_empresa'] ?>" data-codigo="<?= htmlspecialchars($e['codigo_empresa'] ?? '') ?>">
                                    <?= htmlspecialchars($e['razon_social']) ?> (RUC: <?= htmlspecialchars($e['ruc']) ?>)
                                    <?= !empty($e['codigo_empresa']) ? ' [' . htmlspecialchars($e['codigo_empresa']) . ']' : ' [sin código]' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="logoEmisoraPreview" style="margin-top:10px;"></div>
            </fieldset>

            <!-- CONDICIONES COMERCIALES (SIN ZONA DE PRICING) -->
            <fieldset>
                <legend>Condiciones comerciales</legend>
                <div class="grid">
                    <div class="form-group">
                        <label>Moneda</label>
                        <select class="form-select" name="moneda">
                            <option value="PEN">Soles (PEN)</option>
                            <option value="USD">Dólares (USD)</option>
                            <option value="EUR">Euros (EUR)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>IGV %</label>
                        <input type="number" class="form-input" name="igv_pct" value="18.00" step="0.01" min="0" max="100">
                    </div>
                    <div class="form-group">
                        <label>Descuento global %</label>
                        <input type="number" class="form-input" name="descuento_global_pct" value="0.00" step="0.01" min="0" max="100">
                    </div>
                    <div class="form-group">
                        <label>Forma de pago</label>
                        <select class="form-select" name="id_forma_pago">
                            <option value="">(sin especificar)</option>
                            <?php foreach ($formasPago as $f): ?>
                                <option value="<?= $f['id_forma_pago'] ?>"><?= htmlspecialchars($f['descripcion']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Garantía</label>
                        <select class="form-select" name="id_garantia">
                            <option value="">(sin especificar)</option>
                            <?php foreach ($garantias as $g): ?>
                                <option value="<?= $g['id_garantia'] ?>"><?= htmlspecialchars($g['descripcion']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Plazo de entrega</label>
                        <select class="form-select" name="id_plazo_entrega">
                            <option value="">(sin especificar)</option>
                            <?php foreach ($plazos as $p): ?>
                                <option value="<?= $p['id_plazo'] ?>"><?= htmlspecialchars($p['descripcion']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Validez</label>
                        <select class="form-select" name="id_validez">
                            <option value="">(sin especificar)</option>
                            <?php foreach ($valideces as $v): ?>
                                <option value="<?= $v['id_validez'] ?>"><?= htmlspecialchars($v['descripcion']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- ZONA DE PRICING ELIMINADA -->
                </div>
            </fieldset>

            <!-- CUENTAS BANCARIAS: CHECKBOXES MÚLTIPLES -->
            <fieldset style="background: #fafbfc;">
                <legend>Cuentas bancarias (selección múltiple — aparecerán en el PDF)</legend>
                <div style="font-size: 11px; color: #7f8c8d; margin-bottom: 10px;">
                    Seleccione las cuentas que aparecerán en la cotización.
                    Por defecto aparecen marcadas las 3 cuentas activas de la emisora.
                </div>
                <div class="cuentas-container" id="cuentasContainer">
                    <div style="color: #7f8c8d; font-size: 12px; padding: 14px; text-align: center;">
                        Seleccione una emisora para ver sus cuentas bancarias.
                    </div>
                </div>
                <div style="margin-top:10px; padding:8px; background:#e3f2fd; border-radius:4px; font-size:11px; color:#1976d2;">
                    <strong>Cuentas seleccionadas:</strong> <span id="cuentasCount">0</span>
                    · Estas cuentas aparecerán automáticamente en el PDF de la cotización.
                </div>
            </fieldset>

            <!-- INFORMACIÓN DEL DOCUMENTO -->
            <fieldset>
                <legend>Información del documento</legend>
                <div class="form-group">
                    <label>Título del documento</label>
                    <input type="text" class="form-input" name="titulo_documento" placeholder="COTIZACIÓN, PROPUESTA, etc. (vacío = usa el del tipo)">
                </div>
                <div class="form-group">
                    <label>Motivo</label>
                    <input type="text" class="form-input" name="motivo" placeholder="Breve descripción del motivo de la cotización">
                </div>
                <div class="form-group">
                    <label>Observaciones</label>
                    <textarea class="form-input" name="observaciones" rows="3" placeholder="Notas internas, condiciones especiales..."></textarea>
                </div>
            </fieldset>

            <!-- DETALLE -->
            <fieldset>
                <legend>Detalle de items</legend>
                <table class="detalle" id="detalleTable">
                    <thead>
                        <tr>
                            <th style="width:30px;">#</th>
                            <th>Descripción</th>
                            <th style="width:70px;">Cant.</th>
                            <th style="width:100px;">P. Unit.</th>
                            <th style="width:60px;">Desc.%</th>
                            <th style="width:100px;">Subtotal</th>
                            <th style="width:30px;"></th>
                        </tr>
                    </thead>
                    <tbody id="detalleBody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" style="text-align:right;">
                                <button type="button" class="btn btn-secondary" onclick="addItem()">+ Agregar item</button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <div class="totales" id="totales"></div>
            </fieldset>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px;">
                <a href="/index.php" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">💾 Guardar Cotización</button>
            </div>
        </form>
    </div>
</div>

<script>
const CUENTAS_POR_EMISORA = <?= $cuentasPorEmisoraJson ?>;

function cargarCuentas() {
    const idEmi = parseInt(document.getElementById('id_emisora').value, 10);
    const cont = document.getElementById('cuentasContainer');

    if (!idEmi) {
        cont.innerHTML = '<div style="color:#7f8c8d;font-size:12px;padding:14px;text-align:center;">Seleccione una emisora.</div>';
        document.getElementById('cuentasCount').textContent = '0';
        return;
    }

    const cuentas = CUENTAS_POR_EMISORA[idEmi] || [];
    if (cuentas.length === 0) {
        cont.innerHTML = '<div style="padding:14px;text-align:center;color:#dc3545;background:#fff5f5;border-radius:4px;font-size:12px;">⚠ Esta emisora no tiene cuentas bancarias configuradas.</div>';
        document.getElementById('cuentasCount').textContent = '0';
        return;
    }

    cont.innerHTML = cuentas.map((cu, i) => {
        const checked = i < 3 ? 'checked' : '';
        const principal = i === 0 ? 'principal' : '';
        const monedaColor = cu.moneda === 'PEN' ? '#28a745' : (cu.moneda === 'USD' ? '#007bff' : '#6c757d');
        return `
            <label class="cuenta-item ${principal}">
                <input type="checkbox" name="cuentas[]" value="${cu.id_cuenta}" ${checked} onchange="actualizarCuentasCount()">
                <div style="flex:1;">
                    <div style="font-weight:600;color:#2c3e50;font-size:13px;">
                        ${cu.banco}
                        ${i === 0 ? '<span class="badge badge-success" style="margin-left:6px;">PRINCIPAL</span>' : ''}
                    </div>
                    <div style="font-size:11px;color:#7f8c8d;font-family:monospace;">
                        Cuenta: ${cu.numero_cuenta}
                        ${cu.cci ? ' · CCI: ' + cu.cci : ''}
                    </div>
                    ${cu.titular ? `<div style="font-size:10px;color:#95a5a6;">Titular: ${cu.titular}</div>` : ''}
                </div>
                <div>
                    <span style="background:${monedaColor};color:#fff;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:600;">${cu.moneda}</span>
                </div>
            </label>`;
    }).join('');
    actualizarCuentasCount();
}

function actualizarCuentasCount() {
    const checks = document.querySelectorAll('input[name="cuentas[]"]:checked');
    document.getElementById('cuentasCount').textContent = checks.length;
}

function actualizarPreview() {
    const idTipo = document.getElementById('id_tipo').value;
    const idEmpresa = document.getElementById('id_empresa').value;
    const valEl = document.getElementById('codigoPreview');
    const warnEl = document.getElementById('codigoWarning');

    if (!idTipo || !idEmpresa) {
        valEl.textContent = '(Seleccione empresa y tipo para ver el código)';
        warnEl.style.display = 'none';
        return;
    }

    fetch(`/api/v1/cotizaciones/preview_codigo?id_empresa=${idEmpresa}&id_tipo=${idTipo}`, {
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(r => {
        const data = r.data || {};
        if (data.codigo_proximo) {
            valEl.textContent = data.codigo_proximo;
            if (data.advertencia) {
                warnEl.textContent = '⚠ ' + data.advertencia;
                warnEl.style.display = 'block';
            } else {
                warnEl.style.display = 'none';
            }
        } else if (data.advertencia) {
            valEl.textContent = '(no disponible)';
            warnEl.textContent = '⚠ ' + data.advertencia;
            warnEl.style.display = 'block';
        } else {
            valEl.textContent = '(no disponible)';
            warnEl.style.display = 'none';
        }
    })
    .catch(e => {
        valEl.textContent = '(error al obtener código)';
        warnEl.style.display = 'none';
    });
}

let itemIdx = 0;
function addItem() {
    itemIdx++;
    const tbody = document.getElementById('detalleBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>${itemIdx}</td>
        <td><input type="text" name="descripcion[]" placeholder="Descripción" style="width:100%;"></td>
        <td><input type="number" name="cantidad[]" value="1" step="0.01" min="0" style="width:70px;text-align:right;" oninput="recalcular()"></td>
        <td><input type="number" name="precio_unitario[]" value="0" step="0.01" min="0" style="width:100px;text-align:right;" oninput="recalcular()"></td>
        <td><input type="number" name="descuento_pct[]" value="0" step="0.01" min="0" max="100" style="width:60px;text-align:right;" oninput="recalcular()"></td>
        <td style="text-align:right;font-weight:600;" class="subtotal">0.00</td>
        <td><button type="button" class="btn btn-danger" style="padding:2px 6px;font-size:10px;" onclick="this.closest('tr').remove(); recalcular();">✗</button></td>
    `;
    tbody.appendChild(tr);
    recalcular();
}

function recalcular() {
    const rows = document.querySelectorAll('#detalleBody tr');
    let subtotal = 0;
    rows.forEach(tr => {
        const cant = parseFloat(tr.querySelector('input[name="cantidad[]"]').value) || 0;
        const prec = parseFloat(tr.querySelector('input[name="precio_unitario[]"]').value) || 0;
        const desc = parseFloat(tr.querySelector('input[name="descuento_pct[]"]').value) || 0;
        const sub = cant * prec * (1 - desc / 100);
        tr.querySelector('.subtotal').textContent = sub.toFixed(2);
        subtotal += sub;
    });
    const igvPct = parseFloat(document.querySelector('input[name="igv_pct"]').value) || 0;
    const descGlb = parseFloat(document.querySelector('input[name="descuento_global_pct"]').value) || 0;
    const subtotalConDesc = subtotal * (1 - descGlb / 100);
    const igv = subtotalConDesc * (igvPct / 100);
    const total = subtotalConDesc + igv;
    document.getElementById('totales').innerHTML = `
        <div class="linea"><span>Subtotal:</span><strong>S/ ${subtotal.toFixed(2)}</strong></div>
        <div class="linea"><span>Descuento global (${descGlb}%):</span><strong>- S/ ${(subtotal * descGlb / 100).toFixed(2)}</strong></div>
        <div class="linea"><span>IGV (${igvPct}%):</span><strong>S/ ${igv.toFixed(2)}</strong></div>
        <div class="linea gran-total"><span>TOTAL:</span><strong>S/ ${total.toFixed(2)}</strong></div>
    `;
}

// Inicializar
addItem();
cargarCuentas();
</script>

</body>
</html>
