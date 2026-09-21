---
paths:
  - 'app/Enums/GoogleBusiness/**'
  - app/Jobs/PublishToSocialPlatform.php
  - app/Jobs/ReconcileGoogleBusinessPost.php
  - app/Console/Commands/ReconcileGoogleBusinessPosts.php
  - app/Services/Social/GoogleBusinessPublisher.php
  - app/Support/PostPlatformMetaRules.php
  - app/Services/Social/GoogleBusinessAnalytics.php
---

# Google Business

## GBP Local Posts v4 values are enums
LocalPost.state, topicType, and callToAction.actionType live on App\Enums\GoogleBusiness\{LocalPostState,TopicType,CtaAction}. Compare cases / helpers (isPendingReview, isLive, requiresEvent, allowsCallToAction, requiresUrl) — never raw PROCESSING/SCHEDULED/STANDARD/OFFER strings. Do not ship Google-deprecated Local Post values: no GET_OFFER CTA, no ALERT/COVID_19 topic, no PRODUCT topic, no localPosts.reportInsights. Missing state defaults to Processing; Unspecified (LOCAL_POST_STATE_UNSPECIFIED) is pending review, not published. Missing topic defaults to Standard. Rejected targets have no retry — the user must duplicate the post. The Show/Edit publishing overlay must use isActivelyPublishing (in-flight platforms only); pending_review keeps post.status=publishing and would hide the page for up to 24h.

## GBP publish result uses tryFrom not fromApi
PublishToSocialPlatform::recordPublishResult must use LocalPostState::tryFrom, never fromApi. Other publishers omit `state`; fromApi(null) is Processing and would park LinkedIn/X/… in pending review. The GBP publisher always returns `$state->value` after fromApi, so the job only sees a known case.

## GBP event title is 58 characters, coupon is not
LocalPost event.title is capped at TopicType::TITLE_MAX_LENGTH (58) — Google returns Must be at most 58 characters even though the v4 schema page omits the limit. Do not reuse 58 on offer.coupon_code / terms; those have no published cap. Mirror the title cap in resources/js/types/google-business.ts as GOOGLE_BUSINESS_EVENT_TITLE_MAX. requiredMetaViolation() must reject a stored title over 58 — MCP PublishPostTool never re-runs rules(). Do not apply the cap to leftover titles on STANDARD.

## GBP reconcile refreshes on 401
ReconcileGoogleBusinessPost must call ConnectionVerifier::verify() after a TokenExpiredException from fetchLocalPost and retry the GET once. If refresh also fails, mark the SocialAccount token-expired, then deferOrGiveUp. Do not park a 401 in pending_review for 24h while a refresh would settle it. RecoverStuckPosts times the 24h ceiling from submitted_at only — never created_at.

## GBP analytics must not cache a failed fetch
GoogleBusinessAnalytics caches only a successful array. An HTTP failure or a missing location returns false and is not written to cache — a 500 must not blank the dashboard for an hour. Publish/verify/analytics require both location_id (v4) and location_name (v1) via GoogleBusinessResourceName::connectedLocation().

## Local Posts 401 after a live BI verify does not expire the account
retryAfterExpiredToken calls ConnectionVerifier::verify() then retries fetchRemote. markAsTokenExpired only when verify() itself throws TokenExpiredException. If BI verify succeeds and Local Posts still 401s, deferOrGiveUp without disconnecting — the token still works for the house verify. ReconcileGoogleBusinessPosts only dispatches enabled() pending_review rows.
