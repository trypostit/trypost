# Quality Analysis

Communicate with user in `{communication_language}`. Write report content in `{document_output_language}`.

You orchestrate quality analysis on a BMad workflow or skill. The pipeline is optimized for speed and completeness:

1. **Deterministic checks** (scripts) — zero tokens, instant
2. **LLM scanners** (parallel subagents) — judgment-based analysis against `skill-quality-principles.md`
3. **Fast JSON extraction** (deterministic script) — lossless capture of all scanner findings (~10 seconds, no LLM)
4. **HTML generation** — interactive, auto-opening report from JSON (no wait for synthesis)
5. **Optional markdown synthesis** (LLM subagent, background) — thematic analysis and archival markdown

The scanners verify against `references/skill-quality-principles.md` — the same file the build process loads at create/edit time. Findings cite the principle that's being violated rather than restating it.

## Your Role: Coordination, Not File Reading

**Do not read the target skill's files yourself.** Scripts and subagents do all analysis. You orchestrate: run deterministic scripts and pre-pass extractors, spawn LLM scanner subagents in parallel, hand off to the report creator for synthesis.

## Headless Mode

If `{headless_mode}=true`, skip user interaction, use safe defaults, note any warnings, and output structured JSON as specified in the Present Findings section.

## Pre-Scan Checks

Check for uncommitted changes. In headless mode, note warnings and proceed. In interactive mode, inform the user, confirm before proceeding, and confirm the workflow is currently functioning.

## Analysis Principles

**Effectiveness over efficiency.** The analysis may suggest leaner phrasing, but if the current phrasing captures the right guidance, it should be kept. The report presents opportunities — the user applies judgment.

## Scanners

### Lint Scripts (Deterministic — Run First)

Run instantly, cost zero tokens, produce structured JSON:

| #  | Script                           | Focus                                   | Output File                |
| -- | -------------------------------- | --------------------------------------- | -------------------------- |
| S1 | `scripts/scan-path-standards.py` | Path conventions                        | `path-standards-temp.json` |
| S2 | `scripts/scan-scripts.py`        | Script portability, PEP 723, unit tests | `scripts-temp.json`        |

### Pre-Pass Scripts (Feed LLM Scanners)

Extract metrics so LLM scanners work from compact data instead of raw files:

| #  | Script                                  | Feeds                  | Output File                       |
| -- | --------------------------------------- | ---------------------- | --------------------------------- |
| P1 | `scripts/prepass-workflow-integrity.py` | architecture scanner   | `workflow-integrity-prepass.json` |
| P2 | `scripts/prepass-prompt-metrics.py`     | architecture scanner   | `prompt-metrics-prepass.json`     |
| P3 | `scripts/prepass-execution-deps.py`     | determinism scanner    | `execution-deps-prepass.json`     |

### LLM Scanners (Judgment-Based — Run After Scripts)

Each scanner loads `references/skill-quality-principles.md` and writes a free-form analysis document:

| #  | Scanner                              | Focus                                                                          | Pre-Pass | Output File                  |
| -- | ------------------------------------ | ------------------------------------------------------------------------------ | -------- | ---------------------------- |
| L1 | `quality-scan-architecture.md`       | Structural integrity, prose craft, cohesion (was: integrity + craft + cohesion)| Yes (P1, P2) | `architecture-analysis.md`   |
| L2 | `quality-scan-determinism.md`        | Intelligence placement, parallelization, subagent delegation, script opportunities (was: execution-efficiency + script-opportunities) | Yes (P3) | `determinism-analysis.md`    |
| L3 | `quality-scan-customization.md`      | customize.toml opportunities and abuse                                         | No       | `customization-analysis.md`  |
| L4 | `quality-scan-enhancement.md`        | Edge cases, UX gaps, headless potential, facilitative patterns                 | No       | `enhancement-analysis.md`    |

## Execution

Bind `{quality-report-dir} = {skill-path}/.analysis/{date-time-stamp}/` and create the directory. Use this single name in every script invocation and subagent prompt below. Quality analyses live at the skill's own root, as a peer of `.decision-log.md` and `SKILL.md` — the audit trail travels with the skill.

### Step 1: Run All Scripts (Parallel)

```bash
python3 scripts/scan-path-standards.py {skill-path} -o {quality-report-dir}/path-standards-temp.json
python3 scripts/scan-scripts.py {skill-path} -o {quality-report-dir}/scripts-temp.json
uv run scripts/prepass-workflow-integrity.py {skill-path} -o {quality-report-dir}/workflow-integrity-prepass.json
python3 scripts/prepass-prompt-metrics.py {skill-path} -o {quality-report-dir}/prompt-metrics-prepass.json
uv run scripts/prepass-execution-deps.py {skill-path} -o {quality-report-dir}/execution-deps-prepass.json
```

### Step 2: Spawn LLM Scanners (Parallel)

After scripts complete, spawn all four LLM scanners as parallel subagents.

Each subagent receives:
- Scanner file to load
- Skill path: `{skill-path}`
- Output directory: `{quality-report-dir}`
- Pre-pass file paths (L1: P1+P2; L2: P3)

The subagent loads its scanner file (which loads the principles file), analyzes the skill, writes its analysis to `{quality-report-dir}`, and returns the filename.

### Step 3: Synthesize Report (Parallel with Scanner 4)

Spawn report creator to synthesize scanner outputs into `report-data.json` and `quality-report.md`. This can run in parallel with the last scanner finishing.

```bash
# Spawn as background task — does not block step 4
Agent(description="Synthesize quality report", subagent_type="report-creator", run_in_background=true, prompt="...")
```

The report creator:
- Reads all 4 analysis files + prepass JSON
- Identifies thematic clusters (root-cause synthesis)
- Writes `report-data.json` with: broken, opportunities, strengths, recommendations, detailed_analysis
- Writes `quality-report.md` for archival

### Step 4: Generate & Open HTML Report (Do Not Block on Markdown)

As soon as `report-data.json` exists (the report creator writes it mid-synthesis), generate the interactive HTML report:

```bash
python3 scripts/generate-html-report.py {quality-report-dir} --open
```

**Important:** Do not wait for `quality-report.md` to be written. The JSON is the complete data source. Open HTML immediately. The markdown report finishes asynchronously and provides archival context.

### Step 5: Log the Run

After HTML opens, append a session heading to `{skill-path}/.decision-log.md`:

```markdown
## YYYY-MM-DD — Quality analysis

Grade: <grade from report-data.json>. Interactive HTML: `.analysis/<timestamp>/quality-report.html`. Full markdown: `.analysis/<timestamp>/quality-report.md`.
```

## Present to User

**Headless** (`{headless_mode}=true`): emit JSON only.

```json
{
  "status": "complete",
  "intent": "analyze",
  "skill": "{skill-path}",
  "decision_log": "{skill-path}/.decision-log.md",
  "report": "{quality-report-dir}/quality-report.md"
}
```

Blocked (scanner failure, missing required input, etc.): replace `"complete"` with `"blocked"` and add `"reason": "<one-line cause>"`. The log + any partial report carry the detail.

**Interactive:** read `report-data.json` and present grade + 2-3 sentence narrative, broken items if any, top opportunities by theme, paths to the full report and HTML. Offer to apply fixes, walk findings, or discuss.
