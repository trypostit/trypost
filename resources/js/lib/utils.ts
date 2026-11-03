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

/** Runs `task` once the browser is idle; Safari, without `requestIdleCallback`, runs it on the next tick. */
export const whenIdle = (task: () => void): void => {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(task);

        return;
    }

    setTimeout(task, 0);
};

export const toNullableText = (value: unknown): string | null =>
    typeof value === 'string' && value.trim() ? value : null;

export const formatNumber = (value: number): string => {
    return value.toLocaleString('en-US');
};

/** What a number reads while it has no value. */
const MISSING_VALUE = '—';

/** A number that may have no value yet; the formatters below read it as a dash. */
type MaybeNumber = number | null | undefined;

/** A number formatter that reads a missing value as a dash, so no caller checks for one. */
const withDash =
    <Args extends unknown[]>(format: (value: number, ...args: Args) => string) =>
    (value: MaybeNumber, ...args: Args): string => {
        if (value == null) {
            return MISSING_VALUE;
        }

        return format(value, ...args);
    };

export const formatNumberCompact = withDash(
    (value: number, digits: number = 1): string =>
        new Intl.NumberFormat(activeLocale.value, {
            notation: 'compact',
            compactDisplay: 'short',
            maximumFractionDigits: digits,
        }).format(value),
);

/** The most decimals a compact axis label takes before it falls back to whole numbers. */
const MAX_COMPACT_DIGITS = 2;

/**
 * Compact labels for a run of axis values, with the fewest decimals that
 * round none of them ("2.5", "9.33K"), else the exact values when two would
 * still read the same ("1,000.5" next to "1,001", never two "1,001").
 */
export const distinctCountLabels = (values: number[]): string[] => {
    const precise = values.map((value) => formatNumberCompact(value, MAX_COMPACT_DIGITS));

    if (new Set(precise).size !== precise.length) {
        return values.map((value) => formatFullNumber(value));
    }

    for (let digits = 0; digits < MAX_COMPACT_DIGITS; digits += 1) {
        const labels = values.map((value) => formatNumberCompact(value, digits));

        if (labels.every((label, index) => label === precise[index])) {
            return labels;
        }
    }

    return precise;
};

export const formatPercent = withDash(
    (value: number, digits: number = 2): string =>
        new Intl.NumberFormat(activeLocale.value, {
            style: 'percent',
            maximumFractionDigits: digits,
        }).format(value / 100),
);

/** A change in a count, signed and exact: "+1,234", "-3", "0". */
export const formatSignedNumber = (value: number): string =>
    new Intl.NumberFormat(activeLocale.value, { signDisplay: 'exceptZero' }).format(value);

/** A count in the reader's language, exact: "1,234". */
export const formatFullNumber = withDash(
    (value: number): string => value.toLocaleString(activeLocale.value),
);

/** A change in a count, signed and compact: "+1.2K", "-3", "0". */
export const formatCountChange = withDash(
    (value: number): string =>
        new Intl.NumberFormat(activeLocale.value, {
            notation: 'compact',
            compactDisplay: 'short',
            maximumFractionDigits: 1,
            signDisplay: 'exceptZero',
        }).format(value),
);

export const formatPercentChange = withDash((value: number): string => {
    const compact = Math.abs(value) >= 1000;

    return new Intl.NumberFormat(activeLocale.value, {
        style: 'percent',
        signDisplay: 'exceptZero',
        notation: compact ? 'compact' : 'standard',
        maximumFractionDigits: compact ? 1 : 2,
    }).format(value / 100);
});

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

/** How long a download's object URL lives: long enough for the browser to start saving the file. */
const DOWNLOAD_URL_LIFETIME_MS = 60_000;

/**
 * Saves a file the way a download link would. The link's URL lives a minute:
 * revoked at once, some browsers cancel the download before it starts.
 */
export const downloadFile = (file: File): void => {
    const url = URL.createObjectURL(file);
    const link = document.createElement('a');

    link.href = url;
    link.download = file.name;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), DOWNLOAD_URL_LIFETIME_MS);
};

/**
 * Copies a PNG to the clipboard and leaves the feedback to the caller; it
 * rejects where the browser cannot copy images. Call it inside the click with
 * the image still a promise: Safari refuses a write that only starts once the
 * image is ready.
 */
export const copyImageToClipboard = async (image: Promise<Blob>): Promise<void> =>
    navigator.clipboard.write([new ClipboardItem({ 'image/png': image })]);


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
