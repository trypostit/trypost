# Workspace Analytics and Native Backfill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace request-time social analytics with workspace-scoped, database-backed follower history, reconciled TryPost/external publication history, persisted post metrics, and the Summary, Followers, Posts, Top 5 Posts, Performance, and individual-publication views.

**Architecture:** Four tables separate daily account facts, publication identity, daily cumulative publication metrics, and a deliberately small operational checkpoint used only by publication backfill/discovery. Every provider call runs in an isolated queued job; page, API, and MCP reads use local query services only. Provider adapters normalize platform responses into stable DTOs, while a hybrid scalar-plus-JSON snapshot keeps cross-network queries portable across PostgreSQL and MySQL and preserves content-specific metrics.

**Tech Stack:** PHP 8.5, Laravel 13.24, Horizon 5.47, PostgreSQL and MySQL, Inertia Vue 3.6, Vue 3.5, Tailwind CSS 4, Pest 5, Pest Browser 5.

**Spec:** `docs/superpowers/specs/2026-09-23-workspace-follower-analytics-design.md`

## Global Constraints

- Execute all tasks on one branch named `feat/workspace-analytics-backfill`.
- Analytics tenancy and aggregation are always scoped to the current workspace; never trust a request-provided social-account id as tenancy proof.
- Multiple accounts on the same network remain separate series and Performance rows through `social_account_id` while connected and `social_account_key` historically; reconnecting the same workspace + network + provider user id reuses its historical key.
- V1 includes TikTok, Instagram, Instagram through Facebook, Facebook Pages, Threads, X, Pinterest, YouTube, Bluesky, and Mastodon.
- V1 excludes LinkedIn profile, LinkedIn Page, Telegram, Discord, and Google Business Profile from every analytics read, collection job, summary, chart, and post-detail block.
- LinkedIn profile and Page analytics remain a separate V2 after Community Management API approval.
- `origin=trypost` means a matching TryPost destination proves ownership; every other discovered publication is `origin=external` and renders `Published on <Platform>`.
- Target 365 days of owned-publication history, but persist and expose actual coverage when a provider is shallower, partial, or permission-limited.
- Never fabricate follower history, historical post-metric snapshots, unsupported metrics, zero values after provider failure, or a native/manual origin the provider cannot prove.
- Followers retry at widely spaced same-day windows and carry the last value forward only after the day is exhausted; provider `Retry-After` wins when valid.
- `/analytics`, post detail, REST, and MCP make no social-provider calls and never use Redis as the analytics source of truth.
- Common aggregate metrics are nullable scalar columns; content-specific metrics use stable enum-backed JSON keys with value, unit, time basis, precision, availability, and provider identity.
- `analytics_sync_states` is not a job ledger: only publication backfill/discovery use it. Queue/Horizon owns attempts and delays; follower and publication snapshots prove successful collection.
- Use string columns plus PHP backed enums; do not use database-native enum types.
- Every query, migration, unique constraint, and test must work on PostgreSQL and MySQL.
- Do not add a charting dependency; use focused Vue/SVG/CSS components and existing UI primitives.
- Do not alter the unrelated `package-lock.json` change. Preserve the existing
  metric inventory in `ANALYTIC.md`; its only planning change is the note that
  distinguishes current behavior from this V1 design.
- Generate Laravel files with `php artisan make:* --no-interaction`, use Pest TDD, run `vendor/bin/pint --dirty --format agent` after PHP edits, and commit after each task.

## Review Focus

- Two Instagram accounts in one workspace must remain independent in totals, charts, publication counts, and sorting; Task 12 adds a cross-network-duplicate account test.
- Deleting a live social account must null the foreign key without erasing historical identity or presentation; Tasks 1 and 2 test `social_account_key` and snapshot retention.
- Provider null/missing metrics must stay unavailable while a measured numeric zero remains zero; Tasks 3 and 10 add explicit parser and writer tests.
- Concurrent TryPost sync and external discovery of the same provider post id must converge to one publication with `trypost` origin; Task 5 tests both arrival orders.
- A failed paginated backfill must resume from the last committed cursor and disclose partial/provider-limited coverage instead of restarting or claiming 365 days; Task 9 tests checkpoint, retry, and completion conditions.
- A duplicate or stale page job must never move a provider cursor backward; Task 9 uses a captured checkpoint version and row lock around advancement.
- Pre-rollout `post_platforms` whose social account was already deleted cannot be safely assigned by username; Task 9 skips and reports them instead of inventing historical identity.

---

## File Structure

The implementation introduces these focused areas:

- `app/Enums/Analytics/*`: stable persisted states, metric keys, units, and provenance.
- `app/Models/Analytics*`: four persistence boundaries and their relationships.
- `app/Dto/Analytics/*`: provider-independent account, publication, page, and metric results.
- `app/Contracts/Analytics/*`: follower, history, and publication-metric collector contracts.
- `app/Services/Analytics/Collectors/*`: one provider adapter per concern; no authorization or database writes.
- `app/Actions/Analytics/*`: idempotent writers and publication reconciliation.
- `app/Jobs/Analytics/*`: one bounded piece of external or local synchronization per job.
- `app/Console/Commands/Analytics/*`: chunked dispatchers and rollout entry points; commands never call providers.
- `app/Queries/Analytics/*`: workspace-only local read models for dashboard and publication detail.
- `resources/js/components/analytics/workspace/*`: reusable dashboard cards and dependency-free SVG/CSS charts.
- `tests/Feature/Analytics/*`, `tests/Unit/Analytics/*`, and `tests/Browser/WorkspaceAnalyticsTest.php`: provider contracts, persistence, queue behavior, read paths, and UI coverage.

### Task 1: Create the four-table analytics schema and persisted enums

**Files:**
- Create: `database/migrations/2026_09_23_103300_create_analytics_account_daily_snapshots_table.php`
- Create: `database/migrations/2026_09_23_103301_create_analytics_publications_table.php`
- Create: `database/migrations/2026_09_23_103302_create_analytics_publication_daily_snapshots_table.php`
- Create: `database/migrations/2026_09_23_103303_create_analytics_sync_states_table.php`
- Create: `app/Enums/Analytics/ObservationProvenance.php`
- Create: `app/Enums/Analytics/MetricPrecision.php`
- Create: `app/Enums/Analytics/PublicationOrigin.php`
- Create: `app/Enums/Analytics/PublicationAvailability.php`
- Create: `app/Enums/Analytics/PublicationContentType.php`
- Create: `app/Enums/Analytics/ExposureKind.php`
- Create: `app/Enums/Analytics/MetricUnit.php`
- Create: `app/Enums/Analytics/MetricTimeBasis.php`
- Create: `app/Enums/Analytics/MetricAvailability.php`
- Create: `app/Enums/Analytics/MetricKey.php`
- Create: `app/Enums/Analytics/SyncCollector.php`
- Create: `app/Enums/Analytics/SyncStatus.php`
- Test: `tests/Feature/Analytics/AnalyticsSchemaTest.php`

**Interfaces:**
- Consumes: existing UUID workspace, social-account, and post-platform keys; `App\Enums\SocialAccount\Platform`.
- Produces: the four tables and enum values consumed by every later task.

- [ ] **Step 1: Generate the migrations and failing schema test**

Run:

```bash
php artisan make:migration create_analytics_account_daily_snapshots_table --no-interaction
php artisan make:migration create_analytics_publications_table --no-interaction
php artisan make:migration create_analytics_publication_daily_snapshots_table --no-interaction
php artisan make:migration create_analytics_sync_states_table --no-interaction
php artisan make:test --pest Analytics/AnalyticsSchemaTest --no-interaction
```

Add assertions that all four tables exist, that account and publication rows accept two distinct account UUIDs on the same platform, and that deleting `social_accounts` nulls live foreign keys while `social_account_key`, platform, username, and historical facts remain.

```php
expect(Schema::hasColumns('analytics_account_daily_snapshots', [
    'workspace_id', 'social_account_id', 'social_account_key', 'platform',
    'network', 'platform_user_id',
    'snapshot_date', 'followers_count', 'metrics', 'provenance', 'precision',
    'provider_observed_at', 'collected_at',
]))->toBeTrue();
```

- [ ] **Step 2: Run the schema test and verify it fails**

Run: `php artisan test --compact tests/Feature/Analytics/AnalyticsSchemaTest.php`

Expected: FAIL because the migrations and enum classes are empty or incomplete.

- [ ] **Step 3: Define the exact persisted enum vocabulary**

