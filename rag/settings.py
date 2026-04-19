from __future__ import annotations

import os
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def _php_config_value(key: str) -> str | None:
    config_path = ROOT / "app" / "config" / "env.php"
    if not config_path.exists():
        return None

    text = config_path.read_text(encoding="utf-8", errors="ignore")
    match = re.search(rf"['\"]{re.escape(key)}['\"]\s*=>\s*['\"]([^'\"]+)['\"]", text)
    return match.group(1) if match else None


def openai_api_key() -> str:
    key = os.getenv("OPENAI_API_KEY") or _php_config_value("openai_api_key") or ""
    if not key or key == "PUT_YOUR_OPENAI_KEY_HERE":
        raise RuntimeError("OPENAI_API_KEY is missing. Set it in the environment or app/config/env.php.")
    os.environ["OPENAI_API_KEY"] = key
    return key


def embedding_model() -> str:
    return os.getenv("RAG_EMBEDDING_MODEL", "text-embedding-3-small")


def embedding_provider() -> str:
    return os.getenv("RAG_EMBEDDING_PROVIDER", "hash").strip().lower()


def hash_embedding_dimensions() -> int:
    return int(os.getenv("RAG_HASH_DIMENSIONS", "1536"))


def persist_dir() -> str:
    return os.getenv("RAG_PERSIST_DIR", str(ROOT / "storage" / "rag" / "chroma"))


def collection_name() -> str:
    default = "ahli_patient_corpus_hash_v2" if embedding_provider() == "hash" else "ahli_patient_corpus"
    return os.getenv("RAG_COLLECTION", default)
