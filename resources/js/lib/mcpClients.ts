export type PrimaryMcpClientId = 'claude' | 'chatgpt';

export interface McpClient {
    id: PrimaryMcpClientId;
    label: string;
    logo: string;
    settingsUrl: string;
    theme: { bg: string; rotate: string };
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
        theme: { bg: 'bg-orange-100', rotate: '-rotate-2' },
    },
    {
        id: 'chatgpt',
        label: 'ChatGPT',
        logo: '/images/ai/chatgpt-white.svg',
        settingsUrl: 'https://chatgpt.com/plugins#settings/Connectors?create-connector=true&redirectAfter=%2Fplugins',
        theme: { bg: 'bg-black', rotate: 'rotate-1' },
    },
];
