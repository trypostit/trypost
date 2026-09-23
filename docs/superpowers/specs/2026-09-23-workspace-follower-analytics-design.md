# Workspace follower and post analytics — design

**Status:** written design awaiting approval. Nothing implemented.

## Objective

Replace the request-time, per-social-account analytics experience with an
initial workspace-level analytics view covering follower history and posts
successfully published through TryPost.

After the daily collection pipeline begins producing local snapshots, the page
must answer six questions without querying social APIs at request time:

1. How many followers did this workspace have at the end of the selected
   period?
2. How did each connected social account's follower count change over that
   period?
3. Which accounts gained or lost followers?
4. How many posts did each social account successfully publish during the
   selected period, and how was that volume distributed over time?
5. How did publication volume, reactions, comments, and engagement compare
   with the immediately preceding equivalent period?
6. Which destination publications and social accounts performed best?

Success means `/analytics` renders without making social API calls, daily
follower collection is resilient to transient failures and rate limits, one
broken platform cannot block another account's data, and post volume is derived
from the local publication history. Post-performance metrics are also collected
ahead of page requests and retained locally.

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

### Explicit follower exclusions

Both LinkedIn identity types are excluded from follower analytics v1:

- LinkedIn personal profile
- LinkedIn Page

Neither receives follower collection jobs, appears in the follower charts, nor
contributes to the workspace total. Existing LinkedIn publishing and existing
post analytics remain untouched. Successfully published LinkedIn destinations
do appear in the Posts widget because that metric comes from TryPost's local
publication records and requires no LinkedIn analytics permission.

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

The Total Followers card inside Summary sums one daily follower value per
included social account for the selected range's end date.

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

### Posts chart

A second widget shows the number of destinations successfully published through
TryPost during the selected range. It uses two modes:

- **Bar:** horizontal total per social account across the entire selected
  range.
- **Stacked Bar:** publication count over time, with one colored segment per
  social account in each time bucket.

The initial display mode is Stacked Bar. The time bucket is selected
automatically from the inclusive range length:

- up to 14 days: one bucket per day;
- 15 through 90 days: one bucket per week;
- more than 90 days: one bucket per calendar month.

The first and last weekly or monthly buckets may be partial when the selected
range begins or ends inside that period. Empty buckets are returned with zero
values so the time axis remains continuous.

One successful destination counts as one post for that social account. For
example, one TryPost post successfully delivered to Instagram and X contributes
one count to each account. The metric is based on the destination publication
record, not the parent post, so a partially successful multi-network post counts
only its successful destinations.

The Posts widget includes every supported publishing platform, including both
LinkedIn identity types. It includes posts published from any TryPost entry
point, such as the app, API, MCP, or repurpose flows, when they share the normal
publication records. It excludes drafts, scheduled posts that have not yet
published, failed or rejected destinations, and posts created directly on a
social network outside TryPost.

A retry that eventually succeeds counts once because the destination record is
counted once. Historical publications remain facts even if an account is later
deactivated or disconnected. Account snapshot metadata stored with the
destination is used for historical presentation when the live social-account
row is no longer available.

### Summary

The page includes one workspace-level Summary block with exactly five cards:

- **Posts:** successful destination publications whose `published_at` falls
  inside the selected range.
- **Total Followers:** the follower total at the selected range's end date,
  using the same eligibility rules as the follower widget.
- **Reactions:** the sum of the latest stored reactions for successful
  destination publications inside the selected range.
- **Comments:** the sum of the latest stored comments for successful
  destination publications inside the selected range.
- **Engagement Rate:** pooled engagement divided by pooled exposure for the
  eligible destination publications inside the selected range.

Cross-network labels are normalized for comparison. Reactions include native
likes, favorites, and reactions. Comments include native comments and replies
when the platform exposes replies as its comment-equivalent metric. The
underlying native name remains available in the post detail and tooltip.

