---
paths:
  - 'app/Http/Middleware/Mcp/**'
  - 'app/Support/Mcp/**'
  - 'app/Mcp/Servers/**'
  - 'app/Enums/Mcp/**'
---

# Mcp

## Compare MCP JSON-RPC methods with App\Enums\Mcp\Method
Never compare an MCP method as a string literal ('tools/call', 'tools/list', 'initialize', ...). Use `App\Enums\Mcp\Method`, read from the request with `$request->enum('method', Method::class)` (`LoadMcpWorkspace`). laravel/mcp keeps its method names in the protected `Server::$methods` array, so there is no package constant. A method the app starts reading gets a case in the enum. `ChallengeChatGptToolCalls`, `ChatGptMcpClient` and `TryPostServer` still use strings; moving them is issue #438.
