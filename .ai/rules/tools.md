---
paths:
  - 'app/Mcp/Tools/**'
---

# Tools

## MCP tools declare their own rules
An MCP tool declares its validation rules inline, like every FormRequest (see AGENTS.md "Backend Validation": requests are independent). Do not call a shared rules helper; the classes under app/Support/Requests/** were created in the same PR, before this rule; the owner will refactor them later, so no new code may use or extend them. Business rules live in Actions/policies so web, API and MCP enforce them identically, and the parity tests in tests/Feature/Parity prove the three surfaces agree.

## Every MCP tool declares readOnlyHint, destructiveHint and openWorldHint explicitly
The OpenAI plugin directory refuses a tool unless all three annotations are explicit booleans, and the Claude directory needs readOnlyHint or destructiveHint, so every tool carries #[IsReadOnly(bool)], #[IsDestructive(bool)] and #[IsOpenWorld(bool)], false included (follow the MCP spec: destructive = changes or removes existing data or sends something irreversible, false for purely additive writes; openWorld = can publish to public networks, fetch arbitrary URLs, call or set arbitrary external endpoints or read public catalogs, false for the private workspace, including network APIs that only read the connected account's own data such as Pinterest boards, Discord channels or TikTok creator info). A read-only tool is never destructive. Borderline cases already decided: duplicating an idea is destructive (it shifts the positions of the ideas after it); attaching media is destructive (AppendPostMedia rewrites content_type and can send a scheduled post back to approval); a note is destructive (it emails the team); creating or updating a webhook is open world (it sets an arbitrary destination); send-webhook-test-tool is not destructive (an inert ping that writes no log), while replay-webhook-log-tool is (it re-sends a real event). A token refresh during a read, like cache warming, is housekeeping and keeps the tool read-only. Add each new tool to mcpExpectedToolAnnotations() in tests/Feature/Mcp/ToolAnnotationsTest.php; the test fails when a registered tool is missing from that list.
