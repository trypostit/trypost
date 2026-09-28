export const AspectRatio = {
    Square: '1:1',
    Portrait: '4:5',
    Landscape: '16:9',
    Original: 'original',
} as const;

export type AspectRatioValue = (typeof AspectRatio)[keyof typeof AspectRatio];
