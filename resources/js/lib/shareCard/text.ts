import type { Point } from '@/lib/shareCard/types';

export type TextStyle = {
    font: string;
    color: string;
    /** Always a side, never `start` or `end`, so a right-to-left text keeps the card's left-to-right layout. */
    align?: 'left' | 'right' | 'center';
    baseline?: CanvasTextBaseline;
};

/** Writes one line of text in its own style, so no drawing step inherits another's. */
export const writeText = (
    context: CanvasRenderingContext2D,
    text: string,
    at: Point,
    { font, color, align = 'left', baseline = 'alphabetic' }: TextStyle,
): void => {
    context.font = font;
    context.fillStyle = color;
    context.textAlign = align;
    context.textBaseline = baseline;
    context.fillText(text, at.x, at.y);
};

/** The width of the widest of `texts` in `font`. */
export const widest = (context: CanvasRenderingContext2D, texts: string[], font: string): number => {
    context.font = font;

    return Math.max(0, ...texts.map((text) => context.measureText(text).width));
};

/** The text cut with an ellipsis to fit `limit` in `font`, one whole character (an emoji with its joiners included) at a time. */
export const ellipsise = (
    context: CanvasRenderingContext2D,
    text: string,
    font: string,
    limit: number,
): string => {
    context.font = font;

    if (context.measureText(text).width <= limit) {
        return text;
    }

    const characters = Array.from(new Intl.Segmenter().segment(text), (piece) => piece.segment);

    while (characters.length > 1 && context.measureText(`${characters.join('')}…`).width > limit) {
        characters.pop();
    }

    return `${characters.join('')}…`;
};
