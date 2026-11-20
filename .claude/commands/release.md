---
description: Friday release ritual — create git tag, GitHub release (auto-generated changelog), and a customer-facing email draft (Cal.com style)
allowed-tools: Bash, Write, Read, Skill
---

You are running the Friday release ritual for TryPost. Four artifacts are produced:

1. A git tag (semver)
2. A GitHub release with the **auto-generated** changelog (PR list + authors via GitHub's native generator — flat, technical, for developers)
3. A **customer-facing email draft** in Cal.com style (themed prose, end-user voice, no commit/PR references)
4. A **changelog thumbnail** (1200×630 PNG) rendered from the email's headline and themes, on the marketing site's hero design

Plus local mirrors in `releases/<version>/` (changelog, email, and thumbnail), versioned via the artifacts PR.

**Always confirm with the user before any push/tag/release.**

## Context (auto-loaded)

- Current branch: !`git branch --show-current`
- Working tree: !`git status --porcelain`
- Latest tag: !`git describe --tags --abbrev=0 2>/dev/null || echo "(none)"`
- Repo (owner/name): !`gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null || echo "(no gh)"`
- Local vs origin/main: !`git fetch --quiet origin main 2>/dev/null; git rev-list --left-right --count HEAD...origin/main 2>/dev/null || echo "0	0"`
- Commits since latest tag (or all if no tag): !`LAST_TAG=$(git describe --tags --abbrev=0 2>/dev/null); if [ -z "$LAST_TAG" ]; then git log --pretty=format:"%H%x09%s" --reverse; else git log "$LAST_TAG"..HEAD --pretty=format:"%H%x09%s" --reverse; fi`

## Workflow

### Step 1 — Pre-flight checks

Stop and tell the user if any of these fail:

- Current branch must be `main`. Else: ask user to `git checkout main`.
- Working tree must be clean. Else: ask user to commit/stash.
- Local in sync with `origin/main` (rev-list count `0	0`). Else: ask user to pull/push.
- Commits-since-tag list must be non-empty. Else: "Nothing new since the last tag."

### Step 2 — Determine next version

TryPost uses **sequential numbering with rollover at 9** — not standard semver. Do not parse conventional commits to choose the bump. Every release is the next sequential number, whatever the commits look like.

1. If no previous tag exists → next version = **`v1.0.0`** (first release ever).
2. Otherwise, parse the latest tag as `vMAJOR.MINOR.PATCH` and increment by these rules:
   - `patch += 1`
   - If `patch` reaches `10`: set `patch = 0`, `minor += 1`
   - If `minor` reaches `10`: set `minor = 0`, `major += 1`
3. Re-prefix with `v`.

Examples:

| From | To |
|---|---|
| (no tag) | v1.0.0 |
| v1.0.0 | v1.0.1 |
| v1.0.8 | v1.0.9 |
| v1.0.9 | v1.1.0 |
| v1.5.7 | v1.5.8 |
| v1.9.8 | v1.9.9 |
| v1.9.9 | v2.0.0 |

There is no manual override — the next version is whatever the rule above produces. If a release needs a different version for some special reason, the user must create the tag manually outside this command.

### Step 3 — Preview the changelog (GitHub native format)

Use GitHub's release-notes generator API to produce the changelog **without creating anything yet**:

```bash
gh api -X POST "repos/{OWNER}/{REPO}/releases/generate-notes" \
  -f tag_name="<new_version>" \
  -f target_commitish="main" \
  -f previous_tag_name="<latest_tag>" \
  --jq '.body'
```

For the first release ever (no previous tag), omit the `previous_tag_name` flag — GitHub falls back to the initial commit.

The body already contains:
- `<subject> by @<author> in #<PR>` lines
- "New Contributors" section when applicable
- `Full Changelog: ...` compare link

**Do not modify it.** The GitHub-native format is the goal.

### Step 4 — Draft the customer email (Cal.com style)

This email is for **end users of TryPost** — non-developers, paying customers, trial users. It must **NOT** reference: commits, PRs, authors, SHAs, conventional commit scopes, version control concepts, internal class names, file paths.

Read the commits only as **internal source material**. Translate to user-facing language.

#### Structure

```markdown
---
subject: "Changelog <version> — <improvement 1>, <improvement 2> and more..."
---

# Changelog <version> — <improvement 1>, <improvement 2> and more...

By TryPost Product Team • [Release <version>](https://github.com/<OWNER>/<REPO>/releases/tag/<version>)

Hello! Welcome to this week's update. Here's what's new in TryPost.

## <Outcome the reader gets, or the symptom that is gone>

<2-4 sentences of concrete narrative — what changed, why a user should care, what they'll notice. Open on what the reader experienced ("you may have watched it go out twice"), not on the internal cause. No marketing puffery.>

## <second outcome>

<same>

## <third outcome — only if there are genuinely 3 themes worth of work>

<same>

## New features

- <user-facing one-liner — what they can now do>
- <...>

## Fixes

- <user-facing one-liner — what no longer breaks>
- <...>

Cheers,
Paulo from TryPost.it

---

You're receiving this because you subscribed.
[Unsubscribe]({{unsubscribe_url}})
```

**Always link the GitHub release from the byline** — make `Release <version>` a link to `https://github.com/<OWNER>/<REPO>/releases/tag/<version>` (as shown above). It gives developer-minded readers the raw PR-level changelog without cluttering the body.

**Always end with the unsubscribe footer** — the `---` separator, the "You're receiving this because you subscribed." line, and an `[Unsubscribe]({{unsubscribe_url}})` link below the signature. Keep `{{unsubscribe_url}}` as a literal placeholder; the email sending tool fills it in. This footer is required on every customer email.

#### Voice

The email is copy, not a commit log: apply the `copywriting` skill's principles (`.agents/skills/copywriting/SKILL.md`) and the voice of the `in-app-copy` skill (`.claude/skills/in-app-copy/SKILL.md`). In practice:

- **Run every header, subject slot and bullet through "Now you can…".** If "Now you can see every post on a board" reads as a concrete, true new ability, keep it. If it reads vague ("Now you can… plan it on a board") or isn't an ability at all, rewrite it.
- **Talk to the reader and lead with the point.** "You", "your posts". The first sentence of a section says what they can do now; the details come after. A rhetorical question that names their situation is a good opener ("Want to know what's waiting for approval?").
- **Use the words on screen.** Name buttons, tabs and types as the app shows them (Publish, Board, Poll, Sent, Move up), so the reader can find the thing the email talks about. No internal names (slots, holders, meta, destinations).
- **Specific over clever.** Say which networks, which view, which limit. A line that sounds nice but could describe any release ("Plan it on a board, ask your followers") fails.
- **Bullets start with what the reader can do** (an imperative or "you can"), not with the name of a feature: "Add a first comment to a LinkedIn post", not "LinkedIn comments".

#### Section headers

A theme header names the outcome the reader gets or the symptom that is gone. Sentence case. It has to make sense to someone who never saw the bug report.

- ❌ `## Publishing that finishes what it started` (writerly; says nothing concrete)
- ❌ `## Your Asset Library, from anywhere` (area label dressed up)
- ✅ `## Your long videos stop posting twice`
- ✅ `## The Facebook Pages that wouldn't connect`

**Section order must match the subject order.** The subject promises a sequence. A reader who opens on a different topic than the one that got them to click feels the mismatch even if they can't name it.

#### Theme grouping (AI clusters by user impact)

Read all commits since the last tag and cluster into **2-3 user-facing themes**. Use whatever frame makes the changes feel coherent to a customer, not to a developer.

**Good themes** (end-user framing):
- "Trial protection" — bundles billing/Stripe Radar work
- "Reliable Facebook posting" — bundles Facebook fixes
- "Faster scheduling" — bundles queue/post improvements
- "Better post editor" — bundles UI changes to the post composer

**Bad themes** (internal framing — never use these):
- "Refactoring"
- "Dependency updates"
- "Feature commits" / "Fix commits"
- "Backend improvements"

If there are fewer than 3 themeable groups, use 2 or just 1. Don't pad. Internal-only changes (chore, CI, refactor, deps) usually shouldn't appear at all — fold the user-visible ones into "Fixes" with a user-voice rewrite, drop the rest.

**Order themes by reach, not by newness.** The first theme goes to whatever the largest share of subscribers will actually feel. A fix that unblocks a whole platform for everyone who uses it outranks a feature only integrators can reach, even though the feature is the newer work. Rank by how many people were affected.

**Check who a theme really reaches before you headline it.** Exposing an existing in-app capability over the API or MCP is new for integrators only — everyone else already had it in the UI. Headlining it as if the capability itself were new misleads the majority. Either qualify the section in its first sentence ("If you build against the TryPost API or connect an AI assistant...") and say plainly that the in-app path already worked, or demote it under a theme with broader reach.

#### Bullet rules for "New features" / "Fixes"

Rewrite each item in **user voice**, not commit voice:

- ❌ "fix(facebook): send Graph API requests as form-urlencoded"
- ✅ "Fixed an issue where multi-image Facebook posts could fail to publish"

- ❌ "feat(billing): charge one-time trial setup fee at Stripe Checkout"
- ✅ (Probably its own theme, not a bullet — billing is a big user-facing topic)

- ❌ "chore(deps): bump axios to 1.13.5"
- ✅ (Skip entirely — pure internal)

If a commit has no user-visible effect, **omit it**. Don't pad the email.

**Never repeat a theme section in the bullets.** "New features" and "Fixes" are for what did *not* earn its own section. If duplicate Instagram posts got three paragraphs above, they don't also get a bullet — the reader meets the same fact twice and the lists stop being scannable. Write the sections first, then list only what is left over.

#### Subject line

Pattern: `Changelog <version> — <improvement 1>, <improvement 2> and more...`

Each slot must name **what got better for the reader**, never the area it happened in. The area is where the work landed; the improvement is what they can now do, or what stopped hurting. A subject built from area labels tells a customer nothing — they already know TryPost has an Asset Library.

- ❌ `Changelog v1.0.8 — Publishing that finishes, Facebook Pages, Asset Library` (a writerly abstraction plus two bare area labels)
- ❌ `Changelog v1.0.8 — No duplicate posts, Facebook Pages that connect` (still abstract: "no duplicate posts" reads like a new dedup feature rather than a fix, and the second half is awkward)
- ✅ `Changelog v1.0.8 — Facebook Pages finally connect, reuse your media and more...`

Rules:

- **Two named improvements plus `and more...` beats three cramped ones.** The trailing `and more...` carries the rest of the release and buys the two named slots enough room to be specific.
- **The first slot goes to the widest reach.** Same ranking as the themes: most subscribers affected wins, even when that is a bug fix and the release also shipped a shiny feature.
- Plain words like `finally`, `stop`, `no longer` are good — they read as an honest founder, not a marketer.
- Don't put "TryPost" in the subject — the email already comes from the TryPost sender, so it's redundant.
- Cap around 80 chars.

### Step 5 — Humanize the email prose

Run the email body through the `humanizer` skill before previewing:

1. Invoke the `Skill` tool with `skill: humanizer` and pass the draft email body plus this context: *"This is a customer-facing changelog email for TryPost (social media scheduler SaaS). Tone: developer founder writing to early users on a Friday — warm, specific, no marketing puffery. Cal.com style. Keep the existing structure (subject frontmatter, section headers, bullets, signature, unsubscribe footer). Do not strip section headers, the 'Cheers, Paulo from TryPost.it' signature, or the unsubscribe footer."*
2. Replace the draft email body with the humanized version.

**Do NOT humanize:**
- The changelog from Step 3 (flat commit list, no prose).
- The subject line frontmatter.
- The literal signature `Cheers,\nPaulo from TryPost.it` — keep it exact.
- The unsubscribe footer (`---`, "You're receiving this because you subscribed.", `[Unsubscribe]({{unsubscribe_url}})`) — keep it exact, below the signature.

The humanizer skill itself covers all patterns. Trust it.

### Step 5b — Render the changelog thumbnail

**The image tells one story: the headline, the screenshot and the chips are all about the same feature**, the release's widest-reach one (the email's first section). A headline about the board next to a calendar, with chips about other features, reads as three unrelated things. The rest of the release lives in the email, not in the image.

