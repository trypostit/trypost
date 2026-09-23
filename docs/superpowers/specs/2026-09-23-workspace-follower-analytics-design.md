# Workspace follower analytics — design

**Status:** written design awaiting approval. Nothing implemented.

## Objective

Replace the request-time, per-social-account analytics experience with the
first workspace-level historical metric: follower count.

After the daily collection pipeline begins producing local snapshots, the page
must answer three questions without querying social APIs at request time:

1. How many followers did this workspace have at the end of the selected
   period?
2. How did each connected social account's follower count change over that
   period?
3. Which accounts gained or lost followers?

Success means `/analytics` renders without making social API calls, daily data
collection is resilient to transient failures and rate limits, and one broken
platform cannot block another account's data.

## Scope boundary

Analytics are always scoped to the **current workspace**. There is no
cross-workspace or user-global total.

The workspace is the tenancy and aggregation boundary. A social account is a
dimension inside that workspace so two accounts on the same network remain
separate chart series.

Every query and collection write must validate the relationship between the
workspace and social account. The design must continue to support multiple
accounts of the same network.

## First-version scope

### Included platforms

| Platform | Value represented | Precision / caveat |
| --- | --- | --- |
| TikTok | Profile followers | Exact value exposed by the user stats API |
| Instagram | Professional-account followers | Includes direct Instagram login and Instagram through Facebook |
| Facebook | Page followers | Page follower total, not daily follows gained |
| Threads | Profile followers | Account insight |
| X | Profile followers | Public user metric; subject to the application's X access and billing limits |
| Pinterest | Account followers | Account `follower_count` |
| YouTube | Channel subscribers | YouTube may round the public subscriber count for larger channels |
| Bluesky | Profile followers | Profile `followersCount` |
| Mastodon | Account followers | Account `followers_count` from the connected instance |
| Telegram | Chat/channel members | Member count, treated as the Telegram follower equivalent |
| Discord | Server members | Approximate member count, treated as the Discord follower equivalent |
| Google Business Profile | Location followers | Total follower count for the connected location |

The value is labelled using the platform's native meaning where needed in
tooltips, but all values participate in the workspace's top-level follower
total.

### Explicit exclusions

Both LinkedIn identity types are excluded from follower analytics v1:

- LinkedIn personal profile
- LinkedIn Page

Neither receives follower collection jobs, appears in the follower charts, nor
contributes to the workspace total. Existing LinkedIn publishing and existing
post analytics remain untouched.

LinkedIn personal follower analytics requires `r_member_profileAnalytics`,
which is provisioned through the vetted Community Management API product. That
product must initially be the only product on a separate LinkedIn developer
application. It is not part of this delivery.

### Planned v2: LinkedIn

Follower analytics for both LinkedIn identity types are planned for v2:

- LinkedIn personal profile follower count;
- LinkedIn Page follower count.

The v2 keeps the network consistent by introducing both identity types
together. LinkedIn Page data is already technically accessible through the
current application scopes, but personal-profile data remains gated by
Community Management API approval and `r_member_profileAnalytics`.

Before v2 implementation, TryPost must:

1. create a separate LinkedIn developer application with no other provisioned
   products;
2. request and receive Community Management API access;
3. confirm the production credential arrangement with LinkedIn after approval;
4. add the newly provisioned analytics scope to the appropriate OAuth flow;
5. require affected LinkedIn accounts to reconnect so their tokens contain the
   approved scope;
6. verify the current LinkedIn API version and data-retention requirements.

If Community Management API access is not approved, LinkedIn personal cannot
enter v2. Shipping LinkedIn Page alone would then require a new explicit
product decision rather than happening implicitly.

## User experience

### Workspace total

The page displays a follower-total summary above the chart. It sums one daily
follower value per included social account for the selected range's end date.

- An account contributes at most once.
- Two accounts on the same network both contribute.
- An account with no value for the end date does not silently contribute an
  older, unclassified value.
- A carried-forward value created by the daily fallback does contribute.
- An account that was disconnected or deactivated before that date does not
  receive a snapshot for the date and therefore does not contribute.

### Follower chart

One follower widget presents the same workspace dataset in three modes:

- **Line:** daily follower count per social account across the selected range.
- **Bar:** follower count per social account on the selected end date.
- **Growth:** net change per social account between its first and last available
  values inside the selected range. Positive and negative changes share a zero
  axis.

Series and rows use the social account's platform icon, display name or
username, and stable social-account identity. They are not collapsed by
network.

The initial display mode is Line. Changing modes is client-side because all
three views derive from the same response dataset.

### Date range

The existing analytics range date picker remains the page filter.

- `minDate` is the earliest follower snapshot available in the workspace.
- `maxDate` is the latest follower snapshot available in the workspace.
- The picker cannot select a range wholly outside those bounds.
- All chart modes and the total use the same selected range.
- A social account connected after the selected start date begins when its own
  data begins; no pre-connection values are invented.
