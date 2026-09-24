# Independent Social Posts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create one independently editable post per selected social account from a two-step dialog, preserving production history and parity across web, API, and MCP.

**Architecture:** Keep `posts` and `post_platforms`: every new post has exactly one enabled delivery row. A batch Action resolves account-specific overrides, validates all destinations, and writes all rows in one transaction. Preserve settled legacy rows; split only editable multi-target drafts and scheduled posts at a guarded cutover.

**Tech Stack:** PHP 8.5, Laravel 13.24.0, PostgreSQL and MySQL, Pest 5, Inertia Laravel 3.3.1, Vue 3.5, `@inertiajs/vue3` 3.6, Wayfinder, existing Asset Library and queue.

**Spec:** `docs/superpowers/specs/2026-09-24-independent-social-posts-design.md`

## Global Constraints

- One selected **social account** equals one `posts` row and one enabled `post_platforms` row; multiple accounts on one network are valid.
- Account/network cannot change on edit; a compatible `content_type` can change.
- Compose in browser state until Save Draft, Schedule, or Publish. Upload media through the existing Asset Library before saving posts.
- Keep the current sidebar/status navigation. The proposed single Posts item and tabs are deferred.
- Batch database creation is atomic. A rejected request leaves already uploaded assets in the workspace library.
- Web, REST API and MCP call the same Actions. `POST /posts` retains a single-resource response; a distinct endpoint/tool handles batches.
- `posts.scheduled_at` remains the schedule source; `FinalizePostPublication` remains the only settler.
- X caption limits and previews must use the existing sanitized-content/defused-link behavior; the new dialog needs the same `xLinkTlds` prop when `X_DEFUSE_LINKS` is enabled.
- Preserve settled legacy IDs, analytics FKs, comments, media and provider IDs/URLs. Split only active drafts/scheduled rows after pausing writes/scheduler and draining affected workers.
- No new dependency or schema migration. SQL, migrations and tests must work on PostgreSQL and MySQL; MySQL `timestamp()` ends at 2038-01-19.
- Read `.ai/rules/index.md` and matching rules before each implementation area; search versioned Laravel Boost docs before code changes. Consult official platform docs if changing platform API behavior.
- Every code change has a focused test. Run affected Pest/browser tests and `vendor/bin/pint --dirty --format agent` after PHP edits.

## Review Focus

Each likely failure has a test in the owning task:

1. Two accounts on the same network must produce two independent posts (Task 2).
2. Explicit empty caption/media overrides must replace shared values while inherited values track first-step changes (Task 5).
3. Changing Instagram Post to Reel/Story must revalidate media without changing the account (Task 3).
4. One invalid/inactive/foreign-workspace destination must roll back the entire batch and dispatch no jobs (Task 2).
5. Rerunning the legacy split with disabled placeholders and nested comments must preserve IDs and relationships (Task 8).

---

## File map

| Area | Files | Responsibility |
| --- | --- | --- |
| Domain | `app/Support/PostCompositionValidator.php`, `app/Actions/Post/{CreateChannelPost,CreatePosts,UpdatePost}.php` | Resolve, validate, create and edit individual destination posts. |
| API/MCP | `app/Http/Controllers/Api/PostController.php`, `app/Http/Requests/Api/Post/{StorePostRequest,StorePostsRequest,UpdatePostRequest}.php`, `routes/api.php`, `app/Mcp/Tools/Post/{CreatePostTool,CreatePostsTool,UpdatePostTool}.php`, `app/Mcp/Servers/TryPostServer.php` | Single and batch contracts via Actions. |
| Composer | `resources/js/composables/usePostComposition.ts`, `resources/js/components/posts/composer/PostComposerDialog.vue`, `resources/js/components/ImageCropperDialog.vue`, `resources/js/lib/imageCrop.ts` | Two-step dialog, inheritance, per-account crop and upload. |
| Web | `app/Http/Controllers/App/PostController.php`, app post requests, `app/Support/LegacyPostCard.php`, `resources/js/pages/posts/{Index,Calendar,Edit}.vue`, `routes/app.php` | Dialog routes, individual/legacy cards and old links. |
| Internal paths | AI creation, repurpose and duplication callers of `CreatePost::execute` / `SyncPostPlatforms::execute` | Remove all grouped writes. |
| Legacy | `app/Console/Commands/{AuditLegacyPosts,SplitLegacyActivePosts}.php` | Audit and idempotent split. |

