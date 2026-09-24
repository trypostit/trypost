export type SourceRect = {
    sx: number;
    sy: number;
    sw: number;
    sh: number;
};

export type Corner = 'nw' | 'ne' | 'sw' | 'se';

const DEFAULT_SELECTION_RATIO = 0.8;

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
): number => {
    if (naturalWidth <= 0 || naturalHeight <= 0) {
        return 1;
    }

    return Math.min(viewport / naturalWidth, viewport / naturalHeight);
};

export const defaultSelection = (
    naturalWidth: number,
    naturalHeight: number,
    aspectRatio = 1,
): SourceRect => {
    const width =
        Math.min(naturalWidth, naturalHeight * aspectRatio) *
        DEFAULT_SELECTION_RATIO;
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
    aspectRatio = 1,
): SourceRect => {
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
    aspectRatio = 1,
): SourceRect => {
    const right = selection.sx + selection.sw;
    const bottom = selection.sy + selection.sh;

    const anchorX = corner === 'nw' || corner === 'sw' ? right : selection.sx;
    const anchorY = corner === 'nw' || corner === 'ne' ? bottom : selection.sy;
    const horizontal = corner === 'ne' || corner === 'se' ? 1 : -1;
    const vertical = corner === 'sw' || corner === 'se' ? 1 : -1;

    const width = Math.max(
        horizontal * (px - anchorX),
        vertical * (py - anchorY) * aspectRatio,
        minSize,
    );
    const height = width / aspectRatio;
    const sx = horizontal === 1 ? anchorX : anchorX - width;
    const sy = vertical === 1 ? anchorY : anchorY - height;

    return clampSelection(
        { sx, sy, sw: width, sh: height },
        naturalWidth,
        naturalHeight,
        minSize,
        aspectRatio,
    );
};
