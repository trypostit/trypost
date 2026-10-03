import type { Component } from 'vue';

import type { SidebarChannel } from '@/types/channel';

export type CommandPaletteLabel = { text: string } | { key: string };

export type CommandPaletteTitle =
    | CommandPaletteLabel
    | { path: [CommandPaletteLabel, CommandPaletteLabel] };

export type CommandPaletteGroup =
    | 'actions'
    | 'navigation'
    | 'channels'
    | 'connect'
    | 'settings'
    | 'insights';

export interface CommandPaletteEntry {
    id: string;
    group: CommandPaletteGroup;
    title: CommandPaletteTitle;
    subtitle?: CommandPaletteLabel;
    icon?: Component;
    channel?: SidebarChannel;
    count?: number;
    run: () => void;
}
