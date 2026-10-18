---
paths:
  - 'app/Actions/SavedReply/**'
---

# Saved Reply

## Saved replies belong to the inbox
Saved replies (workspace_saved_replies) exist only while Platform::inboxModuleEnabled(): web/API 404 and MCP tools refuse when it is off. Managing and using them needs WorkspacePolicy::publishDirectly (the inbox reply gate). The inbox gets them through the `savedReplies` once prop (InboxSavedRepliesResource, key = count+max(updated_at) withTrashed + a cache version SavedReplyVersionObserver bumps); pickers merge replies created inline through composables/inbox/useSavedReplies until the next visit. Variables {name}, {first_name}, {username} are filled on insert (lib/savedReplies.ts), never stored filled.
