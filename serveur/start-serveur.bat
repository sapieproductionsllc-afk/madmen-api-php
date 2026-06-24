@echo off
REM =====================================================================
REM  MadMen SERVEUR — pont K40 + Live20 vers le cloud (demarrage auto)
REM  A installer 1x sur le PC du bureau (celui qui voit le K40 + Live20).
REM  Lance en arriere-plan : MySQL, agent Live20, API locale, synchro K40.
REM  La synchro lit le K40 et POUSSE tout au cloud (relais).
REM  Idempotent : ne relance pas ce qui tourne deja.
REM =====================================================================
setlocal
cd /d "%~dp0"

REM --- Chemins (auto-detectes, ajustables si besoin) -------------------
REM PROJET = dossier API (parent de ce script \serveur).
for %%I in ("%~dp0..") do set "PROJET=%%~fI"
set "AGENT_DIR=%PROJET%\..\madmen-agent"
REM PHP du PATH, sinon ajuste la ligne suivante :
set "PHP=php"
where php >nul 2>&1 || set "PHP=C:\php\php.exe"
set "MYSQLD=C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe"
set "MYSQL_BASEDIR=C:\Program Files\MySQL\MySQL Server 8.4"
set "MYSQL_DATADIR=%USERPROFILE%\mysql-data"
REM --------------------------------------------------------------------

echo [1/4] MySQL...
tasklist /FI "IMAGENAME eq mysqld.exe" | find /I "mysqld.exe" >nul
if errorlevel 1 (
  start "MySQL MadMen" /MIN "%MYSQLD%" --basedir="%MYSQL_BASEDIR%" --datadir="%MYSQL_DATADIR%"
  timeout /t 6 /nobreak >nul
) else ( echo     deja lance )

echo [2/4] Agent Live20 (8080)...
tasklist /FI "IMAGENAME eq zkagent.exe" | find /I "zkagent.exe" >nul
if errorlevel 1 (
  start "Agent Live20" /D "%AGENT_DIR%" /MIN "%AGENT_DIR%\zkagent.exe"
) else ( echo     deja lance )

echo [3/4] API locale (8000)...
tasklist /FI "IMAGENAME eq php.exe" | find /I "php.exe" >nul
if errorlevel 1 (
  start "API MadMen" /D "%PROJET%" /MIN "%PHP%" -S 0.0.0.0:8000 -t public public\index.php
  timeout /t 3 /nobreak >nul
) else ( echo     deja lance )

echo [4/4] Synchro K40 -> cloud (boucle continue)...
tasklist /FI "WINDOWTITLE eq MadMen - Synchro K40*" | find /I "cmd.exe" >nul
if errorlevel 1 (
  start "MadMen - Synchro K40" /MIN cmd /c ""%~dp0sync-loop.bat""
) else ( echo     deja lance )

echo.
echo  MadMen Serveur demarre : K40 + Live20 -^> cloud (fenetres minimisees).
endlocal
