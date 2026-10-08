#!/usr/bin/env python3
"""Finnish translation tooling for the kit (stdlib only).

Commands:
    todo  <omeka_dir> <out_dir>   write <Module>.todo.json with public strings
                                  that have no Finnish translation yet
    build <omeka_dir>             merge upstream fi_FI.po + translations/*.json,
                                  write translations/<Module>/fi_FI.po and .mo
    install <omeka_dir>           copy the built fi_FI.po/.mo into each module

Kit translations live in translations/<Module>.fi_FI.json as
{"msgid": "msgstr", ...}. Upstream translations win only when the kit file
has no entry, so reviewers can override them.
"""
import json
import re
import shutil
import struct
import sys
from pathlib import Path

KIT = Path(__file__).resolve().parent.parent
TRANSLATIONS = KIT / "translations"
sys.path.insert(0, str(Path(__file__).resolve().parent))
from public_strings import parse_pot, scan_sources, PUBLIC_REF  # noqa: E402

MODULES = ["AdvancedSearch", "BlockPlus", "BotChallenge", "ContactUs", "Group", "Guest",
           "GuestPrivate", "Hierarchy", "Mapping", "MetadataBrowse", "Reference"]
LOCALE = "fi_FI"


_UNESCAPES = {"n": "\n", "t": "\t", '"': '"', "\\": "\\", "'": "'"}


def unescape(s: str) -> str:
    return re.sub(r"\\(.)", lambda m: _UNESCAPES.get(m.group(1), "\\" + m.group(1)), s)


def escape(s: str) -> str:
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n").replace("\t", "\\t")


def read_po(path: Path) -> dict:
    """Minimal .po reader: returns {msgid: msgstr} for non-empty, non-plural entries."""
    result, key, cur, field = {}, None, {"msgid": "", "msgstr": ""}, None
    if not path.exists():
        return result

    def flush():
        if cur["msgid"] and cur["msgstr"]:
            result[unescape(cur["msgid"])] = unescape(cur["msgstr"])

    for line in path.read_text(encoding="utf-8").splitlines() + [""]:
        line = line.strip()
        if line.startswith("msgid "):
            flush()
            cur, field = {"msgid": line[6:].strip()[1:-1], "msgstr": ""}, "msgid"
        elif line.startswith("msgstr "):
            cur["msgstr"], field = line[7:].strip()[1:-1], "msgstr"
        elif line.startswith("msgid_plural") or line.startswith("msgstr["):
            field = None
        elif line.startswith('"') and field:
            cur[field] += line[1:-1]
        elif not line:
            flush()
            cur, field = {"msgid": "", "msgstr": ""}, None
    return result


def read_mo(path: Path) -> dict:
    """Read a compiled gettext catalog (singular entries only)."""
    if not path.exists():
        return {}
    data = path.read_bytes()
    order = "<" if struct.unpack("<I", data[:4])[0] == 0x950412DE else ">"
    _, _, n, koff, voff = struct.unpack(order + "5I", data[:20])
    result = {}
    for i in range(n):
        klen, kpos = struct.unpack(order + "2I", data[koff + 8 * i: koff + 8 * i + 8])
        vlen, vpos = struct.unpack(order + "2I", data[voff + 8 * i: voff + 8 * i + 8])
        key = data[kpos:kpos + klen].decode("utf-8", "replace")
        val = data[vpos:vpos + vlen].decode("utf-8", "replace")
        if key and "\0" not in key and val:
            result[key] = val
    return result


def upstream_catalog(module_dir: Path) -> dict:
    lang = module_dir / "language"
    return read_po(lang / f"{LOCALE}.po") or read_mo(lang / f"{LOCALE}.mo")


def public_strings(module_dir: Path) -> list:
    pot = module_dir / "language" / "template.pot"
    entries = parse_pot(pot) if pot.exists() else scan_sources(module_dir)
    return sorted({unescape(m) for m, refs in entries if any(PUBLIC_REF.search(r) for r in refs)})


