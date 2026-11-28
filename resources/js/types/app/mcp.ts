import type { Component } from 'vue';

/** The MCP apps TryPost shows connect steps for; `other` is any other MCP-capable app. */
export type McpClientId = 'claude' | 'chatgpt' | 'other';

export interface McpClient {
    id: McpClientId;
    /** A brand name, or a lang key to resolve with `$t` when `labelKey` is set. */
    label: string;
    labelKey?: boolean;
    /** The mark on neutral surfaces, in its brand color or the text color. */
    logo: Component;
    /** Where the user adds a connector in that client; none for a generic app. */
    settingsUrl?: string;
    /** Listed in the client's app store: the user connects from its page, nothing to paste. */
    listed?: boolean;
    /** A button filled in the brand's color, with the mark in the button's text color. */
    brandButtonClass?: string;
}

/** A connected app's logo, found by a word of the name the app registered with. */
export interface McpAppLogo {
    id: string;
    name: string;
    logo: Component;
    logoClass?: string;
}
