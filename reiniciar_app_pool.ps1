# ============================================================================
#  reiniciar_app_pool.ps1
#  Ejecutar como Administrador
# ============================================================================

Write-Host "=== Reiniciando App Pool webfinal ===" -ForegroundColor Cyan

Import-Module WebAdministration

# 1. Detener el App Pool si esta corriendo
try {
    Stop-WebAppPool -Name "webfinal" -ErrorAction SilentlyContinue
    Write-Host "[OK] App Pool detenido" -ForegroundColor Green
} catch {
    Write-Host "[INFO] App Pool ya estaba detenido" -ForegroundColor Yellow
}

# 2. Esperar a que libere recursos
Start-Sleep -Seconds 3

# 3. (Opcional) Desactivar Rapid Fail Protection temporalmente
# Para que IIS no cancele el App Pool si PHP falla repetidamente
Set-ItemProperty "IIS:\AppPools\webfinal" -Name failure.rapidFailProtection -Value $false
Write-Host "[OK] Rapid Fail Protection desactivado (diagnostico)" -ForegroundColor Green

# 4. Iniciar el App Pool
try {
    Start-WebAppPool -Name "webfinal"
    Write-Host "[OK] App Pool iniciado" -ForegroundColor Green
} catch {
    Write-Host "[ERROR] No se pudo iniciar: $_" -ForegroundColor Red
    # Intentar via WMI
    try {
        (Get-WmiObject -Namespace "root\WebAdministration" -Class "ApplicationPool" | Where-Object { $_.Name -eq "webfinal" }).Start()
        Write-Host "[OK] App Pool iniciado via WMI" -ForegroundColor Green
    } catch {
        Write-Host "[ERROR] Tambien fallo via WMI: $_" -ForegroundColor Red
    }
}

# 5. Verificar estado
Start-Sleep -Seconds 2
$pool = Get-IISAppPool "webfinal"
Write-Host ""
Write-Host "Estado del App Pool:"
$pool | Select-Object Name, State, WorkerProcesses | Format-List

# 6. Reiniciar el sitio web
try {
    Restart-WebItem "IIS:\Sites\webfinal"
    Write-Host "[OK] Sitio reiniciado" -ForegroundColor Green
} catch {
    Write-Host "[WARN] No se pudo reiniciar sitio: $_" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== Verificacion ===" -ForegroundColor Cyan
Get-Website "webfinal" | Select-Object Name, State, PhysicalPath | Format-List

Write-Host ""
Write-Host "Listo. Probar ahora: http://localhost:8036/ping.php" -ForegroundColor Cyan
Write-Host "Despues: http://localhost:8036/api/index.php" -ForegroundColor Cyan
