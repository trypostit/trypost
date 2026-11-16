import { canvasToFile } from '@/lib/canvasFile';

/** The video URL seeked to its chosen cover frame, so a `<video preload="metadata">` paints it as a poster. */
export const videoFrameUrl = (item: {
    url: string;
    meta?: { cover_offset_ms?: number | null };
}): string =>
    `${item.url}#t=${Math.max(0.1, (item.meta?.cover_offset_ms ?? 0) / 1000)}`;

/** The longest side of a captured frame, like the media editor's output cap. */
const CAPTURE_MAX_SIDE = 3840;

/**
 * The video's frame at `milliseconds` as a JPEG file, at the video's own
 * ratio. The video is read with CORS, so its host must allow this origin:
 * otherwise the canvas cannot be exported and the promise rejects.
 */
export const captureVideoFrame = (
    url: string,
    milliseconds: number,
): Promise<File> =>
    new Promise((resolve, reject) => {
        const video = document.createElement('video');
        video.crossOrigin = 'anonymous';
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';

        video.addEventListener('error', () => reject(new Error('The video could not be loaded.')));
        video.addEventListener('loadedmetadata', () => {
            video.currentTime = Math.min(milliseconds / 1000, video.duration);
        });
        video.addEventListener('seeked', () => {
            const scale = Math.min(1, CAPTURE_MAX_SIDE / Math.max(video.videoWidth, video.videoHeight));
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
            canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
            const context = canvas.getContext('2d');

            if (!context) {
                reject(new Error('Canvas is unavailable'));

                return;
            }

            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            canvasToFile(canvas, 'cover.jpg', 'image/jpeg', 0.92).then(resolve, reject);
        }, { once: true });

        video.src = url;
    });
