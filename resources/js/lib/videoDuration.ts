const PROBE_TIMEOUT_MS = 5000;

/**
 * Read a video file's duration (seconds) from its container metadata without
 * uploading it. The server has no ffprobe, so this is the only place duration
 * is ever measured; it feeds `Media.meta.duration` via the chunked upload.
 * Resolves null when the browser cannot decode the container or times out.
 */
export const probeVideoDuration = (file: File): Promise<number | null> =>
    new Promise((resolve) => {
        const video = document.createElement('video');
        const url = URL.createObjectURL(file);

        const finish = (duration: number | null) => {
            clearTimeout(timer);
            video.removeAttribute('src');
            video.load();
            URL.revokeObjectURL(url);
            resolve(duration);
        };

        const timer = setTimeout(() => finish(null), PROBE_TIMEOUT_MS);

        video.preload = 'metadata';
        video.onloadedmetadata = () =>
            finish(
                Number.isFinite(video.duration) && video.duration > 0
                    ? video.duration
                    : null,
            );
        video.onerror = () => finish(null);
        video.src = url;
    });
