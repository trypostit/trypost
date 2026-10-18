---
paths:
  - 'app/Services/Inbox/**'
  - app/Services/Inbox/Drivers/GoogleBusinessInboxDriver.php
  - 'resources/js/components/inbox/**'
  - app/Services/Inbox/Drivers/MastodonInboxDriver.php
---

# Inbox

## YouTube inbox spends through YouTubeQuota
Every YouTube Data API call the inbox makes goes through App\Services\Inbox\YouTubeQuota (app-wide, per Pacific day): polls stop at daily_units − reply_reserve_units (trypost.inbox.youtube.*, 5000/1000 by default), replies may use the reserve, and a 403 quotaExceeded from Google spends the day for every account until midnight PT (retryAt, so SyncInboxAccount defers without reporting). Never call the Data API from the inbox without it.

## GBP reviews: one reply per review, bytes, 401 rule
The review reply id is `{review name}/reply`; Google keeps one reply per review, so a new reply (PUT …/reply) replaces the old one and SendInboxReply merges it into that row (body, state, replying member). The limit is 4096 bytes (ReplyLengthUnit::Bytes, byteLength() on the client). A 401 after a live ConnectionVerifier::verify() is PlatformUnavailableException and never expires the channel. A rejected reply (review_reply_state = rejected) reopens the review; pending replies are re-read every poll.

## Reviews show in List view only
By post lists comment conversations only (ListInboxConversations $kinds = [Comment] from BuildInboxPageProps); a review-only channel picked in By post opens the List view; API and MCP list both kinds. A review deep link (`app.inbox.conversations.show`, the activity email) redirects to the List view filtered to its channel (status all); By post never renders a review.

## GBP rejected replies: reasons, rechecks, replaced time
A rejected reply's policyViolation is grouped by App\Enums\Inbox\ReviewRejectionReason (every documented value, unknown → policy) and shown as visible text (resource `rejection_reason`, inbox.review.rejection.* in 16 locales), never a title attribute; the reply box opens with the rejected text. Pending rechecks run oldest updated_at first (rows are touched when rechecked); A recheck 404 removes nothing (reviews.get answers 404 for reviews the list still returns, October 2026); a review read back without our pending reply leaves the reply pending (Google shows a reply only after moderation, and removals are never inferred); other non-limit errors are reported (redacted) and skipped, only a rate limit fails the poll. A removed reply never answers its review (the review reopens), and a reply sent again under the same id clears `removed_at`. A review reply (same `{review}/reply` id) is the one message whose platform_created_at follows the latest read or send.

## Mastodon polls are capped and resumable
A poll makes at most MAX_CONTEXT_CALLS context calls (Mastodon allows 300/5 min per account); unread candidates go to the cursor's `backlog` and are read first next poll. A thread that fails for any other reason (deleted, made private) is dropped from the backlog. A 429 while reading threads returns what was read plus PollResult::$retryAt (SyncInboxAccount schedules next_sync_at there) instead of throwing. The forward cursor is the newest id read (last min_id page), not the first page.

## One switch for the whole inbox
INBOX_ENABLED (trypost.inbox.enabled) is the only inbox flag (owner decision 2026-10-17): on, comments, reviews, mentions and DMs work on every network with an inbox driver, and every connect asks for all inbox scopes (Platform::inboxConnectScopes: comments + inboxMentionScopes + inboxDirectMessageScopes; Bluesky's connect page asks for DM access on the app password); off, nothing of the inbox exists and connects ask for no inbox scope. Never add a per-network or per-kind flag again. Which kinds a network has comes from its driver's kinds() (Platform::inboxKindEnabled); what a channel may sync comes from its granted scopes (SocialAccount::hasInboxAccessFor), so a channel connected before a scope existed shows "reconnect". LinkedIn Page has no inbox (needs Community Management API access) and Meta DMs have no Human Agent tag: the reply window is always 24 h.

## Mentions: one gate, own tab
Mention sync goes through InboxDrivers::kindsFor() (sync job, dispatcher, backfill, Meta webhook jobs): a Mention kind syncs only while the driver reads mentions and SocialAccount::hasInboxMentionAccess() (Platform::inboxMentionScopes(), Threads threads_manage_mentions). InboxTab scopes lists, counts and all/older resolves (comments = comment+review, mentions = mention); the sidebar badge sums both (Counter mode only), the activity email and comment stats never count mentions. A message whose parent is stored joins the parent's conversation (UpsertInboxMessage), which is how replies under a mention land in it.

## Direct messages: own gate, flat conversations
DMs sync and show only while Platform::inboxKindEnabled(DirectMessage) (the driver reads DMs) and SocialAccount::hasInboxAccessFor(DirectMessage) (Meta scopes, Bluesky token scope claim via BlueskyTokenScope, Mastodon read:statuses); the gate is InboxDrivers::kindsFor and InboxTab::enabledOn (visibleInTab/isVisible → policy 404s). A DM conversation is flat (isFlat): an outbound answers every earlier inbound of the conversation, sending/failed rows answer nothing, reply_window_ends_at comes from DirectMessageDriver::replyWindowEndsAt and is enforced by SendInboxDirectMessage and again in SendInboxReply before the network call (delivery_window_closed). Sending is send-inbox-direct-message-tool [false,true,true].

## Inbox polls run on a time budget and resume
Every long inbox poll checks App\Services\Inbox\PollBudget (scoped, trypost.inbox.poll_budget_seconds, 90 s) between units of work (a publication for Meta comments via ScansRecentPublications::scanPublications, every conversation and every message for Meta DMs, whose cursor keeps the page it stopped on plus the conversations and messages of that page already read (ResumableCursor::$done), a changed thread for YouTube) and, once spent, returns PollResult(partial: true) with a cursor that resumes there; SyncInboxAccount upserts what was read, comes back at once (next_sync_at = now) and keeps backfilled_at null, and a backfill with a stored cursor resumes it. Inbox HTTP uses InboxHttpClient (trypost.inbox.http_timeout_seconds, 20 s); the job times out at 300 s, the Horizon inbox supervisor at 330 s, and failed() records a redacted last_error and backs off. Mastodon counts failed reads per id in the cursor (attempts) and drops an id after 5, reporting it once. Bluesky and Mastodon polls spend the analytics per-host guard (AnalyticsRateLimits::sharedHostGuard).

## Removals come from webhooks only, never inferred
Owner decision 2026-10-18: a message is removed (removed_at) only when the network says so by webhook (Facebook feed verb remove/delete, Threads `delete` field, Meta DM is_deleted, review NOT_FOUND on read). Never infer a removal by polling: no list diffing (a comment missing from /comments, a post missing from /tagged) and no single-item read errors (Graph 100/33 also means missing permission). Where the network sends nothing (Instagram comments, Facebook mention posts on a profile, Threads replies deleted by others), the message stays. Do not add polling-based removal.
