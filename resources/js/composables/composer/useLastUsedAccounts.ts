import { computed } from 'vue';

import { useWorkspaceStorage } from '@/composables/useBrowserStorage';
import type { ComposerAccount } from '@/composables/usePostComposition';

/**
 * The channels the last post of this workspace was sent to, offered as a
 * shortcut in the composer. Kept in this browser only.
 */
export const useLastUsedAccounts = (accounts: () => ComposerAccount[]) => {
    const ids = useWorkspaceStorage('composer.lastUsedAccounts', []);

    const recentlyUsed = computed(() =>
        accounts().filter((account) => ids.value.includes(account.id)),
    );

    const remember = (accountIds: string[]): void => {
        ids.value = accountIds;
    };

    return { recentlyUsed, remember };
};
