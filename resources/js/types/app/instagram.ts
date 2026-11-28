/**
 * An Instagram audio track: what the audio search and details return, and
 * what a reel stores in `meta.audio` once picked (with the two volumes).
 */
export interface InstagramAudio {
    id: string;
    title: string;
    artist: string | null;
    cover_url: string | null;
    duration_ms?: number | null;
    preview_url: string | null;
    audio_volume?: number | null;
    video_volume?: number | null;
}