Use backed string enums. The cases and values are:

```php
enum ObservationProvenance: string { case Actual = 'actual'; case CarriedForward = 'carried_forward'; }
enum MetricPrecision: string { case Exact = 'exact'; case Approximate = 'approximate'; case Estimated = 'estimated'; case Experimental = 'experimental'; }
enum PublicationOrigin: string { case TryPost = 'trypost'; case External = 'external'; }
enum PublicationAvailability: string { case Available = 'available'; case Deleted = 'deleted'; case Unavailable = 'unavailable'; }
enum ExposureKind: string { case Reach = 'reach'; case Impressions = 'impressions'; case Views = 'views'; }
enum MetricUnit: string { case Count = 'count'; case Milliseconds = 'milliseconds'; case Percent = 'percent'; }
enum MetricTimeBasis: string { case Lifetime = 'lifetime'; case Range = 'range'; case Rolling90Days = 'rolling_90_days'; case Snapshot = 'snapshot'; }
enum MetricAvailability: string { case Available = 'available'; case Unsupported = 'unsupported'; case Unavailable = 'unavailable'; case Delayed = 'delayed'; case PrivacyLimited = 'privacy_limited'; }
enum SyncCollector: string { case PublicationBackfill = 'publication_backfill'; case PublicationDiscovery = 'publication_discovery'; }
enum SyncStatus: string { case Pending = 'pending'; case Running = 'running'; case Complete = 'complete'; case Partial = 'partial'; case ProviderLimited = 'provider_limited'; case Failed = 'failed'; }
```

`PublicationContentType` must contain `Text`, `Image`, `Carousel`, `Video`, `Reel`, `Story`, `Short`, `Link`, `Poll`, and `Unknown`. `MetricKey` must contain every metric in the spec catalog, including normalized reactions/comments/shares/saves/views/impressions/reach, watch-time metrics, clicks, video quartiles, follows, profile activity, Story navigation, Pinterest audience metrics, and YouTube subscriber gains/losses.

- [ ] **Step 4: Implement portable migrations and indexes**

Use UUID primary keys, string-backed enum columns, and explicit foreign keys.

`analytics_account_daily_snapshots` has `workspace_id` with cascade delete;
nullable `social_account_id` with null-on-delete; non-null
`social_account_key`, `network`, `platform_user_id`, and `platform`; account
name/username/avatar snapshots; `snapshot_date`; nullable
`followers_count`; nullable future account `metrics` JSON; `provenance`,
`precision`, nullable `provider_observed_at`, `collected_at`, and timestamps. Its
unique key is `workspace_id, social_account_key, snapshot_date`.

`analytics_publications` has `workspace_id` with cascade delete; nullable live
`social_account_id` and unique nullable `post_platform_id`, both null-on-delete;
non-null `social_account_key`, `network`, `platform_user_id`, `platform`,
`provider_post_id`, `provider_published_at`, `origin`, `content_type`, and
`availability`; nullable provider content type, permalink, excerpt, preview
metadata, account presentation snapshots, first/last seen times,
provider-synced time, and provider metadata JSON. Its provider identity unique
key is `workspace_id, social_account_key, network, provider_post_id`.

`analytics_publication_daily_snapshots` has only its UUID, non-null parent
`analytics_publication_id` with cascade delete, `snapshot_date`, `collected_at`,
nullable `provider_observed_at`, nullable metric-catalog JSON, and nullable
portable projections: reactions, comments, shares, saves, views, impressions,
reach, engagement, exposure, exposure kind, total watch milliseconds, and
average watch milliseconds. It deliberately has no duplicate `workspace_id`.
Its unique key is `analytics_publication_id, snapshot_date`.

`analytics_sync_states` has a non-null `social_account_id` with cascade delete,
collector, status, nullable provider-specific `checkpoint` JSON,
`target_since`, `oldest_reached_at`, `high_watermark_at`, `last_success_at`,
sanitized `last_error_category`, and timestamps. It deliberately has no
workspace/account-history copies, attempt count, retry timestamp, or raw error
message. Its unique key is `social_account_id, collector`.

Only snapshots and publications store `network` plus `platform_user_id`, because
they are historical identity. Operational sync state is tied to the live row and
is recreated on reconnect.

Add these query indexes:

```php
$table->index(['workspace_id', 'snapshot_date']);
$table->index(['workspace_id', 'social_account_key', 'snapshot_date']);
$table->index(['workspace_id', 'provider_published_at']);
$table->index(['workspace_id', 'social_account_key', 'provider_published_at']);
$table->index(['analytics_publication_id', 'collected_at']);
$table->index(['collector', 'status']);
```

Historical `social_account_id` and `post_platform_id` use `nullOnDelete()`;
sync-state `social_account_id` and every `workspace_id` use
`cascadeOnDelete()`. Keep provider ids as bounded strings, metric counters and
canonical durations as nullable big integers, precise rates as nullable
decimals, timestamps below the MySQL 2038 ceiling, and JSON object assertions
order-independent. Test that a publication snapshot cannot carry a tenant id
different from its parent because no such child column exists.

- [ ] **Step 5: Run schema tests on the configured database**

Run: `php artisan test --compact tests/Feature/Analytics/AnalyticsSchemaTest.php`

Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/Analytics database/migrations tests/Feature/Analytics/AnalyticsSchemaTest.php
git commit -m "feat: add workspace analytics schema"
```

### Task 2: Add analytics models, factories, DTOs, and idempotent writers

**Files:**
- Create: `app/Models/AnalyticsAccountDailySnapshot.php`
- Create: `app/Models/AnalyticsPublication.php`
- Create: `app/Models/AnalyticsPublicationDailySnapshot.php`
- Create: `app/Models/AnalyticsSyncState.php`
- Create: `database/factories/AnalyticsAccountDailySnapshotFactory.php`
- Create: `database/factories/AnalyticsPublicationFactory.php`
- Create: `database/factories/AnalyticsPublicationDailySnapshotFactory.php`
- Create: `database/factories/AnalyticsSyncStateFactory.php`
- Create: `app/Dto/Analytics/AccountDailyObservation.php`
- Create: `app/Dto/Analytics/MetricValue.php`
- Create: `app/Dto/Analytics/PublicationMetricObservation.php`
- Create: `app/Actions/Analytics/WriteAccountDailySnapshot.php`
- Create: `app/Actions/Analytics/WritePublicationDailySnapshot.php`
- Create: `app/Actions/Analytics/ResolveAnalyticsAccountKey.php`
- Test: `tests/Feature/Analytics/AnalyticsObservationWriterTest.php`

**Interfaces:**
- Consumes: Task 1 tables and enums.
- Produces: `ResolveAnalyticsAccountKey::for(SocialAccount $account): string`, `WriteAccountDailySnapshot::handle(SocialAccount $account, AccountDailyObservation $observation): AnalyticsAccountDailySnapshot`, and `WritePublicationDailySnapshot::handle(AnalyticsPublication $publication, PublicationMetricObservation $observation): AnalyticsPublicationDailySnapshot`.

- [ ] **Step 1: Generate models, factories, actions, and the failing writer test**

Run:

```bash
php artisan make:model AnalyticsAccountDailySnapshot --factory --no-interaction
php artisan make:model AnalyticsPublication --factory --no-interaction
php artisan make:model AnalyticsPublicationDailySnapshot --factory --no-interaction
php artisan make:model AnalyticsSyncState --factory --no-interaction
php artisan make:class Dto/Analytics/AccountDailyObservation --no-interaction
php artisan make:class Dto/Analytics/MetricValue --no-interaction
php artisan make:class Dto/Analytics/PublicationMetricObservation --no-interaction
php artisan make:class Actions/Analytics/ResolveAnalyticsAccountKey --no-interaction
php artisan make:class Actions/Analytics/WriteAccountDailySnapshot --no-interaction
php artisan make:class Actions/Analytics/WritePublicationDailySnapshot --no-interaction
php artisan make:test --pest Analytics/AnalyticsObservationWriterTest --no-interaction
```

The test must prove same-day writes update one row, next-day writes create history, null stays null, numeric zero stays zero, deleting the social account preserves snapshots through the immutable key, reconnecting the same workspace + network + `platform_user_id` reuses that key, and a different provider identity receives a new key even if the username is identical.

```php
$writer->handle($account, new AccountDailyObservation(
    date: CarbonImmutable::parse('2026-09-23', 'UTC'),
    followers: 0,
    provenance: ObservationProvenance::Actual,
    precision: MetricPrecision::Exact,
    providerObservedAt: null,
));

