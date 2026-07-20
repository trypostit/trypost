# Complex Workflow Patterns

Patterns for workflows whose SKILL.md got too big and had to carve out to `references/`. The default for any new skill is **inline** — a multi-stage coaching workflow lives in a single SKILL.md. Reach for these patterns only when SKILL.md genuinely won't fit.

## Carve-Out Conventions

When carving out to `references/`:

- Descriptive filenames (`press-release.md`, `customer-faq.md`, `verdict.md`). Never numbered prefixes — the carve-out is a section, not a "step." SKILL.md decides the order by routing.
- Each file works standalone (context compaction can drop SKILL.md). No "as described in the overview."
- SKILL.md keeps Overview, Activation, the Conventions block (see `references/skill-quality-principles.md`), and the routing logic. Everything else moves out.
- `assets/` is for templates and other static content the workflow loads, not for stages.

## Workflow Persona

BMad workflows treat the human operator as the expert. The agent facilitates — asks clarifying questions, presents options with trade-offs, validates before irreversible actions. The operator knows their domain; the workflow knows the process.

## Config Reading and Integration

Workflows read config from `{project-root}/_bmad/config.yaml` and `config.user.yaml`.

**Module-based skills** load with fallback and setup-skill awareness:

```
Load config from {project-root}/_bmad/config.yaml ({module-code} section) and config.user.yaml.
If missing: inform user that {module-setup-skill} is available, continue with sensible defaults.
```

**Standalone skills** load best-effort:

```
Load config from {project-root}/_bmad/config.yaml and config.user.yaml if available.
If missing: continue with defaults — no mention of a setup skill.
```

Config variables resolved already contain `{project-root}` — never double-prefix.

## Decision-Log Workspace Pattern (canonical compaction survival)

For workflows that produce revisable artifacts, the Decision-Log Workspace pattern is the default. See `references/skill-quality-principles.md` for the full treatment.

**The pattern in one paragraph.** The workspace folder (artifact + `.decision-log.md` + optional `addendum.md` + optional `distillate.md`) exists from the moment intent is confirmed. Decision-log captures every meaningful decision and rationale; addendum captures rejected alternatives. Resume on activation, conflict-detect on update, audit at finalize. The decision log is the load-bearing artifact — the document is what the user takes; the log is what carries identity across sessions.

**For Complex Workflows that route to carved-out files**, each carved file must work standalone (compaction can drop SKILL.md mid-flow). Carved files reference the workspace by config-resolved path (`{workflow.output_dir}/{workflow.output_folder_name}/`) — never assume in-context state.

**YAML frontmatter on the primary artifact** (status + inputs survives compaction):

```markdown
---
title: 'Analysis: Research Topic'
status: 'discovery'
inputs:
  - '{project-root}/docs/brief.md'
created: '2025-03-02T10:00:00Z'
updated: '2025-03-02T11:30:00Z'
---
```

**When NOT to apply:** purely conversational workflows, one-shot single-turn outputs, multi-artifact workflows where each artifact gets its own folder.

## Routing from SKILL.md

When SKILL.md routes to a carved-out file, the route is by descriptive name. Use a Stages table near the bottom of SKILL.md:

```markdown
## Stages

| # | Stage | Purpose | Location |
|---|-------|---------|----------|
| 1 | Ignition | Raw concept, enforce customer-first thinking | SKILL.md (above) |
| 2 | Press Release | Iterative drafting with hard coaching | `references/press-release.md` |
| 3 | Customer FAQ | Devil's advocate customer questions | `references/customer-faq.md` |
```

The `#` is a reading aid for the table, not a filename prefix.

## Module Metadata Reference

BMad module workflows require extended frontmatter metadata. See `references/metadata-reference.md` for the metadata template and field explanations.

## Architecture Checklist

Before finalizing a complex BMad workflow:

- [ ] Default reconsidered — would this fit inline as named sections in a single SKILL.md?
- [ ] Facilitator persona — treats the operator as expert?
- [ ] Config integration — language, output locations read and used?
- [ ] Conventions block stamped at top of SKILL.md (when multiple internal files are referenced)
- [ ] Carve-outs in `references/` use descriptive names, no numbered prefixes
- [ ] Each carved file works standalone (compaction survival)
- [ ] Decision-Log Workspace pattern applied (or explicit reason for skipping — Simple Utility, one-shot, purely conversational)
- [ ] Resume protocol — Activation checks for existing workspace and offers to resume
- [ ] Update mode reads `.decision-log.md` first; surfaces conflicts before applying changes
- [ ] Final polish — subagent polish step at the end?
- [ ] Finalize step includes decision-log audit (every entry → primary, addendum, or explicit process noise)
