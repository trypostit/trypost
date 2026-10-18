---
paths:
  - routes/well-known.php
  - app/Http/Controllers/WellKnownController.php
  - routes/stateless-webhook.php
---

# Routes

## New /.well-known files go in config, never a new route or a public/ file
Every /.well-known/{name} file is one entry in config('trypost.well_known') read from its own env variable (e.g. OPENAI_APPS_CHALLENGE), served by WellKnownController as plain text; an empty value answers 404. Never hard-code a token or drop a file in public/.well-known: the repo is open source and self-hosted installs must not serve TryPost Cloud's tokens. Cover each new entry in tests/Feature/WellKnownTest.php and add the env key to .env.example.

- The route is required from routes/web.php but runs `withoutMiddleware('web')`: a verifier or crawler hit must not start a session (sessions live in the database).
- `whereIn` keeps {file} to the configured names so it never shadows another /.well-known route (laravel/mcp's oauth-*); the test that registers a later /.well-known route proves it.
- The route is only registered when the config has names: `whereIn` with an empty list throws on route compile and would break every request and `route:cache`.
- Plain-text tokens only. A JSON file (apple-app-site-association, assetlinks.json) needs its content type decided first; do not serve JSON as text/plain.

## Network webhooks are stateless routes
Meta deliveries live in routes/stateless-webhook.php, loaded by bootstrap/app.php withRouting(then:) outside the web group: no session row, no cookie and no CSRF per delivery; the controllers verify the signature. Do not move them back into routes/webhook.php (web group) or re-add CSRF exceptions for them.