expect(AnalyticsAccountDailySnapshot::count())->toBe(1)
    ->and(AnalyticsAccountDailySnapshot::first()->followers_count)->toBe(0);
```

- [ ] **Step 2: Run the writer test and verify it fails**

Run: `php artisan test --compact tests/Feature/Analytics/AnalyticsObservationWriterTest.php`

Expected: FAIL because the models and writers do not exist.

- [ ] **Step 3: Implement typed DTOs and model relationships**

`MetricValue` is the JSON boundary:

```php
final readonly class MetricValue
{
    public function __construct(
        public MetricKey $key,
        public int|float|null $value,
        public MetricUnit $unit,
        public MetricTimeBasis $timeBasis,
        public MetricPrecision $precision,
        public MetricAvailability $availability,
        public ?string $providerMetric = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
    ) {}
}
```

Models use `HasUuids`, `HasFactory`, explicit `$fillable`, enum/date/array casts, and typed `belongsTo`/`hasMany` relationships. Add relationships from `Workspace`, `SocialAccount`, and `PostPlatform` only when a later query uses them.

- [ ] **Step 4: Implement transactional upsert writers**

Use the unique business keys rather than process-local locks.
`ResolveAnalyticsAccountKey` searches historical account snapshots and
publications by workspace + `Platform::network()` + `platform_user_id`, and
otherwise returns the current social-account UUID. Sync state is operational
and is never an identity source. Snapshot presentation fields come from the
account at write time.

The publication writer locks the same-day row and atomically merges the metric
catalog and scalar projections. A collector response may update the metrics it
actually observed, but a missing/unsupported/delayed value never blanks a prior
successful same-day value and never becomes zero. The test compares every
scalar projection against its canonical JSON entry so the two representations
cannot drift. A carried-forward follower snapshot retains the original
`provider_observed_at` while recording its new `collected_at`, so staleness is
not hidden.

```php
return AnalyticsAccountDailySnapshot::query()->updateOrCreate(
    [
        'workspace_id' => $account->workspace_id,
        'social_account_key' => $this->accountKeys->for($account),
        'snapshot_date' => $observation->date->toDateString(),
    ],
    $this->attributes($account, $observation),
);
```

- [ ] **Step 5: Run writer tests**

Run: `php artisan test --compact tests/Feature/Analytics/AnalyticsObservationWriterTest.php`

Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models app/Dto/Analytics app/Actions/Analytics database/factories tests/Feature/Analytics/AnalyticsObservationWriterTest.php
git commit -m "feat: persist analytics observations"
```

### Task 3: Normalize follower collection for every included platform

**Files:**
- Create: `app/Contracts/Analytics/FollowerCollector.php`
- Create: `app/Exceptions/Analytics/AnalyticsCollectionException.php`
- Create: `app/Services/Analytics/Collectors/Followers/FollowerCollectorFactory.php`
- Create: `app/Services/Analytics/Collectors/Followers/InstagramFollowerCollector.php`
- Create: `app/Services/Analytics/Collectors/Followers/FacebookFollowerCollector.php`
- Create: `app/Services/Analytics/Collectors/Followers/ThreadsFollowerCollector.php`
- Create: `app/Services/Analytics/Collectors/Followers/XFollowerCollector.php`
- Create: `app/Services/Analytics/Collectors/Followers/PinterestFollowerCollector.php`
- Create: `app/Services/Analytics/Collectors/Followers/YouTubeFollowerCollector.php`
- Create: `app/Services/Analytics/Collectors/Followers/TikTokFollowerCollector.php`
- Create: `app/Services/Analytics/Collectors/Followers/BlueskyFollowerCollector.php`
- Create: `app/Services/Analytics/Collectors/Followers/MastodonFollowerCollector.php`
- Test: `tests/Feature/Analytics/Collectors/FollowerCollectorsTest.php`

**Interfaces:**
- Consumes: `SocialAccount` and Task 2 `AccountDailyObservation`.
- Produces: `FollowerCollector::collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation` and `FollowerCollectorFactory::for(Platform $platform): FollowerCollector`.

- [ ] **Step 1: Write the contract and failing provider dataset**

```php
interface FollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation;
}
```

Use one Pest dataset that fakes and verifies these read paths and canonical fields:

| Platform | Read path | Field |
| --- | --- | --- |
| Instagram variants | Graph user/profile or account insights supported by login type | `followers_count` |
| Facebook Page | Graph Page | `followers_count` |
| Threads | user insights | `followers_count` |
| X | `/2/users/{id}?user.fields=public_metrics` | `public_metrics.followers_count` |
| Pinterest | `/v5/user_account` | `follower_count` |
| YouTube | `channels.list(part=statistics)` | `subscriberCount`, approximate; null if hidden |
| TikTok | `/v2/user/info/?fields=follower_count` | `follower_count` |
| Bluesky | `app.bsky.actor.getProfile` | `followersCount` |
| Mastodon | `/api/v1/accounts/{id}` | `followers_count` |

The test must also assert excluded platforms make no request and that missing fields throw an unavailable collection result rather than returning zero.

- [ ] **Step 2: Run the collector test and verify it fails**

Run: `php artisan test --compact tests/Feature/Analytics/Collectors/FollowerCollectorsTest.php`

Expected: FAIL because the collector factory is missing.

- [ ] **Step 3: Implement failure classification and provider collectors**

`AnalyticsCollectionException` carries `transient`, `rate_limited`, `authentication`, `permission`, `unsupported`, or `malformed`, plus nullable provider retry time. Reuse existing token refresh and Graph error classification where available; never log response bodies containing tokens.

The factory has an explicit match for the ten included platform values and throws for LinkedIn, Telegram, Discord, and Google Business. Instagram direct and Facebook-login variants share the collector class but branch on the existing platform value.

- [ ] **Step 4: Run collector tests**

Run: `php artisan test --compact tests/Feature/Analytics/Collectors/FollowerCollectorsTest.php`

Expected: PASS with `Http::assertSent` endpoint and field verification for every platform.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Contracts/Analytics app/Exceptions/Analytics app/Services/Analytics/Collectors/Followers tests/Feature/Analytics/Collectors/FollowerCollectorsTest.php
git commit -m "feat: collect normalized follower snapshots"
```

### Task 4: Queue daily followers, same-day retries, and carry-forward

**Files:**
- Create: `app/Jobs/Analytics/CollectAccountDailySnapshot.php`
- Create: `app/Jobs/Analytics/FinalizeAccountDailySnapshots.php`
- Create: `app/Console/Commands/Analytics/DispatchAccountDailyAnalytics.php`
- Modify: `routes/console.php`
- Modify: `app/Observers/SocialAccountObserver.php`
- Test: `tests/Feature/Analytics/AccountDailyJobsTest.php`
- Test: `tests/Feature/Analytics/AnalyticsScheduleTest.php`
- Modify: `tests/Feature/Observers/SocialAccountObserverTest.php`

**Interfaces:**
- Consumes: Task 3 collectors and Task 2 account writer.
- Produces: one actual or carried-forward row per eligible account/day and immediate collection after connection.

- [ ] **Step 1: Generate jobs/command and write failing dispatch tests**

Test included/excluded platforms, inactive/disconnected accounts, duplicate-network accounts, immediate post-commit dispatch, `analytics` queue selection, and scheduler guards.

```php
Bus::assertDispatched(CollectAccountDailySnapshot::class,
    fn ($job) => $job->socialAccountId === $instagram->id
        && $job->observationDate === '2026-09-23');
Bus::assertNotDispatched(CollectAccountDailySnapshot::class,
    fn ($job) => $job->socialAccountId === $linkedin->id);
```

- [ ] **Step 2: Run queue tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/AccountDailyJobsTest.php tests/Feature/Analytics/AnalyticsScheduleTest.php tests/Feature/Observers/SocialAccountObserverTest.php`

Expected: FAIL because jobs and schedules are absent.

- [ ] **Step 3: Implement bounded dispatch and job isolation**

The command uses `lazyById(200)` and dispatches IDs only. The job re-queries the social account, revalidates active/connected/included status, uses `WithoutOverlapping` keyed by account/date, and exits if an actual row already exists.

On a transient/rate-limit exception, release near the next `06:00`, `10:00`, `14:00`, `18:00`, or `22:00` UTC window, honoring a later provider time inside the same UTC day. Authentication/permission errors use existing account-health handling and do not write a value. Set job `retryUntil()` to the end of its observation day.

