@echo off
setlocal

set "TOOLS_DIR=%~dp0"
set "PROJECT_DIR=%TOOLS_DIR%.."
set "APP=%TOOLS_DIR%fixerupper_inventory_desktop.py"

if not exist "%APP%" (
    echo FixerUpper inventory desktop app was not found:
    echo   %APP%
    pause
    exit /b 1
)

cd /d "%PROJECT_DIR%" || (
    echo Could not open project directory:
    echo   %PROJECT_DIR%
    pause
    exit /b 1
)

set "PYTHON_EXE="
set "PYTHON_ARGS="

if "%~1"=="" (
    pyw.exe -3 -c "import sys" >nul 2>nul
    if not errorlevel 1 (
        set "PYTHON_EXE=pyw.exe"
        set "PYTHON_ARGS=-3"
    )

    if not defined PYTHON_EXE (
        pythonw.exe -c "import sys" >nul 2>nul
        if not errorlevel 1 set "PYTHON_EXE=pythonw.exe"
    )
)

if not defined PYTHON_EXE (
    py.exe -3 -c "import sys" >nul 2>nul
    if not errorlevel 1 (
        set "PYTHON_EXE=py.exe"
        set "PYTHON_ARGS=-3"
    )
)

if not defined PYTHON_EXE (
    python.exe -c "import sys" >nul 2>nul
    if not errorlevel 1 set "PYTHON_EXE=python.exe"
)

if not defined PYTHON_EXE (
    echo Python 3 was not found.
    echo Install Python 3 or add it to PATH, then run this launcher again.
    pause
    exit /b 1
)

if "%~1"=="" (
    start "" /D "%PROJECT_DIR%" "%PYTHON_EXE%" %PYTHON_ARGS% "%APP%"
    exit /b 0
)

"%PYTHON_EXE%" %PYTHON_ARGS% "%APP%" %*
set "EXIT_CODE=%ERRORLEVEL%"
if not "%EXIT_CODE%"=="0" pause
exit /b %EXIT_CODE%
