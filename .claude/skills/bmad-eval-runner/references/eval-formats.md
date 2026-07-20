# Eval Formats

The runner accepts two file shapes, both compatible with Anthropic's skill-creator conventions.

## Artifact evals — `evals.json`

```json
{
  "skill_name": "bmad-product-brief",
  "evals": [
    {
      "id": 1,
      "prompt": "I want to create a brief for ...",
      "expected_output": "A run folder with brief.md and decision-log.md ...",
      "files": [
        "evals/.../files/some-fixture.md"
      ],
      "expectations": [
        "brief.md exists in the run folder",
        "decision-log.md exists",
        "brief.md word count is between 250 and 1500"
      ]
    }
  ]
}
```

Field semantics:

- **id**: stable identifier; used as the eval's directory name in the run folder.
- **prompt**: the literal user message Claude will receive. Sent verbatim to `claude -p`.
- **expected_output**: human-readable description, used for context only — the grader reads it but does not score against it directly.
- **files**: optional fixture paths. Resolved relative to the project root (or the evals folder). Each file is staged into the eval's workspace before execution. Path semantics:
  - A bare filename is staged at the workspace root.
  - A nested path (`some-brief/brief.md`) preserves the directory structure inside the workspace.
- **expectations**: list of pass/fail assertions evaluated by the grader subagent. Each is graded independently. The grader is instructed to flag weak assertions — assertions a wrong output would also trivially pass.

The grader writes `grading.json` next to each eval's artifacts; the runner aggregates.

## Trigger evals — `triggers.json`

```json
[
  { "query": "Help me write a product brief for ...", "should_trigger": true },
  { "query": "Help me brainstorm ideas for ...",      "should_trigger": false }
]
```

The runner creates a synthetic command file in the sandbox's `.claude/commands/<skill-name>.md` containing the skill's description, then runs each query against `claude -p` with stream-JSON output and detects whether the skill (or a Read of its SKILL.md) appears as a tool call. Each query is run `--runs-per-query` times (default 3); `trigger_rate` is the fraction of runs that fired.

A query passes when:
- `should_trigger=true` and `trigger_rate >= --trigger-threshold` (default 0.5)
- `should_trigger=false` and `trigger_rate < --trigger-threshold`

Trigger evals do not produce artifacts beyond the result JSON. They are cheap and parallelize aggressively.

## Where evals can live

The runner discovers evals in this order:

1. `--evals <path>` — explicit. May point to a folder or a specific `*.json`.
2. `<skill-path>/evals/` — colocated with the skill.
3. `<skill-path>/../../evals/<skill-name>/` — sibling-of-parent. Common pattern when evals are intentionally excluded from skill distribution.
4. `<project-root>/evals/<skill-name>/`.
5. `<project-root>/evals/**/<skill-name>/` — fuzzy search under the project's evals tree.

If both `evals.json` and `triggers.json` are found, both run unless `--mode` narrows it.

## Two patterns for single-shot evals

Most multi-turn workflow skills can be evaluated single-shot if you design the eval right. Two patterns cover the bulk of what you'd otherwise need a multi-turn simulator for:

### Pattern A — artifact correctness (headless + rich prompt)

Force the skill into headless mode and pack the prompt with everything Discovery would have surfaced. Grade what comes out: the artifact, its structure, whether it reflects the inputs without inventing.

Use when:
- The deliverable is the artifact (brief, PRD, doc, plan)
- You can write a complete pre-Discovery prompt
- You want regression coverage on drafting/format/extraction

### Pattern B — process discipline (headless + transcript and side-artifact inspection)

Same single-shot mechanics, but the expectations look at *what the skill did internally* — not just the final output. The grader reads the stream-JSON transcript for tool calls, walks side-artifacts (decision logs, addenda, distillates), checks file mtimes, and verifies phase ordering.

Use when:
- The skill enforces a protocol (decision log, polish phase, finalize sequence)
- The skill has read-only intents (Validate must not write)
- You need to catch "drafting works but the discipline went soft" regressions

These are deterministic checks against the transcript and filesystem — no LLM judgment needed for most of them.

### What single-shot can NOT cover

Facilitation arc: vague-input → sharper pushback → user clarifies → better artifact. That requires a multi-turn user simulator. Defer it to a separate eval mode for skills where conversation is the value (coaching, brainstorming, design thinking).

## Writing good expectations

The grader's job is easier when expectations are *discriminating* — hard to pass without actually doing the work.

**Weak patterns to avoid:**
- **Filename-only checks** — "brief.md exists" passes for an empty file. Pair with a content check.
- **Wholly subjective phrasing** — "the brief is high quality" cannot be evaluated. State the property concretely.
- **Tautologies** — anything that follows from the prompt being understood is not a useful expectation.

**Strong patterns for artifact correctness (Pattern A):**
- Specific facts that should appear ("incorporates at least 2 specific findings from section X")
- Structural claims a wrong output would fail ("word count between 250 and 1500")
- Negative assertions ("does not introduce content from unrelated sections")
- YAML frontmatter checks ("frontmatter contains title, status, created, updated as ISO 8601")
- Bounded JSON output ("final assistant message contains a JSON object with intent='create'")

**Strong patterns for process discipline (Pattern B):**
- **Side-artifact existence + content** ("decision-log.md exists AND captures the pricing decision with rejected alternative and rationale")
- **Transcript tool-call patterns** ("the transcript contains a Skill tool call invoking bmad-editorial-review-prose")
- **Phase ordering** ("the polish-phase Skill calls occur after the brief body Write and before the final JSON status block")
- **Read-only enforcement** ("the input brief.md is byte-identical to the staged fixture; no Write or Edit tool calls targeted the run folder")
- **Bidirectional fidelity** ("every substantive entry in decision-log.md has a corresponding reflection in brief.md, AND no claim in brief.md is absent from the input prompt or decision-log.md")
- **Timestamp checks** ("YAML frontmatter 'updated' field is later than 'created'; 'created' is unchanged from the input fixture")

## Headless mode — getting the skill to behave non-interactively

Most multi-turn skills expose a headless flag or keyword that suppresses clarifying questions and produces a structured JSON status block at the end. To use Pattern A or B, the eval prompt needs to trigger this. Common signals:

- The literal phrase `Run headless.` at the start of the prompt
- Skill-specific flags or keywords as documented in the skill's `## Headless Mode` section
- Sufficient context such that no clarification is genuinely needed

If the skill has no headless mode, single-shot evals will halt at the first clarifying question and you have two options: (1) add a headless mode to the skill, (2) defer that skill's evals to the multi-turn simulator.

## Pre-staging files (Update / Validate intents)

For Update and Validate evals, the workspace needs to contain an existing brief, decision log, addendum, etc. Use the `files` field — each path is staged into the workspace at the same relative location. The eval prompt then references the staged path explicitly:

```json
{
  "id": "B5",
  "prompt": "Run headless. Update the brief at evals/skill-x/files/some-brief/brief.md — ...",
  "files": [
    "evals/skill-x/files/some-brief/brief.md",
    "evals/skill-x/files/some-brief/decision-log.md",
    "evals/skill-x/files/some-brief/addendum.md"
  ]
}
```

For Validate (read-only) expectations, pair the staged files with byte-identical assertions and a no-Write/no-Edit transcript check.
