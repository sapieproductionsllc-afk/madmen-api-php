@echo off
REM Boucle de synchro K40 : appelle l'endpoint local /api/k40/sync qui LIT le K40
REM ET pousse au cloud (relais) — exactement le chemin utilise par le dashboard.
REM Toutes les 30s. Resilient : si le K40 ou le cloud bug, on retente au tour suivant.
title MadMen - Synchro K40
set "INTERVAL=30"
:loop
curl -s -m 60 -X POST http://127.0.0.1:8000/api/k40/sync >nul 2>&1
timeout /t %INTERVAL% /nobreak >nul
goto loop
