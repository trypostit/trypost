---
subject: "Changelog v1.0.9 — Introducing webhooks 🎉"
---

# Changelog v1.0.9 — Introducing webhooks 🎉

By TryPost Product Team • [Release v1.0.9](https://github.com/trypostit/trypost/releases/tag/v1.0.9)

This week we shipped something I've wanted in TryPost for a while: webhooks.

## Introducing webhooks

Give us a URL. Pick the post events you care about. We POST to that URL the moment they happen.

A Slack ping when a post goes live. A ticket when Instagram fails. A row in your own database when someone on the team schedules something. Zapier, Make, n8n, or a tiny endpoint you wrote in an afternoon.

The events you can subscribe to:

- Post created
- Post scheduled or unscheduled
- Post published (or only on some networks)
- Post failed
- Post deleted

Subscribe to the ones you care about. Skip the rest.

Every delivery is signed, so your endpoint can check it came from us. There's a test button, a live log of each attempt (what we sent, what your server said back), and a Replay if one missed. If the endpoint fails five times in a row we pause it and email you.

You'll find it in the workspace sidebar, under Webhooks. If you build against the API or talk to TryPost from an AI assistant, you can manage the same endpoints from there too.

If you wire something cool, reply to this. I read them. 👋

Cheers,
Paulo from TryPost.it

---

You're receiving this because you subscribed.
[Unsubscribe]({{unsubscribe_url}})
