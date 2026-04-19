# Rasa Runtime Status

Rasa is no longer part of the live `/api/chat` request path.

Current live chat runtime:

1. `ChatController`
2. `LlmReceptionistOrchestratorService`
3. deterministic tool layer backed by repositories/services
4. optional RAG retrieval
5. OpenRouter response polishing through `OpenAIService`

The old Rasa project, generated corpora, model files, and Rasa virtual
environment were removed from the working project to save disk space. They are
not required for normal chatbot testing.

Do not start Rasa for normal chatbot testing. `scripts/start-ai-services.bat`
starts only the optional RAG sidecar now.

The live chatbot keeps booking deterministic through PHP services and database
repositories. RAG is a retrieval helper only; it is not a replacement for the
booking tools.