- With no follower snapshots, the picker is disabled and the page shows a
  collection-pending empty state.

Historical data for a disconnected or deactivated account is retained. Its
line ends on the last day for which it was eligible; it remains visible when
the selected range overlaps that history.

## Collection architecture

```text
Laravel scheduler (daily, UTC)
    -> dispatch-only collection command
        -> one queued job per eligible social account
            -> platform follower collector
                -> normalized follower observation
                    -> persistence boundary

End-of-day finalizer
    -> identifies eligible accounts without a successful observation
        -> carries forward the most recent known value when one exists
```

### Scheduler and dispatcher

The scheduled command starts at `02:00 UTC`, runs with
`withoutOverlapping()` and `onOneServer()`, reads eligible accounts in bounded
chunks, and only dispatches jobs. It never calls a social API itself.

An account is eligible when it:

- belongs to a workspace;
- uses an included platform;
- is active;
- is connected and has the platform metadata required by its collector.

Each account gets an independent job on a dedicated analytics queue. The job's
logical uniqueness key is follower metric + social account + UTC observation
date. Re-dispatching the same logical job is safe and cannot create a second
daily value.

Connecting a supported account dispatches an immediate first collection so the
workspace does not wait for the next daily sweep. This initial job follows the
same idempotency and retry policy as the scheduled job.

### Collector contract

Each platform-specific collector has one responsibility: fetch the current
follower-equivalent value for one social account and return a normalized
observation. A collector does not authorize workspace access, aggregate totals,
or know the database schema.

The normalized result contains, at minimum:

- metric identity (`followers` at this stage);
- integer value;
- observation date in UTC;
- actual versus carried-forward provenance;
- exact versus approximate precision;
- platform response timestamp when the API provides one.

This contract is intentionally independent of the physical persistence model
so future metrics can reuse the collection pipeline after their data shapes are
known.

### Retry policy

Transient HTTP failures, connection failures, server errors, and rate limits
must retry far apart within the same UTC day. The target attempt windows are:

- 02:00
- 06:00
- 10:00
- 14:00
- 18:00
- 22:00

The actual delayed execution may occur later under queue load. When a platform
returns a longer valid retry time, the job respects that time instead of the
four-hour default, provided the attempt still belongs to the observation day.

Permanent authentication or permission rejection is not retried six times as
a transient error. It goes through the existing account-health handling and
does not write zero as a follower value.

An attempt exits without writing when another attempt has already persisted an
actual observation for the account and date.

### End-of-day fallback

After the final attempt window, a finalizer covers eligible accounts that had
no successful API observation that day:

- If an earlier valid follower value exists, copy it into the current date and
  mark it as carried forward / estimated.
- If the account has never produced a valid follower value, no value can be
  invented; it remains unavailable until a collection succeeds.
- A disconnected or deactivated account is not eligible for carry-forward.
- A carried-forward value may itself be carried into a later unavailable day,
  while retaining provenance that the newest value is not a fresh API
  observation.

This keeps charts and workspace totals continuous during a platform outage
without misclassifying a repeated value as a successful API fetch.

## Persistence decision gate

This specification deliberately defines the **logical data requirements** but
does not choose a physical table design.

The user intends to add many account-, post-, and workspace-level analytics
metrics. Choosing a generic metrics table, metric-specific tables, JSON
snapshots, or a hybrid before that catalog exists would prematurely constrain
dimensions, indexes, retention, and aggregation.

Before any analytics migration or model is implemented, a follow-up design
must inventory each planned metric with:

- entity level: workspace, social account, or post;
- value type and unit;
- snapshot, interval, delta, or lifetime semantics;
- supported dimensions;
- collection frequency and retention;
- exact, approximate, or estimated provenance;
- availability and historical limits per platform.

That follow-up design selects the physical schema and proves it on both
PostgreSQL and MySQL. The implementation plan for this feature must not include
a persistence migration until that decision is approved.

Regardless of the final schema, persistence must support:

- workspace-scoped queries;
- social-account breakdown;
- one effective follower value per account and UTC date;
- idempotent writes;
- actual versus carried-forward provenance;
- exact versus approximate precision;
- earliest/latest available workspace dates;
- retaining history after an account is disconnected;
- efficient aggregation at a selected end date.

Workspace totals are derived from account observations and are not stored as a
second source of truth.

## Read path

`/analytics` reads only local persisted data. It performs no request-time
social API calls for the follower widget.

The server response supplies:

- the workspace's available date bounds;
- the effective selected range after validation;
- the follower total at the range end;
- one daily series per social account;
- account identity and platform presentation metadata;
- actual/carried-forward and exact/approximate provenance required for
  truthful tooltips.

