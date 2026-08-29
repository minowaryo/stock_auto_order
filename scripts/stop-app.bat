@echo off
setlocal
title stock_auto_order - STOP

rem === settings ===
set "PROJECT_DIR=c:\workspace\stock_auto_order"

cd /d "%PROJECT_DIR%" || (echo [ERROR] Project folder not found: %PROJECT_DIR% & pause & exit /b 1)

echo Checking Docker...
docker info >nul 2>&1
if errorlevel 1 (echo   Docker is not running. Nothing to stop. & ping -n 3 127.0.0.1 >nul & endlocal & exit /b 0)

echo Stopping containers (docker compose stop)...
docker compose stop || (echo   [ERROR] docker compose stop failed. & pause & exit /b 1)

echo.
echo ============================================================
echo   STOP COMPLETE  (data / DB are preserved)
echo     Restart with the "stock_auto_order start" shortcut
echo ============================================================
ping -n 4 127.0.0.1 >nul
endlocal