- **version** — always pass the release version (e.g. `v1.0.6`). It is shown in the violet pill above the headline (`● Changelog · v1.0.6`). Not optional.
- **headline** — that feature as a hero line that passes the "Now you can…" test (see Voice in Step 4): "Now you can see every post on one board" is concrete and true, so `See every post | on one board` works; `Plan it on a board, ask your followers` does not (vague, two ideas, says nothing a reader can picture). Two short lines of about 15–20 characters each, split with `|`; the script shrinks a line that is too long for the column, so a long line comes out small: shorten it instead. Sentence case, no version number, no period.
  - ❌ `Plan it on a board, ask your followers` (clever, vague, two ideas)
  - ❌ `All your posts on one board, polls on X, Mastodon and Threads` (two ideas, too long for the column)
  - ✅ `See every post | on one board`
- **scene** — the app path that shows that feature, with its query (`/schedule/board`, `/schedule/board?group=tag`, `/insights`). The script signs in as the demo user and shoots it (see Demo data below).
- **themes** — 2-4 short chips about the same feature: what it lets the reader do, in the words the screen uses (`By status`, `By tag`, `By channel` for the board). Never another feature of the release.

#### Demo data

The screenshot is the real app with demo content, never a real workspace. There is no seeder file for it: create it with tinker right before the render and remove it right after, so the scheduler never picks its posts up.

