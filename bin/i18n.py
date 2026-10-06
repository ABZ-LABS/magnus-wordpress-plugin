#!/usr/bin/env python3
"""Translations for the plugin, without WP-CLI.

    bin/i18n.py pot     # languages/iamagnus-chat.pot from the PHP source
    bin/i18n.py check   # every string in the source is translated in the .po
    bin/i18n.py mo      # check, then compile the Spanish .po into one .mo per locale

The Spanish is neutral (tú) and the same file serves every Spanish locale
WordPress uses: a site set to es_UY or es_MX gets Spanish without anyone
translating it twice.
"""
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = "iamagnus-chat"
POT = ROOT / "languages" / f"{DOMAIN}.pot"
SPANISH_PO = ROOT / "languages" / f"{DOMAIN}-es.po"
SPANISH_LOCALES = [
    "es_ES", "es_AR", "es_UY", "es_MX", "es_CL", "es_CO", "es_PE", "es_VE",
    "es_EC", "es_CR", "es_GT", "es_DO", "es_PR",
]

CALL = re.compile(
    r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'" + DOMAIN + r"'\s*\)"
)
TRANSLATORS = re.compile(r"/\*\s*translators:(.*?)\*/", re.S)
HEADERS = ("Plugin Name", "Description", "Author")


def php_unescape(s):
    return s.replace("\\'", "'").replace("\\\\", "\\")


def po_escape(s):
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def extract():
    """Every translatable string, in source order, with its translator comment."""
    found = {}
    main = (ROOT / f"{DOMAIN}.php").read_text(encoding="utf-8")
    for header in HEADERS:
        m = re.search(r"^\s*\*\s*" + re.escape(header) + r":\s*(.+)$", main, re.M)
        if m:
            found.setdefault(m.group(1).strip(), {"refs": [f"{DOMAIN}.php"], "comment": f"Plugin {header.lower()}."})
    files = [ROOT / f"{DOMAIN}.php"] + sorted((ROOT / "includes").glob("*.php"))
    for path in files:
        text = path.read_text(encoding="utf-8")
        for m in CALL.finditer(text):
            msgid = php_unescape(m.group(1))
            line = text.count("\n", 0, m.start()) + 1
            before = text[max(0, m.start() - 400):m.start()]
            notes = TRANSLATORS.findall(before)
            entry = found.setdefault(msgid, {"refs": [], "comment": None})
            entry["refs"].append(f"{path.relative_to(ROOT)}:{line}")
            if notes and "%" in msgid and not entry["comment"]:
                entry["comment"] = "translators:" + notes[-1].strip()
    return found


def write_pot(strings):
    out = [
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Magnus Chat\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        f'"X-Domain: {DOMAIN}\\n"',
        "",
    ]
    for msgid, entry in strings.items():
        if entry["comment"]:
            out.append(f"#. {entry['comment']}")
        out.append("#: " + " ".join(entry["refs"]))
        out.append(f'msgid "{po_escape(msgid)}"')
        out.append('msgstr ""')
        out.append("")
    POT.write_text("\n".join(out), encoding="utf-8")
    print(f"{POT.relative_to(ROOT)}: {len(strings)} strings")


def read_po(path):
    """msgid -> msgstr for single-line entries (the format this repo writes)."""
    entries = {}
    msgid = None
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.startswith("msgid "):
            msgid = json_unquote(line[6:])
        elif line.startswith("msgstr ") and msgid is not None:
            entries[msgid] = json_unquote(line[7:])
            msgid = None
    entries.pop("", None)
    return entries


def json_unquote(s):
    s = s.strip()
    assert s.startswith('"') and s.endswith('"'), s
    return s[1:-1].replace('\\"', '"').replace("\\n", "\n").replace("\\\\", "\\")


def check(strings):
    po = read_po(SPANISH_PO)
    missing = [s for s in strings if not po.get(s)]
    stale = [s for s in po if s not in strings]
    placeholders = [s for s in strings if po.get(s) and sorted(re.findall(r"%\d?\$?[sd]", s)) != sorted(re.findall(r"%\d?\$?[sd]", po[s]))]
    for s in missing:
        print(f"  missing: {s}")
    for s in stale:
        print(f"  no longer in the source: {s}")
    for s in placeholders:
        print(f"  placeholders differ: {s}")
    if missing or placeholders:
        sys.exit(1)
    print(f"{SPANISH_PO.relative_to(ROOT)}: {len(strings)} strings translated" + (f", {len(stale)} stale" if stale else ""))


def compile_mo():
    for locale in SPANISH_LOCALES:
        target = ROOT / "languages" / f"{DOMAIN}-{locale}.mo"
        subprocess.run(["msgfmt", "--check-format", "-o", str(target), str(SPANISH_PO)], check=True)
    print(f"compiled {len(SPANISH_LOCALES)} locales: {', '.join(SPANISH_LOCALES)}")


if __name__ == "__main__":
    command = sys.argv[1] if len(sys.argv) > 1 else "mo"
    strings = extract()
    if command == "pot":
        write_pot(strings)
    elif command == "check":
        check(strings)
    elif command == "mo":
        write_pot(strings)
        check(strings)
        compile_mo()
    else:
        sys.exit(__doc__)
