#!/usr/bin/env python3
# /// script
# requires-python = ">=3.9"
# ///
"""Run a skill's artifact evals in isolated workspaces.

For each eval, the runner:
  1. Stages a fresh workspace (Docker container or local tmp dir under ~/bmad-evals).
  2. Applies the setup overlay (base then per-eval) so _bmad/ config and dependency
     skills land in the workspace BEFORE the skill is staged — the skill's own copy
     always wins over overlay content.
  3. Copies the skill into .claude/skills/ so it is discoverable by claude.
  4. Stages any fixture files declared in the eval's `files` list.
  5. Runs `claude -p '<prompt>' --output-format stream-json --verbose`, capturing
     the transcript. The Skill tool is available in -p mode and fires for installed
     skills, so dependency skills provided by the setup overlay are properly invokable.
  6. Rsyncs any files claude wrote into `<run-dir>/<eval-id>/artifacts/`.
  7. Writes `metrics.json` (tool-call counts, timing, output sizes).

Grading is performed separately by the parent skill's grader subagents.

Usage:
  python3 run_evals.py \\
    --skill-path PATH \\
    --evals-file PATH/evals.json \\
    --project-root PATH \\
    --output-dir PATH \\
    --isolation docker|local \\
    [--workers N] [--timeout SECS] [--eval-ids A1,B3] [--quiet]
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

from utils import (  # noqa: E402
    apply_setup_overlay,
    discover_setup_dirs,
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
RSYNC_EXCLUDES = (
    ".git", ".bare", "node_modules", ".venv", "__pycache__",
    ".pytest_cache", ".next", "dist", "build", ".cache",
    ".DS_Store", "*.pyc",
)


def stage_workspace_local(
    workspace: Path,
    project_root: Path,
    skill_path: Path,
    fixtures: list[tuple[Path, str]],
    setup_dirs: list[Path] | None = None,
) -> Path:
    """Build a clean local workspace. Returns the project root inside workspace."""
    workspace.mkdir(parents=True, exist_ok=True)
    project_dest = workspace / "project"
    home_dir = workspace / ".home"
    (home_dir / ".claude").mkdir(parents=True, exist_ok=True)

    excludes: list[str] = []
    for pat in RSYNC_EXCLUDES:
        excludes.extend(["--exclude", pat])

    if shutil.which("rsync"):
        subprocess.run(
            ["rsync", "-a", *excludes, f"{project_root}/", f"{project_dest}/"],
            check=True,
        )
    else:
        shutil.copytree(project_root, project_dest, dirs_exist_ok=True,
                        ignore=shutil.ignore_patterns(*RSYNC_EXCLUDES))

    # Apply setup overlay before staging the skill — the skill's own copy wins.
    if setup_dirs:
        apply_setup_overlay(setup_dirs, project_dest)

    skill_link_dir = project_dest / ".claude" / "skills"
    skill_link_dir.mkdir(parents=True, exist_ok=True)
    skill_dest = skill_link_dir / skill_path.name
    if not skill_dest.exists():
        try:
            os.symlink(skill_path, skill_dest)
        except OSError:
            shutil.copytree(skill_path, skill_dest, dirs_exist_ok=True)

    for src, dest_rel in fixtures:
        dest = project_dest / dest_rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, dest)

    return project_dest


def run_eval_local(
    eval_item: dict,
    run_dir: Path,
    skill_path: Path,
    project_root: Path,
    timeout: int,
    setup_dirs: list[Path] | None = None,
) -> dict:
    eval_id = str(eval_item.get("id", "unnamed"))
    eval_dir = run_dir / eval_id
    workspace_root = eval_dir / "workspace"
    artifacts_dir = eval_dir / "artifacts"
    transcript_path = eval_dir / "transcript.jsonl"

    eval_dir.mkdir(parents=True, exist_ok=True)
    artifacts_dir.mkdir(parents=True, exist_ok=True)

    fixtures = resolve_fixtures(eval_item.get("files", []), project_root)
    workspace_project = stage_workspace_local(
        workspace_root, project_root, skill_path, fixtures, setup_dirs
    )

    (eval_dir / "prompt.txt").write_text(eval_item["prompt"], encoding="utf-8")
    workspace_snapshot_before = snapshot_files(workspace_project)

    home_dir = workspace_root / ".home"
    stage_credentials(home_dir / ".claude", _KEYCHAIN_CREDS)
    env = {
        "HOME": str(home_dir),
        "CLAUDE_CONFIG_DIR": str(home_dir / ".claude"),
        "PATH": os.environ.get("PATH", ""),
        "ANTHROPIC_API_KEY": os.environ.get("ANTHROPIC_API_KEY", ""),
    }

    cmd = [
        "claude",
        "-p", eval_item["prompt"],
        "--output-format", "stream-json",
        "--verbose",
        "--dangerously-skip-permissions",
    ]

    start = time.time()
    try:
        with transcript_path.open("wb") as out:
            proc = subprocess.run(
                cmd,
                stdout=out,
                stderr=subprocess.PIPE,
                cwd=str(workspace_project),
                env=env,
                timeout=timeout,
            )
        elapsed = time.time() - start
        return_code = proc.returncode
        stderr_tail = (proc.stderr or b"").decode("utf-8", errors="replace")[-2000:]
    except subprocess.TimeoutExpired as e:
        elapsed = time.time() - start
        return_code = -1
        stderr_tail = f"TIMEOUT after {timeout}s"
        if e.stderr:
            stderr_tail += "\n" + e.stderr.decode("utf-8", errors="replace")[-2000:]

    new_files = diff_workspace(workspace_project, workspace_snapshot_before)
    sync_artifacts(workspace_project, new_files, artifacts_dir)

    metrics = compute_metrics(transcript_path, artifacts_dir, elapsed, return_code, stderr_tail)
    write_json(eval_dir / "metrics.json", metrics)

    return {
        "eval_id": eval_id,
        "elapsed_s": elapsed,
        "return_code": return_code,
        "transcript": str(transcript_path.relative_to(run_dir)),
        "artifacts_dir": str(artifacts_dir.relative_to(run_dir)),
        "metrics": metrics,
    }


def run_eval_docker(
    eval_item: dict,
    run_dir: Path,
    skill_path: Path,
    project_root: Path,
    timeout: int,
    setup_dirs: list[Path] | None = None,
) -> dict:
    eval_id = str(eval_item.get("id", "unnamed"))
    eval_dir = run_dir / eval_id
    artifacts_dir = eval_dir / "artifacts"
    transcript_path = eval_dir / "transcript.jsonl"

    eval_dir.mkdir(parents=True, exist_ok=True)
    artifacts_dir.mkdir(parents=True, exist_ok=True)
    fixtures_staging = eval_dir / "fixtures_in"
    fixtures_staging.mkdir(parents=True, exist_ok=True)

    fixtures = resolve_fixtures(eval_item.get("files", []), project_root)
    for src, dest_rel in fixtures:
        dest = fixtures_staging / dest_rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, dest)

    (eval_dir / "prompt.txt").write_text(eval_item["prompt"], encoding="utf-8")

    # Pre-merge setup overlay dirs on the host; mount as /setup:ro in the container.
    setup_merged: Path | None = None
    if setup_dirs:
        setup_merged = eval_dir / "setup_merged"
        apply_setup_overlay(setup_dirs, setup_merged)
        if not any(setup_merged.iterdir()):
            setup_merged = None

    creds_dir: Path | None = None
    if _KEYCHAIN_CREDS:
        creds_dir = eval_dir / "creds"
        creds_dir.mkdir(parents=True, exist_ok=True)
        (creds_dir / ".credentials.json").write_text(_KEYCHAIN_CREDS, encoding="utf-8")

    container_script = r"""
