import { characterCount, displayText } from '@/lib/characters';
import { filled } from '@/lib/helpers';

/** A `<` that opens no tag is text the person typed (`ContentSanitizer::escapeTypedBrackets()`). */
const TYPED_BRACKET = /<(?!\/?[a-z][^<>]*>)/gi;

/** Older rich-editor markup as lines, in `ContentSanitizer::blocksToLines()` order. */
const BLOCK_MARKUP: ReadonlyArray<readonly [RegExp, string]> = [
    [/<p\b[^>]*>/gi, ''],
    [/<li\b[^>]*>/gi, '- '],
    [/<br\s*\/?>|<\/(?:p|li|div|h[1-6]|blockquote)>/gi, '\n'],
];

/** Any tag left once typed brackets are escaped (`strip_tags()`). */
const TAG = /<[^>]*>/g;

/** `&#x41;`, `&#65;` or `&name;`: the references `html_entity_decode()` reads. */
const HTML_REFERENCE = /&(?:#x([0-9a-f]+)|#([0-9]+)|[a-z][a-z0-9]*);/gi;

/**
 * Code points PHP's HTML5 decoder writes out (`unicode_cp_is_allowed()`, plus
 * U+000D, which it never decodes); any other numeric reference stays as written.
 */
const DECODABLE_CODE_POINTS: ReadonlyArray<readonly [number, number]> = [
    [0x09, 0x0a],
    [0x0c, 0x0c],
    [0x20, 0x7e],
    [0xa0, 0xd7ff],
    [0xe000, 0xfdcf],
    [0xfdf0, 0x10ffff],
];

/** The last two code points of every plane are noncharacters. */
const PLANE_SIZE = 0x10000;
const FIRST_PLANE_NONCHARACTER = 0xfffe;

const isDecodable = (codePoint: number): boolean => {
    if (codePoint % PLANE_SIZE >= FIRST_PLANE_NONCHARACTER) {
        return false;
    }

    return DECODABLE_CODE_POINTS.some(
        ([first, last]) => codePoint >= first && codePoint <= last,
    );
};

const decodeNumericReference = (
    reference: string,
    codePoint: number,
): string => {
    if (!isDecodable(codePoint)) {
        return reference;
    }

    return String.fromCodePoint(codePoint);
};

/**
 * A known name decodes to one or two characters. The browser also reads the
 * known start of an unknown name (`&notit;` as `¬it;`, three or more
 * characters), which PHP leaves as written.
 */
const decodeNamedReference = (reference: string): string => {
    const decoder = document.createElement('textarea');
    decoder.innerHTML = reference;

    if (characterCount(decoder.value) > 2) {
        return reference;
    }

    return decoder.value;
};

const decodeReference = (
    reference: string,
    hex: string | undefined,
    decimal: string | undefined,
): string => {
    if (filled(hex)) {
        return decodeNumericReference(reference, parseInt(hex, 16));
    }

    if (filled(decimal)) {
        return decodeNumericReference(reference, parseInt(decimal, 10));
    }

    return decodeNamedReference(reference);
};

/**
 * The text a network receives for plain-text content, as the reader counts it:
 * `ContentSanitizer::plainText()` step by step, then `displayText()`.
 */
export const plainText = (text: string): string => {
    const lines = BLOCK_MARKUP.reduce(
        (html, [markup, line]) => html.replace(markup, line),
        text.replace(TYPED_BRACKET, '&lt;'),
    );

    return displayText(
        lines.replace(TAG, '').replace(HTML_REFERENCE, decodeReference),
    );
};
