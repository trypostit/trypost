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
7. Which detailed metrics, including video-retention metrics where available,
   explain the performance of an individual published destination?

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
contributes to the workspace total. LinkedIn publishing remains untouched, but
supported LinkedIn post analytics participate in the same database-backed post
metrics migration as the other networks. Successfully published LinkedIn
destinations also appear in the Posts widget because that metric comes from
TryPost's local publication records and requires no LinkedIn follower-analytics
permission.

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

### Individual post analytics

The existing analytics area inside each published post is part of this same
delivery. It must stop fetching provider metrics during the page request and
must stop treating the five-minute Redis entry as the metric source.

The post-performance pipeline collects through queued jobs and persists through
one observation writer. The individual post page, REST API, MCP, Summary, Top 5
Posts, and Performance all read the same latest persisted observation for each
destination. Redis is not a source of truth for post analytics; a
database-query cache may be added later only if profiling proves it useful.

The individual post page is richer than the cross-network reporting blocks. It
shows every persisted metric supported by that platform and content type,
grouped into common engagement, exposure, and video-retention sections. It also
shows when the metrics were last collected and whether the value is actual,
estimated, stale after a failed refresh, experimental, or unsupported.

The response contract uses stable metric keys and explicit units. Translated
labels are presentation only and are never stored as metric identity. An
unsupported metric is omitted or marked unavailable; an API error must not
replace the most recent successful value with zero.

### Content-specific metric catalog

The initial content-type analysis establishes the following catalog. It is the
minimum that the platform collectors should request and persist when supported
by the connected account, login type, API version, and media type.

| Content type | Metrics for the individual post page | Current TryPost gap |
| --- | --- | --- |
| Instagram feed | Views, reach, likes/reactions, comments, shares, saves, reposts, total interactions, follows, profile visits, and profile activity | The current collector omits views, reposts, follows, profile visits, and profile activity |
| Instagram Reel | Views, reach, likes/reactions, comments, shares, saves, reposts, total interactions, total watch time, average watch time, and skip rate when returned | The current collector already has views/reach/basic engagement but omits interactions, reposts, watch-time metrics, and skip rate |
| Instagram Story | Views, reach, replies, shares, reposts, follows, profile visits/activity, link clicks, and navigation breakdown | The current collector only requests views, reach, and replies |
| YouTube video or Short | Video views, engaged views, watch time, average view duration, average percentage viewed, likes/reactions, comments, shares, subscribers gained, and subscribers lost | The current collector already has views, watch time, average duration, likes, comments, and shares, but omits engaged views, average percentage viewed, and subscriber change |
| TikTok video | Views, likes, comments, and shares | The current Display API collector already exposes the complete performance set available to this integration; video duration is metadata, not watch time |
| Pinterest image Pin | Impressions, saves, comments, reactions, engagements, engagement rate, save rate, Pin clicks/rate, outbound clicks/rate, profile visits, follows, total audience, and engaged audience | The current collector has impressions, saves, Pin clicks, and outbound clicks, but omits the remaining native and lifetime metrics |
| Pinterest video Pin | Every applicable image-Pin metric plus video views, average video play time, 10-second plays, plays to 95%, and total play time | The current collector only adds basic video views and omits the richer video-retention metrics |

Instagram Reel total watch time is displayed in minutes and average watch time
in seconds, matching the reference UI, while persistence retains the canonical
unit needed to avoid rounding loss. Metrics that Meta marks estimated or in
development, currently including Reel reach, watch time, views, total
interactions, and skip rate as applicable, preserve that precision/stability
metadata for tooltips.

Meta documents that Instagram insight values can lag by up to 48 hours. A
successful response with an absent or not-yet-populated metric is therefore not
converted to measured zero. The read model keeps the last successful value and
exposes its collection time so the UI can distinguish fresh, delayed, and stale
data. Provider retention does not control TryPost retention: once collected,
the observation remains stored under TryPost's permanent-history policy.

Instagram Reel engagement rate uses the normalized interactions divided by
reach when both are available. This matches the reference behavior and avoids
using repeated views as though they were unique people.

The Meta collector must parse both `values[].value` and `total_value.value`, and
must preserve requested breakdowns such as Story navigation actions. It splits
incompatible or experimental metric families into separate provider requests:
one rejected metric must not blank every otherwise supported metric for the
post.

Instagram cross-posted and Facebook-only view metrics are conditional: they are
stored and displayed only when the Reel was actually shared or recommended to
Facebook and the API returns them. They do not replace Instagram views.

The approved TikTok Display API does not expose total watch time, average watch
time, completion rate, or retention. Those values must remain unavailable
rather than being inferred from view count and video duration.

YouTube's per-video report already supports the retention metrics needed for
Shorts. The collector expands its current query rather than introducing a
second Shorts-specific API path.

On the individual YouTube post, the cross-network `Reactions` label maps to the
native `likes` metric, `Comments` maps to `comments`, and `Video Views` maps to
`views`. The YouTube Analytics API does not return a native per-video engagement
rate, so that native field is unavailable rather than fabricated. A normalized
TryPost engagement rate used by Summary or Performance is a separately labelled
derived value with its numerator and `views` denominator preserved; it must not
be represented as a provider-returned YouTube metric.