Engagement follows the Buffer-style model approved for this design. Each
platform collector normalizes the interactions that its API treats as
engagement, such as reactions, comments, reposts/shares, saves, and clicks when
available. Exposure uses the platform-appropriate impressions, reach, or views
denominator. The workspace rate is calculated from the pooled numerator and
pooled denominator, rather than averaging post percentages, so a low-exposure
post does not weigh the same as a high-exposure post.

A destination without a supported or valid exposure denominator is excluded
from Engagement Rate only. Its supported reactions and comments still
contribute to those cards. Unsupported metrics render as unavailable and are
never converted to zero.

### Period comparison

Summary and Performance compare the selected inclusive range with the
immediately preceding range of equal length. For example, a 30-day selection
compares against the preceding 30 days. The comparison period is calculated
automatically and is not a second user-selectable range.

- Posts, Reactions, and Comments show percentage change.
- Engagement Rate shows the relative percentage change between the two pooled
  rates.
- Total Followers shows the absolute follower change between the two period-end
  totals, matching the reference design.
- When the previous value is zero or unavailable, the UI shows a neutral
  unavailable/new-data state instead of infinity or a fabricated percentage.
- Partial historical coverage is disclosed in the tooltip and is not presented
  as a complete comparison.

### Top 5 Posts

The page includes one Top 5 Posts block with a two-option toggle:

- **Reactions** is the initial ranking;
- **Comments** ranks the same eligible dataset by normalized comments.

The ranking unit is the successful destination publication, not the parent
post. A parent sent to multiple social accounts may therefore appear more than
once when more than one destination qualifies. Only destinations published
inside the selected range participate.

Each card shows rank, normalized metric value, platform/account identity,
publication date, content type, excerpt, thumbnail when available, and actions
to open the TryPost post or its public social URL when supported. Ties are
resolved by newest `published_at` and then by stable destination id so the order
does not jump between requests.

A destination whose selected ranking metric is unsupported is excluded from
that ranking. Fewer than five cards are shown when fewer than five eligible
destinations have a real value. An empty state replaces the list when none do.

### Performance

The page includes one Performance table with one row per social account that
has a successful destination publication in the selected range. Multiple
accounts on the same network remain separate rows.

The fixed first-version columns are:

- Channel;
- Posts;
- Reactions;
- Comments;
- Engagement Rate.

Posts use the local successful-destination count. The other columns aggregate
the latest stored post-performance observations using the same normalization
and pooled-rate rules as Summary. Each supported numeric column can be sorted,
and its current value includes the equivalent-period comparison when a valid
comparison exists.

When a network or content type does not expose a metric, the cell shows an
unavailable marker rather than zero. Historical account snapshot metadata keeps
rows presentable after an account is disconnected or deleted.

These are exactly the three additional reporting blocks in v1: Summary, Top 5
Posts, and Performance. More cards, ranking modes, or configurable Performance
columns require a later product decision.

### Date range

The existing analytics range date picker remains the shared page filter for the
Summary, follower chart, Posts widget, Top 5 Posts, and Performance.

- `minDate` is the earliest follower snapshot or successful TryPost publication
  available in the workspace.
- `maxDate` is the latest follower snapshot or successful TryPost publication
  available in the workspace.
- The picker cannot select a range wholly outside those bounds.
- All chart modes and the total use the same selected range.
- A social account connected after the selected start date begins when its own
  data begins; no pre-connection values are invented.
- A widget shows its own empty state when the selected range contains no data
  for that metric.
- With neither follower snapshots nor successful publications, the picker is
  disabled and the page shows an analytics-empty state.

Historical data for a disconnected or deactivated account is retained. Its
line ends on the last day for which it was eligible; it remains visible when
the selected range overlaps that history.

## Collection architecture

