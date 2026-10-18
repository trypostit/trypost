---
paths:
  - 'app/Jobs/Inbox/**'
---

# Jobs Inbox

## Replies have their own queue; YouTube shares its quota per channel
SendInboxReply (comment replies and DMs) runs on `inbox-replies`, listed before `inbox` in the Horizon inbox supervisor, so a reply never waits behind long polls. YouTube polls every 30 min by default (INBOX_YOUTUBE_POLL_MINUTES) and each connected YouTube channel gets an equal share of the daily poll units (YouTubeQuota::channelShare); a channel past its share still reads one page per poll, without extra reply pages.

## Inbox sync errors: category stored, failing derived
SyncInboxAccount stores inbox_sync_states.error_category (App\Enums\Inbox\SyncError): messages_unavailable (DirectMessagesUnavailableException, Meta 200/2534041) and permission (Meta accounts only: GraphError::isPermissionDenied, codes 10 and 200-299, or an AnalyticsCollectionException with category permission). It is read by code only, never by message text. failing is derived (consecutive_failures >= SyncError::FAILING_AFTER) by InboxSyncState::syncError(). A successful poll clears error_category. BuildInboxPageProps sends inboxChannels.*.sync_states [{kind, sync_error, last_synced_at}] for every kind in InboxDrivers::kindsFor.

## Meta webhooks drive the poll cadence; subscribe after backfill
HandleMetaWebhook records inbox_sync_states.last_webhook_at per (channel, kind) of the messages it stores. SyncInboxAccount::pollMinutes(account, kind, state): a ReceivesInboxWebhooks network with a webhook in the last trypost.inbox.webhook_recent_hours (24) polls every webhook_reconcile_minutes (360); otherwise poll_minutes (Meta comments/mentions 15, DMs 5). YouTube and non-Meta unchanged. A connect backfills by polling; SubscribeInboxWebhooks is dispatched only when a backfill completes (SyncInboxAccount, not partial) and DispatchInboxSync subscribes only channels whose comment backfill is done.

## Threads deletes: threads_delete scope, removed in every copy
The Threads `delete` webhook field (Moderate topic) needs threads_delete and fires only for posts the connected user deletes. Platform::inboxRemovalScopes() asks for it on connect but never gates sync (a channel without it only misses deletes). HandleMetaWebhook removes the id in every Threads channel storing it (InboxMessage or InboxPendingMessage hash), since the other connected account's copy (comment or mention) is never told by Meta. Deletes by people not connected to TryPost are not detected (no polling-based removal yet).
