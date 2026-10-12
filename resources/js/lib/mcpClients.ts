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
 * First-class MCP clients surfaced on workspace MCP settings.
 * `settingsUrl` is the client's connector-management entry point (per the
 * official OpenAI/Anthropic docs), not a deep link into a specific form.
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