- **The brand is the site's:** John Doe / Doe Media, the channel avatar and the coffee and ceramics photos of trypost-site (`public/images/avatars`, `public/images/mockup/posts`). Media URLs use the host `https://demo-media.trypost.invalid/<path under the site's public/images>`; the render script answers them from the site, so nothing is uploaded to a disk.
- **Shape it for the scene:** the base below fills the board and the calendar (ten channels, three tags, posts in every status dated around today). When the feature needs other data (a poll with results, a cover, ideas, insights), add it to the snippet, built with the model factories and their states (`database/factories`).
- **Sign-in:** `demo@trypost.it` / `password`, with an active subscription so the app opens without Welcome.

Write the snippet to the scratchpad and run it with `--execute` (never `php artisan tinker <file>`, which stays in the interactive shell and hangs):

```bash
php artisan tinker --execute "$(cat <scratchpad>/release-demo.php)" < /dev/null
```

Base snippet:

```php
use App\Models\{Account, Post, SocialAccount, User, Workspace, WorkspaceLabel};
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

$media = fn (?string $photo) => blank($photo) ? [] : [['id' => (string) Str::uuid(), 'path' => "demo/{$photo}", 'url' => "https://demo-media.trypost.invalid/mockup/posts/{$photo}", 'type' => 'image', 'mime_type' => 'image/jpeg']];

