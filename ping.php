<?php
/* ping.php - Test minimo absoluto
 * Si esto NO funciona, el problema es de IIS/PHP (no de tu codigo).
 * Si esto SI funciona, el problema esta en otro archivo PHP.
 */
header('Content-Type: text/plain; charset=utf-8');
echo "PONG\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Server: " . php_uname() . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "SAPI: " . php_sapi_name() . "\n";
echo "Document root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a') . "\n";
echo "Script path: " . __FILE__ . "\n";

// Verificar extensiones criticas
echo "\nExtensiones:\n";
foreach (['pdo_sqlsrv', 'sqlsrv', 'pdo', 'openssl', 'mbstring', 'json', 'session'] as $ext) {
    echo "  - $ext: " . (extension_loaded($ext) ? 'OK' : 'FALTA') . "\n";
}

// Verificar variables de servidor
echo "\nVariables de servidor:\n";
foreach (['REQUEST_URI', 'PATH_INFO', 'SCRIPT_NAME', 'REQUEST_METHOD', 'HTTP_HOST'] as $v) {
    echo "  - $v: " . ($_SERVER[$v] ?? '(vacio)') . "\n";
}
