---
subject: "Changelog v1.0.8 — Facebook Pages finally connect, reuse your media and more..."
---

# Changelog v1.0.8 — Facebook Pages finally connect, reuse your media and more...

By TryPost Product Team • [Release v1.0.8](https://github.com/trypostit/trypost/releases/tag/v1.0.8)

Hello! Welcome to this week's update. Here's what's new in TryPost.

## The Facebook Pages that wouldn't connect

If you administer a Facebook Page through a Business Portfolio rather than holding a direct role on the Page itself, which is how most Pages work these days, TryPost used to tell you it found no Pages at all. It was asking Facebook the wrong question. It now checks your Business Portfolio too, so those Pages show up in the picker with the rest.

The connect window also stopped guessing when it can't offer you a Page. It tells you which of the four things happened: you have no Pages, you have Pages you can't post to, you declined a permission we need, or we couldn't finish reading the list.

## Your long videos stop posting twice

If you sent a long video to Instagram or TikTok, you may have watched it go out twice, or seen TikTok report the post as done before the upload had actually finished. Both platforms process video in the background, and that takes longer than one publish attempt waits around for.

TryPost now saves its place. When a publish gets retried, it picks up the upload already in progress instead of starting a second one, so nothing goes out twice. Instagram waits for confirmation that the post is ready before publishing it. Threads had a cousin of this bug, where media reports as missing for a second or two right after it uploads fine; we retry that now instead of giving up.

## Reuse your media over the API

If you build against the TryPost API or connect an AI assistant to your workspace, your Asset Library is now reachable from both. You can list what's in there, preview a file, and attach it to a draft or scheduled post without uploading the same image a second time. Inside the app this already worked; now your integrations get it too.

## New features

- Connect your first social account during signup, before you reach checkout
- Browse, preview, and attach Asset Library files over the API and through connected AI assistants
- Self-hosted: connect more than one account per network by setting `ALLOW_MULTIPLE_SOCIAL_ACCOUNTS`
- Self-hosted: search now works on MySQL as well as PostgreSQL

## Fixes

- Pinterest now tells you the site doesn't allow saving Pins, instead of showing raw error data
- "View on LinkedIn" on a company page post opens the post itself, not the page's list of posts
- Your X connection stops quietly dropping out and asking to be reconnected
- AI post generation no longer misses the first few words it writes
- The MCP settings page no longer errors out if you've never connected a client
- Self-hosted: OpenRouter works as the AI provider again

## One thing we removed

The browsable post template catalog is gone. If you write posts from scratch or with AI, nothing changes for you.

Three people outside the team shipped work in this release. Thanks to James for finding the Business Portfolio bug and reproducing it against a live account, and to Hafiz and Jamie for multi-account support and MySQL.

Cheers,
Paulo from TryPost.it

---

You're receiving this because you subscribed.
[Unsubscribe]({{unsubscribe_url}})
