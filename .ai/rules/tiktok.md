---
paths:
  - 'app/Services/Social/TikTok*.php'
  - app/Actions/Post/AssignTikTokVideoId.php
  - app/Jobs/ResolveTikTokVideoId.php
  - app/Console/Commands/ResolveTikTokVideoIds.php
---

# TikTok

## TikTok video ids come only from the status fetch
Never infer a TikTok post's video id from video/list (caption prefix, create_time window): it handed each post of a same-caption series the previous post's video (17 posts, October 2026). TikTok reports `publicaly_available_post_id` only after moderation (minutes to hours, seen weeks later); until then the post keeps its publish_id and the profile url. `PublishToSocialPlatform` dispatches `ResolveTikTokVideoId` (through `TikTokPublisher::publicVideoId()`) a minute after the publish; `social:resolve-tiktok-video-ids` (every 15 minutes) asks again for slower reviews, for 30 days. The resolver and the metrics job write the id through `AssignTikTokVideoId`, which also moves the analytics publication. The only other writer is `ImportExternalPosts::claimedBySentPost` (exact text, single candidate in the match window, deferred when ambiguous); for TikTok it only claims for a post without a numeric video id (still on its publish_id, or released by the repair), never one whose numeric id TikTok already reported. Only posts public to everyone are asked: TikTok never reports an id for followers, friends or private posts. A video another TryPost post holds is refused (`LogicException` from `reconcileRemoteId`); the resolver logs it once a day instead of failing, and `tiktok:repair-video-ids` is the fix for those.
