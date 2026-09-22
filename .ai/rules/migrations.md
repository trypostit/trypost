---
paths:
  - 'app/Jobs/PostHog/**'
  - 'app/Models/PostPlatform.php'
  - 'database/migrations/**'
---

# Migrations

## Keep publishing activity lookup scoped and minimal
Account publishing activity must scope through indexed account workspace/post IDs before ordering PostPlatform rows. Its migration only adds conventional Laravel indexes and must not change PostPlatform.published_at precision. PostHog snapshot retries must keep progressive backoff so transient outages do not exhaust attempts immediately.
