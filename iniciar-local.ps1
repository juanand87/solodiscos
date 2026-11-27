$ErrorActionPreference = 'Stop'
$projectPath = Join-Path $PSScriptRoot 'app'
$runtimePath = Join-Path $PSScriptRoot '.local'
$phpPath = 'C:\xampp\php\php.exe'
New-Item -ItemType Directory -Force -Path $runtimePath | Out-Null
if (!(Test-Path -LiteralPath $phpPath)) { throw 'No se encontró PHP de XAMPP.' }
if (!(Test-Path -LiteralPath (Join-Path $projectPath 'vendor\autoload.php'))) { throw 'Primero instala las dependencias de Composer.' }
$services = @{
    web = @('artisan','serve','--host=127.0.0.1','--port=8000')
    worker = @('artisan','queue:work','--sleep=3','--tries=1','--timeout=1800','--memory=512')
    scheduler = @('artisan','schedule:work')
}
foreach ($service in $services.Keys) {
    $pidPath = Join-Path $runtimePath ($service + '.pid')
    if ($service -eq 'web') {
        $listener = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($listener) {
            $listenerProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$($listener.OwningProcess)" -ErrorAction SilentlyContinue
            if ($listenerProcess.CommandLine -like "*$projectPath*") { Write-Host 'La web de SoloDiscos ya está disponible.'; continue }
            throw 'El puerto 8000 está ocupado por otra aplicación.'
        }
    }
    if (Test-Path -LiteralPath $pidPath) {
        $savedProcessId = [int](Get-Content -LiteralPath $pidPath)
        $existing = Get-CimInstance Win32_Process -Filter "ProcessId=$savedProcessId" -ErrorAction SilentlyContinue
        if ($existing -and $existing.CommandLine -like '*artisan*' -and $existing.ExecutablePath -eq $phpPath) {
            Write-Host "$service ya está ejecutándose."
            continue
        }
    }
    $process = Start-Process -FilePath $phpPath -ArgumentList $services[$service] -WorkingDirectory $projectPath -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimePath ($service + '.log')) -RedirectStandardError (Join-Path $runtimePath ($service + '.error.log'))
    Set-Content -LiteralPath $pidPath -Value $process.Id
    Write-Host "$service iniciado (PID $($process.Id))."
}
Write-Host 'SoloDiscos: http://127.0.0.1:8000'
Write-Host 'Administración: http://127.0.0.1:8000/admin'
Write-Host 'MySQL debe estar iniciado desde XAMPP. Los servicios funcionan mientras el equipo siga encendido.'
