import AntigravityGoogle from '@thesvg/vue/antigravity-google';
import CherryStudio from '@thesvg/vue/cherry-studio';
import Claude from '@thesvg/vue/claude';
import ClaudeCode from '@thesvg/vue/claude-code';
import CodexOpenai from '@thesvg/vue/codex-openai';
import Composio from '@thesvg/vue/composio';
import Cursor from '@thesvg/vue/cursor';
import GeminiCli from '@thesvg/vue/gemini-cli';
import Glama from '@thesvg/vue/glama';
import GrokXai from '@thesvg/vue/grok-xai';
import McpModelContextProtocol from '@thesvg/vue/mcp-model-context-protocol';
import N8n from '@thesvg/vue/n8n';
import NousresearchHermes from '@thesvg/vue/nousresearch-hermes';
import OpenWebui from '@thesvg/vue/open-webui';
import OpenaiChatgpt from '@thesvg/vue/openai-chatgpt';
import Opencode from '@thesvg/vue/opencode';

import type { McpAppLogo, McpClient } from '@/types/mcp';

/** TryPost's page in the ChatGPT app store. */
const CHATGPT_APP_URL =
    'https://chatgpt.com/plugins/plugin_asdk_app_6ad0fbec07f4819191139b8a00f5f129';

/** Opens Claude straight on the "Add custom connector" dialog. */
const CLAUDE_ADD_CONNECTOR_URL =
    'https://claude.ai/new?modal=add-custom-connector#customize/connectors/yours';

/** Claude: the app whose connect steps open first, before the user picks one. */
export const claudeMcpClient: McpClient = {
    id: 'claude',
    label: 'Claude',
    logo: Claude,
    settingsUrl: CLAUDE_ADD_CONNECTOR_URL,
    brandButtonClass:
        'bg-[#d97757] text-white hover:bg-[#c4643f] dark:bg-[#d97757] dark:text-white dark:hover:bg-[#c4643f]',
};

/**
 * First-class MCP clients surfaced on workspace MCP settings and the in-app
 * card that invites the user to connect an AI (`McpPromptTour`).
 * `settingsUrl` is where the user adds a connector in that client.
 */
export const mcpClients: McpClient[] = [
    claudeMcpClient,
    {
        id: 'chatgpt',
        label: 'ChatGPT',
        logo: OpenaiChatgpt,
        settingsUrl: CHATGPT_APP_URL,
        listed: true,
        brandButtonClass:
            'bg-black text-white hover:bg-black/80 dark:bg-white dark:text-black dark:hover:bg-white/85',
    },
];

/** Any other MCP-capable app: it gets the name, URL and config to paste. */
export const otherMcpClient: McpClient = {
    id: 'other',
    label: 'mcp.other_clients_title',
    labelKey: true,
    logo: McpModelContextProtocol,
};

/**
 * The logos of the MCP apps people connect, most specific name first
 * ("Claude Code" before Claude's own mark). An app without one shows the MCP mark.
 */
export const mcpAppLogos: McpAppLogo[] = [
    { id: 'claude-code', name: 'claude code', logo: ClaudeCode },
    { id: 'cursor', name: 'cursor', logo: Cursor, logoClass: '[&_path]:fill-current!' },
    { id: 'codex', name: 'codex', logo: CodexOpenai },
    { id: 'gemini', name: 'gemini', logo: GeminiCli },
    { id: 'antigravity', name: 'antigravity', logo: AntigravityGoogle },
    { id: 'grok', name: 'grok', logo: GrokXai },
    { id: 'n8n', name: 'n8n', logo: N8n, logoClass: '[&_path]:fill-current' },
    { id: 'open-webui', name: 'open webui', logo: OpenWebui },
    { id: 'opencode', name: 'opencode', logo: Opencode },
    { id: 'cherry-studio', name: 'cherry studio', logo: CherryStudio },
    { id: 'composio', name: 'composio', logo: Composio, logoClass: '[&_path]:fill-current [&_path]:stroke-current' },
    { id: 'glama', name: 'glama', logo: Glama },
    { id: 'hermes', name: 'hermes', logo: NousresearchHermes },
    ...mcpClients.map((client) => ({ id: client.id, name: client.label.toLowerCase(), logo: client.logo })),
];
