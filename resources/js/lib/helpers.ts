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

/**
 * Laravel's `isEmpty()` on a string (`Str::of()`) or a list (`collect()`): no
 * characters or no items. Unlike `blank()`, a string of spaces is not empty.
 */
export const isEmpty = (value: string | unknown[]): boolean =>
    value.length === 0;

/** Whether a value is a string, like PHP's `is_string()`. */
export const isString = (value: unknown): value is string =>
    typeof value === 'string';

/** Whether a value is a number, like PHP's `is_int()` or `is_float()`; NaN is not. */
export const isNumber = (value: unknown): value is number =>
    typeof value === 'number' && !Number.isNaN(value);

/**
 * Laravel's `transform()`: the callback's result when the value is filled,
 * else the fallback (null by default).
 */
export const transform = <T, R, F = null>(
    value: T,
    callback: (value: NonNullable<T>) => R,
    fallback: F = null as F,
): R | F => (filled(value) ? callback(value) : fallback);

/** Laravel's `Number::clamp()`: the value kept between `min` and `max`. */
export const clamp = (value: number, min: number, max: number): number =>
    Math.min(Math.max(value, min), max);

/** Laravel's `Str::isUuid()` (no version given): a UUID of any version, in either case. */
export const isUuid = (value: unknown): value is string =>
    isString(value) &&
    /^[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}$/.test(
        value,
    );
