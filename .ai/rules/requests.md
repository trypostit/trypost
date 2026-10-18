---
paths:
  - 'app/Http/Requests/**'
---

# Requests

## Filter params use named values, never flags
Owner decision 2026-10-17: every list filter in a query string (web, API, MCP) takes a named value backed by an enum, never a boolean flag like `all_posts=1`. Example: `posts=commented|all` (enum App\Enums\Inbox\PostsFilter), like `status=unanswered`, `sort=newest`, `range=30d`. The default value is left out of the URL. Validate with Rule::enum in each request/tool independently.
