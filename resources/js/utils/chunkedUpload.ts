import { classifyBy, MediaType } from '@/lib/mediaType';
import { probeVideoDuration } from '@/lib/videoDuration';

interface ChunkedUploadOptions {
    file: File;
    url: string;
    model?: string;
    modelId?: string;
    collection?: string;
    chunkSize?: number;
    signal?: AbortSignal;
    onProgress?: (progress: number) => void;
    onComplete?: (response: any) => void;
    onError?: (error: any) => void;
}

interface ChunkedUploadResult {
    id: string;
    path?: string;
    url: string;
    type: MediaType;
    mime_type?: string;
    original_filename: string;
    [key: string]: any;
}

/** The server answered a chunk with an error status; `message` is its first validation message, when it sent one. */
export class ChunkedUploadError extends Error {
    constructor(
        public readonly status: number,
        public readonly serverMessage: string | null,
    ) {
        super(serverMessage ?? `Upload chunk failed with status ${status}`);
        this.name = 'ChunkedUploadError';
    }
}

const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024; // 5MB chunks

const firstServerMessage = (body: string): string | null => {
    try {
        const data = JSON.parse(body) as {
            message?: unknown;
            errors?: Record<string, unknown>;
        };
        const fieldMessages = Object.values(data.errors ?? {}).flat();
        const first = fieldMessages.find(
            (message): message is string => typeof message === 'string',
        );

        return first ?? (typeof data.message === 'string' ? data.message : null);
    } catch {
        return null;
    }
};

const sendChunk = (
    url: string,
    headers: Record<string, string>,
    chunk: Blob,
    signal: AbortSignal | undefined,
    onChunkProgress: (loaded: number) => void,
): Promise<any> =>
    new Promise((resolve, reject) => {
        if (signal?.aborted) {
            reject(new DOMException('Aborted', 'AbortError'));
            return;
        }

        const xhr = new XMLHttpRequest();
        const onAbort = (): void => {
            xhr.abort();
            reject(new DOMException('Aborted', 'AbortError'));
        };

        xhr.open('POST', url);
        Object.entries(headers).forEach(([name, value]) =>
            xhr.setRequestHeader(name, value),
        );
        xhr.upload.addEventListener('progress', (event) =>
            onChunkProgress(event.loaded),
        );
        xhr.addEventListener('load', () => {
            signal?.removeEventListener('abort', onAbort);
            if (xhr.status < 200 || xhr.status >= 300) {
                reject(
                    new ChunkedUploadError(
                        xhr.status,
                        firstServerMessage(xhr.responseText),
                    ),
                );
                return;
            }
            try {
                resolve(JSON.parse(xhr.responseText));
            } catch {
                reject(new ChunkedUploadError(xhr.status, null));
            }
        });
        xhr.addEventListener('error', () => {
            signal?.removeEventListener('abort', onAbort);
            reject(new TypeError('Network request failed'));
        });
        signal?.addEventListener('abort', onAbort, { once: true });
        xhr.send(chunk);
    });

export const uploadChunked = async (options: ChunkedUploadOptions): Promise<ChunkedUploadResult> => {
    const {
        file,
        url,
        model,
        modelId,
        collection = 'default',
        chunkSize = DEFAULT_CHUNK_SIZE,
        signal,
        onProgress,
        onComplete,
        onError,
    } = options;

    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const totalSize = file.size;
    const totalChunks = Math.ceil(totalSize / chunkSize);
    const uploadId = crypto.randomUUID();

    // The server reads the duration from the file; the browser value is the fallback for containers without one.
    const isVideo = classifyBy(file.type, file.name) === MediaType.Video;
    const duration = isVideo ? await probeVideoDuration(file) : null;

    try {
        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            const start = chunkIndex * chunkSize;
            const end = Math.min(start + chunkSize, totalSize);
            const chunk = file.slice(start, end);

            const headers: Record<string, string> = {
                'Content-Type': 'application/octet-stream',
                'Content-Range': `bytes ${start}-${end - 1}/${totalSize}`,
                'X-File-Name': encodeURIComponent(file.name),
                'X-Upload-Id': uploadId,
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            };

            if (model) headers['X-Model'] = model;
            if (modelId) headers['X-Model-Id'] = modelId;
            if (collection) headers['X-Collection'] = collection;
            if (duration !== null) headers['X-Media-Duration'] = duration.toFixed(2);

            const data = await sendChunk(url, headers, chunk, signal, (loaded) =>
                onProgress?.(Math.min(99, Math.round(((start + loaded) / totalSize) * 100))),
            );

            onProgress?.(Math.round((end / totalSize) * 100));

            if (data.done) {
                onComplete?.(data);
                return data;
            }
        }

        throw new Error('Upload did not complete');
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') throw error;
        onError?.(error);
        throw error;
    }
};
