# =====================================================================
#  Installe "MadMen Serveur" pour qu'il demarre AUTOMATIQUEMENT a chaque
#  ouverture de session Windows, en arriere-plan (zero terminal a gerer).
#  A lancer UNE FOIS sur le PC du bureau :
#     clic droit > "Executer avec PowerShell"
#     ou :  powershell -ExecutionPolicy Bypass -File installer-serveur.ps1
# =====================================================================
$ErrorActionPreference = "Stop"
$bat = Join-Path $PSScriptRoot "start-serveur.bat"
if (-not (Test-Path $bat)) { throw "start-serveur.bat introuvable a cote de ce script." }

$action  = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$bat`""
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
            -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) `
            -ExecutionTimeLimit ([TimeSpan]::Zero)

Register-ScheduledTask -TaskName "MadMen Serveur" -Action $action -Trigger $trigger `
    -Settings $settings -RunLevel Highest -Force | Out-Null

Write-Host ">> OK : 'MadMen Serveur' demarrera tout seul a chaque ouverture de session." -ForegroundColor Green
Write-Host ">> Lancement immediat du pont K40 + Live20 -> cloud..." -ForegroundColor Cyan
Start-ScheduledTask -TaskName "MadMen Serveur"
Write-Host ">> Fait. Le serveur tourne en fond. Tu peux fermer cette fenetre." -ForegroundColor Green
