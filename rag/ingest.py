from __future__ import annotations

import argparse
import glob
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Iterable

from rag.store import build_vector_store


ENTITY_PATTERN = re.compile(r"\[([^\]]+)\]\([^)]+\)")


def clean_training_line(line: str) -> str:
    text = line.strip()
    if not text or text in {"nlu:", "examples: |"} or text.startswith("version:"):
        return ""
    if text.startswith("#") or text.startswith("- intent:") or text.startswith("- lookup:"):
        return ""
    if text.startswith("- "):
        text = text[2:].strip()
    elif re.match(r"^[A-Za-z_]+:", text):
        return ""

    text = ENTITY_PATTERN.sub(r"\1", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text if len(text) >= 8 else ""


def iter_examples(path: Path, sample_every: int = 1, max_examples: int | None = None) -> Iterable[tuple[str, int]]:
    seen = 0
    emitted = 0
    with path.open("r", encoding="utf-8", errors="ignore") as handle:
        for line_no, line in enumerate(handle, start=1):
            text = clean_training_line(line)
            if not text:
                continue

            seen += 1
            if sample_every > 1 and seen % sample_every != 0:
                continue

            yield text, line_no
            emitted += 1
            if max_examples is not None and emitted >= max_examples:
                break


def combine_examples(examples: list[tuple[str, int]], max_chars: int) -> list[tuple[str, int]]:
    chunks: list[tuple[str, int]] = []
    current: list[str] = []
    first_line = 0
    current_len = 0

    for text, line_no in examples:
        extra_len = len(text) + 1
        if current and current_len + extra_len > max_chars:
            chunks.append(("\n".join(current), first_line))
            current = []
            current_len = 0

        if not current:
            first_line = line_no
        current.append(text)
        current_len += extra_len

    if current:
        chunks.append(("\n".join(current), first_line))

    return chunks


def resolve_files(patterns: list[str]) -> list[Path]:
    files: list[Path] = []
    for pattern in patterns:
        matches = glob.glob(pattern, recursive=True)
        files.extend(Path(match) for match in matches if Path(match).is_file())
    return sorted(set(files))


def chunk_id(id_prefix: str, path: Path, first_line: int, chunk: str) -> str:
    source = f"{id_prefix}:{path.as_posix()}:{first_line}:{hashlib.sha1(chunk.encode('utf-8')).hexdigest()}"
    return hashlib.sha1(source.encode("utf-8")).hexdigest()


def load_state(state_file: str | None) -> dict:
    if not state_file:
        return {"done_files": []}

    path = Path(state_file)
    if not path.exists():
        return {"done_files": []}

    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {"done_files": []}
    except json.JSONDecodeError:
        return {"done_files": []}


def save_state(state_file: str | None, state: dict) -> None:
    if not state_file:
        return

    path = Path(state_file)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")


def docs_from_raw_batch(raw_batch: list[tuple[str, int]], path: Path, max_chars: int):
    from langchain_core.documents import Document

    chunks = combine_examples(raw_batch, max_chars=max_chars)
    docs = [
        Document(page_content=chunk, metadata={"source": str(path), "line": first_line})
        for chunk, first_line in chunks
    ]
    return docs, chunks


def ingest_files(
    patterns: list[str],
    batch_size: int,
    max_examples_per_file: int | None,
    sample_every: int,
    max_chars: int,
    state_file: str | None,
    resume: bool,
    id_prefix: str,
) -> None:
    try:
        import langchain_core.documents  # noqa: F401
    except ImportError as exc:
        raise SystemExit("Missing LangChain dependencies. Run: .\\.venv-rag\\Scripts\\python.exe -m pip install -r rag\\requirements.txt") from exc

    store = build_vector_store()
    files = resolve_files(patterns)
    if not files:
        raise SystemExit("No files matched the provided patterns.")

    state = load_state(state_file) if resume else {"done_files": []}
    done_files = set(str(item) for item in state.get("done_files", []))
    total_docs = 0
    for path in files:
        path_key = str(path)
        if resume and path_key in done_files:
            print(f"[RAG] skipping completed {path}", flush=True)
            continue

        print(f"[RAG] ingesting {path}", flush=True)
        raw_batch: list[tuple[str, int]] = []
        file_docs = 0

        for text, line_no in iter_examples(path, sample_every=sample_every, max_examples=max_examples_per_file):
            raw_batch.append((text, line_no))
            if len(raw_batch) >= batch_size:
                docs, chunks = docs_from_raw_batch(raw_batch, path, max_chars)
                ids = [chunk_id(id_prefix, path, first_line, chunk) for chunk, first_line in chunks]
                store.add_documents(docs, ids=ids)
                file_docs += len(docs)
                total_docs += len(docs)
                raw_batch = []
                print(f"[RAG] {path.name}: {file_docs} chunks indexed", flush=True)

        if raw_batch:
            docs, chunks = docs_from_raw_batch(raw_batch, path, max_chars)
            ids = [chunk_id(id_prefix, path, first_line, chunk) for chunk, first_line in chunks]
            store.add_documents(docs, ids=ids)
            file_docs += len(docs)
            total_docs += len(docs)

        if resume:
            done_files.add(path_key)
            state["done_files"] = sorted(done_files)
            state["updated_at"] = __import__("datetime").datetime.now().isoformat()
            save_state(state_file, state)

        print(f"[RAG] finished {path.name}: {file_docs} chunks", flush=True)

    print(f"[RAG] done. Indexed {total_docs} chunks.", flush=True)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Ingest hospital chatbot corpora into the RAG vector database.")
    parser.add_argument("--glob", action="append", required=True, help="File glob to ingest. Can be repeated.")
    parser.add_argument("--batch-size", type=int, default=256)
    parser.add_argument("--max-examples-per-file", type=int, default=None)
    parser.add_argument("--sample-every", type=int, default=1, help="Use 10 to index every 10th extracted example.")
    parser.add_argument("--max-chars", type=int, default=1800, help="Maximum characters per vectorized chunk.")
    parser.add_argument("--state-file", default=None, help="JSON state file for completed-file resume.")
    parser.add_argument("--resume", action="store_true", help="Skip files already marked done in the state file.")
    parser.add_argument("--id-prefix", default="ahli-rag", help="Stable prefix used to create deterministic vector ids.")
    args = parser.parse_args(argv)

    ingest_files(
        patterns=args.glob,
        batch_size=max(1, args.batch_size),
        max_examples_per_file=args.max_examples_per_file,
        sample_every=max(1, args.sample_every),
        max_chars=max(200, args.max_chars),
        state_file=args.state_file,
        resume=args.resume,
        id_prefix=args.id_prefix,
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