Set `tries = 6` for the initial `02:00` attempt plus the five delayed windows.
Because `release()` consumes an attempt, calculate the next window from the
observation date and current attempt rather than using a fast `backoff()` array.
If `Retry-After` points beyond the UTC day, stop retrying and let the finalizer
decide whether a historical value exists.

- [ ] **Step 4: Implement end-of-day fallback**

`FinalizeAccountDailySnapshots` iterates eligible accounts without an actual
row. It copies the last non-null follower count into the current date with
`CarriedForward`, preserves the source row's original `provider_observed_at`,
and records a new `collected_at`. It writes nothing when history is absent and
never overwrites an actual row, including when a late successful job races the
finalizer.

- [ ] **Step 5: Schedule and observer integration**

Schedule the dispatch command at `02:00` UTC and finalizer at `23:30` UTC,
after the last retry window, both with `withoutOverlapping()` and
`onOneServer()`. Dispatch initial collection `afterCommit()` when an included
account becomes connected; observers must never throw during delete/reconnect.

- [ ] **Step 6: Run queue/schedule tests**

Run: `php artisan test --compact tests/Feature/Analytics/AccountDailyJobsTest.php tests/Feature/Analytics/AnalyticsScheduleTest.php tests/Feature/Observers/SocialAccountObserverTest.php`

Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/Analytics app/Console/Commands/Analytics routes/console.php app/Observers/SocialAccountObserver.php tests/Feature/Analytics tests/Feature/Observers/SocialAccountObserverTest.php
git commit -m "feat: schedule resilient follower analytics"
```

### Task 5: Reconcile TryPost and external publications into one catalog

**Files:**
- Create: `app/Dto/Analytics/DiscoveredPublication.php`
- Create: `app/Dto/Analytics/TryPostPublicationIdentity.php`
- Create: `app/Actions/Analytics/UpsertAnalyticsPublication.php`
- Create: `app/Actions/Analytics/SyncTryPostPublication.php`
- Create: `app/Jobs/Analytics/SyncTryPostPublication.php`
- Modify: `app/Observers/PostPlatformObserver.php`
- Create: `tests/Feature/Analytics/PublicationReconciliationTest.php`
- Modify: `tests/Feature/Observers/PostPlatformObserverTest.php`

**Interfaces:**
- Consumes: Task 2 `AnalyticsPublication` and existing published `PostPlatform`.
- Produces: `UpsertAnalyticsPublication::external(SocialAccount $account, DiscoveredPublication $publication): AnalyticsPublication` and `SyncTryPostPublication::handle(PostPlatform $postPlatform): AnalyticsPublication`.

- [ ] **Step 1: Write failing reconciliation tests for both arrival orders**

Test external-first/TryPost-second, TryPost-first/external-second, duplicate
provider pages, same provider id on two social accounts, workspace isolation,
and deletion of the social account after the job is dispatched but before it
runs.

```php
expect(AnalyticsPublication::query()->where('provider_post_id', 'remote-1')->count())->toBe(1)
    ->and(AnalyticsPublication::first()->origin)->toBe(PublicationOrigin::TryPost)
    ->and(AnalyticsPublication::first()->post_platform_id)->toBe($postPlatform->id);
```

- [ ] **Step 2: Run reconciliation tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/PublicationReconciliationTest.php tests/Feature/Observers/PostPlatformObserverTest.php`

Expected: FAIL because catalog actions/jobs are absent.

- [ ] **Step 3: Implement the discovery DTO and transactional upsert**

`DiscoveredPublication` carries provider id, publication time,
normalized/provider content type, permalink, excerpt, preview metadata, and
provider metadata. Resolve the historical account key first, then lock the
provider identity row using workspace + key + normalized network + provider
post id. `trypost` origin wins; provider publication time never becomes
discovery time; presentation snapshots update only with non-null values.

A lock cannot protect a row that does not exist yet. Treat the database unique
constraint as the final concurrency arbiter: attempt the insert, catch only the
unique-constraint collision, reload the winning row under lock, and merge. Test
that simultaneous discovery and TryPost sync converge without swallowing any
other database error.

`TryPostPublicationIdentity` is a token-free primitive snapshot captured while
the live account still exists: workspace id, social-account id, resolved
historical key, normalized network, provider account id, platform, and account
presentation. The queued local-catalog sync receives this DTO plus the
post-platform id. This closes the race where a user deletes the account after
dispatch but before the job runs; the job must not depend on reloading the live
account to establish historical identity.

- [ ] **Step 4: Dispatch catalog sync after a destination becomes published**

Extend `PostPlatformObserver` independently of the PostHog flag: whenever
status changes to `Published` and `platform_post_id` is present on an included
platform, resolve the identity snapshot and dispatch
`App\Jobs\Analytics\SyncTryPostPublication` with that snapshot and the
post-platform id using `afterCommit()`. The observer remains non-throwing: a
local sync dispatch failure is reported and repaired by the rollout/daily local
reconciliation command.

- [ ] **Step 5: Run reconciliation/observer tests**

Run: `php artisan test --compact tests/Feature/Analytics/PublicationReconciliationTest.php tests/Feature/Observers/PostPlatformObserverTest.php`

Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Dto/Analytics app/Actions/Analytics app/Jobs/Analytics/SyncTryPostPublication.php app/Observers/PostPlatformObserver.php tests/Feature/Analytics/PublicationReconciliationTest.php tests/Feature/Observers/PostPlatformObserverTest.php
git commit -m "feat: reconcile analytics publications"
```

### Task 6: Add owned-publication collector contracts and Meta/Threads adapters

**Files:**
- Create: `app/Contracts/Analytics/PublicationHistoryCollector.php`
- Create: `app/Dto/Analytics/PublicationPage.php`
- Create: `app/Services/Analytics/Collectors/Publications/PublicationHistoryCollectorFactory.php`
- Create: `app/Services/Analytics/Collectors/Publications/InstagramPublicationCollector.php`
- Create: `app/Services/Analytics/Collectors/Publications/FacebookPublicationCollector.php`
- Create: `app/Services/Analytics/Collectors/Publications/ThreadsPublicationCollector.php`
- Test: `tests/Feature/Analytics/Collectors/MetaPublicationCollectorsTest.php`

**Interfaces:**
- Consumes: Task 5 `DiscoveredPublication`.
- Produces: `PublicationHistoryCollector::page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage`.

- [ ] **Step 1: Define the page contract and failing cursor tests**

```php
final readonly class PublicationPage
{
    /** @param list<DiscoveredPublication> $publications */
    public function __construct(
        public array $publications,
        public ?string $nextCursor,
        public bool $providerExhausted,
        public bool $providerLimited = false,
    ) {}
}
```

Tests must prove: Instagram paginates `/media` for feed/carousel/Reels but does not claim expired Stories; Facebook uses Page-owned published posts plus required video/Reel hydration without visitor posts; Threads paginates owned posts; timestamps stop at but do not cross the cutoff; preview failure does not drop the publication.

- [ ] **Step 2: Run Meta collector tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/Collectors/MetaPublicationCollectorsTest.php`

Expected: FAIL because collectors are absent.

- [ ] **Step 3: Implement one-page Meta adapters**

Each call reads exactly one provider page and returns the provider cursor without dispatching or persisting. Share only a low-level Graph client/error parser with Repurpose; do not call `PollRepurposeSource`, create `RepurposeItem`, or download media. Parse Instagram direct and Facebook-login field differences explicitly.

- [ ] **Step 4: Run Meta collector tests**

Run: `php artisan test --compact tests/Feature/Analytics/Collectors/MetaPublicationCollectorsTest.php`

Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Contracts/Analytics app/Dto/Analytics/PublicationPage.php app/Services/Analytics/Collectors/Publications tests/Feature/Analytics/Collectors/MetaPublicationCollectorsTest.php
git commit -m "feat: discover Meta analytics publications"
```

### Task 7: Add X, Pinterest, YouTube, and TikTok publication adapters

**Files:**
- Create: `app/Services/Analytics/Collectors/Publications/XPublicationCollector.php`
- Create: `app/Services/Analytics/Collectors/Publications/PinterestPublicationCollector.php`
- Create: `app/Services/Analytics/Collectors/Publications/YouTubePublicationCollector.php`
- Create: `app/Services/Analytics/Collectors/Publications/TikTokPublicationCollector.php`
- Modify: `app/Services/Analytics/Collectors/Publications/PublicationHistoryCollectorFactory.php`
- Test: `tests/Feature/Analytics/Collectors/MediaPublicationCollectorsTest.php`

**Interfaces:**
- Consumes/produces: Task 6 history contract and page DTO.

- [ ] **Step 1: Write failing endpoint, pagination, and capability tests**

Cover X `users/:id/tweets` next tokens and paid-read fields; Pinterest `/v5/pins` bookmarks; YouTube uploads-playlist page tokens followed by batched `videos.list`; TikTok `video.list` cursor with maximum 20. TikTok without `video.list` must return provider-limited coverage, not fail account connection. YouTube imports all uploads as `Video` unless the provider gives an authoritative type; do not infer Shorts from duration or aspect ratio.

- [ ] **Step 2: Run collector tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/Collectors/MediaPublicationCollectorsTest.php`

