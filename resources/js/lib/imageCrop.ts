export type SourceRect = {
    sx: number;
    sy: number;
    sw: number;
    sh: number;
};

export type Corner = 'nw' | 'ne' | 'sw' | 'se';

const ENCODABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const EXTENSIONS: Record<string, string> = {
    'image/jpeg': 'jpg',
    'image/png': 'png',
    'image/webp': 'webp',
};

export const resolveOutputMime = (mimeType: string): string =>
    ENCODABLE_MIMES.includes(mimeType) ? mimeType : 'image/png';

export const resolveOutputFileName = (fileName: string, mime: string): string =>
    `${fileName.replace(/\.[^./]*$/, '') || 'image'}.${EXTENSIONS[mime] ?? 'png'}`;

export const containScale = (
    naturalWidth: number,
    naturalHeight: number,
    viewport: number,
    viewportHeight = viewport,
): number => {
    if (naturalWidth <= 0 || naturalHeight <= 0) {
        return 1;
    }

    return Math.min(viewport / naturalWidth, viewportHeight / naturalHeight);
};

export const fullSelection = (
    naturalWidth: number,
    naturalHeight: number,
    aspectRatio: number | null,
): SourceRect => {
    if (aspectRatio === null) {
        return { sx: 0, sy: 0, sw: naturalWidth, sh: naturalHeight };
    }

    const width = Math.min(naturalWidth, naturalHeight * aspectRatio);
    const height = width / aspectRatio;

    return {
        sx: (naturalWidth - width) / 2,
        sy: (naturalHeight - height) / 2,
        sw: width,
        sh: height,
    };
};

export const clampSelection = (
    selection: SourceRect,
    naturalWidth: number,
    naturalHeight: number,
    minSize: number,
    aspectRatio: number | null = 1,
): SourceRect => {
    if (aspectRatio === null) {
        const width = Math.min(Math.max(selection.sw, minSize), naturalWidth);
        const height = Math.min(Math.max(selection.sh, minSize), naturalHeight);

        return {
            sx: Math.min(Math.max(selection.sx, 0), naturalWidth - width),
            sy: Math.min(Math.max(selection.sy, 0), naturalHeight - height),
            sw: width,
            sh: height,
        };
    }

    const maxWidth = Math.min(naturalWidth, naturalHeight * aspectRatio);
    const width = Math.min(Math.max(selection.sw, minSize), maxWidth);
    const height = width / aspectRatio;
    const sx = Math.min(Math.max(selection.sx, 0), naturalWidth - width);
    const sy = Math.min(Math.max(selection.sy, 0), naturalHeight - height);

    return { sx, sy, sw: width, sh: height };
};

export const resizeSelection = (
    selection: SourceRect,
    corner: Corner,
    px: number,
    py: number,
    naturalWidth: number,
    naturalHeight: number,
    minSize: number,
    aspectRatio: number | null = 1,
): SourceRect => {
    const right = selection.sx + selection.sw;
    const bottom = selection.sy + selection.sh;

    const anchorX = corner === 'nw' || corner === 'sw' ? right : selection.sx;
    const anchorY = corner === 'nw' || corner === 'ne' ? bottom : selection.sy;
    const horizontal = corner === 'ne' || corner === 'se' ? 1 : -1;
    const vertical = corner === 'sw' || corner === 'se' ? 1 : -1;

    const width =
        aspectRatio === null
            ? Math.max(horizontal * (px - anchorX), minSize)
            : Math.max(
                  horizontal * (px - anchorX),
                  vertical * (py - anchorY) * aspectRatio,
                  minSize,
              );
    const height =
        aspectRatio === null
            ? Math.max(vertical * (py - anchorY), minSize)
            : width / aspectRatio;
    const boundedWidth = Math.min(
        width,
        horizontal === 1 ? naturalWidth - anchorX : anchorX,
    );
    const boundedHeight = Math.min(
        height,
        vertical === 1 ? naturalHeight - anchorY : anchorY,
    );
    const finalWidth =
        aspectRatio === null
            ? boundedWidth
            : Math.min(boundedWidth, boundedHeight * aspectRatio);
    const finalHeight =
        aspectRatio === null ? boundedHeight : finalWidth / aspectRatio;
    const sx = horizontal === 1 ? anchorX : anchorX - finalWidth;
    const sy = vertical === 1 ? anchorY : anchorY - finalHeight;

    return clampSelection(
        { sx, sy, sw: finalWidth, sh: finalHeight },
        naturalWidth,
        naturalHeight,
        minSize,
        aspectRatio,
    );
};
