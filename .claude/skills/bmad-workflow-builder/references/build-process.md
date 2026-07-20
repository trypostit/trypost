**Workspace.** Once intent is clear and the target skill is named (propose a kebab-case name for new skills if the user didn't give one — they can rename later, that's a logged decision not a redo), write `.decision-log.md` at the skill's root as a peer of `SKILL.md`. The decision log is canonical memory — load-bearing decisions, rejected alternatives, and overrides live on disk, not in the conversation. On resume, append a new session heading; at handoff, audit the log so the user signs off on how their thinking was handled.

## Phase 1: Classify

**Outcome:** you and the user agree on the skill type and whether it's part of a module. Reasoning is shared, not hidden.

| Type | When |
|---|---|
| **Simple Utility** | Composable building block with clear input → processing → output. Often deterministic. No multi-turn discovery. |
| **Simple Workflow** | Multi-step process that fits inline in SKILL.md as named sections (`## Discovery`, `## Constraints`, etc.). Default. |
| **Complex Workflow** | SKILL.md routing + carved-out sections in `references/` with descriptive filenames. Reserved for workflows whose SKILL.md would otherwise be too big to scan (~250+ lines). |

Default to Simple Workflow. Carving is a SIZE decision, not a stage-count decision.

If module-based: capture module code, other skills it'll invoke (with name / inputs / outputs), and config variables it needs.

For Workflows that produce an artifact: confirm whether `--headless` should be supported.

**On Edit:** classification is already set — read it from the existing skill or from `.decision-log.md` frontmatter. Skip this phase.

## Phase 2: Determine Spec

**Outcome:** you have everything needed to draft the skill — extracted from what the user has already shared (open-floor + decision log) plus targeted follow-ups for whatever's missing.

Through what's already known or further conversation, determine all of the following that are relevant:

| Field | Applies | Notes |
|---|---|---|
| Name | All | kebab-case. `{module-code}-{name}` for modules, `{name}` standalone. `bmad-` reserved for official. |
| Description | All | `[5-8 word summary]. [Use when user says 'specific phrase'.]` See `references/standard-fields.md`. |
| Overview | All | What / How / Why-Outcome. Domain framing + theory of mind for interactive or complex skills. |
| Role | Workflows | "Act as a [role/expert]" primer. |
| Design rationale | Where non-obvious | Choices the executing agent should understand so it doesn't optimize them away. |
| External skills | All | Which other skills this calls. |
| Scripts | All | Deterministic operations to push out of prompts; see `references/script-opportunities-reference.md`. List non-stdlib deps and get user approval (`uv` required). |
| Output documents | All | Yes/no — uses `{document_output_language}` if yes. |
| Revisable artifact | If output doc | If Update / Validate intents are likely, propose the Decision-Log Workspace pattern (`references/skill-quality-principles.md`). |
| Inputs / outputs | Simple Utility | Format, schema, required fields. |
| Stages | Workflows | Named sections (Simple) or carved files in `references/` with descriptive filenames (Complex). |
| Module capability | If module-based | phase-name, after, before, is-required, short description. |
| Customization | All | Fixed, or swappable templates / paths / hooks? Default no. If yes, walk each scalar (`<purpose>_template`, `<purpose>_output_path`, `on_<event>`); auto-promote in headless. |

The customization opt-in question (interactive only):

> "Should this support end-user customization (activation hooks, swappable templates, output paths)? If no, it ships fixed — users who need changes fork it."

For path conventions and customize.toml schema, see `references/skill-quality-principles.md`.

**On Edit:** spec is already defined by the existing skill. Read what's relevant to the change, ignore the rest. Update the decision-log with what's actually changing and why.

## Phase 3: Draft & Refine

**Load `references/skill-quality-principles.md` before reviewing the plan** — same principles file the quality scanners verify against. Building against it upfront is cheaper than fixing afterwards.

Present a plan. Point out vague areas. Iterate with the user until the outcome and shape are clear. Apply the principles file's core test to every planned instruction: **would an LLM do this correctly without being told?** If yes, cut it.

## Phase 4: Build

**Load:**

- `references/skill-quality-principles.md` — what earns its place, BMad institutional knowledge, failure modes (already loaded in Phase 3; keep open)
- `references/standard-fields.md` — field-by-field schema reference for frontmatter, customize.toml, and the Overview formula
- `references/complex-workflow-patterns.md` (Complex Workflow only) — config integration, compaction survival, document-as-cache

Load `assets/SKILL-template.md` and `references/template-substitution-rules.md`. Default to writing the entire workflow inline in SKILL.md as named sections. Carve out to `references/` ONLY when SKILL.md would otherwise be too big to scan; when you do, use descriptive filenames (`press-release.md`), never numbered prefixes (`01-discover.md`). Output to `{bmad_builder_output_folder}`.

**If the SKILL.md references multiple internal files** (anything in `references/`, `assets/`, `scripts/`, `agents/`), stamp the Conventions block at the top of SKILL.md (after Overview, before On Activation):

