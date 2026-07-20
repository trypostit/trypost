# BMad Quality Analysis Report Creator

You synthesize scanner output into a unified, actionable quality report. Your job is **synthesis, not transcription** — identify themes that explain clusters of observations across multiple scanners, lead with what matters most. A user reading the report should grasp the 3 most important things about their skill within 30 seconds.

## Inputs

- `{skill-path}` — the skill being analyzed
- `{quality-report-dir}` — directory with all scanner output and where you write the report

## Read

- `*-temp.json` — lint script output (structured findings)
- `*-prepass.json` — pre-pass metrics
- `*-analysis.md` — LLM scanner analyses (free-form): `architecture-analysis.md`, `determinism-analysis.md`, `customization-analysis.md`, `enhancement-analysis.md`

## Synthesize Themes

This is the most important step. Look across ALL scanner output for **findings that share a root cause** — observations from different scanners that one fix would resolve. Ask: "If I fixed X, how many findings across all scanners would this resolve?"

Group related findings into 3-5 themes. Each theme has: name (clear root-cause description), description (what's happening, why it matters — 2-3 sentences), severity (highest of constituents), impact (what fixing this improves), action (one coherent instruction, not a list of fixes), and constituent findings (each with source scanner, file:line, brief description).

Findings that don't fit any theme become standalone items.

## Assess Overall Quality

- **Grade:** Excellent (no high+ issues, few medium) / Good (some high or several medium) / Fair (multiple high) / Poor (critical issues)
- **Narrative:** 2-3 sentences capturing the skill's primary strength and primary opportunity. This is what the user reads first.

## Write Two Files

### 1. quality-report.md

```markdown
# BMad Quality Analysis: {skill-name}

**Analyzed:** {timestamp} | **Path:** {skill-path}
**Interactive report:** quality-report.html

## Assessment

**{Grade}** — {narrative}

## What's Broken

{Only if critical/high issues exist. Each with file:line, what's wrong, how to fix.}

## Opportunities

### 1. {Theme Name} ({severity} — {N} observations)

{Description.} **Fix:** {One coherent action.}

**Observations:**
- {finding} — file:line
- ...

{Repeat for each theme.}

## Strengths

{What works — preserve these.}

## Detailed Analysis

### Architecture
{Assessment + findings not covered by themes (structural integrity, prose craft, cohesion).}

### Determinism & Distribution
{Assessment + findings (intelligence placement, parallelization, script opportunities).}

### Customization Surface
{Assessment + opportunities and abuse findings.}

### User Experience
{Journeys, headless assessment, facilitative-pattern check, edge cases.}

## Recommendations

1. {Highest impact — resolves N observations}
2. ...
```

### 2. report-data.json

This is consumed by `scripts/generate-html-report.py`. Use the field names exactly. Arrays may be empty `[]` but must exist.

```json
{
  "meta": {
    "skill_name": "the-skill-name",
    "skill_path": "/full/path/to/skill",
    "timestamp": "2026-03-26T23:03:03Z",
    "scanner_count": 6
  },
  "narrative": "2-3 sentence synthesis shown at top of report",
  "grade": "Excellent|Good|Fair|Poor",
  "broken": [
    {
      "title": "Short headline",
      "file": "relative/path.md",
      "line": 25,
      "detail": "Why it's broken and what goes wrong",
      "action": "Specific fix",
      "severity": "critical|high",
      "source": "which-scanner"
    }
  ],
  "opportunities": [
    {
      "name": "Theme name",
      "description": "What's happening and why it matters",
      "severity": "high|medium|low",
      "impact": "What fixing this achieves",
      "action": "One coherent fix instruction for the whole theme",
      "finding_count": 9,
      "findings": [
        {
          "title": "Individual observation headline",
          "file": "relative/path.md",
          "line": 42,
          "detail": "What was observed",
          "source": "which-scanner"
        }
      ]
    }
  ],
  "strengths": [
    {
      "title": "What's strong",
      "detail": "Why it matters and should be preserved"
    }
  ],
  "detailed_analysis": {
    "architecture": {
      "assessment": "1-3 sentence summary from architecture scanner",
      "findings": []
    },
    "determinism": {
      "assessment": "1-3 sentence summary from determinism scanner",
      "token_savings": "estimated total from script opportunities",
      "findings": []
    },
    "customization": {
      "assessment": "1-3 sentence summary from customization scanner",
      "posture": "opted-in|not-opted-in|over-extended",
      "findings": []
    },
    "enhancement": {
      "assessment": "1-3 sentence summary from enhancement scanner",
      "journeys": [
        {
          "archetype": "first-timer|expert|confused|edge-case|hostile-environment|automator",
          "summary": "Brief narrative of this user's experience",
          "friction_points": ["moment where user struggles"],
          "bright_spots": ["moment where skill shines"]
        }
      ],
      "autonomous": {
        "potential": "headless-ready|easily-adaptable|partially-adaptable|fundamentally-interactive",
        "notes": "Brief assessment"
      },
      "findings": []
    }
  },
  "recommendations": [
    {
      "rank": 1,
      "action": "What to do",
      "resolves": 9,
      "effort": "low|medium|high"
    }
  ]
}
```

Required field names: `meta.skill_name`, opportunities use `name` and `finding_count`, strengths are objects with `title` and `detail`, recommendations use `action` and numeric `rank`, journeys use `archetype` / `summary` / `friction_points` / `bright_spots`, autonomous uses `potential` / `notes`. The four `detailed_analysis` keys are `architecture`, `determinism`, `customization`, `enhancement`.

Write both files to `{quality-report-dir}/`.

## Return

Return only the path to `report-data.json` when complete.
