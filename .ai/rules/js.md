---
paths:
  - 'resources/js/**'
---

# Js

## Never resolve translations in script-level computed/const
laravel-vue-i18n loads the locale JSON asynchronously and `trans()` is not reactive. A `trans()` call in `<script setup>` (a const, or a computed whose deps don't change) can run before the locale loads and cache the raw key forever (seen: channel settings "Add a new posting time" select showed `channels.settings_page.targets.every_day`). Keep the key in data and resolve with `$t(key)` in the template. Reka `<SelectValue>` also caches the item text, so pass the label explicitly in its slot via `$t`.
