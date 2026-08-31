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

## Stripe Checkout (env knobs)

Checkout options are configured only via env — do not hardcode trial/coupon/promo behavior in controllers. All of it goes through `App\Support\Billing\ConfigureSubscriptionCheckout` (called from `StartSubscriptionCheckout`).

| Env | Config | Default | Effect |
| --- | --- | --- | --- |
| `REQUIRE_CARD_FOR_TRIAL` | `trypost.billing.require_card_for_trial` | `true` | `true`: app access only after Stripe Checkout (no generic signup trial). `false`: generic `accounts.trial_ends_at` trial without a card |
| `CASHIER_TRIAL_DAYS` | `cashier.trial_days` | `8` | Card-required Checkout: `trialDays(N)` for **first-time** subscribers when no first-month coupon is applied (`0` = off). Re-subscribers skip trial. No-card mode: length of the generic signup trial |
| `STRIPE_FIRST_MONTH_COUPON_ID` | `cashier.first_month_coupon_id` | empty | Optional. When set for a qualifying first-time single-workspace checkout, applies `withCoupon` and **skips** trial. Empty = trial mode |
| `CASHIER_ALLOW_PROMOTION_CODES` | `cashier.allow_promotion_codes` | `false` | When `true` and no coupon is applied, show the Checkout promo-code field |

Standing constraints:
- Stripe rejects `discounts` (coupon) and `allow_promotion_codes` on the same session — if both would apply, `ConfigureSubscriptionCheckout` must throw (fail loud). Never “prefer one silently.” Envs may both be set when the account does **not** qualify for the coupon (no throw).
- A set first-month coupon wins over trial (`trialDays` is skipped for that checkout).
- Empty coupon + card required + first-time must use `trialDays` — do **not** reintroduce a required-coupon throw.
- Coupon qualification stays: card required, exactly one workspace, no prior real subscription (`incomplete` / `incomplete_expired` still qualify).
- Prefer documenting durable billing decisions here (and in `CLAUDE.md`) — do **not** create a `.ai/` rules folder for this project.

## Multiple social accounts per network

One connected identity per social network per workspace is the Cloud default. This is **not** tied to `SELF_HOSTED` — Cloud cannot flip that flag, but it can flip this one.

| Env | Config | Default | Effect |
| --- | --- | --- | --- |
| `ALLOW_MULTIPLE_SOCIAL_ACCOUNTS` | `trypost.allow_multiple_social_accounts` | `false` (falls back to `SELF_HOSTED` when unset) | `true`: a workspace may connect more than one account of the same network (two LinkedIns, two Instagrams, …). `false`: one per network (LinkedIn profile + page count as one; Instagram standalone + Instagram-via-Facebook count as one). Reconnecting the same `platform` + `platform_user_id` still updates the existing row. Shared to Inertia as `allowMultipleSocialAccounts`. |

Self-hosted compose / `.env.example` set this `true`. When the env is unset, the config falls back to `SELF_HOSTED` so existing self-hosted installs keep multiple accounts. Do **not** use `selfHosted` for the occupancy check (observer, Telegram connect, `NetworkConnectGrid`).

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
- Character limits are measured against the **sanitized** content — the string the publisher actually sends — in both `App\Rules\ContentFitsPlatformLimits` (save/schedule) and `HasSocialHttpClient::validateContentLength()` (publish). The editor stores HTML and per-platform rules change the length again, so measuring the raw draft blocks saving posts that publish fine and lets through posts the network rejects. Keep the two in step.
- Tests enable it explicitly with `config()->set('trypost.platforms.x.defuse_links', true)` rather than pinning an env, so the suite runs against the shipped default.
- The editor counts characters and renders the X preview client-side, so the rewrite is mirrored in `resources/js/lib/defuseXLinks.ts`. The TLD list is NOT duplicated there: `PostController@edit` sends `App\Support\LinkTlds::all()` as the `xLinkTlds` page prop, and only when defusing is on — an empty set means the feature is off, since without the list a bare host cannot be told from `Node.js`. Do not move it to the Inertia shared props; only the editor needs it. Two tests keep the mirror honest: `XLinkDefusingParityTest` runs a shared corpus through both engines over the same list and diffs the output, and `tests/Browser/XLinkDefusingTest.php` drives the real editor.
- Neither expression may use lookbehind. Safari only understands it from 16.4, esbuild cannot transpile it, and a `SyntaxError` there takes down the whole chunk — the character before a candidate URL is consumed and put back instead.

