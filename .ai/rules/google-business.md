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
LocalPost.state, topicType, and callToAction.actionType live on App\Enums\GoogleBusiness\{LocalPostState,TopicType,CtaAction}. Compare cases / helpers (isPendingReview, isLive, requiresEvent, allowsCallToAction, requiresUrl) — never raw PROCESSING/SCHEDULED/STANDARD/OFFER strings. Do not ship Google-deprecated Local Post values: no GET_OFFER CTA, no ALERT/COVID_19 topic, no PRODUCT topic, no localPosts.reportInsights. Missing state defaults to Processing; Unspecified (LOCAL_POST_STATE_UNSPECIFIED) is pending review, not published. Missing topic defaults to Standard. Rejected targets have no retry — the user must duplicate the post. The Show/Edit publishing overlay must use isActivelyPublishing (in-flight platforms only); pending_review keeps post.status=publishing and would hide the page for up to 24h.

## GBP publish result uses tryFrom not fromApi
PublishToSocialPlatform::recordPublishResult must use LocalPostState::tryFrom, never fromApi. Other publishers omit `state`; fromApi(null) is Processing and would park LinkedIn/X/… in pending review. The GBP publisher always returns `$state->value` after fromApi, so the job only sees a known case.
