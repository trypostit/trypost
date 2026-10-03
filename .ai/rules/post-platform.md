---
paths:
  - app/Enums/PostPlatform/ContentType.php
---

# Post Platform

## Instagram feed accepts 3:4 despite the Graph API docs
InstagramFeed aspectRatioBounds min is 0.75 (3:4), not the 0.8 (4:5) the IG User Media docs still state. Verified 2026-09-28 against the live API: an unpublished container (POST /{ig-user-id}/media, no media_publish) for a 1080x1440 JPEG, single and carousel item, returned FINISHED, while a 1080x2400 control failed with 36003 "aspect ratio is not supported" — so the ratio is validated at container creation. Do not raise it back to 0.8 from the docs; re-test with a container-only call if Instagram changes behaviour.