$user = User::factory()->create(['name' => 'John Doe', 'email' => 'demo@trypost.it', 'password' => 'password', 'timezone' => 'America/New_York']);
$user->account->update(['name' => 'Doe Media']);
$user->account->subscriptions()->create(['type' => Account::SUBSCRIPTION_NAME, 'stripe_id' => 'sub_demo', 'stripe_status' => 'active', 'stripe_price' => 'price_demo']);
$workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id, 'name' => 'Doe Media']);
$workspace->members()->attach($user->id, ['is_admin' => true, 'requires_approval' => false]);
$user->update(['current_workspace_id' => $workspace->id]);

$channels = [];
foreach (['instagram' => 'doe.media', 'linkedin' => 'Doe Media', 'x' => 'doemedia', 'tiktok' => 'doe.media', 'youtube' => 'Doe Media', 'threads' => 'doe.media', 'bluesky' => 'doemedia.bsky.social', 'mastodon' => 'doemedia@mastodon.social', 'facebook' => 'Doe Media', 'pinterest' => 'doemedia'] as $network => $username) {
    $channels[$network] = SocialAccount::factory()->{$network}()->create(['workspace_id' => $workspace->id, 'display_name' => 'John Doe', 'username' => $username, 'avatar_url' => 'https://demo-media.trypost.invalid/avatars/persona.svg', 'position' => count($channels) + 1, 'timezone' => 'America/New_York']);
}

