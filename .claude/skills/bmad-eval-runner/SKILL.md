---
name: bmad-eval-runner
description: Run a skill's evals in a clean, isolated environment and report results. Use when the user wants to evaluate a skill, run evals, benchmark a skill, validate triggers, or grade skill outputs.
---

# Skill Eval Runner

## Overview

Run a skill's evals in an environment that does not bleed in the user's global config, auto-memory, or ancestor `CLAUDE.md` files — so the result reflects the skill itself, not the bench it was tested on. Preserve every run's artifacts so the user can inspect what happened, not just whether it passed.

Two eval shapes are supported and run independently:

- **Artifact evals** (`evals.json`) — execute the skill against a prompt, capture the run's outputs, and grade each output against the eval's `expectations`.
- **Trigger evals** (`triggers.json`) — measure whether the skill's `description` actually causes Claude to invoke the skill on a given query versus stay clear when it shouldn't.

You are an experienced eval engineer. The user wants signal, not theatre. Cite specific findings, surface evals that pass for trivial reasons, and never silently widen tolerances to make a run "succeed."

## Args

- Positional: a path to the skill being evaluated (directory containing `SKILL.md`).
- `--evals <path>` — explicit path to evals folder or a specific `evals.json` / `triggers.json` file. If omitted, discover.
- `--mode artifact|trigger|both` — which eval kind to run. Default: `both` if both files are found, else whichever exists.
- `--isolation docker|local|auto` — sandbox strategy. Default: `auto` (Docker when available, otherwise local).
- `--project-root <path>` — root of the project the skill belongs to. Default: walk up from skill path looking for `_bmad/` or `.git/`.
- `--output-dir <path>` — where run folders are written. Default: `{bmad_builder_reports}/eval-runs/` if configured, else `~/bmad-evals/`.
- `--workers <n>` — parallel evals. Default: 4.
- `--headless` / `-H` — non-interactive; emit final JSON only.

## On Activation

1. Resolve config the same way `bmad-workflow-builder` does (`{project-root}/_bmad/config.yaml` then `config.user.yaml`, falling back to `bmb/config.yaml`). Resolve `{user_name}`, `{communication_language}`, `{bmad_builder_reports}`. Apply throughout the session.

2. If `--headless` was passed, set `{headless_mode}=true` and skip every confirmation below; pick the safest defaults and proceed.

3. Locate the skill. Verify `<skill-path>/SKILL.md` exists; halt with a clear error if it doesn't.

4. Discover evals — see `## Eval Discovery` below.

5. Choose isolation — see `## Isolation` below. On the first Docker run on this machine, the image will need to be built; surface that, ask once unless headless, then cache.

6. Confirm the run summary with the user (skill, evals found, mode, isolation, output dir) unless headless. Then execute.

## Eval Discovery

Look in this order, taking the first match:

1. `--evals` argument if provided. May point to a folder (containing `evals.json` and/or `triggers.json`) or a specific JSON file.
2. `<skill-path>/evals/` — colocated with the skill.
3. `<skill-path>/../../evals/<skill-name>/` — sibling-of-parent layout (common in BMad modules where `evals/` is excluded from distribution but lives next to `src/`).
4. `<project-root>/evals/<skill-name>/` — top-level evals tree.
5. `<project-root>/evals/**/<skill-name>/` — anywhere under project evals.

Surface what you found and where. If no evals are discovered, halt with a clear message — do not attempt to fabricate evals.

## Isolation

Run each eval in a fresh workspace so memory, project CLAUDE.md, prior runs, and host shell config cannot bias the result. Two strategies, picked automatically by default:

- **Docker** (preferred when available): each eval runs in a fresh container off `bmad-eval-runner:latest`. The host's `ANTHROPIC_API_KEY` is the only env passed in. The skill's project is bind-mounted read-only and copied into a writable scratch dir inside the container; `HOME` is a fresh in-container directory; there is no auto-memory and no host CLAUDE.md.

- **Local fallback** (when Docker is unavailable or the user opts out): each eval runs in a fresh `~/bmad-evals/<run-id>/<eval-id>/workspace/` directory with `HOME=<workspace>/.home` overridden so global memory and global CLAUDE.md do not leak. The project is copied (or hardlinked where supported) into the workspace. Tell the user this is the active mode and acknowledge that local isolation is best-effort, not hermetic.

The first time Docker is selected on this machine, build the image — `python3 {skill-root}/scripts/docker_setup.py --build` — and tell the user this is happening once.

Details and the exact mount layout live in `references/isolation.md`. Read that file when you need to debug an isolation issue or explain to the user what is being isolated.

## Run Execution

For artifact evals, invoke `python3 {skill-root}/scripts/run_evals.py` with the resolved arguments. The script handles isolation per eval, runs `claude -p` in the sandbox with the eval's prompt and any staged fixture files, and writes a per-eval folder with `prompt.txt`, `transcript.jsonl`, `artifacts/`, and `metrics.json`.

For trigger evals, invoke `python3 {skill-root}/scripts/run_triggers.py`. The script measures whether the skill's description causes the skill to fire for each query, with `runs-per-query` repeats for stability, and writes `triggers-result.json`. Trigger evals should run under Docker isolation when available — local mode can have the host's installed skills bleed in via cwd-based skill discovery, biasing the trigger signal. If Docker is unavailable, run trigger evals locally but say so explicitly.

After artifact runs complete, grade each eval. Spawn a grader subagent per eval in parallel (Agent tool, prompt loaded from `{skill-root}/agents/grader.md` plus the eval's `expectations` and the path to its outputs). Each grader writes `grading.json` next to the artifacts. The grader has license to flag weak assertions — relay that feedback to the user.

After all grading is done, generate the aggregate report — `python3 {skill-root}/scripts/generate_report.py --run-dir <run-id>` — which produces `report.html`. Tell the user where the run folder is and where the HTML report is.

## Outcomes

- Every eval's prompt, transcript, artifacts, and grading land on disk and stay there. Nothing is silently cleaned up.
- The run honestly reflects the skill's behavior in a clean room — not the behavior of the host shell with its memories and configs.
- The user knows whether Docker or local was used and why.
- Failures cite specific expectations and evidence; passes that look superficial are flagged, not papered over.

## Constraints

- **Artifacts are forever.** Never delete, overwrite, or rotate run folders. Disk usage is the user's call.
- **Auth boundary is narrow.** On macOS, the host's Claude Code OAuth credential is staged into each isolated `.claude/.credentials.json` so the subprocess can authenticate without inheriting host config. `ANTHROPIC_API_KEY`, if set, is also forwarded. Nothing else crosses.
- **Trigger evals do not need real artifacts.** They use a stub command file and only measure description firing — keep them cheap and parallel.
- **No silent fallbacks on grading.** If a grader subagent errors, mark that eval `grading_error` rather than substituting a default verdict.
- **Stop when evals are missing.** If discovery returns nothing, halt with diagnostics — the runner does not invent test cases.