The frontend derives Bar and Growth from this normalized response instead of
requesting separate endpoints. Large date ranges may later be downsampled, but
daily resolution is the source resolution and is sufficient for this first
version.

## Failure handling and observability

Failures are isolated per social account. One platform outage cannot prevent
other account jobs from succeeding.

Operational visibility must distinguish:

- successful actual observation;
- transient failure awaiting retry;
- rate-limited attempt and next eligible attempt time;
- permanent authentication/permission rejection;
- successful carried-forward fallback;
- unavailable account with no historical value;
- finalizer failure.

Logs include workspace, social account, platform, observation date, attempt,
and error category, but never access tokens or raw sensitive responses.

The system must make it possible to alert on workspaces that repeatedly rely on
carried-forward values, even though defining an alerting product is outside
this first delivery.

## Account lifecycle

- **Connected:** dispatch an immediate first collection.
- **Active and connected:** participate in the daily sweep.
- **Deactivated:** stop new collection and fallback; preserve history; exclude
  from totals after its last eligible date.
- **Disconnected/deleted:** stop collection and fallback; preserve historical
  observations even if the account row is later removed. The physical schema
  design must decide how to retain enough immutable identity for this.
- **Reconnected as the same persisted identity:** resume collection without
  rewriting earlier observations.
- **New identity:** begins a new series even when its username matches an older
  disconnected account.

## Security and privacy

- Analytics authorization follows the current workspace membership and policy
  model.
- A social account id from another workspace must never affect collection or
  read results.
- API tokens remain on `social_accounts` and are never copied into analytics
  storage or job logs.
- Platform data-retention terms must be checked as each collector is
  implemented; the physical schema review must record any network-specific
  retention constraint.

## Testing strategy

### Collector contract tests

Each included platform needs tests for:

- successful exact or approximate follower parsing;
- missing/null metric handling;
- malformed response handling;
- rate-limit classification;
- transient server/connection classification;
- permanent authentication/permission classification;
- no accidental conversion of failure or null to zero.

HTTP calls are faked. Tests must not call live social APIs.

### Queue and scheduling tests

- The daily command dispatches one job per eligible account and none for
  LinkedIn, inactive, disconnected, or unsupported accounts.
- Jobs are isolated and idempotent by account, metric, and date.
- Retry delays cover the same UTC day and stop after a successful observation.
- Platform-provided retry timing is respected.
- A permanent authentication failure does not follow the transient retry loop.
- Immediate collection is dispatched after a supported account is connected.
- `withoutOverlapping()` and `onOneServer()` remain present on the schedule.

### Fallback tests

- The finalizer carries forward the last known value after all daily attempts
  fail.
- Carried-forward provenance is preserved.
- No historical value means no fabricated snapshot.
- Deactivated and disconnected accounts are not carried forward.
- A successful observation is never overwritten by the finalizer.

### Read and UI tests

- Every response is workspace-scoped.
- The total sums each eligible account once on the selected end date.
- Multiple accounts on one network remain separate.
- Date bounds reflect the workspace's actual stored history.
- Line, Bar, and Growth derive the expected values from the same dataset.
- Growth handles negative values and a zero baseline.
- A later-connected account does not receive invented earlier points.
- Historical series remain available after disconnect/deactivation.
- No-data workspaces receive the collection-pending state.
- LinkedIn personal and LinkedIn Page never appear or contribute.

Database-dependent tests run on PostgreSQL and MySQL after the persistence
design is approved and implemented.

## Considered and rejected

- **Calling social APIs from `/analytics`.** Slow, rate-limit prone, impossible
  to trend reliably, and couples page availability to every provider.
- **One queued job per workspace.** A single slow or broken account delays the
  whole workspace and makes retries unnecessarily broad.
- **Fast retry loops.** Follower totals tolerate delay; four-hour spacing gives
  providers time to recover and protects API quotas.
- **Writing zero on failure.** Produces false losses and corrupts totals.
- **Silently using an old observation without provenance.** Keeps the UI full
  but makes stale data indistinguishable from measured data.
- **Deleting history when an account disconnects.** Removes valid workspace
  history and breaks historical comparisons.
- **Persisting workspace totals.** Duplicates account facts and risks drift.
- **Choosing the final table structure now.** The wider metric catalog is not
  yet known, so the choice would be speculative.
- **Including either LinkedIn identity in v1.** Personal analytics require a
  separately vetted product, and the product decision for this release is to
  defer the whole network to the planned v2 rather than ship partial LinkedIn
  support.

## Delivery gates

1. This written design must be reviewed and approved.
2. The broader metric catalog must be supplied and its persistence design
   approved.
3. Only then can the Superpowers implementation-plan stage define migrations,
   concrete classes, and ordered implementation tasks.
4. Implementation begins only after that written plan is reviewed and its
   execution method is selected.
5. LinkedIn follower analytics receives a separate v2 implementation plan
   after the external Community Management API dependency is resolved.
