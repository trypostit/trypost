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
- **A button that waits on a request shows it through the `Button` component's own `loading` prop whenever possible** (`<Button :loading="form.processing">`): it disables the button and swaps its label for the spinner, so every button waits the same way. Do not hand-roll a `<Spinner v-if>` inside the label or a separate disabled flag for this. When `loading` cannot fit (the label must stay visible next to the spinner, an `as-child` link, a control that is not our `Button`), keep the label and add the spinner next to it. Many existing buttons predate this rule; move them to `loading` when touching them, not in a sweep.
- **Unsaved changes in the composer** go through `composables/composer/useUnsavedChanges.ts` (the history guard) and `useComposerUnsavedChanges.ts` (what counts as a change). The owner treats this guard as critical. How the guard works is written in the composable's docblock; three rules hold around it:
    - The composer's save visits (`router.put` / `router.post` in `pages/publish/Index.vue`) send `replace: true`, so the saved page takes the place of the edit's history entry instead of stacking on it.
    - A composer opened from a page of the app (`lib/inAppHistory.ts`, `cameFromAppPage()`) leaves through history: closing goes back, and saving goes back once the saved page lands and reloads that page. So after a save or close, one browser Back reaches the page before the list, calendar or tab it was opened from. A composer reached by its link or a reload has nothing behind it and still replaces its entry with the list.
    - "Is there unsaved work" is decided by the snapshot comparison alone, never by `isSubmitting` or another request flag.
    - Every change to the guard ships with a browser test in `tests/Browser/ComposerUnsavedChangesTest.php` that fails without it, checked by removing the change and running the test. History bugs here showed up only in a real browser (Inertia's own popstate handler is part of every flow).
- **Composables are scoped by area.** A composable used by one area only lives in that area's folder, `resources/js/composables/<area>/` (e.g. `composables/composer/useComposerUnsavedChanges.ts`, `composables/echo/usePostEcho.ts`). Only composables shared across areas stay at the root of `resources/js/composables/`. When a scoped composable gains a consumer in another area, move it to the root in the same change.
- Searching a list in the UI goes through `matchesSearch` / `filterBySearch` (`resources/js/lib/search.ts`): word by word, ignoring case and accents. Never hand-roll `.toLowerCase().includes(...)` for a search box.

## Inertia SSR

- This project does **not** run Inertia SSR. `config/inertia.php` defaults `ssr.enabled` to `false` and nothing in the repo sets `INERTIA_SSR_ENABLED`.
- Keep it off. With it on, every test rendering an Inertia page issues a real HTTP request to the SSR endpoint, which fails silently and falls back to client rendering — slow, and it hides missing `Http::fake()` stubs.
- The build wiring is still shipped (`resources/js/ssr.ts`, `vite.config.ts`, `npm run build:ssr` in `docker/Dockerfile`). Turning SSR on means building that bundle and running `inertia:start-ssr` alongside the app, not just flipping the env.

## Dialog and slide-over actions

In dialogs and slide-overs with Cancel and a primary action, render **Cancel first, primary action last** in the DOM and visually. On desktop, Cancel belongs to the left of the primary action; on mobile, the primary action remains last. Apply this to destructive confirmations as well. Do not reverse the order only with CSS, because keyboard and screen-reader order must match what users see. If there are other secondary actions, place them between Cancel and the primary action.

## Delete confirmations (type-to-confirm)

**Typing to confirm is reserved for critical actions.** Everything else — posts, ideas, templates and other everyday content — uses a plain confirmation dialog (title, description, Cancel, destructive action) with no typing. The dialog states what will be removed and nothing more: there is no generic "This action cannot be undone" line.

Critical means losing it breaks something outside the item itself or cannot be rebuilt by the user in a minute. Only these ask the user to type:

- **The resource's name or identity:** deleting a workspace (its name) and removing a member or invitation (their email).
- **A fixed keyword:** deleting or regenerating an API key (`REGENERATE` for the latter), because integrations stop working at once, and disconnecting a channel or an MCP app (`disconnect`), because its queue and automations, or the AI integration, stop.

Do not add typing to a new dialog unless it meets that bar; when in doubt, ask.

Typing the exact name is the stronger guard for the identity-bound ones, on purpose; do not switch those to the keyword. When the keyword is used:

- The keyword is translated and **always fully uppercase** in every locale (`DELETE`, `EXCLUIR`, `ELIMINAR`, …), shown uppercase in the helper text, and compared **case-sensitively** after trimming: `delete` or `excluir` must not confirm.
- It comes from one shared lang key in all 16 locales — never a literal, and never a per-feature copy of the word.
- Resolve it with `$t` in the template, not `trans()` in script (see `.ai/rules/js.md`).
- Regenerating an API key uses its own keyword, `settings.api_keys.regenerate_modal.keyword` (`REGENERATE`, `REGENERAR`, …), under the same rules.
- **Exception — disconnecting a channel** (`DisconnectChannelDialog`, user decision October 2026): its keyword, `channels.disconnect_modal.keyword`, is translated and **lowercase** (`disconnect`, `desconectar`, …), shown as the input placeholder and in the copyable keyword line `Type [disconnect ⧉] to confirm.` (same markup as `ConfirmDeleteModal`), and still compared case-sensitively after trimming (`DISCONNECT` must not confirm). The dialog ends its description with a bold `This cannot be undone.` and offers **Refresh connection** before disconnecting. Do not carry either into the other dialogs.
- **Disconnecting an MCP app** (`settings/workspace/Mcp.vue`, owner decision October 2026) asks for the same `channels.disconnect_modal.keyword`, not the app's name, so the word matches the channel dialog in every locale (compared case-sensitively after trimming). It keeps the plain `ConfirmDeleteModal` without the refresh option or the bold line.

## Writing in-app copy

Text inside the app (popovers, empty states, dialogs, tooltips that ask for an action, errors, toasts) talks to the end user, who is in the middle of doing something. Write it from their side, not from how the system is built. The `copywriting` skill in `.agents/skills` is for marketing pages; it does not apply here.

**Full guide, examples, per-component rules, the review checklist and the sources: the `in-app-copy` skill** (`.claude/skills/in-app-copy/SKILL.md`, mirrored in `.agents/skills` and `.cursor/skills`). Load it before writing or reviewing any user-facing string. The rules below are the minimum.

- **A prompt that asks the user to act follows three steps, in this order** (owner decision October 2026):
    1. **What is missing, in the user's words and addressed to them:** "You haven't set up this channel's queue yet". Not a system state ("Set up the queue first", "No posting times configured").
    2. **Why it matters for what they just tried:** "To add posts to the queue, set this channel's posting times." Tie it to the click that opened the prompt, never to how the feature works in general.
    3. **The next action, named by its button:** the body leads to the button ("Set posting times"), and the button says exactly what happens. Keep a way out ("Not now").
- **Talk to the user ("you"), lead with the point, keep it short.** One or two sentences. No explanation of the mechanism before the user knows what to do, no apology, no "please", no blame, no generic "This action cannot be undone" line.
- **A user who cannot do the action** (a member without the permission) gets the same title and a body that names who can do it: "To add posts to the queue, a workspace admin needs to set this channel's posting times." Never show them a button they cannot use.
- **Errors say what happened and how to fix it**, in the interface's voice: "Couldn't load music from Instagram. Try again." Not "Something went wrong".
- **Use the words the user already sees on screen.** The same action keeps the same name everywhere (the button "Add to queue", the tab "Queue", the prompt "the queue"). Never introduce internal names (slots, holders, destinations, meta).
- **Every locale follows the same structure**, written natively, not word for word: keep each locale's existing word for the feature (Fila, Warteschlange, file, cola), and rephrase a sentence that needs grammatical gender for the user into an impersonal one (Polish "Kolejka tego kanału nie jest jeszcze skonfigurowana").
- Reference: the queue setup popover (`posts.publish.actions.queue_setup`, shown by `AddToQueueButton.vue`).

## Translated copy must fit its UI slot

Every lang string is rendered in all 16 locales, and the longest one decides the layout. Menu items, buttons, tabs, badges and other single-line controls must not wrap onto a second line in any locale.

- When adding or changing a key, check the longest translations (French, German, Ukrainian, Russian, Polish and Portuguese usually run longest) against the slot, and shorten the copy in that locale rather than letting it wrap. A shorter natural phrase beats a literal one (`Ajustes do workspace`, not `Configurações do workspace`).
- Never fix a wrap by truncating the text or widening one locale's layout; the label has to stay readable everywhere.
- A settings page description (the line under the page title in `SettingsLayout`) stays on one line in every locale, next to the header action.
- Single-line copy is guarded by `tests/Feature/SingleLineCopyTest.php`, which fails when any locale's translation of a watched key gets longer than its budget in `tests/fixtures/single-line-copy.php`. When adding a button, tab, menu item or badge label, add its key there with the length of its longest translation; raise a budget only after checking the slot still fits.
- Do not write browser tests that measure whether a label wraps (pixel widths, line counts, `Locale::cases()` loops over rendered text). They depend on the machine's fonts and fail differently on CI and locally; they were replaced by the length test in October 2026 (owner decision).

## AI agents (`app/Ai/Agents`)

- **Never** embed prompts in PHP (`<<<PROMPT`, heredocs, or long string literals in `instructions()`).
- Put system/instruction text in Blade under `resources/views/prompts/` (e.g. `prompts.post_content.assistant`, `prompts.post_image.alt_text`).
- In `instructions()`, return `view('prompts....', [...])->render()` and pass only the variables the Blade file needs — same pattern as `PostWritingAssistant` and `MediaAltTextGenerator`.

## AI language

- There is no workspace language. Every AI prompt receives `{{ $language }}` from `Locale::promptLanguage()` of the user the call runs for: the requesting user on the web, the post author for Google Business `languageCode` (`bcp47()`), the repurpose creator for Repurpose. Fall back to `Locale::DEFAULT` when that user is gone.
- A rewrite of existing text (shorten, rephrase, tone) keeps the text's own language; only new text is written in `{{ $language }}`.
- AI is web only: no REST API endpoint and no MCP tool calls an AI agent.

## Stripe Checkout (offers)

Everything that is not the full price is an offer (owner decision October 2026): the discount, the trial and the promotion code field live on the `offers` row (see "Offers"), never in env or in controllers. All of it goes through `App\Support\Billing\ConfigureSubscriptionCheckout` (called from `StartSubscriptionCheckout`).

There is no checkout env (owner decision October 2026; `REQUIRE_CARD_FOR_TRIAL` and `CASHIER_TRIAL_DAYS` were removed, so a forgotten env value can never override an offer). An account has app access with a subscription, or during a generic trial an offer started (see below). Signup alone never grants a trial.

Standing constraints:
- Without an offer the checkout charges the full price at once: no coupon, no trial, no promotion code field.
- An offer applies what its row sets: `stripe_coupon_id` (the discount), `trial_days` (clamped to Stripe's 2-day minimum), `allow_promotion_codes` and `requires_card`. Stripe rejects a coupon and promotion codes on one session but accepts a trial with either, so a coupon wins and the field is left off; a trial goes with both.
- There are no fm1 coupon or promotion code envs any more (`STRIPE_*_FIRST_MONTH_COUPON_ID` and `CASHIER_ALLOW_PROMOTION_CODES` were removed in October 2026). Every offer reaches an account through its link: the 7-day trial (`ft7d`, `trial_days` 8 because Stripe Checkout counts the trial one day short and shows 8 as 7 days, `first_subscription_only`), the `$1` first month (`fm1`, `first_subscription_only`) and Black Friday (`bf26`). There is no default offer: a signup without a link pays the full price. Do not reintroduce a checkout env.
- First-subscription offers skip an account with a prior real subscription (`incomplete` / `incomplete_expired` still qualify).
- Welcome checkout is the last Welcome step, the plan step (`app.welcome.plan.store`): it shows only **Socials**, monthly, and always opens Stripe Checkout on the **Socials monthly** price. Offers apply there and, coupons only, at **Subscribe now** on billing. Yearly and Workspaces stay on the billing change-plan picker for existing subscribers (no offer).
- An offer with `requires_card = false` and `trial_days` (`Offer::startsWithoutCard()`) never opens Stripe: the Welcome plan step starts Cashier's generic trial (`StartGenericTrial`: `accounts.trial_ends_at`, the Socials `plan_id`, the redemption, `trial.started`) and goes on to the MCP step (owner decision October 2026). The account has app access while it lasts (`Account::hasAppAccess()` = subscribed or `onGenericTrial()`), billing shows the trial and **Subscribe now** (`app.billing.checkout`), a Checkout on the plan's monthly price charged at once, without carrying the days left of the trial (owner decision October 2026), applies a coupon offer claimed meanwhile (never a trial) and returns to billing after paying (`app.billing.processing?from=billing`, read through `CheckoutOrigin`). Changing plan during the trial (the workspace upgrade picker; billing shows only Subscribe now) opens the same Checkout on the picked plan and interval, under the same `swapPlan` rules, which allow a generic trial. Only Socials monthly gets an offer (`StartSubscriptionCheckout::offerPlan()`). An old subscription ending during the trial leaves its plan alone, and a second submit of the no-card trial starts nothing. The Welcome no-card trial goes through Processing too (`from=welcome`, ready at once), so phones skip the MCP step like a paid checkout. Cashier's `customer.subscription.created` handler clears `trial_ends_at`. When the trial ends without a subscription, access stops and the owner is back at Welcome. Every other offer goes through Stripe Checkout and asks for a card.
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
- `WorkspacePolicy::create()` is owner-only. The cap is not a permission and the
  server decides it, never the browser's shared props (owner decision October
  2026), through `User::atWorkspaceLimit()` (`workspaceLimitApplies()`: never
  self-hosted, never the first workspace).
  - Every "new workspace" entry point (sidebar workspace menu, workspaces list)
    asks first with a non-navigating `useHttp` POST to
    `app.workspaces.creation-check.store` (`useWorkspaceLimit().createOrUpgrade`).
    `{ allowed: false }` opens the `WorkspaceUpgradeDialog` in place, on the page
    the user is on; `allowed: true` (or a failed check) visits
    `app.workspaces.create`. A refused check tracks `workspace.limit_hit`.
  - GET `/workspaces/create` is never shown at the cap: it tracks
    `workspace.limit_hit` and redirects to `app.workspaces.index` with
    `workspaces.limit_reached`. Without a subscription it still goes to billing.
  - POST at the cap is refused the same way (to the list, not 403'd, never to create),
    tracked once by `store` itself after its transaction.
- Welcome checks out Socials monthly only, so an offer's price is always about
  Socials monthly. Billing keeps yearly and Workspaces for subscribers changing
  plan.
- The legacy plan is archived: it never appears in the picker, so nobody can move
  back to it. Its `workspace_limit` is 1 because it costs less than Socials.
- Welcome's plan step (`app.welcome.plan`, after the current-tool step) shows
  only Socials (owner decision October 2026): new owners always start on
  Socials. The same `PlanPicker` drives upgrade/downgrade on the billing page
  (`app.billing.change-plan`).
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

## Offers (`?offer=` links)

An offer is anything the Welcome checkout gives instead of the full price
(owner decision October 2026): a Stripe coupon, a trial, the promotion code
field, or a mix. The 7-day trial (`ft7d`), the `$1` first month
(`fm1`) and campaigns such as Black Friday (`bf26`) are all offers. It
lives in `offers` (`slug` and `starts_at` required; `stripe_coupon_id`,
`trial_days`, `ends_at` and `max_redemptions` nullable: no coupon, no trial, no
end, no limit; `allow_promotion_codes`, `first_subscription_only` and
`show_banner` false by default; `requires_card` true by default) and reaches an account through the pivot
`account_offer` (`claimed_at`, `redeemed_at`).

- **Capture.** `CaptureOffer` (web middleware) reads `?offer=<slug>` on any page.
  A signed-in owner claims it at once (`ClaimOffer`). A guest's slug waits in the
  session, only when it names an available offer, and the first authenticated
  request claims it, so email signup, Google, GitHub and login need no code of
  their own; moving between register and login keeps it. Slugs match exactly on
  both engines through `Offer::findAvailable()` (MySQL compares without case). Ads and landing pages link to `/register?offer=<slug>`, win-back emails to
  `/login?offer=<slug>`. There is no offer page or route.
- **Who gets it.** Only an account owner whose account has no active
  subscription: new signups and former customers alike, unless the offer is
  `first_subscription_only`, which skips an account that was ever billed or
  already redeemed an offer, a no-card trial included
  (`Account::isFirstTimeSubscriber()`; Cashier clears `trial_ends_at` on any new
  subscription, so the redemption is the lasting record), so trials and
  the `$1` month never stack. `Offer::scopeAvailableTo()` holds both rules
  and drops offers the account redeemed. An unknown, not yet started, ended or
  full offer is ignored silently and nothing is kept for later. A redeemed offer
  is never offered to that account again.
- **The `offers` row is the only source of truth for dates and places.** The
  Stripe coupon has no `redeem_by` and no `max_redemptions`. Only redemptions use
  up a place (`Offer::scopeAvailable`), never a claim; two checkouts racing for the
  last place may both get it (accepted).
- A trial means `trial_days > 0` (`Offer::hasTrial()`); `0` is no trial.
- **Checkout.** `Account::currentOffer()` picks the latest claim the account may
  still use, else none (full price).
  `ConfigureSubscriptionCheckout::apply()` applies it. Validity is checked again
  when checkout starts. The controller resolves the offer once (`Account::currentOffer()`) and
  hands the same one to `StartSubscriptionCheckout` and `apply()`, so the coupon,
  the metadata and the tracked events agree. The offer id and slug go in the
  subscription metadata (`trypost_offer_id`, `trypost_offer_slug`) and the slug in
  the session metadata; the `customer.subscription.created` / `updated` webhook
  writes `redeemed_at` (`RedeemOffer`) only once the subscription is `active` or
  `trialing`, so an incomplete checkout never uses up a place. Offers apply at the
  Welcome checkout (Socials monthly) and, coupons only, at Subscribe now on
  billing (`currentOffer(withTrial: false)`).
- **Tracking.** `checkout.started` is sent by `StartSubscriptionCheckout` itself, on the owner, for every Checkout (Welcome, Subscribe now, plan change), never by a controller. The offer goes to PostHog only with the checkout, as the property
  `offer` holding its slug (`bf26`, `ft7d`, null without one) on
  `checkout.started`, `checkout.completed`, `checkout.expired` and the account
  group (set on subscription creation). There is no offer event of its own. The old `is_first_month_offer`,
  `first_month_coupon_id` and `first_month_offer_ends_at` are gone.
- **Copy.** Each offer's copy lives in `lang/<locale>/offers.php` under the
  slug with dashes as underscores (`ft7d` → `ft7d`): `name` (the
  banner's tag), `headline` (the banner's sentence), `price`, `period` and an
  optional `unit` shown next to the price (`/month` for Black Friday; a trial or
  the `$1` first month has none, so their copy decides it, never the code), in all
  16 locales, plus an optional `terms` for the line under the button (a trial
  without a card says no card is needed; without it the line is
  `welcome.plan_terms`, "Then :price/month"); the price is display text per locale, like
  `billing.subscribe.prices.socials.monthly`. Nothing about the discount is
  stored in the database, and the banner (`name`, `headline`) is optional: an
  offer has both or neither, while `price`, `period` and `checkout_button` are required
  (`OfferCopyTest`). Each trial length is its own offer with its own copy
  (`ft7d`, `free-trial-14-days`), so several can run at once. The page has no fallback language, so `OfferResource`
  checks each key in the current locale only. The Welcome plan step shows the
  banner (a `bg-foreground` pill, the name in a `bg-background` uppercase tag) only when the row has `show_banner` and `name` and `headline` exist, and the offer price only when `price` and `period` exist; it never
  prints a raw key. The button that opens Stripe Checkout is
  the offer's `checkout_button` (required, like `price` and `period`), or
  `welcome.plan_checkout_button` without an offer; without an offer the step
  shows the full price.
  `tests/Feature/Offer/OfferCopyTest.php` fails when an offer misses its copy in
  any locale.
- **Adding an offer.** Create its coupon in Stripe when it has one (no dates, no
  limit), add its
  copy to all 16 locales and deploy, then insert the `offers` row in each
  environment's database by hand (owner decision October 2026: no data
  migration, since the coupon id differs between Stripe test and live). Locally,
  `php artisan db:seed --class=OfferSeeder` adds the test-mode offers; it is not
  part of `DatabaseSeeder`, so tests never start with them.

## Multiple social accounts per network

A workspace may connect as many accounts of the same network as it wants (two
LinkedIns, three Instagrams, ...). There is **no** one-per-network rule and no
flag for it: `ALLOW_MULTIPLE_SOCIAL_ACCOUNTS` was removed in September 2026, along
with `SocialAccount::occupiesNetwork()` and the observer's `creating` guard. Do
not reintroduce either. What still holds:

- Reconnecting the same `platform` + `platform_user_id` updates the existing row
 (`SocialAccount::connectIdentity()`). The connect confirmation page offers an
 identity already connected on the same platform as "Already connected" (picking
 it refreshes the row) and drops one seated under another platform of the
 network, so one identity can never be seated twice under two platforms of one
 network (Instagram directly and via Facebook).
- `Platform::network()` still collapses variants (LinkedIn profile/page,
 Instagram standalone/Facebook) — that grouping drives the accounts UI, not a cap.
- `accounts.connect.errors.network_taken` / `accounts.telegram.network_taken` stay
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

## Connecting a channel (redirect + confirmation page)

There is no connect popup (owner decision 2026-10-06): no `window.open`, no
`postMessage`, no `PopupCallback`. Every OAuth network is a full-page redirect.

- The start route (`app.social.<network>.connect`) stores a
  `App\Support\Social\PendingConnection` in the session: network, workspace,
  validated reconnect id and the return page, kept only as a whitelisted
  `app.*` route name + parameters resolved from `return_to` (never a raw URL).
- The callback (registered redirect URIs unchanged) never writes a row. It hands
  the identities, tokens included, to `SocialController::offerIdentities()`
  (session only, 15 minutes) and redirects to `app.social.connect.show`. Every
  stop (cancel, missing publish scope, error, expired) goes through
  `failConnection()` / `ConnectFlowException`, which drops the tokens and keeps the
  return page for "Try again".
- "Finish connection" (`app.social.connect.finish`, `FinishSocialConnectionRequest`)
  only accepts keys the pending connection offered, re-checks `manageAccounts`,
  stores the picks in one transaction and always redirects to the connected
  channel's publish page (`app.channels.publish`: the new account, or the first one
  when reconnecting or refreshing) with the `connectedChannel` Inertia flash that
  opens the posting goal there for a new channel (owner decision 2026-10-06). The
  return page serves only close (X), Back, cancel and the error states.
