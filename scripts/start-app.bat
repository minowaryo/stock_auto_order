@echo off
setlocal enabledelayedexpansion
title stock_auto_order - START

rem === settings ===
set "PROJECT_DIR=c:\workspace\stock_auto_order"
set "DOCKER_EXE=C:\Program Files\Docker\Docker\Docker Desktop.exe"
set "APP_URL=http://localhost"

cd /d "%PROJECT_DIR%" || (echo [ERROR] Project folder not found: %PROJECT_DIR% & pause & exit /b 1)

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

echo [2/4] Starting containers (docker compose up -d)...
docker compose up -d || (echo   [ERROR] docker compose up failed. & pause & exit /b 1)

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
echo   START COMPLETE
echo     URL   : %APP_URL%
echo     Login : test@example.com  /  password
echo ============================================================
ping -n 6 127.0.0.1 >nul
endlocal
