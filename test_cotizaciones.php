<?php
/**
 * test_cotizaciones.php
 *
 * Script de diagnóstico para verificar que el módulo de cotizaciones quedó
 * bien instalado. Cópielo a la raíz del proyecto (junto a index.php) y
 * ábralo en el navegador:
 *
 *   http://localhost:8036/test_cotizaciones.php
 *
 * IMPORTANTE: ELIMINE este archivo después de la verificación en producción.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

function check(string $label, bool $ok, string $extra = ''): string {
    $icon = $ok ? '✅' : '❌';
    $color = $ok ? '#28a745' : '#dc3545';
    return "<div style='padding:6px 0;border-bottom:1px solid #eee;'>
        <span style='color:$color;font-weight:bold;'>$icon</span>
        <strong>$label</strong>
        " . ($extra ? "<span style='color:#555;margin-left:10px;'>$extra</span>" : '') . "
    </div>";
}

echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
<title>Test módulo Cotizaciones</title>
<style>body{font-family:Segoe UI,Arial,sans-serif;max-width:900px;margin:40px auto;padding:20px;color:#2c3e50;}</style>
</head><body>
<h1>🧪 Diagnóstico módulo Cotizaciones</h1>
<p style='color:#7f8c8d;'>Verificando que el módulo quedó correctamente instalado...</p>
<div style='background:#f9fafc;padding:18px;border-radius:6px;margin-top:18px;'>";

/* ---------- 1) Conexión a BD ---------- */
try {
    $pdo = Database::connection();
    echo check('Conexión a base de datos MonosyncERP', true);
} catch (Throwable $e) {
    echo check('Conexión a base de datos MonosyncERP', false, $e->getMessage());
    echo "</div></body></html>";
    exit;
}

/* ---------- 2) Tabla cotizacion existe ---------- */
try {
    $c = $pdo->query("SELECT OBJECT_ID(N'dbo.cotizacion') AS id")->fetchColumn();
    echo check('Tabla dbo.cotizacion existe', !empty($c));
} catch (Throwable $e) {
    echo check('Tabla dbo.cotizacion existe', false, $e->getMessage());
}

/* ---------- 3) Tabla cotizacion_empresa_emisora + columna logo_base64 ---------- */
try {
    $stmt = $pdo->query("SELECT COL_LENGTH('dbo.cotizacion_empresa_emisora','logo_base64') AS len");
    $len = (int)$stmt->fetchColumn();
    echo check('Columna logo_base64 en cotizacion_empresa_emisora', $len > 0, "len=$len");
} catch (Throwable $e) {
    echo check('Columna logo_base64', false, $e->getMessage());
}

/* ---------- 4) Tabla ticket_documento + columna contenido ---------- */
try {
    $stmt = $pdo->query("SELECT COL_LENGTH('dbo.ticket_documento','contenido') AS len");
    $len = (int)$stmt->fetchColumn();
    echo check('Columna contenido en ticket_documento', $len > 0, "len=$len");
} catch (Throwable $e) {
    echo check('Columna contenido', false, $e->getMessage());
}

/* ---------- 5) SPs requeridos ---------- */
$sps = [
    'sp_ticket_documento_save',
    'sp_cotizacion_emisora_logo_save',
    'sp_cotizacion_emisora_listar',
    'sp_cotizacion_emisora_get',
    'sp_cotizacion_emisora_save',
    'sp_cotizacion_create_v4',
    'sp_cotizacion_get',
    'sp_cotizacion_list_paged',
    'sp_cotizacion_update',
    'sp_cotizacion_update_status',
    'sp_cotizacion_delete_admin',
    'sp_cotizacion_add_comunicacion',
    'sp_cotizacion_calcular_totales',
    'sp_cotizacion_catalogos_listar',
    'sp_cotizacion_documento_save',
    'sp_cotizacion_documento_get',
];
foreach ($sps as $sp) {
    try {
        $id = $pdo->query("SELECT OBJECT_ID(N'dbo.$sp', N'P')")->fetchColumn();
        echo check("SP <code>$sp</code>", !empty($id));
    } catch (Throwable $e) {
        echo check("SP <code>$sp</code>", false, $e->getMessage());
    }
}

/* ---------- 6) Módulo COTIZACIONES en catálogo ---------- */
try {
    $stmt = $pdo->query("SELECT id_modulo, activo FROM dbo.modulo_catalogo WHERE codigo = 'COTIZACIONES'");
    $row = $stmt->fetch();
    echo check('Módulo COTIZACIONES registrado', !empty($row), $row ? "id={$row['id_modulo']} activo={$row['activo']}" : 'NO EXISTE');
} catch (Throwable $e) {
    echo check('Módulo COTIZACIONES registrado', false, $e->getMessage());
}

