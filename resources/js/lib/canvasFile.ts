/**
 * A canvas encoded as an image file; rejects when the browser cannot encode it.
 * It imports nothing, so code run outside the bundler (the media editor's
 * preset parity test runs `mediaEditor.ts` in Node) can use it.
 */
export const canvasToFile = (
    canvas: HTMLCanvasElement,
    name: string,
    type: string,
    quality?: number,
): Promise<File> =>
    new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    reject(new Error('The image could not be encoded.'));

                    return;
                }

                resolve(new File([blob], name, { type }));
            },
            type,
            quality,
        );
    });
