---
paths:
  - routes/well-known.php
  - app/Http/Controllers/WellKnownController.php
---

# Routes

## New /.well-known files go in config, never a new route or a public/ file
Every /.well-known/{name} file is one entry in config('trypost.well_known') read from its own env variable (e.g. OPENAI_APPS_CHALLENGE), served by WellKnownController as plain text; an empty value answers 404. Never hard-code a token or drop a file in public/.well-known: the repo is open source and self-hosted installs must not serve TryPost Cloud's tokens. The route's whereIn keeps {file} to the configured names so it never shadows laravel/mcp's /.well-known/oauth-* routes. Cover each new entry in tests/Feature/WellKnownTest.php and add the env key to .env.example.
