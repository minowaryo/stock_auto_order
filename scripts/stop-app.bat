@echo off
setlocal
title stock_auto_order - STOP

rem  Stops the WSL2-native stack. Containers stop but the MySQL named
rem  volume is preserved. Restart with the "stock_auto_order start" shortcut.

rem === settings ===
set "WSL_DISTRO=Ubuntu"
set "WSL_PROJECT_DIR=/root/workspace/stock_auto_order"

echo Checking Docker...
docker info >nul 2>&1
if errorlevel 1 (echo   Docker is not running. Nothing to stop. & ping -n 3 127.0.0.1 >nul & endlocal & exit /b 0)

echo Stopping containers in WSL (%WSL_DISTRO%:%WSL_PROJECT_DIR%)...
wsl -d %WSL_DISTRO% -- bash -lc "cd %WSL_PROJECT_DIR% && docker compose stop"
if errorlevel 1 (echo   [ERROR] docker compose stop failed in WSL. & pause & exit /b 1)

echo.
echo ============================================================
echo   STOP COMPLETE  (data / DB are preserved)
echo     Restart with the "stock_auto_order start" shortcut
echo ============================================================
ping -n 4 127.0.0.1 >nul
endlocal
