# RAG for Al-Ahli Hospital Chatbot

This folder adds a LangChain + Chroma RAG sidecar for the PHP chatbot.

## What It Does

- Searches a local Chroma index when one exists under `storage/rag/chroma`.
- Stores vectors in `storage/rag/chroma`.
- Exposes a local search API at `http://127.0.0.1:8011/search`.
- PHP calls the API through `RagRetrievalService` and adds retrieved context to the OpenAI prompt.

If the RAG server is not running, the chatbot continues normally.

## Setup

Run once:

```powershell
C:\xampp\htdocs\Hospital\scripts\setup-rag.bat
```

This creates `.venv-rag` and installs LangChain, Chroma, FastAPI, and OpenAI embedding support.

## Index Data

Generated indexes are local runtime data and are ignored by Git. The old Rasa
training corpus was removed from this project because it was not part of the
live chatbot runtime and occupied more than 100GB.

If you later receive a new approved knowledge corpus, ingest it with
`rag/ingest.py` and keep the generated Chroma files outside Git.

## Start The RAG Server

```powershell
C:\xampp\htdocs\Hospital\scripts\start-rag.bat
```

Keep the terminal open.

## Test Search

```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:8011/search" -Method POST -ContentType "application/json; charset=utf-8" -Body '{"query":"بدي احجز عند الدكتور امجد","top_k":5}'
```

## Notes

- Embeddings use local Arabic-friendly character hashing by default, so indexing does not need OpenAI quota.
- To use OpenAI embeddings instead, set `RAG_EMBEDDING_PROVIDER=openai`. The API key is read from `OPENAI_API_KEY` first, then from `app/config/env.php`.
- Production-scale indexing should use a dedicated vector database such as Qdrant or a managed vector store. Chroma is good for local development.
