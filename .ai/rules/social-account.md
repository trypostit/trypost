---
paths:
  - app/Enums/SocialAccount/Platform.php
---

# Social Account

## Adding a platform: grep for exhaustive Platform matches beyond the known touch-point list
Adding a new Platform enum case breaks any `match ($platform) { ... }` elsewhere in the codebase that enumerates every case with no `default` arm — these throw UnhandledMatchError only at runtime/test time, not statically. Known example found the hard way: `app/Services/Media/MediaOptimizer.php`'s per-platform image optimization settings match, which isn't part of the "usual" platform touch-point list (Platform enum, ContentType enum, config, PostPlatformMetaRules, publisher, controller, frontend registry). Before considering a new platform done, run the full test suite (`php artisan test --compact --parallel`) — a missing arm surfaces as a clean, unambiguous UnhandledMatchError failure, not a silent bug.
