import { filled, isNumber } from '@/lib/helpers';
import { formatFullNumber, MISSING_VALUE } from '@/lib/utils';

/** The value, or a dash when there is none. */
export const orDash = (value: string | null | undefined): string => {
    if (filled(value)) {
        return value;
    }

    return MISSING_VALUE;
};

/** A stored key as people read it: `token_expired` becomes "Token expired"; none is a dash. */
export const humanize = (value: string | null | undefined): string => {
    if (filled(value)) {
        const words = value.replaceAll('_', ' ');

        return `${words.charAt(0).toUpperCase()}${words.slice(1)}`;
    }

    return MISSING_VALUE;
};

/** The parts a line has, joined by a middle dot; the missing ones leave no gap. */
export const joinFilled = (parts: (string | null | undefined)[]): string =>
    parts.filter((part) => filled(part)).join(' · ');

/** A count with the word it counts: "1 channel", "1,204 channels". */
export const countLabel = (count: number, singular: string, plural: string): string => {
    if (count === 1) {
        return `1 ${singular}`;
    }

    return `${formatFullNumber(count)} ${plural}`;
};

/** A count the server left out reads 0: a key missing from a map, a day past the end of a list. */
export const countIn = <Key extends PropertyKey>(counts: Partial<Record<Key, number>>, key: Key): number => {
    const count = counts[key];

    if (isNumber(count)) {
        return count;
    }

    return 0;
};

/** An amount in US dollars, from cents, in whole dollars: "$1,235". Cents go through `formatMoney`. */
export const formatUsd = (cents: number): string =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    }).format(cents / 100);

/** The first message of a failed request's errors, or the fallback when it sent none. */
export const firstError = (errors: Record<string, string>, fallback: string): string => {
    const [message] = Object.values(errors);

    if (filled(message)) {
        return message;
    }

    return fallback;
};
