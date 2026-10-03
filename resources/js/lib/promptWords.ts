export const PROMPT_MIN_WORDS = 4;
export const PROMPT_MAX_LENGTH = 10000;

export const countPromptWords = (text: string): number => {
    const pattern = /[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}]/gu;
    const ideographs = text.match(pattern)?.length ?? 0;
    const rest = text
        .replace(pattern, ' ')
        .trim()
        .split(/\s+/u)
        .filter(Boolean).length;

    return ideographs + rest;
};
