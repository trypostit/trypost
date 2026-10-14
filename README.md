<p align="center">
  <img src="public/images/trypost/logo-dark.png" alt="TryPost" width="190">
</p>

<h1 align="center">The open-source social media scheduler built for AI agents</h1>

<p align="center">
  Let your AI agents create, schedule and publish posts to every network through MCP or the REST API,<br/>
  or write them yourself in a composer your team will love.
</p>

<p align="center">
  <a href="https://github.com/trypostit/trypost/stargazers"><img src="https://img.shields.io/github/stars/trypostit/trypost?style=flat-square&color=7c3aed&labelColor=0a0a0a" alt="Stars"></a>
  <a href="https://github.com/trypostit/trypost/blob/main/LICENSE.md"><img src="https://img.shields.io/badge/license-AGPL--3.0-7c3aed?style=flat-square&labelColor=0a0a0a" alt="License"></a>
  <a href="https://github.com/trypostit/trypost/releases"><img src="https://img.shields.io/github/v/release/trypostit/trypost?style=flat-square&color=7c3aed&labelColor=0a0a0a" alt="Release"></a>
  <a href="https://trypost.it/discord"><img src="https://img.shields.io/badge/discord-join-7c3aed?style=flat-square&labelColor=0a0a0a&logo=discord&logoColor=white" alt="Discord"></a>
</p>

<p align="center">
  <a href="https://trypost.it"><b>Try TryPost Cloud</b></a> &nbsp;&bull;&nbsp;
  <a href="https://docs.trypost.it">Documentation</a> &nbsp;&bull;&nbsp;
  <a href="https://trypost.it/discord">Discord</a> &nbsp;&bull;&nbsp;
  <a href="https://github.com/trypostit/trypost/discussions">Community</a>
</p>

<p align="center">
  <img src="public/images/github/calendar.png" alt="TryPost calendar with a month of posts across eight channels" width="100%">
</p>

---

## Two ways to post, one place to run it

Most posts on TryPost are no longer written by hand. They come from AI agents and scripts. The rest come from teams who still want a great editor, a calendar and someone to approve before anything goes live. TryPost is built for both.

### 🤖 For AI agents: MCP and REST API

Connect Claude, ChatGPT, Cursor or any MCP client and ask in plain words:

> *"Write a week of posts about our spring tours for Instagram, LinkedIn and X, add them to each channel's queue and show me the Instagram insights for last month."*

