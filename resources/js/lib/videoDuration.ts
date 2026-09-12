const PROBE_TIMEOUT_MS = 5000;

/** Video duration in seconds from the file's metadata; null when undecodable or timed out. */
export const probeVideoDuration = async (file: File): Promise<number | null> => {
    const video = document.createElement('video');
    const url = URL.createObjectURL(file);

    const metadata = new Promise<number | null>((resolve) => {
        video.preload = 'metadata';
        video.onloadedmetadata = () => resolve(Number.isFinite(video.duration) && video.duration > 0 ? video.duration : null);
        video.onerror = () => resolve(null);
        video.src = url;
    });

    const timeout = new Promise<null>((resolve) => setTimeout(() => resolve(null), PROBE_TIMEOUT_MS));

    try {
        return await Promise.race([metadata, timeout]);
    } finally {
        video.removeAttribute('src');
        video.load();
        URL.revokeObjectURL(url);
    }
};
