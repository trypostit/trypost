---
paths:
  - 'app/Jobs/PostHog/**'
  - 'app/Models/PostPlatform.php'
  - 'database/migrations/**'
---

# Migrations

## Preserve publishing activity ordering and lookup indexes
Account publishing activity must scope through indexed account workspace/post IDs before ordering PostPlatform rows. PostPlatform.published_at stores microseconds so simultaneous multi-network publications are ordered by their actual completion time; preserve its precision-aware mutator and schema precision. PostHog snapshot retries must keep progressive backoff so transient outages do not exhaust attempts immediately.
