interface ChunkedUploadOptions {
    file: File;
    url: string;
    model?: string;
    modelId?: string;
    collection?: string;
    chunkSize?: number;
    onProgress?: (progress: number) => void;
    onComplete?: (response: any) => void;
    onError?: (error: any) => void;
}

interface ChunkedUploadResult {
    id: string;
    path?: string;
    url: string;
    type: string;
    mime_type?: string;
    original_filename: string;
    [key: string]: any;
}

const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024; // 5MB chunks

export const uploadChunked = async (options: ChunkedUploadOptions): Promise<ChunkedUploadResult> => {
    const {
        file,
        url,
        model,
        modelId,
        collection = 'default',
        chunkSize = DEFAULT_CHUNK_SIZE,
        onProgress,
        onComplete,
        onError,
    } = options;

    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const totalSize = file.size;
    const totalChunks = Math.ceil(totalSize / chunkSize);
    let uploadedBytes = 0;

    try {
        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            const start = chunkIndex * chunkSize;
            const end = Math.min(start + chunkSize, totalSize);
            const chunk = file.slice(start, end);

            const headers: Record<string, string> = {
                'Content-Type': 'application/octet-stream',
                'Content-Range': `bytes ${start}-${end - 1}/${totalSize}`,
                'X-File-Name': encodeURIComponent(file.name),
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            };

            if (model) headers['X-Model'] = model;
            if (modelId) headers['X-Model-Id'] = modelId;
            if (collection) headers['X-Collection'] = collection;

            const response = await fetch(url, {
                method: 'POST',
                headers,
                body: chunk,
            });

            if (!response.ok) throw new Error(`Upload chunk failed: ${response.statusText}`);

            const data = await response.json();

            uploadedBytes = end;
            const progress = Math.round((uploadedBytes / totalSize) * 100);
            onProgress?.(progress);

            if (data.done) {
                onComplete?.(data);
                return data;
            }
        }

        throw new Error('Upload did not complete');
    } catch (error) {
        onError?.(error);
        throw error;
    }
};
