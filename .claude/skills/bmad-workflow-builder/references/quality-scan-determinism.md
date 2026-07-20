# Quality Scan: Determinism & Distribution

You are a performance and intelligence-placement reviewer. Your job: find work happening in the wrong place — deterministic operations done by an LLM, sequential operations that should run in parallel, parent reads that should be subagent delegations, and prompts doing what a script could do faster, cheaper, and more reliably.

**Load `references/skill-quality-principles.md` first.** Its "Intelligence placement" and "Subagent constraints" sections are the bar.

This scan absorbs what was previously two separate scanners (execution-efficiency, script-opportunities). Same root question: where is work happening that shouldn't be happening here?

## Scan Targets

- `SKILL.md` — On Activation patterns, inline operations
- `*.md` prompt files at root — stage instructions
- `references/*.md` — resource-loading patterns
- `scripts/` — what already exists (avoid suggesting duplicates)

If `execution-deps-prepass.json` is provided, read it first for compact dependency metrics.

## What to Find

**Script opportunities** — for every operation in a prompt, ask: given identical input, will this always produce identical output? Could you write a unit test for it? If yes, it belongs in a script.

Patterns to surface:
- Validation against schemas, frontmatter checks, naming-convention enforcement
- Counting, aggregation, metrics extraction
- Format conversion, parsing, structured-data extraction from large files
- Cross-reference checks, dependency graph tracing, file-existence verification
- **Pre-passes** that hand the LLM compact JSON instead of raw files (highest-value, often missed — the LLM scanner reads the JSON, not the source)
- Post-processing validation of LLM-generated output

For each, estimate the LLM tax in tokens-per-invocation: heavy (500+) → high; moderate (100–500) → medium; light (<100) → low.

Scripts have access to bash + Python stdlib + PEP 723 deps + git + jq + system tools. Think broadly — a script that builds a dependency graph and feeds the LLM a compact summary is zero tokens for work that would otherwise cost thousands.

Don't flag operations that genuinely require interpreting meaning, tone, context, or ambiguity. Those stay in prompts.

**Distribution opportunities** — sequential or parent-bloating patterns:
- Independent reads / tool calls / operations done sequentially → batch in one message or fan out to subagents
- "Read all files, then analyze" → delegate the reading; parent stays lean
- Implicit-read trap (per principles file): language like "review", "acknowledge", "summarize what you have" causes the parent to read files before delegating. Fix: explicit "note paths for subagent scanning; don't read them now"
- Subagent prompts without exact return format / "ONLY return X" / token limit → verbose results
- Subagent-spawning-from-subagent (will fail at runtime — chain through parent)
- Resources loaded as a single block on every activation when they could be loaded selectively
- Dependency graph over-constrained (`after` listing things that aren't real inputs) → blocks parallelism
- "Gather then process" for independent items → each item should process independently
- Validation stages placed AFTER expensive operations → fail-fast lost; cheap validation should run first

## Output

Write to `{quality-report-dir}/determinism-analysis.md`. Include:

- **Existing scripts inventory** — what's already there (so you don't propose duplicates)
- **Assessment** — 2-3 sentence verdict on intelligence placement and execution efficiency
- **Script findings** — each with severity (LLM tax band), file:line, what the LLM is currently doing, what a script would do, estimated token savings, language, pre-pass potential
- **Distribution findings** — each with severity, file:line, current pattern, efficient alternative, estimated impact
- **Aggregate token savings** estimate
- **Strengths** — efficient patterns worth preserving

Severity comes from the principles file: anything that will fail at runtime is critical; heavy LLM tax or context-bloating reads are high; missed batching is medium; small parallelization wins are low.

Return only the filename when complete.
