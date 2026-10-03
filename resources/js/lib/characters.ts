/** Characters as the server counts them (`mb_strlen`): one per code point, so an emoji is one. */
export const characterCount = (text: string): number => Array.from(text).length;

/** Mirrors Laravel `Str::trim()`: whitespace plus `Str::INVISIBLE_CHARACTERS` count as nothing. */
const INVISIBLE =
    '\\s\\0\\u{0085}\\u{00A0}\\u{00AD}\\u{034F}\\u{061C}\\u{115F}\\u{1160}\\u{17B4}\\u{17B5}\\u{180E}\\u{2000}-\\u{200F}\\u{202F}\\u{205F}\\u{2060}-\\u{2065}\\u{206A}-\\u{206F}\\u{3000}\\u{2800}\\u{3164}\\u{FEFF}\\u{FFA0}\\u{1D159}\\u{1D173}-\\u{1D17A}\\u{E0020}';
const BLANK = new RegExp(`^[${INVISIBLE}]*$`, 'u');

export const isBlankText = (text: string): boolean => BLANK.test(text);
