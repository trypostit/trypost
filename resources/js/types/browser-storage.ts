import type { AutosaveSnapshot } from '@/composables/useComposerAutosave';
import type { PerformanceMetric, SummaryMetric } from '@/types/analytics';
import type { PlatformValue } from '@/types/platform';

/** The columns picked for each network's per-post table on the channel insights. */
type ChannelColumns = {
    [Item in `insights.columns.${PlatformValue}`]: SummaryMetric[];
};

/**
 * What the app keeps in this browser for a user, in every workspace, by name
 * (`area.thing`, like lang keys). An item never set is absent.
 */
export interface UserStorage extends ChannelColumns {
    'mcp.promptDismissed': boolean;
    'calendar.skipCustomTimeConfirm': boolean;
    'publish.tz': string;
    'publish.showSlots': boolean;
    'insights.performanceColumns': PerformanceMetric[];
    'composer.instagramCollaboratorsNoticeSeen': boolean;
    'emoji.recents': string[];
}

/** What the app keeps for a user in one workspace: channel and post ids, the draft. */
export interface WorkspaceStorage {
    'composer.lastUsedAccounts': string[];
    'commandPalette.recent': string[];
    'composer.autosave': AutosaveSnapshot;
}

/** The shape of the stored value; a value of another version reads as empty. */
export const BROWSER_STORAGE_VERSION = 1;

/**
 * The value of a user's one localStorage key, `trypost:<user id>`: `user`
 * applies in every workspace, `workspaces` holds what only makes sense in one.
 */
export interface BrowserStorage {
    version: typeof BROWSER_STORAGE_VERSION;
    user: Partial<UserStorage>;
    workspaces: Record<string, Partial<WorkspaceStorage>>;
}