$labels = [];
foreach (['Launch' => '#DDA0DD', 'Behind the scenes' => '#89CFF0', 'Promo' => '#FFD580'] as $name => $color) {
    $labels[$name] = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => $name, 'color' => $color]);
}

foreach ([
    ['instagram', 'draft', 3, 9, 'New mugs, same morning ritual. Which glaze is your favorite?', 'mugs.jpg', ['Launch']],
    ['linkedin', 'draft', 4, 11, 'Three things we learned shipping our first ceramics collection.', null, ['Behind the scenes']],
    ['pinterest', 'draft', 5, 15, 'Moodboard for the spring collection: soft clay, warm light.', 'moodboard.jpg', []],
    ['tiktok', 'pendingApproval', 1, 18, 'From a lump of clay to your morning latte, in 30 seconds.', 'wheel.jpg', ['Behind the scenes']],
    ['facebook', 'pendingApproval', 2, 10, 'Weekend market this Saturday: come say hi and try a cup.', 'shelf.jpg', ['Promo']],
    ['instagram', 'scheduled', 1, 9, 'Latte art Friday. Tag a friend who needs a coffee.', 'latte.jpg', ['Promo']],
    ['x', 'scheduled', 1, 12, 'The spring collection drops Monday. Set a reminder.', null, ['Launch']],
    ['youtube', 'scheduled', 2, 14, 'How we glaze a mug, start to finish', 'shoot.jpg', ['Behind the scenes']],
    ['threads', 'scheduled', 3, 11, 'Coffee first, emails later. What is your morning order?', null, []],
    ['instagram', 'published', -1, 9, 'Our studio shelf is officially full. Thank you for an amazing month.', 'shelf.jpg', []],
    ['linkedin', 'published', -1, 13, 'We are hiring a part-time studio assistant. Know someone?', 'laptop.jpg', []],
    ['bluesky', 'published', -2, 10, 'Fresh batch out of the kiln this morning.', 'mug.jpg', ['Behind the scenes']],
    ['mastodon', 'failed', -1, 16, 'Spring collection preview, now with photos.', 'mugs.jpg', ['Launch']],
] as [$network, $state, $day, $hour, $text, $photo, $tags]) {
    $at = CarbonImmutable::now('America/New_York')->startOfDay()->addDays($day)->setHour($hour)->utc();
    $post = Post::factory()->forAccount($channels[$network])->{$state}()->create(['user_id' => $user->id, 'content' => "<p>{$text}</p>", 'media' => $media($photo), 'scheduled_at' => $at, 'published_at' => $state === 'published' ? $at : null, 'meta' => $network === 'youtube' ? ['title' => $text] : []]);
    $post->labels()->attach(collect($tags)->map(fn ($tag) => $labels[$tag]->id)->all());
}

echo "demo ready\n";
```

After the render (and before anything else), remove it, and check that no demo user is left:

```bash
php artisan tinker --execute '$user = App\Models\User::where("email", "demo@trypost.it")->first(); $account = $user->account; $account->workspaces()->each(fn ($workspace) => $workspace->delete()); $user->delete(); $account->delete(); echo App\Models\User::where("email", "demo@trypost.it")->count();' < /dev/null
```

Create the directory and render the thumbnail so the user can preview it before confirming:

```bash
mkdir -p releases/<version>
node .claude/release-assets/render-thumbnail.ts \
  --version "<version>" \
  --headline "<first line> | <second line>" \
  --themes "<label 1>,<label 2>,<label 3>" \
  --scene "<app path>" \
  --out releases/<version>/thumbnail.png
