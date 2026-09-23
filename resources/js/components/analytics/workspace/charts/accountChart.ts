import type { ChartConfig } from '@/components/ui/chart';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { formatNumberCompact } from '@/lib/utils';

import { accountColor, type AccountIdentityData } from '../types';

export const accountChartConfig = (
    accounts: AccountIdentityData[],
    colors: Record<string, string>,
): ChartConfig =>
    Object.fromEntries(
        accounts.map((account, index) => [
            `account_${index}`,
            {
                label: account.username
                    ? `@${account.username}`
                    : account.name || account.platform,
                color:
                    colors[account.social_account_key] ?? accountColor(index),
                icon: getPlatformLogo(account.platform),
            },
        ]),
    );

export const formatCountTick = (tick: number | Date): string =>
    typeof tick === 'number' ? formatNumberCompact(tick) : '';