Expected: FAIL because collectors are absent.

- [ ] **Step 3: Implement bounded provider pages and ephemeral preview handling**

Never persist TikTok cover URLs as durable truth: store them as provider preview metadata with `expires_at`, and let UI fallback when expired. Pinterest records provider metric time-basis metadata. X requests only fields required by the catalog to control read cost.

- [ ] **Step 4: Run collector tests**

Run: `php artisan test --compact tests/Feature/Analytics/Collectors/MediaPublicationCollectorsTest.php`

Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Analytics/Collectors/Publications tests/Feature/Analytics/Collectors/MediaPublicationCollectorsTest.php
git commit -m "feat: discover media network publications"
```

### Task 8: Add Bluesky and Mastodon publication adapters

**Files:**
- Create: `app/Services/Analytics/Collectors/Publications/BlueskyPublicationCollector.php`
- Create: `app/Services/Analytics/Collectors/Publications/MastodonPublicationCollector.php`
- Modify: `app/Services/Analytics/Collectors/Publications/PublicationHistoryCollectorFactory.php`
- Modify: `app/Http/Controllers/Auth/MastodonController.php`
- Test: `tests/Feature/Analytics/Collectors/OpenPublicationCollectorsTest.php`
- Modify: `tests/Feature/Auth/MastodonOAuthTest.php`

**Interfaces:**
- Consumes/produces: Task 6 history contract and page DTO.

- [ ] **Step 1: Write failing Bluesky repository and Mastodon scope tests**

Bluesky must page `com.atproto.repo.listRecords` for `app.bsky.feed.post` and hydrate batches for public counts instead of trusting `getAuthorFeed` completeness. Mastodon must page `/api/v1/accounts/{id}/statuses` with `max_id`; private/complete history requires `read:statuses`. Existing accounts without that scope remain public-history/partial rather than failing.

- [ ] **Step 2: Run tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/Collectors/OpenPublicationCollectorsTest.php tests/Feature/Auth/MastodonOAuthTest.php`

Expected: FAIL because collectors and the scope change are absent.

- [ ] **Step 3: Implement adapters and request `read:statuses` for new Mastodon connections**

Keep instance URLs account-specific and validate them through the existing connection flow. Mark existing insufficient-scope imports `Partial` with a reconnect hint; never silently claim complete private history.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Analytics/Collectors/OpenPublicationCollectorsTest.php tests/Feature/Auth/MastodonOAuthTest.php`

Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Analytics/Collectors/Publications app/Http/Controllers/Auth/MastodonController.php tests/Feature/Analytics/Collectors/OpenPublicationCollectorsTest.php tests/Feature/Auth/MastodonOAuthTest.php
git commit -m "feat: discover open network publications"
```

### Task 9: Run resumable account backfill and daily publication discovery entirely through jobs

**Files:**
- Create: `app/Jobs/Analytics/BootstrapAccountAnalytics.php`
- Create: `app/Jobs/Analytics/BackfillAccountPublications.php`
- Create: `app/Jobs/Analytics/DiscoverAccountPublications.php`
- Create: `app/Jobs/Analytics/BackfillTryPostPublications.php`
- Create: `app/Console/Commands/Analytics/DispatchPublicationDiscovery.php`
- Create: `app/Console/Commands/Analytics/BackfillExistingAnalytics.php`
- Create: `app/Actions/Analytics/AdvanceAnalyticsSyncState.php`
- Modify: `app/Observers/SocialAccountObserver.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Analytics/PublicationBackfillJobsTest.php`
- Test: `tests/Feature/Analytics/BackfillExistingAnalyticsCommandTest.php`

**Interfaces:**
- Consumes: Tasks 5–8 catalog action, collectors, and sync-state model.
- Produces: resumable 365-day initial import, local TryPost catalog seeding, and overlapping daily discovery.

- [ ] **Step 1: Write failing job-chain and resume tests**

Test one provider page per execution, separate backfill/discovery state rows,
cursor committed only after publication upserts, continuation dispatch after
commit, duplicate job idempotency, stale checkpoint version rejection, account
deletion cascading operational state only, reconnect creating fresh state while
reusing historical publication identity, failure resume, 365-day stop,
exhausted stop, provider-limited stop, overlap window, existing-account rollout
chunking, and per-account isolation. Assert daily discovery is suppressed while
backfill is pending/running and enabled after every terminal backfill state.

```php
Bus::assertDispatched(BackfillAccountPublications::class,
    fn ($job) => $job->socialAccountId === $account->id);
expect($state->fresh()->checkpoint['cursor'])->toBe('provider-next-page')
    ->and($state->fresh()->status)->toBe(SyncStatus::Running);
```

- [ ] **Step 2: Run backfill tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/PublicationBackfillJobsTest.php tests/Feature/Analytics/BackfillExistingAnalyticsCommandTest.php`

Expected: FAIL because jobs and command are absent.

- [ ] **Step 3: Implement sync-state locking and bounded jobs**

Jobs carry account/state ids only and use a provider-specific queue limiter.
Inside a short transaction, lock the sync-state row, capture its checkpoint and
`updated_at` version, and mark it running. Fetch exactly one provider page
outside the transaction. In a second transaction, lock the state again and
upsert the returned publications idempotently. Advance `checkpoint`, coverage,
and high-water fields only if the captured checkpoint/version still matches;
otherwise leave progress untouched. A stale duplicate may safely reconcile
facts but can never move the cursor backward.

The sync table has no retry counters or timestamps: queue attempts, classified
delays, failed-job storage, and Horizon are authoritative. Persist only the
sanitized latest error category needed to explain coverage in the UI; never a
raw provider body.

Set `target_since` to the bootstrap time minus 365 days for initial history.
Daily discovery uses the high-water mark minus a fixed overlap window and the
same provider identity unique key. Persist `oldest_reached_at`,
`last_success_at`, and a truthful final status. A provider listing omission is
not proof of deletion: mark a publication deleted/unavailable only on an
explicit provider response for that publication.

When backfill first becomes terminal, initialize discovery from the newest
provider publication already stored for that account, falling back to the
current time only when the catalog is empty. If a provider invalidates an old
cursor, clear only that cursor and restart from `oldest_reached_at` plus an
overlap window; idempotent publication identity prevents duplicates and the
365-day target remains unchanged.

- [ ] **Step 4: Implement rollout and local TryPost backfill**

`analytics:backfill-existing` first uses `lazyById(100)` to dispatch
`BackfillTryPostPublications` for published included destinations with a live
social account, then dispatches `BootstrapAccountAnalytics` for active included
accounts. The command itself performs no provider calls and accepts an optional
workspace id for controlled rollout.

Pre-rollout published destinations whose `social_account_id` is already null
are counted and logged as `historical_identity_unrecoverable`; they are not
merged by username and no synthetic account key is invented. This limitation
applies only to facts orphaned before the analytics catalog exists. The command
is repeatable, and discovery later reconciles any reachable provider post by
its real account identity.

- [ ] **Step 5: Connect observer and schedule**

On included account creation/reconnection, dispatch `BootstrapAccountAnalytics` after commit. Schedule daily discovery dispatch with `withoutOverlapping()` and `onOneServer()`.

- [ ] **Step 6: Run backfill tests**

Run: `php artisan test --compact tests/Feature/Analytics/PublicationBackfillJobsTest.php tests/Feature/Analytics/BackfillExistingAnalyticsCommandTest.php tests/Feature/Observers/SocialAccountObserverTest.php`

Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/Analytics app/Console/Commands/Analytics app/Actions/Analytics app/Observers/SocialAccountObserver.php routes/console.php tests/Feature/Analytics tests/Feature/Observers/SocialAccountObserverTest.php
git commit -m "feat: backfill native analytics through jobs"
```

