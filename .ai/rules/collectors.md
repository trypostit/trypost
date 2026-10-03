---
paths:
  - 'app/Services/Analytics/Collectors/**'
---

# Collectors

## Analytics collectors read tokens through AnalyticsAccessToken
Never send $account->access_token directly from a collector client. AbstractApiPublicationCollector and AbstractFollowerCollector go through AnalyticsAccessToken::for(), which refreshes an expired token (YouTube lives 1h) and maps TokenExpired/PlatformUnavailable to authentication/transient. Optional reads (Meta insights, YouTube Analytics report, Facebook comments) must tolerate permission/malformed and keep whatever else was measured; engagement_rate per post is derived on read in ReadPublicationAnalytics (engagements / exposure * 100), never stored by collectors except Pinterest-style provider values.
