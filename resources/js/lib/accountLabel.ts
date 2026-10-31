import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { AccountIdentityData } from '@/types/analytics';

/** How Insights names a channel: its @handle, else its name, else its network. */
export const accountLabel = (
    account: Pick<AccountIdentityData, 'username' | 'name' | 'platform'>,
): string =>
    account.username
        ? `@${account.username}`
        : account.name || getPlatformLabel(account.platform);
