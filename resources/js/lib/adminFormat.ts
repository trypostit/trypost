import date from '@/date';
import { filled } from '@/lib/helpers';

/** What the backoffice shows for a value it does not have. */
export const NO_VALUE = '—';

/** The value, or a dash when there is none. */
export const orDash = (value: string | null | undefined): string => {
    if (filled(value)) {
        return value;
    }

    return NO_VALUE;
};

/** A date and time, or a dash when there is none. */
export const dateTimeOrDash = (value: string | null | undefined): string => {
    if (filled(value)) {
        return date.formatDateTime(value);
    }

    return NO_VALUE;
};

/** A stored key as people read it: `token_expired` becomes "Token expired"; none is a dash. */
export const humanize = (value: string | null | undefined): string => {
    if (filled(value)) {
        const words = value.replaceAll('_', ' ');

        return `${words.charAt(0).toUpperCase()}${words.slice(1)}`;
    }

    return NO_VALUE;
};

/** The parts a line has, joined by a middle dot; the missing ones leave no gap. */
export const joinFilled = (parts: (string | null | undefined)[]): string =>
    parts.filter((part) => filled(part)).join(' · ');

/**
 * An amount in US dollars, from cents: whole dollars by default ("$1,235"),
 * or with up to `decimals` digits for a unit price ("$0.005").
 */
export const formatUsd = (cents: number, decimals: number = 0): string =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: decimals,
    }).format(cents / 100);

/** The first message of a failed request's errors, or the fallback when it sent none. */
export const firstError = (errors: Record<string, string>, fallback: string): string => {
    const [message] = Object.values(errors);

    if (filled(message)) {
        return message;
    }

    return fallback;
};