### Task 10: Normalize and persist post metrics for all included networks

**Files:**
- Create: `app/Contracts/Analytics/PublicationMetricsCollector.php`
- Create: `app/Services/Analytics/Collectors/Metrics/PublicationMetricsCollectorFactory.php`
- Create: one `*PublicationMetricsCollector.php` in that directory for Instagram, Facebook, Threads, X, Pinterest, YouTube, TikTok, Bluesky, and Mastodon
- Refactor: `app/Services/Social/InstagramAnalytics.php`
- Refactor: `app/Services/Social/FacebookAnalytics.php`
- Refactor: `app/Services/Social/ThreadsAnalytics.php`
- Refactor: `app/Services/Social/XAnalytics.php`
- Refactor: `app/Services/Social/PinterestAnalytics.php`
- Refactor: `app/Services/Social/YouTubeAnalytics.php`
- Refactor: `app/Services/Social/TikTokAnalytics.php`
- Refactor: `app/Services/Social/BlueskyAnalytics.php`
- Refactor: `app/Services/Social/MastodonAnalytics.php`
- Test: `tests/Feature/Analytics/Collectors/PublicationMetricsCollectorsTest.php`

**Interfaces:**
- Consumes: `AnalyticsPublication` and Task 2 metric DTOs.
- Produces: `PublicationMetricsCollector::collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation`.

- [ ] **Step 1: Write failing normalized-metric datasets**

The dataset must pin the exact mapping from the spec:

- Instagram feed/Reel/Story common engagement, reach/views, Reel watch time/average/skip, and Story navigation; parse both `values[].value` and `total_value.value`.
- Facebook Page publication reactions, comments, shares, impressions/reach, and video metrics when supported.
- Threads views, likes, replies, reposts, and quotes.
- X impressions, likes, reposts, replies, quotes, bookmarks, and 30-day-only private/video fields when available.
- Pinterest image/video Pin metrics with lifetime/range/rolling basis retained and batch-ready IDs.
- YouTube views, engaged views, watch time, average duration/percentage, likes, comments, shares, subscriber gains/losses.
- TikTok views, likes, comments, shares and no fabricated retention.
- Bluesky likes, replies, reposts, quotes.
- Mastodon favourites, replies, reblogs.

For every provider, include measured zero, omitted, unsupported, malformed, rate-limited, and stale-value-preservation cases.

- [ ] **Step 2: Run metric collector tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/Collectors/PublicationMetricsCollectorsTest.php`

Expected: FAIL because normalized collectors are absent.

- [ ] **Step 3: Implement stable normalized observations**

```php
interface PublicationMetricsCollector
{
    public function collect(
        AnalyticsPublication $publication,
        CarbonImmutable $date,
    ): PublicationMetricObservation;
}
```

Split Meta metric families that cannot share one request. Store all durations
canonically as integer milliseconds and convert only at presentation time.
Compute normalized engagement numerator from supported interaction components
and preserve `exposure_count` plus `ExposureKind`; do not store a provider
engagement rate as if it were the normalized TryPost rate. The writer updates
the JSON catalog and every corresponding scalar projection in one transaction.

Refactor existing service methods to share low-level authenticated requests/parsers where safe, but do not return translated labels to persistence. Excluded providers remain callable by legacy code until Task 13 removes their analytics read paths, but the new factory never returns them.

- [ ] **Step 4: Run metric collector and existing provider tests**

Run:

```bash
php artisan test --compact tests/Feature/Analytics/Collectors/PublicationMetricsCollectorsTest.php tests/Feature/Services/Social tests/Feature/XAnalyticsTest.php tests/Feature/YouTubeAnalyticsTest.php
```

Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Contracts/Analytics app/Services/Analytics/Collectors/Metrics app/Services/Social tests/Feature/Analytics/Collectors/PublicationMetricsCollectorsTest.php tests/Feature/Services/Social
git commit -m "feat: normalize publication analytics metrics"
```

### Task 11: Queue metric refresh, baseline backfill, and Story lifecycle collection

**Files:**
- Create: `app/Jobs/Analytics/CollectPublicationMetrics.php`
- Create: `app/Console/Commands/Analytics/DispatchPublicationMetrics.php`
- Create: `app/Jobs/Analytics/ScheduleInstagramStoryMetrics.php`
- Modify: `app/Jobs/Analytics/BackfillAccountPublications.php`
- Modify: `app/Jobs/Analytics/DiscoverAccountPublications.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Analytics/PublicationMetricsJobsTest.php`

**Interfaces:**
- Consumes: Task 10 collector factory and Task 2 publication writer.
- Produces: latest persisted metrics with 20-day X, 30-day other-network, one-time old backfill baseline, and sub-day Story collection.

- [ ] **Step 1: Write failing eligibility and retry tests**

Test included/excluded platforms, usable provider ids, active access, X day 20/day 21, other day 30/day 31, final-day collection, old imported baseline exactly once, no perpetual old refresh, same-day idempotency, latest-value preservation on failure, batching eligibility, and Story immediate/pre-expiry runs.

- [ ] **Step 2: Run job tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/PublicationMetricsJobsTest.php`

Expected: FAIL because jobs are absent.

- [ ] **Step 3: Implement metric job and dispatcher**

The job re-queries publication and live account, skips stale/excluded rows,
collects, then writes one daily snapshot. Use the same classified same-day retry
policy as followers. Default to a bounded batch job per account/provider; use a
single publication for endpoints that do not batch and the documented provider
maximum for Pinterest, TikTok, and YouTube. Persist successful items before
retrying only failed items, so one bad id never discards a whole successful
batch.

- [ ] **Step 4: Implement import handoff and Story schedule**

New publications inside the refresh window dispatch normal collection. Older
backfill rows dispatch one baseline job only when that publication has no
snapshot; baseline completion is therefore proved by the fact table, not sync
metadata. Instagram Stories dispatch immediately, at configured within-lifetime
checkpoints, and once shortly before expiry; all writes converge on the daily
writer. A delayed insight must not be mistaken for unsupported, and Story jobs
stop after the provider availability window.

- [ ] **Step 5: Schedule daily metric dispatch and run tests**

Run: `php artisan test --compact tests/Feature/Analytics/PublicationMetricsJobsTest.php tests/Feature/Analytics/AnalyticsScheduleTest.php`

Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/Analytics app/Console/Commands/Analytics routes/console.php tests/Feature/Analytics
git commit -m "feat: schedule persisted publication metrics"
```

### Task 12: Build the portable workspace analytics read model

**Files:**
- Create: `app/Dto/Analytics/DateRange.php`
- Create: `app/Queries/Analytics/WorkspaceAnalyticsQuery.php`
- Create: `app/Queries/Analytics/PublicationAnalyticsQuery.php`
- Create: `app/Support/Analytics/PeriodBuckets.php`
- Test: `tests/Feature/Analytics/WorkspaceAnalyticsQueryTest.php`

**Interfaces:**
- Consumes: all four analytics models.
- Produces: `WorkspaceAnalyticsQuery::for(Workspace $workspace, DateRange $range): array` and `PublicationAnalyticsQuery::latestForPostPlatform(PostPlatform $postPlatform): array`.

- [ ] **Step 1: Write failing query tests covering every dashboard block**

Create two Instagram accounts and one X account in the same workspace plus a foreign-workspace account. Assert:

- min/max date bounds from snapshots or publications;
- range validation and equal-length previous range;
- end-date follower total, per-account Line/Bar/Growth, carry-forward provenance;
- Posts Bar totals and daily/weekly/monthly zero-filled buckets at 14/15/90/91-day boundaries;
- exactly five Summary values;
- pooled engagement `sum(numerator) / sum(denominator)`, excluding only invalid denominators;
- deterministic Top 5 ties by publication time then id;
- Performance rows per social account, including two separate Instagram rows;
- historical rows after live account deletion;
- cumulative metrics for posts selected by publication date use their latest
  successful observation and are never summed across snapshot dates;
- an imported YouTube upload without authoritative Short metadata is presented
  as YouTube Video, not falsely as YouTube Short;
- no excluded platform or foreign-workspace contribution;
- unavailable/null distinct from zero.

