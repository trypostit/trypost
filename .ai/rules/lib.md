---
paths:
  - resources/js/lib/inbox.ts
---

# Lib

## Inertia v3 sends reset keys as partial data
Inertia v3 adds every `reset` key to X-Inertia-Partial-Data, so a router visit with `reset` becomes a partial reload of only those props. A filter visit in the inbox therefore passes `only: INBOX_PAGE_KEYS` (every page prop) together with `reset: INBOX_SCROLL_KEYS`; without it the filters, view and counts keep their old values (tests/Browser/InboxTest.php "picking a status updates the filter").

## Inbox reloads name the open conversation
On the index route the server picks a default post/conversation by sort, which moves after an action. So every action and realtime reload leaves conversation/conversationThreads/directMessages out (inboxReloadOptions(conversationId)) and reads them again with reloadOpenConversation(id, aroundMessageId), which sends thread_conversation (and thread_around for the comment acted on) and resets the threads if another conversation came back. Never reload those props without thread_conversation.
