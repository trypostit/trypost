---
subject: "Changelog v1.0.7 — Reliable uploads, safer connections, and Ukrainian support"
---

# Changelog v1.0.7 — Reliable uploads, safer connections, and Ukrainian support

By TryPost Product Team • [Release v1.0.7](https://github.com/trypostit/trypost/releases/tag/v1.0.7)

Hello! Welcome to this week's update. Here's what's new in TryPost.

## More reliable uploads

Chunked uploads got hardened this week. Filenames with unicode characters or invalid bytes used to break the upload pipeline, which meant a file could get stuck halfway through without warning. That's fixed now, along with a race condition where two upload sessions could step on each other. If uploads have been flaky for you lately, this should clear it up.

## Catching connection problems before they cost you a post

TryPost now checks your connected accounts ahead of anything scheduled soon, so you hear about a broken connection before the post fails, not after. We also got better at spotting dead Threads, Instagram, and Facebook tokens, and fixed a pagination bug that was hiding some of your Facebook Pages from the connection list.

## A more personal workspace

TryPost now speaks Ukrainian. Calendar date pickers follow your chosen language instead of defaulting to English, and the sidebar's workspace and account menus are now one menu instead of two.

## New features

- Add an optional title and destination link to Pinterest posts
- Account owners can delete workspaces
- Manage workspace settings, grant read-only viewer access, and manage API tokens through the MCP assistant

## Fixes

- Draft posts stay drafts. No more getting auto-scheduled.
- Duplicating a post skips accounts you've since removed
- Fixed TikTok posts failing on a malformed request
- YouTube Shorts can run up to 3 minutes now
- Pinterest video processing gets a longer timeout, so slow videos aren't marked failed too early

Cheers,
Paulo from TryPost.it

---

You're receiving this because you subscribed.
[Unsubscribe]({{unsubscribe_url}})
