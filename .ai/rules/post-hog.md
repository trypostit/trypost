---
paths:
  - 'app/Jobs/PostHog/**'
---

# Post Hog

## Serialize account publishing snapshots at the final send
Publishing activity uses SyncAccountPublishingActivity with per-account debounce and overlap protection. It must re-query the latest confirmed PostPlatform when it executes and send the group update in that same job; routing the snapshot through SendEvent can reorder payloads and regress the account's last publication. The backfill command must reuse this job.
