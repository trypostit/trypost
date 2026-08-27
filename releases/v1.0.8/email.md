---
subject: "Changelog v1.0.8 — Publishing that finishes, Facebook Pages, Asset Library"
---

# Changelog v1.0.8 — Publishing that finishes, Facebook Pages, Asset Library

By TryPost Product Team • [Release v1.0.8](https://github.com/trypostit/trypost/releases/tag/v1.0.8)

Hello! Welcome to this week's update. Here's what's new in TryPost.

## Publishing that finishes what it started

Instagram and TikTok process video in the background, and sometimes they take longer than one publish attempt can wait around for. That could end badly: the same video posted twice, or a TikTok marked as done before TikTok had actually finished with it.

TryPost now saves its place. A retry picks up the upload already in progress instead of starting a second one, and Instagram waits for the platform to confirm the post is ready before publishing it. Threads had a cousin of this bug, where media reports as missing for a second or two right after processing; we retry that now instead of giving up. Bluesky also checks in less often during long transcodes.

## The Facebook Pages you couldn't connect

If you administer a Facebook Page through a Business Portfolio instead of holding a direct role on the Page itself, which is how most Pages work these days, TryPost used to tell you it found no Pages at all. It was asking Facebook the wrong question.

It now checks your Business Portfolio too, so those Pages turn up in the picker with the rest. The connect window is also more honest when it comes up short: it tells you whether you have no Pages, Pages you can't post to, a permission you declined, or a list we couldn't finish reading.

## Your Asset Library, from anywhere

Your workspace's Asset Library is now reachable over the API and through AI assistants connected to TryPost. You can list what's in there, preview a file, and attach something you've already uploaded to a draft or scheduled post. You don't have to re-upload a file you gave us last month.

## New features

- Browse, preview, and attach Asset Library files over the API and via connected AI assistants
- Connect your first social account during signup, before you reach checkout
- Self-hosted: connect more than one account per network by setting `ALLOW_MULTIPLE_SOCIAL_ACCOUNTS`
- Self-hosted: search now works on MySQL as well as PostgreSQL

## Fixes

- Instagram and TikTok publishes no longer duplicate or report success early when the platform is slow
- Threads posts no longer fail when media briefly reports as missing right after processing
- Facebook Pages reached through a Business Portfolio now appear when connecting
- Pinterest rejections explain that the site doesn't allow saving Pins, instead of showing raw error data
- The "view on LinkedIn" link on company page posts now opens the post
- X connections refresh before the token expires rather than after, so they stop dropping out
- AI post generation no longer misses the start of what it's writing
- The MCP settings page no longer errors for a client that has never connected
- Self-hosted: OpenRouter works as the AI provider again

## One thing we removed

The browsable post template catalog is gone. If you write posts from scratch or with AI, nothing changes for you.

Three people outside the team shipped work in this release. Thanks to James for finding the Business Portfolio bug and reproducing it against a live account, and to Hafiz and Jamie for multi-account support and MySQL.

Cheers,
Paulo from TryPost.it

---

You're receiving this because you subscribed.
[Unsubscribe]({{unsubscribe_url}})
