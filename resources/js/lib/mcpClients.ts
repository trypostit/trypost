export type PrimaryMcpClientId = 'claude' | 'chatgpt';

export interface McpClient {
    id: PrimaryMcpClientId;
    label: string;
    logo: string;
    settingsUrl: string;
    chatUrl: string;
    logoClass?: string;
}

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
        settingsUrl: 'https://claude.ai/customize/connectors',
        chatUrl: 'https://claude.ai/new',
    },
    {
        id: 'chatgpt',
        label: 'ChatGPT',
        logo: '/images/ai/chatgpt-white.svg',
        settingsUrl: 'https://chatgpt.com/plugins',
        chatUrl: 'https://chatgpt.com/',
        logoClass: 'invert dark:invert-0',
    },
];