Develop Tasks 1–7 without enabling new write routes in production. Task 8 supplies the data gate; Task 9 enables the flow. Each task ends with a reviewable commit.

### Task 1: Resolve and validate compositions

**Files:** Create `app/Support/PostCompositionValidator.php`; test `tests/Unit/Support/PostCompositionValidatorTest.php`. Reuse `PostMediaRules`, `PostPlatformMetaRules`, `ContentFitsPlatformLimits`, `ContentTypeCompatibleWithMedia`.

**Interfaces:** `PostCompositionValidator::validate(Workspace $workspace, array $composition): array` returns ordered fully resolved `destinations[]` with `social_account_id`, `content_type`, `meta`, `content`, `media`.

- [ ] **Step 1: Write failing tests** for inheritance, explicit `content: ''` / `media: []`, duplicate accounts, inactive and foreign accounts, unsupported type, bad labels, media asset ownership, and scheduled media/caption/meta errors keyed as `destinations.N.field`. Include X sanitized-length behavior. Core assertion:

```php
$resolved = PostCompositionValidator::validate($workspace, $composition);
expect($resolved['destinations'][0]['content'])->toBe('Shared');
expect($resolved['destinations'][1]['content'])->toBe('');
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Unit/Support/PostCompositionValidatorTest.php`; expect missing class.
- [ ] **Step 3: Implement** one account lookup, duplicate-ID check, account/workspace/activity/type checks and existing per-platform media/content/meta rules. Drafts may be incomplete; scheduled/publishing posts must be publishable. Resolve overrides with key presence:

```php
$content = array_key_exists('content', $destination) ? $destination['content'] : ($composition['content'] ?? '');
$media = array_key_exists('media', $destination) ? $destination['media'] : ($composition['media'] ?? []);
```

- [ ] **Step 4: Run** focused tests on PostgreSQL and MySQL test configurations; run Pint. Expect pass.
- [ ] **Step 5: Commit** `feat(posts): validate per-account compositions`.

### Task 2: Create one post per destination atomically

**Files:** Create `app/Actions/Post/{CreateChannelPost,CreatePosts}.php`; test `tests/Feature/Actions/CreatePostsTest.php`. Use snapshot fields from `SyncPostPlatforms.php`.

**Interfaces:** `CreateChannelPost::execute(Workspace $workspace, User $user, array $destination): Post`; `CreatePosts::execute(Workspace $workspace, User $user, array $composition): Collection` in input order. Both consume Task 1's resolved shape.

- [ ] **Step 1: Write failing tests** for four accounts/four posts/four enabled targets and zero disabled placeholders; two Instagram accounts separate; labels and schedule copied; draft/scheduled/immediate statuses; invalid destination creates zero rows and zero `PublishPost` jobs. Check failure/retry on one post leaves siblings unchanged:

```php
$posts = CreatePosts::execute($workspace, $user, $composition);
expect($posts)->toHaveCount(4);
foreach ($posts as $post) {
    expect($post->postPlatforms()->count())->toBe(1);
    expect($post->postPlatforms()->enabled()->count())->toBe(1);
}
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Actions/CreatePostsTest.php`; expect missing Actions.
- [ ] **Step 3: Implement** single parent+target creation with account snapshot, no `SyncPostPlatforms`. Validate before `DB::transaction`; within it create each post, attach labels, and set status/schedule. Queue immediate publication only after commit:

```php
$resolved = PostCompositionValidator::validate($workspace, $composition);
return DB::transaction(fn (): Collection => collect($resolved['destinations'])->map(
    fn (array $destination): Post => CreateChannelPost::execute($workspace, $user, $destination)
));
```

Include labels/status/schedule and `PublishPost::dispatch($post)->afterCommit()` inside the actual transaction mapping; no external publish inside it.
- [ ] **Step 4: Run** this test and `tests/Feature/Jobs/PublishPostTest.php` on both engines; run Pint. Expect pass.
- [ ] **Step 5: Commit** `feat(posts): create independent destination posts`.

### Task 3: Edit only one destination

**Files:** Modify `app/Actions/Post/UpdatePost.php`, app/API update requests; test `tests/Feature/Actions/UpdateChannelPostTest.php` and existing `tests/Feature/UpdatePostRequestTest.php`.

