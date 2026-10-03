---
paths:
  - 'app/**'
---

# App

## Reuse model scopes for canonical state filters
When a model already exposes a scope for a recurring state filter, jobs, commands, services, observers, and controllers must use that scope instead of repeating raw where clauses. Add a descriptive model scope when a canonical state condition will be reused (for example SocialAccount::connected()->active()).

## Prefer Eloquent/query builder over raw SQL
Always use Eloquent and query-builder methods (where*, orderBy*, latest, scopes, relations) instead of hand-written SQL (orderByRaw, whereRaw, selectRaw, DB::raw). Only fall back to raw SQL when the builder genuinely has no equivalent, and then keep it in one named model scope (e.g. Post::scopeOrderBySentAt) so the raw expression lives in a single place. User decision, 2026-10-02.
