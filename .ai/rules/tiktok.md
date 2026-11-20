---
paths:
  - 'app/Services/Social/TikTok*.php'
  - app/Actions/Post/AssignTikTokVideoId.php
  - app/Jobs/ResolveTikTokVideoId.php
  - app/Console/Commands/ResolveTikTokVideoIds.php
---

# TikTok

## TikTok video ids come only from the status fetch
Never infer a TikTok post's video id from video/list (caption prefix, create_time window): it handed each post of a same-caption series the previous post's video (17 posts, October 2026). TikTok reports `publicaly_available_post_id` only after moderation (minutes to hours, seen weeks later); until then the post keeps its publish_id and the profile url. `PublishToSocialPlatform` dispatches `ResolveTikTokVideoId` (through `TikTokPublisher::publicVideoId()`) a minute after the publish; `social:resolve-tiktok-video-ids` (every 15 minutes) asks again for slower reviews, for 30 days. The resolver and the metrics job write the id through `AssignTikTokVideoId`, which also moves the analytics publication. The only other writer is `ImportExternalPosts::claimedBySentPost` (exact text, single candidate in the match window, deferred when ambiguous); for TikTok it only claims for a post without a numeric video id (still on its publish_id, or released by the one-off repair run in October 2026), never one whose numeric id TikTok already reported. Only posts public to everyone are asked: TikTok never reports an id for followers, friends or private posts. A video another TryPost post holds is refused (`LogicException` from `reconcileRemoteId`); the resolver logs it once a day instead of failing, and such a post needs a manual fix (the one-off `tiktok:repair-video-ids` ran in production in October 2026 and was removed).

## A refused TikTok status fetch is not a failed publish
TikTok answered status/fetch with 400 `invalid_params` / `invalid_publish_id` for publish_ids it published in the same second (two posts, October 2026: marked failed, one reposted by hand and duplicated). After /init/ accepts a publish, a refused status fetch (not 429/5xx, not a dead token, not a Permission or ContentPolicy refusal, which fail at once) is checked again until `STATUS_UNCONFIRMED_SECONDS` (5 minutes after `tiktok_init_at`), then fails with `TikTokPublishException::unconfirmed()` asking the user to check the profile. Never send the post again (/init/) from that path. Status checks run at 5 s, 30 s, 1 min, then every minute after `tiktok_init_at` until the hour window closes; a content rejection arrives as a FAILED status with its fail_reason.
