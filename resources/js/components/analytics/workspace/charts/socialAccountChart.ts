import type { ChartConfig } from '@/components/ui/chart';
import { accountColor } from '@/lib/analyticsColors';
import { distinctCountLabels } from '@/lib/utils';
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

/** A count axis' tick, compact but never like its neighbours: close values (9,990 to 10,010) keep the decimals that tell them apart. */
export const formatCountTick = (tick: number | Date, index: number, ticks: number[] | Date[]): string => {
    const counts = ticks.filter((value): value is number => typeof value === 'number');

    if (typeof tick !== 'number' || counts.length !== ticks.length) {
        return '';
    }

    return distinctCountLabels(counts)[index] ?? '';
};