Pinterest uses two complementary sources. The Pin Analytics endpoint provides
date-range metrics, including impressions, saves, engagement, clicks, rates,
and video performance. `GET /pins/{pin_id}?pin_metrics=true` provides rolling
and lifetime Pin metrics, including total comments and total reactions. The
collector combines both into one idempotent observation while retaining each
metric's time basis (`range`, `rolling_90_day`, or `lifetime`). A lifetime value
must never be presented as though it occurred entirely inside the selected
dashboard range.

Pinterest's native engagement rate remains the provider-defined engagements
divided by impressions. Saves, comments, and reactions are displayed as their
own metrics; comments and reactions are not silently added to the provider's
engagement numerator unless Pinterest includes them in the returned native
definition. This keeps the reference labels without changing their meaning.

Instagram Stories require an exception to the normal 30-day refresh window:
their media insights are generally available for only 24 hours. A queued
collection is scheduled during the Story lifetime, the `story_insights` webhook
is enabled when the integration supports it, and a final collection runs
shortly before expiry. Webhook deliveries and scheduled jobs persist through the
same idempotent observation writer. The normal once-daily sweep alone is
insufficient because it can miss the availability window. Stored Story metrics
remain available after the provider stops serving them. Privacy-threshold or
"not enough viewers" responses mean unavailable, not measured zero and not a
reason to erase a previous observation.

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

    -> Instagram Story lifecycle jobs and `story_insights` webhook
        -> same idempotent post-performance observation writer

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

The complete supported post metric catalog, including reactions, comments,
exposure, engagement inputs, and video retention, must not trigger social API
calls while `/analytics` or an individual post is rendering. Metrics are
refreshed in queued jobs and stored behind the same persistence decision gate as
follower observations.

The daily dispatcher selects successful destination publications that have a
platform post id, a connected account with the required access, and remain
inside their refresh window:

- X destinations: through 20 days after publication;
- every other supported destination: through 30 days after publication.

Instagram Stories use their separately documented within-24-hours schedule
instead of the 30-day sweep.

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

The currently specified post-performance catalog includes both cross-network
fields and content-specific detail. Cross-network fields are normalized
reactions, normalized comments, normalized engagement numerator, exposure
denominator and kind, provider collection timestamp, and availability status
per destination.
The full observation additionally retains stable metric key, numeric value,
unit, content type, precision/stability flags, and provider metric identity for
every supported native metric described by the catalog. It does not remove the
gate: the user may supply more metrics before the physical schema is selected.

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
- stable metric keys and units independent of the active UI locale;
- content-type-specific metrics without sparse schema assumptions;
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
- the complete latest metric set for each destination on the individual post
  page, REST API, and MCP;
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
- Instagram Reel watch-time units and experimental/estimated flags;
- YouTube Short watch time, average duration, average percentage viewed, and
  subscriber-change mapping;
- YouTube post labels map likes to Reactions and views to Video Views while the
  unavailable native engagement rate remains distinct from TryPost's derived
  normalized rate;
- TikTok never fabricating unsupported retention metrics;
- Pinterest saves, comments, reactions, impressions, and native engagement
  rate retain their distinct metric identities and time bases;
- Pinterest video Pins map average play time, 10-second plays, 95% plays, and
  total play time with explicit units;
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
- Instagram Story jobs collect while insights are available and perform a
  final pre-expiry collection even when the normal daily sweep would miss it.

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
- The individual post page, REST API, and MCP return the same persisted latest
  observation and make no provider request during reads.
- The individual post page shows the content-type-specific catalog, canonical
  units, freshness, and metric stability/provenance.
- Expired Redis entries cannot remove or change persisted post analytics.

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
- **Keeping the individual post page on request-time API calls and Redis.** It
  would give the post page a different source and freshness model from Summary,
  Top 5 Posts, Performance, REST, and MCP. All consumers must converge on the
  persisted observation.
- **Reducing post persistence to the five cross-network fields.** That would
  discard high-value, content-specific metrics such as Reel/Short watch time
  and make the individual post page less useful than the provider data already
  available to TryPost.
- **Inferring TikTok retention from duration and views.** Video length describes
  the asset, not how long viewers watched it; the approved integration exposes
  no retention metric.

## External references checked

- Meta Instagram Media Insights, updated September 11, 2026:
  <https://developers.facebook.com/documentation/instagram-platform/reference/instagram-media/insights>
- YouTube Analytics metrics and channel report combinations:
  <https://developers.google.com/youtube/analytics/metrics> and
  <https://developers.google.com/youtube/analytics/channel_reports>
- TikTok Display API video query and Video Object fields:
  <https://developers.tiktok.com/docs/en/tiktok-api-v2-video-query> and
  <https://developers.tiktok.com/docs/en/tiktok-api-v2-video-object>
- Pinterest organic reporting and metric definitions:
  <https://developers.pinterest.com/docs/analytics-and-reports/organic-reporting/>
  and
  <https://developers.pinterest.com/docs/analytics-and-reports/metrics-glossary/>
- Pinterest's official generated API client, including `pin_metrics` lifetime
  comments/reactions and Pin Analytics parameters:
  <https://github.com/pinterest/pinterest-python-generated-api-client/blob/main/docs/PinsApi.md>
- Buffer Insights metric presentation and per-post behavior:
  <https://support.buffer.com/en-us/articles/using-insights-in-buffer-x4gLauQU5a>

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
