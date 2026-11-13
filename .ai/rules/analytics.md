---
paths:
  - 'app/Actions/Analytics/**'
---

# Analytics

## Keep analytics reads in Actions
Analytics dashboard and post-metric database reads live in app/Actions/Analytics alongside the existing analytics workflows. Do not introduce an app/Queries layer. Keep workspace report orchestration separate from publication and follower aggregation.

## A merged Facebook publication leaves its post on the video id
UpsertAnalyticsPublication::mergeDiscovery() merges the Page post discovery of a Facebook video or photo into the TryPost publication, which takes the Page post id while the post keeps its video or photo id. Never move the post there (reconcileRemoteId does, for TikTok and for the Instagram and TikTok ids claimedBySentPost resolves): repurpose recognises what TryPost published by comparing the source's video ids with posts.platform_post_id (PollRepurposeSource::idsPublishedByTryPost). The merge checks under its lock that the publication is available and still on the post's own id. Same-day snapshots of the two rows go through WritePublicationDailySnapshot::combine(), the same-day rule of every read. A TryPost write that finds a publication carrying its post's media id keeps what discovery reported about the Page post (UpsertAnalyticsPublication::keepsDiscovery()). Which Page post is the TryPost one is decided in ImportExternalPosts (see .ai/rules/post.md).

