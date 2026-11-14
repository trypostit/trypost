/**
 * White text keeps a contrast of at least 3:1 (WCAG's minimum for short, bold
 * text) on a color up to this relative luminance: (1 + 0.05) / (0.3 + 0.05).
 */
const WHITE_TEXT_UP_TO_LUMINANCE = 0.3;

const DARK_TEXT = '#0a0a0a';
const LIGHT_TEXT = '#ffffff';

/** One sRGB channel (0–255) as linear light, as WCAG measures it. */
const linear = (channel: number): number => {
    const value = channel / 255;

    if (value <= 0.04045) {
        return value / 12.92;
    }

    return ((value + 0.055) / 1.055) ** 2.4;
};

/**
 * The text color of a label's chip on its color (`#RRGGBB`): white whenever it
 * still reads (green, blue, red, purple), near black only on a light color
 * (light gray, yellow) where white would fade.
 */
export const labelTextColor = (hex: string): string => {
    const [red, green, blue] = [1, 3, 5].map((start) =>
        linear(Number.parseInt(hex.slice(start, start + 2), 16)),
    );
    const luminance = 0.2126 * red + 0.7152 * green + 0.0722 * blue;

    if (luminance <= WHITE_TEXT_UP_TO_LUMINANCE) {
        return LIGHT_TEXT;
    }

    return DARK_TEXT;
};