- [ ] **Step 2: Run read-model tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/WorkspaceAnalyticsQueryTest.php`

Expected: FAIL because query services are absent.

- [ ] **Step 3: Implement date/bucket value objects and indexed queries**

Avoid JSON predicates for dashboard aggregation. Select latest publication snapshot through portable subqueries keyed by publication id and maximum collected time/date. Use scalar nullable columns and Eloquent/query builder without `ILIKE`, driver-specific date truncation, or database-specific JSON functions. Generate calendar bucket boundaries in PHP and group fetched aggregate rows into those boundaries.

The response shape is stable:

```php
[
    'bounds' => ['min' => '2025-09-23', 'max' => '2026-09-23'],
    'range' => ['start' => '2026-08-25', 'end' => '2026-09-23'],
    'summary' => [...],
    'followers' => ['total' => 15200, 'accounts' => [...], 'series' => [...]],
    'posts' => ['resolution' => 'weekly', 'accounts' => [...], 'buckets' => [...]],
    'top_posts' => ['reactions' => [...], 'comments' => [...]],
    'performance' => [...],
    'coverage' => [...],
];
```

- [ ] **Step 4: Run query tests and inspect query count**

Run: `php artisan test --compact tests/Feature/Analytics/WorkspaceAnalyticsQueryTest.php`

Expected: PASS with a fixed query count that does not grow with account/publication count.

- [ ] **Step 5: Run the same test suite against PostgreSQL and MySQL**

Run the repository's configured PostgreSQL and MySQL CI/database commands. Expected: identical values and ordering on both engines; JSON assertions use recursive equality.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Dto/Analytics/DateRange.php app/Queries/Analytics app/Support/Analytics tests/Feature/Analytics/WorkspaceAnalyticsQueryTest.php
git commit -m "feat: query workspace analytics reports"
```

### Task 13: Replace analytics web, post, REST, and MCP read paths with persisted data

**Files:**
- Modify: `app/Http/Controllers/App/AnalyticsController.php`
- Modify: `routes/app.php`
- Modify: `app/Http/Controllers/App/PostController.php`
- Modify: `app/Services/Post/PostMetricsFetcher.php`
- Modify: `app/Mcp/Tools/Post/GetPostMetricsTool.php`
- Modify: `app/Http/Controllers/Api/PostController.php`
- Modify: `app/Http/Resources/Api/PostMetricsResource.php`
- Test: `tests/Feature/Analytics/AnalyticsControllerTest.php`
- Test: `tests/Feature/Analytics/PersistedPostMetricsReadTest.php`
- Modify: `tests/Feature/AnalyticsResilienceTest.php`

**Interfaces:**
- Consumes: Task 12 queries.
- Produces: one workspace analytics Inertia response and one persisted publication-detail contract shared by web/API/MCP.

- [ ] **Step 1: Write failing no-provider-read tests**

Seed analytics rows, call `/analytics`, post metrics JSON, REST, and MCP, then assert response values and `Http::assertNothingSent()`. Assert an account id from another workspace cannot affect results. Assert LinkedIn, Telegram, Discord, and Google Business expose no V1 block.

- [ ] **Step 2: Run controller/read tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/AnalyticsControllerTest.php tests/Feature/Analytics/PersistedPostMetricsReadTest.php tests/Feature/AnalyticsResilienceTest.php`

Expected: FAIL because current controllers call provider services and Redis-backed `PostMetricsFetcher`.

- [ ] **Step 3: Make `AnalyticsController@index` the only dashboard read endpoint**

Validate `start`/`end` as dates, clamp them to available bounds, authorize the current workspace, and pass the Task 12 report to `Inertia::render('analytics/Index', ...)`. Remove the per-account `show` route and provider dispatch after all frontend callers are removed.

- [ ] **Step 4: Convert `PostMetricsFetcher` into a persisted read facade**

Remove `Cache::remember` and all social-service dependencies. It delegates to `PublicationAnalyticsQuery`, returns canonical metric keys/labels/units/freshness/origin, and preserves its web/API/MCP callers until their response types are updated together.

- [ ] **Step 5: Run all analytics read tests**

Run: `php artisan test --compact tests/Feature/Analytics/AnalyticsControllerTest.php tests/Feature/Analytics/PersistedPostMetricsReadTest.php tests/Feature/AnalyticsResilienceTest.php tests/Feature/Mcp`

Expected: PASS and no provider HTTP requests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers app/Services/Post/PostMetricsFetcher.php app/Mcp routes/app.php tests/Feature/Analytics tests/Feature/AnalyticsResilienceTest.php tests/Feature/Mcp
git commit -m "feat: read analytics exclusively from database"
```

### Task 14: Build the workspace analytics dashboard

**Files:**
- Replace: `resources/js/pages/analytics/Index.vue`
- Create: `resources/js/components/analytics/workspace/types.ts`
- Create: `resources/js/components/analytics/workspace/AnalyticsSection.vue`
- Create: `resources/js/components/analytics/workspace/SummaryCards.vue`
- Create: `resources/js/components/analytics/workspace/FollowersChart.vue`
- Create: `resources/js/components/analytics/workspace/PostsChart.vue`
- Create: `resources/js/components/analytics/workspace/TopPosts.vue`
- Create: `resources/js/components/analytics/workspace/PerformanceTable.vue`
- Create: `resources/js/components/analytics/workspace/ImportCoverage.vue`
- Create: `resources/js/components/analytics/workspace/AccountIdentity.vue`
- Create: `resources/js/components/analytics/workspace/charts/LineChart.vue`
- Create: `resources/js/components/analytics/workspace/charts/HorizontalBarChart.vue`
- Create: `resources/js/components/analytics/workspace/charts/StackedBarChart.vue`
- Modify: `lang/en/analytics.php`
- Modify: every locale counterpart required by localization parity
- Test: `tests/Browser/WorkspaceAnalyticsTest.php`

**Interfaces:**
- Consumes: Task 13 Inertia report shape.
- Produces: responsive workspace dashboard matching the reference behavior without provider calls.

- [ ] **Step 1: Write the failing browser test**

The test seeds two Instagram accounts and one X account, visits `/analytics`, asserts exactly five Summary cards, separate account labels, Line/Bar/Growth switching, Posts Bar/Stacked Bar switching, Top 5 Reactions/Comments switching, Performance rows, range navigation, origin labels, coverage state, empty state, and no JavaScript errors/console logs.

```php
$page = visit(route('app.analytics'));
$page->assertSee('Total Followers')
    ->assertSee('@first · Instagram')
    ->assertSee('@second · Instagram')
    ->click('Growth')
    ->assertSee('-20')
    ->assertNoJavaScriptErrors()
    ->assertNoConsoleLogs();
```

- [ ] **Step 2: Run the browser test and verify it fails**

Run: `php artisan test --compact tests/Browser/WorkspaceAnalyticsTest.php`

Expected: FAIL because the workspace components are absent.

- [ ] **Step 3: Implement typed dashboard composition and date filter**

Use a single root element, existing `DateRangePicker`, and an Inertia GET visit preserving state/scroll. Set picker min/max from report bounds and disable it in the no-data/import-pending state. All chart-mode toggles are client-side because the response contains every required series.

- [ ] **Step 4: Implement dependency-free visualizations**

`LineChart.vue` computes SVG points from daily values and leaves gaps where no observation exists. `HorizontalBarChart.vue` supports positive/negative Growth around a zero axis. `StackedBarChart.vue` renders zero-filled daily/weekly/monthly buckets. Use stable account colors based on account order/id, platform icons, semantic buttons, keyboard focus, tooltips for precision/freshness, and horizontal scrolling on narrow screens.

- [ ] **Step 5: Implement reporting blocks and translations**

Summary contains only Posts, Total Followers, Reactions, Comments, and
Engagement Rate. Top 5 cards show destination origin and only valid actions.
Performance sorting is local over the returned rows. Unsupported renders an em
dash, never `0`; measured zero renders `0`. Summary, Top 5, and Performance
tooltips disclose that reactions/comments are the latest cumulative values for
posts published in the selected period, not events that occurred inside it.

- [ ] **Step 6: Run browser and frontend checks**

```bash
php artisan test --compact tests/Browser/WorkspaceAnalyticsTest.php
npm run lint
npx vue-tsc --noEmit
npm run build
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/analytics resources/js/components/analytics/workspace lang tests/Browser/WorkspaceAnalyticsTest.php
git commit -m "feat: add workspace analytics dashboard"
```

### Task 15: Replace individual-post analytics UI and add external publication detail

