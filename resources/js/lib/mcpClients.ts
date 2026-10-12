export type PrimaryMcpClientId = 'claude' | 'chatgpt';

export interface McpClient {
    id: PrimaryMcpClientId;
    label: string;
    logo: string;
    settingsUrl: string;
    logoClass?: string;
}

/** Opens Claude straight on the "Add custom connector" dialog. */
const CLAUDE_ADD_CONNECTOR_URL =
    'https://claude.ai/new?modal=add-custom-connector#customize/connectors/yours';

/**
 * First-class MCP clients surfaced on workspace MCP settings and the Welcome
 * MCP step. `settingsUrl` is where the user adds a connector in that client.
 */
export const mcpClients: McpClient[] = [
    {
        id: 'claude',
        label: 'Claude',
        logo: '/images/ai/claude.svg',
        settingsUrl: CLAUDE_ADD_CONNECTOR_URL,
    },
    {
        id: 'chatgpt',
        label: 'ChatGPT',
        logo: '/images/ai/chatgpt-white.svg',
        settingsUrl: 'https://chatgpt.com/plugins',
        logoClass: 'invert dark:invert-0',
    },
];
