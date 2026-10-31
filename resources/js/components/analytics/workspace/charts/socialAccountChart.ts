import type { ChartConfig } from '@/components/ui/chart';
import { accountColor } from '@/lib/analyticsColors';
import { formatNumberCompact } from '@/lib/utils';
import { type AccountIdentityData, accountLabel } from '@/types/analytics';

export const socialAccountChartConfig = (
    accounts: AccountIdentityData[],
    colors: Record<string, string>,
): ChartConfig =>
    Object.fromEntries(
        accounts.map((account, index) => {
            const label = accountLabel(account);

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
                        verified: account.verified_badge ?? null,
                    },
                },
            ];
        }),
    );

export const formatCountTick = (tick: number | Date): string =>
    typeof tick === 'number' ? formatNumberCompact(tick) : '';
