---
paths:
  - 'app/Actions/Analytics/**'
---

# Analytics

## Keep analytics reads in Actions
Analytics dashboard and post-metric database reads live in app/Actions/Analytics alongside the existing analytics workflows. Do not introduce an app/Queries layer. Keep workspace report orchestration separate from publication and follower aggregation.