/* ---------- 7) Permisos COTIZACIONES por rol ---------- */
try {
    $stmt = $pdo->query("SELECT rp.id_rol, pr.nombre AS rol,
                                rp.ver, rp.registrar, rp.modificar, rp.eliminar,
                                rp.aprobar, rp.exportar, rp.imprimir
                         FROM dbo.role_permissions rp
                         LEFT JOIN dbo.persona_rol pr ON rp.id_rol = pr.id_rol
                         WHERE rp.modulo = 'COTIZACIONES'
                         ORDER BY rp.id_rol");
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        echo check('Permisos COTIZACIONES asignados', false, 'Ningún rol tiene permisos');
    } else {
        echo check('Permisos COTIZACIONES asignados', true, count($rows) . ' rol(es)');
        echo "<div style='margin-left:30px;font-size:12px;'>";
        foreach ($rows as $r) {
            $flags = [];
            foreach (['ver','registrar','modificar','eliminar','aprobar','exportar','imprimir'] as $f) {
                if ((int)$r[$f] === 1) $flags[] = $f;
            }
            echo "<div>rol #{$r['id_rol']} ({$r['rol']}): " . implode(', ', $flags) . "</div>";
        }
        echo "</div>";
    }
} catch (Throwable $e) {
    echo check('Permisos COTIZACIONES asignados', false, $e->getMessage());
}

/* ---------- 8) Empresas emisoras cargadas ---------- */
try {
    $stmt = $pdo->query("SELECT id_emisora, razon_social, ruc,
                                CASE WHEN logo_base64 IS NULL THEN 0 ELSE 1 END AS tiene_logo
                         FROM dbo.cotizacion_empresa_emisora
                         ORDER BY es_principal DESC, razon_social");
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        echo check('Empresas emisoras cargadas', false, 'La tabla está vacía. Ejecutar el script SQL.');
    } else {
        echo check('Empresas emisoras cargadas', true, count($rows) . ' emisora(s)');
        echo "<div style='margin-left:30px;font-size:12px;'>";
        foreach ($rows as $r) {
            $logoTxt = $r['tiene_logo'] ? '🎨 con logo' : '⚠ sin logo';
            echo "<div>#{$r['id_emisora']} {$r['razon_social']} (RUC: {$r['ruc']}) — $logoTxt</div>";
        }
        echo "</div>";
    }
} catch (Throwable $e) {
    echo check('Empresas emisoras cargadas', false, $e->getMessage());
}

/* ---------- 9) Catálogos con datos ---------- */
$catalogos = [
    'cotizacion_tipo_catalogo'   => 'tipos',
    'cotizacion_forma_pago'      => 'formas de pago',
    'cotizacion_garantia'        => 'garantías',
    'cotizacion_plazo_entrega'   => 'plazos de entrega',
    'cotizacion_validez'         => 'valideces',
    'cotizacion_zona_pricing'    => 'zonas de pricing',
];
foreach ($catalogos as $tabla => $nombre) {
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM dbo.$tabla WHERE activo = 1")->fetchColumn();
        echo check("Catálogo <code>$nombre</code>", $count > 0, "$count registro(s)");
    } catch (Throwable $e) {
        echo check("Catálogo <code>$nombre</code>", false, $e->getMessage());
    }
}

/* ---------- 10) Archivos PHP requeridos ---------- */
$archivos = [
    'api/routes/cotizaciones.php',
    'api/routes/cotizaciones_emisora.php',
    'api/routes/cotizaciones_catalogos.php',
    'api/routes/cotizaciones_documentos.php',
    'api/routes/cotizaciones_export.php',
    'api/routes/cotizaciones_print.php',
    'assets/js/cotizaciones.js',
];
echo "</div><h2 style='margin-top:30px;'>📁 Archivos</h2><div style='background:#f9fafc;padding:18px;border-radius:6px;'>";
foreach ($archivos as $f) {
    $exists = is_file(__DIR__ . '/' . $f);
    echo check("<code>$f</code>", $exists);
}

/* ---------- Resumen final ---------- */
echo "</div>
<div style='margin-top:30px;padding:14px;background:#eaf4ff;border-left:4px solid #007bff;border-radius:4px;'>
    <strong>📌 Próximos pasos:</strong>
    <ol style='margin:8px 0 0 18px;line-height:1.7;'>
        <li>Si algún check está ❌, ejecuta de nuevo <code>scripts_sql/12_cotizaciones_completo.sql</code>.</li>
        <li>Si falta algún archivo PHP/JS, copia los archivos del paquete a las rutas indicadas.</li>
        <li>Edita <code>index.php</code> para agregar el script <code>cotizaciones.js</code> y el menú lateral (ver README.md).</li>
        <li>Cierra sesión y vuelve a entrar (para refrescar permisos en caché).</li>
        <li>Abre la app y navega a <code>/#/cotizaciones</code>.</li>
        <li>Para probar el logo: ve a <em>Cotizaciones → Empresas Emisoras</em> y súbelo desde ahí.</li>
        <li>Para probar impresión: crea una cotización y presiona el botón <em>Imprimir / PDF</em>.</li>
        <li>Para probar exportación: en el listado, presiona <em>Exportar → CSV/XLSX/HTML</em>.</li>
    </ol>
</div>
<p style='margin-top:30px;color:#95a5a6;font-size:11px;'>
    <strong>⚠️ IMPORTANTE:</strong> elimina este archivo <code>test_cotizaciones.php</code>
    después del diagnóstico en entornos de producción.
</p>
</body></html>";

