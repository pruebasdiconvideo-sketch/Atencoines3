# ============================================================================
# fix_upload_limits.ps1
# ----------------------------------------------------------------------------
# Script PowerShell para modificar php.ini directamente y aumentar el límite
# de subida de archivos a 200 MB.
#
# EJECUTAR en el servidor como Administrador:
#   1. Descarga este archivo al servidor
#   2. Abre PowerShell como Administrador
#   3. Ejecuta: .\fix_upload_limits.ps1
#
# El script:
#   - Busca el archivo php.ini del servidor
#   - Modifica upload_max_filesize, post_max_size, max_execution_time, memory_limit
#   - Reinicia IIS
# ============================================================================

Write-Host "====================================================================" -ForegroundColor Cyan
Write-Host "  FIX UPLOAD LIMITS - Modificar php.ini directamente" -ForegroundColor Cyan
Write-Host "====================================================================" -ForegroundColor Cyan

# Buscar php.ini
$phpIni = $null

# Método 1: Buscar en las rutas comunes
$possiblePaths = @(
    "C:\PHP\php.ini",
    "C:\Program Files\PHP\v8.1\php.ini",
    "C:\Program Files\PHP\v8.2\php.ini",
    "C:\Program Files\PHP\v8.3\php.ini",
    "C:\Program Files (x86)\PHP\v8.1\php.ini",
    "C:\Program Files (x86)\PHP\v8.2\php.ini",
    "C:\inetpub\php\php.ini",
    "C:\PHP\php.ini"
)

foreach ($path in $possiblePaths) {
    if (Test-Path $path) {
        $phpIni = $path
        break
    }
}

# Método 2: Usar la salida de php --ini
if (!$phpIni) {
    try {
        $phpInfo = & php --ini 2>&1
        $phpIni = ($phpInfo | Select-String "Loaded Configuration File:\s*(.+)").Matches.Groups[1].Value.Trim()
    } catch {}
}

# Método 3: Buscar con Get-ChildItem
if (!$phpIni) {
    Write-Host "Buscando php.ini en el sistema..." -ForegroundColor Yellow
    $found = Get-ChildItem -Path "C:\" -Filter "php.ini" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($found) {
        $phpIni = $found.FullName
    }
}

if (!$phpIni -or !(Test-Path $phpIni)) {
    Write-Host "ERROR: No se encontro php.ini en el sistema." -ForegroundColor Red
    Write-Host "Rutas verificadas:" -ForegroundColor Yellow
    foreach ($p in $possiblePaths) { Write-Host "  - $p" }
    Write-Host ""
    Write-Host "Solucion manual:" -ForegroundColor Yellow
    Write-Host "  1. Abre IIS Manager"
    Write-Host "  2. Ve a tu sitio web → PHP Manager"
    Write-Host "  3. Click 'Manage PHP settings' o similar"
    Write-Host "  4. Edita estas lineas:"
    Write-Host "     upload_max_filesize = 200M"
    Write-Host "     post_max_size = 210M"
    Write-Host "     max_execution_time = 300"
    Write-Host "     memory_limit = 512M"
    Write-Host "  5. Guarda y ejecuta: iisreset"
    exit 1
}

Write-Host "php.ini encontrado: $phpIni" -ForegroundColor Green

# Hacer backup
$backup = "$phpIni.bak_$(Get-Date -Format 'yyyyMMdd_HHmmss')"
Copy-Item $phpIni $backup
Write-Host "Backup creado: $backup" -ForegroundColor Green

# Leer contenido
$content = Get-Content $phpIni -Raw

# Función para reemplazar o agregar una directiva
function Set-PhpIniDirective {
    param($content, $key, $value)
    $pattern = "(?m)^\s*;?\s*$key\s*=\s*.*$"
    $replacement = "$key = $value"
    if ($content -match $pattern) {
        $content = $content -replace $pattern, $replacement
    } else {
        $content += "`r`n$key = $value"
    }
    return $content
}

# Modificar directivas
Write-Host "Modificando directivas..." -ForegroundColor Yellow
$content = Set-PhpIniDirective $content "upload_max_filesize" "200M"
$content = Set-PhpIniDirective $content "post_max_size" "210M"
$content = Set-PhpIniDirective $content "max_execution_time" "300"
$content = Set-PhpIniDirective $content "max_input_time" "300"
$content = Set-PhpIniDirective $content "memory_limit" "512M"

# Guardar
$content | Set-Content $phpIni -NoNewline
Write-Host "php.ini modificado correctamente." -ForegroundColor Green

# Verificar
Write-Host ""
Write-Host "Verificando cambios:" -ForegroundColor Cyan
$verifyContent = Get-Content $phpIni
foreach ($key in @("upload_max_filesize", "post_max_size", "max_execution_time", "memory_limit")) {
    $line = $verifyContent | Select-String "^\s*$key\s*=" | Select-Object -First 1
    if ($line) {
        Write-Host "  $line" -ForegroundColor Green
    } else {
        Write-Host "  $key: NO ENCONTRADO" -ForegroundColor Red
    }
}

# Reiniciar IIS
Write-Host ""
Write-Host "Reiniciando IIS..." -ForegroundColor Yellow
try {
    iisreset
    Write-Host "IIS reiniciado correctamente." -ForegroundColor Green
} catch {
    Write-Host "No se pudo reiniciar IIS automaticamente." -ForegroundColor Red
    Write-Host "Ejecuta manualmente: iisreset (como administrador)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "====================================================================" -ForegroundColor Green
Write-Host "  COMPLETADO. Ahora puedes subir archivos hasta 200 MB." -ForegroundColor Green
Write-Host "====================================================================" -ForegroundColor Green
Write-Host ""
Write-Host "Verifica abriendo: https://tu-dominio/test_php_limits.php" -ForegroundColor Cyan
