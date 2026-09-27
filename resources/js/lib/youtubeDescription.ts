export const YOUTUBE_DESCRIPTION_MAX_BYTES = 5000;

export const youtubeDescriptionBytes = (description: string): number =>
    new TextEncoder().encode(description).length;

export const normalizeYouTubeDescription = (description: unknown): string | null => {
    // Use the same blank characters as PHP trim(), preserving the original text.
    if (typeof description !== 'string' || /^[ \t\n\r\0\v]*$/.test(description)) {
        return null;
    }

    return description;
};

const isWellFormedUnicode = (text: string): boolean => {
    for (const character of text) {
        const codePoint = character.codePointAt(0)!;

        if (codePoint >= 0xd800 && codePoint <= 0xdfff) {
            return false;
        }
    }

    return true;
};

export const getYouTubeDescriptionIssue = (
    description: unknown,
): string | null => {
    if (description === null || description === undefined) {
        return null;
    }

    if (typeof description !== 'string' || !isWellFormedUnicode(description)) {
        return 'posts.form.youtube.description_invalid';
    }

    if (youtubeDescriptionBytes(description) > YOUTUBE_DESCRIPTION_MAX_BYTES) {
        return 'posts.form.youtube.description_max';
    }

    if (description.includes('<') || description.includes('>')) {
        return 'posts.form.youtube.description_invalid';
    }

    return null;
};
