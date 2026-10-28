<?php
/**
 * test_v3.php — Página de diagnóstico INDEPENDIENTE
 * ----------------------------------------------------------------------------
 * Acceder directamente: http://su-servidor/test_v3.php
 *
 * Verifica:
 *   - Conexión a la base de datos
 *   - Si la columna codigo_empresa existe
 *   - Si el SP sp_cotizacion_create_v5 existe
 *   - Si el SP sp_cotizacion_preview_codigo existe
 *   - Cuántas empresas tienen codigo_empresa asignado
 *   - Qué tipos de cotización están activos
 *   - Si las cuentas bancarias existen
 *   - Si los logos están cargados
 */

declare(strict_types=1);
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

function check($label, $ok, $detail = '') {
    $color = $ok ? '#28a745' : '#dc3545';
    $icon = $ok ? '✓' : '✗';
    echo "<div style='padding:6px 10px;border-left:4px solid {$color};background:#f8f9fa;margin-bottom:4px;'>";
    echo "<strong style='color:{$color};'>{$icon}</strong> <strong>{$label}</strong>";
    if ($detail) echo " — <span style='color:#666;font-size:12px;'>{$detail}</span>";
    echo "</div>";
}

try {
    $pdo = Database::connection();
    $okDb = true;
} catch (Throwable $e) {
    $okDb = false;
    $dbErr = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Diagnóstico V3 — Cotizaciones</title>
<style>
body { font-family: 'Segoe UI', Arial, sans-serif; padding: 30px; background: #f5f5f5; color: #333; max-width: 1000px; margin: 0 auto; }
h1 { color: #1976d2; border-bottom: 3px solid #1976d2; padding-bottom: 10px; }
h2 { color: #2c3e50; margin-top: 30px; }
.card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
.btn { display: inline-block; padding: 10px 18px; background: #1976d2; color: #fff; text-decoration: none; border-radius: 4px; margin: 4px; }
.btn:hover { background: #1565c0; }
.btn-success { background: #28a745; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
th { background: #f9fafc; padding: 8px; text-align: left; border-bottom: 2px solid #e0e0e0; }
td { padding: 8px; border-bottom: 1px solid #ecf0f1; }
.warn { background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 10px 0; }
.success { background: #d4edda; padding: 10px; border-left: 4px solid #28a745; margin: 10px 0; }
</style>
</head>
<body>

<h1>🔧 Diagnóstico V3 — Módulo de Cotizaciones</h1>

<div class="success">
    <strong>✅ Esta página funciona.</strong> Si está viendo esto, PHP está ejecutando correctamente.
    Acceda a las páginas nuevas directamente con estos enlaces:
    <div style="margin-top:10px;">
        <a href="nueva_cotizacion_v3.php" class="btn">📄 Nueva Cotización V3</a>
        <a href="config_logo_v3.php" class="btn btn-success">🖼️ Configurar Logo V3</a>
        <a href="seguimiento_comercial_v3.php" class="btn">📋 Seguimiento Comercial</a>
    </div>
</div>

<h2>1. Conexión a la base de datos</h2>
<div class="card">
<?php
if ($okDb) {
    check('Conexión a MonosyncERP', true, 'PDO OK');
} else {
    check('Conexión a MonosyncERP', false, $dbErr ?? 'Error desconocido');
    echo '</div></body></html>';
    exit;
}
?>
</div>

<h2>2. Estructura de la base de datos</h2>
<div class="card">
<?php
// 2.1 Columna codigo_empresa
$stmt = $pdo->query("SELECT COL_LENGTH('dbo.empresa','codigo_empresa') AS existe");
$col = $stmt->fetchColumn();
check('Columna codigo_empresa en dbo.empresa', !empty($col), $col ? 'Existe' : 'NO existe — ejecute el script SQL 09');

// 2.2 SP v5
$stmt = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_create_v5','P') IS NOT NULL THEN 1 ELSE 0 END");
$spV5 = (int)$stmt->fetchColumn();
check('SP sp_cotizacion_create_v5', $spV5 === 1, $spV5 ? 'Existe' : 'NO existe — ejecute el script SQL 09');

// 2.3 SP preview_codigo
$stmt = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_preview_codigo','P') IS NOT NULL THEN 1 ELSE 0 END");
$spPrev = (int)$stmt->fetchColumn();
check('SP sp_cotizacion_preview_codigo', $spPrev === 1, $spPrev ? 'Existe' : 'NO existe');

// 2.4 SP update_status
$stmt = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_cotizacion_update_status','P') IS NOT NULL THEN 1 ELSE 0 END");
$spUpd = (int)$stmt->fetchColumn();
check('SP sp_cotizacion_update_status', $spUpd === 1);

// 2.5 SP set_codigo
$stmt = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_empresa_set_codigo','P') IS NOT NULL THEN 1 ELSE 0 END");
$spSet = (int)$stmt->fetchColumn();
check('SP sp_empresa_set_codigo', $spSet === 1);
?>
</div>

<h2>3. Tipos de cotización activos (deben ser solo SERV y VENT)</h2>
<div class="card">
<?php
$stmt = $pdo->query("SELECT id_tipo, codigo, nombre, prefijo_codigo, activo FROM dbo.cotizacion_tipo_catalogo ORDER BY activo DESC, orden");
$tipos = $stmt->fetchAll();
if (empty($tipos)) {
    echo '<div class="warn">No hay tipos de cotización configurados.</div>';
} else {
    echo '<table><thead><tr><th>ID</th><th>Código</th><th>Nombre</th><th>Prefijo</th><th>Activo</th></tr></thead><tbody>';
    foreach ($tipos as $t) {
        $activo = (int)$t['activo'] === 1 ? '✓ SÍ' : '✗ NO';
        echo "<tr><td>{$t['id_tipo']}</td><td>{$t['codigo']}</td><td>{$t['nombre']}</td><td>{$t['prefijo_codigo']}</td><td>{$activo}</td></tr>";
    }
    echo '</tbody></table>';
    $activos = array_filter($tipos, fn($t) => (int)$t['activo'] === 1);
    if (count($activos) > 2) {
        echo '<div class="warn">Hay más de 2 tipos activos. Ejecute el script SQL 09 para desactivar los demás.</div>';
    } elseif (count($activos) === 2) {
        echo '<div class="success">✓ Correcto: hay exactamente 2 tipos activos.</div>';
    }
}
?>
</div>

<h2>4. Empresas y sus códigos</h2>
<div class="card">
<?php
$stmt = $pdo->query("SELECT id_empresa, ruc, razon_social, codigo_empresa FROM dbo.empresa WHERE activo = 1 ORDER BY razon_social");
$empresas = $stmt->fetchAll();
if (empty($empresas)) {
    echo '<div class="warn">No hay empresas activas.</div>';
} else {
    echo '<table><thead><tr><th>ID</th><th>RUC</th><th>Razón Social</th><th>Código empresa</th><th>Acción</th></tr></thead><tbody>';
    foreach ($empresas as $e) {
        $codigo = $e['codigo_empresa'] ?: '<span style="color:#dc3545;">⚠ Sin código</span>';
        $btnAsignar = $e['codigo_empresa']
            ? "<a href='config_logo_v3.php#empresa-{$e['id_empresa']}' class='btn' style='padding:4px 10px;font-size:11px;'>Ver</a>"
            : "<form method='post' action='asignar_codigo_v3.php' style='display:inline;'>
                 <input type='hidden' name='id_empresa' value='{$e['id_empresa']}'>
                 <input type='text' name='codigo_empresa' placeholder='Ej: SERV' required
                        style='padding:4px;width:80px;border:1px solid #ccc;border-radius:3px;'>
                 <button type='submit' class='btn btn-success' style='padding:4px 10px;font-size:11px;'>Asignar</button>
               </form>";
        echo "<tr><td>{$e['id_empresa']}</td><td>{$e['ruc']}</td><td>{$e['razon_social']}</td><td>{$codigo}</td><td>{$btnAsignar}</td></tr>";
    }
    echo '</tbody></table>';
    $sinCodigo = array_filter($empresas, fn($e) => empty($e['codigo_empresa']));
    if (!empty($sinCodigo)) {
        echo '<div class="warn">⚠ Hay empresas sin código asignado. Asígneles un código para que puedan generar cotizaciones.</div>';
    }
}
?>
</div>

<h2>5. Cuentas bancarias por emisora</h2>
<div class="card">
<?php
$stmt = $pdo->query("SELECT em.id_emisora, em.razon_social,
                           COUNT(c.id_cuenta) AS total_cuentas,
                           SUM(CASE WHEN c.activo = 1 THEN 1 ELSE 0 END) AS activas
                    FROM dbo.cotizacion_empresa_emisora em
                    LEFT JOIN dbo.cotizacion_cuenta_bancaria c ON em.id_emisora = c.id_emisora
                    WHERE em.activo = 1
                    GROUP BY em.id_emisora, em.razon_social
                    ORDER BY em.razon_social");
$cuentas = $stmt->fetchAll();
if (empty($cuentas)) {
    echo '<div class="warn">No hay emisoras configuradas.</div>';
} else {
    echo '<table><thead><tr><th>ID</th><th>Emisora</th><th>Total cuentas</th><th>Activas</th><th>Acción</th></tr></thead><tbody>';
    foreach ($cuentas as $c) {
        $activas = (int)$c['activas'];
        $color = $activas >= 3 ? '#28a745' : ($activas > 0 ? '#ffc107' : '#dc3545');
        echo "<tr><td>{$c['id_emisora']}</td><td>{$c['razon_social']}</td>
              <td>{$c['total_cuentas']}</td>
              <td style='color:{$color};font-weight:600;'>{$activas}</td>
              <td><a href='config_logo_v3.php' class='btn' style='padding:4px 10px;font-size:11px;'>Configurar</a></td></tr>";
    }
    echo '</tbody></table>';
}
?>
</div>

<h2>6. Logos cargados</h2>
<div class="card">
<?php
$stmt = $pdo->query("SELECT id_emisora, razon_social,
                           CASE WHEN logo_base64 IS NULL THEN 0 ELSE 1 END AS tiene_logo
                    FROM dbo.cotizacion_empresa_emisora
                    WHERE activo = 1
                    ORDER BY razon_social");
$logos = $stmt->fetchAll();
if (empty($logos)) {
    echo '<div class="warn">No hay emisoras.</div>';
} else {
    echo '<table><thead><tr><th>ID</th><th>Emisora</th><th>Logo</th><th>Acción</th></tr></thead><tbody>';
    foreach ($logos as $l) {
        $tiene = (int)$l['tiene_logo'] === 1
            ? '<span style="color:#28a745;">✓ Cargado</span>'
            : '<span style="color:#dc3545;">✗ Sin logo</span>';
        echo "<tr><td>{$l['id_emisora']}</td><td>{$l['razon_social']}</td><td>{$tiene}</td>
              <td><a href='config_logo_v3.php' class='btn' style='padding:4px 10px;font-size:11px;'>Subir/Reemplazar</a></td></tr>";
    }
    echo '</tbody></table>';
}
?>
</div>

<h2>7. Prueba de generación de código</h2>
<div class="card">
<?php
if ($spPrev === 1) {
    // Tomar la primera empresa con codigo_empresa
    $stmt = $pdo->query("SELECT TOP 1 id_empresa, razon_social, codigo_empresa FROM dbo.empresa WHERE codigo_empresa IS NOT NULL AND activo = 1");
    $emp = $stmt->fetch();
    if ($emp) {
        // Tomar el primer tipo activo
        $stmt = $pdo->query("SELECT TOP 1 id_tipo, nombre, prefijo_codigo FROM dbo.cotizacion_tipo_catalogo WHERE activo = 1 ORDER BY orden");
        $tipo = $stmt->fetch();
        if ($tipo) {
            $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_preview_codigo @id_empresa = ?, @id_tipo = ?");
            $stmt->bindValue(1, (int)$emp['id_empresa'], PDO::PARAM_INT);
            $stmt->bindValue(2, (int)$tipo['id_tipo'], PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            echo "<p>Empresa: <strong>{$emp['razon_social']}</strong> (código: {$emp['codigo_empresa']})</p>";
            echo "<p>Tipo: <strong>{$tipo['nombre']}</strong> (prefijo: {$tipo['prefijo_codigo']})</p>";
            if (!empty($row['codigo_proximo'])) {
                echo "<div class='success'><strong>Código que se generaría:</strong> <code style='font-size:18px;font-weight:700;color:#1976d2;'>{$row['codigo_proximo']}</code></div>";
            } else {
                echo "<div class='warn'>Advertencia: {$row['advertencia']}</div>";
            }
        }
    } else {
        echo '<div class="warn">No hay empresas con codigo_empresa asignado. Asígnelo arriba.</div>';
    }
} else {
    echo '<div class="warn">El SP sp_cotizacion_preview_codigo no existe.</div>';
}
?>
</div>

<h2>8. Próximos pasos</h2>
<div class="card">
<ol>
    <li>Si algo arriba dice ✗, ejecute el script <code>09_configuracion_final_v3.sql</code> con un usuario <code>db_owner</code> o <code>sa</code>.</li>
    <li>Asigne <code>codigo_empresa</code> a las empresas (puede hacerlo desde el formulario arriba).</li>
    <li>Use estas páginas nuevas:
        <ul>
            <li><a href="nueva_cotizacion_v3.php">📄 nueva_cotizacion_v3.php</a> — Formulario nuevo con checkboxes de cuentas y sin Zona de Pricing</li>
            <li><a href="config_logo_v3.php">🖼️ config_logo_v3.php</a> — Subir y gestionar logos de las emisoras</li>
            <li><a href="seguimiento_comercial_v3.php">📋 seguimiento_comercial_v3.php</a> — Listado de cotizaciones con filtros</li>
        </ul>
    </li>
    <li>Estas páginas son INDEPENDIENTES del SPA. No dependen del router JavaScript.</li>
</ol>
</div>

</body>
</html>