```text
Laravel scheduler (daily, UTC)
    -> follower dispatch-only command
        -> one queued job per eligible social account
            -> platform follower collector
                -> normalized follower observation
                    -> persistence boundary

    -> post-performance dispatch-only command
        -> one queued job per eligible destination publication
            -> platform post-metrics collector or trusted local metric source
                -> normalized post-performance observation
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

## Post-performance collection

Reactions, comments, engagement inputs, and Top 5 rankings must not trigger
social API calls while `/analytics` is rendering. They are refreshed in daily
queued jobs and stored behind the same persistence decision gate as follower
observations.

The daily dispatcher selects successful destination publications that have a
platform post id, a connected account with the required access, and remain
inside their refresh window:

- X destinations: through 20 days after publication;
- every other supported destination: through 30 days after publication.

There is no free-versus-paid retention rule in TryPost. All workspaces use the
same collection windows. The windows limit external API work only; all values
already collected are retained permanently.

Each eligible destination gets an independent queued job so one failing API or
post cannot block another. The logical uniqueness key is post-performance +
destination + UTC collection date. The job normalizes only metrics genuinely
returned for that network and content type, preserving unsupported separately
from a measured zero.

Post-performance values are cumulative totals for that destination as of the
collection timestamp. Summary, Top 5 Posts, and Performance use the latest
stored observation for each destination selected by its publication date; they
do not add daily snapshots together.

The normal daily run collects once per UTC day. The final eligible day performs
one final collection before the destination becomes inactive for scheduled
refresh. Transient and rate-limit failures use the same widely spaced, same-day
retry approach as follower collection. If the final-day collection fails, the
latest successful observation remains available with its collection timestamp;
the system does not replace it with zero.

Metrics already maintained from trusted local events, such as webhook-backed
reaction metadata, may be normalized from that local source without making a
redundant provider request. Networks without post analytics still contribute
their locally known Posts count but show other values as unavailable.

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

The newly approved post-performance catalog for this design consists of
normalized reactions, normalized comments, normalized engagement numerator,
exposure denominator and kind, provider collection timestamp, and availability
status per destination. It does not remove the gate: the user may supply more
metrics before the physical schema is selected.

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
- efficient aggregation at a selected end date;
- latest supported post-performance values per destination;
- permanent retention after a destination leaves its refresh window;
- unsupported versus measured-zero post metrics;
- provider and collection timestamps needed to disclose freshness;
- efficient workspace, publication-range, account, and ranking aggregations.

Workspace totals are derived from account observations and are not stored as a
second source of truth.

The Posts widget does not require a new analytics snapshot or collection table.
Its source of truth is the existing destination publication history. A counted
row must belong to a post in the current workspace, have the published status,
and have a `published_at` timestamp inside the selected range. The concrete
query must use the existing enum/status conventions and work on PostgreSQL and
MySQL.

## Read path

`/analytics` reads only local persisted data. It performs no request-time
social API calls for any analytics block.

The server response supplies:

- the workspace's available date bounds;
- the effective selected range after validation;
- the follower total at the range end;
- one daily series per social account;
- account identity and platform presentation metadata;
- actual/carried-forward and exact/approximate provenance required for
  truthful tooltips;
- successful publication totals per social account;
- zero-filled publication buckets and per-account values for the automatically
  selected daily, weekly, or monthly resolution;
- current and previous-period Summary values;
- the two deterministic Top 5 rankings;
- Performance rows and comparisons per social account;
- freshness and availability metadata needed for tooltips and unavailable
  states.

The frontend derives Bar and Growth from this normalized response instead of
requesting separate endpoints. Large date ranges may later be downsampled, but
daily resolution is the source resolution and is sufficient for this first
version.

The server aggregates the Posts dataset at the chosen bucket resolution and
returns both bucketed and range-total values. Publication rows are always
filtered through their parent post's `workspace_id`; a social-account id from
the request is never trusted as the tenancy boundary.

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
- finalizer failure;
- post-performance collection success;
- post-performance metric unsupported;
- post-performance retry or permanent collection failure;
- post-performance destination leaving its refresh window with a final stored
  value.

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

Post-performance collector tests additionally cover:

- native-to-normalized reaction and comment names;
- cumulative metrics stored as one observation rather than summed across days;
- engagement numerator and exposure denominator mapping;
- content-type-specific metric availability;
- unsupported, missing, malformed, and measured-zero distinctions.

### Queue and scheduling tests

- The daily command dispatches one job per eligible account and none for
  LinkedIn, inactive, disconnected, or unsupported accounts.
- Jobs are isolated and idempotent by account, metric, and date.
- Retry delays cover the same UTC day and stop after a successful observation.
- Platform-provided retry timing is respected.
- A permanent authentication failure does not follow the transient retry loop.
- Immediate collection is dispatched after a supported account is connected.
- `withoutOverlapping()` and `onOneServer()` remain present on the schedule.
- Post-performance jobs are dispatched only for successful destinations with a
  usable platform id and access.
- X destinations remain eligible through day 20; other supported destinations
  remain eligible through day 30.
- The final eligible day receives a final collection and older destinations no
  longer create provider jobs.
- Collection-window expiry never deletes an already stored value.
- Unsupported metrics remain distinct from measured zero.

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
- LinkedIn personal and LinkedIn Page never appear or contribute to follower
  analytics v1.
- The Posts Bar mode counts one successful destination per social account in
  the selected range.
- The Posts Stacked Bar mode selects daily, weekly, and monthly buckets at the
  documented range thresholds and zero-fills missing buckets.
- A multi-network post contributes once to every successful destination and
  nothing to failed, rejected, pending, or future-scheduled destinations.
- A destination that succeeds after retries counts only once.
- Direct/native social-network posts are absent because no TryPost publication
  record exists for them.
- LinkedIn personal and LinkedIn Page publications appear in the Posts widget
  even though both remain excluded from follower analytics v1.
- Post aggregation is workspace-scoped through the parent post.
- Historical publications retain presentable account information after the
  social account is disconnected or deleted.
- Summary contains exactly Posts, Total Followers, Reactions, Comments, and
  Engagement Rate.
- Summary compares against the immediately preceding inclusive range of equal
  length and handles zero, unavailable, and partial previous data safely.
- Engagement Rate pools normalized engagement and exposure rather than
  averaging per-post percentages.
- Posts without a valid exposure denominator are excluded only from the rate.
- Top 5 ranks destination publications deterministically by Reactions or
  Comments and excludes unsupported values.
- Performance returns one row per social account, keeps duplicate-network
  accounts separate, supports sorting, and uses the same aggregation rules as
  Summary.

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
- **Fetching post counts from social APIs.** The v1 metric represents successful
  TryPost deliveries, which already have a reliable local destination record;
  provider analytics would add permissions, rate limits, inconsistent history,
  and native posts outside the agreed definition.
- **Counting parent posts.** One parent can target several accounts and can
  partially fail, so the successful destination is the only accurate unit.
- **Persisting daily post-count snapshots.** Publication rows are immutable
  facts that can be aggregated for the selected range without introducing a
  second source of truth.
- **Using one fixed Posts bucket size.** A fixed daily view becomes noisy over
  long ranges, while a fixed weekly or monthly view hides useful short-range
  detail.
- **Refreshing every historical post forever.** Engagement changes slow after
  publication, while an unbounded daily job set would continually increase API
  cost and rate-limit pressure. The last stored result remains available after
  the 20/30-day refresh window closes.
- **Applying plan-based analytics retention.** TryPost has no free analytics
  tier in this design; collection and permanent local retention are consistent
  for every workspace.
- **Averaging individual engagement rates.** It overweights posts with little
  exposure. Pooling the engagement and exposure totals produces a weighted
  workspace/account rate.
- **Treating unsupported metrics as zero.** Zero means the provider measured no
  activity; unsupported means no measurement was available and must remain
  visibly different.

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
