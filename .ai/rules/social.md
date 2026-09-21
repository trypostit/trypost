---
paths:
  - app/Services/Social/GoogleBusinessPublisher.php
  - app/Support/Social/GoogleBusinessDerivativeCleaner.php
---

# Social

## GBP JPEG must outlive PROCESSING
Google fetches Local Post sourceUrl after create while state is PROCESSING/SCHEDULED. Keep the JPEG derivative on disk until reconcile settle() or RecoverStuckPosts fails the 24h review. Deleting in publish() finally races PHOTO_FETCH_FAILED. LIVE/REJECTED on the create response may prune immediately. Path is deterministic: google-business-derivatives/{postPlatformId}.jpg.
