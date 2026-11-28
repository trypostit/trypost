/** The public profile page of an Instagram username. */
export const instagramProfileUrl = (username: string): string =>
    `https://www.instagram.com/${encodeURIComponent(username)}/`;
