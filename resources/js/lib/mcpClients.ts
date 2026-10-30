export type McpClientId = 'claude' | 'chatgpt' | 'other';

export interface McpClient {
    id: McpClientId;
    /** A brand name, or a lang key to resolve with `$t` when `labelKey` is set. */
    label: string;
    labelKey?: boolean;
    /** The mark on neutral surfaces, with `logoClass` for the theme. */
    logo: string;
    logoClass?: string;
    /** A black mark for primary buttons, inverted to white in light mode. */
    monoLogo: string;
    /** Where the user adds a connector in that client; none for a generic app. */
    settingsUrl?: string;
    /** Listed in the client's app store: the user connects from its page, nothing to paste. */
    listed?: boolean;
}

/** TryPost's page in the ChatGPT app store. */
const CHATGPT_APP_URL =
    'https://chatgpt.com/plugins/plugin_asdk_app_6ad0fbec07f4819191139b8a00f5f129';

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
        settingsUrl: CHATGPT_APP_URL,
        listed: true,
    },
];

/** Any other MCP-capable app: it gets the name, URL and config to paste. */
export const otherMcpClient: McpClient = {
    id: 'other',
    label: 'mcp.other_clients_title',
    labelKey: true,
    logo: '/images/ai/mcp.svg',
    logoClass: 'dark:invert',
    monoLogo: '/images/ai/mcp.svg',
};
