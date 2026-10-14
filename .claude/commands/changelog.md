---
description: Write a "What's New" entry (title, tag, body and cover) for a shipped feature, under changelog/
argument-hint: <PR number, branch or a short description of the feature>
allowed-tools: Bash, Write, Read, Edit, Skill
---

You are writing one in-app "What's New" entry for TryPost. Entries live in the repo
and reach production through `php artisan changelog:sync`, which runs on every deploy.

Feature to announce: $ARGUMENTS

## What an entry is

```
changelog/<YYYY-MM-DD>-<slug>/
  entry.md     front matter + Markdown body
  cover.png    1200×750 cover (8:5, the card and modal slot)
```

```markdown
---
title: Music, collaborators and trial reels for Instagram
date: 2026-10-14
tag: new            # optional: new | beta
---

Body in Markdown.
```

- The folder name is the slug and never changes once synced (views are keyed to the row it creates).
- `date` is the day users see. A future date hides the entry until that day (UTC).
- Users only see entries dated on or after the day they signed up.
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
- **tag**: `new` for a new capability, `beta` for a beta feature, none for improvements and fixes.
- **body**: one short lead sentence on the benefit, then details. Use a bullet list when there
  are several parts (bold the part's name). Use the names the user sees on screen. No internal
  names, no PR or commit references, no apologies, 40–120 words.

Run the text through the `humanizer` skill before saving.

### Step 3 — Render the cover

Pick a short headline (the title or a tighter version of it), one phrase in it to underline, and
two or three theme chips:

```bash
node .claude/release-assets/render-thumbnail.mjs \
  --template changelog-cover.template.html --width 1200 --height 750 --scale 1 \
  --badge "What's new" \
  --headline "Music, collaborators and trial reels" \
  --underline "trial reels" \
  --themes "Music,Collaborators,Trial reels" \
  --out changelog/<YYYY-MM-DD>-<slug>/cover.png
```

Open the PNG and check that the headline fits on two or three lines.

### Step 4 — Validate

```bash
php artisan changelog:sync
```

It fails loud, naming the folder, when the front matter is invalid or the cover is missing.
Locally it also uploads the cover to the default disk, so the entry shows in the sidebar card.

### Step 5 — Hand over

Show the user the title, tag, body and cover path. Do not commit or push unless asked.
