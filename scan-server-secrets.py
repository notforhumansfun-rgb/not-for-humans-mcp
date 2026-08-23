#!/usr/bin/env python3
"""Fail closed if any public upload input contains credential-shaped material."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOTS = [Path(value).resolve() for value in sys.argv[1:]] or [Path(__file__).resolve().parent / "server"]
FORBIDDEN_SUFFIXES = {".env", ".pem", ".key", ".p12", ".pfx"}
GENERATED_RUNTIME_NAMES = {"founder-away-monitor-report.json", "network-pulse.json"}
PATTERNS = {
    "private-key block": re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    "AWS access key": re.compile(r"AKIA[0-9A-Z]{16}"),
    "GitHub token": re.compile(r"gh[pousr]_[A-Za-z0-9_]{30,}"),
    "assigned credential": re.compile(
        r"(?i)(?<![A-Za-z0-9_])(?P<key_quote>[\"']?)"
        r"(?:password|passwd|secret|api[_-]?key|private[_-]?key|"
        r"verifier(?:[_-]?shared)?[_-]?secret|(?:client|shared)[_-]?secret|"
        r"(?:access|refresh|bearer|bot|discord)[_-]?token|token)"
        r"(?P=key_quote)"
        r"\s*[:=]\s*"
        r"(?![\"']?(?:\[?(?:redacted|test-only|example|placeholder)\]?|0x[0-9a-f]{40})"
        r"[\"']?(?:\s|[,;#]|$))"
        r"(?:[\"'][^\"'\r\n]{16,}[\"']|"
        # This exact identifier is source code from Promise.allSettled handling,
        # not a credential value. Keep the exception narrow: all-letter values
        # such as password=abcdefghijklmnop must still fail closed.
        r"(?!(?:tokenResult\.status|config\.verifierSharedSecret)(?=\s|[,;#]|$))"
        r"[A-Za-z0-9_./+=:@-]{16,}(?=\s|[,;#]|$))"
    ),
}

failures: list[str] = []
for root in ROOTS:
    if not root.exists():
        print(f"Credential scan input does not exist: {root}", file=sys.stderr)
        raise SystemExit(2)
    paths = [root] if root.is_file() else sorted(root.rglob("*"))
    for path in paths:
        if not path.is_file():
            continue
        relative = path.name if root.is_file() else str(path.relative_to(root))
        if not root.is_file() and ({"node_modules", ".git"} & set(Path(relative).parts)):
            continue
        if not root.is_file() and path.name in GENERATED_RUNTIME_NAMES:
            continue
        label_path = f"{root.name}/{relative}"
        # Filename checks apply before content exclusions so a credential file
        # cannot hide under a third-party-looking directory.
        if path.suffix.lower() in FORBIDDEN_SUFFIXES:
            failures.append(f"{label_path}: credential-shaped filename")
            continue
        # Third-party vendored dependencies (e.g. the checked-in ethers.js UMD
        # build) are not project-authored; scanning their minified source for
        # "credential-shaped" identifiers only produces false positives (that
        # exact build's own QuickNode provider code assigns a local variable
        # literally named "token"). Forbidden filename suffixes above still
        # apply to vendored files.
        if not root.is_file() and "vendor" in Path(relative).parts:
            continue
        # surrogateescape preserves every non-UTF-8 byte while leaving embedded
        # ASCII credential assignments searchable instead of silently skipping
        # an otherwise uploadable binary file.
        text = path.read_bytes().decode("utf-8", errors="surrogateescape")
        for line_number, line in enumerate(text.splitlines(), 1):
            for label, pattern in PATTERNS.items():
                if pattern.search(line):
                    failures.append(f"{label_path}:{line_number}: {label}")

if failures:
    print("Public MCP deployment blocked by credential content scan:", file=sys.stderr)
    for failure in failures:
        print(f"- {failure}", file=sys.stderr)
    raise SystemExit(1)

print(f"Public credential content scan passed for {len(ROOTS)} upload input(s).")
