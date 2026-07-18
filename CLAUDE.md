<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/ai (AI) - v0
- laravel/boost (BOOST) - v2
- laravel/cashier (CASHIER) - v16
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/mcp (MCP) - v0
- laravel/nightwatch (NIGHTWATCH) - v1
- laravel/passport (PASSPORT) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/socialite (SOCIALITE) - v5
- laravel/wayfinder (WAYFINDER) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- laravel/telescope (TELESCOPE) - v5
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA_VUE) - v3
- tailwindcss (TAILWINDCSS) - v4
- vue (VUE) - v3
- @laravel/echo-vue (ECHO_VUE) - v2
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- laravel-echo (ECHO) - v2
- prettier (PRETTIER) - v3

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

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

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

## Dialogs

- In `<DialogFooter>`, put the **primary action button first** in the markup, then secondary/cancel (e.g. Save → Cancel). `DialogFooter` uses `flex-col` on mobile (primary on top, cancel at the bottom) and `sm:flex-row sm:justify-start` on desktop, so the first child is the leftmost action on larger screens.
- Match sibling dialogs in the same feature area before inventing a new footer layout.

## AI agents (`app/Ai/Agents`)

- **Never** embed prompts in PHP (`<<<PROMPT`, heredocs, or long string literals in `instructions()`).
- Put system/instruction text in Blade under `resources/views/prompts/` (e.g. `prompts.post_content.generator`, `prompts.post_image.regenerator`).
- In `instructions()`, return `view('prompts....', [...])->render()` and pass only the variables the Blade file needs — same pattern as `PostContentStreamer`, `PostContentReviewer`, and `BrandAnalyzer`.

## System AI (always allowed, never metered)

