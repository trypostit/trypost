import type { AutosaveSnapshot } from '@/composables/useComposerAutosave';
import type { PerformanceMetric, SummaryMetric } from '@/types/analytics';

/**
 * What the app keeps in this browser for a user, in every workspace, by name
 * (`area.thing`, like lang keys). An item never set is absent.
 */
export interface UserStorage {
    'mcp.promptDismissed': boolean;
    'calendar.skipCustomTimeConfirm': boolean;
    'publish.tz': string;
    'publish.showSlots': boolean;
    'insights.performanceColumns': PerformanceMetric[];
    [channelColumns: `insights.columns.${string}`]: SummaryMetric[];
    'composer.instagramCollaboratorsNoticeSeen': boolean;
    'emoji.recents': string[];
}

/** What the app keeps for a user in one workspace: channel and post ids, the draft. */
export interface WorkspaceStorage {
    'composer.lastUsedAccounts': string[];
    'commandPalette.recent': string[];
    'composer.autosave': AutosaveSnapshot;
}

/**
 * The value of a user's one localStorage key, `trypost:<user id>`: `user`
 * applies in every workspace, `workspaces` holds what only makes sense in one.
 */
export interface BrowserStorage {
    version: 1;
    user: Partial<UserStorage>;
    workspaces: Record<string, Partial<WorkspaceStorage>>;
}
