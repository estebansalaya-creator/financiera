#Requires -RunAsAdministrator
<#
  Financiera - crea el sitio del backoffice en IIS.

  Que hace:
    1. Busca el php-cgi.exe que IIS ya usa (handler *.php o FastCGI) y verifica pdo_mysql.
    2. Crea el grupo de aplicaciones "financiera" (sin .NET).
    3. Crea el sitio "financiera" apuntando a D:\financiera\app\public, en el primer puerto libre desde 8081.
    4. Si no hay handler de PHP a nivel servidor, lo agrega solo para este sitio.
    5. Da permiso de lectura sobre D:\financiera\app al grupo de aplicaciones y a IUSR,
       y de modificacion sobre D:\financiera\documentos (donde se guarda la documentacion)
       y D:\financiera\sesiones (donde PHP guarda las sesiones de los usuarios).
    6. Prueba la pagina y la abre en el navegador.

  Se puede correr mas de una vez: si el sitio ya existe, no lo duplica.
  Deja el detalle en D:\financiera\app\instalar_iis.log
#>
param(
    [string]$Nombre = 'financiera',
    [int]$Puerto    = 8081,
    [string]$Ruta   = 'D:\financiera\app\public',
    [string]$Docs   = 'D:\financiera\documentos'
)

$ErrorActionPreference = 'Stop'
$raiz = Split-Path $Ruta -Parent
$log  = Join-Path $raiz 'instalar_iis.log'
Start-Transcript -Path $log -Force | Out-Null

function Paso($t) { Write-Host ''; Write-Host "== $t" -ForegroundColor Cyan }

