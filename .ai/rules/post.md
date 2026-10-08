---
paths:
  - app/Actions/Post/FinalizePostPublication.php
  - app/Jobs/PublishPost.php
  - app/Actions/Post/UpdatePost.php
  - 'app/Actions/Post/**'
---

# Post

## FinalizePostPublication is the only post settler
handle() takes the Post. Every path that can finish the post's publication must call it: PublishToSocialPlatform, ReconcileGoogleBusinessPost, RecoverStuckPosts, PublishPost (also for a post without a channel) and PublishPost::failed, and AbandonGoogleBusinessReview. It settles from `posts.publish_status` (published → Published; failed/rejected → Failed); a post without a destination is a no-op unless it is Publishing, which becomes Failed so it does not sit non-editable forever. Do not mark the post Published / Failed by hand outside Finalize. Finalize keeps the publish time the publisher wrote (`markAsPublished()` only fills `published_at` when it is empty).

## Finalize is idempotent once the post is settled
handle() lockForUpdates the post and returns without notifying when status is already Published or Failed (Status::isSettled()). RecoverStuckPosts and ReconcileGoogleBusinessPost can both finish the post at the 24h ceiling; the second call must not send a second email. Dispatch SendNotification only after the transaction commits.

## Abandoning a Google Business review goes through AbandonGoogleBusinessReview
Abandoning a GBP pending_review (disconnect, recover sweep) goes through AbandonGoogleBusinessReview, which rejects the publication and lets Finalize settle the post.

## One destination per post, stored on the post
A post has at most one destination, stored on the post itself (`posts.social_account_id`, `platform`, `content_type`, `meta`, and the publication fields). Create through CreatePosts/CreateChannelPost; never reintroduce a targets table or grouped writes. Editing uses UpdatePost and may change content type within the same account, never the social account. A legacy draft without a channel (platform null) stays editable as a draft (content, media, labels) and is turned into a channel post only through RecoverEmptyDraft; scheduling or publishing it fails with posts.errors.choose_channel.

## No per-destination validation inside a repurpose batch
ProcessRepurposeItem creates all posts of one run in a single all-or-nothing transaction (CreatePosts batch + SyncOwnedMedia). Never add per-destination/per-platform validation inside CreateChannelPost or SyncOwnedMedia: one bad destination would roll back the whole run, and repurpose items are never retried. Destination health stays a publish-time failure (AGENTS.md "Repurpose account health"); platform rules belong in the FormRequests/CreatePosts validation for user-driven flows.

## ImportExternalPosts is the only exception to Finalize
App\Actions\Post\ImportExternalPosts is the single path allowed to create Published posts (origin network) without FinalizePostPublication. Those posts were already published on the network, not by TryPost, so they are written quietly under Post::withoutEvents: no notification, webhook, PostHog event or job. Every post TryPost publishes itself still settles through Finalize.

## PostApproval is the only approval gate
Every post write that can schedule, queue or publish passes the acting user to CreatePosts / UpdatePost so App\Support\PostApproval can store Status::PendingApproval. Do not gate in controllers, API or MCP tools, and do not pass an actor from system callers (ScheduleNextOccurrence), or approved recurring series start asking again. A pending queue request is never placed in the queue; ApprovePost replays it through UpdatePost as the approver. Appending media to a post (MCP/API attach) goes through AppendPostMedia, never Post::appendMedia directly from an entry point, so an approved post edited by a requester returns to approval. Approve, reject and any UpdatePost of a pending post run under PostApproval::whilePending (lock post-approval:{id}, re-entrant).

## Defer ambiguous captionless Instagram imports
When a captionless Instagram discovery has an unresolved TryPost candidate in the same channel, compatible content type and configured publication-time window, defer importing it without claiming either ID. Do not infer identity from empty captions or timestamps. The owner accepted that a genuine native post in that window may also wait. Exact-ID matches and provider-confirmed originals keep their normal behavior (2026-10-08).

## Instagram discovery can precede publish completion
Discovery can see a live Instagram post while PublishToSocialPlatform is still Publishing or Retrying after losing media_publish's response. Defer matching discoveries without assigning their IDs during that window too; Retrying requires an Instagram container checkpoint. Keep channel, content type, caption and time-window guards. Published-only matching leaves a duplicate-import race.
