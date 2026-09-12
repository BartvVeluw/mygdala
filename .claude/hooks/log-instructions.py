#!/usr/bin/env python3
"""Schrijft op welk instructiebestand actief werd, en waardoor.

Deze repository heeft vier lagen instructies (WORKFLOW.md, "De vier lagen").
Achteraf is moeilijk na te gaan welke daarvan een sessie echt heeft gezien:
een agent kan het alleen navertellen. Deze hook legt het vast in
`.claude/instructions-loaded.log`.

Wat erin komt, per regel: het tijdstip, de eerste acht tekens van de
sessie-id, het instructiebestand, waarom het actief werd, en het bestand dat
de aanleiding was.

Twee soorten gebeurtenissen:

- **Een skill wordt aangeroepen.** Waargenomen, niet afgeleid: de aanroep
  gaat door de tool `Skill` heen en die noemt de skill bij naam.
- **Een bestand wordt geopend of geschreven.** Welke path-regel daarbij
  hoort, wordt hier *afgeleid* uit de `paths:`-frontmatter van
  `.claude/rules/*.md` plus de `CLAUDE.md` van de map zelf. Dat is een
  benadering van wat de harness doet, geen weergave ervan — de globlijst
  blijft in het regelbestand staan, zodat er geen tweede lijst is die kan
  gaan afwijken.

Elke combinatie van instructiebestand en aanleiding wordt per sessie één keer
geschreven. Het gaat om het moment waarop iets actief werd, niet om een
telling.

WAT DIT LOGBOEK NIET ZIET, en waarom dat zo blijft:

- **Bestanden die via `Bash` gelezen worden** (`cat`, `sed`, `grep`). De hook
  hangt aan de tools uit FILE_TOOLS, en dat zijn precies de tools waar de
  harness zijn path-regels aan hangt. Een sessie die via Bash leest krijgt die
  regels dus ook niet; het logboek verzwijgt niets wat er wél gebeurde. Uit
  een willekeurig shellcommando afleiden welke bestanden het opende vraagt een
  parser die bij elke pipe of `xargs` het verkeerde antwoord geeft, en een
  verkeerd logboek is erger dan een leeg logboek.
- **Een uitchecking zonder `.claude/`.** Dan stopt main() meteen, en er komt
  ook geen regel die zegt dat er niets gebeurde. `Tests\Architecture\ContextSetupTest`
  is de controle daarvoor; zie WORKFLOW.md, "Een verse worktree".

Een regel in het logboek is dus bewijs dat een laag geladen werd. Het ontbreken
van een regel is geen bewijs van het tegendeel.

Er wordt nooit inhoud gelogd: geen promptteksten, geen bestandsinhoud, geen
omgevingsvariabelen. Alleen paden en namen.

De hook faalt nooit hardop. Wat er ook misgaat, hij eindigt met 0 en laat de
tool-aanroep met rust.
"""

from __future__ import annotations

import fnmatch
import json
import os
import sys
from datetime import datetime, timezone

LOG_NAME = ".claude/instructions-loaded.log"
SEEN_NAME = ".claude/instructions-loaded.seen"

# Alleen deze tools zeggen iets over "er is een bestand geopend".
FILE_TOOLS = {"Read", "Edit", "Write", "NotebookEdit"}


def expand_braces(pattern: str) -> list[str]:
    """`a/{b,c}*.php` -> `a/b*.php`, `a/c*.php`. Zonder accolades: zichzelf."""
    start = pattern.find("{")
    if start == -1:
        return [pattern]
    end = pattern.find("}", start)
    if end == -1:
        return [pattern]

    head, options, tail = pattern[:start], pattern[start + 1:end], pattern[end + 1:]
    expanded = []
    for option in options.split(","):
        expanded.extend(expand_braces(head + option + tail))
    return expanded


def rule_paths(rule_file: str) -> list[str]:
    """De globs uit de `paths:`-frontmatter, zonder een YAML-parser."""
    patterns: list[str] = []
    try:
        with open(rule_file, encoding="utf-8") as handle:
            lines = handle.read().splitlines()
    except OSError:
        return patterns

    if not lines or lines[0].strip() != "---":
        return patterns

    in_paths = False
    for line in lines[1:]:
        if line.strip() == "---":
            break
        if line.startswith("paths:"):
            in_paths = True
            continue
        if in_paths:
            stripped = line.strip()
            if stripped.startswith("- "):
                patterns.append(stripped[2:].strip().strip("\"'"))
            elif stripped and not line.startswith(" "):
                in_paths = False

    return patterns


