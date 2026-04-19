from __future__ import annotations

from typing import Any

from fastapi import FastAPI
from pydantic import BaseModel, Field

from rag.settings import collection_name, persist_dir
from rag.store import build_vector_store


class SearchRequest(BaseModel):
    query: str = Field(min_length=1)
    top_k: int = Field(default=5, ge=1, le=20)


app = FastAPI(title="Al-Ahli Hospital RAG")
_store = None


def vector_store():
    global _store
    if _store is None:
        _store = build_vector_store()
    return _store


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "ok": True,
        "collection": collection_name(),
        "persist_dir": persist_dir(),
    }


@app.post("/search")
def search(request: SearchRequest) -> dict[str, Any]:
    results = vector_store().similarity_search_with_score(request.query, k=request.top_k)
    matches = []
    for document, score in results:
        metadata = document.metadata or {}
        matches.append(
            {
                "text": document.page_content,
                "score": float(score),
                "source": metadata.get("source", ""),
                "line": metadata.get("line"),
            }
        )

    return {"matches": matches}
