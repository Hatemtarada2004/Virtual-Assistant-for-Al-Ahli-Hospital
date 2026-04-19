@echo off
cd /d C:\xampp\htdocs\Hospital
"C:\Users\abed-\AppData\Local\Programs\Python\Python310\python.exe" -m venv ".venv-rag"
".venv-rag\Scripts\python.exe" -m pip install --upgrade pip
".venv-rag\Scripts\python.exe" -m pip install -r "rag\requirements.txt"
