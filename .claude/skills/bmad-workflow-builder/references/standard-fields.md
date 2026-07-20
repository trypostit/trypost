# Standard Workflow/Skill Fields

## Frontmatter Fields

Only these fields go in the YAML frontmatter block:

| Field         | Description                                          | Example                                       |
| ------------- | ---------------------------------------------------- | --------------------------------------------- |
| `name`        | Full skill name (kebab-case, same as folder name)    | `validate-json`, `cis-brainstorm` |
| `description` | [5-8 word summary]. [Use when user says 'X' or 'Y'.] | See Description Format below                  |

## Content Fields (All Types)

These are used within the SKILL.md body — never in frontmatter:

| Field           | Description                   | Example                           |
| --------------- | ----------------------------- | --------------------------------- |
| `role-guidance` | Brief expertise primer        | "Act as a senior DevOps engineer" |
| `module-code`   | Module code (if module-based) | `bmb`, `cis`                      |

## Simple Utility Fields

| Field           | Description                         | Example                                     |
| --------------- | ----------------------------------- | ------------------------------------------- |
| `input-format`  | What it accepts                     | JSON file path, stdin text                  |
| `output-format` | What it returns                     | Validated JSON, error report                |
| `standalone`    | Fully standalone, no config needed? | true/false                                  |
| `composability` | How other skills use it             | "Called by quality scanners for validation" |

## Simple Workflow Fields

| Field        | Description           | Example                                   |
| ------------ | --------------------- | ----------------------------------------- |
| `steps`      | Numbered inline steps | "1. Load config 2. Read input 3. Process" |
| `tools-used` | CLIs/tools/scripts    | gh, jq, python scripts                    |
| `output`     | What it produces      | PR, report, file                          |

## Complex Workflow Fields

| Field                    | Description                       | Example                               |
| ------------------------ | --------------------------------- | ------------------------------------- |
| `stages`                 | Named numbered stages             | "01-discover, 02-plan, 03-build"      |
| `progression-conditions` | When stages complete              | "User approves outline"               |
| `headless-mode`          | Supports autonomous?              | true/false                            |
| `config-variables`       | Beyond core vars                  | `planning_artifacts`, `output_folder` |
| `output-artifacts`       | What it creates (output-location) | "PRD document", "agent skill"         |

## Customization Surface (`customize.toml`, opt-in)

Emitted only when the skill author opts in during Phase 3.5 (Configurability Discovery). The file sits next to SKILL.md and is loaded via `{project-root}/_bmad/scripts/resolve_customization.py` at activation.

### Always-present fields (when opted in)

| Field                      | Type          | Purpose                                                                    |
| -------------------------- | ------------- | -------------------------------------------------------------------------- |
| `activation_steps_prepend` | array[string] | Steps run before standard activation. Overrides append.                    |
| `activation_steps_append`  | array[string] | Steps run after greet, before the workflow's first stage. Overrides append. |
| `persistent_facts`         | array[string] | Facts (literal or `file:` prefixed paths/globs) loaded on activation. Overrides append. |

### Workflow-specific scalars (lifted during Phase 3.5)

Named by purpose and suffix. Override wins (scalar merge rule).

| Naming pattern      | Use for                                              | Example                                             |
| ------------------- | ---------------------------------------------------- | --------------------------------------------------- |
| `<purpose>_template` | File path for templates the workflow loads          | `brief_template = "assets/brief-template.md"`    |
| `<purpose>_output_path` | Writable destination paths                       | `output_path = "{project-root}/docs/briefs"`        |
| `on_<event>`        | Prompt or command executed at a hook point           | `on_complete = ""`                                  |

**Path resolution within scalar values:**

- Bare paths (e.g. `assets/brief-template.md`) resolve from the skill root.
- `{project-root}/...` resolves from the project working directory — use for org-owned overrides.
- Never mix `{project-root}` with config variables that already contain it (no double-prefix).

### How SKILL.md references the resolved values

After the resolver step runs, read customized values as `{workflow.<name>}`:

```markdown
Load the brief template from `{workflow.brief_template}`.
```

At runtime, that resolves to whatever the merged `[workflow].brief_template` scalar is — the default, a team override, or a personal override.

