Connect my TryPost account to you through TryPost's MCP server so you can create, schedule and analyze my social media posts.

- MCP server URL: {{ $mcpUrl }}
- Name: TryPost
- Transport: Streamable HTTP
- Authentication: OAuth. A browser window opens and I log in to TryPost and pick a workspace. Do not ask me for an API key.

Do this:
1. If you can add MCP servers yourself (for example with a command such as `claude mcp add --transport http trypost {{ $mcpUrl }}`, or your app's connector settings), add it now and tell me when the TryPost login opens.
2. If you cannot add it yourself, guide me step by step to add a custom connector or MCP server with the URL above in the app I am using right now, and wait while I log in.
3. Once connected, list my TryPost social accounts to confirm it works.

Talk to me in {{ $language }}.
