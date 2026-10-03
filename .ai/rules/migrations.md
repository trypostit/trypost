---
paths:
  - 'app/Jobs/PostHog/**'
  - app/Models/PostPlatform.php
  - 'database/migrations/**'
  - app/Models/SocialAccount.php
---

# Migrations

## Keep publishing activity lookup scoped and minimal
Account publishing activity must scope through indexed account workspace/post IDs before ordering PostPlatform rows. Its migration only adds conventional Laravel indexes and must not change PostPlatform.published_at precision. PostHog snapshot retries must keep progressive backoff so transient outages do not exhaust attempts immediately.

## Enum-backed column defaults use the enum
Columns backed by a PHP enum are plain `string` columns (never `$table->enum()`, which breaks PG/MySQL parity), cast to the enum on the model. Their migration default must reference the enum, not a literal: `->default(Theme::DEFAULT->value)` (give the enum a `DEFAULT` constant, like `App\Enums\User\Locale::DEFAULT`), and the model's `$attributes` uses the same constant. If an enum is later deleted, the migrations that import it must be edited in the same change (as done for ImageStyle in 2026_05_07_231552).

## ideas.workspace_id FK name orders the MySQL cascade
The `ideas.workspace_id` foreign key is named `idea_cascade_workspace_id_foreign` on purpose. On MySQL, deleting a workspace cascades into both `ideas` and `idea_stages`; if `idea_stages` goes first, its `ideas.idea_stage_id` SET NULL hits idea rows already being cascaded away (SQLSTATE 1452). The name makes InnoDB cascade `ideas` first. Never rename it; `tests/Feature/Models/IdeaModelTest.php` (workspace delete cascade) fails on MySQL if it breaks. Apply the same trick to any new table with two cascading FKs to one parent where one child SET NULLs the other.

## Default idea stages: observer + job, backfill without app code
New workspaces get To Do / In Progress / Done from `WorkspaceObserver::created` → `SetupWorkspaceDefaults` (afterCommit) → `CreateDefaultIdeaStages`, which does nothing when the workspace has any stage. There is no `seeded_at` column and pages never seed, so deleted stages stay deleted. Existing workspaces are backfilled inside `2026_09_30_154338_create_idea_stages_table.php` with plain `DB::table` batched inserts (owner locale via `users`, `Str::uuid7()` ids): data backfills in migrations must never call app actions or Eloquent models, because a later refactor would break upgrades of self-hosted installs.

## SocialAccount global order scope must stay aggregate-safe
`SocialAccountOrderScope` (#[ScopedBy]) orders every channel list by position, created_at, id — the order users set in the sidebar/settings. Do not re-add `orderBy('platform'|'created_at'|'id')` to channel lists: an explicit order disables the scope. The scope skips queries that already order/group/DISTINCT/union or select only raw expressions (has-count sub-queries), and a beforeQuery callback drops the ORDER BY once `aggregate` is set (count/max/sum/paginate totals are applied at toBase(), before the aggregate exists) — PostgreSQL rejects `select count(*) ... order by position` (SQLSTATE 42803), MySQL does not, so a regression only shows on PG. Use `withoutGlobalScopes()` in position maintenance (max(position), reorder writes). Covered by tests/Feature/Channels/ReorderChannelsTest.php.
