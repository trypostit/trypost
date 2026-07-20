#!/usr/bin/env python3
# /// script
# requires-python = ">=3.9"
# ///
"""Run trigger evals: does the skill's description fire on each query?

Adapted from Anthropic skill-creator's run_eval.py
(https://github.com/anthropics/skills/tree/main/skills/skill-creator) with two
adaptations:

  1. Isolation. Each query runs in either a fresh Docker container off
     bmad-eval-runner:latest, or a fresh local tmp dir under ~/bmad-evals/<run-id>/
     with HOME overridden to a clean directory. This prevents the host's global
     CLAUDE.md and auto-memory from biasing whether the skill fires.

  2. Output. Results are written to a run folder alongside the artifact eval
     run-folder layout (so triggers and artifacts can share a single report).

Usage:
  python3 run_triggers.py \\
    --skill-path PATH \\
    --triggers-file PATH/triggers.json \\
    --output-dir PATH \\
    --isolation docker|local \\
    [--workers N] [--runs-per-query N] [--timeout SECS] [--threshold 0.5]
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

from utils import (  # noqa: E402
    new_run_id,
    parse_skill_md,
    read_json,
    read_macos_keychain_credentials,
    stage_credentials,
    utc_now_iso,
    write_json,
)

DOCKER_IMAGE = "bmad-eval-runner:latest"
_KEYCHAIN_CREDS: str | None = read_macos_keychain_credentials()


def write_synthetic_skill(skills_dir: Path, skill_name: str, description: str, unique_id: str) -> tuple[Path, str]:
    """Place a synthetic skill at <skills_dir>/<clean_name>/SKILL.md.

    The Skill tool only fires for entries discovered as actual skills (frontmatter
    `name` + `description` under a `.claude/skills/<name>/SKILL.md`). Slash-commands
    under `.claude/commands/` do not auto-invoke the Skill tool, so the previous
    implementation could never observe a positive trigger. This places the synthetic
    skill where Claude Code looks for skills, with a unique name so the detector
    can disambiguate it from any pre-existing skill of the same display name.
    """
    clean_name = f"{skill_name}-skill-{unique_id}"
    skill_root = skills_dir / clean_name
    skill_root.mkdir(parents=True, exist_ok=True)
    path = skill_root / "SKILL.md"
    indented_desc = "\n  ".join(description.split("\n"))
    path.write_text(
        f"---\n"
        f"name: {clean_name}\n"
        f"description: |\n"
        f"  {indented_desc}\n"
        f"---\n\n"
        f"# {skill_name}\n\n"
        f"This skill handles: {description}\n",
        encoding="utf-8",
    )
    return path, clean_name


def parse_stream_for_trigger(buffer: str, clean_name: str) -> tuple[bool | None, str]:
    """Return (triggered_or_none, leftover_buffer). None means undecided yet."""
    triggered: bool | None = None
    pending_tool: str | None = None
    accumulated_json = ""
    leftover = ""

    while "\n" in buffer:
        line, buffer = buffer.split("\n", 1)
        line = line.strip()
        if not line:
            continue
        try:
            evt = json.loads(line)
        except json.JSONDecodeError:
            continue

        if evt.get("type") == "stream_event":
            se = evt.get("event", {})
            t = se.get("type", "")
            if t == "content_block_start":
                cb = se.get("content_block", {})
                if cb.get("type") == "tool_use":
                    name = cb.get("name", "")
                    if name in ("Skill", "Read"):
                        pending_tool = name
                        accumulated_json = ""
                    else:
                        return False, ""
            elif t == "content_block_delta" and pending_tool:
                delta = se.get("delta", {})
                if delta.get("type") == "input_json_delta":
                    accumulated_json += delta.get("partial_json", "")
                    if clean_name in accumulated_json:
                        return True, ""
            elif t in ("content_block_stop", "message_stop"):
                if pending_tool:
                    return clean_name in accumulated_json, ""
                if t == "message_stop":
                    return False, ""
        elif evt.get("type") == "assistant":
            for item in evt.get("message", {}).get("content", []):
                if item.get("type") != "tool_use":
                    continue
                tname = item.get("name", "")
                tinput = item.get("input", {})
                if tname == "Skill" and clean_name in tinput.get("skill", ""):
                    return True, ""
                if tname == "Read" and clean_name in tinput.get("file_path", ""):
                    return True, ""
            return False, ""
        elif evt.get("type") == "result":
            return triggered if triggered is not None else False, ""
    leftover = buffer
    return triggered, leftover


def run_query_local(query: str, skill_name: str, description: str,
                    workspace_root: Path, timeout: int) -> bool:
    workspace_root.mkdir(parents=True, exist_ok=True)
    home_dir = workspace_root / ".home"
    (home_dir / ".claude").mkdir(parents=True, exist_ok=True)
    stage_credentials(home_dir / ".claude", _KEYCHAIN_CREDS)
    project_dir = workspace_root / "project"
    skills_dir = project_dir / ".claude" / "skills"
    project_dir.mkdir(parents=True, exist_ok=True)

    unique = uuid.uuid4().hex[:8]
    cmd_file, clean_name = write_synthetic_skill(skills_dir, skill_name, description, unique)

    env = {
        "HOME": str(home_dir),
        "CLAUDE_CONFIG_DIR": str(home_dir / ".claude"),
        "PATH": os.environ.get("PATH", ""),
        "ANTHROPIC_API_KEY": os.environ.get("ANTHROPIC_API_KEY", ""),
    }

    cmd = [
        "claude", "-p", query,
        "--output-format", "stream-json",
        "--verbose",
        "--include-partial-messages",
        "--dangerously-skip-permissions",
    ]

    try:
        proc = subprocess.Popen(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            cwd=str(project_dir),
            env=env,
        )
        buffer = ""
        triggered: bool | None = None
        start = time.time()
        try:
            while time.time() - start < timeout:
                if proc.poll() is not None:
                    rest = proc.stdout.read()
                    if rest:
                        buffer += rest.decode("utf-8", errors="replace")
                    break
                chunk = proc.stdout.read1(8192) if hasattr(proc.stdout, "read1") else proc.stdout.read(8192)
                if not chunk:
                    time.sleep(0.05)
                    continue
                buffer += chunk.decode("utf-8", errors="replace")
                decided, buffer = parse_stream_for_trigger(buffer, clean_name)
                if decided is not None:
                    triggered = decided
                    break
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait()
        if triggered is None:
            decided, _ = parse_stream_for_trigger(buffer + "\n", clean_name)
            triggered = bool(decided)
        return bool(triggered)
    finally:
        try:
            shutil.rmtree(cmd_file.parent, ignore_errors=True)
        except OSError:
            pass


def run_query_docker(query: str, skill_name: str, description: str,
                     workspace_root: Path, timeout: int) -> bool:
    workspace_root.mkdir(parents=True, exist_ok=True)
    unique = uuid.uuid4().hex[:8]
    skills_in = workspace_root / "skills_in"
    skills_in.mkdir(parents=True, exist_ok=True)
    _, clean_name = write_synthetic_skill(skills_in, skill_name, description, unique)

    creds_dir: Path | None = None
    if _KEYCHAIN_CREDS:
        creds_dir = workspace_root / "creds_in"
        creds_dir.mkdir(parents=True, exist_ok=True)
        (creds_dir / ".credentials.json").write_text(_KEYCHAIN_CREDS, encoding="utf-8")

    container_script = f"""
