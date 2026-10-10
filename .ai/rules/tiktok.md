---
paths:
  - 'app/Services/Social/TikTok*.php'
  - app/Actions/Post/AssignTikTokVideoId.php
  - app/Jobs/ResolveTikTokVideoId.php
  - app/Console/Commands/ResolveTikTokVideoIds.php
---

# TikTok

## TikTok video ids come only from the status fetch
Never infer a TikTok post's video id from video/list (caption prefix, create_time window): it handed each post of a same-caption series the previous post's video (17 posts, October 2026). TikTok reports `publicaly_available_post_id` only after moderation (minutes to hours, seen weeks later); until then the post keeps its publish_id and the profile url. `social:resolve-tiktok-video-ids` (every minute, only querying the database until a post is due; `ResolveTikTokVideoId` through `TikTokPublisher::publicVideoId()`) asks again for 30 days. The resolver and the metrics job write the id through `AssignTikTokVideoId`, which also moves the analytics publication. The only other writer is `ImportExternalPosts::claimedBySentPost` (exact text, single candidate in the match window, deferred when ambiguous), which is safe to keep.
