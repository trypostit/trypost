# Agent Type Guidance

Use this during Phase 1 to determine what kind of agent the user is describing. The three agent types are a gradient, not separate architectures. Surface them as feature decisions, not hard forks.

## The Three Types

### Stateless Agent

Everything lives in SKILL.md. No memory folder, no First Breath, no init script. The agent is the same every time it activates.

**Choose this when:**
- The agent handles isolated, self-contained sessions (no context carries over)
- There's no ongoing relationship to deepen (each interaction is independent)
- The user describes a focused expert for individual tasks, not a long-term partner
- Examples: code review bot, diagram generator, data formatter, meeting summarizer

**SKILL.md carries:** Full identity, persona, principles, communication style, capabilities, session close.

### Memory Agent

Lean bootloader SKILL.md + sanctum folder with 6 standard files. First Breath calibrates the agent to its owner. Identity evolves over time.

**Choose this when:**
- The agent needs to remember between sessions (past conversations, preferences, learned context)
- The user describes an ongoing relationship: coach, companion, creative partner, advisor
- The agent should adapt to its owner over time
- Examples: creative muse, personal coding coach, writing editor, dream analyst, fitness coach

**SKILL.md carries:** Identity seed, Three Laws, Sacred Truth, species-level mission, activation routing. Everything else lives in the sanctum.

### Autonomous Agent

A memory agent with PULSE enabled. Operates on its own when no one is watching. Maintains itself, improves itself, creates proactive value.

**Choose this when:**
- The agent should do useful work autonomously (cron jobs, background maintenance)
- The user describes wanting the agent to "check in," "stay on top of things," or "work while I'm away"
- The domain has recurring maintenance or proactive value creation opportunities
- Examples: creative muse with idea incubation, project monitor, content curator, research assistant that tracks topics

**PULSE.md carries:** Default wake behavior, named task routing, frequency, quiet hours.

## How to Surface the Decision

Don't present a menu of agent types. Instead, ask natural questions and let the answers determine the type:

1. **"Does this agent need to remember you between sessions?"** A dream analyst that builds understanding of your dream patterns over months needs memory. A diagram generator that takes a spec and outputs SVG doesn't.

2. **"Should the user be able to teach this agent new things over time?"** This determines evolvable capabilities (the Learned section in CAPABILITIES.md and capability-authoring.md). A creative muse that learns new techniques from its owner needs this. A code formatter doesn't.

3. **"Does this agent operate on its own — checking in, maintaining things, creating value when no one's watching?"** This determines PULSE. A creative muse that incubates ideas overnight needs it. A writing editor that only activates on demand doesn't.

## Relationship Depth

After determining the agent type, assess relationship depth. This informs which First Breath style to use (calibration vs. configuration):

- **Deep relationship** (calibration): The agent is a long-term creative partner, coach, or companion. The relationship IS the product. First Breath should feel like meeting someone. Examples: creative muse, life coach, personal advisor.

- **Focused relationship** (configuration): The agent is a domain expert the user works with regularly. The relationship serves the work. First Breath should be warm but efficient. Examples: code review partner, dream logger, fitness tracker.

Confirm your assessment with the user: "It sounds like this is more of a [long-term creative partnership / focused domain tool] — does that feel right?"

## Customization Surface by Archetype

Every agent emits a `customize.toml` — the metadata block (`code`, `name`, `title`, `icon`, `description`, `agent_type`) is required for all three types to satisfy the module.yaml roster contract. The override surface beneath it is opt-in and differs by archetype:

- **Stateless agent** — natural candidate for the override surface. Exposes `activation_steps_prepend/append`, `persistent_facts`, and any agent-specific scalars (e.g. swappable reference docs, output paths). Offer the opt-in during Phase 3; accept either answer.

- **Memory agent** — sanctum is the primary behavior-customization surface. PERSONA.md, CREED.md, BOND.md, CAPABILITIES.md are calibrated by First Breath and evolved by the owner. A TOML override surface competes with that. **Default the opt-in to no.** Opt in only when the user has a specific pre-sanctum-load need (e.g. org-mandated compliance preload) that the sanctum cannot express.

- **Autonomous agent** — same as memory. PULSE.md already owns autonomous behavior configuration. Default to no; opt in only with cause.

### First-Breath-Named Agents

Memory and autonomous agents whose name is learned during First Breath ship with `name = ""` in `customize.toml`. The owner fills the name post-activation by adding a stanza to `{project-root}/_bmad/custom/config.toml`:

```toml
[agents.creative-muse]
name = "Zephyr"
```

The installer and any roster-consuming UIs tolerate empty `name` and fall back to `title` for display until the owner fills it in. Do not prompt the user for a name at build time for these archetypes — the First Breath experience is where the name is born.

## Edge Cases

- **"I'm not sure if it needs memory"** — Ask: "If you used this agent every day for a month, would the 30th session be different from the 1st?" If yes, it needs memory.
- **"It needs some memory but not a deep relationship"** — Memory agent with configuration-style First Breath. Not every memory agent needs deep calibration.
- **"It should be autonomous sometimes but not always"** — PULSE is optional per activation. Include it but let the owner control frequency.
