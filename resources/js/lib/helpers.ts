/**
 * Laravel's `blank()` and `filled()` for the front end, so an emptiness check
 * reads the same on both sides. They follow PHP's rules, kept equal by
 * `HelpersParityTest`: null, undefined, a string of only whitespace and an
 * empty array or plain object are blank; numbers and booleans (zero and false
 * included) and any other object, like a Date, never are.
 */
export const blank = (value: unknown): boolean => {
    if (value === null || value === undefined) {
        return true;
    }

    if (typeof value === 'string') {
        return value.trim() === '';
    }

    if (Array.isArray(value)) {
        return value.length === 0;
    }

    if (Object.getPrototypeOf(value) === Object.prototype) {
        return Object.keys(value).length === 0;
    }

    return false;
};

/** The opposite of `blank()`; a filled value is never null or undefined. */
export const filled = <T>(value: T): value is NonNullable<T> => !blank(value);
