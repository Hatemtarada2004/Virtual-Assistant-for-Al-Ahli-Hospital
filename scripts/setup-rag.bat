@echo off
set "PROJECT_ROOT=%~dp0.."
cd /d "%PROJECT_ROOT%"

where python >nul 2>nul
if errorlevel 1 (
  echo Python is not installed or not available on PATH.
  exit /b 1
)

if not exist ".venv-rag\Scripts\python.exe" (
  python -m venv ".venv-rag"
)

".venv-rag\Scripts\python.exe" -m pip install --upgrade pip
".venv-rag\Scripts\python.exe" -m pip install -r "rag\requirements.txt"