set -e
mkdir -p /workspace/.claude/skills
cp -R /skills/. /workspace/.claude/skills/ 2>/dev/null || true
if [ -f /creds/.credentials.json ]; then
  mkdir -p /home/evaluator/.claude
  cp /creds/.credentials.json /home/evaluator/.claude/.credentials.json
fi
cd /workspace
claude -p "$EVAL_QUERY" \\
  --output-format stream-json --verbose --include-partial-messages \\
  --dangerously-skip-permissions \\
  > /output/stream.jsonl 2>/dev/null || true
"""

    output_dir = workspace_root / "output"
    output_dir.mkdir(parents=True, exist_ok=True)

    cmd = [
        "docker", "run", "--rm",
        "-v", f"{skills_in}:/skills:ro",
        "-v", f"{output_dir}:/output",
        "-e", "ANTHROPIC_API_KEY",
        "-e", f"EVAL_QUERY={query}",
    ]
    if creds_dir:
        cmd += ["-v", f"{creds_dir}:/creds:ro"]
    cmd += [DOCKER_IMAGE, "bash", "-c", container_script]

    try:
        subprocess.run(cmd, capture_output=True, timeout=timeout + 30)
    except subprocess.TimeoutExpired:
        pass

    stream_file = output_dir / "stream.jsonl"
    if not stream_file.is_file():
        return False
    decided, _ = parse_stream_for_trigger(stream_file.read_text(encoding="utf-8", errors="replace") + "\n", clean_name)
    return bool(decided)


def main() -> int:
    parser = argparse.ArgumentParser(description="Run trigger evals in isolation")
    parser.add_argument("--skill-path", required=True, type=Path)
    parser.add_argument("--triggers-file", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--isolation", choices=("docker", "local"), required=True)
    parser.add_argument("--workers", type=int, default=8)
    parser.add_argument("--runs-per-query", type=int, default=3)
    parser.add_argument("--timeout", type=int, default=45)
    parser.add_argument("--threshold", type=float, default=0.5)
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    skill_path = args.skill_path.resolve()
    triggers_file = args.triggers_file.resolve()
    if not triggers_file.is_file():
        print(f"triggers file not found: {triggers_file}", file=sys.stderr)
        return 2

    skill_name, description, _ = parse_skill_md(skill_path)
    queries = read_json(triggers_file)

    run_id = new_run_id(f"{skill_name}-triggers")
    run_dir = (args.output_dir / run_id).resolve()
    (run_dir / "queries").mkdir(parents=True, exist_ok=True)

    write_json(run_dir / "run.json", {
        "run_id": run_id,
        "skill_name": skill_name,
        "description": description,
        "isolation": args.isolation,
        "started_at": utc_now_iso(),
        "query_count": len(queries),
        "runs_per_query": args.runs_per_query,
        "threshold": args.threshold,
    })

    runner = run_query_docker if args.isolation == "docker" else run_query_local

    def run_one(idx: int, q: dict, run_idx: int) -> tuple[int, bool]:
        ws = run_dir / "queries" / f"q{idx:03d}-r{run_idx}"
        triggered = runner(q["query"], skill_name, description, ws, args.timeout)
        return idx, triggered

    per_query: dict[int, list[bool]] = {}
    if not args.quiet:
        print(f"[run_triggers] {len(queries)} queries × {args.runs_per_query} runs, isolation={args.isolation}", file=sys.stderr)

    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        futures = []
        for idx, q in enumerate(queries):
            for run_idx in range(args.runs_per_query):
                futures.append(pool.submit(run_one, idx, q, run_idx))
        for fut in as_completed(futures):
            try:
                idx, triggered = fut.result()
            except Exception as e:
                print(f"Warning: query failed: {e}", file=sys.stderr)
                continue
            per_query.setdefault(idx, []).append(triggered)

    results = []
    for idx, q in enumerate(queries):
        triggers = per_query.get(idx, [])
        rate = (sum(triggers) / len(triggers)) if triggers else 0.0
        should = bool(q["should_trigger"])
        if should:
            passed = rate >= args.threshold
        else:
            passed = rate < args.threshold
        results.append({
            "query": q["query"],
            "should_trigger": should,
            "trigger_rate": rate,
            "triggers": int(sum(triggers)),
            "runs": len(triggers),
            "pass": passed,
        })

    output = {
        "run_id": run_id,
        "completed_at": utc_now_iso(),
        "skill_name": skill_name,
        "description": description,
        "isolation": args.isolation,
        "results": results,
        "summary": {
            "total": len(results),
            "passed": sum(1 for r in results if r["pass"]),
            "failed": sum(1 for r in results if not r["pass"]),
        },
    }
    write_json(run_dir / "triggers-result.json", output)
    print(json.dumps(output, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
