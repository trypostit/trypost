---
subject: "Changelog v1.0.9 — Introducing webhooks 🎉"
---

# Changelog v1.0.9 — Introducing webhooks 🎉

By TryPost Product Team • [Release v1.0.9](https://github.com/trypostit/trypost/releases/tag/v1.0.9)

Hello! A lot of you asked for webhooks. They're in.

## Introducing webhooks

New page in the workspace sidebar: Webhooks. Paste a URL, pick your events, and we POST there when a post is created, scheduled, unscheduled, published, published only on some networks, fails, or gets deleted.

Use it for a Slack ping when something publishes. Or a ticket when Instagram fails. Plenty of people will just point Zapier or n8n at it. A small endpoint on a server you already run works too.

Deliveries are signed, so you can verify they came from us. Send a test, watch the live log, replay a miss. Five failures in a row and we pause it and email you.

The same endpoints are on the API and MCP if you manage things that way.

Hit reply if you wire one up.

Cheers,
Paulo from TryPost.it

---

You're receiving this because you subscribed.
[Unsubscribe]({{unsubscribe_url}})
