from __future__ import annotations

import hashlib
import math
import re
from pathlib import Path

from langchain_core.embeddings import Embeddings

from rag.settings import (
    collection_name,
    embedding_model,
    embedding_provider,
    hash_embedding_dimensions,
    openai_api_key,
    persist_dir,
)


class HashingEmbeddings(Embeddings):
    """Local character n-gram embeddings for Arabic typos and short patient phrases."""

    def __init__(self, dimensions: int = 1536):
        self.dimensions = dimensions

    def embed_documents(self, texts: list[str]) -> list[list[float]]:
        return [self._embed(text) for text in texts]

    def embed_query(self, text: str) -> list[float]:
        return self._embed(text)

    def _embed(self, text: str) -> list[float]:
        normalized = self._normalize(text)
        vector = [0.0] * self.dimensions
        if not normalized:
            return vector

        grams = []
        compact = normalized.replace(" ", "_")
        for n in (2, 3, 4, 5):
            if len(compact) >= n:
                grams.extend(compact[i : i + n] for i in range(len(compact) - n + 1))

        grams.extend(token for token in normalized.split() if len(token) >= 2)
        if not grams:
            return vector

        for gram in grams:
            digest = hashlib.blake2b(gram.encode("utf-8"), digest_size=8).digest()
            value = int.from_bytes(digest, "big")
            index = value % self.dimensions
            sign = 1.0 if (value >> 8) & 1 else -1.0
            vector[index] += sign

        norm = math.sqrt(sum(item * item for item in vector))
        if norm > 0:
            vector = [item / norm for item in vector]
        return vector

    def _normalize(self, text: str) -> str:
        text = text.lower()
        text = re.sub(r"[\u064b-\u065f]", "", text)
        text = text.replace("أ", "ا").replace("إ", "ا").replace("آ", "ا")
        text = text.replace("ة", "ه").replace("ؤ", "و").replace("ئ", "ي")
        text = text.translate(str.maketrans("٠١٢٣٤٥٦٧٨٩", "0123456789"))
        text = re.sub(r"[^\w\u0600-\u06ff\s]+", " ", text)
        text = re.sub(r"\s+", " ", text).strip()
        return text


def build_embeddings():
    if embedding_provider() == "openai":
        openai_api_key()
        from langchain_openai import OpenAIEmbeddings

        return OpenAIEmbeddings(model=embedding_model())

    return HashingEmbeddings(dimensions=hash_embedding_dimensions())


def build_vector_store():
    Path(persist_dir()).mkdir(parents=True, exist_ok=True)

    from langchain_chroma import Chroma

    return Chroma(
        collection_name=collection_name(),
        embedding_function=build_embeddings(),
        persist_directory=persist_dir(),
    )
