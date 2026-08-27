#!/usr/bin/env python3
"""Read-only redacted scanner for private forensic source exports.

The scanner never writes source fragments or matched values. It emits only path,
line, classification, match byte length and SHA-256 of the matched text.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter
from pathlib import Path

POSSIBLE_SECRET = re.compile(
    r"(?i)(?:password|secret|token|access_token|refresh_token|client_secret|api_key|authorization|bearer|private_key)"
)
SECRET_LITERAL = re.compile(
    r"(?i)(?:password|secret|token|access_token|refresh_token|client_secret|api_key|private_key)"
    r"\s*(?:=>|:|=|,)\s*['\"][^'\"]{8,}['\"]"
)
AUTH_LITERAL = re.compile(
    r"(?i)(?:authorization\s*[:=]\s*['\"]?bearer\s+|bearer\s+)[A-Z0-9._~+/=-]{12,}"
)
ENVIRONMENT = re.compile(r"(?:staging2\.nuvanx\.com|nuvanx\.com|/home/customer/|/home/ubuntu/)", re.I)
STABLE_PATTERNS = (
    re.compile(r"\bGTM-[A-Z0-9-]+\b", re.I),
    re.compile(r"\bG-[A-Z0-9]{6,}\b", re.I),
    re.compile(r"\bAW-\d+(?:/[A-Z0-9_-]+)?\b", re.I),
    re.compile(r"\bact_\d+\b", re.I),
    re.compile(r"\bportal(?:[_-]?id)?\s*[:=>]+\s*['\"]?\d+", re.I),
)
CONTENT_PATTERNS = (
    re.compile(r"(?i)\b(?:page|post)[_-]?id\s*(?:=>|:|=)\s*\d+"),
    re.compile(r"(?i)\bis_page\s*\(\s*\d+"),
    re.compile(r"(?i)\bget_post\s*\(\s*\d+"),
    re.compile(r"(?i)\bID\s*(?:=>|:|=)\s*\d+"),
)
BUSINESS_PATTERNS = (
    re.compile(r"(?i)hubspot"),
    re.compile(r"(?i)klaviyo"),
    re.compile(r"(?i)complianz"),
    re.compile(r"(?i)joinchat"),
    re.compile(r"(?i)google\s*(?:ads|analytics|tag|site kit)"),
    re.compile(r"(?i)meta\s*(?:pixel|event|ads)?"),
    re.compile(r"(?i)consent"),
)
ACCIDENTAL = re.compile(r"(?i)(?:\bTODO\b|\bFIXME\b|\bHACK\b|\bTEMP(?:ORARY)?\b|\bREMOVE\s+ME\b)")


def add(rows: list[dict], path: str, line: int, category: str, value: str) -> None:
    rows.append({
        "file": path,
        "line": line,
        "category": category,
        "match_length": len(value.encode("utf-8")),
        "sha256_match": hashlib.sha256(value.encode("utf-8")).hexdigest(),
    })


def reject_traversal(path: Path) -> Path:
    if any(part == ".." for part in path.parts):
        raise SystemExit("PATH_TRAVERSAL_REJECTED")
    return path.expanduser()


def classify_line(rows: list[dict], rel: str, line_no: int, source_line: str) -> None:
    secret_matches = list(SECRET_LITERAL.finditer(source_line))
    auth_matches = list(AUTH_LITERAL.finditer(source_line))
    for match in secret_matches:
        add(rows, rel, line_no, "SECRET", match.group(0))
    for match in auth_matches:
        add(rows, rel, line_no, "SECRET", match.group(0))
    secret_spans = [(item.start(), item.end()) for item in (*secret_matches, *auth_matches)]
    for match in POSSIBLE_SECRET.finditer(source_line):
        if any(start <= match.start() and match.end() <= end for start, end in secret_spans):
            continue
        add(rows, rel, line_no, "BUSINESS_CONFIG", match.group(0))
    grouped = (
        ("ENVIRONMENT_SPECIFIC", (ENVIRONMENT,)),
        ("STABLE_PUBLIC_IDENTIFIER", STABLE_PATTERNS),
        ("CONTENT_IDENTIFIER", CONTENT_PATTERNS),
        ("BUSINESS_CONFIG", BUSINESS_PATTERNS),
        ("ACCIDENTAL_HARDCODE", (ACCIDENTAL,)),
    )
    for category, patterns in grouped:
        for pattern in patterns:
            for match in pattern.finditer(source_line):
                add(rows, rel, line_no, category, match.group(0))


def scan(root: Path) -> dict:
    rows: list[dict] = []
    files = []
    for p in sorted(root.rglob("*")):
        if not p.is_file():
            continue
        try:
            content = p.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        rel = p.relative_to(root).as_posix()
        files.append(rel)
        for line_no, source_line in enumerate(content.splitlines(), 1):
            classify_line(rows, rel, line_no, source_line)
    unique = {(r["file"], r["line"], r["category"], r["sha256_match"]): r for r in rows}
    ordered = sorted(unique.values(), key=lambda r: (r["file"], r["line"], r["category"], r["sha256_match"]))
    return {
        "schema": "nuvanx-forensic-redacted-source-scan/v1",
        "redaction": "Matched values and source fragments are never emitted.",
        "files_scanned": files,
        "category_counts": dict(sorted(Counter(r["category"] for r in ordered).items())),
        "findings": ordered,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("source_dir", type=Path)
    parser.add_argument("output_json", type=Path)
    args = parser.parse_args()
    source_dir = reject_traversal(args.source_dir).resolve(strict=True)
    if not source_dir.is_dir():
        raise SystemExit("SOURCE_DIR_NOT_DIRECTORY")
    output_json = reject_traversal(args.output_json).resolve(strict=False)
    report = scan(source_dir)
    output_json.parent.mkdir(parents=True, exist_ok=True)
    output_json.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps({"files": len(report["files_scanned"]), "category_counts": report["category_counts"]}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