- An identity already connected in the workspace stays where the network listed
  it but is locked (owner decision 2026-10-06): muted card, a right-aligned
  "✓ Connected" (`accounts.connect.connected`) instead of a checkbox, never
  pre-checked, not counted in "N selected" and skipped by "Select all"; the
  counter starts at 0 and Finish stays disabled until a new one is picked. When
  every offered identity is locked the page adds `accounts.connect.all_connected`
  under the subtitle. The rule is `PendingConnection::lockedIdentityKeys()`, sent
  as `locked` on each card and enforced by `FinishSocialConnectionRequest`
  (`not_in`, 422 with `accounts.connect.errors.identity_connected`). A reconnect
  (validated `reconnect` id) locks nothing: its one card is pre-checked so the
  token can be refreshed.
- Bluesky (app password) and Mastodon (instance) keep their form step on the same
  page layout and end on the same confirmation page; Telegram keeps its dialog.
- "Switch account" (header of the select state) shows a "Connect a different account"
  step whose CTA restarts the flow with `switch=1` (only the exact value `'1'` counts,
  `SocialController::switchingAccount`), keeping reconnect and return page and
  discarding the first login. Where the network documents it, the restart asks for
  the account chooser again: Google Business `prompt=select_account consent`,
  Facebook and Instagram via Facebook `auth_type=rerequest,reauthenticate`, Mastodon
  `force_login=true` (YouTube, Instagram and TikTok already send theirs on every
  connect). X OAuth 2, LinkedIn, Pinterest, Threads and Discord document none, so
  the step's instructions are the only help there.
