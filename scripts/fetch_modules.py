#!/usr/bin/env python3
"""Download the pinned Omeka S modules and themes listed in modules.json.

Usage:
    python scripts/fetch_modules.py <omeka_dir> [--with-core] [--only NAME ...] [--force]

Each release zip is extracted into <omeka_dir>/modules/<name> (or
<omeka_dir>/themes/<name>). Existing folders are skipped unless --force.
Only the Python standard library is used, so it runs the same on Windows,
macOS, Linux and inside the Docker build.
"""
import argparse
import io
import json
import shutil
import sys
import tempfile
import urllib.request
import zipfile
from pathlib import Path

MANIFEST = Path(__file__).resolve().parent.parent / "modules.json"


def download(url: str) -> bytes:
    req = urllib.request.Request(url, headers={"User-Agent": "omeka-s-finland-kit"})
    with urllib.request.urlopen(req, timeout=120) as resp:
        return resp.read()


def extract(data: bytes, target: Path) -> None:
    """Extract a release zip so that its single top-level folder becomes `target`."""
    with tempfile.TemporaryDirectory() as tmp:
        tmp_path = Path(tmp)
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            for member in zf.namelist():
                # Refuse path traversal entries.
                if member.startswith("/") or ".." in Path(member).parts:
                    raise ValueError(f"unsafe path in archive: {member}")
            zf.extractall(tmp_path)
        entries = [p for p in tmp_path.iterdir() if not p.name.startswith("__MACOSX")]
        source = entries[0] if len(entries) == 1 and entries[0].is_dir() else tmp_path
        if target.exists():
            shutil.rmtree(target)
        shutil.copytree(source, target)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("omeka_dir", type=Path)
    parser.add_argument("--only", nargs="*", help="limit to these module/theme names")
    parser.add_argument("--force", action="store_true", help="re-download existing folders")
    parser.add_argument("--with-core", action="store_true", help="first download Omeka S itself into omeka_dir")
    args = parser.parse_args()

    manifest = json.loads(MANIFEST.read_text(encoding="utf-8-sig"))
    if args.with_core and not (args.omeka_dir / "bootstrap.php").exists():
        extract(download(manifest["omeka"]["url"]), args.omeka_dir)
        print(f"ok     omeka-s {manifest['omeka']['version']}")
    jobs = [("modules", m) for m in manifest["modules"]] + [("themes", t) for t in manifest["themes"]]
    if args.only:
        jobs = [(kind, e) for kind, e in jobs if e["name"] in args.only]

    failed = []
    for kind, entry in jobs:
        target = args.omeka_dir / kind / entry["name"]
        if target.exists() and not args.force:
            print(f"skip   {kind}/{entry['name']} (exists)")
            continue
        try:
            extract(download(entry["url"]), target)
            print(f"ok     {kind}/{entry['name']} {entry['version']}")
        except Exception as exc:  # keep going, report at the end
            failed.append(entry["name"])
            print(f"FAILED {kind}/{entry['name']}: {exc}", file=sys.stderr)

    if failed:
        print(f"\n{len(failed)} failed: {', '.join(failed)}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
