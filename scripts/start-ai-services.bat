@echo off
set "PROJECT_ROOT=%~dp0.."
cd /d "%PROJECT_ROOT%"

echo Starting Ahli chatbot AI services...
echo.
echo Keep the opened windows running while testing the chatbot.
echo RAG API:  http://127.0.0.1:8011
echo Rasa is disabled for live chat. /api/chat now uses the LLM receptionist orchestrator.
echo.

start "Ahli RAG Retrieval" cmd.exe /k ""%~dp0start-rag.bat""

echo Done. RAG can take a few seconds to become ready.
