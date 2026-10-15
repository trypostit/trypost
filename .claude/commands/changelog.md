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
  cover.png    1200×675 cover (16:9, the card and modal slot)
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
- `date` is the day users see. A future date hides the entry until that day (UTC).
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

### Step 3 — Render the cover

The cover is **always 16:9, 1200×675 PNG**. The sidebar card, the list thumbnail and the detail
all show it in a 16:9 slot (`aspect-video`), and `changelog:sync` refuses any cover that is
not 16:9. Always render it with the command below; never hand-make, crop or resize a cover, and
never change `--width`, `--height` or `--scale`.

Pick a short headline (the title or a tighter version of it), one phrase in it to underline, and
two or three theme chips:

```bash
node .claude/release-assets/render-thumbnail.mjs \
  --template changelog-cover.template.html --width 1200 --height 675 --scale 1 \
  --badge "What's new" \
  --headline "Music, collaborators and trial reels" \
  --underline "trial reels" \
  --themes "Music,Collaborators,Trial reels" \
  --out resources/changelog/<YYYY-MM-DD>-<slug>/cover.png
```

Open the PNG and check it:

- The size is 1200×675 (`sips -g pixelWidth -g pixelHeight <path>`).
- The headline fits on one or two lines (about 35 characters at most) and the underlined phrase
  sits on a single line. Shorten the headline rather than letting it run to a third line.
- Two or three theme chips, one or two words each.

### Step 4 — Validate

```bash
php artisan changelog:sync
```

It fails loud, naming the folder, when the front matter is invalid, `entry.md` or `cover.png` is
missing, or the cover is not 16:9. Locally it also writes the entry to the database, so it shows in the sidebar
card and the "What's New" list.

### Step 5 — Hand over

Show the user the title, tags, body and the entry's folder. Do not commit or push unless asked.
