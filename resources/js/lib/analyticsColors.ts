const accountColors = [
    '#6D43CC',
    '#159B92',
    '#E9833D',
    '#347AB7',
    '#C64F7B',
    '#74894B',
    '#A855F7',
    '#0F766E',
    '#18181B',
    '#E11D48',
];

export const accountColor = (index: number): string =>
    accountColors[index % accountColors.length];