### Override files

Teams and users override without editing `customize.toml` in the skill, and instead modify the following:

- Team: `{project-root}/_bmad/custom/{skill-name}.toml`
- Personal: `{project-root}/_bmad/custom/{skill-name}.user.toml`

Both use the same `[workflow]` block shape. Merge order: base (skill's `customize.toml`) → team → user.

## Overview Section Format

The Overview is the first section after the title — it primes the AI for everything that follows.

**3-part formula:**

1. **What** — What this workflow/skill does
2. **How** — How it works (approach, key stages)
3. **Why/Outcome** — Value delivered, quality standard

**Templates by skill type:**

**Complex Workflow:**

```markdown
This skill helps you {outcome} through {approach}. Act as {role-guidance}, guiding users through {key stages}. Your output is {deliverable}.
```

**Simple Workflow:**

```markdown
This skill {what it does} by {approach}. Act as {role-guidance}. Use when {trigger conditions}. Produces {output}.
```

**Simple Utility:**

```markdown
This skill {what it does}. Use when {when to use}. Returns {output format} with {key feature}.
```

## SKILL.md Description Format

The frontmatter `description` is the PRIMARY trigger mechanism — it determines when the AI invokes this skill. Most BMad skills are **explicitly invoked** by name (`/skill-name` or direct request), so descriptions should be conservative to prevent accidental triggering.

**Format:** Two parts, one sentence each:

```
[What it does in 5-8 words]. [Use when user says 'specific phrase' or 'specific phrase'.]
```

**The trigger clause** uses one of these patterns depending on the skill's activation style:

- **Explicit invocation (default):** `Use when the user requests to 'create a PRD' or 'edit an existing PRD'.` — Quotes around specific phrases the user would actually say. Conservative — won't fire on casual mentions.
- **Organic/reactive:** `Trigger when code imports anthropic SDK, or user asks to use Claude API.` — For lightweight skills that should activate on contextual signals, not explicit requests.

**Examples:**

Good (explicit): `Builds workflows and skills through conversational discovery. Use when the user requests to 'build a workflow', 'modify a workflow', or 'quality check workflow'.`

Good (organic): `Initializes BMad project configuration. Trigger when any skill needs module-specific configuration values, or when setting up a new BMad project.`

Bad: `Helps with PRDs and product requirements.` — Too vague, would trigger on any mention of PRD even in passing conversation.

Bad: `Use on any mention of workflows, building, or creating things.` — Over-broad, would hijack unrelated conversations.

**Default to explicit invocation** unless the user specifically describes organic/reactive activation during discovery.

## Role Guidance Format

Every generated workflow SKILL.md includes a brief role statement in the Overview or as a standalone line:

```markdown
Act as {role-guidance}. {brief expertise/approach description}.
```

This provides quick prompt priming for expertise and tone. Workflows may also use full Identity/Communication Style/Principles sections when personality serves the workflow's purpose.

## Path Rules

### Skill-Internal References

Use bare paths from the skill root for any file inside this skill — including same-folder references between two files in `references/` or two files in `scripts/`:

- `references/build-process.md`
- `references/standard-fields.md` (referenced from another file in `references/` — still bare path)
- `scripts/validate.py`
- `assets/template.md`

The convention is universal: bare paths from skill root. Never use `./` prefixes — they cause inconsistency and break under context compaction when the working directory shifts.

### Project-Scope Paths

Use `{project-root}/...` for any path relative to the project root:

- `{project-root}/_bmad/planning/prd.md`
- `{project-root}/docs/report.md`

### Config Variables

Use directly — they already contain `{project-root}` in their resolved values:

- `{output_folder}/file.md`
- `{planning_artifacts}/prd.md`

### Anti-patterns (negative examples — fenced so the linter doesn't fire on them)

```text
{project-root}/{output_folder}/file.md   # WRONG — double-prefix; config var already has {project-root}
_bmad/planning/prd.md                    # WRONG — bare _bmad must have {project-root} prefix
./references/foo.md                      # WRONG — never use ./ for skill-internal paths
./scripts/foo.py                         # WRONG — same; bare paths from skill root only
```