**Interfaces:** Retain `UpdatePost::execute(Workspace $workspace, Post $post, array $data): array{post: Post, action: PostAction|null}`. Accept content, media, content_type, meta, schedule/status and labels; reject destination replacement.

- [ ] **Step 1: Write failing tests** changing Instagram Post to Reel/Story with compatible media; incompatible media gives 422 and rolls back; a different `social_account_id` or `platforms[]` gives 422. Editing one of two new posts leaves the other unchanged. Finalized and legacy multi-target posts remain readable and cannot enter the new edit flow.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Actions/UpdateChannelPostTest.php tests/Feature/UpdatePostRequestTest.php`; expect failures.
- [ ] **Step 3: Implement** validation against the final content/media/type, then update only the sole enabled target's type/meta and parent fields. Keep status transitions and Google Business cleanup behavior. Never disable/re-enable all targets:

```php
if (array_key_exists('social_account_id', $data) || array_key_exists('platforms', $data)) {
    throw ValidationException::withMessages(['social_account_id' => __('posts.errors.target_fixed')]);
}
$target = $post->postPlatforms()->enabled()->sole();
```

- [ ] **Step 4: Run** focused tests and API update tests; run Pint. Expect pass.
- [ ] **Step 5: Commit** `feat(posts): edit one account post`.

### Task 4: Add API and MCP single/batch parity

**Files:** Modify API PostController, `StorePostRequest`, routes, MCP `CreatePostTool`/`UpdatePostTool`/server; create `app/Http/Requests/Api/Post/StorePostsRequest.php`, `app/Mcp/Tools/Post/CreatePostsTool.php`; test `tests/Feature/Api/PostApiTest.php`, `tests/Feature/Mcp/PostToolTest.php`.

**Interfaces:** `POST /api/posts` creates exactly one destination and returns one `PostResource`; `POST /api/posts/batch` takes `destinations[]` and returns ordered `posts: PostResource[]`; MCP single/batch tools return one or ordered IDs. Both call `CreatePosts`.

- [ ] **Step 1: Write failing tests** for single legacy `platforms[0]`, clear 422 on multi-platform single create, batch 201/ordered IDs, invalid batch zero rows, MCP permissions/parity, fixed-account update, and old aggregate GET readability.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Api/PostApiTest.php tests/Feature/Mcp/PostToolTest.php`; expect contract failures.
- [ ] **Step 3: Implement** conversion of one legacy platform into one destination, register batch route before parameterized route, call Actions and preserve `PostResource`:

```php
Route::post('/posts/batch', [PostController::class, 'storeBatch'])->name('api.posts.batch.store');
Route::post('/posts', [PostController::class, 'store'])->name('api.posts.store');
```

Register `CreatePostsTool` in `TryPostServer`. Reject multi-platform old requests explicitly; do not silently return a new response shape.
- [ ] **Step 4: Run** focused tests and `php artisan route:list --path=api/posts --except-vendor`; run Pint. Expect pass.
- [ ] **Step 5: Commit** `feat(posts): expose independent batch creation in API and MCP`.

### Task 5: Build the two-step dialog and account-specific crop

**Files:** Create `resources/js/composables/usePostComposition.ts`, `resources/js/components/posts/composer/PostComposerDialog.vue`; modify `ImageCropperDialog.vue`, `imageCrop.ts`; test `tests/Browser/PostComposerDialogTest.php`.

**Interfaces:** Dialog emits the spec's `PostComposition`; edit mode receives one post/target. Crop output is an uploaded Asset Library snapshot for only the chosen account.

- [ ] **Step 1: Write failing browser tests** selecting four accounts, overriding one caption/crop/type, returning to step one, and checking inherited fields update while explicit empty/other overrides persist. Removing/re-adding an account resets its override. Upload before save creates an asset but no post. GIF/video cannot use static crop; crop upload failure leaves original selected. The X preview/character count matches sanitized output when link defusing is on.
- [ ] **Step 2: Run** `php artisan test --compact tests/Browser/PostComposerDialogTest.php`; expect missing UI.
- [ ] **Step 3: Implement** base step and expandable per-account step, account-ID-keyed override map, platform previews, existing asset upload, optional aspect ratio/output dimensions in cropper, and disabled final button during submission. Reuse the editor's `xLinkTlds` prop and `defuseXLinks` mirror for X previews/counts. Materialize inheritance by key presence:

