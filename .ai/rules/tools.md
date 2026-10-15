---
paths:
  - 'app/Mcp/Tools/**'
---

# Tools

## MCP tools declare their own rules
An MCP tool declares its validation rules inline, like every FormRequest (see AGENTS.md "Backend Validation": requests are independent). Do not call a shared rules helper; the classes under app/Support/Requests/** were created in the same PR, before this rule; the owner will refactor them later, so no new code may use or extend them. Business rules live in Actions/policies so web, API and MCP enforce them identically, and the parity tests in tests/Feature/Parity prove the three surfaces agree.

## Every MCP tool declares readOnlyHint, destructiveHint and openWorldHint explicitly
The OpenAI plugin directory refuses a tool unless all three annotations are explicit booleans, and the Claude directory needs readOnlyHint or destructiveHint, so every tool carries #[IsReadOnly(bool)], #[IsDestructive(bool)] and #[IsOpenWorld(bool)], false included (follow the MCP spec: destructive = changes or removes existing data or sends something irreversible, false for purely additive writes; openWorld = can publish to public networks, fetch arbitrary URLs, call external endpoints or read public catalogs, false for the private workspace). A read-only tool is never destructive. Add each new tool to mcpExpectedToolAnnotations() in tests/Feature/Mcp/ToolAnnotationsTest.php; the test fails when a registered tool is missing from that list.
