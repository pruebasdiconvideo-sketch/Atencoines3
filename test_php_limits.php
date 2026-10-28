<?php
/**
 * test_php_limits.php
 * ----------------------------------------------------------------------------
 * Página de diagnóstico que muestra los límites actuales de PHP para subida
 * de archivos. Útil para diagnosticar por qué falla la subida de archivos
 * pesados en tickets.
 *
 * USO: Abre https://tu-dominio/test_php_limits.php en el navegador.
 * BORRAR después de probar.
 */

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>PHP Limits</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;}";
echo "table{border-collapse:collapse;width:100%;}th,td{border:1px solid #ccc;padding:8px;}";
echo "th{background:#007bff;color:#fff;}td{background:#fff;}";
echo ".ok{color:green;font-weight:bold;}.fail{color:red;font-weight:bold;}.warn{color:orange;font-weight:bold;}";
echo "</style></head><body>";

echo "<h1>Diagnóstico de límites PHP para subida de archivos</h1>";

// Función para formatear bytes
function formatBytes($val) {
    $val = trim($val);
    if ($val === '') return '0 B';
    $last = strtolower($val[strlen($val)-1]);
    $num = (int)$val;
    switch($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    if ($num < 1024) return $val . ' (' . $num . ' B)';
    if ($num < 1048576) return $val . ' (' . round($num/1024,1) . ' KB)';
    return $val . ' (' . round($num/1048576,1) . ' MB)';
}

// Límites críticos
$limits = [
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_execution_time' => ini_get('max_execution_time') . 's',
    'max_input_time' => ini_get('max_input_time') . 's',
    'memory_limit' => ini_get('memory_limit'),
];

echo "<h2>Límites actuales de PHP</h2>";
echo "<table><tr><th>Directiva</th><th>Valor actual</th><th>Recomendado</th><th>Estado</th></tr>";

$recomendados = [
    'upload_max_filesize' => '200M',
    'post_max_size' => '210M',
    'max_execution_time' => '300s',
    'max_input_time' => '300s',
    'memory_limit' => '512M',
];

foreach ($limits as $key => $val) {
    $rec = $recomendados[$key] ?? '—';
    $ok = true;
    if ($key === 'upload_max_filesize') {
        $ok = (int)$val >= 200 || (strpos($val, 'M') !== false && (int)$val >= 200);
    }
    if ($key === 'post_max_size') {
        $ok = (int)$val >= 210 || (strpos($val, 'M') !== false && (int)$val >= 210);
    }
    echo "<tr><td>$key</td><td>$val</td><td>$rec</td><td class='" . ($ok ? 'ok' : 'fail') . "'>" . ($ok ? '✓ OK' : '✗ FALLA') . "</td></tr>";
}
echo "</table>";

echo "<h2>Información del servidor</h2>";
echo "<table><tr><th>Parámetro</th><th>Valor</th></tr>";
echo "<tr><td>PHP Version</td><td>" . phpversion() . "</td></tr>";
echo "<tr><td>Server Software</td><td>" . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "</td></tr>";
echo "<tr><td>Document Root</td><td>" . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "</td></tr>";
echo "<tr><td>Script Path</td><td>" . __DIR__ . "</td></tr>";
echo "<tr><td>.user.ini exists?</td><td class='" . (file_exists(__DIR__ . '/.user.ini') ? 'ok' : 'fail') . "'>" . (file_exists(__DIR__ . '/.user.ini') ? 'SÍ ✓' : 'NO ✗') . "</td></tr>";
echo "<tr><td>user_ini.filename</td><td>" . ini_get('user_ini.filename') . " (" . (ini_get('user_ini.filename') ? 'configurado' : 'default: .user.ini') . ")</td></tr>";
echo "<tr><td>Loaded Configuration File</td><td>" . php_ini_loaded_file() . "</td></tr>";
echo "<tr><td>Additional .ini files</td><td>" . implode('<br>', explode(',', php_ini_scanned_files() ?: '(none)')) . "</td></tr>";
echo "</table>";

echo "<h2>Diagnóstico</h2>";
$uploadMax = ini_get('upload_max_filesize');
$postMax = ini_get('post_max_size');

if ((int)$uploadMax < 200 && strpos($uploadMax, 'M') !== false && (int)$uploadMax < 200) {
    echo "<div style='background:#f8d7da;padding:15px;border-radius:5px;margin:10px 0;'>";
    echo "<strong style='color:#721c24;'>⚠ PROBLEMA DETECTADO: upload_max_filesize = $uploadMax</strong><br>";
    echo "<p>El límite de subida de archivos es demasiado bajo. Para solucionarlo:</p>";
    echo "<ol>";
    echo "<li>Verifica que el archivo <code>.user.ini</code> exista en la carpeta raíz del sitio web (donde está index.php).</li>";
    echo "<li>El archivo <code>.user.ini</code> debe contener: <code>upload_max_filesize = 200M</code></li>";
    echo "<li>Después de subirlo, <strong>reinicia el Application Pool de IIS</strong> (IIS Manager → Application Pools → Recycle).</li>";
    echo "<li>Si .user.ini no funciona, edita el <code>php.ini</code> global del servidor:</li>";
    echo "<ul>";
    echo "<li>Ábrelo con: <code>" . php_ini_loaded_file() . "</code></li>";
    echo "<li>Cambia: <code>upload_max_filesize = 200M</code> y <code>post_max_size = 210M</code></li>";
    echo "<li>Reinicia IIS: <code>iisreset</code> en CMD como administrador.</li>";
    echo "</ul>";
    echo "</ol>";
    echo "</div>";
} else {
    echo "<div style='background:#d4edda;padding:15px;border-radius:5px;margin:10px 0;'>";
    echo "<strong style='color:#155724;'>✓ Límites correctos. Los archivos se pueden subir sin problema.</strong>";
    echo "</div>";
}

echo "<h2>Contenido de .user.ini (si existe)</h2>";
if (file_exists(__DIR__ . '/.user.ini')) {
    echo "<pre style='background:#fff;border:1px solid #ccc;padding:10px;overflow:auto;'>" . htmlspecialchars(file_get_contents(__DIR__ . '/.user.ini')) . "</pre>";
} else {
    echo "<div style='background:#f8d7da;padding:15px;border-radius:5px;'>";
    echo "<strong>El archivo .user.ini NO existe en esta carpeta.</strong><br>";
    echo "Debes crearlo o subirlo. El archivo debe estar en: <code>" . __DIR__ . "/.user.ini</code>";
    echo "</div>";
}

echo "<p style='margin-top:20px;color:#6c757d;'>⚠️ BORRA este archivo del servidor después de diagnosticar (expone configuración interna).</p>";
echo "</body></html>";
