import type { ChartConfig } from '@/components/ui/chart';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { accountColor } from '@/lib/analyticsColors';
import { formatNumberCompact } from '@/lib/utils';
import type { AccountIdentityData } from '@/types/analytics';

export const socialAccountChartConfig = (
    accounts: AccountIdentityData[],
    colors: Record<string, string>,
): ChartConfig =>
    Object.fromEntries(
        accounts.map((account, index) => {
            const label = account.username
                ? `@${account.username}`
                : account.name || getPlatformLabel(account.platform);

            return [
                `account_${index}`,
                {
                    label,
                    color:
                        colors[account.social_account_key] ??
                        accountColor(index),
                    avatar: {
                        platform: account.platform,
                        name: label,
                        src: account.avatar_url,
                    },
                },
            ];
        }),
    );

export const formatCountTick = (tick: number | Date): string =>
    typeof tick === 'number' ? formatNumberCompact(tick) : '';
