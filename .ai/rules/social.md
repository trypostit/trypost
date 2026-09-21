---
paths:
  - app/Services/Social/GoogleBusinessPublisher.php
  - app/Support/Social/GoogleBusinessDerivativeCleaner.php
---

# Social

## GBP JPEG must outlive PROCESSING
Google fetches Local Post sourceUrl after create while LocalPostState is Processing/Scheduled. Keep the JPEG derivative on disk until reconcile settle() or RecoverStuckPosts fails the 24h review. Deleting in publish() finally races PHOTO_FETCH_FAILED. Live/Rejected on the create response may prune immediately. Path is deterministic: google-business-derivatives/{postPlatformId}.jpg. Wire values live on App\Enums\GoogleBusiness\LocalPostState — never compare raw PROCESSING/SCHEDULED strings.