```

It draws the marketing site's hero, like the site's share image and the profile banners (`trypost-site/.claude/og-assets/shoot-og-hero.mjs`, `~/Herd/paulo-social-media-banners`): the hero's lilac with the text on the left (logo, version pill, headline, chips) and, on the right, a window holding the real screenshot of the scene, running off the edges, 1200×630 at 2x. It needs the app's Vite dev server (`npm run dev`; start it if `public/hot` is missing) and trypost-site next to this repo (`../trypost-site`, or `TRYPOST_SITE_DIR`), whose dev server on port 4390 it starts and stops when nothing answers there. It needs the demo data above to exist while it runs. If the render fails, report and stop before tagging.

### Step 6 — Confirm with the user

Show:
1. **Proposed version** (e.g., `v1.0.9 → v1.1.0` — sequential rollover at 9).
2. **Changelog preview** (Step 3 output).
3. **Email preview**: subject line + full body (post-humanizer).
4. **Thumbnail**: `releases/<version>/thumbnail.png` (already rendered in Step 5b — tell the user they can open it to preview).
5. **Files that will be created/pushed**:
   - Tag `<version>` (pushed to origin)
   - GitHub release `<version>`
   - `releases/<version>/changelog.md`
   - `releases/<version>/email.md`
   - `releases/<version>/thumbnail.png`
   - Branch `chore/release-<version>-artifacts` + a PR versioning the three files above

Then ask: **"Create the tag, publish the release, and open the PR with the artifacts?"**

Do **not** proceed without explicit yes.

### Step 7 — Execute

After confirmation, in this exact order. Steps 4–6 (tag + release) run from `main` so the tag stays on the released `main` commit; steps 7–8 version the artifacts on a separate branch and open a PR.

1. Create local directory: `mkdir -p releases/<version>` (already created in Step 5b).
2. Write `releases/<version>/changelog.md` with the Step 3 content (raw GitHub markdown).
3. Write `releases/<version>/email.md` with frontmatter + humanized body.
   (`releases/<version>/thumbnail.png` was already rendered in Step 5b.)
4. From `main`, create the annotated tag: `git tag -a <version> -m "Release <version>"`
5. Push tag: `git push origin <version>`
6. Create the GitHub release using the changelog file as body:
   ```bash
   gh release create <version> --title "<version>" --notes-file releases/<version>/changelog.md
   ```
7. Version the artifacts on a branch (the three files carry over from the working tree) and push:
   ```bash
   git checkout -b chore/release-<version>-artifacts
   git add releases/<version>/changelog.md releases/<version>/email.md releases/<version>/thumbnail.png
   git commit -m "chore(release): add <version> changelog, customer email, and thumbnail artifacts"
   git push -u origin chore/release-<version>-artifacts
   ```
8. Open the PR against `main` (body: what the three files are + a link to the GitHub release):
   ```bash
   gh pr create --base main --head chore/release-<version>-artifacts \
     --title "chore(release): <version> changelog, customer email + thumbnail artifacts" \
     --body "<short body — the three artifact files + link to the release>"
   ```
9. Report to the user:
   - GitHub release URL (from `gh release` output)
   - PR URL (from `gh pr create` output)
   - Local paths: `releases/<version>/changelog.md`, `releases/<version>/email.md`, `releases/<version>/thumbnail.png`

### On failure

- `git push origin <version>` fails: report the exact error, leave the local tag in place, do not retry destructively.
- `gh release create` fails: the tag is already pushed; tell the user they can recreate manually with `gh release create <version> --title "<version>" --notes-file releases/<version>/changelog.md`.
- `git push` / `gh pr create` for the artifacts branch fails: the tag and GitHub release are already live; report the error and tell the user they can open the PR manually from `chore/release-<version>-artifacts`.
- `Skill`, `Write`, or thumbnail render failure during artifact prep: report and stop. Do not push the tag without the artifacts being prepared.