try {
    Import-Module WebAdministration
    $apphost = 'MACHINE/WEBROOT/APPHOST'

    if (-not (Test-Path (Join-Path $Ruta 'comisiones.php'))) {
        throw "No encuentro $Ruta\comisiones.php"
    }

    # -----------------------------------------------------------------
    Paso 'PHP'
    $handlerSrv = Get-WebConfiguration -PSPath $apphost -Filter "system.webServer/handlers/add[@path='*.php']" |
                  Select-Object -First 1
    $phpCgi = $null
    if ($handlerSrv) {
        $phpCgi = ($handlerSrv.scriptProcessor -split '\|')[0]
        Write-Host "Handler *.php a nivel servidor: $($handlerSrv.name) -> $phpCgi"
    } else {
        $phpCgi = Get-WebConfiguration -PSPath $apphost -Filter 'system.webServer/fastCgi/application' |
                  Where-Object { $_.fullPath -match 'php-cgi\.exe$' } |
                  Select-Object -First 1 -ExpandProperty fullPath
        if (-not $phpCgi) { throw 'IIS no tiene PHP registrado (ni handler *.php ni FastCGI con php-cgi.exe).' }
        Write-Host "Sin handler *.php a nivel servidor; uso FastCGI: $phpCgi"
    }
    if (-not (Test-Path $phpCgi)) { throw "No existe $phpCgi" }

    $phpExe = Join-Path (Split-Path $phpCgi -Parent) 'php.exe'
    $phpBin = if (Test-Path $phpExe) { $phpExe } else { $phpCgi }
    $ver = (& $phpBin -r 'echo PHP_VERSION;') 2>$null
    Write-Host "Version de PHP: $ver"
    if ($ver -and ([version]($ver -replace '[^0-9.].*$','') -lt [version]'7.1')) {
        Write-Warning 'La app necesita PHP 7.1 o superior.'
    }
    $mods = (& $phpBin -m) 2>$null | ForEach-Object { $_.Trim() }
    if ($mods -contains 'pdo_mysql') {
        Write-Host 'pdo_mysql: habilitado'
    } else {
        Write-Warning 'pdo_mysql NO esta habilitado: agregar extension=pdo_mysql en php.ini y reiniciar IIS (iisreset).'
    }

    # -----------------------------------------------------------------
    Paso 'Grupo de aplicaciones'
    if (-not (Test-Path "IIS:\AppPools\$Nombre")) {
        New-WebAppPool -Name $Nombre | Out-Null
        Write-Host "Creado: $Nombre"
    } else {
        Write-Host "Ya existia: $Nombre"
    }
    Set-ItemProperty "IIS:\AppPools\$Nombre" -Name managedRuntimeVersion -Value ''

    # -----------------------------------------------------------------
    Paso 'Sitio'
    $sitio = Get-Website | Where-Object { $_.Name -eq $Nombre }
    if ($sitio) {
        Set-ItemProperty "IIS:\Sites\$Nombre" -Name physicalPath -Value $Ruta
        Set-ItemProperty "IIS:\Sites\$Nombre" -Name applicationPool -Value $Nombre
        $Puerto = [int](($sitio.bindings.Collection | Select-Object -First 1).bindingInformation -split ':')[1]
        Write-Host "Ya existia; ruta actualizada. Puerto: $Puerto"
    } else {
        $ocupados = @(Get-WebBinding | Where-Object { $_.protocol -in 'http','https' } |
                      ForEach-Object { [int]($_.bindingInformation -split ':')[1] })
        while (($ocupados -contains $Puerto) -or
               (Get-NetTCPConnection -LocalPort $Puerto -State Listen -ErrorAction SilentlyContinue)) {
            $Puerto++
        }
        New-Website -Name $Nombre -Port $Puerto -PhysicalPath $Ruta -ApplicationPool $Nombre | Out-Null
        Write-Host "Creado: $Nombre en el puerto $Puerto -> $Ruta"
    }

    # -----------------------------------------------------------------
    Paso 'Handler de PHP'
    if ($handlerSrv) {
        Write-Host 'Se usa el handler del servidor.'
    } else {
        $yaTiene = Get-WebConfiguration -PSPath $apphost -Location $Nombre -Filter "system.webServer/handlers/add[@path='*.php']"
        if (-not $yaTiene) {
            Add-WebConfigurationProperty -PSPath $apphost -Location $Nombre -Filter 'system.webServer/handlers' -Name '.' -Value @{
                name = 'PHP_via_FastCGI'; path = '*.php'; verb = '*'; modules = 'FastCgiModule';
                scriptProcessor = $phpCgi; resourceType = 'File'
            }
            Write-Host "Agregado solo para el sitio: *.php -> $phpCgi"
        } else {
            Write-Host 'El sitio ya tenia handler *.php.'
        }
    }

    # -----------------------------------------------------------------
    Paso 'Permisos'
    & icacls $raiz /grant "IIS AppPool\$($Nombre):(OI)(CI)RX" | Out-Null
    & icacls $raiz /grant 'IUSR:(OI)(CI)RX' | Out-Null
    Write-Host "Lectura sobre $raiz para IIS AppPool\$Nombre e IUSR"
    if (-not (Test-Path $Docs)) { New-Item -ItemType Directory -Path $Docs | Out-Null }
    & icacls $Docs /grant "IIS AppPool\$($Nombre):(OI)(CI)M" | Out-Null
    if (-not (Test-Path $Sesiones)) { New-Item -ItemType Directory -Path $Sesiones | Out-Null }
    & icacls $Sesiones /grant "IIS AppPool\$($Nombre):(OI)(CI)M" | Out-Null
    Write-Host "Modificacion sobre $Docs para IIS AppPool\$Nombre (documentacion de clientes)"

    # -----------------------------------------------------------------
    Paso 'Prueba'
    Start-WebAppPool -Name $Nombre -ErrorAction SilentlyContinue
    Start-Website -Name $Nombre -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    # Sin sesion, cualquier pantalla lleva al ingreso: si se ve el formulario, PHP y la base andan.
    $url = "http://localhost:$Puerto/login.php"
    try {
        $r = Invoke-WebRequest $url -UseBasicParsing -TimeoutSec 60
        Write-Host "HTTP $($r.StatusCode) $url"
        if ($r.Content -match 'name="clave"') {
            Write-Host 'La pantalla de ingreso responde.' -ForegroundColor Green
            if ($r.Content -match 'Todav') { Write-Host 'Todavia no hay usuarios: crea el primero con  php D:\financiera\app\crear_usuario.php tu@correo "Tu nombre"' -ForegroundColor Yellow }
        } elseif ($r.Content -match 'alerta-error[^>]*>([^<]+)') {
            Write-Warning "La pagina responde pero muestra un error: $($Matches[1])"
        } else {
            Write-Warning 'La pagina responde pero no se ve el resumen. Primeros 500 caracteres:'
            Write-Host ($r.Content.Substring(0, [Math]::Min(500, $r.Content.Length)))
        }
    } catch {
        Write-Warning "La prueba fallo: $($_.Exception.Message)"
        if ($_.Exception.Response) {
            $sr = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
            $cuerpo = $sr.ReadToEnd()
            Write-Host ($cuerpo.Substring(0, [Math]::Min(1500, $cuerpo.Length)))
        }
    }

    Write-Host ''
    Write-Host "Listo: http://localhost:$Puerto/" -ForegroundColor Green
    Start-Process "http://localhost:$Puerto/"
}
catch {
    Write-Host ''
    Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
}
finally {
    Stop-Transcript | Out-Null
}
