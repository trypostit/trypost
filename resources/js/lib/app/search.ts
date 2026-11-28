type SearchField = string | null | undefined;

/**
 * Text as a search compares it: without accents and in lowercase. Only the
 * combining marks NFD splits off are removed, so "São" becomes "sao" while a
 * typed "^" or "`" stays as it is.
 */
const normalize = (text: string): string =>
    text.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase();

/**
 * Whether every word typed appears in one of the fields, ignoring case and
 * accents: "sao paulo" finds "São Paulo", and "paulo instagram" finds Paulo's
 * Instagram account. An empty search matches everything.
 */
export const matchesSearch = (
    query: string | null | undefined,
    fields: readonly SearchField[],
): boolean => {
    const words = normalize(query ?? '').split(/\s+/).filter(Boolean);

    if (words.length === 0) return true;

    const values = fields
        .filter((field): field is string => Boolean(field))
        .map(normalize);

    return words.every((word) => values.some((value) => value.includes(word)));
};

/** The items whose fields match the search (see `matchesSearch`). */
export const filterBySearch = <T>(
    items: readonly T[],
    query: string | null | undefined,
    fieldsOf: (item: T) => readonly SearchField[],
): T[] => items.filter((item) => matchesSearch(query, fieldsOf(item)));
