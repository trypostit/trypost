---
name: {module-code-or-empty}{skill-name}
description: { skill-description } # [5-8 word summary]. [trigger phrases, e.g. Use when user says create xyz or wants to do abc]
---

# {skill-name}

## Overview

{overview — concise: what it does, args supported, and the outcome for the singular or different paths. This overview needs to contain succinct information for the llm as this is the main provision of help output for the skill.}

## Conventions

- Bare paths (e.g. `references/guide.md`) resolve from the skill root.
- `{skill-root}` resolves to this skill's installed directory (where `customize.toml` lives).
- `{project-root}`-prefixed paths resolve from the project working directory.
- `{skill-name}` resolves to the skill directory's basename.

## On Activation

{if-customizable}
### Step 1: Resolve the Workflow Block

Run: `python3 {project-root}/_bmad/scripts/resolve_customization.py --skill {skill-root} --key workflow`

If the script fails, resolve the `workflow` block yourself by reading these three files in base → team → user order and applying structural merge rules: `{skill-root}/customize.toml`, `{project-root}/_bmad/custom/{skill-name}.toml`, `{project-root}/_bmad/custom/{skill-name}.user.toml`. Scalars override, tables deep-merge, arrays of tables keyed by `code`/`id` replace matching entries and append new ones, all other arrays append.

### Step 2: Execute Prepend Steps

Execute each entry in `{workflow.activation_steps_prepend}` in order before proceeding.

### Step 3: Load Persistent Facts

Treat every entry in `{workflow.persistent_facts}` as foundational context for the whole run. Entries prefixed `file:` are paths or globs — load the referenced contents as facts. All other entries are facts verbatim.

### Step 4: Load Config

{/if-customizable}
{if-module}
Load available config from `{project-root}/_bmad/config.yaml` and `{project-root}/_bmad/config.user.yaml` (root level and `{module-code}` section). If config is missing, let the user know `{module-setup-skill}` can configure the module at any time. Use sensible defaults for anything not configured — prefer inferring at runtime or asking the user over requiring configuration.
{/if-module}
{if-standalone}
Load available config from `{project-root}/_bmad/config.yaml` and `{project-root}/_bmad/config.user.yaml` if present. Use sensible defaults for anything not configured.
{/if-standalone}
{if-customizable}

### Step 5: Execute Append Steps

Execute each entry in `{workflow.activation_steps_append}` in order before entering the workflow's first stage.

{/if-customizable}

{The rest of the skill — body structure, sections, phases, stages, scripts, external skills — is determined entirely by what the skill needs. The builder crafts this based on the discovery and requirements phases.}
