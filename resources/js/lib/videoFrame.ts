/** The video URL seeked to its chosen cover frame, so a `<video preload="metadata">` paints it as a poster. */
export const videoFrameUrl = (item: {
    url: string;
    meta?: { cover_offset_ms?: number | null };
}): string =>
    `${item.url}#t=${Math.max(0.1, (item.meta?.cover_offset_ms ?? 0) / 1000)}`;