def matching_rules(project_dir: str, relative_path: str) -> list[tuple[str, str]]:
    """(regelbestand, glob die matchte) voor elke path-regel die aanslaat."""
    matches = []
    rules_dir = os.path.join(project_dir, ".claude", "rules")

    try:
        names = sorted(os.listdir(rules_dir))
    except OSError:
        return matches

    for name in names:
        if not name.endswith(".md"):
            continue
        rule_file = os.path.join(rules_dir, name)
        for pattern in rule_paths(rule_file):
            for candidate in expand_braces(pattern):
                if fnmatch.fnmatch(relative_path, candidate):
                    matches.append((".claude/rules/" + name, candidate))
                    break
            else:
                continue
            break

    return matches


def folder_instructions(project_dir: str, relative_path: str) -> list[str]:
    """De CLAUDE.md's boven dit bestand, de projectroot niet meegerekend."""
    found = []
    parts = relative_path.split("/")[:-1]

    for depth in range(1, len(parts) + 1):
        folder = "/".join(parts[:depth])
        if os.path.isfile(os.path.join(project_dir, folder, "CLAUDE.md")):
            found.append(folder + "/CLAUDE.md")

    return found


def already_logged(project_dir: str, session: str, key: str) -> bool:
    """True als deze sessie deze regel al geschreven heeft."""
    marker = session + "\t" + key
    seen_file = os.path.join(project_dir, SEEN_NAME)

    try:
        with open(seen_file, encoding="utf-8") as handle:
            if marker in handle.read().splitlines():
                return True
    except OSError:
        pass

    try:
        os.makedirs(os.path.dirname(seen_file), exist_ok=True)
        with open(seen_file, "a", encoding="utf-8") as handle:
            handle.write(marker + "\n")
    except OSError:
        pass

    return False


def write(project_dir: str, session: str, instruction: str, reason: str, trigger: str) -> None:
    if already_logged(project_dir, session, instruction + "\t" + trigger):
        return

    stamp = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%SZ")
    line = "\t".join([stamp, session[:8] or "-", instruction, reason, trigger])

    try:
        log_file = os.path.join(project_dir, LOG_NAME)
        os.makedirs(os.path.dirname(log_file), exist_ok=True)
        with open(log_file, "a", encoding="utf-8") as handle:
            handle.write(line + "\n")
    except OSError:
        pass


def main() -> None:
    event = json.load(sys.stdin)

    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or event.get("cwd") or os.getcwd()
    if not os.path.isdir(os.path.join(project_dir, ".claude")):
        return  # niet de projectmap; nooit ergens anders een logboek achterlaten
    session = str(event.get("session_id") or "")
    tool = event.get("tool_name") or ""
    tool_input = event.get("tool_input") or {}

    if tool == "Skill":
        skill = str(tool_input.get("skill") or "").strip()
        if skill:
            write(
                project_dir,
                session,
                ".claude/skills/" + skill + "/SKILL.md",
                "skill aangeroepen",
                "Skill(" + skill + ")",
            )
        return

    if tool not in FILE_TOOLS:
        return

    raw_path = str(tool_input.get("file_path") or tool_input.get("notebook_path") or "")
    if not raw_path:
        return

    try:
        relative = os.path.relpath(raw_path, project_dir).replace(os.sep, "/")
    except ValueError:
        return
    if relative.startswith(".."):
        return

    for rule_file, pattern in matching_rules(project_dir, relative):
        write(project_dir, session, rule_file, "pad matcht " + pattern, relative)

    for folder_file in folder_instructions(project_dir, relative):
        write(project_dir, session, folder_file, "bestand in deze map geopend", relative)


if __name__ == "__main__":
    try:
        main()
    except Exception:  # noqa: BLE001 - een hook mag nooit een tool-aanroep breken
        pass
    sys.exit(0)
