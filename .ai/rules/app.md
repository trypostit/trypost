---
paths:
  - 'app/**'
---

# App

## Reuse model scopes for canonical state filters
When a model already exposes a scope for a recurring state filter, jobs, commands, services, observers, and controllers must use that scope instead of repeating raw where clauses. Add a descriptive model scope when a canonical state condition will be reused (for example SocialAccount::connected()->active()).
