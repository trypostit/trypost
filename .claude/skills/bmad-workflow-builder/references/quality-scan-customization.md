# Quality Scan: Customization Surface

You are a customization-surface economist. Two paired questions other scanners don't ask: **what should be customizable but isn't, and what's exposed as customizable that shouldn't be?**

**Load `references/skill-quality-principles.md` first.** Its "Customization (customize.toml)" section is the schema, naming conventions, and merge rules. The customization surface is a contract with every future user — too thin forces forks, too loud creates a permutation forest no one can reason about.

This is purely advisory. Nothing here is broken; everything is either an opportunity to expose or a risk to trim.

## Scan Targets

- `customize.toml` — if present, the canonical schema for this workflow
- `SKILL.md` — `{workflow.X}` references (signals customize.toml is wired); hardcoded paths (lift candidates); resolver activation step
- `assets/` — templates the workflow loads (candidates for `*_template`)
- `references/*.md` — stage prompts that may reference configurable values

If no `customize.toml`, scan opportunity-side only: would this skill benefit from opting in?

## What to Find

**Opportunities — things to lift:**
- Hardcoded template paths in SKILL.md or stages → `<purpose>_template` scalars (each separate, don't bundle)
- Hardcoded output destinations → `<purpose>_output_path` (weaker than templates; flag low unless org-dependent)
- Workflow produces an artifact and stops → consider `on_complete` hook
- Missing or empty `persistent_facts` — the BMad default glob (`["file:{project-root}/**/project-context.md"]`) is high-value, low-risk; almost every customizable workflow ships it
- Sentence-shaped variance baked into prompts (tone, style, compliance rules) — not scalar candidates, but signals the `persistent_facts` surface is valuable; suggest documenting it
- Workflow has 2+ hardcoded templates and no `customize.toml` at all → high-opportunity to opt in

**Abuse — things to trim:**
- Boolean toggles (3+ in one file = the surface is doing the job of a variant skill; suggest two skills or fewer knobs)
- Identity / communication-style / principles in `[workflow]` (those are agent-shape fields — point the author at agent-builder; remove from workflow surface)
- 4+ `on_<event>` hooks (workflow internals leaking into the override surface; users can interleave hooks at so many points they break the workflow's contract)
- Arrays of tables without `code` or `id` keys (resolver can't merge by key; falls back to append-only — users can't replace items)
- Mixed keying (`code` on some, `id` on others) — pick one
- Opaque scalar names (`style_config`, `mode`-as-path) — use the principles file's `*_template` / `*_output_path` / `on_<event>` patterns
- `customize.toml` declares a scalar but SKILL.md hardcodes the same value (high-abuse — overrides silently no-op; SKILL.md must read `{workflow.<name>}`)
- Scalars with no comment explaining when/why to override

## Output

Write to `{quality-report-dir}/customization-analysis.md`. Include:

- **Customization posture** — opted in? Surface size and shape?
- **Opportunity findings** — severity (high/medium/low-opportunity), location, proposed scalar (name, default, type)
- **Abuse findings** — severity (high/medium/low-abuse), offending field, fix (rename, remove, document, rewire)
- **Overall assessment** — too thin, too loud, or about right?
- **Top 2-3 insights** distilled

Return only the filename when complete.