```ts
const content = Object.prototype.hasOwnProperty.call(override, 'content') ? override.content! : composition.content;
const media = Object.prototype.hasOwnProperty.call(override, 'media') ? override.media! : composition.media;
```

- [ ] **Step 4: Run** browser test and the typecheck/lint scripts in `package.json`; expect pass.
- [ ] **Step 5: Commit** `feat(posts): compose and crop per account in a dialog`.

### Task 6: Wire web routes and independent cards

**Files:** Create `app/Support/LegacyPostCard.php`; modify `app/Http/Controllers/App/PostController.php`, app post requests, `resources/js/pages/posts/{Index,Calendar,Edit}.vue`, `routes/app.php`; test `tests/Feature/PostControllerTest.php`, `tests/Browser/SidebarMenuTest.php`, `tests/Browser/PostComposerDialogTest.php`.

**Interfaces:** Web store calls `CreatePosts`; web edit calls `UpdatePost`; New Post/Edit open Task 5's dialog. Keep old status/deep-link URLs.

- [ ] **Step 1: Write failing tests**: opening New Post creates no draft, saving four drafts gives four cards, edit affects one, account fixed/type mutable, Calendar opens same composer, old status URLs and sidebar links remain usable, search/labels/pagination still work. A settled legacy multi-target post yields one read-only card per enabled target without changing its DB rows.
- [ ] **Step 2: Run** focused controller/sidebar/composer tests; expect old full-page/autosave behavior.
- [ ] **Step 3: Implement** dialog props and route redirects, replace empty-draft-on-open, use Actions, flatten settled legacy aggregate into one read-only card per enabled target and keep new posts as one card each. Keep `/posts/create`, `/posts/{post}/edit`, the existing sidebar and status deep links. Controller entry pattern:

```php
$posts = CreatePosts::execute($workspace, $request->user(), $request->validated());
return redirect()->route('app.posts.index')->with('created_post_ids', $posts->pluck('id')->all());
```

- [ ] **Step 4: Run** focused tests, TypeScript check, Wayfinder generation/check and localization parity; run Pint. Expect pass.
- [ ] **Step 5: Commit** `feat(posts): use dialog and independent cards`.

### Task 7: Remove grouped internal writes without changing publishers

**Files:** Modify `app/Jobs/Ai/StreamPostCreation.php`, `app/Jobs/Repurpose/ProcessRepurposeItem.php`, `app/Actions/Post/DuplicatePost.php` and every remaining production caller found by search; retire `app/Actions/Post/CreatePost.php`, `SyncPostPlatforms.php`; test affected AI/repurpose/duplicate suites and `tests/Feature/Jobs/PublishPostTest.php`.

**Interfaces:** Internal single destination calls `CreateChannelPost`; multi-destination internal creation calls `CreatePosts`. `PublishPost` and `FinalizePostPublication` keep their interfaces.

- [ ] **Step 1: Write failing tests** for one-target AI and repurpose posts, one-target duplication, per-post failure/retry, scheduler using `posts.scheduled_at`, and legacy in-flight multi-target publication completing unchanged.
- [ ] **Step 2: Run** focused AI/repurpose/duplicate/publish tests; expect placeholder or grouped-write failures.
- [ ] **Step 3: Move** callers to new Actions while preserving `created_via`, provenance, labels, media and schedule. Search all production callers with:

```bash
rg -n 'CreatePost::execute|SyncPostPlatforms::execute' app routes
```

Retire old grouped writers after the search has no production call site. Do not rewrite platform publishers.
- [ ] **Step 4: Run** focused tests and repeat the search; run Pint. Expect pass/no grouped caller.
- [ ] **Step 5: Commit** `refactor(posts): retire grouped post writers`.

### Task 8: Audit and split active legacy rows

**Files:** Create `app/Console/Commands/{AuditLegacyPosts,SplitLegacyActivePosts}.php`; modify web PostController for zero-target draft recovery; test `tests/Feature/Commands/{AuditLegacyPosts,SplitLegacyActivePosts}Test.php`.

**Interfaces:** `php artisan posts:audit-legacy --no-interaction` reports counts/anomalies read-only; `php artisan posts:split-legacy-active --no-interaction` idempotently splits draft/scheduled rows with >1 enabled target. Settled/in-flight rows remain unchanged.

