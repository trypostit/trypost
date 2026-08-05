/**
 * True when `value` is an absolute http(s) URL. Used to keep incomplete
 * destination links out of autosaved platform meta (Laravel rejects them).
 */
export const isValidHttpUrl = (value: string): boolean => {
    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
};
