import type { ChartConfig } from '@/components/ui/chart';
import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import { formatNumberCompact } from '@/lib/utils';

import { accountColor } from '@/lib/analyticsColors';
import type { AccountIdentityData } from '@/types/analytics';

export const socialAccountChartConfig = (
    accounts: AccountIdentityData[],
    colors: Record<string, string>,
): ChartConfig =>
    Object.fromEntries(
        accounts.map((account, index) => [
            `account_${index}`,
            {
                label: account.username
                    ? `@${account.username}`
                    : account.name || getPlatformLabel(account.platform),
                color:
                    colors[account.social_account_key] ?? accountColor(index),
                icon: getPlatformLogo(account.platform),
            },
        ]),
    );

export const formatCountTick = (tick: number | Date): string =>
    typeof tick === 'number' ? formatNumberCompact(tick) : '';