- [ ] **Step 1: Write failing tests** with two enabled targets plus disabled placeholders, labels, media, nested comments. After two runs original post/first target IDs remain, second target ID moves to clone, comment parent IDs map to cloned comments, schedule/labels/media preserved and second run changes nothing. Audit flags zero-target scheduled rows; zero-target drafts are recoverable. Settled/in-flight rows and analytics IDs remain untouched.
- [ ] **Step 2: Run** focused command tests; expect missing commands.
- [ ] **Step 3: Implement** read-only audit, then lock active parent/targets in ID order in a transaction, clone parent/labels/comments quietly, move each additional enabled target, and assert one enabled target per resulting active post. Exclude disabled placeholders, avoid asset duplication and event emission. Zero-target draft recovery creates new destination posts and deletes the empty draft only after success:

```php
$targets = $post->postPlatforms()->enabled()->orderBy('id')->lockForUpdate()->get();
if ($targets->count() <= 1) {
    return;
}
foreach ($targets->skip(1) as $target) {
    $clone = $this->clonePostAndRelationsQuietly($post);
    $target->updateQuietly(['post_id' => $clone->id]);
}
```

- [ ] **Step 4: Run** command tests on PostgreSQL and MySQL and rehearse audit/split on a restored production snapshot; compare post/target counts, IDs, schedules, labels/comments, media and analytics references. Run Pint. Expect pass.
- [ ] **Step 5: Commit** `feat(posts): audit and split editable legacy posts`.

### Task 9: Guarded cutover and regression gate

**Files:** Only fixes exposed by Tasks 1–8; affected tests and deployment record.

**Interfaces:** Enable new write paths only after strict audit/split succeeds.

- [ ] **Step 1: Write a failing regression test** for any defect found in production-snapshot rehearsal. At minimum strict audit must fail on a zero-target scheduled row:

```php
$this->artisan('posts:audit-legacy', ['--strict' => true])->assertFailed();
```

- [ ] **Step 2: Run** that focused test and observe red before its fix. If it was already covered in Task 8 and no new defect appears, use that existing red/green evidence and add no duplicate test.
- [ ] **Step 3: Apply** the smallest regression fix. The branch contains the composer and new write paths together, so cut over during a maintenance window: back up production; pause post writes and `posts:process-scheduled`; drain affected publishing workers; deploy the branch while application ingress remains closed; run the read-only audit, resolve zero-target scheduled and unavailable-target blockers, split editable multi-target rows, then run strict audit and reconcile counts before reopening ingress and resuming workers/scheduler. If the gate fails, keep ingress closed and restore the prior application release against the unchanged backup or fix forward after assessing any writes. Do not run the split on live data outside the maintenance window.
- [ ] **Step 4: Run** affected post/API/MCP/command/browser tests on both engines, TypeScript check/lint, localization parity, Wayfinder generation/check, route listing and Pint. Verify four-account draft/schedule through web, API and MCP yields four IDs; editing one leaves three unchanged; old aggregate GET/history/analytics still load.
- [ ] **Step 5: Commit** regression fixes as `fix(posts): complete independent-post cutover` if any; record actual production audit/split counts, snapshot rehearsal, both-engine results and go/no-go decision in the deployment record when that work is authorized and available.

## Completion criteria

- Every new writer makes one enabled target per post and no disabled placeholders.
- Save Draft and Schedule each make one independent card/ID per account, including two accounts on the same network.
- Account stays fixed on edit; type, caption, media/crop, metadata and schedule affect only that post.
- Existing navigation remains usable, and opening the composer never creates a post.
- Web, API, MCP and internal producers use shared Actions; settled legacy IDs/analytics remain readable.
- Production audit/split is repeatable, rehearsed on a production snapshot and both engines, and blocks enablement on anomalies.

## Release gate still requiring an environment

The local branch can be completed and reviewed without touching production. A release is **not** approved by local tests alone. Before production cutover, run the audit/split against a restored production snapshot on its database engine and check row counts, target IDs, schedules, media, labels, comments, history and analytics references. Run the suite on MySQL as well as PostgreSQL. Record those results and a fresh production backup before entering the maintenance window above. No production audit, split or deployment has been run as part of local implementation.
