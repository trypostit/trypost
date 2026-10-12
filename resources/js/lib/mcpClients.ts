export type PrimaryMcpClientId = 'claude' | 'chatgpt';

export interface McpClient {
    id: PrimaryMcpClientId;
    label: string;
    /** The mark on neutral surfaces, with `logoClass` for the theme. */
    logo: string;
    logoClass?: string;
    /** A black mark for primary buttons, inverted to white in light mode. */
    monoLogo: string;
    settingsUrl: string;
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
        logo: '/images/ai/claude-color.svg',
        monoLogo: '/images/ai/claude-mono.svg',
        settingsUrl: CLAUDE_ADD_CONNECTOR_URL,
    },
    {
        id: 'chatgpt',
        label: 'ChatGPT',
        logo: '/images/ai/openai.svg',
        logoClass: 'dark:invert',
        monoLogo: '/images/ai/openai.svg',
        settingsUrl: 'https://chatgpt.com/plugins',
    },
];
