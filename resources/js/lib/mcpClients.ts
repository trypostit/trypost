import type { Component } from 'vue';

import ClaudeLogo from '@/components/mcp/ClaudeLogo.vue';
import OpenAiLogo from '@/components/mcp/OpenAiLogo.vue';

export type PrimaryMcpClientId = 'claude' | 'chatgpt';

export interface McpClient {
    id: PrimaryMcpClientId;
    label: string;
    /** A one-color mark in `currentColor`: it takes the text color of where it sits. */
    logo: Component;
    /** The mark's brand color on neutral surfaces; primary buttons keep their text color. */
    brandClass: string;
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
        logo: ClaudeLogo,
        brandClass: 'text-[#D97757]',
        settingsUrl: CLAUDE_ADD_CONNECTOR_URL,
    },
    {
        id: 'chatgpt',
        label: 'ChatGPT',
        logo: OpenAiLogo,
        brandClass: 'text-foreground',
        settingsUrl: 'https://chatgpt.com/plugins',
    },
];
