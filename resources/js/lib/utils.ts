import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { trans } from 'laravel-vue-i18n';
import { twMerge } from 'tailwind-merge';
import { toast } from 'vue-sonner';

import { activeLocale } from '@/language';

export const cn = (...inputs: ClassValue[]) => {
    return twMerge(clsx(inputs));
};

export const toUrl = (href: NonNullable<InertiaLinkProps['href']>) => {
    return typeof href === 'string' ? href : href?.url;
};

export const toNullableText = (value: unknown): string | null =>
    typeof value === 'string' && value.trim() ? value : null;

export const formatNumber = (value: number): string => {
    return value.toLocaleString('en-US');
};

export const formatNumberCompact = (value: number): string => {
    return new Intl.NumberFormat(activeLocale.value, {
        notation: 'compact',
        compactDisplay: 'short',
        maximumFractionDigits: 1,
    }).format(value);
};

export const formatPercent = (value: number): string => {
    return new Intl.NumberFormat(activeLocale.value, {
        style: 'percent',
        maximumFractionDigits: 2,
    }).format(value / 100);
};

export const formatPercentChange = (value: number): string => {
    const compact = Math.abs(value) >= 1000;

    return new Intl.NumberFormat(activeLocale.value, {
        style: 'percent',
        signDisplay: 'exceptZero',
        notation: compact ? 'compact' : 'standard',
        maximumFractionDigits: compact ? 1 : 2,
    }).format(value / 100);
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

export const copyToClipboard = async (
    text: string,
    message?: string,
    { showSuccessToast = true }: { showSuccessToast?: boolean } = {},
): Promise<boolean> => {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        toast.error(trans('common.actions.copy_failed'));

        return false;
    }

    if (showSuccessToast) {
        toast.success(message ?? trans('common.actions.copied'));
    }

    return true;
};

/** Mirrors ContentSanitizer::stripHtml(): the text a network receives for an HTML caption. */
export const htmlToPlainText = (html: string): string => {
    const withBreaks = html
        .replace(/<p[^>]*>/gi, '')
        .replace(/<\/p>/gi, '\n')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<li[^>]*>/gi, '- ')
        .replace(/<\/li>/gi, '\n');
    const text =
        new DOMParser().parseFromString(withBreaks, 'text/html').body
            .textContent ?? '';

    return text.replace(/\n{3,}/g, '\n\n').trim();
};