- **MCP server** with 80+ tools: posts, media, queues, ideas, labels, signatures, analytics and more. [Connect your assistant &rarr;](https://docs.trypost.it/ai/introduction)
- **REST API** for your own scripts and automations: create posts in batch, upload media, read metrics, receive webhooks. [API reference &rarr;](https://docs.trypost.it/api-reference/introduction)
- Everything the app can do, your agent can do. Same rules, same limits, same approvals.

### ✍️ For teams: the web app

- **Composer** that writes once and adapts per network, with a live preview of every post and an AI assistant to rephrase, shorten or expand.
- **Calendar and queues**: drop posts on each channel's posting times, drag to reschedule, see the whole month at a glance.
- **Approvals**: members who need a review send posts for approval, and admins approve or reject in one click. Built for agencies running many brands.
- **Workspaces** to keep every client or brand apart, with its own channels, members and settings.

## A closer look

<p align="center">
  <img src="public/images/github/queue.png" alt="A channel queue with scheduled posts and labels" width="100%">
  <br/><sub><b>Queues.</b> Set each channel's posting times once. New posts take the next free slot.</sub>
</p>

<p align="center">
  <img src="public/images/github/ideas.png" alt="Ideas board in dark mode with cards in To Do, In Progress and Done" width="100%">
  <br/><sub><b>Ideas board, in dark mode.</b> Collect ideas, move them through your stages and turn them into posts.</sub>
</p>

<p align="center">
  <img src="public/images/github/insights.png" alt="Channel insights compared with the previous period" width="100%">
  <br/><sub><b>Insights.</b> Followers, reach and engagement per channel and per post, compared with the previous period.</sub>
</p>

## Supported networks

Every post is published natively through each network's official API. No reminders, no "finish in the mobile app".

<p align="center">
  <img src="public/images/github/networks/instagram.png" width="44" alt="Instagram" title="Instagram">&nbsp;
  <img src="public/images/github/networks/tiktok.png" width="44" alt="TikTok" title="TikTok">&nbsp;
  <img src="public/images/github/networks/linkedin.png" width="44" alt="LinkedIn" title="LinkedIn">&nbsp;
  <img src="public/images/github/networks/x.png" width="44" alt="X" title="X">&nbsp;
  <img src="public/images/github/networks/youtube.png" width="44" alt="YouTube" title="YouTube">&nbsp;
  <img src="public/images/github/networks/facebook.png" width="44" alt="Facebook" title="Facebook">&nbsp;
  <img src="public/images/github/networks/threads.png" width="44" alt="Threads" title="Threads">&nbsp;
  <img src="public/images/github/networks/bluesky.png" width="44" alt="Bluesky" title="Bluesky">&nbsp;
  <img src="public/images/github/networks/pinterest.png" width="44" alt="Pinterest" title="Pinterest">&nbsp;
  <img src="public/images/github/networks/mastodon.png" width="44" alt="Mastodon" title="Mastodon">&nbsp;
  <img src="public/images/github/networks/google_business.png" width="44" alt="Google Business" title="Google Business">&nbsp;
  <img src="public/images/github/networks/telegram.png" width="44" alt="Telegram" title="Telegram">&nbsp;
  <img src="public/images/github/networks/discord.png" width="44" alt="Discord" title="Discord">&nbsp;
</p>

<p align="center"><sub>Instagram &middot; TikTok &middot; LinkedIn &middot; X &middot; YouTube &middot; Facebook &middot; Threads &middot; Bluesky &middot; Pinterest &middot; Mastodon &middot; Google Business &middot; Telegram &middot; Discord</sub></p>

## Everything else you get

- **Repurpose**: videos you post outside TryPost are copied to your other networks automatically.
- **Media sources**: upload from your device, Unsplash, Google Drive, Google Photos and Canva.
- **Signatures and labels**: reusable hashtag and CTA blocks, color-coded labels to filter the calendar.
- **Notes**: leave feedback on a post and the rest of the team gets an email.
- **Webhooks**: get notified when a post is created, scheduled, published, fails or is deleted.
- **16 languages**: English, Portuguese, Spanish, French, German, Italian, Dutch, Polish, Greek, Ukrainian, Russian, Turkish, Arabic, Japanese, Korean and Chinese.

## Open source, with every feature

TryPost is open source under AGPL-3.0. You can self-host it on your own servers and keep your posts, drafts and metrics on your infrastructure. The self-hosted version has every feature of TryPost Cloud: no paid tiers, no locked features.

If you would rather not run servers, [TryPost Cloud](https://trypost.it) is the same product, hosted and updated for you.

## Built by Paulo Castellano

<img src="public/images/github/paulo.png" width="96" align="left" alt="Paulo Castellano">

Hi, I'm Paulo, and I build TryPost in the open. Follow me for what is coming next to TryPost.

<a href="https://www.linkedin.com/in/paulocastellano"><img src="public/images/github/networks/linkedin.png" width="22" alt="LinkedIn"></a>&nbsp; <a href="https://www.linkedin.com/in/paulocastellano"><b>LinkedIn</b></a> &nbsp;&nbsp;&nbsp;
<a href="https://x.com/paulocastellano"><img src="public/images/github/networks/x.png" width="22" alt="X"></a>&nbsp; <a href="https://x.com/paulocastellano"><b>@paulocastellano</b></a>

<br clear="left"/>

## Community and contributing

- **Questions or ideas?** Join the [Discord](https://trypost.it/discord) or open a [Discussion](https://github.com/trypostit/trypost/discussions).
- **Found a bug?** Open an [issue](https://github.com/trypostit/trypost/issues).
- **Want to help?** Pull requests of any size are welcome.

Short on time? A ⭐ is the best way to help. It puts TryPost in front of more people.

## License

[GNU Affero General Public License v3.0](LICENSE.md). Use, modify, fork, self-host and redistribute, including commercially. If you run a modified version as a network service, make your changes available to its users (AGPL §13).
