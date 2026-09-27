export const YOUTUBE_DESCRIPTION_MAX_BYTES = 5000;

export const youtubeDescriptionBytes = (description: string): number =>
    new TextEncoder().encode(description).length;

export const getYouTubeDescriptionIssue = (
    description: unknown,
): string | null => {
    if (description === null || description === undefined) return null;
    if (typeof description !== 'string')
        return 'posts.form.youtube.description_invalid';
    for (const character of description) {
        const codePoint = character.codePointAt(0)!;
        if (codePoint >= 0xd800 && codePoint <= 0xdfff) {
            return 'posts.form.youtube.description_invalid';
        }
    }
    if (youtubeDescriptionBytes(description) > YOUTUBE_DESCRIPTION_MAX_BYTES) {
        return 'posts.form.youtube.description_max';
    }
    if (description.includes('<') || description.includes('>')) {
        return 'posts.form.youtube.description_invalid';
    }
    return null;
};