- Google connects (YouTube, Google Business) never send `include_granted_scopes`.
  TryPost may run every Google feature on one OAuth client, and that flag folds in
  the scopes the user granted elsewhere (the composer's `drive.file`), which Google
  refuses to combine with the YouTube scopes ("scopes that cannot be requested
  together"). Each Google flow asks for its own scopes only.

## Disconnecting a channel deletes its posts

`SocialController@disconnect` runs `DeleteChannelPosts::forAccount()` before it
deletes the account: every post of that channel (drafts, scheduled, pending
approval, failed, sent and imported) goes with its media. There is no orphaned
history — a post without a channel is a history that no longer exists.

- Quiet: no `post.deleted` webhook or notification per post.
- It deletes by `posts.social_account_id`, without a workspace clause: a post
  has one channel, so every post pointing at the account goes.
- Analytics publications stay and are only unlinked, never dismissed, so
  reconnecting the same identity re-imports its recent posts once.

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
- Approval emails go through `SendNotification` with `Type::Collaboration`, one
  email per post (owner decision October 2026): posts created together for
  several channels are separate posts, each approved on its own, so they are
  never grouped by `post_group_id`. A request emails every approver except the
  requester and is dropped when its post is no longer pending; a decision
  (`NotifyApprovalDecision`) emails the requester once it commits and is dropped
  when its post was deleted.
- The published and failed emails (`FinalizePostPublication::notify()`) go to
  every member of the workspace (owner decision October 2026; the owner is always
  a member); each user's notification preferences still decide whether it is sent.

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
  with it: the language the visitor picked, else the browser's language
  (`detectBrowserLanguage` over the supported codes, so `pt-PT` becomes `pt-BR`),
  else `Locale::DEFAULT` (`signupLocale` in `useGuestLocale`). The page still
  renders in the default until the visitor picks; only the account gets the
  browser language. Lifecycle emails pick their language from it, so a signup
  must not start in English and switch minutes later.
  **Login submits it only once the visitor picks a language** — the field goes
  out empty otherwise, and an empty value leaves `users.locale`
  untouched. This asymmetry is load-bearing: the login screen always renders in
  `Locale::DEFAULT`, so an always-sent field would reset every non-English user
  to English on each login. Forgot and reset password do not send it at all.
- Google and GitHub signups send the same `signupLocale` as a query param on the
  start route, kept by `PreservesSignupPreferences` with the zone and clock (the
  OAuth callback itself tells you nothing reliable about the person). `CreateUser`
  falls back to `Locale::DEFAULT` for a missing or unsupported value. A returning
  user logging in through OAuth keeps their stored locale.

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
- **Insights days.** Range presets and custom dates are calendar days of the
  viewing user's zone (`DateRange::$timezone`, set by
  `ResolveAnalyticsRangePreset::selection()`). Publications are queried between
  `DateRange::startsAt()` and `endsAt()` (UTC instants of the first day's start
  and the last day's end there) and bucketed in PHP by `DateRange::localDate()`,
  never with `CONVERT_TZ` / `AT TIME ZONE`. This holds for web, API, MCP and the
  exports, and for the previous period. Account daily snapshots keep the
  network's calendar date and are matched by that date (`observedThrough` lets
  today's snapshot dated by the next UTC day still count).
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
- **Signup detection:** email, Google and GitHub signups store the browser
  language (see "UI locale"), the browser zone, the week start (`Intl.Locale`
  week info, else the Sunday-first zone list in
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

`App\Jobs\PostHog\SyncUser` writes the user's person properties (two narrower
jobs below add one each), and the distinction between its two buckets is
load-bearing:

- **`$set_once`** — first-touch facts that must never be rewritten: `signed_up_at`
  and the attribution keys (`utm_*`, `gclid`, `fbclid`, …). A later sync must not
  overwrite where a user originally came from.
- **Top level** — current state, overwritten on every sync: `$email`, `$name`, and
  `locale`.

The only other jobs that write person properties are `IdentifyConnectedPlatforms`
(`connected_platforms`) and `TrackLifecycleChange` (`lifecycle_stage`), both
current state at the top level.

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
- Brand logos come from `@thesvg/vue`, one import per logo (`import Canva from '@thesvg/vue/canva'`). A one-color logo that must follow the theme goes through `inTextColor()` (`resources/js/lib/brandLogos.ts`).
- **Exception — card brand logos on billing** (owner decision October 2026): the full-color card artwork lives in `public/images/card-brands/*.svg`, mapped by Stripe's `card.brand` in `resources/js/lib/cardBrands.ts` (a brand without its own art uses `generic.svg`).
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
    - **Exception — the per-post table on the channel insights page** (owner decision 2026-10-05): it is page-based with numbered pages, 10 posts per page (`ListChannelPublicationPerformance::INSIGHTS_PAGE_SIZE`, through `insightsPage()`), and keeps `?page=` in the URL; a sort, period or filter change goes back to page 1. The API endpoint and the MCP tool for the same list keep the config page size through `handle()`.
    - **Exception — the ideas board columns** (owner decision 2026-10-06): each stage column (and Unassigned) is its own scroll prop, `columns.<stage id|unassigned>`, with its own page name, and loads 10 ideas per page (`ListIdeas::BOARD_PAGE_SIZE`) as the column scrolls. A label filter change resets every column to page 1. Column counts come from the database (`stages.*.ideas_count`, `unassigned_count`), never from the loaded cards. The web board moves a card with `after_idea_id` (`MoveIdea::after()`), since a partly loaded column cannot send the full order; the API and MCP keep the full `idea_ids` contract. The gallery keeps the config page size.
    - **Exception — the "What's New" modal** (owner decision 2026-10-14): it loads 5 entries per page, enough to fill the modal (capped at 640px) and the next one loads only when the user scrolls (`ListChangelogEntries::PAGE_SIZE`) under the page name `changelog_page`, as the shared optional scroll prop `changelogEntries` (built with the `changelog` card prop by `BuildChangelogProps`, which `HandleInertiaRequests` only spreads), requested by a partial reload when the modal opens.
    - **Exception — the invoices tab on the billing page** (October 2026): invoices live in Stripe, which only pages by cursor, so `BillingController@index` sends `Inertia::scroll()` over Cashier's `cursorPaginateInvoices()` (paid invoices only, config page size). It is the one cursor paginator in the app; database lists keep `paginate()`.
    - **This includes the public REST API** (`app/Http/Controllers/Api`). It used to pin its own page size of 15 as a stable contract; that exception is gone, so a list endpoint reads the same config as everything else. Changing `app.pagination.default` therefore changes the API's page size too — deliberate, and the reason a list response always carries `meta.per_page` for clients to read rather than assume.

## Changelog ("What's New")

The in-app announcements: a card at the bottom of the sidebar announcing the newest
entry, and a "What's New" modal (Help menu) listing every published entry.

- **Each entry is one folder in the repo**, `resources/changelog/<YYYY-MM-DD>-<slug>/` with
  `entry.md` (front matter `title`, `date`, optional `tags` from `App\Enums\Changelog\Tag`: `new`, `improved`, `fixed`, `beta`, shown above the title with a fixed color each in `ChangelogTagBadge.vue`)
  and `cover.png` (16:9, rendered at 2400×1350). Covers come from three numbered templates in `.claude/release-assets/changelog-covers/`, rendered by its `render.mjs`: 1 settings panel (a composer-like panel with the network's badge on a tint with corner dots, white surfaces with a 2.5px ink border), 2 integration (a tile grid with the TryPost mark, a plus and the service's official logo from `logos/`) and 3 announcement (the same grid with 🎁 📣 🎉). An entry only swaps the content; the style only, never another product's sample content, never screenshots or headlines. The folder holds a `.gitkeep` so it exists with no entries.
  There is no admin panel. Write them with the `/changelog` command.
- **Covers are Vite assets** (owner decision 2026-10-14): `vite.config.ts` lists
  `resources/changelog/**/*.png` in the plugin's `assets`, so the build versions them, and
  `ChangelogEntry::coverUrl()` reads `Vite::asset()`. A cover missing from the build gives a null
  `cover_url` (not reported) and the UI keeps the empty muted frame; factories hit this, production
  does not because the build and the sync run from the same commit. Never upload covers to a disk
  or put them in `public/`.
- **`php artisan changelog:sync` runs on every deploy** (after `migrate`). Each folder is validated against
  `App\Http\Requests\App\Changelog\SyncChangelogEntryRequest` (rules and messages live there, like
  every other request); `SyncChangelogEntries` reads the files, validates and writes. DTOs never validate. It upserts
  `changelog_entries` by slug, deletes rows whose folder is gone, and fails loud without writing
  anything when the folder itself is missing (never "no entries, delete everything") or one folder is invalid (its name must be `<YYYY-MM-DD>-<slug>` and its `date` that same day, which also catches an impossible unquoted date YAML would roll over) (missing `entry.md` or `cover.png`, or a cover that is not
  16:9). Never edit the rows by hand, and never rename a synced folder (its views are keyed to
  the row).
- **The entries and the modal are English only** (owner decision 2026-10-14): the entries, the
  tag labels (`Tag::label()`), the modal title, its empty state, the "Do you like it?" block (its
  `Like` / `Dislike` labels and its error toast), the card's "Read more" and the entry's "All updates" button are hard-coded English,
  never lang keys. The exception is the Help menu item, which is part
  of the app menu and is translated (`sidebar.support.changelog`, all 16 locales). The close
  button's `aria-label` reuses `common.close`. An open entry's header drops the "What's New"
  title for an "← All updates" button (English, like the rest) and the X; the dialog's title
  for screen readers is then the entry's title (`sr-only`).
- **"Viewed" means the user opened the entry's detail**, never that it was shown in the
  card or the list. `changelog_entry_views` holds one row per user and entry, and
  `ChangelogEntryViewObserver` queues `TrackChangelogEntryEvent` (`posthog` queue) when the row
  is created, so `changelog.entry_viewed` goes to PostHog on the first open only.
- **"Do you like it?"** sits at the end of an entry's detail with 👍 / 👎
  (`App\Enums\Changelog\Reaction`: `like`, `dislike`), shown as emoji that grow on hover; once
  one is picked, the other turns grayscale. The answer is the `reaction` column on the
  user's view row (owner decision 2026-10-14: no separate reactions table); clicking the chosen
  one again clears it. `ReactToChangelogEntry` (`PUT app.changelog.reaction.update`) records the
  view if needed and stores the reaction; the observer queues `TrackChangelogEntryEvent` with
  `changelog.entry_reacted` on every change, carrying the reaction picked (null when cleared). Counts are never shown in the app.
- **PostHog events go through queued jobs**, like the rest of the app: actions never call
  `PostHogService::capture()`; the observer dispatches `app/Jobs/PostHog` jobs on the `posthog` queue.
- **The sidebar card announces one entry only** (owner decision 2026-10-14): the newest
  one dated since the user's signup day (`ChangelogEntry::scopeAnnouncedTo`), and only
  while the user has not opened it (`ShowChangelogCard::execute`). Opening it hides
  the card; older unopened entries never take its place. A new user never gets the backlog.
  The card is cached per user for 10 minutes (`ShowChangelogCard::cached()`), so a page change
  does not query it. The cache holds the entry's raw attributes, never the model:
  `cache.serializable_classes` is off, so a cached object comes back as `__PHP_Incomplete_Class`.
  The action owns its keys (private, like `ListPinterestBoards`). `RecordChangelogEntryView`
  clears one user's card when it creates a view, and a sync that changed anything bumps the cards'
  version (`ShowChangelogCard::forgetAll()`), so a new, removed or re-dated entry shows at once.
- **The modal closes when the page changes** (`useChangelogDialog` closes on every Inertia
  `navigate`, which fires for pushed visits and browser Back/Forward); its own requests (list load,
  scroll pages, card reload) are same-URL visits Inertia replaces without `navigate`, so they leave
  it open. Layouts are not persistent, so keeping it open across pages would remount it
  with stale state.
- **The modal list shows every published entry** (`scopePublished`, dated today or earlier),
  including those from before the user signed up, with a dot on the ones not opened yet. A
  future date schedules an entry for both.
- On by default (`CHANGELOG_ENABLED`, `trypost.changelog.enabled`), self-hosted included: the
  Docker entrypoint runs `changelog:sync` after `migrate`. Turning it off means no shared data, no
  card, no menu item, and the view and reaction routes 404.

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
- **Requests are independent.** Every FormRequest (web under `App/`, API under `Api/`) and every MCP tool declares its own rules inline. Do not create a shared rules helper (e.g. `ChannelRequestRules::reorderQueue()`) for requests or tools to call, and do not make one request delegate its rules to another. Web, API and MCP agreeing is proven by the parity tests in `tests/Feature/Parity`, not by sharing a rules class. Domain rules that already exist as their own concept (`PostPlatformMetaRules`, `PostMediaRules`, `PostStatusRules`, `App\Rules\*` rule objects) stay shared.
    - The classes under `app/Support/Requests/**` predate this rule and will be refactored away; do not add to them or copy their pattern.

## Controllers: one per resource, mirrored on web and API

A controller serves **one resource**. Network-specific endpoints never pile up in a generic controller: in October 2026 `Api\SocialAccountController` had grown to hold Pinterest boards, Discord channels, TikTok creator info, Instagram audio, the posting schedule and the queue slots, and was split (owner decision). Do not let it happen again.

- **One controller per resource**, with resourceful method names (`index`, `show`, `store`, `update`, `destroy`, plus a few named verbs where REST has none, e.g. `generate`, `copy`, `reorder`, `moveToSlot`). A new network feature gets its own controller; it is never added as `pinterestBoards()` / `instagramAudio()` methods on `SocialAccountController` or any other controller.
- **The web and the API mirror each other.** The same resource has the same controller name on both sides: `App\InstagramAudioController` ↔ `Api\InstagramAudioController`, `App\PinterestBoardController` ↔ `Api\PinterestBoardController`, `App\DiscordController` ↔ `Api\DiscordController`, `App\ChannelPostingScheduleController` ↔ `Api\ChannelPostingScheduleController`. When one side gains an endpoint, the other side's endpoint for the same resource goes in the controller with the same name.
- **Channel features belong to their channel controller**: posting schedule reads and writes in `ChannelPostingScheduleController`, queue slots and ordering in `ChannelQueueController`, insights in `ChannelInsightsController`. `Api\SocialAccountController` only lists the connected accounts.
- **MCP has no controllers**: one tool class per action (`app/Mcp/Tools/<Area>/<Verb><Resource>Tool.php`), never one tool that switches on the network.
- Moving an endpoint between controllers keeps its URL and route name, so API clients see no change; the parity tests in `tests/Feature/Parity` must pass unchanged.

## MCP tools list in one page

`tools/list` returns every tool in a single response (owner decision October 2026): some MCP clients do not follow `nextCursor` and silently see only the first page. `TryPostServer` sets both `defaultPaginationLength` and `maxPaginationLength` to 200; laravel/mcp uses the smaller of the two, so raising only the default does nothing (the package's max is 50). The same page size applies to `resources/list`, `resources/templates/list` and `prompts/list`. Raise both before the server reaches 200 tools. A client may still ask for a smaller page (`per_page`, a laravel/mcp extension; the MCP spec only defines `cursor`) and follow `nextCursor`; a bigger request is capped at 200. `tests/Feature/Mcp/ToolListPaginationTest.php` calls the real endpoint and fails when the list is split.

## MCP sign-in inside ChatGPT

ChatGPT offers sign-in inside the conversation when a user who never connected (or whose token expired) asks for something (owner decision October 2026). Every other MCP client, Claude included, keeps the plain flow: a 401 on any request without a token, so it signs in when it connects. Claude stays there on purpose: it shows "Connect to Claude" on the connector page, and its own Connect card already appears in the chat when a token expires, because a tool call gets a 401. Giving Claude the anonymous discovery below would stop it from asking to sign in when the connector is added.

- **Recognising ChatGPT** goes through `App\Support\Mcp\ChatGptMcpClient::matches()`, by the egress ranges OpenAI publishes, the identification OpenAI documents: `chatgpt-connectors.json` (`trypost.mcp_chatgpt_ip_ranges_url`), read by `ListChatGptEgressRanges` on the first request that needs it and cached for an hour (a failed read for a minute); only valid IPv4 ranges of /8 or narrower are kept. Never by user agent: it is undocumented, and a local client sending the same one would lose its sign-in on connect. The IP is a hint, not a security boundary: behind `trustProxies(at: '*')` a client can fake it with `X-Forwarded-For`, which only lets it read the public tool list.
- **Without a token, ChatGPT may only connect and list tools** (`initialize`, `notifications/initialized`, `ping`, `tools/list`), through `AuthenticateMcp` and `LoadMcpWorkspace` (the MCP route's own workspace middleware: it lets that discovery through and hands every other request to the shared `LoadWorkspaceFromToken` with `mcp`, which the MCP change leaves untouched). A tool call always needs a token, so no tool ever runs without a user. A request that carries a token, valid or not, is always authenticated; Passport blanks the `Authorization` header when it refuses a token, so `AuthenticateMcp` records `ChatGptMcpClient::TOKEN_SENT` before it runs.
- **A refused tool call from ChatGPT** gets `ChallengeChatGptToolCalls`'s 200 tool result with `isError` and `_meta["mcp/www_authenticate"]`: ChatGPT never shows its sign-in prompt for a 401. 402 and 403 are unchanged.
- **Every tool declares `securitySchemes`** (`oauth2`, `mcp:use`) through `ListToolsWithSecuritySchemes`; ChatGPT offers sign-in only for tools that declare it, other clients ignore it.
- `tests/Feature/Mcp/ChatGptSignInTest.php` covers ChatGPT, Claude and other clients with no token, an invalid token and a valid one, and the cached ranges; every guard there fails its test when removed.

## MCP OAuth rate limit

All of `Mcp::oauthRoutes()` sits in one `throttle:mcp-oauth` group in `routes/ai.php`, and the `mcp-oauth` limiter (`AppServiceProvider`) limits only what can be abused: `POST /oauth/register` (public dynamic client registration, which writes an `oauth_clients` row), 30 a minute per IP. It never limits ChatGPT and Claude (the egress ranges OpenAI and Anthropic publish: `chatgpt-connectors.json` through `ListChatGptEgressRanges`, and `trypost.mcp_claude_ip_ranges`): each registers for all of its users from a few shared addresses, so a per-IP limit would hold every user to one budget (owner decision October 2026: we trust their ranges).

- The `/.well-known/oauth-protected-resource*` and `/.well-known/oauth-authorization-server*` GETs (and HEADs, `isMethodCacheable()`) are public static JSON and are never limited. Clients fetch them before every connection, even with a stored token (Codex desktop does it for each thread, from the user's own IP), and a 429 there makes the client drop the server, so its tools never appear.
- The exemption reads `$request->ip()`, so it holds as long as only real proxies are trusted (see "Trusted proxies"). With `TRUSTED_PROXIES=*` a client can fake an OpenAI or Anthropic address with `X-Forwarded-For`.
- `tests/Feature/Mcp/OAuthRegistrationTest.php` covers the limit, the exemption and the metadata.

## Trusted proxies (`config/trustedproxy.php`)

The client IP (`$request->ip()`, used by every per-IP rate limit and by the ChatGPT/Claude ranges) is only as good as the proxies Laravel trusts. There is one setting, `trustedproxy.proxies`, the key Laravel's `TrustProxies` reads; `bootstrap/app.php` does not call `trustProxies()` (owner decision October 2026).

- It defaults to the ranges Cloudflare publishes (https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6), written in the config file. TryPost Cloud runs on one machine behind Cloudflare, so the IP is the one Cloudflare appended and a client cannot fake it. When Cloudflare changes its lists (rarely), update the config.
- Another install sets `TRUSTED_PROXIES` to its proxy's IPs or ranges (comma-separated), or `*` to trust any proxy, which lets a client fake its IP with `X-Forwarded-For`. Never `trustProxies(at: '*')` in code: that was the bug.
- `tests/Feature/TrustedProxiesTest.php` covers Cloudflare (IPv4 and IPv6), a direct request, a listed proxy and `*`.

## MCP tool annotations

**Every MCP tool declares all three annotations explicitly, `false` included** (owner decision October 2026): `#[IsReadOnly(bool)]`, `#[IsDestructive(bool)]` and `#[IsOpenWorld(bool)]`, above its `#[Description]`. The OpenAI plugin directory refuses ("Not live") any tool without three explicit booleans, and the Claude directory requires `readOnlyHint` or `destructiveHint`. Annotations only describe the tool to the AI client (when to ask the user for confirmation); they never change what it does and are not rate limits.

- **readOnly**: `true` only when the tool reads or computes. Warming a cache or refreshing a token during a read still counts as read-only.
- **destructive**: `true` when the tool changes or removes existing data or sends something irreversible (updates, deletes, reorders, replacing a schedule, publishing, approving or rejecting, emails, webhook replays). `false` for purely additive writes. A read-only tool is never destructive.
- **openWorld**: `true` when the tool can publish to public networks, fetch arbitrary URLs, call or set arbitrary external endpoints, or read a public catalog. `false` inside the private workspace, including network APIs that only read the connected account's own data.
- A new tool goes into `mcpExpectedToolAnnotations()` in `tests/Feature/Mcp/ToolAnnotationsTest.php`; the test fails when a registered tool is missing from that list or has a different value. Borderline cases already decided are listed in `.ai/rules/tools.md`; follow them instead of deciding again.

## Database engines (PostgreSQL + MySQL)

TryPost runs on **both PostgreSQL and MySQL**. Cloud runs PostgreSQL; a self-hosted install may pick either. Every query, migration, and test must work on both — the suite is expected to be green on each.

- **What the app supports is the intersection of the two engines, never the superset of one.** When they differ, take the narrower behaviour — a feature that only holds on PostgreSQL is a feature TryPost does not have.
- Never use an engine-specific operator or function. Search uses `whereLike()` (Laravel handles the case-insensitive form per driver), never `ilike` or a raw `LOWER(...)` comparison.
- Traps that only surface on MySQL:
    - **JSON object key order is not preserved.** MySQL reorders object keys on storage (by length, then lexicographically); PostgreSQL keeps insertion order. Assert JSON read back from the database with `toEqual` (recursive, order-independent), never `toBe`/`assertSame`. Array *element* order is preserved on both.
    - **`$table->timestamp()` tops out at 2038-01-19.** PostgreSQL has no such limit, so 2038-01-19 is the app's ceiling: nothing written to a `timestamp()` column may go past it — scheduled posts, expiry sentinels and test fixtures alike. `2037-12-31` reads as "far future" and works on both. Do not widen a column to escape the limit without a deliberate decision; it changes what self-hosted MySQL installs can store.
    - **Raw query-builder reads carry no Eloquent cast**, so the driver's native shape leaks through: `DB::table(...)->value('some_bool')` is `true` on PostgreSQL and `1` on MySQL. Read through the model, or use `assertDatabaseHas`.
    - **Identifier quoting differs** — PostgreSQL emits `"posts"`, MySQL emits backticks. Never match logged SQL (`DB::listen`) against a quoted identifier.
    - **MySQL refuses to drop the only index backing a foreign key** (SQLSTATE `1553`). A migration `down()` that drops a unique whose leftmost prefix is an FK column must create a standalone index for that column first.
    - **DDL implicitly commits**, which defeats `RefreshDatabase`'s rollback: schema changes made inside a test leak into the tests that follow. Keep them idempotent.

## One destination per post (`posts` carries its channel)

A post has at most one channel, stored on the post row: `social_account_id`,
`platform`, `content_type`, `meta`, the channel snapshot (`platform_name`,
`platform_username`, `platform_avatar`) and the publication result
(`publish_status`, `platform_post_id`, `platform_url`, `error_message`,
`error_context`, `thread_reply_ids`, `submitted_at`, `last_reconciled_at`,
`retry_at`, `connection_warning_sent_at`). The `post_platforms` table was merged
into `posts` in October 2026 (owner decision); never reintroduce a targets table,
an `enabled` flag or posts with several channels. Posts created together share
`post_group_id`.

- **Two status columns.** `posts.status` is the lifecycle (draft,
  pending_approval, scheduled, publishing, published, failed). `posts.publish_status`
  (`App\Enums\Post\PublishStatus`) is the network side (pending, publishing,
  retrying, pending_review, published, failed, rejected). Publishers write
  `publish_status` through the `markPublication*()` methods; only
  `FinalizePostPublication` moves `status` to published or failed. There is no
  `partially_published`.
- **Publication writes have their own clock.** `writePublication()` (and every
  `markPublication*()`) sets `publication_updated_at` and leaves `updated_at`
  alone, so a publish attempt never moves the post's `updated_at` (API, webhooks)
  and a label or note never moves the publication clock. System writes by query
  (`toBase()->update`) follow the same rule.
- **A draft may have no channel.** Drafts written before every post had one
  channel keep `platform` null. They stay editable as drafts and become channel
  posts only through `RecoverEmptyDraft`; scheduling or publishing one fails with
  `posts.errors.choose_channel`. `platform`/`content_type` therefore stay nullable.
  `Post::hasDestination()` (platform set) and `Post::hasChannel()` (account still
  there) are the two checks.
- **Not fillable on purpose:** `social_account_id`, `platform_post_id`,
  `publish_status`, `scheduled_before_media_checks` — set them
  with `forceCreate`/`forceFill` in `CreateChannelPost`, `ImportExternalPosts` and
  the publication methods only.
- **Public contract:** API, MCP and webhook payloads carry the channel fields at
  the top of the post (no `platforms[]`); inputs take top-level
  `social_account_id` / `content_type` / `meta`, and validation errors are keyed
  `meta.*` (`destinations.{i}.meta.*` in batches).

## Per-Platform Post Meta (`posts.meta`)

- All `meta` validation (the parent array rule AND every per-platform sub-key: TikTok `privacy_level`/flags, Pinterest `board_id`, Discord `channel_id`/`mentions`/`embeds`, etc.) lives in ONE place: `App\Support\PostPlatformMetaRules`.
    - Every post create/update entry point — web (`App\Http\Requests\App\Post\UpdatePostRequest`), public API (`App\Http\Requests\Api\Post\{Store,Update}PostRequest`), and MCP (`App\Mcp\Tools\Post\{Create,Update}PostTool`) — spreads `...PostPlatformMetaRules::rules()`. NEVER add a per-platform meta rule inline to a single request/tool.
    - Why: `FormRequest::validated()` (and MCP `$request->validate()`) STRIPS any key without a rule. A meta field defined in only one entry point is silently dropped everywhere else — which is exactly how Discord/Pinterest/TikTok meta was lost via API/MCP before this was centralized.
- Required-on-publish (meta a platform needs to publish, e.g. Discord `channel_id`) also lives there: `addRequiredOnPublishErrors()` for request-driven flows (web/API update `withValidator`), `assertStoredPostPublishable()` for flows that publish stored state without resubmitting platforms (MCP `PublishPostTool`). Add new required-meta rules to `requiredMetaViolation()`, not inline.
- When adding a new platform's meta field, add it (and any publish requirement) to `PostPlatformMetaRules` ONLY, and cover it in `tests/Feature/Api/PostApiPlatformMetaTest.php` + `tests/Feature/Mcp/PostPlatformMetaToolTest.php`.
- `PostPlatformMetaRules::formatViolation()` holds meta no post may store, even as a draft (a YouTube title with `<` or `>`). Every create and update path (web, API, MCP, repurpose) goes through `App\Support\PostCompositionValidator`, which runs it and, once the post is scheduled or published, the content limits below.
- **What a post stores in `meta` goes through `App\Support\PostMeta::forChannel($account, $meta, $stored)`, on create (`CreateChannelPost`) and on update (`UpdatePost`, passing the post's current meta as `$stored`).** It normalizes the meta, keeps the system keys the post already had (`PostPlatformMetaRules::SYSTEM_KEYS`, today Telegram `reactions`) and has one `match` arm per network that derives something. Never write `posts.meta` from a request without it, and never add a second helper next to it.
- **A derived value is never taken from the request.** Only the id comes in; the server resolves what it names. The Discord `channel_name` has no validation rule: `PostMeta` drops any sent value and reads the name of `channel_id` from the guild's channel list in the cache (`DiscordClient::cachedChannels()`, warmed by the composer, the API, MCP and publishing). Without a cached list the post stores no name; that is accepted (owner decision October 2026). The composer may keep the name in its local meta for the live preview; the server ignores it.
- **Saving never calls a network.** `PostMeta` runs inside the post's transaction and the channel queue lock, so a lookup there reads a cache only, never HTTP. A save never fails over a display name.

## Content limits

- **Per account, not per platform.** Read the cap through `SocialAccount::maxContentLength()`. It is the platform's `Platform::maxContentLength()`, except an X account with long posts (`hasXLongPosts()`: a `Basic`, `Premium` or `PremiumPlus` subscription, or `verified_type=business`), which gets 25000. The tier lives in the account meta and is written by `SyncXSubscription`, called from `ConnectionVerifier` on connect and on the daily `social:check-connections`.
- `Post::CONTENT_MAX_LENGTH` (25000 characters of plain text) is the ceiling of any post, enforced by `PostContentFitsMaxLength`.
- `Platform::reservedLength()` counts what the network adds to the text: the Mastodon content warning (trimmed like `Str::trim`) counts toward the 500.
- `Platform::maxHashtags()` is 5 on Instagram (both platforms). It is a **save-time** rule (web, API, MCP), never a publish-time failure: a post stored with more still publishes. Repurpose captions are trimmed to the first 5 by `CaptionAdapter` through `Hashtags::keepFirst()`. Hashtags are counted by `App\Support\Hashtags`, mirrored by `resources/js/lib/hashtags.ts` and kept identical by `HashtagParityTest`.
- A captionless content type (`ContentType::isCaptionless()`: Instagram and Facebook Stories, mirrored by `CAPTIONLESS_CONTENT_TYPES`) sends no text, so it is measured for neither length nor hashtags, at save, at publish or in the composer.
- `App\Rules\ContentFitsPlatformLimits` (save) and `HasSocialHttpClient::validateContentLength()` (publish) measure the same thing; keep them in step. Every counter counts code points (`mb_strlen`; `characterCount()` in `resources/js/lib/characters.ts`), so an emoji is one.

## Thread replies

- Bluesky, Mastodon and X (`App\Support\ThreadReplies`), up to 24 replies in `meta.thread_replies`. Each reply is measured against the **account's** limit (`SocialAccount::maxContentLength()`), so an X account with long posts gets 25000 per reply; pass the account to `ThreadReplies::violation()` / `PostPlatformMetaRules::requiredMetaViolation()` whenever it is known.
- Each reply is `{text, media}` (a stored plain string is a text-only reply; `ThreadReplies::of()` normalizes both, `PostPlatformMetaRules::normalize()` stores objects). Its media belongs to that reply only, never to the root: it publishes as `ThreadReplies::replyContentType()` (X → `XPost`, Bluesky → `BlueskyPost`, Mastodon → `MastodonPost`), so it follows that type's media rules (`ThreadReplies::mediaErrors()`, at save and at publish), and a reply needs text or media. The frontend mirror is `THREAD_REPLY_CONTENT_TYPES`. Adding a network means one entry in each map plus a reply callback in its publisher.
- Reply media rows are owned by the post like its root media. `SyncOwnedMedia` resolves them from the post's stored `meta.thread_replies` and writes the canonical items back, so write the target's meta **before** calling it; it never deletes a row a reply still uses. Every segment's checkpoint hash covers its media ids.
- In the composer each post of a thread shows its own media under it; the toolbar, paste, drop and the toolbar upload button add to the active post.
- X replies go through `POST /2/tweets` with `reply.in_reply_to_tweet_id` on the previous segment. X restricts API replies (February 2026) to posts whose author summoned the replier; replying to your own post is the case that stays allowed. Each reply is billed as a post.
- Each segment already live is checkpointed in `posts.error_context.thread_progress` (`App\Support\Social\ThreadProgress`), so a retry resumes instead of re-posting, and a resume keeps the root hash. `posts:retry` keeps the live segments.
- `posts.thread_reply_ids` lists the reply ids so `ImportExternalPosts` does not import TryPost's own replies as new posts.

## Composer steps

- Step 1 is one shared editor. "Customize for each network" opens one card per network, in channel-list order, grouped by `platform`; each card starts as a copy of the shared text.
- Going back to step 1 discards every per-network override, including post type and settings.
- Settings in `ACCOUNT_SCOPED_SETTINGS` (Pinterest, Discord, TikTok) stay per account; every other meta fans out to all accounts of the network.

## Link preview card

- `meta.link_preview === false` (the × on the card) is honoured only by the Facebook post, Bluesky and LinkedIn publishers, through `Post::attachesLinkPreview()`. Threads has no ×: its API always cards the first link.
- "Replace link preview with media" goes through `LinkPreviewMediaController`.

## YouTube

- Without `meta.title`, the title is the first non-empty line of the post's plain text, with `<` and `>` removed, cut to 100 code points, and no ` #Shorts` (`YouTubeMetadata::title()`); `YouTubeSettings.vue` fills the Title field with the same rule.
- YouTube rejects an upload without a title (`invalidTitle`). A post with neither `meta.title` nor any text cannot be scheduled or published there: `PostPlatformMetaRules::contentMetaViolation()` (through `YouTubeMetadata::missingTitleViolation()`) reports it on `meta.title` in `PostCompositionValidator` (web, API, MCP, repurpose) and `assertStoredPostPublishable()`, and the publisher checks it again. Drafts may lack both.
- Categories are a fixed list, `App\Enums\YouTube\Category`; the composer reads it from `Platform::publishConfig()` (`categoryOptions`, `defaultCategoryId`). Do not copy it into TypeScript.

## Instagram reels, music, collaborators and tags

What a post can carry on Instagram depends on how the account is connected: through Instagram Login (`Platform::Instagram`) or through a Facebook Page (`Platform::InstagramFacebook`). The rules live in `PostPlatformMetaRules::instagramReelViolation()` (save, schedule and publish) and `InstagramPublisher` (publish); keep the two in step.

- **Music (`meta.audio`)** is for reels on an account connected through Facebook only: Instagram's audio search and `audio_configuration` exist only on that API. The track id is numeric everywhere: the audio routes (`whereNumber('audioId')`), the MCP tools and the `meta.audio.id` rule. A draft may keep music without an id; scheduling or publishing it is refused.
- **Reel-only options** (`trial_graduation`, `audio`, `share_to_feed`) apply to `ContentType::InstagramReel` only. A feed post with a single video still publishes as `REELS`, but without them (`InstagramPublisher::reelOptions()`). A trial reel cannot have collaborators.
- **Music on an account that is not connected through Facebook fails the publish visibly** (`ContentPolicy`, `posts.form.instagram.music.requires_facebook`) instead of being dropped. This happens when a card connected through Facebook is reconnected through Instagram Login: its pending posts move to the new platform with their meta untouched. The user removes the music or reconnects through Facebook, then retries. Never strip the music silently, at reconnect or at publish.
- **Collaborators are not people tags.** Collaborators (`meta.collaborators`, up to 3 usernames, stored without `@` and deduplicated) are co-authors invited on a feed post, carousel or reel, never a story. People tags belong to a media item (`media.*.meta.user_tags`), next to a video's cover frame (`meta.cover_offset_ms`). Both work the same on the web, the API and MCP.
- **One Instagram identity, two ways to connect it.** An account connected through Instagram Login stores its Instagram account id in `social_accounts.meta.ig_user_id` (asked from `/me` on connect, filled for older accounts by the daily connection check). Comparing identities across the two platforms goes through `SocialAccount::isIdentity()` (and `SocialController::isIdentityOf()`), never a raw `platform_user_id` comparison, so one Instagram account is never seated twice and a card can move between the two connection methods.
- **The system adds a key to an account's meta without losing what a reconnect saved meanwhile.** `ConnectionVerifier` reads the account again under a row lock, adds `ig_user_id` to that fresh meta and saves it quietly without timestamps, so `updated_at` does not move. Never write the in-memory meta of a model loaded earlier back over the column.

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
- Assertions do NOT auto-wait on SPA paint (`assertMissing`/`assertVisible` check once; only actions like `click()` wait). **Every wait goes through the shared helpers in `tests/Pest.php`** (owner decision October 2026), never through a helper of your own:
    - Every helper takes a CSS selector where `@name` stands for `[data-testid="name"]`, as in `$page->click('@name')`, anywhere in it: `'@channel-settings-rows [role="combobox"]'`, `'@channel-settings-row:has(@instagram-collaborators)'`. Prefer `@` test ids; reach for a `role` or `data-slot` only when the element has no test id, and never a CSS class.
    - `waitFor($page, '@id')`: an element is mounted and laid out.
    - `waitForGone($page, '@id')`: no element matches.
    - `waitForState($page, '@id', "element.getAttribute('aria-checked') === 'true'")`: an element exists and the expression about `element` is true (attributes, `dataset`, `disabled`, position).
    - `waitForText($page, '[role="option"]', 'general')`: any matching element contains the text.
    - `waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog')`: whichever happens first, so the assertion after it fails at once instead of waiting out the timeout.
    - `waitForPath($page, $path)`: the page is at that path.
    - `waitForCondition($page, $js)`: only for what is not about an element (`location.search`, a `window.__` spy).
    - Never write `document.querySelector(...)` inside a wait, and never define a per-file `waitFor*` helper. Pest's `Webpage` is `final readonly` and not macroable, which is why these are global functions. Files that still have their own `waitFor*` helpers predate this rule; move them to the shared helpers when touching them (the sweep is tracked in #435). If a wait needs something the helpers cannot express, add a helper to `tests/Pest.php` instead of inlining it.
- **Never `sleep()` in a browser test.** The HTTP server that serves the page runs inside the same PHP process (an Amp loop that only ticks while Pest awaits Playwright), so a blocking `sleep()` starves every asset request: the page stays blank, the Vue app never mounts, and screenshots come out empty. The helpers above poll from the page with `$page->script(...)`, which keeps the loop running.
- `BrowserTestCase` sets `$fakesVite = false` on purpose: these tests load real built assets, so faking Vite blanks the app.
- End page assertions with `->assertNoJavaScriptErrors()`.
- Each `visit()` opens a new browser context: `localStorage`, cookies and history are not shared with the previous visit.
- The page starts on `about:blank`, so a `back()` with nothing behind it can land there and a test can pass for the wrong reason. A history test starts from an app page (`visit(...)->navigate(...)`) and asserts where it ended with `assertPathIs()`.
- `assertQueryStringMissing()` checks only the query string: on a route whose id is in the path (`app.posts.edit`) it passes whatever happens. Assert the path instead.
- A new test for a fix must fail without the fix. Check it by removing the change, running the test, and restoring it.
- CI runs them in three shards, each in parallel with three processes (`./vendor/bin/pest tests/Browser --compact --parallel --processes=3 --shard=N/3`), leaving a CPU of the 4-vCPU runner to the database and the app server, against `npm run build` output, so keep them independent of a running dev server and of each other. Run them in parallel through `./vendor/bin/pest`, never `php artisan test --parallel`, which does not start the Playwright server. A test that only passes alone has a race: wait for the state you assert (poll the DOM or the database) instead of assuming timing.

## Array Data Access

- In Action classes and similar service classes, ALWAYS use Laravel's `data_get()` helper instead of direct array access.
    - Example: `data_get($data, 'name')` instead of `$data['name']`.
    - Use the third parameter for fallback values: `data_get($data, 'username', $sender->username)` instead of `$data['username'] ?? $sender->username`.

## Eloquent Models & Morph Map

- EVERY Eloquent model in `app/Models` MUST be registered in `Relation::enforceMorphMap([...])` inside `AppServiceProvider::configureMorphMap()`, keyed by a camelCase alias (e.g. `'postTemplate' => PostTemplate::class`).
- When you add a new model, add it to the morph map in the same change. `tests/Unit/MorphMapTest.php` fails if any model is missing.
- The alias is persisted in polymorphic columns, so never rename or remove an existing alias for a model that has stored rows.
- **Models never make HTTP calls** (owner decision October 2026). A model holds pure rules over its own data (`SocialAccount::isIdentity()`); anything that asks a network lives in a service or an action (`ConnectionVerifier`, `DiscordClient`, the publishers) and writes the result back.

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
- The editor counts characters and renders the X preview client-side, so the rewrite is mirrored in `resources/js/lib/defuseXLinks.ts`. The TLD list is NOT duplicated there: `HandleInertiaRequests::shareOnce()` shares `App\Support\LinkTlds::all()` as the `xLinkTlds` once prop (sent on the first page load, then remembered by the client), only to signed-in users and only while defusing is on — an absent or empty set means the feature is off, since without the list a bare host cannot be told from `Node.js`. Keep it a once prop of its own; never fold it into a per-request prop or the `composer` bundle. Two tests keep the mirror honest: `XLinkDefusingParityTest` runs a shared corpus through both engines over the same list and diffs the output, and `tests/Browser/XLinkDefusingTest.php` drives the real editor.
- Neither expression may use lookbehind. Safari only understands it from 16.4, esbuild cannot transpile it, and a `SyntaxError` there takes down the whole chunk — the character before a candidate URL is consumed and put back instead.

## Legacy queued-job compatibility (TryPost 2.0 deploy)

Jobs, mails and broadcasts queued by the pre-2.0 `main` release are still in the queues when 2.0 is deployed, and a worker that cannot unserialize one fails it. These classes exist only so those payloads finish:

- `App\Enums\Notification\Channel` (`SendNotification` payloads from main).
- `App\Enums\Notification\Type::PostReady` and `Type::MentionedInComment`.
- `App\Events\NotificationCreated`, `App\Events\PostCommentCreated`, `App\Events\Ai\PostCreationReady` and `App\Events\Ai\PostMediaRegenerated` (queued broadcasts that finish without broadcasting).
- `App\Mail\MentionedInComment` (dropped without sending).

No new code may use them. The two `Type` cases never appear in a user-facing list (preferences UI, validation rules, `cases()` iteration) and `User::wantsEmailFor()` answers `false` for them; `tests/Feature/Jobs/LegacyQueuedPayloadsTest.php` covers both. Remove all of it one release after 2.0, once the queues hold no main-era payloads.

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
