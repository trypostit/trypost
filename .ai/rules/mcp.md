---
paths:
  - 'app/Http/Middleware/Mcp/**'
  - 'app/Support/Mcp/**'
  - 'app/Mcp/Servers/**'
  - 'app/Enums/Mcp/**'
---

# Mcp

## Compare MCP JSON-RPC methods with App\Enums\Mcp\Method
Never compare an MCP method as a string literal ('tools/call', 'tools/list', 'initialize', ...). Use `App\Enums\Mcp\Method`, comparing the raw value strictly: `$request->json('method') !== Method::ToolsCall->value` (`LoadMcpWorkspace`). Never `$request->enum('method', Method::class)`: the body is client input, and a non-scalar `method` makes `tryFrom()` throw a TypeError, which turns laravel/mcp's JSON-RPC -32600 into a 500. laravel/mcp keeps its method names in the protected `Server::$methods` array, so there is no package constant. A method the app starts reading gets a case in the enum.
