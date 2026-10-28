<?php
/**
 * seguimiento_comercial_v3.php — Página INDEPENDIENTE con filtros
 * ----------------------------------------------------------------------------
 * Acceder directamente: http://su-servidor/seguimiento_comercial_v3.php
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

$pdo = Database::connection();

// Filtros
$filtroEmpresa = !empty($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : null;
$filtroEstado  = !empty($_GET['estado']) ? $_GET['estado'] : null;
$filtroQuery   = !empty($_GET['query']) ? trim($_GET['query']) : null;
$page          = !empty($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage       = 25;
$offset        = ($page - 1) * $perPage;

// Construir WHERE
$where  = " WHERE ISNULL(c.eliminado, 0) = 0 ";
$params = [];

if ($filtroEmpresa) {
    $where .= " AND c.id_empresa = ? ";
    $params[] = $filtroEmpresa;
}
if ($filtroEstado) {
    $where .= " AND c.estado = ? ";
    $params[] = $filtroEstado;
}
if ($filtroQuery) {
    $where .= " AND (c.codigo LIKE ? OR e.razon_social LIKE ? OR e.ruc LIKE ?) ";
    $like = '%' . $filtroQuery . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

// Total
$sqlCount = "SELECT COUNT(*) FROM dbo.cotizacion c INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa " . $where;
$stmtC = $pdo->prepare($sqlCount);
foreach ($params as $i => $v) {
    $stmtC->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtC->execute();
$total = (int)$stmtC->fetchColumn();
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 0;

// Página actual
$sqlPage = "SELECT c.id_cotizacion, c.codigo, c.version_numero, c.estado,
                   CONVERT(VARCHAR(10), c.fecha_creacion, 23) AS fecha_creacion,
                   CONVERT(VARCHAR(10), c.fecha_proximo_seguimiento, 23) AS fecha_proximo_seguimiento,
                   c.total, c.moneda,
                   c.id_empresa, e.razon_social AS empresa_razon_social, e.ruc AS empresa_ruc,
                   tc.nombre AS tipo_nombre,
                   ISNULL(cnt.total_seguimientos, 0) AS total_seguimientos
            FROM dbo.cotizacion c
            INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
            INNER JOIN dbo.cotizacion_tipo_catalogo tc ON c.id_tipo = tc.id_tipo
            OUTER APPLY (
                SELECT COUNT(*) AS total_seguimientos
                FROM dbo.cotizacion_comunicacion cc
                WHERE cc.id_cotizacion = c.id_cotizacion
            ) cnt "
          . $where
          . " ORDER BY c.id_cotizacion DESC OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

$stmtP = $pdo->prepare($sqlPage);
foreach ($params as $i => $v) {
    $stmtP->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtP->execute();
$cotizaciones = $stmtP->fetchAll();

// Empresas para el select
$empresas = $pdo->query("SELECT id_empresa, razon_social, ruc FROM dbo.empresa WHERE activo = 1 ORDER BY razon_social")->fetchAll();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Seguimiento Comercial V3</title>
<style>
* { box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; padding: 0; background: #f5f5f5; color: #333; margin: 0; }
.container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.header { background: #1976d2; color: #fff; padding: 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; }
.header h1 { margin: 0; font-size: 22px; }
.header a { color: #fff; text-decoration: none; padding: 8px 14px; background: rgba(255,255,255,0.2); border-radius: 4px; }
.card { background: #fff; padding: 20px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
.filtros { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 10px; align-items: flex-end; padding: 12px; background: #f8f9fa; border-radius: 6px; margin-bottom: 16px; }
.form-group label { display: block; font-size: 11px; color: #7f8c8d; margin-bottom: 4px; }
.form-input, .form-select { width: 100%; padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }
.btn { display: inline-block; padding: 8px 16px; background: #1976d2; color: #fff; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; font-size: 13px; }
.btn-secondary { background: #6c757d; }
.menu-superior { background: #2c3e50; padding: 8px 20px; display: flex; gap: 8px; flex-wrap: wrap; }
.menu-superior a { color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; font-size: 13px; }
.menu-superior a:hover { background: rgba(255,255,255,0.1); }
.menu-superior a.active { background: #1976d2; }
table { width: 100%; border-collapse: collapse; font-size: 12px; }
th { background: #f9fafc; padding: 8px; text-align: left; border-bottom: 2px solid #e0e0e0; }
td { padding: 8px; border-bottom: 1px solid #ecf0f1; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; color: #fff; }
.pagination { padding: 10px; border-top: 1px solid #ecf0f1; display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #7f8c8d; }
</style>
</head>
<body>

<div class="menu-superior">
    <a href="/index.php">🏠 Inicio</a>
    <a href="test_v3.php">🔧 Diagnóstico</a>
    <a href="nueva_cotizacion_v3.php">📄 Nueva Cotización</a>
    <a href="config_logo_v3.php">🖼️ Configurar Logo</a>
    <a href="seguimiento_comercial_v3.php" class="active">📋 Seguimiento</a>
</div>

<div class="container">
    <div class="header">
        <h1>📋 Seguimiento Comercial por Empresa</h1>
        <a href="/index.php">← Volver</a>
    </div>

    <div class="card">
        <form method="get" class="filtros">
            <div class="form-group">
                <label>Empresa</label>
                <select class="form-select" name="id_empresa">
                    <option value="">(todas)</option>
                    <?php foreach ($empresas as $e): ?>
                        <option value="<?= $e['id_empresa'] ?>" <?= $filtroEmpresa == $e['id_empresa'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['razon_social']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Estado</label>
                <select class="form-select" name="estado">
                    <option value="">(todos)</option>
                    <?php foreach (['BORRADOR','PENDIENTE_ENVIO','ENVIADA','EN_SEGUIMIENTO','EN_NEGOCIACION','APROBADA','RECHAZADA','CANCELADA','CERRADA'] as $est): ?>
                        <option value="<?= $est ?>" <?= $filtroEstado === $est ? 'selected' : '' ?>><?= $est ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Buscar</label>
                <input type="text" class="form-input" name="query" value="<?= htmlspecialchars($filtroQuery ?? '') ?>" placeholder="Código, RUC, razón social...">
            </div>
            <div style="display:flex;gap:6px;">
                <button type="submit" class="btn">🔍 Buscar</button>
                <a href="seguimiento_comercial_v3.php" class="btn btn-secondary">✗ Limpiar</a>
            </div>
        </form>

        <?php if (empty($cotizaciones)): ?>
            <div style="padding: 30px; text-align: center; color: #7f8c8d;">
                🔍 No se encontraron cotizaciones con los filtros seleccionados.
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Empresa</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th>Fecha</th>
                        <th style="text-align:right;">Total</th>
                        <th style="text-align:center;">Seguimientos</th>
                        <th>Próximo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cotizaciones as $c): ?>
                        <tr>
                            <td><strong style="font-family:monospace;"><?= htmlspecialchars($c['codigo']) ?></strong></td>
                            <td><?= htmlspecialchars($c['empresa_razon_social']) ?><br>
                                <small style="color:#7f8c8d;"><?= htmlspecialchars($c['empresa_ruc']) ?></small></td>
                            <td><?= htmlspecialchars($c['tipo_nombre']) ?></td>
                            <td>
                                <?php
                                $colors = [
                                    'BORRADOR' => '#6c757d', 'PENDIENTE_ENVIO' => '#17a2b8',
                                    'ENVIADA' => '#007bff', 'EN_SEGUIMIENTO' => '#ffc107',
                                    'EN_NEGOCIACION' => '#fd7e14', 'APROBADA' => '#28a745',
                                    'RECHAZADA' => '#dc3545', 'CANCELADA' => '#343a40', 'CERRADA' => '#198754'
                                ];
                                $color = $colors[$c['estado']] ?? '#6c757d';
                                ?>
                                <span class="badge" style="background:<?= $color ?>;"><?= htmlspecialchars($c['estado']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($c['fecha_creacion']) ?></td>
                            <td style="text-align:right;font-weight:600;"><?= htmlspecialchars($c['moneda']) ?> <?= number_format((float)$c['total'], 2) ?></td>
                            <td style="text-align:center;">
                                <span style="background:#e3f2fd;color:#1976d2;padding:3px 10px;border-radius:12px;font-weight:600;">
                                    <?= (int)$c['total_seguimientos'] ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($c['fecha_proximo_seguimiento'] ?? '—') ?></td>
                            <td>
                                <a href="/index.php#/cotizacion-detalle/<?= $c['id_cotizacion'] ?>" class="btn" style="padding:4px 10px;font-size:11px;">Ver</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="pagination">
                <div>
                    Mostrando <?= count($cotizaciones) ?> de <?= $total ?> cotización(es) ·
                    Página <?= $page ?> de <?= max(1, $totalPages) ?>
                </div>
                <div style="display:flex;gap:6px;">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-secondary">← Anterior</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn">Siguiente →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