def write_po(path: Path, module: str, catalog: dict) -> None:
    lines = [
        'msgid ""', 'msgstr ""',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        f'"Language: {LOCALE}\\n"',
        f'"Project-Id-Version: {module} (Omeka S Finland Kit)\\n"',
        '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"', "",
    ]
    for msgid in sorted(catalog):
        lines += [f'msgid "{escape(msgid)}"', f'msgstr "{escape(catalog[msgid])}"', ""]
    path.write_text("\n".join(lines), encoding="utf-8")


def write_mo(path: Path, catalog: dict) -> None:
    """GNU gettext .mo writer (little endian, no hash table)."""
    items = sorted(catalog.items())
    items.insert(0, ("", f"Content-Type: text/plain; charset=UTF-8\nLanguage: {LOCALE}\n"
                         "Plural-Forms: nplurals=2; plural=(n != 1);\n"))
    ids = [k.encode("utf-8") for k, _ in items]
    strs = [v.encode("utf-8") for _, v in items]
    n = len(items)
    data_start = 7 * 4 + 16 * n  # header + two offset tables
    blob = b""
    key_offsets, val_offsets = [], []
    for s in ids:
        key_offsets.append((len(s), data_start + len(blob)))
        blob += s + b"\0"
    for s in strs:
        val_offsets.append((len(s), data_start + len(blob)))
        blob += s + b"\0"
    header = struct.pack("<7I", 0x950412DE, 0, n, 28, 28 + 8 * n, 0, 0)
    table = b"".join(struct.pack("<2I", l, o) for l, o in key_offsets + val_offsets)
    path.write_bytes(header + table + blob)


def placeholders(s: str) -> list:
    return sorted(re.findall(r"%(?:\d+\$)?[sdfu%]|\{[a-z_]+\}", s))


def cmd_todo(omeka_dir: Path, out_dir: Path) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)
    total = 0
    for module in MODULES:
        mdir = omeka_dir / "modules" / module
        upstream = upstream_catalog(mdir)
        kit = load_kit(module)
        todo = [s for s in public_strings(mdir) if s not in upstream and s not in kit]
        (out_dir / f"{module}.todo.json").write_text(json.dumps(todo, ensure_ascii=False, indent=1), encoding="utf-8")
        total += len(todo)
        print(f"{module:15} {len(todo):5} to translate")
    print(f"{'TOTAL':15} {total:5}")


def load_kit(module: str) -> dict:
    path = TRANSLATIONS / f"{module}.{LOCALE}.json"
    return json.loads(path.read_text(encoding="utf-8")) if path.exists() else {}


def cmd_build(omeka_dir: Path) -> None:
    for module in MODULES:
        mdir = omeka_dir / "modules" / module
        catalog = upstream_catalog(mdir)
        kit = load_kit(module)
        bad = [k for k, v in kit.items() if placeholders(k) != placeholders(v)]
        for k in bad:
            print(f"  placeholder mismatch in {module}: {k!r}", file=sys.stderr)
        catalog.update({k: v for k, v in kit.items() if v and k not in bad})
        out = TRANSLATIONS / module
        out.mkdir(parents=True, exist_ok=True)
        write_po(out / f"{LOCALE}.po", module, catalog)
        write_mo(out / f"{LOCALE}.mo", catalog)
        public = public_strings(mdir)
        done = sum(1 for s in public if s in catalog)
        print(f"{module:15} {len(catalog):5} entries, public coverage {done}/{len(public)}")


def cmd_install(omeka_dir: Path) -> None:
    for module in MODULES:
        src = TRANSLATIONS / module
        dst = omeka_dir / "modules" / module / "language"
        if not (src / f"{LOCALE}.mo").exists():
            continue
        dst.mkdir(exist_ok=True)
        for ext in ("po", "mo"):
            shutil.copy2(src / f"{LOCALE}.{ext}", dst / f"{LOCALE}.{ext}")
        print(f"installed {module}")


if __name__ == "__main__":
    if len(sys.argv) < 3 or sys.argv[1] not in {"todo", "build", "install"}:
        print(__doc__)
        sys.exit(2)
    cmd, omeka = sys.argv[1], Path(sys.argv[2])
    if cmd == "todo":
        cmd_todo(omeka, Path(sys.argv[3]) if len(sys.argv) > 3 else KIT / "build" / "todo")
    elif cmd == "build":
        cmd_build(omeka)
    else:
        cmd_install(omeka)
