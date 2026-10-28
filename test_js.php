<?php
/**
 * test_js.php — Diagnóstico de archivos JS
 * ----------------------------------------------------------------------------
 * Verifica que los archivos JS existan, tengan tamaño razonable y NO
 * contengan HTML (lo que indicaría que IIS está reescribiendo a index.php).
 *
 * USO: abre en el navegador /test_js.php
 * No requiere autenticación.
 * ----------------------------------------------------------------------------
 */
header('Content-Type: text/plain; charset=UTF-8');

echo "==============================================================================\n";
echo "  Diagnóstico de archivos JS\n";
echo "==============================================================================\n\n";

$files = [
    'assets/js/app.js',
    'assets/js/dashboard.js',
    'assets/js/views.js',
    'pwa/push-client.js',
    'assets/css/app.css',
];

foreach ($files as $rel) {
    $full = __DIR__ . '/' . $rel;
    echo ">>> $rel\n";
    if (!is_file($full)) {
        echo "    [ERROR] El archivo NO existe en: $full\n";
        echo "           Esto significa que IIS reescribe la petición a index.php y devuelve HTML.\n";
        echo "           Solución: asegúrate de subir el archivo al servidor en la ruta correcta.\n\n";
        continue;
    }
    $size = filesize($full);
    $content = file_get_contents($full);
    $first100 = substr($content, 0, 100);
    $first3 = substr($content, 0, 3);
    $isBom = ($first3 === "\xEF\xBB\xBF");
    $isHtml = preg_match('/^\s*<(?:!doctype|html|head|body|script)\b/i', $content);
    $isPhp = (substr($content, 0, 5) === '<?php') || (substr($content, 0, 3) === '<? ');

    echo "    Ruta:        $full\n";
    echo "    Tamaño:      " . number_format($size) . " bytes (" . round($size / 1024, 1) . " KB)\n";
    echo "    Primeros 100 chars: " . trim(preg_replace('/\s+/', ' ', $first100)) . "\n";
    echo "    BOM UTF-8:   " . ($isBom ? 'SÍ (puede causar problemas)' : 'No') . "\n";
    echo "    Contiene HTML al inicio: " . ($isHtml ? 'SÍ (MALO - el archivo está corrupto)' : 'No') . "\n";
    echo "    Contiene PHP al inicio:  " . ($isPhp ? 'SÍ (MALO - es un .php, no .js)' : 'No') . "\n";

    if ($size < 100) {
        echo "    [WARN] Tamaño sospechosamente pequeño. ¿Archivo incompleto?\n";
    }
    if ($isHtml || $isPhp) {
        echo "    [ERROR] El archivo NO es JavaScript válido. Contiene HTML/PHP.\n";
        echo "            Causa probable: el archivo se subió corrupto o se reemplazó\n";
        echo "            con la salida de index.php (HTML de la SPA).\n";
    }
    echo "\n";
}

echo "==============================================================================\n";
echo "  Verificación de web.config (reglas de rewrite)\n";
echo "==============================================================================\n";
$webConfig = __DIR__ . '/web.config';
if (is_file($webConfig)) {
    $content = file_get_contents($webConfig);
    $hasFallbackRule = strpos($content, 'Frontend Routes') !== false;
    $hasIsFileCondition = strpos($content, 'IsFile') !== false;
    echo "  web.config existe:                 SÍ\n";
    echo "  Regla 'Frontend Routes' presente:  " . ($hasFallbackRule ? 'SÍ' : 'NO') . "\n";
    echo "  Condición IsFile (evita reescribir archivos existentes): " . ($hasIsFileCondition ? 'SÍ' : 'NO') . "\n";
    if (!$hasIsFileCondition) {
        echo "  [ERROR] Falta la condición IsFile. IIS reescribe TODAS las peticiones\n";
        echo "          a index.php, incluyendo /assets/js/views.js, devolviendo HTML.\n";
    }
} else {
    echo "  [ERROR] web.config NO existe.\n";
}
echo "\n";

echo "==============================================================================\n";
echo "  Información de PHP\n";
echo "==============================================================================\n";
echo "  Versión PHP:        " . PHP_VERSION . "\n";
echo "  SAPI:               " . PHP_SAPI . "\n";
echo "  Directorio actual:  " . __DIR__ . "\n";
echo "  ¿writeable?:        " . (is_writable(__DIR__ . '/assets/js/') ? 'Sí' : 'No') . "\n";
echo "\n";

echo "==============================================================================\n";
echo "  Recomendaciones\n";
echo "==============================================================================\n";
echo "  Si algún archivo JS aparece como 'contiene HTML al inicio' o 'no existe':\n";
echo "    1. Sube nuevamente el archivo desde el ZIP al servidor (vía FTP o copia).\n";
echo "    2. Asegúrate de que el archivo tenga permisos de lectura (chmod 644).\n";
echo "    3. Borra la caché del navegador (Ctrl+Shift+R o Ctrl+F5).\n";
echo "    4. Si usas Cloudflare o un CDN, purga la caché.\n";
echo "\n";
echo "  Si todos los archivos están OK pero sigue el error 'Unexpected token <':\n";
echo "    1. Abre las DevTools del navegador (F12) → pestaña Network\n";
echo "    2. Recarga la página con Ctrl+F5\n";
echo "    3. Busca la petición a views.js y mira qué devuelve el servidor\n";
echo "    4. Si devuelve HTML, el problema es el web.config o IIS reescribiendo.\n";
echo "==============================================================================\n";
