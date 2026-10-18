---
paths:
  - 'app/Console/Commands/Inbox/**'
---

# Commands Inbox

## Dispatcher puts off channels it cannot sync
DispatchInboxSync only loads due states of kinds the network's driver reads (SQL filter), and stamps next_sync_at far out for what it skips: 24 h (NO_ACCESS_RECHECK_HOURS) for a channel without inbox access or a kind it lacks the scope for, 1 h for a workspace without app access. InboxSyncState::clearWaits() makes them due again on (re)connect (DispatchInboxBackfill) and on a scope change (SocialAccountObserver).
