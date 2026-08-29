@echo off
setlocal enabledelayedexpansion
title stock_auto_order - START

rem =====================================================================
rem  Starts the WSL2-native stack (fast). The project lives inside WSL at
rem  %WSL_PROJECT_DIR%; running "docker compose" from the Windows path
rem  instead would recreate the container on the slow Windows bind mount
rem  (see docs/ai-context/known-pitfalls.md). Docker Desktop is shared
rem  between Windows and WSL, so the "docker info" check below still works.
rem =====================================================================

rem === settings ===
set "WSL_DISTRO=Ubuntu"
set "WSL_PROJECT_DIR=/root/workspace/stock_auto_order"
set "DOCKER_EXE=C:\Program Files\Docker\Docker\Docker Desktop.exe"
set "APP_URL=http://localhost"

echo [1/4] Checking Docker...
docker info >nul 2>&1
if not errorlevel 1 goto docker_ok

echo   Docker is not running. Starting Docker Desktop...
if exist "%DOCKER_EXE%" (
  start "" "%DOCKER_EXE%"
) else (
  echo   [WARN] Docker Desktop not found. Please start it manually.
)

set /a n=0
:wait_docker
ping -n 4 127.0.0.1 >nul
docker info >nul 2>&1
if not errorlevel 1 goto docker_ok
set /a n+=1
if !n! geq 40 (echo   [ERROR] Timed out waiting for Docker. & pause & exit /b 1)
echo   waiting for Docker... !n!/40
goto wait_docker

:docker_ok
echo   Docker OK

echo [2/4] Starting containers in WSL (%WSL_DISTRO%:%WSL_PROJECT_DIR%)...
wsl -d %WSL_DISTRO% -- bash -lc "cd %WSL_PROJECT_DIR% && docker compose up -d"
if errorlevel 1 (echo   [ERROR] docker compose up failed in WSL. & pause & exit /b 1)

echo [3/4] Waiting for app to respond...
set /a n=0
:wait_app
powershell -NoProfile -Command "try{Invoke-WebRequest -UseBasicParsing '%APP_URL%' -TimeoutSec 3 | Out-Null;exit 0}catch{exit 1}" >nul 2>&1
if not errorlevel 1 goto app_ok
set /a n+=1
if !n! geq 30 (echo   [WARN] Timed out. Open the browser and check manually. & goto open)
ping -n 3 127.0.0.1 >nul
goto wait_app

:app_ok
echo   App responded OK

:open
start "" "%APP_URL%"
echo.
echo ============================================================
echo   START COMPLETE  (WSL-native / fast)
echo     URL   : %APP_URL%
echo     Login : test@example.com  /  password
echo ============================================================
ping -n 6 127.0.0.1 >nul
endlocal
