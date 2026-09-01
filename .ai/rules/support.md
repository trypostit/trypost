---
paths:
  - app/Support/PostPlatformMetaRules.php
---

# Support

## Never use a cross-field Laravel rule (required_unless, required_if, etc.) for a single platform's conditional meta field
`rules()` is shared by every platform via `platforms.*.meta.*` wildcards. Rules like `required_unless`/`required_if` are Laravel "implicit" rules — they validate even when the field itself is absent from the request. Adding one scoped in spirit to a single platform (e.g. `call_to_action.url` required_unless action_type is NONE/CALL, meant only for Google Business Profile) breaks every OTHER platform's create/update through web, API, and MCP, because their requests never send that field at all and the implicit rule still fires. The correct pattern (already used by Pinterest's `board_id`, Discord's `channel_id`): keep the field's `rules()` entry unconditional (`sometimes|nullable|...`), and enforce "required" semantics only in `requiredMetaViolation()`'s `match(true)` block, which is evaluated per the resolved `Platform` of the row being checked. This bug shipped once (fixed in commit 8887b3f3) and broke Pinterest/Discord/TikTok post updates — the task reviewer that approved the original `rules()` entry didn't catch it because it only reviewed Google Business's own test coverage, not sibling platforms'.