```markdown
## Conventions

- Bare paths (e.g. `references/press-release.md`) resolve from the skill root.
- `{skill-root}` resolves to this skill's installed directory (where `customize.toml` lives).
- `{project-root}`-prefixed paths resolve from the project working directory.
- `{skill-name}` resolves to the skill directory's basename.
```

**If `{customizable}` is yes:**

- Emit `customize.toml` alongside SKILL.md from `assets/customize-template.toml`. Fill `[workflow]` with the Phase 2 scalars.
- In SKILL.md, replace hardcoded references with `{workflow.<name>}` indirection. `assets/brief-template.md` → `{workflow.brief_template}` if lifted.
- Add the resolver activation step before config load:

  ```markdown
  ### Step 1: Resolve the Workflow Block

  Run: `python3 {project-root}/_bmad/scripts/resolve_customization.py --skill {skill-root} --key workflow`

  If the script fails, resolve the `workflow` block yourself by reading these three files in base → team → user order and applying structural merge rules: `{skill-root}/customize.toml`, `{project-root}/_bmad/custom/{skill-name}.toml`, `{project-root}/_bmad/custom/{skill-name}.user.toml`. Scalars override, tables deep-merge, arrays of tables keyed by `code`/`id` replace matching entries and append new ones, all other arrays append.
  ```

- Execute `{workflow.activation_steps_prepend}` before the workflow's first stage and `{workflow.activation_steps_append}` after greet but before Stage 1. Treat `{workflow.persistent_facts}` as foundational context loaded on activation (`file:` prefix = path/glob; bare entries = literal facts).

**If `{customizable}` is no:** no `customize.toml`, no resolver step. SKILL.md uses hardcoded paths throughout.

**If the skill uses the Decision-Log Workspace pattern** (Phase 2 confirmed it produces a revisable artifact):

- Add `output_dir` and `output_folder_name` scalars to `customize.toml [workflow]`. Default shape:
  - `output_dir = "{planning_artifacts}/<purpose>"` (e.g. `briefs`, `analyses`)
  - `output_folder_name = "<purpose>-{project_name}-{date}"`
  - This implies `{customizable}=yes` — if the user declined customization, ask whether to enable it for these two scalars.
- In SKILL.md Activation, after config resolution: bind `{doc_workspace} = {workflow.output_dir}/{workflow.output_folder_name}/`.
- Wire Create / Update / Validate intents and a Finalize audit per `references/skill-quality-principles.md` § Decision-Log Workspace Pattern. Follow the **Treatment style** sub-section there: state the principle once where it first applies, mention reads at the moments that matter, no prescribed frontmatter schema, no `## Workspace` header, no tree diagram. The workspace is just files.
- If the artifact will feed downstream LLM consumers: offer a `distillate.md` at finalize. Skip with a note if no distillation tool is available; never inline a substitute.

**Skill source tree** (only create folders that are needed):

```
{skill-name}/
├── SKILL.md           # Frontmatter, Overview, Activation, the workflow itself (default), routing if carved
├── customize.toml     # Only if {customizable} is yes
├── references/        # Carved-out workflow sections — descriptive names, no numbered prefixes
├── assets/            # Templates and other static content the workflow loads
├── scripts/           # Deterministic code with tests
│   └── tests/
```

Never put workflow content (`*.md` prompt files) directly at skill root — that's `SKILL.md`'s job. Carve-outs always go in `references/`.

| Location          | Contains                                                  | LLM relationship                     |
| ----------------- | --------------------------------------------------------- | ------------------------------------ |
| **SKILL.md**      | Overview, Activation, inline workflow OR routing to refs  | LLM identity, the workflow itself    |
| **`references/`** | Carved-out workflow sections (descriptive names)          | Loaded on demand by SKILL.md routing |
| **`assets/`**     | Templates, starter files, static content                  | Copied/transformed into output       |
| **`scripts/`**    | Python, shell scripts with tests                          | Invoked for deterministic operations |

**If the built skill includes scripts**, also load `references/script-standards.md` — ensures PEP 723 metadata, correct shebangs, and `uv run` invocation from the start.

**Lint gate** — validate and auto-fix. If subagents are available, delegate lint-fix; otherwise run inline.

1. Run both lint scripts in parallel:
   ```bash
   python3 scripts/scan-path-standards.py {skill-path}
   python3 scripts/scan-scripts.py {skill-path}
   ```
2. Fix high/critical findings, re-run (up to 3 attempts per script).
3. Run unit tests if scripts exist in the built skill.

## Phase 5: Handoff

**Interactive:** show what was built, lint results, and offer next steps (commit, run quality analysis). Decision log is at `{target-skill-path}/.decision-log.md`.

**Headless** (`{headless_mode}=true`): emit JSON only. `intent` is `"build"` for new, `"edit"` for existing.

```json
{
  "status": "complete",
  "intent": "build",
  "skill": "{target-skill-path}",
  "decision_log": "{target-skill-path}/.decision-log.md"
}
```

Blocked (ambiguous intent that couldn't be inferred, persistent lint failures, etc.): replace `"complete"` with `"blocked"` and add `"reason": "<one-line cause>"`. The log carries the detail.
