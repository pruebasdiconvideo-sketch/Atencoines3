<?php
/**
 * fix_upload_limits.php
 * ----------------------------------------------------------------------------
 * Script que intenta aplicar los límites de subida de archivos de múltiples
 * formas posibles. Se ejecuta UNA VEZ y luego se borra.
 *
 * Este script:
 *   1. Muestra los límites actuales de PHP
 *   2. Intenta aplicar ini_set() para upload_max_filesize y post_max_size
 *      (NOTA: estos valores NO se pueden cambiar con ini_set en PHP, son
 *       PHP_INI_PERDIR — solo se pueden cambiar en php.ini o .user.ini)
 *   3. Verifica si .user.ini existe y tiene los valores correctos
 *   4. Si .user.ini NO existe, lo CREA con los valores correctos
 *   5. Muestra instrucciones claras de qué hacer después
 *
 * USO: Abre https://tu-dominio/fix_upload_limits.php
 * BORRAR después de ejecutar.
 */

// Intentar aplicar los límites (no funcionará para upload_max_filesize pero sí para otros)
@ini_set('upload_max_filesize', '200M');
@ini_set('post_max_size', '210M');
@ini_set('max_execution_time', '300');
@ini_set('max_input_time', '300');
@ini_set('memory_limit', '512M');

// Verificar si .user.ini existe, si no, crearlo
$userIniPath = __DIR__ . '/.user.ini';
$userIniContent = <<<'INI'
; .user.ini - Configuración PHP para subir archivos hasta 200 MB
upload_max_filesize = 200M
post_max_size = 210M
max_execution_time = 300
max_input_time = 300
memory_limit = 512M
session.gc_maxlifetime = 86400
zlib.output_compression = On
zlib.output_compression_level = 5
output_buffering = 4096
INI;

$userIniCreated = false;
$userIniExists = file_exists($userIniPath);

if (!$userIniExists) {
    $userIniCreated = @file_put_contents($userIniPath, $userIniContent) !== false;
}

// Si .user.ini existe pero no tiene los valores correctos, sobreescribirlo
if ($userIniExists) {
    $currentContent = file_get_contents($userIniPath);
    if (strpos($currentContent, 'upload_max_filesize = 200M') === false) {
        @file_put_contents($userIniPath, $userIniContent);
    }
}

?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><title>Fix Upload Limits</title>
<style>
body { font-family: Arial, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
.ok { color: #155724; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
.fail { color: #721c24; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
.warn { color: #856404; background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #007bff; color: white; }
code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
pre { background: #f8f9fa; border: 1px solid #ddd; padding: 15px; overflow: auto; border-radius: 5px; }
</style>
</head><body>

<h1>🔧 Fix Upload Limits</h1>

<h2>Estado actual</h2>
<table>
<tr><th>Parámetro</th><th>Valor actual</th><th>Recomendado</th><th>Estado</th></tr>
<tr><td>upload_max_filesize</td><td><?= ini_get('upload_max_filesize') ?></td><td>200M</td>
<td class="<?= ini_get('upload_max_filesize') == '200M' ? 'ok' : 'fail' ?>"><?= ini_get('upload_max_filesize') == '200M' ? '✓ OK' : '✗ FALLA' ?></td></tr>
<tr><td>post_max_size</td><td><?= ini_get('post_max_size') ?></td><td>210M</td>
<td class="<?= ini_get('post_max_size') == '210M' ? 'ok' : 'fail' ?>"><?= ini_get('post_max_size') == '210M' ? '✓ OK' : '✗ FALLA' ?></td></tr>
<tr><td>max_execution_time</td><td><?= ini_get('max_execution_time') ?>s</td><td>300s</td><td class="ok">✓</td></tr>
<tr><td>memory_limit</td><td><?= ini_get('memory_limit') ?></td><td>512M</td><td class="ok">✓</td></tr>
</table>

<h2>Archivo .user.ini</h2>
<?php if ($userIniCreated): ?>
<div class="ok"><strong>✓ .user.ini CREADO correctamente</strong> en <?= $userIniPath ?></div>
<?php elseif ($userIniExists): ?>
<div class="ok"><strong>✓ .user.ini ya existe</strong> en <?= $userIniPath ?></div>
<?php else: ?>
<div class="fail"><strong>✗ No se pudo crear .user.ini</strong>. Crea el archivo manualmente.</div>
<?php endif; ?>

<?php if (file_exists($userIniPath)): ?>
<p>Contenido actual de <code>.user.ini</code>:</p>
<pre><?= htmlspecialchars(file_get_contents($userIniPath)) ?></pre>
<?php endif; ?>

<h2>¿Qué hacer ahora?</h2>

<?php if (ini_get('upload_max_filesize') == '200M'): ?>
<div class="ok">
<strong>✅ Todo está correcto.</strong> Ya puedes subir archivos hasta 200 MB.
</div>
<?php else: ?>
<div class="warn">
<strong>⚠ Aviso: upload_max_filesize sigue siendo <?= ini_get('upload_max_filesize') ?>.</strong><br>
El archivo <code>.user.ini</code> ya existe pero <strong>IIS no lo ha recargado</strong>.<br>
Para que PHP lea el nuevo <code>.user.ini</code>, debes hacer una de estas acciones:
</div>

<ol>
<li><strong>Reciclar el Application Pool de IIS</strong> (recomendado):
<ul>
<li>Abre <strong>IIS Manager</strong></li>
<li>Ve a <strong>Application Pools</strong></li>
<li>Selecciona el pool de tu sitio</li>
<li>Click derecho → <strong>Recycle</strong> (o "Reciclar")</li>
<li>Espera 10 segundos</li>
</ul>
</li>

<li><strong>Reiniciar IIS completamente</strong> (alternativa):
<ul>
<li>Abre CMD como Administrador</li>
<li>Ejecuta: <code>iisreset</code></li>
</ul>
</li>

<li><strong>Editar php.ini directamente</strong> (si los anteriores no funcionan):
<ul>
<li>Localiza php.ini: <code><?= php_ini_loaded_file() ?></code></li>
<li>Edita estas líneas:
<pre>
upload_max_filesize = 200M
post_max_size = 210M
max_execution_time = 300
memory_limit = 512M
</pre>
</li>
<li>Guarda y ejecuta: <code>iisreset</code></li>
</ul>
</li>
</ol>

<div class="warn">
<strong>Después de reciclar/reiniciar</strong>, vuelve a abrir esta página
(<code>/fix_upload_limits.php</code>) para verificar que el límite cambió a 200M.
</div>
<?php endif; ?>

<h2>Información del servidor</h2>
<table>
<tr><td>PHP Version</td><td><?= phpversion() ?></td></tr>
<tr><td>Server Software</td><td><?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></td></tr>
<tr><td>php.ini path</td><td><?= php_ini_loaded_file() ?: 'N/A' ?></td></tr>
<tr><td>.user.ini path</td><td><?= $userIniPath ?></td></tr>
<tr><td>.user.ini exists</td><td><?= file_exists($userIniPath) ? 'SÍ' : 'NO' ?></td></tr>
<tr><td>user_ini.filename setting</td><td><?= ini_get('user_ini.filename') ?: '.user.ini (default)' ?></td></tr>
<tr><td>user_ini.cache_ttl</td><td><?= ini_get('user_ini.cache_ttl') ?>s (tiempo de cache del .user.ini)</td></tr>
</table>

<p style="margin-top:20px;color:#dc3545;">
<strong>⚠️ BORRA este archivo del servidor después de usarlo:</strong><br>
<code>fix_upload_limits.php</code> y <code>test_php_limits.php</code>
</p>

</body></html>