**Files:**
- Modify: `resources/js/components/posts/PostPlatformMetrics.vue`
- Modify: `resources/js/pages/posts/Show.vue`
- Create: `resources/js/components/analytics/workspace/PublicationMetrics.vue`
- Create: `resources/js/pages/analytics/Publications/Show.vue`
- Create: `app/Http/Controllers/App/AnalyticsPublicationController.php`
- Modify: `routes/app.php`
- Modify: `lang/en/posts.php`
- Modify: locale counterparts required by parity
- Test: `tests/Feature/Analytics/AnalyticsPublicationControllerTest.php`
- Test: `tests/Browser/PublicationAnalyticsTest.php`

**Interfaces:**
- Consumes: Task 13 persisted detail contract.
- Produces: rich persisted metrics for TryPost destinations and a read-only route for imported external publications.

- [ ] **Step 1: Write failing authorization and browser tests**

Assert workspace ownership, imported rows have no edit/retry/delete action, `Published via TryPost` versus `Published on Instagram`, content-specific metric groups, canonical display units, last-collected/stale/estimated labels, excluded-platform absence, and no provider request.

- [ ] **Step 2: Run tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/AnalyticsPublicationControllerTest.php tests/Browser/PublicationAnalyticsTest.php`

Expected: FAIL because the page and persisted UI contract are absent.

- [ ] **Step 3: Implement controller and shared presentation component**

Authorize through the publication's immutable workspace id. The controller passes publication identity, origin, public URL, preview, account snapshot, and latest metric groups. `PublicationMetrics.vue` renders common engagement, exposure, and video-retention sections and is reused by `PostPlatformMetrics.vue`.

- [ ] **Step 4: Remove request-time fetching from the post component**

Pass persisted metrics as page props or load them from the local-only JSON endpoint. Do not keep `onMounted` provider semantics, Redis loading language, or swallowed provider errors. Excluded destinations do not render the block.

- [ ] **Step 5: Run tests and frontend checks**

```bash
php artisan test --compact tests/Feature/Analytics/AnalyticsPublicationControllerTest.php tests/Browser/PublicationAnalyticsTest.php tests/Browser/PostShowContentTypeTest.php
npm run lint
npx vue-tsc --noEmit
```

Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/App/AnalyticsPublicationController.php routes/app.php resources/js/components/posts resources/js/components/analytics/workspace/PublicationMetrics.vue resources/js/pages/analytics/Publications lang tests/Feature/Analytics tests/Browser
git commit -m "feat: persist individual publication analytics"
```

### Task 16: Verify rollout, observability, portability, and remove obsolete analytics code

**Files:**
- Modify: `config/horizon.php`
- Delete: `resources/js/components/analytics/AnalyticsAccountSelector.vue`
- Delete: `resources/js/components/analytics/FacebookAnalytics.vue`
- Delete: `resources/js/components/analytics/GoogleBusinessAnalytics.vue`
- Delete: `resources/js/components/analytics/InstagramAnalytics.vue`
- Delete: `resources/js/components/analytics/LinkedInPageAnalytics.vue`
- Delete: `resources/js/components/analytics/MetricsGrid.vue`
- Delete: `resources/js/components/analytics/PinterestAnalytics.vue`
- Delete: `resources/js/components/analytics/TelegramAnalytics.vue`
- Delete: `resources/js/components/analytics/ThreadsAnalytics.vue`
- Delete: `resources/js/components/analytics/TikTokAnalytics.vue`
- Delete: `resources/js/components/analytics/XAnalytics.vue`
- Delete: `resources/js/components/analytics/YouTubeAnalytics.vue`
- Delete: `resources/js/components/analytics/types.ts`
- Modify: `docs/superpowers/specs/2026-09-23-workspace-follower-analytics-design.md`
- Test: `tests/Feature/Analytics/AnalyticsObservabilityTest.php`
- Test: `tests/Feature/LocalizationParityTest.php`

**Interfaces:**
- Consumes: the completed feature.
- Produces: deployable queue configuration, truthful operational logs, clean code, and verified cross-engine behavior.

- [ ] **Step 1: Write failing observability and queue configuration tests**

Assert every log context contains workspace id, social-account key, platform, collector, date/cursor, attempt, and sanitized category but excludes access/refresh tokens and raw sensitive responses. Assert analytics jobs use the `analytics` queue and Horizon supervises it.

- [ ] **Step 2: Run observability tests and verify they fail**

Run: `php artisan test --compact tests/Feature/Analytics/AnalyticsObservabilityTest.php`

Expected: FAIL until queue/log configuration is complete.

- [ ] **Step 3: Configure the queue and clean obsolete read paths**

Add the analytics queue to existing Horizon supervisors without changing unrelated queue balancing. Remove old account selector/per-network dashboard components only after `rg` proves no imports. Keep low-level social analytics calls that normalized collectors share; remove translated request-time wrappers only when no publisher, test, API, or MCP path references them.

- [ ] **Step 4: Run targeted and full verification**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Analytics tests/Feature/Services/Social tests/Feature/Observers tests/Feature/Mcp tests/Browser/WorkspaceAnalyticsTest.php tests/Browser/PublicationAnalyticsTest.php tests/Feature/LocalizationParityTest.php
npm run lint
npx vue-tsc --noEmit
npm run build
php artisan test --compact
```

Expected: all pass.

- [ ] **Step 5: Verify PostgreSQL and MySQL**

Run the full database-dependent analytics suite on both supported engines. Confirm migrations roll up/down, all four unique keys enforce the same identities, nullable booleans/JSON are asserted portably, and aggregate ordering is deterministic.

- [ ] **Step 6: Perform controlled capability and rollout checks**

Before dispatching the production rollout:

1. confirm the production TikTok app has `video.list` and `user.info.stats`;
2. connect/test one Instagram-direct and one Instagram-via-Facebook account;
3. verify Facebook Page posts, videos/Reels, and follower fields with the current Page token;
4. confirm Threads follower insights and owned-post pagination with a production-approved token;
5. measure X follower, owned-post, and metric read cost on one bounded 365-day account before widening rollout;
6. verify Pinterest owned-Pin bookmarks, lifetime/range analytics, and the production app's read scopes;
7. verify the YouTube uploads playlist, batched video details, channel statistics, and Analytics API scopes;
8. confirm Bluesky repository pagination plus public count hydration against a large account;
9. reconnect a Mastodon test account with `read:statuses` and confirm private-history behavior on two different instances;
10. verify follower collection once for every included platform and both Instagram login variants;
11. export the selected canary workspace UUID as `ANALYTICS_CANARY_WORKSPACE_ID`, then run `php artisan analytics:backfill-existing --workspace="$ANALYTICS_CANARY_WORKSPACE_ID"`;
12. verify coverage, orphan-skip, rate-limit, and retry states, then run the command without the workspace filter.

- [ ] **Step 7: Update spec status and commit**

Mark implemented gates with the actual provider limitations observed; do not weaken documented coverage silently.

```bash
git add config .env.example app resources/js docs/superpowers/specs tests
git commit -m "chore: finalize workspace analytics rollout"
```

## Self-Review Results

- **Spec coverage:** Every V1 surface, included/excluded platform, follower fallback, native backfill, reconciliation rule, metric catalog, date range, Summary, Top 5, Performance, individual detail, REST/MCP read path, and LinkedIn V2 boundary maps to Tasks 1–16.
- **Schema audit:** The four-table design is retained as the minimum safe split.
  Publication snapshots no longer duplicate tenant ownership, and sync state is
  reduced to two live-account cursor workflows rather than becoming a second
  job/fact ledger.
- **Concurrency audit:** Unique constraints arbitrate missing-row races,
  same-day metric families merge under a row lock, and paginated jobs advance
  only a checkpoint version they actually fetched.
- **Lifecycle audit:** Historical facts survive account/post deletion, sync
  checkpoints do not, reconnects reuse identity through provider ids, and
  pre-rollout orphan destinations are skipped and disclosed rather than guessed.
- **Placeholder scan:** The plan contains no forbidden placeholder markers, no unnamed error handling, and no task that delegates unspecified work. Provider mappings and final manual capability gates are explicit.
- **Type consistency:** The four model names, DTO constructors, collector signatures, origin values, sync states, and query method names are introduced once and reused consistently.
- **Review focus:** Each of the five highest-risk inputs is pinned to an explicit test in Tasks 1/2, 3/10, 5, 9, or 12.
- **Scope decomposition:** Backend persistence, account collection, publication discovery, metric collection, read model, dashboard, and detail UI are independent review gates but remain in one plan and one branch because their contracts form one source-of-truth migration.
