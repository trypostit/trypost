import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { trans } from 'laravel-vue-i18n';
import { twMerge } from 'tailwind-merge';
import { toast } from 'vue-sonner';

export const cn = (...inputs: ClassValue[]) => {
    return twMerge(clsx(inputs));
};

export const toUrl = (href: NonNullable<InertiaLinkProps['href']>) => {
    return typeof href === 'string' ? href : href?.url;
};

export const formatNumber = (value: number): string => {
    return value.toLocaleString('en-US');
};

export const formatNumberCompact = (value: number): string => {
    return new Intl.NumberFormat('en-US', {
        notation: 'compact',
        compactDisplay: 'short',
        maximumFractionDigits: 1,
    }).format(value);
};

export const formatPercentChange = (value: number): string => {
    const formatted =
        Math.abs(value) >= 1000
            ? formatNumberCompact(value)
            : new Intl.NumberFormat('en-US', {
                  maximumFractionDigits: 2,
              }).format(value);

    return `${value > 0 ? '+' : ''}${formatted}%`;
};

export const formatMoney = (cents: number): string => {
    const dollars = cents / 100;
    return dollars.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

export const formatMoneyCompact = (cents: number): string => {
    const dollars = cents / 100;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        notation: 'compact',
        compactDisplay: 'short',
        maximumFractionDigits: 1,
    }).format(dollars);
};

export const copyToClipboard = async (text: string, message?: string) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(message ?? trans('common.actions.copied'));
    } catch {
        toast.error(trans('common.actions.copy_failed'));
    }
};
