#!/usr/bin/env python3
# /// script
# requires-python = ">=3.9"
# ///
"""Shared helpers for the eval runner."""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path


def parse_skill_md(skill_path: Path) -> tuple[str, str, str]:
    """Return (name, description, body) from the skill's SKILL.md frontmatter."""
    text = (skill_path / "SKILL.md").read_text(encoding="utf-8")
    fm_match = re.match(r"^---\s*\n(.*?)\n---\s*\n(.*)$", text, re.DOTALL)
    if not fm_match:
        raise ValueError(f"SKILL.md at {skill_path} is missing frontmatter")
    frontmatter, body = fm_match.group(1), fm_match.group(2)

    name = None
    description_lines: list[str] = []
    in_description = False
    for line in frontmatter.splitlines():
        if line.startswith("name:"):
            name = line.split(":", 1)[1].strip()
            in_description = False
        elif line.startswith("description:"):
            value = line.split(":", 1)[1].strip()
            if value in ("|", ">"):
                in_description = True
            else:
                description_lines = [value]
                in_description = False
        elif in_description and line.startswith(("  ", "\t")):
            description_lines.append(line.strip())
        elif in_description:
            in_description = False

    if not name:
        raise ValueError(f"SKILL.md at {skill_path} is missing a name")
    return name, " ".join(description_lines).strip(), body


def discover_project_root(skill_path: Path) -> Path:
    """Walk up from the skill looking for _bmad/ or .git; default to skill's grandparent."""
    for parent in [skill_path, *skill_path.parents]:
        if (parent / "_bmad").is_dir() or (parent / ".git").exists():
            return parent
    return skill_path.parent.parent


def discover_evals(
    skill_path: Path,
    project_root: Path,
    explicit: Path | None,
) -> dict[str, Path]:
    """Locate evals.json and triggers.json. Return dict with keys 'evals' and/or 'triggers'."""
    found: dict[str, Path] = {}

    def check_dir(d: Path) -> None:
        if not d.is_dir():
            return
        for key, fname in (("evals", "evals.json"), ("triggers", "triggers.json")):
            candidate = d / fname
            if candidate.is_file() and key not in found:
                found[key] = candidate

    if explicit is not None:
        explicit = explicit.resolve()
        if explicit.is_file():
            if explicit.name == "evals.json":
                found["evals"] = explicit
            elif explicit.name == "triggers.json":
                found["triggers"] = explicit
        elif explicit.is_dir():
            check_dir(explicit)
        return found

    skill_name = skill_path.name
    candidates: list[Path] = [
        skill_path / "evals",
        skill_path.parent.parent / "evals" / skill_name,
        project_root / "evals" / skill_name,
    ]
    for d in candidates:
        check_dir(d)
        if found:
            break

    if not found:
        evals_root = project_root / "evals"
        if evals_root.is_dir():
            for sub in evals_root.rglob(skill_name):
                if sub.is_dir():
                    check_dir(sub)
                    if found:
                        break

    return found


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def new_run_id(skill_name: str) -> str:
    return f"{datetime.now().strftime('%Y%m%d-%H%M%S')}-{skill_name}"


def have_docker() -> bool:
    if shutil.which("docker") is None:
        return False
    try:
        result = subprocess.run(
            ["docker", "info"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=5,
        )
        return result.returncode == 0
    except Exception:
        return False


def docker_image_present(image: str = "bmad-eval-runner:latest") -> bool:
    if not have_docker():
        return False
    try:
        result = subprocess.run(
            ["docker", "image", "inspect", image],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=10,
        )
        return result.returncode == 0
    except Exception:
        return False


def read_macos_keychain_credentials() -> str | None:
    """Read the Claude Code OAuth credentials JSON from the macOS Keychain.

    Returns the raw JSON string stored under service "Claude Code-credentials",
    or None if unavailable (non-macOS, entry missing, or access denied).

    Called in the parent process — which owns the Keychain ACL — so the credential
    can be staged into each isolated workspace's `.claude/.credentials.json` before
    `claude -p` is launched. Without this, an isolated subprocess with HOME pointed
    at an empty dir has no auth and every eval fails with "Not logged in."
    """
    if sys.platform != "darwin":
        return None
    try:
        result = subprocess.run(
            ["security", "find-generic-password", "-s", "Claude Code-credentials", "-w"],
            capture_output=True,
            timeout=5,
        )
        if result.returncode != 0:
            return None
        val = result.stdout.decode("utf-8", errors="replace").strip()
        return val if val else None
    except Exception:
        return None


def stage_credentials(claude_dir: Path, credentials_json: str | None) -> None:
    """Write credentials_json to <claude_dir>/.credentials.json. No-op if None."""
    if not credentials_json:
        return
    claude_dir.mkdir(parents=True, exist_ok=True)
    (claude_dir / ".credentials.json").write_text(credentials_json, encoding="utf-8")


def write_json(path: Path, data: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")


def read_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def parse_skill_dependencies(skill_path: Path) -> list[str]:
    """Return skill names declared under 'dependencies:' in SKILL.md frontmatter."""
    try:
        text = (skill_path / "SKILL.md").read_text(encoding="utf-8")
    except (FileNotFoundError, OSError):
        return []
    fm = re.match(r"^---\s*\n(.*?)\n---", text, re.DOTALL)
    if not fm:
        return []
    deps: list[str] = []
    in_deps = False
    for line in fm.group(1).splitlines():
        if re.match(r"^dependencies\s*:", line):
            in_deps = True
        elif in_deps:
            m = re.match(r"^\s+-\s+(\S+)", line)
            if m:
                deps.append(m.group(1))
            elif not line.startswith((" ", "\t")):
                break
    return deps


def discover_setup_dirs(evals_file: Path, eval_id: str | None = None) -> list[Path]:
    """Return ordered list of setup overlay dirs that exist.

    base:     <evals_dir>/setup/
    per-eval: <evals_dir>/<eval_id>/setup/

    Applied base-first so per-eval overlays win on conflict.
    """
    evals_dir = evals_file.parent
    dirs: list[Path] = []
    base = evals_dir / "setup"
    if base.is_dir():
        dirs.append(base)
    if eval_id:
        per_eval = evals_dir / eval_id / "setup"
        if per_eval.is_dir():
            dirs.append(per_eval)
    return dirs


def apply_setup_overlay(setup_dirs: list[Path], dest: Path) -> None:
    """Rsync each setup dir onto dest in order (base first, per-eval last)."""
    dest.mkdir(parents=True, exist_ok=True)
    for src in setup_dirs:
        if not src.is_dir():
            continue
        subprocess.run(
            ["rsync", "-a", f"{src}/", f"{dest}/"],
            check=False,
        )


__all__ = [
    "parse_skill_md",
    "discover_project_root",
    "discover_evals",
    "utc_now_iso",
    "new_run_id",
    "have_docker",
    "docker_image_present",
    "read_macos_keychain_credentials",
    "stage_credentials",
    "write_json",
    "read_json",
    "parse_skill_dependencies",
    "discover_setup_dirs",
    "apply_setup_overlay",
]
