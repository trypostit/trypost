<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project keeps committed, area-grouped rules in `.ai/rules` (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>

# Project-Specific Rules

## Frontend (Vue/TypeScript)

- Always use arrow functions in Vue components and TypeScript files. Never use `function` declarations.
- Template event handlers call a named function (`@click="openCreateDialog"`), never an inline statement that mutates state (`@click="isCreateDialogOpen = true"`). Opening something usually grows a second step later (reset a form, focus, track an event), and a named function is where that goes. Passing an argument through is fine: `@click="openEditDialog(label)"`.

## Inertia SSR

- This project does **not** run Inertia SSR. `config/inertia.php` defaults `ssr.enabled` to `false` and nothing in the repo sets `INERTIA_SSR_ENABLED`.
- Keep it off. With it on, every test rendering an Inertia page issues a real HTTP request to the SSR endpoint, which fails silently and falls back to client rendering — slow, and it hides missing `Http::fake()` stubs.
- The build wiring is still shipped (`resources/js/ssr.ts`, `vite.config.ts`, `npm run build:ssr` in `docker/Dockerfile`). Turning SSR on means building that bundle and running `inertia:start-ssr` alongside the app, not just flipping the env.

## Dialog and slide-over actions

In dialogs and slide-overs with Cancel and a primary action, render **Cancel first, primary action last** in the DOM and visually. On desktop, Cancel belongs to the left of the primary action; on mobile, the primary action remains last. Apply this to destructive confirmations as well. Do not reverse the order only with CSS, because keyboard and screen-reader order must match what users see. If there are other secondary actions, place them between Cancel and the primary action.

## Delete confirmations (type-to-confirm)

**Typing to confirm is reserved for critical actions.** Everything else — posts, ideas, templates and other everyday content — uses a plain confirmation dialog (title, description, Cancel, destructive action) with no typing. The dialog states what will be removed and nothing more: there is no generic "This action cannot be undone" line.

Critical means losing it breaks something outside the item itself or cannot be rebuilt by the user in a minute. Only these ask the user to type:

- **The resource's name or identity:** deleting a workspace (its name), removing a member or invitation (their email) and removing an MCP client (its name).
- **A fixed keyword:** deleting or regenerating an API key (`REGENERATE` for the latter), because integrations stop working at once, and disconnecting a channel (`DISCONNECT`), because its queue and automations stop.

Do not add typing to a new dialog unless it meets that bar; when in doubt, ask.

Typing the exact name is the stronger guard for the identity-bound ones, on purpose; do not switch those to the keyword. When the keyword is used:

- The keyword is translated and **always fully uppercase** in every locale (`DELETE`, `EXCLUIR`, `ELIMINAR`, …), shown uppercase in the helper text, and compared **case-sensitively** after trimming: `delete` or `excluir` must not confirm.
- It comes from one shared lang key in all 16 locales — never a literal, and never a per-feature copy of the word.
- Resolve it with `$t` in the template, not `trans()` in script (see `.ai/rules/js.md`).
- Regenerating an API key uses its own keyword, `settings.api_keys.regenerate_modal.keyword` (`REGENERATE`, `REGENERAR`, …), under the same rules.
- **Exception — disconnecting a channel** (`DisconnectChannelDialog`, user decision October 2026): its keyword, `channels.disconnect_modal.keyword`, is translated and **lowercase** (`disconnect`, `desconectar`, …), shown as the input placeholder and in `Type "disconnect" to confirm.`, and still compared case-sensitively after trimming (`DISCONNECT` must not confirm). The dialog ends its description with a bold `This cannot be undone.` and offers **Refresh connection** before disconnecting. Do not carry either into the other dialogs.

## Translated copy must fit its UI slot

Every lang string is rendered in all 16 locales, and the longest one decides the layout. Menu items, buttons, tabs, badges and other single-line controls must not wrap onto a second line in any locale.

- When adding or changing a key, check the longest translations (French, German, Ukrainian, Russian, Polish and Portuguese usually run longest) against the slot, and shorten the copy in that locale rather than letting it wrap. A shorter natural phrase beats a literal one (`Ajustes do workspace`, not `Configurações do workspace`).
- Never fix a wrap by truncating the text or widening one locale's layout; the label has to stay readable everywhere.
- Cover dense menus with a browser test that renders every `Locale` case and fails on wrapped items — `tests/Browser/SidebarMenuTest.php` ("no sidebar menu item wraps onto a second line in any language") is the pattern.
- A settings page description (the line under the page title in `SettingsLayout`) stays on one line in every locale, next to the header action. When adding a settings page with a description, add it to the dataset in `tests/Browser/SettingsDescriptionTest.php`, which measures all 16 translations in place.

## AI agents (`app/Ai/Agents`)

- **Never** embed prompts in PHP (`<<<PROMPT`, heredocs, or long string literals in `instructions()`).
- Put system/instruction text in Blade under `resources/views/prompts/` (e.g. `prompts.post_content.assistant`, `prompts.post_image.alt_text`).
- In `instructions()`, return `view('prompts....', [...])->render()` and pass only the variables the Blade file needs — same pattern as `PostWritingAssistant` and `MediaAltTextGenerator`.

## AI language

- There is no workspace language. Every AI prompt receives `{{ $language }}` from `Locale::promptLanguage()` of the user the call runs for: the requesting user on the web, the post author for Google Business `languageCode` (`bcp47()`), the repurpose creator for Repurpose. Fall back to `Locale::DEFAULT` when that user is gone.
- A rewrite of existing text (shorten, rephrase, tone) keeps the text's own language; only new text is written in `{{ $language }}`.
- AI is web only: no REST API endpoint and no MCP tool calls an AI agent.

## Stripe Checkout (env knobs)

Checkout options are configured only via env — do not hardcode trial/coupon/promo behavior in controllers. All of it goes through `App\Support\Billing\ConfigureSubscriptionCheckout` (called from `StartSubscriptionCheckout`).

| Env | Config | Default | Effect |
| --- | --- | --- | --- |
| `REQUIRE_CARD_FOR_TRIAL` | `trypost.billing.require_card_for_trial` | `true` | `true`: app access only after Stripe Checkout (no generic signup trial). `false`: generic `accounts.trial_ends_at` trial without a card |
| `CASHIER_TRIAL_DAYS` | `cashier.trial_days` | `8` | Card-required Checkout: `trialDays(N)` for **first-time** subscribers when no first-month coupon is applied (`0` = off). Re-subscribers skip trial. No-card mode: length of the generic signup trial |
| `STRIPE_SOCIALS_FIRST_MONTH_COUPON_ID` | `cashier.first_month_coupon_ids.socials` | empty | Optional. `$18` off Socials monthly (`$19` → `$1`). When set for a qualifying first-time **monthly** checkout of that plan, applies `withCoupon` and **skips** trial. Empty = trial mode |
| `STRIPE_WORKSPACES_FIRST_MONTH_COUPON_ID` | `cashier.first_month_coupon_ids.workspaces` | empty | Optional. `$88` off Workspaces monthly (`$99` → `$1`). Same qualification as the Socials coupon. Never reuse one coupon on the other plan |
| `CASHIER_ALLOW_PROMOTION_CODES` | `cashier.allow_promotion_codes` | `false` | When `true` and no coupon is applied, show the Checkout promo-code field |

Standing constraints:
- Stripe rejects `discounts` (coupon) and `allow_promotion_codes` on the same session — if both would apply, `ConfigureSubscriptionCheckout` must throw (fail loud). Never “prefer one silently.” Envs may both be set when the account does **not** qualify for the coupon (no throw).
- A set first-month coupon wins over trial (`trialDays` is skipped for that checkout).
- Empty coupon + card required + first-time must use `trialDays` — do **not** reintroduce a required-coupon throw.
- Coupon qualification stays: card required, no prior real subscription (`incomplete` / `incomplete_expired` still qualify), **and** the checkout price is that plan's **monthly** price. Workspace count is irrelevant — Socials is already capped at one, and a first-time Workspaces subscriber qualifies the same way.
- First-month coupons are **per plan**. Socials is `$18` off, Workspaces is `$88` off. Never apply one plan's coupon to the other price, and never apply either coupon to a yearly price — `$190 − $18` is not `$1`.
- Welcome checkout (`app.welcome.plan`) is monthly only. Yearly stays on the billing change-plan picker for existing subscribers (they do not get a first-month coupon).
- Prefer documenting durable billing decisions here (and in `AGENTS.md`) — do **not** create a `.ai/` rules folder for this project.

## Plans and the workspace limit

TryPost sells two plans. Both are flat: Stripe subscription **quantity is never
used** — `syncWorkspaceQuantity()` was removed with the per-workspace model.

| slug | name | price | workspace_limit |
| --- | --- | --- | --- |
| `socials` | Socials | $19/mo, $190/yr | 1 |
| `workspaces` | Workspaces | $99/mo, $990/yr | `null` (unlimited) |
| `workspace` | Workspace (legacy, archived) | $12/mo per workspace | 1 |

- The cap lives in `plans.workspace_limit`, **not** in code. `null` on that
  column means unlimited. Read it through `Account::workspaceLimit()` /
  `Account::canCreateWorkspace()` — never compare `plan->slug` to decide what
  an account may do. A **missing** `plan_id` is not unlimited: it may create
  only the signup workspace (`count === 0`).
- `WorkspacePolicy::create()` is owner-only. The cap is not a permission: GET
  `/workspaces/create` still renders at the cap (the upgrade dialog opens on
  submit). POST is redirected back to create with `workspaces.limit_reached`,
  not 403'd.
- First-month coupon qualification is card required + first-time subscriber +
  that plan's monthly price. Workspace count is not part of it.
  (`incomplete` / `incomplete_expired` still qualify; coupon +
  `allow_promotion_codes` still throws.) Each plan has its own coupon.
- Welcome is monthly only so the `$1` first month can exist. Billing keeps
  yearly for subscribers swapping interval.
- The legacy plan is archived: it never appears in the picker, so nobody can move
  back to it. Its `workspace_limit` is 1 because it costs less than Socials.
- Plan choice is a welcome step (`app.welcome.plan`) and the same `PlanPicker`
  component drives upgrade/downgrade on the billing page (`app.billing.change-plan`).
  A change is a `swap()` to another price id. `accounts.plan_id` is written by
  `changePlan` after a successful `swap()` only when the subscription is
  `active` or `trialing` (so create works before the webhook). Welcome checkout
  never writes it — `StartSubscriptionCheckout` **clears** a leftover `plan_id`
  so Processing cannot treat a stale Workspaces row as paid. The
  `customer.subscription.created` / `updated` webhook writes on `active` /
  `trialing`, **clears** on `unpaid` / `canceled` / `incomplete_expired`, and
  leaves `past_due` / `incomplete` alone. `deleted` always clears.
- **AI usage is not recorded or metered.** There are no credits, no usage log
  (`workspace_ai_usages` was dropped in September 2026) and no ceiling.
  `AccountPolicy::useAi` checks app access and nothing else. Do not
  reintroduce per-call usage recording.

## Multiple social accounts per network

A workspace may connect as many accounts of the same network as it wants (two
LinkedIns, three Instagrams, ...). There is **no** one-per-network rule and no
flag for it: `ALLOW_MULTIPLE_SOCIAL_ACCOUNTS` was removed in September 2026, along
with `SocialAccount::occupiesNetwork()` and the observer's `creating` guard. Do
not reintroduce either. What still holds:

- Reconnecting the same `platform` + `platform_user_id` updates the existing row
 (`SocialAccount::connectIdentity()`), and the identity pickers drop identities
 already connected on that network, so one identity can never be seated twice
 under two platforms of one network (Instagram directly and via Facebook).
- `Platform::network()` still collapses variants (LinkedIn profile/page,
 Instagram standalone/Facebook) — that grouping drives the accounts UI, not a cap.
- `accounts.popup_callback.network_taken` / `accounts.telegram.network_taken` stay
 in the lang files because `NetworkAlreadyConnectedException` still uses the key
 for a reconnect that collides on the unique identity index.

## Queue slots

A queued post keeps its slot. The queue is **not** packed into the first free
slots any more (user decision 2026-10-02: a post dropped on a slot on the channel
page must stay there). The rule lives in `ReflowChannelQueue` (`handleLocked()` and
`isFreeSlot()`) and `Post::scopeOccupyingSlotsOn`:

- A queued post stays while its instant is still a slot of the channel's posting
  schedule and more than a minute out. Nothing moves it implicitly.
- Next takes the **first free** slot (gaps first). Top takes the first slot not
  held by a custom post and shifts the contiguous run of queued posts behind it
  to the next gap only.
- Reordering (drag one queued post onto another, Move up/down) swaps the posts
  among the instants they already hold.
- Deleting, drafting, publishing now or re-timing a queued post leaves its slot
  free; there is no pull-forward reflow.
- A schedule change re-places only posts whose slot vanished, into the first
  free slots in order. A time zone change first gives every queued post the slot
  at its same local clock time in the new zone (even when its old instant is
  another slot there), then the first free slots in order; pending holders follow the same rule. With
  no slot left a queued post becomes custom at its time.
- Any scheduled post (custom too) on a slot instant occupies it: no "+ New" there
  and the queue never double-books it.
- A queue request pending approval that holds an instant (a queued post edited, or a
  "+ New" slot post saved, by a member who needs approval) keeps reserving that slot for
  placement, reflow, move-to-slot, "+ New" and the composer shortcuts
  (`Post::scopeOccupyingSlotsOn`). Approving keeps it there while the slot still
  exists and is free (`ReflowChannelQueue::isFreeSlot`), else it takes the first
  free slot; rejecting or deleting frees it.
- The Queue timeline shows a reserved pending holder as its post card at that
  instant (`queue.pending`, visibility per `Post::scopeVisiblePendingApprovalsFor`):
  approve/reject for approvers, edit for the requester, never draggable. A viewer
  who may not see the request sees a gap there, never "+ New".
- "+ New" on a free slot (queue list and calendar chips) opens the composer at that
  slot's instant; saving it unchanged (same single channel, same instant) sends
  `queue_slot`, and `CreatePosts` stores it in that slot as a queue post under the
  channel lock, failing with `queue_slot` when `ReflowChannelQueue::isFreeSlot()`
  says it is taken. Changing the time or channels falls back to the normal rules.

## Disconnecting a channel deletes its posts

`SocialController@disconnect` runs `DeleteChannelPosts::forAccount()` before it
deletes the account: every post of that channel (drafts, scheduled, pending
approval, failed, sent and imported) goes with its media. There is no orphaned
history — a post without a channel is a history that no longer exists.

- Quiet: no `post.deleted` webhook or notification per post.
- A legacy post with another live, enabled target keeps that target and loses
  only this one (a Publishing post is re-settled through `FinalizePostPublication`).
- Analytics publications stay and are only unlinked, never dismissed, so
  reconnecting the same identity re-imports its recent posts once.
- Orphans left by disconnects before this rule are removed once by the release
  script (`release:trypost-2`, step `posts:purge-orphaned`, in
  `app/Console/Commands/Scripts/`), through the same action.

## Member permissions and post approvals

A workspace membership (`user_workspace`) and an invite carry two flags:
`is_admin` (manages members, settings, channels) and `requires_approval` (the
member's posts need approval; always `false` for admins). There are no roles any
more (Admin / Member / Viewer) — `App\Enums\UserWorkspace\Role` was removed in
October 2026; do not reintroduce it. The account owner is always an admin and
publishes directly.

- Read abilities through `User::isWorkspaceAdmin()`, `requiresApprovalIn()`,
  `canPublishDirectlyIn()` and the `WorkspacePolicy` abilities (`createPost` =
  any member, `publishDirectly` / `approvePosts` = owner or member who publishes
  directly). On the client, `useWorkspaceAbilities()`.
- `App\Support\PostApproval` is the only approval rule. `CreatePosts`,
  `UpdatePost` and `ProcessRepurposeItem` decide through
  `PostApproval::isRequired()` with the acting user and store
  `Status::PendingApproval` instead of scheduling, queueing or publishing;
  `CreateChannelPost` stores the status its caller decided. `ApprovePost` replays
  the request through `UpdatePost`; `RejectPost` writes `Draft` with
  `PostApproval::transition()`. Media attached by the MCP/API attach tools goes
  through `AppendPostMedia`, which sends an approved, scheduled post back through
  `UpdatePost` when `PostApproval::isRequired()` says so. Never add a second check
  in a controller, API or MCP tool. System callers (`ScheduleNextOccurrence`, recovery) pass no actor and are
  never gated, so an approved recurring series keeps publishing.
- `posts.approval_requested_by` records who asked, which is not always the author
  (a member who needs approval editing someone else's approved post). It is set
  and cleared only by `PostApproval::transition()`; read it through
  `Post::approvalRequester()` / `scopeApprovalRequestedBy()`, which fall back to
  `posts.user_id` when it is null. Requesters see only their own requests in the
  Approvals tab and the calendar (`scopeVisiblePendingApprovalsFor()`), and the
  decision email goes to the requester.
- Approving, rejecting and any `UpdatePost` of a pending post run under the
  post's approval lock (`PostApproval::whilePending()`), which re-reads the post
  and fails with `posts.approvals.errors.not_pending` when it is no longer pending.
  `AppendPostMedia` takes the same lock for every post (`PostApproval::locked()`,
  no status requirement) and decides from the status re-read there. The lock is
  re-entrant within one process, so `ApprovePost` can call `UpdatePost`.
- A pending queue request has `schedule_mode = queue`, no `scheduled_at` and its
  position in `approval_queue_position`; it is placed in the queue only when
  approved. Approving replays the stored request through `UpdatePost` as the
  approver (`ApprovePost`), which records `approved_by` / `approved_at`.
- A repurpose item created by a member who requires approval stays marked
  Published while its posts are pending approval: the item records the hand-off to
  the queue, not the network publication.
- Approval emails go through `SendNotification` with `Type::Collaboration`.
  Requests email every approver except the requester, list only the posts still
  pending when the job runs and are dropped when none is; decisions are grouped
  per `post_group_id`, approver and requester by `NotifyApprovalDecision`
  (cache + one unique delayed job).

## UI locale (`users.locale`)

The user's UI language lives in the database, on `users.locale`, cast to
`App\Enums\User\Locale`. That enum is the single source of truth for the
supported locales — there is no `config/languages.php` any more, and a case is
only valid if `lang/<value>` exists (`tests/Unit/Enums/User/LocaleTest.php`
enforces that).

- **There is no `locale` cookie.** The app stored the locale in the database
  until March 2026, moved it to a forever cookie, and moved it back here. Do not
  reintroduce the cookie: a second source of truth is what made the switcher and
  the register page disagree the first time.
- **`SetLocale` has exactly one rule:** an authenticated request renders in
  `Auth::user()->locale`, everything else in `Locale::DEFAULT`. It does not look
  at the request body, old input or `Accept-Language`. A logged-out visitor
  therefore always gets English from the server, including validation messages.
- **The auth switcher is client-side only.** It calls `loadLanguageAsync`, so
  changing language on login or register costs no round trip and touches nothing
  on the server. `useGuestLocale` holds the choice at module scope so it survives
  Inertia navigation between those screens, and it sets `document.documentElement.dir`
  from the picked language — for a guest that is the *only* source of direction,
  since the middleware renders `htmlDir` from the default on every request.
- **Register submits `locale` as a required hidden field** and creates the user
  with it. **Login submits it only once the visitor picks a language** — the
  field goes out empty otherwise, and an empty value leaves `users.locale`
  untouched. This asymmetry is load-bearing: the login screen always renders in
  `Locale::DEFAULT`, so an always-sent field would reset every non-English user
  to English on each login. Forgot and reset password do not send it at all.
- Google and GitHub signups store `Locale::DEFAULT`: they have no picker, and the
  OAuth callback tells you nothing reliable about the person.

## User preferences (`/settings/preferences`)

Theme, time zone, time format, start of week and the composer's default posting
action live on `users` (`theme`, `timezone`, `time_format`, `week_starts_on`,
`default_post_action`, cast to the enums in `App\Enums\User`). Language is edited
on the same page but still goes through `ProfileController@updateLanguage` so
`SyncUser` keeps firing. Each control saves on its own (`PATCH`
`app.settings.preferences.update`, every rule `sometimes`).
`/settings/profile/notifications` follows the same rule: each switch `PATCH`es
only its field, there is no Save button, no success toast, and a failed save
rolls the switch back and toasts.

- **Three zones.** The channel zone (`social_accounts.timezone`) decides when a
  post publishes: queue slots and recurrence (see "Queue slots"). The user zone
  (`users.timezone`) is the default display zone, the composer zone for mixed
  channels, the zone of Insights ranges and of every email, and the default for a
  newly connected channel. The display zone (`?tz` → `publish.tz` → user zone) is
  only what the list and calendar show. The browser zone never shows or takes a
  time; it is only the suggested value at signup.
- **Frontend zone.** `@/date`'s `getUserTimezone()` returns `auth.user.timezone`
  (the `userTimezone` ref in `resources/js/preferences.ts`); "now" comes from
  `userNow()`, never the browser clock. Times shown next to display-zone cards go
  through `useViewTimezone()`: the page's display zone where a picker exists
  (publish list and calendar call `provideViewTimezone()`), the user zone
  elsewhere.
- **Composer zone** (`useComposerTimezone()`): all selected channels share one
  zone → that zone; mixed zones or no channel → the user zone. The zone label sits
  next to the time input. The composer keeps the UTC instant as its state
  (`scheduledInstant`) and shows it as a wall clock in the composer zone
  (`utcToWallClock()`), so a selection change never moves the instant; it sends a
  UTC instant (the backend contract is unchanged). Wall clocks handed to the
  composer (`initialDate`, edit pre-fill, drafts) are in the user zone, and the
  custom pre-fill is `date.nextFullHour` of the composer zone. With exactly one
  channel the picker offers that channel's posting slots for the picked day,
  disabling those already held by a scheduled post (`taken_slots`, composer
  props only, like `posting_schedule`).
  Calendar clicks pass the clicked instant (a day click is 09:00 of the display
  zone). Approving with a new time uses the post's channel zone.
- **Time format:** `users.time_format` is NOT NULL and only `12h` or `24h`;
  `TimeFormat::DEFAULT` is 12h. There is no language-following state: signup
  stores the browser's hour cycle, else `TimeFormat::forLocale()`. Every
  displayed time goes through `@/date` (`timeToken()`, `formatHourOption()`, …),
  which reads it from `resources/js/preferences.ts`; never format a clock time
  with `LT`, `LLL` or a literal `HH:mm` in a component. Hour selects keep
  `00`–`23` as values and only change the labels.
- **Start of week:** only Sunday and Monday exist. `preferences.ts` applies it to
  every loaded dayjs locale, so `startOf('week')` follows it; the Reka calendars
  default to it; the posting-schedule grid orders its columns with
  `orderedWeekdays()`; `BuildCalendarPageProps` passes
  `WeekStart::firstDay()/lastDay()` to Carbon. The weekly posting goal
  (`CountPostsSentThisWeek`) and the Insights weekly buckets (`PeriodBuckets`)
  follow the **viewing** user's `week_starts_on`, never Monday or the UI language.
- **Signup detection:** email, Google and GitHub signups store the browser zone,
  the week start (`Intl.Locale` week info, else the Sunday-first zone list in
  `resources/js/lib/detectPreferences.ts`) and the hour cycle. OAuth sends them as
  query params on the start route and `PreservesSignupPreferences` keeps them in
  the session; `CreateUser` validates each one. Fallbacks: UTC, Monday, the
  language clock.
- **Emails** render every time with `App\Support\Mail\RecipientTime`: the
  recipient's zone, their clock, and the zone name.
- **Channel zone change** on the channel settings page asks for confirmation
  first (Cancel first, no typing); queued posts reflow into the new zone's slots,
  custom-time posts keep their instant.
- **Theme:** the root Blade renders `data-theme` and the `dark` class, and an
  inline script resolves `system` before first paint and follows OS changes.
  Guests always get light.
- **Default posting action:** a queue default (`next`/`top`) only applies when
  every selected channel has posting times; otherwise the composer falls back
  as before. `custom` pre-fills the next full hour of the composer zone.

## PostHog person properties

`App\Jobs\PostHog\SyncUser` is the only place that writes person properties, and
the distinction between its two buckets is load-bearing:

- **`$set_once`** — first-touch facts that must never be rewritten: `signed_up_at`
  and the attribution keys (`utm_*`, `gclid`, `fbclid`, …). A later sync must not
  overwrite where a user originally came from.
- **Top level** — current state, overwritten on every sync: `$email`, `$name`, and
  `locale`.

`locale` mirrors `users.locale` and is what the PostHog email automations (the
onboarding cadence and friends) read to decide which translation to send, so it
has to reflect the language the user picked *now* — never `$set_once`. Anything
that changes `users.locale` must dispatch `SyncUser`; `ProfileController@updateLanguage`
does, and registration already does via `CreateUser`.

Do not reach for `$browser_language` / `$browser_language_prefix` instead. They
are captured automatically by posthog-js but only as **event** properties on
`$pageview`, so they cannot segment a person or feed an automation — and they
report the browser's language at that pageview, not the language the user chose.

Before adding a person property, check what the project already has with the
PostHog MCP (`read-data-schema` with `{"kind": "entity_properties", "entity":
"person"}`) rather than guessing a name; overwriting an existing property is
silent and retroactive.

## Emails (Maizzle + i18n)

**Every email the app sends is fully translated into all 16 supported locales,
and every new email must be too.** There is no English-only email left in the
codebase, and adding one is a regression — not a gap to fill in later.

**Every email is built with Maizzle, and every string in it goes through
`__()`.** Both halves are mandatory, with no exceptions for "small",
"transactional", "internal" or "temporary" emails:

- **Maizzle, always.** Email HTML is authored in `maizzle/templates/<slug>.html`
  and compiled to `resources/views/mail/<slug>.blade.php` by
  `cd maizzle && npm run build`. Never hand-write a Blade view under
  `resources/views/mail/`, never use Laravel's markdown mailables, and **never
  edit the Blade files** — they are build output and the next build overwrites
  them. A one-off email written outside Maizzle loses the shared layout, header,
  footer and inlined CSS, and silently drops out of the translation workflow.
- **i18n, always.** No user-visible string may be a literal — not in the
  template, not in the Mailable, not in a notification closure. Subject, preview
  text, headings, body copy, button labels and footer chrome all resolve through
  `__()` / `trans_choice()` against `lang/*/mail.php`, in all 16 locales. A
  literal is invisible to `LocalizationParityTest`, so it ships and stays broken.

How that works in practice:

- **Maizzle eats one `{`-level.** Write `@{{ ... }}` in the template to emit Blade
  `{{ ... }}`; write `{!! ... !!}` as-is (it passes through via
  `posthtml.expressions.unescapeDelimiters`). A `{{ }}` written directly is
  evaluated by Maizzle at build time and disappears.
- **Copy lives in the template, not in the Mailable.** Body text is
  `@{{ __('mail.<slug>.<key>') }}` inside the template; the Mailable resolves only
  the envelope metadata the layout needs — `subject`, `title`, `previewText` — and
  otherwise passes **data** (`$workspaceName`, `$endpoint`, `$publishedPlatforms`),
  never sentences. Injecting resolved strings as view variables is what the
  disconnected-connections email used to do, and it meant every new sentence had
  to be threaded through PHP while the template gave no hint it was translatable.
- **One `lang/*/mail.php` block per template**, keyed by the slug with dashes as
  underscores (`post-published.html` => `post_published`). Shared chrome (footer
  tagline, sign-off) lives under `layout`. Keys go in all 16 locales;
  `LocalizationParityTest` fails on drift. Feature lang files must not carry email
  copy — `webhooks.mail.*` moved here for that reason.
- **The recipient's locale is automatic.** `User` implements
  `HasLocalePreference`, so `Mail::to($user)` and `$user->notify(...)` localize on
  their own; never add a `->locale()` call at a send site. Two consequences:
  `Mail::to($user->email)` (a bare string) silently loses it, so always pass the
  model; and the invite is the one exception — the recipient has no account yet,
  so `CreateInvite` explicitly sends in the inviter's locale.
- **`trans_choice` must handle zero.** The last plural segment is `[0,*]`, not
  `[2,*]`: `PostAtRisk` can report a count of 0 when rows disappear between
  dispatch and send, and an unmatched count renders a stray leading space.

New email checklist: add the template, add the `mail.<slug>` block to all 16
locales, write a Mailable that passes data plus the three metadata strings, send
with `Mail::to($user)`, run the Maizzle build, and cover it with a render test —
`tests/Feature/Mail/MailRenderingTest.php` exists because copy moving into the
view turns a forgotten variable into a runtime-only failure.

## Icons (@tabler/icons-vue)

- This project uses `@tabler/icons-vue` for all icons. NEVER use `lucide-vue-next`.
- All Tabler icons are prefixed with `Icon`, e.g. `IconCheck`, `IconChevronRight`, `IconMail`.
- Import icons from `@tabler/icons-vue`: `import { IconCheck, IconX } from '@tabler/icons-vue'`.
- Browse available icons at https://tabler.io/icons

## Dates

- For date manipulation, always use `@/dayjs` (pre-configured dayjs instance with utc, timezone, relativeTime plugins).
- For formatting dates for display (formatDate, formatDateTime, formatTime, diffForHumans), always use `@/date` which centralizes all formatting logic with proper timezone handling.
- Never use raw `new Date()` for date calculations — use dayjs.

## Routing (Wayfinder)

- This project uses Laravel Wayfinder for type-safe frontend routing.
- ALWAYS use Wayfinder-generated route helpers in Vue pages (e.g. `register()`, `login()`, `dashboard()`). NEVER hardcode URL strings like `href="/register"`.
- After creating or modifying PHP routes/controllers, run `php artisan wayfinder:generate` to regenerate the TypeScript route helpers.
- Import routes from `@/routes/...` (e.g. `import { store } from '@/routes/login'`).

## Pagination

- Always use normal pagination (`->paginate()`). NEVER use cursor pagination (`->cursorPaginate()`).
- All paginated lists must use Inertia's scroll pagination (`Inertia::scroll()` on the backend with `<InfiniteScroll>` on the frontend). NEVER use traditional page-based pagination with page links/buttons.
- The page size ALWAYS comes from `config('app.pagination.default')` — never a magic number, and never a `perPage`/`per_page` value supplied by the request or frontend. Action/service list methods must NOT accept a `$perPage` parameter; call `->paginate((int) config('app.pagination.default'))` directly.
    - **This includes the public REST API** (`app/Http/Controllers/Api`). It used to pin its own page size of 15 as a stable contract; that exception is gone, so a list endpoint reads the same config as everything else. Changing `app.pagination.default` therefore changes the API's page size too — deliberate, and the reason a list response always carries `meta.per_page` for clients to read rather than assume.

## Empty states on list pages

A list page answers two different questions, and each needs its own data:

- **Is there anything at all?** The controller sends `hasData`, read from the database without the search or filters (`$workspace->labels()->exists()`). `false` shows the first-use empty state: illustration and create button only, with no header or search (`SettingsLayout :centered`).
- **Did this search or page return anything?** The paginated list decides it. Empty while `hasData` is `true` means no results: header and search stay, and the empty state shows the same illustration without the create button.

Never derive the first-use state from the list or from the search box. A paginator's `total()` counts the filtered query, the scroll metadata carries no total at all, and the search input changes before the reload arrives, so any of them flashes or shows the wrong state while a search is active. `labels/Index.vue` is the reference.

## Form Validation

- NEVER use HTML5 validation attributes (`required`, `minlength`, `pattern`, etc.) on form inputs. Always rely solely on backend validation.

## Backend Validation

- Validation rules always live in a dedicated `Illuminate\Foundation\Http\FormRequest` subclass under `app/Http/Requests/App/<Group>/`. Controller actions must type-hint the FormRequest as the parameter — NEVER call `$request->validate([...])` inline in the controller.
- Naming: `<Verb><Resource>Request.php` (e.g. `StorePostRequest`, `UpdatePostRequest`, `LinkPreviewRequest`).

## Database engines (PostgreSQL + MySQL)

TryPost runs on **both PostgreSQL and MySQL**. Cloud runs PostgreSQL; a self-hosted install may pick either. Every query, migration, and test must work on both — the suite is expected to be green on each.

- **What the app supports is the intersection of the two engines, never the superset of one.** When they differ, take the narrower behaviour — a feature that only holds on PostgreSQL is a feature TryPost does not have.
- Never use an engine-specific operator or function. Search uses `whereLike()` (Laravel handles the case-insensitive form per driver), never `ilike` or a raw `LOWER(...)` comparison.
- Traps that only surface on MySQL:
    - **JSON object key order is not preserved.** MySQL reorders object keys on storage (by length, then lexicographically); PostgreSQL keeps insertion order. Assert JSON read back from the database with `toEqual` (recursive, order-independent), never `toBe`/`assertSame`. Array *element* order is preserved on both.
    - **`$table->timestamp()` tops out at 2038-01-19.** PostgreSQL has no such limit, so 2038-01-19 is the app's ceiling: nothing written to a `timestamp()` column may go past it — scheduled posts, expiry sentinels and test fixtures alike. `2037-12-31` reads as "far future" and works on both. Do not widen a column to escape the limit without a deliberate decision; it changes what self-hosted MySQL installs can store.
    - **Raw query-builder reads carry no Eloquent cast**, so the driver's native shape leaks through: `DB::table(...)->value('some_bool')` is `true` on PostgreSQL and `1` on MySQL. Read through the model, or use `assertDatabaseHas`.
    - **Identifier quoting differs** — PostgreSQL emits `"post_platforms"`, MySQL emits backticks. Never match logged SQL (`DB::listen`) against a quoted identifier.
    - **MySQL refuses to drop the only index backing a foreign key** (SQLSTATE `1553`). A migration `down()` that drops a unique whose leftmost prefix is an FK column must create a standalone index for that column first.
    - **DDL implicitly commits**, which defeats `RefreshDatabase`'s rollback: schema changes made inside a test leak into the tests that follow. Keep them idempotent.

## Per-Platform Post Meta (`PostPlatform.meta`)

- All `platforms.*.meta` validation (the parent array rule AND every per-platform sub-key: `aspect_ratio`, TikTok `privacy_level`/flags, Pinterest `board_id`, Discord `channel_id`/`mentions`/`embeds`, etc.) lives in ONE place: `App\Support\PostPlatformMetaRules`.
    - Every post create/update entry point — web (`App\Http\Requests\App\Post\UpdatePostRequest`), public API (`App\Http\Requests\Api\Post\{Store,Update}PostRequest`), and MCP (`App\Mcp\Tools\Post\{Create,Update}PostTool`) — spreads `...PostPlatformMetaRules::rules()`. NEVER add a per-platform meta rule inline to a single request/tool.
    - Why: `FormRequest::validated()` (and MCP `$request->validate()`) STRIPS any key without a rule. A meta field defined in only one entry point is silently dropped everywhere else — which is exactly how Discord/Pinterest/TikTok meta was lost via API/MCP before this was centralized.
- Required-on-publish (meta a platform needs to publish, e.g. Discord `channel_id`) also lives there: `addRequiredOnPublishErrors()` for request-driven flows (web/API update `withValidator`), `assertStoredPostPublishable()` for flows that publish stored state without resubmitting platforms (MCP `PublishPostTool`). Add new required-meta rules to `requiredMetaViolation()`, not inline.
- When adding a new platform's meta field, add it (and any publish requirement) to `PostPlatformMetaRules` ONLY, and cover it in `tests/Feature/Api/PostApiPlatformMetaTest.php` + `tests/Feature/Mcp/PostPlatformMetaToolTest.php`.
- `PostPlatformMetaRules::formatViolation()` holds meta no post may store, even as a draft (a YouTube title with `<` or `>`). Every create and update path (web, API, MCP, repurpose) goes through `App\Support\PostCompositionValidator`, which runs it and, once the post is scheduled or published, the content limits below.

## Content limits

- **Per account, not per platform.** Read the cap through `SocialAccount::maxContentLength()`. It is the platform's `Platform::maxContentLength()`, except an X account with long posts (`hasXLongPosts()`: a `Basic`, `Premium` or `PremiumPlus` subscription, or `verified_type=business`), which gets 25000. The tier lives in the account meta and is written by `SyncXSubscription`, called from `ConnectionVerifier` on connect and on the daily `social:check-connections`.
- `Post::CONTENT_MAX_LENGTH` (25000 characters of plain text) is the ceiling of any post, enforced by `PostContentFitsMaxLength`.
- `Platform::reservedLength()` counts what the network adds to the text: the Mastodon content warning (trimmed like `Str::trim`) counts toward the 500.
- `Platform::maxHashtags()` is 5 on Instagram (both platforms). It is a **save-time** rule (web, API, MCP), never a publish-time failure: a post stored with more still publishes. Repurpose captions are trimmed to the first 5 by `CaptionAdapter` through `Hashtags::keepFirst()`. Hashtags are counted by `App\Support\Hashtags`, mirrored by `resources/js/lib/hashtags.ts` and kept identical by `HashtagParityTest`.
- A captionless content type (`ContentType::isCaptionless()`: Instagram and Facebook Stories, mirrored by `CAPTIONLESS_CONTENT_TYPES`) sends no text, so it is measured for neither length nor hashtags, at save, at publish or in the composer.
- `App\Rules\ContentFitsPlatformLimits` (save) and `HasSocialHttpClient::validateContentLength()` (publish) measure the same thing; keep them in step. Every counter counts code points (`mb_strlen`; `characterCount()` in `resources/js/lib/characters.ts`), so an emoji is one.

## Thread replies

- Bluesky and Mastodon only (`App\Support\ThreadReplies`), up to 24 replies in `meta.thread_replies`. X threads are not built yet.
- Each segment already live is checkpointed in `post_platforms.error_context.thread_progress` (`App\Support\Social\ThreadProgress`), so a retry resumes instead of re-posting, and a resume keeps the root hash. `posts:retry` keeps the live segments.
- `post_platforms.thread_reply_ids` lists the reply ids so `ImportExternalPosts` does not import TryPost's own replies as new posts.

## Composer steps

- Step 1 is one shared editor. "Customize for each network" opens one card per network, in channel-list order, grouped by `platform`; each card starts as a copy of the shared text.
- Going back to step 1 discards every per-network override, including post type and settings.
- Settings in `ACCOUNT_SCOPED_SETTINGS` (Pinterest, Discord, TikTok) stay per account; every other meta fans out to all accounts of the network.

## Link preview card

- `meta.link_preview === false` (the × on the card) is honoured only by the Facebook post, Bluesky and LinkedIn publishers, through `PostPlatform::attachesLinkPreview()`. Threads has no ×: its API always cards the first link.
- "Replace link preview with media" goes through `LinkPreviewMediaController`.

## YouTube

- Without `meta.title`, the title is the first non-empty line of the post's plain text, with `<` and `>` removed, cut to 100 code points, and no ` #Shorts` (`YouTubeMetadata::title()`); `YouTubeSettings.vue` fills the Title field with the same rule.
- Categories are a fixed list, `App\Enums\YouTube\Category`; the composer reads it from `Platform::publishConfig()` (`categoryOptions`, `defaultCategoryId`). Do not copy it into TypeScript.

## Media Types (image / video / document)

- A media item is one of exactly three types: **image**, **video**, **document** (PDF). There is no standalone "audio" media type (audio exists only as a video voiceover input).
- Media-type detection lives in ONE place per side — NEVER hand-write `type === 'image'`, `mime_type === 'application/pdf'`, `mime.startsWith('video/')`, or extension checks inline.
    - Backend: `App\Enums\Media\Type` — `classify()`, `fromMime()`, `fromExtension()`, `isGif()`, plus the `allowedMimeTypes()` / `extensions()` allow-lists. Use these, never a raw MIME/extension comparison.
    - Frontend: `resources/js/lib/mediaType.ts` — the mirror of the backend enum: the `MediaType` union, `classify()`, `fromMimeType()` (for a browser `File.type`), `fromExtension()`, `isImage()`/`isVideo()`/`isDocument()`/`isGif()`. `@/composables/useMedia` re-exports `isImageMedia`/`isVideoMedia`/`isDocumentMedia` aliases for legacy call sites.
    - Detection trusts the explicit `type` first, then the MIME, then the filename extension — so an item with only a MIME (e.g. AI/Unsplash/Giphy media without a `type`) still classifies correctly. A bare `item.type === 'image'` (with a `v-else` video) silently mis-renders those.
- The `type` field on every media-ish interface is the `MediaType` union, never `string` — `MediaItem`, and any sibling shape (an upload result, an autosaved item, etc.).
- The upload `accept` attribute for "everything we allow" comes from `acceptAttribute()` (frontend) / `Media\Type::allowedMimeTypes()` (backend) — never a hardcoded MIME list. Per-capability `accept` builders driven by content-type rules (e.g. `image/*,video/*`) are fine; those aren't detection.

## Pest / Feature Tests

- ALWAYS use named routes via the `route()` helper in feature tests. NEVER hardcode URL strings like `'/posts/ai/create'`.
    - Example: `$this->postJson(route('app.posts.store'))` instead of `$this->postJson('/posts')`.
    - With params: `route('app.posts.ai.create.finalize', $creationId)`.

## Browser Tests (Pest + Playwright)

Browser tests live in `tests/Browser` and run on `pestphp/pest-plugin-browser` driving Playwright. **Laravel Dusk is not installed** — there is no `DuskTestCase`, no `$browser` object, and no `browse()`. Do not add `dusk="..."` attributes; they select nothing.

- ALWAYS use named routes via `route()`. NEVER hardcode URLs like `'https://trypost.test/login'`.
    - Example: `visit(route('login'))`.
- ALWAYS target elements by `data-testid`. NEVER use CSS classes (`.text-red-600`), tag names, or text strings.
    - `@my-element` resolves to `[data-testid="my-element"]`, so add `data-testid="my-element"` in the Vue component and use `$page->click('@my-element')`.
    - Bind it for repeated elements: `:data-testid="`connect-${platform.value}`"`.
- Assertions do NOT auto-wait on SPA paint. Wait for the element to mount and lay out first — see the `waitFor*TestId()` helper at the top of `tests/Browser/WelcomeConnectTest.php` and copy the pattern under a file-unique name (these helpers are global functions; a duplicated name collides across test files).
- **Never `sleep()` in a browser test.** The HTTP server that serves the page runs inside the same PHP process (an Amp loop that only ticks while Pest awaits Playwright), so a blocking `sleep()` starves every asset request: the page stays blank, the Vue app never mounts, and screenshots come out empty. Poll from the page with `$page->script(...)` (as the `waitFor*TestId()` helpers do) — that keeps the loop running.
- `BrowserTestCase` sets `$fakesVite = false` on purpose: these tests load real built assets, so faking Vite blanks the app.
- End page assertions with `->assertNoJavaScriptErrors()`.
- CI runs them un-parallelised (`php artisan test tests/Browser --compact`) against `npm run build` output, so keep them independent of a running dev server.

## Array Data Access

- In Action classes and similar service classes, ALWAYS use Laravel's `data_get()` helper instead of direct array access.
    - Example: `data_get($data, 'name')` instead of `$data['name']`.
    - Use the third parameter for fallback values: `data_get($data, 'username', $sender->username)` instead of `$data['username'] ?? $sender->username`.

## Eloquent Models & Morph Map

- EVERY Eloquent model in `app/Models` MUST be registered in `Relation::enforceMorphMap([...])` inside `AppServiceProvider::configureMorphMap()`, keyed by a camelCase alias (e.g. `'postPlatform' => PostPlatform::class`).
- When you add a new model, add it to the morph map in the same change. `tests/Unit/MorphMapTest.php` fails if any model is missing.
- The alias is persisted in polymorphic columns, so never rename or remove an existing alias for a model that has stored rows.

## Imports

- NEVER use inline class references (e.g., `\DB::listen`, `\Str::uuid()`). ALWAYS import classes at the top of the file with a `use` statement.
    - PHP: `use Illuminate\Support\Facades\DB;` then `DB::listen(...)`
    - TypeScript/Vue: `import { ref } from 'vue'` then `ref(...)`

## API Response Status Codes

- When returning JSON responses with explicit status codes, always use `Symfony\Component\HttpFoundation\Response` constants instead of magic numbers.
    - Example: `Response::HTTP_CREATED` instead of `201`, `Response::HTTP_NO_CONTENT` instead of `204`.

## String Interpolation

- When injecting variables into strings, prefer **double-quoted interpolation** with curly braces over concatenation with `.`.
    - PHP: `"workspace.{$workspace->id}"` instead of `'workspace.'.$workspace->id`.
    - Use curly braces `{}` even for simple variables to keep the boundary explicit and to allow object/array access without ambiguity.
    - Single quotes are still preferred when the string has no interpolation.

## External Service URLs

- NEVER hardcode third-party API hosts, OAuth endpoints, or per-platform service URLs (e.g. `https://api.x.com/2`, `https://www.linkedin.com/oauth/v2/accessToken`, `https://bsky.social`). They live in `config/trypost.php` under `platforms.<name>` with a matching `env(...)` default, so self-hosted users can override them and we have a single source of truth.
    - Production code: `config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken'`, never the literal URL.
    - Tests: use the same `config(...)` value in `Http::fake([...])` — `Http::fake([config('trypost.platforms.x.api').'/oauth2/token' => ...])`. Tests with hardcoded URLs drift silently when the config changes.
    - Path/route segments after the host (e.g. `/oauth/v2/accessToken`, `/xrpc/com.atproto.server.refreshSession`) are part of the provider's protocol spec — those stay inline next to the call. Only the host comes from config.

## Social Platform API Documentation (official sources)

**Always consult the official docs below before implementing or changing OAuth, publishing, deletion, rate-limit, or any other platform-specific behavior — never guess endpoints, scopes, rate limits, or capabilities from memory.** APIs shift over time; a behavior confirmed in a past session may no longer hold. One entry per social network we integrate with:

- **Facebook / Instagram / Threads (Meta)**: all three share the Graph API error format (`error.code`, `error.type`).
    - General error handling / codes 1, 2, 4, 17, 190: https://developers.facebook.com/docs/graph-api/guides/error-handling/
    - Rate limiting — Platform Rate Limits (app/user tokens, codes 4/17) vs. Business Use Case (BUC) Rate Limits (Page/system-user tokens, codes 80000–80014 — e.g. `80001` Pages API, `80002` Instagram Platform; BUC rejections come back as plain HTTP 400, not 429): https://developers.facebook.com/docs/graph-api/overview/rate-limiting/
    - Instagram content-publishing error codes: https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/error-codes/
    - Instagram media reference (incl. `DELETE`): https://developers.facebook.com/docs/instagram-platform/reference/instagram-media/
    - Threads API: https://developers.facebook.com/docs/threads — reuses the Graph API error format; no separate Threads-specific error code table exists. Delete posts (needs the separate `threads_delete` permission, 100 deletes/day/account): https://developers.facebook.com/docs/threads/posts/delete-posts/
    - Our `App\Services\Social\Meta\GraphError` (used by `ConnectionVerifier`'s verify/refresh calls) has the full rationale and code table in its class docblock — check there before changing transient-vs-confirmed-rejection classification.
    - `Facebook`/`InstagramFacebook` `SocialAccount`s use a Facebook Page access token (BUC-limited); `Instagram` (direct login) and `Threads` use a user access token (Platform Rate Limit-limited). This affects which rate-limit codes apply to which platform.
- **X (Twitter)**: API v2 — https://docs.x.com/x-api ; Post management (create/delete) — https://docs.x.com/x-api/posts/manage-tweets/introduction
- **LinkedIn**: Posts API (create/update/delete, member + organization) — https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api (replaces the deprecated `ugcPosts` API)
- **Mastodon**: Statuses API — https://docs.joinmastodon.org/methods/statuses/
- **Pinterest**: API v5 reference — https://developers.pinterest.com/docs/api/v5/
- **YouTube**: Data API v3 — https://developers.google.com/youtube/v3/docs
- **TikTok**: Content Posting API — https://developers.tiktok.com/doc/content-posting-api-reference-direct-post — **no delete/unpublish endpoint exists**; a published post can only be removed manually inside the TikTok app
- **Bluesky / AT Protocol**: official lexicons — https://github.com/bluesky-social/atproto/tree/main/lexicons/com/atproto/repo ; HTTP API reference — https://docs.bsky.app
- **Discord**: Webhook resource (used for our webhook-based publishing) — https://docs.discord.com/developers/resources/webhook
- **Telegram**: Bot API — https://core.telegram.org/bots/api
- **Google Business Profile**: Business Information API, Account Management API, Business Profile Performance API — https://developers.google.com/my-business/reference/rest ; legacy but still-active Local Posts v4 API (the only endpoint for creating/updating/deleting Local Posts) — https://developers.google.com/my-business/reference/rest/v4/accounts.locations.localPosts

## TryPost.it Documentation

- All our documentation to final user it's under https://docs.trypost.it

## X link defusing (env knob)

X bills a post containing a URL at **$0.20** vs **$0.015** for a plain post (13x), and its algorithm demotes link posts. So on Cloud the `ContentSanitizer` rewrites every URL in the X version of a post into a non-clickable form — `https://example.com/post` becomes `example(.)com/post`.

| Env | Config | Default | Effect |
| --- | --- | --- | --- |
| `X_DEFUSE_LINKS` | `trypost.platforms.x.defuse_links` | `false` | `true`: URLs in the X version of a post are rewritten non-clickable (scheme and `www.` dropped, **every** dot of the host replaced with `(.)`). `false`: the X content is published unchanged. Only affects `Platform::X` — every other network keeps the URL intact. |

Standing constraints:
- The transform lives in ONE place: the `Platform::X` arm of `App\Services\Social\ContentSanitizer::sanitize()`. Never re-implement it in a publisher or add a `$defuseLinks` parameter to `sanitize()` — a per-call-site flag gets forgotten at the next entry point and we silently start paying again. Because `PostPreviewer` also goes through `ContentSanitizer`, the app/API/MCP previews show the defused text for free.
- **Every** dot of the host must be broken. Defusing only the dot before the TLD leaves `blog.example.com` in `blog.example.com(.)br`, which X still detects and bills.
- A URL carrying `https://`, `http://` or `www.` is defused on sight. A **bare** host is only a link when its last label is a delegated TLD — that check is the one thing separating `acme.com` from `Node.js`, and it goes through `App\Support\LinkTlds`, which mirrors the full IANA root zone rather than a hand-picked subset. Never replace it with "any 2+ letters after a dot", and never trim it back to a curated list: whatever X links is what X bills, so the two must stay in step. `README.md` and `backup.zip` are defused on purpose — `.md` and `.zip` are real TLDs and X links them too.
- Off by default everywhere. Cloud opts in; self-hosted installs publish through their own X app and pay their own bill, so they only turn it on if they want to.
- Character limits are measured against the **sanitized** content — the string the publisher actually sends — against the **account's** limit plus the reserved length (see Content limits), in both `App\Rules\ContentFitsPlatformLimits` (save/schedule) and `HasSocialHttpClient::validateContentLength()` (publish). The editor stores HTML and per-platform rules change the length again, so measuring the raw draft blocks saving posts that publish fine and lets through posts the network rejects. Keep the two in step.
- Tests enable it explicitly with `config()->set('trypost.platforms.x.defuse_links', true)` rather than pinning an env, so the suite runs against the shipped default.
- The editor counts characters and renders the X preview client-side, so the rewrite is mirrored in `resources/js/lib/defuseXLinks.ts`. The TLD list is NOT duplicated there: `PostController@edit` sends `App\Support\LinkTlds::all()` as the `xLinkTlds` page prop, and only when defusing is on — an empty set means the feature is off, since without the list a bare host cannot be told from `Node.js`. Do not move it to the Inertia shared props; only the editor needs it. Two tests keep the mirror honest: `XLinkDefusingParityTest` runs a shared corpus through both engines over the same list and diffs the output, and `tests/Browser/XLinkDefusingTest.php` drives the real editor.
- Neither expression may use lookbehind. Safari only understands it from 16.4, esbuild cannot transpile it, and a `SyntaxError` there takes down the whole chunk — the character before a candidate URL is consumed and put back instead.

## Git

- NEVER add `Co-Authored-By` lines to commit messages.
- NEVER commit, push, or open PRs unless explicitly asked by the user.
- Always create a new branch for feature work before making changes.
- **Exception — TryPost 2.0:** while the checked-out branch is `codex/independent-social-posts`, every change belongs to TryPost 2.0 and stays on that branch. Do not create new branches or worktrees for features, fixes or follow-ups there. This exception ends when that branch is merged into `main`; from then on the rule above applies again.

## Repurpose account health

A repurpose depends on social accounts it does not own the lifecycle of. Three
decisions govern how it reacts, and each exists because the obvious alternative
was tried and was wrong.

- **A destination is only checked for tenancy.** There is no switched-off
  state: an account is either in the workspace or it is not.
  `ActivateRepurpose::assertDestinationsPublishable()` requires **one** usable
  destination, not all of them, and the destination rule in the repurpose
  FormRequests carries only the `workspace_id` clause — that is tenancy, not
  health. `ProcessRepurposeItem` skips a destination that no longer resolves in
  the workspace. The `source_social_account_id` rules stay strict: a source
  genuinely must work.
- **`repurposes.paused_reason` is not UI copy.** NULL means the user paused it.
  Its only two jobs are deciding the watermark on resume (a system pause starts
  from `now()`, a user pause keeps its place) and deciding whether the system may
  auto-resume. Banners derive from current account health instead, so they can
  say "ready to resume" once the cause is fixed. **Never clear it in
  `UpdateRepurpose`** — that destroys the record that the pause was systemic, and
  the next Resume replays the entire backlog.
- **Source and destination are deliberately asymmetric.** A dead source stops the
  automation; a dead destination keeps flowing to the publisher, which fails the
  post visibly and lets the user retry it after reconnecting. Skipping a
  destination at job time would be permanent for that item, since items are never
  retried.

`RepurposeAccountSync` runs from `SocialAccountObserver` and must never throw:
`deleting` runs inside `$account->delete()`, and `persistIdentity()` wraps a
reconnect in a transaction, so an exception there would 500 a disconnect or roll
back a reconnect. It reads account health **from the database**, not from the
model it was handed — strict mode exempts recently-created models from the
missing-attribute exception, so an attribute absent from the factory read back
as `null` and silently skipped auto-resume.

No email is sent when a repurpose stops. `markAsTokenExpired()` and
`VerifyWorkspaceConnections` already email about the account, and reconnecting is
what auto-resumes the repurpose; deleting an account is
something the user just did, so the flash on the accounts page reports the count
instead.

`VerifyWorkspaceConnections` is the **only** thing that promotes an account back
to `Connected`, because it does so after a real `verify()` call. A successful
token refresh is not that proof — the refresh token being valid says nothing
about whether publishing still works — so `RefreshSocialToken` must not promote,
even though it would let a paused repurpose resume sooner.
