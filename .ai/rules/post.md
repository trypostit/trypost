---
paths:
  - app/Actions/Post/FinalizePostPublication.php
---

# Post

## In-app publish notice uses owner locale
SendNotification title/body are stored already-resolved. Resolve them through lang/*/notifications.php (post_published / post_failed) with $owner->preferredLocale() — the worker locale is English. Mailables stay untranslated at dispatch; Mail::to($owner) applies HasLocalePreference. Do not hardcode English title/body here.
