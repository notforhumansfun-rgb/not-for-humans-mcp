#!/usr/bin/env python3
"""Regression tests for fail-closed public upload credential scanning."""

from __future__ import annotations

import subprocess
import tempfile
import unittest
from pathlib import Path

SCANNER = Path(__file__).with_name("scan-server-secrets.py")


class CredentialScannerTests(unittest.TestCase):
    def run_scan(self, content: bytes) -> subprocess.CompletedProcess[str]:
        with tempfile.TemporaryDirectory(prefix="nfh-secret-scan-") as directory:
            candidate = Path(directory) / "public.bin"
            candidate.write_bytes(content)
            return subprocess.run(
                ["python3", str(SCANNER), str(candidate)],
                capture_output=True,
                text=True,
                check=False,
            )

    def test_rejects_unquoted_assigned_credential(self) -> None:
        result = self.run_scan(b"api_key=abcdefghijklmnop1234567890\n")
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("assigned credential", result.stderr)

    def test_scans_credential_text_inside_non_utf8_file(self) -> None:
        result = self.run_scan(b"\xff\x00api_key=abcdefghijklmnop1234567890\n")
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("assigned credential", result.stderr)

    def test_allows_public_address_and_explicit_placeholders(self) -> None:
        result = self.run_scan(
            b"recipient=0x1111111111111111111111111111111111111111\n"
            b"api_key=placeholder\n"
            b"token=[REDACTED]\n"
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_does_not_relabel_source_code_expressions_as_credentials(self) -> None:
        result = self.run_scan(
            b'const token = root.querySelector("#journey-token");\n'
            b'$apiKey = nfh_opensea_api_key();\n'
            b'owned.forEach(token=>grid.append(token));\n'
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_skips_third_party_vendored_dependencies_by_directory(self) -> None:
        with tempfile.TemporaryDirectory(prefix="nfh-secret-scan-") as directory:
            vendor_dir = Path(directory) / "assets" / "vendor"
            vendor_dir.mkdir(parents=True)
            (vendor_dir / "some-library.min.js").write_bytes(
                b'let token=options.quicknode;providers.push(new QuickNodeProvider(token));\n'
            )
            result = subprocess.run(
                ["python3", str(SCANNER), directory],
                capture_output=True,
                text=True,
                check=False,
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_still_scans_non_vendored_files_in_the_same_tree(self) -> None:
        with tempfile.TemporaryDirectory(prefix="nfh-secret-scan-") as directory:
            vendor_dir = Path(directory) / "assets" / "vendor"
            vendor_dir.mkdir(parents=True)
            (vendor_dir / "some-library.min.js").write_bytes(b"harmless\n")
            (Path(directory) / "leaked.js").write_bytes(b"api_key=abcdefghijklmnop1234567890\n")
            result = subprocess.run(
                ["python3", str(SCANNER), directory],
                capture_output=True,
                text=True,
                check=False,
            )
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("assigned credential", result.stderr)


if __name__ == "__main__":
    unittest.main()
