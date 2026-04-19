@echo off
cd /d C:\xampp\htdocs\Hospital
if not exist ".venv-rag\Scripts\uvicorn.exe" (
  echo RAG environment is missing.
  echo Run scripts\setup-rag.bat first, then start RAG again.
  exit /b 1
)
set RAG_EMBEDDING_PROVIDER=hash
set RAG_COLLECTION=ahli_patient_corpus_hash_v2
".venv-rag\Scripts\uvicorn.exe" rag.server:app --host 127.0.0.1 --port 8011
