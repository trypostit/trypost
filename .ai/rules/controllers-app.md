---
paths:
  - app/Http/Controllers/App/InboxConversationController.php
---

# Controllers App

## Review-only channel in By post redirects to List
GET app.inbox.index with a channel whose driver reads reviews but no comments redirects server-side to app.inbox.messages.index with the same query, so every entry point (header link, direct URL, filter) lands in List view. Inbox scope checks live only in Platform::inboxScopes() (Mastodon: read:notifications or read).
