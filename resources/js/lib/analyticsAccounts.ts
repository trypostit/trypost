import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { AccountIdentityData } from '@/types/analytics';
import { channelName } from '@/types/channel';

/**
 * A channel's name in Insights by the app's one rule, `channelName`, as the
 * sidebar shows it; its network for a row that kept neither name nor handle.
 */
export const accountLabel = (
    account: Pick<AccountIdentityData, 'username' | 'name' | 'platform'>,
): string => {
    const name = channelName({ display_name: account.name, username: account.username });

    if (name) {
        return name;
    }

    return getPlatformLabel(account.platform);
};
