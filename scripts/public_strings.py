#!/usr/bin/env python3
"""List the translatable strings a module shows on the public site.

Usage:
    python scripts/public_strings.py <module_dir> [--json out.json]

Reads language/template.pot when present, otherwise scans the source for
translate()/translate-like calls. A string counts as public when at least
one of its source references is outside admin-only paths (admin views,
admin controllers/forms, admin JS). Admin-only strings are skipped,
matching tenders that only require a localised public interface.
"""
import argparse
import json
import re
import sys
from pathlib import Path

# Anything outside admin views/controllers/forms may surface on the public
# site (login and contact forms, challenge pages, blocks, facets, emails).
ADMIN_REF = re.compile(r"(^|/)(view/[^/]+/admin/|src/Controller/Admin/|src/Form/Admin|asset/js/admin|data/|test/|config/module\.ini)", re.I)


class _Public:
    @staticmethod
    def search(ref: str) -> bool:
        return not ADMIN_REF.search(ref)


PUBLIC_REF = _Public()
CALL = re.compile(r"""(?:translate|->translate|\$this->translate|_tr|@translate)\s*\(\s*(['"])((?:\\.|(?!\1).)*)\1""", re.S)
ANNOT = re.compile(r"""(['"])((?:\\.|(?!\1).)+)\1\s*,?\s*//\s*@translate""")


def parse_pot(path: Path):
    entries, refs, msgid, in_id = [], [], None, False
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.startswith("#:"):
            refs += line[2:].split()
        elif line.startswith("msgid "):
            msgid, in_id = line[6:].strip()[1:-1], True
        elif in_id and line.startswith('"'):
            msgid += line.strip()[1:-1]
        else:
            if in_id and msgid:
                entries.append((msgid, refs))
            if not line.startswith("#"):
                refs = [] if not line.strip() else refs
            in_id = False if not line.startswith('"') else in_id
            if not line.strip():
                refs, msgid = [], None
    if in_id and msgid:
        entries.append((msgid, refs))
    return entries


def scan_sources(module_dir: Path):
    found = {}
    for path in module_dir.rglob("*"):
        if path.suffix not in {".php", ".phtml", ".js"} or "vendor" in path.parts:
            continue
        rel = path.relative_to(module_dir).as_posix()
        text = path.read_text(encoding="utf-8", errors="ignore")
        for regex in (CALL, ANNOT):
            for match in regex.finditer(text):
                found.setdefault(match.group(2), []).append(rel)
    return list(found.items())


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("module_dir", type=Path)
    parser.add_argument("--json", type=Path)
    args = parser.parse_args()

    pot = args.module_dir / "language" / "template.pot"
    entries = parse_pot(pot) if pot.exists() else scan_sources(args.module_dir)
    public = sorted({msgid for msgid, refs in entries if any(PUBLIC_REF.search(r) for r in refs)})
    print(f"{args.module_dir.name}: {len(entries)} total, {len(public)} public")
    if args.json:
        args.json.write_text(json.dumps(public, ensure_ascii=False, indent=1), encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