- The brand analyzer / workspace autofill (`App\Services\Brand\BrandAnalyzerRunner`, `App\Actions\Ai\AutofillBrand`, `WorkspaceController::autofillBrand`) is a **system** feature, not the user's AI usage. It runs during workspace creation, before the user has AI access.
- It MUST always be allowed: NEVER gate it behind the `useAi` policy, an active subscription, or a credit check.
- It MUST NOT deduct anything: NEVER call `RecordAiUsage` (or otherwise consume the account's credits) for brand analysis. Cost is the platform's, not the user's.
- Any future "system" AI helper (runs as part of the platform, not on behalf of a workspace's metered quota) follows the same rule: ungated and unmetered.

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
    - The only exception is the public REST API (`app/Http/Controllers/Api`), which uses its own fixed, documented page size (15) as a stable API contract.

## Form Validation

- NEVER use HTML5 validation attributes (`required`, `minlength`, `pattern`, etc.) on form inputs. Always rely solely on backend validation.

## Backend Validation

- Validation rules always live in a dedicated `Illuminate\Foundation\Http\FormRequest` subclass under `app/Http/Requests/App/<Group>/`. Controller actions must type-hint the FormRequest as the parameter — NEVER call `$request->validate([...])` inline in the controller.
- Naming: `<Verb><Resource>Request.php` (e.g. `StorePostRequest`, `ApplyPostTemplateRequest`, `IndexPostTemplateRequest`).

## Per-Platform Post Meta (`PostPlatform.meta`)

- All `platforms.*.meta` validation (the parent array rule AND every per-platform sub-key: `aspect_ratio`, TikTok `privacy_level`/flags, Pinterest `board_id`, Discord `channel_id`/`mentions`/`embeds`, etc.) lives in ONE place: `App\Support\PostPlatformMetaRules`.
    - Every post create/update entry point — web (`App\Http\Requests\App\Post\UpdatePostRequest`), public API (`App\Http\Requests\Api\Post\{Store,Update}PostRequest`), and MCP (`App\Mcp\Tools\Post\{Create,Update}PostTool`) — spreads `...PostPlatformMetaRules::rules()`. NEVER add a per-platform meta rule inline to a single request/tool.
    - Why: `FormRequest::validated()` (and MCP `$request->validate()`) STRIPS any key without a rule. A meta field defined in only one entry point is silently dropped everywhere else — which is exactly how Discord/Pinterest/TikTok meta was lost via API/MCP before this was centralized.
- Required-on-publish (meta a platform needs to publish, e.g. Discord `channel_id`) also lives there: `addRequiredOnPublishErrors()` for request-driven flows (web/API update `withValidator`), `assertStoredPostPublishable()` for flows that publish stored state without resubmitting platforms (MCP `PublishPostTool`). Add new required-meta rules to `requiredMetaViolation()`, not inline.
- When adding a new platform's meta field, add it (and any publish requirement) to `PostPlatformMetaRules` ONLY, and cover it in `tests/Feature/Api/PostApiPlatformMetaTest.php` + `tests/Feature/Mcp/PostPlatformMetaToolTest.php`.

## Media Types (image / video / document)

- A media item is one of exactly three types: **image**, **video**, **document** (PDF). There is no standalone "audio" media type (audio exists only as a video voiceover input).
- Media-type detection lives in ONE place per side — NEVER hand-write `type === 'image'`, `mime_type === 'application/pdf'`, `mime.startsWith('video/')`, or extension checks inline.
    - Backend: `App\Enums\Media\Type` — `classify()`, `fromMime()`, `fromExtension()`, `isGif()`, plus the `allowedMimeTypes()` / `extensions()` allow-lists. Use these, never a raw MIME/extension comparison.
    - Frontend: `resources/js/lib/mediaType.ts` — the mirror of the backend enum: the `MediaType` union, `classify()`, `fromMimeType()` (for a browser `File.type`), `fromExtension()`, `isImage()`/`isVideo()`/`isDocument()`/`isGif()`. `@/composables/useMedia` re-exports `isImageMedia`/`isVideoMedia`/`isDocumentMedia` aliases for legacy call sites.
    - Detection trusts the explicit `type` first, then the MIME, then the filename extension — so an item with only a MIME (e.g. AI/Unsplash/Giphy media without a `type`) still classifies correctly. A bare `item.type === 'image'` (with a `v-else` video) silently mis-renders those.
- The `type` field on every media-ish interface is the `MediaType` union, never `string` — `MediaItem`, and any sibling picked/asset/saved shape (`PickedMedia`, `AssetMedia`, `SavedMedia`, etc.).
- The upload `accept` attribute for "everything we allow" comes from `acceptAttribute()` (frontend) / `Media\Type::allowedMimeTypes()` (backend) — never a hardcoded MIME list. Per-capability `accept` builders driven by content-type rules (e.g. `image/*,video/*`) are fine; those aren't detection.

## Pest / Feature Tests

- ALWAYS use named routes via the `route()` helper in feature tests. NEVER hardcode URL strings like `'/posts/ai/create'`.
    - Example: `$this->postJson(route('app.posts.store'))` instead of `$this->postJson('/posts')`.
    - With params: `route('app.posts.ai.create.finalize', $creationId)`.

## Dusk (Browser Tests)

- In Dusk tests, ALWAYS use named routes via `route()` helper. NEVER hardcode URLs like `'https://trypost.test/login'`.
    - Example: `$browser->visit(route('login'))` instead of `$browser->visit('https://trypost.test/login')`.
- ALWAYS use `dusk` selectors (`@selector-name`) for interacting with and asserting elements. NEVER use CSS classes (`.text-red-600`), tag names, or text strings.
    - Add `dusk="my-element"` attributes to Vue components and use `$browser->click('@my-element')`, `$browser->waitFor('@my-element')`, etc.
    - Example: `$browser->waitFor('@input-error')` instead of `$browser->waitFor('.text-red-600')`.

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

## TryPost.it Documentation

- All our documentation to final user it's under https://docs.trypost.it

## Git

- NEVER add `Co-Authored-By` lines to commit messages.
- NEVER commit, push, or open PRs unless explicitly asked by the user.
- Always create a new branch for feature work before making changes.
