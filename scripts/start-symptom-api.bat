@echo off
chcp 65001 > nul
echo.
echo ========================================
echo   Symptom API - المستشفى الاهلي
echo   http://localhost:5001
echo ========================================
echo.

cd /d "%~dp0.."

if not exist ".venv-rag\Scripts\python.exe" (
    echo [خطأ] البيئة الافتراضية غير موجودة
    pause
    exit /b 1
)

if not exist "app\ml\models\symptom_pipeline.pkl" (
    echo [خطأ] ملف النموذج غير موجود - شغّل train-symptom-model.bat اولاً
    pause
    exit /b 1
)

echo [+] تشغيل Symptom API على المنفذ 5001...
set PYTHONIOENCODING=utf-8
set PYTHONUTF8=1
.venv-rag\Scripts\python.exe app/ml/symptom_api.py
