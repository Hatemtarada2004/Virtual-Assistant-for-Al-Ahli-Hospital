# Ahli Hospital Chatbot

Local PHP/XAMPP hospital site with an LLM-orchestrated receptionist chatbot.

## Runtime

- Main site: `http://localhost/Hospital/frontend/index.html`
- Chat API: `http://localhost/Hospital/public/api/chat`
- Chat runtime: `LlmReceptionistOrchestratorService`
- Rasa runtime: removed from live chat
- Optional RAG sidecar: `http://127.0.0.1:8011`

## Local Setup

1. Import the database schema and seed data from `database/`.
2. Copy `app/config/env.example.php` to `app/config/env.php`.
3. Add your local database settings and OpenRouter API key in `app/config/env.php`.
4. Start Apache and MySQL from XAMPP.
5. Open `http://localhost/Hospital/frontend/index.html`.

## Optional RAG

The repository keeps the RAG code, but generated Chroma data and the old 119GB
Rasa corpus are not committed.

To prepare the RAG environment:

```powershell
C:\xampp\htdocs\Hospital\scripts\setup-rag.bat
```

To start RAG:

```powershell
C:\xampp\htdocs\Hospital\scripts\start-rag.bat
```

If the RAG sidecar is down, the chatbot still works through the deterministic
tools and OpenRouter response builder.

## Security

Do not commit `app/config/env.php`. It contains local secrets and is ignored by Git.
