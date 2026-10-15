---
description: Write a "What's New" entry (title, tag, body and cover) for a shipped feature, under resources/changelog/
argument-hint: <PR number, branch or a short description of the feature>
allowed-tools: Bash, Write, Read, Edit, Skill
---

You are writing one in-app "What's New" entry for TryPost. Entries live in the repo
and reach production through `php artisan changelog:sync`, which runs on every deploy.

Feature to announce: $ARGUMENTS

## What an entry is

```
resources/changelog/<YYYY-MM-DD>-<slug>/
  entry.md     front matter + Markdown body
  cover.png    16:9 cover (rendered at 2400×1350), the card and modal slot
```

The cover is a Vite asset: the build versions it and the app links it with `Vite::asset()`.
Nothing is uploaded anywhere.

```markdown
---
title: Music, collaborators and trial reels for Instagram
date: 2026-10-14
tags: [new]         # optional, any of: new, improved, fixed, beta
---

Body in Markdown.
```

- The folder name is the slug and never changes once synced (views and reactions are keyed to the row it creates).
- `date` is the day users see and must be the date in the folder name. A future date hides the entry until that day (UTC).
- The sidebar card announces only the newest entry dated on or after the user's signup day; the
  "What's New" list shows every published entry.
- Entry content is **English only**. Never add lang keys for it.

## Workflow

### Step 1 — Understand the feature

- PR number: `gh pr view <n> --json title,body,mergedAt,files` and skim the diff.
- Branch or description: read the relevant commits and code.

Find what changed **for the user**: what they can do now, where it lives on screen, and any
condition (plan, network, connection method). Verify every claim against the code or the lang
files; never describe behaviour you have not confirmed.

### Step 2 — Write the copy

Load the `in-app-copy` skill first, then write:

- **title**: what the user can now do, in their words, sentence case, max ~60 characters.
- **tags** (render above the title, each with a fixed color):
  - `new`: a capability users did not have before.
  - `improved`: something that already existed works better.
  - `fixed`: something that was broken works again.
  - `beta`: the feature is in beta (combine with `new` when both apply).

  Adding a tag means a case in `App\Enums\Changelog\Tag` with its English `label()`, and its
  color in `ChangelogTagBadge.vue`. Tags are not translated, like the rest of the entry.
- **body**: one short lead sentence on the benefit, then details. Use a bullet list when there
  are several parts (bold the part's name). Use the names the user sees on screen. No internal
  names, no PR or commit references, no apologies, 40–120 words.

Run the text through the `humanizer` skill before saving.

### Step 3 — Draw the cover

The cover is a drawing, never a screenshot and never a headline on a background: the entry's
title already sits right under it. It shows the product's own UI, drawn, so it looks like
TryPost and stays true when the real screen changes.

**Style rules (every cover): flat colors, a firm border, the content inside.** Use the style,
never another product's composition, elements or sample content.

- **Background:** one flat color, no gradient, glow or pattern: `bg-violet` (the app's
  `--primary`, the default), `bg-blue`, `bg-green`, `bg-peach` (the app's light tints). Vary it
  between consecutive entries.
- **Surfaces:** white, with the 2.5px ink border (`#292928`, the app's text color), rounded, and
  **no shadow**. Dividers inside a surface use the same ink line.
- **Draw where the feature lives, not a device.** For new settings, one `panel` that reads like
  the composer's section (a header naming what it applies to, e.g. `Reel`, then the new settings
  as `setting` rows), with the network's `badge` stuck on its top-left corner. No phones, browser
  frames or mockups of hardware.
- **One icon per idea, everywhere:** if the header uses `movie` for the reel, every reel on the
  cover uses `movie` too (the Trial reel row included). Never two icons for the same thing.
- **Settings are drawn the way the app shows them:** the Tabler icon of the idea (`at` for
  collaborators, `music`, `movie` for reels), the label exactly as on screen, and the real
  control: `handle` chips with `@`, a `track` pick, the app's violet `switch`.
- **Content is ours and generic:** invented handles (`@nina`, `@leomakes`), an invented track;
  never a real person, account or customer.
- **No decoration:** no sparkles, confetti, glows or shadows. The UI is the picture.
- **Readable small:** labels about 28px semibold, nothing under 19px; the cover also shows small
  in the sidebar card. Keep everything inside the 1200×675 canvas, nothing overlapping by
  accident.

**Pick the scene**, then copy its template from `.claude/release-assets/changelog-covers/` to the
scratchpad and edit the copy (the templates stay as they are):

| Scene | Template | Use it for |
| --- | --- | --- |
| Feature (default) | `feature.html` | A new or improved option, control or screen: the composer-like panel with the new settings and the network's badge. |
| Integration | `integration.html` | A new network, app or service: the TryPost mark + the service's app icon. |
| Announcement | `announcement.html` | Broad news with nothing to draw (a milestone, a round-up, a policy change): three emojis on white surfaces. |

A fix usually uses the Feature scene with the control that works again. Render the scene:

```bash
node .claude/release-assets/changelog-covers/render.mjs \
  --scene <scratchpad>/scene.html \
  --out resources/changelog/<YYYY-MM-DD>-<slug>/cover.png
```

The renderer links `cover.css`, inlines Tabler icons (`<i data-icon="users"></i>`, add
`data-filled` for the filled set) and the TryPost mark (`{{TRYPOST_MARK}}`), and saves the
cover at 2× (2400×1350, 16:9). Never resize or crop it afterwards.

Open the PNG and check it against the style rules: nothing clipped or overflowing (chips
inside the panel, media filling its card), labels readable at the size of the sidebar card,
no borders or decoration.

### Step 4 — Validate

```bash
php artisan changelog:sync
```

It fails loud, naming the folder, when the folder name is not `<YYYY-MM-DD>-<slug>`, the `date`
is not that same day, the front matter or tags are invalid, `entry.md` or `cover.png` is
missing, or the cover is not 16:9. Locally it also writes the entry to the database, so it shows in the sidebar
card and the "What's New" list.

### Step 5 — Hand over

Show the user the title, tags, body and the entry's folder. Do not commit or push unless asked.
