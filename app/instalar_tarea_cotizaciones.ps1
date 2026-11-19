#Requires -RunAsAdministrator
<#
  Financiera - tarea programada que actualiza las cotizaciones del BCRA.

  Que hace:
    1. Busca el php.exe que usa IIS (el mismo que el sitio).
    2. Prueba la actualizacion una vez y muestra el resultado.
    3. Crea (o reemplaza) la tarea "Financiera - Cotizaciones BCRA":
       de lunes a viernes a las 10:00, 16:30 y 19:30, como SYSTEM.
       La salida queda en D:\financiera\app\cron\cotizaciones.log

  Para cargar historia la primera vez (una sola vez):
    php D:\financiera\app\cron\actualizar_cotizaciones.php --historico=365
#>
param(
    [string]$App     = 'D:\financiera\app',
    [string]$Nombre  = 'Financiera - Cotizaciones BCRA',
    [string[]]$Horas = @('10:00', '16:30', '19:30')
)

$ErrorActionPreference = 'Stop'
try {
    Import-Module WebAdministration
    $apphost = 'MACHINE/WEBROOT/APPHOST'
    $h = Get-WebConfiguration -PSPath $apphost -Filter "system.webServer/handlers/add[@path='*.php']" | Select-Object -First 1
    if ($h) {
        $phpCgi = ($h.scriptProcessor -split '\|')[0]
    } else {
        $phpCgi = Get-WebConfiguration -PSPath $apphost -Filter 'system.webServer/fastCgi/application' |
                  Where-Object { $_.fullPath -match 'php-cgi\.exe$' } | Select-Object -First 1 -ExpandProperty fullPath
    }
    if (-not $phpCgi) { throw 'No encuentro el PHP de IIS.' }
    $php = Join-Path (Split-Path $phpCgi -Parent) 'php.exe'
    if (-not (Test-Path $php)) { throw "No existe $php" }
    Write-Host "PHP: $php"

    $script = Join-Path $App 'cron\actualizar_cotizaciones.php'
    $log    = Join-Path $App 'cron\cotizaciones.log'
    if (-not (Test-Path $script)) { throw "No existe $script" }

    Write-Host ''
    Write-Host 'Prueba:' -ForegroundColor Cyan
    & $php $script
    if ($LASTEXITCODE -ne 0) { Write-Warning 'La prueba fallo (ver el mensaje). La tarea se crea igual.' }

    $accion = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c `"`"$php`" `"$script`" >> `"$log`" 2>&1`"" -WorkingDirectory (Split-Path $script -Parent)
    $disparadores = foreach ($hora in $Horas) {
        New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday,Tuesday,Wednesday,Thursday,Friday -At $hora
    }
    $config = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
    $quien  = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName $Nombre -Action $accion -Trigger $disparadores -Settings $config -Principal $quien -Force | Out-Null

    Write-Host ''
    Write-Host "Listo: tarea '$Nombre' de lunes a viernes a las $($Horas -join ', ')." -ForegroundColor Green
    Write-Host "Salida en $log"
}
catch {
    Write-Host ''
    Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
}
