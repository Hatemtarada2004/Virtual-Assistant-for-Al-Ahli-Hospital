@echo off
chcp 65001 > nul
echo.
echo ========================================
echo   تدريب نموذج تصنيف الاعراض الطبية
echo ========================================
echo.

cd /d "%~dp0.."

if not exist ".venv-rag\Scripts\python.exe" (
    echo [خطأ] البيئة الافتراضية غير موجودة
    pause
    exit /b 1
)

echo [1] تثبيت المتطلبات...
.venv-rag\Scripts\python.exe -m pip install scikit-learn flask numpy -q

echo [2] بدء التدريب على 10,000 سيناريو...
set PYTHONIOENCODING=utf-8
set PYTHONUTF8=1
.venv-rag\Scripts\python.exe app/ml/generate_and_train.py

echo.
echo [3] نسخ الملفات للخادم...
if exist "C:\xampp\htdocs\hospital-chatbot\app\ml" (
    xcopy /E /I /Y "app\ml\models" "C:\xampp\htdocs\hospital-chatbot\app\ml\models" > nul
    echo     تم نسخ النموذج للخادم
)

echo.
echo ========================================
echo   اكتمل التدريب! شغّل start-symptom-api.bat
echo ========================================
pause
