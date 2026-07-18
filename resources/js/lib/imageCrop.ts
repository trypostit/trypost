export type SourceRect = {
    sx: number;
    sy: number;
    sw: number;
    sh: number;
};

export type Corner = 'nw' | 'ne' | 'sw' | 'se';

const DEFAULT_SELECTION_RATIO = 0.8;

const ENCODABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const EXTENSIONS: Record<string, string> = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' };

export const resolveOutputMime = (mimeType: string): string =>
    ENCODABLE_MIMES.includes(mimeType) ? mimeType : 'image/png';

export const resolveOutputFileName = (fileName: string, mime: string): string =>
    `${fileName.replace(/\.[^./]*$/, '') || 'image'}.${EXTENSIONS[mime] ?? 'png'}`;

export const containScale = (naturalWidth: number, naturalHeight: number, viewport: number): number => {
    if (naturalWidth <= 0 || naturalHeight <= 0) {
        return 1;
    }

    return Math.min(viewport / naturalWidth, viewport / naturalHeight);
};

export const defaultSelection = (naturalWidth: number, naturalHeight: number): SourceRect => {
    const size = Math.min(naturalWidth, naturalHeight) * DEFAULT_SELECTION_RATIO;

    return {
        sx: (naturalWidth - size) / 2,
        sy: (naturalHeight - size) / 2,
        sw: size,
        sh: size,
    };
};

export const clampSelection = (
    selection: SourceRect,
    naturalWidth: number,
    naturalHeight: number,
    minSize: number,
): SourceRect => {
    const maxSize = Math.min(naturalWidth, naturalHeight);
    const size = Math.min(Math.max(selection.sw, minSize), maxSize);
    const sx = Math.min(Math.max(selection.sx, 0), naturalWidth - size);
    const sy = Math.min(Math.max(selection.sy, 0), naturalHeight - size);

    return { sx, sy, sw: size, sh: size };
};

export const resizeSelection = (
    selection: SourceRect,
    corner: Corner,
    px: number,
    py: number,
    naturalWidth: number,
    naturalHeight: number,
    minSize: number,
): SourceRect => {
    const right = selection.sx + selection.sw;
    const bottom = selection.sy + selection.sh;

    const anchorX = corner === 'nw' || corner === 'sw' ? right : selection.sx;
    const anchorY = corner === 'nw' || corner === 'ne' ? bottom : selection.sy;
    const horizontal = corner === 'ne' || corner === 'se' ? 1 : -1;
    const vertical = corner === 'sw' || corner === 'se' ? 1 : -1;

    const size = Math.max(horizontal * (px - anchorX), vertical * (py - anchorY), minSize);
    const sx = horizontal === 1 ? anchorX : anchorX - size;
    const sy = vertical === 1 ? anchorY : anchorY - size;

    return clampSelection({ sx, sy, sw: size, sh: size }, naturalWidth, naturalHeight, minSize);
};
