---
paths:
  - app/Enums/GoogleBusiness/**
  - app/Jobs/PublishToSocialPlatform.php
  - app/Jobs/ReconcileGoogleBusinessPost.php
  - app/Services/Social/GoogleBusinessPublisher.php
  - app/Support/PostPlatformMetaRules.php
---

# Google Business

## GBP Local Posts v4 values are enums
LocalPost.state, topicType, and callToAction.actionType live on App\Enums\GoogleBusiness\{LocalPostState,TopicType,CtaAction}. Compare cases / helpers (isPendingReview, isLive, requiresEvent, allowsCallToAction, requiresUrl) — never raw PROCESSING/SCHEDULED/STANDARD/OFFER strings. GET_OFFER is DeprecatedCtaAction only, not a CtaAction case. Missing state defaults to Processing; missing topic defaults to Standard. ALERT and PRODUCT are not authorable topic types.

## GBP publish result uses tryFrom not fromApi
PublishToSocialPlatform::recordPublishResult must use LocalPostState::tryFrom, never fromApi. Other publishers omit `state`; fromApi(null) is Processing and would park LinkedIn/X/… in pending review. The GBP publisher always returns `$state->value` after fromApi, so the job only sees a known case.
