import { type ChartConfig, numericTick } from '@/components/ui/chart';
import { accountLabel } from '@/lib/analyticsAccounts';
import { accountColor } from '@/lib/analyticsColors';
import { distinctCountLabels } from '@/lib/utils';
import type { AccountIdentityData } from '@/types/analytics';

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

/** A count axis' tick, compact but never like its neighbours: close values (9,990 to 10,010) keep the decimals that tell them apart. */
export const formatCountTick = numericTick(
    (_tick, index, ticks): string => distinctCountLabels(ticks)[index] ?? '',
);