set -e
mkdir -p /workspace
rsync -a \
  --exclude=.git --exclude=.bare --exclude=node_modules --exclude=.venv \
  --exclude=__pycache__ --exclude=.pytest_cache --exclude=.next \
  --exclude=dist --exclude=build --exclude=.cache --exclude=.DS_Store \
  /project/ /workspace/
if [ -d /setup ]; then
  rsync -a /setup/ /workspace/
fi
mkdir -p /workspace/.claude/skills
cp -R "$SKILL_SRC" "/workspace/.claude/skills/$SKILL_NAME"
if [ -d /fixtures ]; then
  cp -R /fixtures/. /workspace/
fi
if [ -f /creds/.credentials.json ]; then
  mkdir -p /home/evaluator/.claude
  cp /creds/.credentials.json /home/evaluator/.claude/.credentials.json
fi
cd /workspace
claude -p "$EVAL_PROMPT" \
  --output-format stream-json --verbose \
  --dangerously-skip-permissions \
  > /output/transcript.jsonl 2> /output/stderr.log || true
mkdir -p /output/artifacts
rsync -a --exclude=.claude --exclude=node_modules --exclude=.git \
  --filter='+ */' --filter='+ *' \
  /workspace/ /output/artifacts/
"""

    skill_name = skill_path.name
    cmd = [
        "docker", "run", "--rm",
        "-v", f"{project_root}:/project:ro",
        "-v", f"{skill_path}:/skill_src:ro",
        "-v", f"{eval_dir}:/output",
        "-e", "ANTHROPIC_API_KEY",
        "-e", f"EVAL_PROMPT={eval_item['prompt']}",
        "-e", f"SKILL_SRC=/skill_src",
        "-e", f"SKILL_NAME={skill_name}",
    ]
    if creds_dir:
        cmd += ["-v", f"{creds_dir}:/creds:ro"]
    if fixtures:
        cmd += ["-v", f"{fixtures_staging}:/fixtures:ro"]
    if setup_merged:
        cmd += ["-v", f"{setup_merged}:/setup:ro"]
    cmd += [DOCKER_IMAGE, "bash", "-c", container_script]

    start = time.time()
    try:
        proc = subprocess.run(
            cmd,
            capture_output=True,
            timeout=timeout + 30,
        )
        elapsed = time.time() - start
        return_code = proc.returncode
        stderr_tail = proc.stderr.decode("utf-8", errors="replace")[-2000:]
        if proc.stdout:
            (eval_dir / "docker.stdout.log").write_bytes(proc.stdout)
    except subprocess.TimeoutExpired as e:
        elapsed = time.time() - start
        return_code = -1
        stderr_tail = f"TIMEOUT after {timeout}s"
        if e.stderr:
            stderr_tail += "\n" + e.stderr.decode("utf-8", errors="replace")[-2000:]

    metrics = compute_metrics(transcript_path, artifacts_dir, elapsed, return_code, stderr_tail)
    write_json(eval_dir / "metrics.json", metrics)
    shutil.rmtree(fixtures_staging, ignore_errors=True)

    return {
        "eval_id": eval_id,
        "elapsed_s": elapsed,
        "return_code": return_code,
        "transcript": str(transcript_path.relative_to(run_dir)),
        "artifacts_dir": str(artifacts_dir.relative_to(run_dir)),
        "metrics": metrics,
    }


def resolve_fixtures(files: list[str], project_root: Path) -> list[tuple[Path, str]]:
    out: list[tuple[Path, str]] = []
    for entry in files:
        candidate = (project_root / entry).resolve()
        if not candidate.is_file():
            alt = Path(entry).resolve()
            if alt.is_file():
                candidate = alt
            else:
                print(f"Warning: fixture not found: {entry}", file=sys.stderr)
                continue
        out.append((candidate, entry))
    return out


def snapshot_files(root: Path) -> set[str]:
    snap: set[str] = set()
    for p in root.rglob("*"):
        if p.is_file():
            snap.add(str(p.relative_to(root)))
    return snap


def diff_workspace(root: Path, before: set[str]) -> list[str]:
    after = snapshot_files(root)
    return sorted(after - before)


def sync_artifacts(workspace: Path, new_files: list[str], dest: Path) -> None:
    for rel in new_files:
        src = workspace / rel
        if not src.is_file():
            continue
        if any(part in (".claude", "node_modules", ".git", ".venv") for part in src.parts):
            continue
        target = dest / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, target)


def compute_metrics(transcript: Path, artifacts: Path, elapsed: float,
                    rc: int, stderr_tail: str) -> dict:
    tool_calls: dict[str, int] = {}
    total_steps = 0
    if transcript.is_file():
        for raw in transcript.read_text(encoding="utf-8", errors="replace").splitlines():
            raw = raw.strip()
            if not raw:
                continue
            try:
                evt = json.loads(raw)
            except json.JSONDecodeError:
                continue
            if evt.get("type") == "assistant":
                total_steps += 1
                for item in evt.get("message", {}).get("content", []):
                    if item.get("type") == "tool_use":
                        name = item.get("name", "?")
                        tool_calls[name] = tool_calls.get(name, 0) + 1

    output_chars = 0
    for f in artifacts.rglob("*"):
        if f.is_file():
            try:
                output_chars += f.stat().st_size
            except OSError:
                pass

    return {
        "elapsed_s": round(elapsed, 2),
        "return_code": rc,
        "tool_calls": tool_calls,
        "total_tool_calls": sum(tool_calls.values()),
        "total_steps": total_steps,
        "output_chars": output_chars,
        "transcript_chars": transcript.stat().st_size if transcript.is_file() else 0,
        "stderr_tail": stderr_tail,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Run a skill's artifact evals in isolation")
    parser.add_argument("--skill-path", required=True, type=Path)
    parser.add_argument("--evals-file", required=True, type=Path)
    parser.add_argument("--project-root", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--isolation", choices=("docker", "local"), required=True)
    parser.add_argument("--workers", type=int, default=8)
    parser.add_argument("--timeout", type=int, default=600)
    parser.add_argument("--eval-ids", default=None, help="Comma-separated subset of eval ids to run")
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    skill_path = args.skill_path.resolve()
    project_root = args.project_root.resolve()
    evals_file = args.evals_file.resolve()
    if not evals_file.is_file():
        print(f"evals file not found: {evals_file}", file=sys.stderr)
        return 2

    skill_name, _, _ = parse_skill_md(skill_path)
    data = read_json(evals_file)
    evals = data["evals"] if isinstance(data, dict) and "evals" in data else data

    if args.eval_ids:
        wanted = {x.strip() for x in args.eval_ids.split(",") if x.strip()}
        evals = [e for e in evals if str(e.get("id")) in wanted]

    run_id = new_run_id(skill_name)
    run_dir = (args.output_dir / run_id).resolve()
    run_dir.mkdir(parents=True, exist_ok=True)

    write_json(run_dir / "run.json", {
        "run_id": run_id,
        "skill_name": skill_name,
        "skill_path": str(skill_path),
        "project_root": str(project_root),
        "evals_file": str(evals_file),
        "isolation": args.isolation,
        "started_at": utc_now_iso(),
        "eval_count": len(evals),
    })

    runner = run_eval_docker if args.isolation == "docker" else run_eval_local

    results: list[dict] = []
    if not args.quiet:
        print(
            f"[run_evals] {len(evals)} evals, isolation={args.isolation}, run_dir={run_dir}",
            file=sys.stderr,
        )

    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        future_to_eval = {
            pool.submit(
                runner,
                item,
                run_dir,
                skill_path,
                project_root,
                int(item.get("timeout", args.timeout)),
                discover_setup_dirs(evals_file, str(item.get("id", ""))),
            ): item
            for item in evals
        }
        for fut in as_completed(future_to_eval):
            item = future_to_eval[fut]
            try:
                res = fut.result()
            except Exception as e:
                res = {"eval_id": str(item.get("id")), "error": str(e), "return_code": -1}
            results.append(res)
            if not args.quiet:
                rc = res.get("return_code")
                status = "ok" if rc == 0 else f"rc={rc}"
                print(
                    f"  [{status}] eval {res.get('eval_id')} ({res.get('elapsed_s', 0):.1f}s)",
                    file=sys.stderr,
                )

    summary = {
        "run_id": run_id,
        "completed_at": utc_now_iso(),
        "total": len(evals),
        "executed": len(results),
        "exec_failures": sum(1 for r in results if r.get("return_code") != 0),
        "run_dir": str(run_dir),
        "results": results,
    }
    write_json(run_dir / "execution-summary.json", summary)
    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
