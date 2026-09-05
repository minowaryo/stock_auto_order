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
echo   Docker OK (Windows side)

rem "docker info" on the Windows side can succeed before Docker Desktop's
rem WSL integration (the internal docker-desktop VM bridging the daemon
rem socket into %WSL_DISTRO%) has actually finished starting. Confirm the
rem daemon is reachable from inside %WSL_DISTRO% too, so we don't rush into
rem "docker compose up" against a socket that isn't there yet.
set /a n=0
:wait_wsl_docker
wsl -d %WSL_DISTRO% -- docker info >nul 2>&1
if not errorlevel 1 goto wsl_docker_ok
set /a n+=1
if !n! geq 20 goto compose_start
echo   waiting for Docker WSL integration (%WSL_DISTRO%)... !n!/20
ping -n 4 127.0.0.1 >nul
goto wait_wsl_docker

:wsl_docker_ok
echo   Docker OK (%WSL_DISTRO%)

:compose_start
echo [2/4] Starting containers in WSL (%WSL_DISTRO%:%WSL_PROJECT_DIR%)...
set /a n=0
:try_compose
wsl -d %WSL_DISTRO% -- bash -lc "cd %WSL_PROJECT_DIR% && docker compose up -d"
if not errorlevel 1 goto compose_ok
set /a n+=1
if !n! geq 5 goto compose_failed
echo   [WARN] docker compose up failed, retrying (!n!/5)...
ping -n 6 127.0.0.1 >nul
goto try_compose

:compose_failed
echo.
echo ============================================================
echo   [ERROR] docker compose up failed in WSL after 5 attempts.
echo.
echo   This usually means Docker Desktop's internal WSL VM
echo   (docker-desktop distro) failed to boot correctly, even
echo   though Docker Desktop itself looked ready above.
echo.
echo   Manual recovery:
echo     1. Close Docker Desktop
echo     2. Run in PowerShell/CMD: wsl --shutdown
echo        NOTE: this restarts ALL WSL distros. Make sure no other
echo        WSL terminals/sessions/work are running first.
echo     3. Start Docker Desktop again and wait until it is ready
echo     4. Re-run this script
echo ============================================================
pause
exit /b 1

:compose_ok

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
