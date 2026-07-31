# TryPost — Code Review Instructions

Laravel 13 + Inertia 3 (Vue 3 + TypeScript) + Tailwind 4, PHP 8.4, Pest 5. Flag violations of these project conventions; cite `file:line`. Skip nits the linters (Pint/ESLint) already catch.

## Backend validation
- Validation MUST live in a `FormRequest` subclass under `app/Http/Requests/App/<Group>/` (or `Api/`), type-hinted in the controller action. Name `<Verb><Resource>Request` (e.g. `StorePostRequest`). Flag any inline `$request->validate([...])` in a controller.

## Eloquent & responses
- API JSON MUST go through an Eloquent API Resource (`JsonResource`). Flag `response()->json([...])` that maps models inline.
- Explicit status codes use `Symfony\Component\HttpFoundation\Response` constants — `Response::HTTP_CREATED`, never `201`.

## PHP idioms
- In Action/service classes use `data_get($data, 'key', $default)` — flag direct `$data['key']` or `$data['k'] ?? $x`.
- Imports at the top via `use`; flag inline refs like `\DB::`, `\Str::uuid()`.
- Double-quoted interpolation with curly braces: `"workspace.{$id}"`, not `'workspace.'.$id`.
- Constructor property promotion; explicit return types + param type hints; `declare(strict_types=1)`; curly braces on every control structure (even one-liners); TitleCase enum keys; prefer PHPDoc (with array-shape types) over inline comments.

## Migrations (app is in production)
- Schema changes go in NEW migrations. Flag any edit to an existing migration file.

## Storage & external URLs
- Never pass a disk name to `Storage::` or `->store()` — use the default disk.
- Third-party API hosts / OAuth endpoints come from `config/trypost.php` (`platforms.<name>`), never hardcoded (e.g. `https://api.x.com`). Tests must `Http::fake` the same `config(...)` value, not a literal URL. Only the host is config; protocol path segments (`/oauth2/token`) stay inline.

## AI agents (`app/Ai/Agents`)
- Never embed prompts in PHP (heredocs / long string literals in `instructions()`). Instruction text lives in Blade under `resources/views/prompts/`; return `view('prompts....', [...])->render()` passing only needed vars.

## Frontend (Vue / TypeScript)
- Arrow functions only — flag `function` declarations.
- Icons from `@tabler/icons-vue` (Icon-prefixed, e.g. `IconCheck`). Flag `lucide-vue-next`.
- Dates: `@/dayjs` for calculation, `@/date` for display formatting — flag raw `new Date()`.
- Routes: Wayfinder helpers from `@/routes` / `@/actions` — flag hardcoded URLs like `href="/register"`. After route/controller changes, `wayfinder:generate` must be run.
- No HTML5 validation attributes (`required`, `minlength`, `pattern`, …) — rely solely on backend validation.
- Vue components have a single root element. Reuse existing components/composables before adding new ones.
- `<DialogFooter>`: primary action button FIRST in the markup, then cancel/secondary.

## Pagination
- Use `->paginate()` only (never `cursorPaginate()`). Paginated lists use Inertia scroll (`Inertia::scroll()` + `<InfiniteScroll>`) — flag traditional page-number/link pagination.

## Tests (required)
- Every behavioral change needs a test, new or updated. Flag logic changes shipped without tests.
- Feature & Dusk tests MUST use named routes via `route('...')` — flag hardcoded URL strings. Dusk interacts/asserts via `@dusk` selectors, never CSS classes, tags, or text.

## Git
- No `Co-Authored-By` lines or AI attribution in commit messages or PR descriptions.
