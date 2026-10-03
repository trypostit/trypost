import {
    mediaRuleFor,
    toMediaRules,
    type MediaRules,
} from '@/lib/contentTypeMediaRules';
import {
    fullSelection,
    resolveOutputFileName,
    resolveOutputMime,
    type SourceRect,
} from '@/lib/imageCrop';
import { isGif, isImage, isVideo } from '@/lib/mediaType';
import type { MediaItem, MediaUserTag } from '@/types/media';

export type CropPresetValue =
    | 'freeform'
    | 'original'
    | '9:16'
    | '2:3'
    | '3:4'
    | '4:5'
    | '1:1'
    | '4:3'
    | '1.91:1'
    | '16:9'
    | '2:1';

type FixedCropPresetValue = Exclude<CropPresetValue, 'freeform' | 'original'>;

export type CropPreset = {
    value: CropPresetValue;
    ratio: number | null;
    shape: 'freeform' | 'portrait' | 'square' | 'landscape';
};

export const FILTER_PRESETS = ['original', 'vivid', 'mono', 'sepia'] as const;
export type FilterPreset = (typeof FILTER_PRESETS)[number];

const FILTER_CSS: Record<FilterPreset, string> = {
    original: '',
    vivid: 'saturate(1.3) contrast(1.08)',
    mono: 'grayscale(1)',
    sepia: 'sepia(1)',
};

export const ADJUSTMENTS = [
    'brightness',
    'contrast',
    'saturation',
    'warmth',
] as const;
export type Adjustment = (typeof ADJUSTMENTS)[number];

export type MediaEdit = {
    preset: CropPresetValue;
    selection: SourceRect | null;
    quarterTurns: number;
    flipX: boolean;
    flipY: boolean;
    straighten: number;
    filter: FilterPreset;
    adjustments: Record<Adjustment, number>;
    altText: string;
    userTags: MediaUserTag[];
    coverOffsetMs: number | null;
};

export const USER_TAGS_MAX = 20;

const RATIO_TOLERANCE = 0.0001;
const MAX_OUTPUT_SIDE = 4096;

const FIXED_PRESETS: Record<
    FixedCropPresetValue,
    CropPreset & { ratio: number }
> = {
    '9:16': { value: '9:16', ratio: 9 / 16, shape: 'portrait' },
    '2:3': { value: '2:3', ratio: 2 / 3, shape: 'portrait' },
    '3:4': { value: '3:4', ratio: 3 / 4, shape: 'portrait' },
    '4:5': { value: '4:5', ratio: 4 / 5, shape: 'portrait' },
    '1:1': { value: '1:1', ratio: 1, shape: 'square' },
    '4:3': { value: '4:3', ratio: 4 / 3, shape: 'landscape' },
    '1.91:1': { value: '1.91:1', ratio: 1.91, shape: 'landscape' },
    '16:9': { value: '16:9', ratio: 16 / 9, shape: 'landscape' },
    '2:1': { value: '2:1', ratio: 2, shape: 'landscape' },
};

/**
 * Freeform and Original always lead; then the given values in the given
 * order (from the `crop_presets` prop), filtered by the bounds when given.
 */
export const cropPresetsFor = (
    values: CropPresetValue[],
    bounds: { min?: number; max?: number } = {},
): CropPreset[] => [
    { value: 'freeform', ratio: null, shape: 'freeform' },
    { value: 'original', ratio: null, shape: 'landscape' },
    ...values
        .map((value) => FIXED_PRESETS[value as FixedCropPresetValue])
        .filter((preset) => preset !== undefined)
        .filter(
            (preset) =>
                (bounds.min === undefined ||
                    preset.ratio >= bounds.min - RATIO_TOLERANCE) &&
                (bounds.max === undefined ||
                    preset.ratio <= bounds.max + RATIO_TOLERANCE),
        ),
];

/** Media rules of each known content type, in order; unknown or empty types are skipped. */
export const rulesFor = (contentTypes: string[]): MediaRules[] =>
    contentTypes.flatMap((contentType) => {
        const rule = contentType ? mediaRuleFor(contentType) : undefined;

        return rule ? [toMediaRules(rule)] : [];
    });

/**
 * Spec ME16: one channel → its row; several channels in step 1 or none →
 * the no-channel row.
 */
export const presetValuesFor = (
    rules: MediaRules[],
    defaults: CropPresetValue[],
): CropPresetValue[] => (rules.length === 1 ? rules[0].cropPresets : defaults);

export type EditorTab = 'edit' | 'thumbnail' | 'alt' | 'tags';

/** Tabs the editor offers for an item; an empty list means no pencil. */
export const editorTabsFor = (
    item: MediaItem,
    rules: MediaRules[],
): EditorTab[] => {
    if (isVideo(item)) {
        return rules.some((rule) => rule.supportsVideoCover)
            ? ['thumbnail']
            : [];
    }

    if (!isImage(item) || isGif(item)) {
        return [];
    }

    return [
        'edit',
        ...(rules.some((rule) => rule.supportsAltText)
            ? (['alt'] as const)
            : []),
        ...(rules.some((rule) => rule.supportsUserTags)
            ? (['tags'] as const)
            : []),
    ];
};

/** An untouched edit: no crop, turn, flip, filter or metadata. */
export const blankMediaEdit = (
    preset: CropPresetValue = 'original',
): MediaEdit => ({
    preset,
    selection: null,
    quarterTurns: 0,
    flipX: false,
    flipY: false,
    straighten: 0,
    filter: 'original',
    adjustments: { brightness: 0, contrast: 0, saturation: 0, warmth: 0 },
    altText: '',
    userTags: [],
    coverOffsetMs: null,
});

export const createMediaEdit = (item: MediaItem): MediaEdit => ({
    ...blankMediaEdit(),
    altText: item.meta?.alt_text ?? '',
    userTags: [...(item.meta?.user_tags ?? [])],
    coverOffsetMs: item.meta?.cover_offset_ms ?? null,
});

export const hasPixelChanges = (edit: MediaEdit): boolean =>
    edit.selection !== null ||
    !['original', 'freeform'].includes(edit.preset) ||
    edit.quarterTurns % 4 !== 0 ||
    edit.flipX ||
    edit.flipY ||
    edit.straighten !== 0 ||
    edit.filter !== 'original' ||
    ADJUSTMENTS.some((key) => edit.adjustments[key] !== 0);

export const hasMetaChanges = (edit: MediaEdit, item: MediaItem): boolean =>
    edit.altText.trim() !== (item.meta?.alt_text ?? '').trim() ||
    JSON.stringify(edit.userTags) !==
        JSON.stringify(item.meta?.user_tags ?? []) ||
    (edit.coverOffsetMs ?? 0) !== (item.meta?.cover_offset_ms ?? 0);

/** A cover offset in milliseconds as `m:ss.s`. */
export const formatCoverOffset = (milliseconds: number): string => {
    const tenths = Math.round(milliseconds / 100);
    const minutes = Math.floor(tenths / 600);
    const seconds = ((tenths % 600) / 10).toFixed(1).padStart(4, '0');

    return `${minutes}:${seconds}`;
};

/**
 * Size of the image once quarter turns are applied; crop coordinates live in
 * this space.
 */
export const workingSize = (
    natural: { width: number; height: number },
    edit: MediaEdit,
): { width: number; height: number } =>
    edit.quarterTurns % 2 === 0
        ? { width: natural.width, height: natural.height }
        : { width: natural.height, height: natural.width };

/**
 * Straightening rotates the picture inside its own frame; this zoom keeps the
 * corners covered so the output never shows empty wedges.
 */
export const straightenCoverScale = (
    width: number,
    height: number,
    degrees: number,
): number => {
    const radians = (Math.abs(degrees) * Math.PI) / 180;
    const cos = Math.cos(radians);
    const sin = Math.sin(radians);

    return Math.max(cos + (height / width) * sin, cos + (width / height) * sin);
};

export const cropSelection = (
    natural: { width: number; height: number },
    edit: MediaEdit,
): SourceRect => {
    const size = workingSize(natural, edit);

    return (
        edit.selection ??
        fullSelection(size.width, size.height, presetRatio(edit.preset, size))
    );
};

export const presetRatio = (
    preset: CropPresetValue,
    size: { width: number; height: number },
): number | null => {
    if (preset === 'freeform') return null;
    if (preset === 'original') return size.width / size.height;

    return FIXED_PRESETS[preset].ratio;
};

export const cssFilter = (edit: MediaEdit): string =>
    [
        FILTER_CSS[edit.filter],
        `brightness(${1 + edit.adjustments.brightness / 100})`,
        `contrast(${1 + edit.adjustments.contrast / 100})`,
        `saturate(${1 + edit.adjustments.saturation / 100})`,
    ]
        .filter(Boolean)
        .join(' ');

export const warmthOverlay = (edit: MediaEdit): string | null => {
    const warmth = edit.adjustments.warmth;

    if (warmth === 0) return null;

    const rgb = warmth > 0 ? '245, 158, 11' : '59, 130, 246';

    return `rgba(${rgb}, ${Math.abs(warmth) / 500})`;
};

/**
 * The transform that maps the source image, centred on the origin, onto the
 * working frame. The stage applies it as CSS and the export applies it to a
 * canvas, so both always agree.
 */
export const imageTransform = (
    natural: { width: number; height: number },
    edit: MediaEdit,
): { rotation: number; scaleX: number; scaleY: number } => {
    const size = workingSize(natural, edit);
    const cover = straightenCoverScale(
        size.width,
        size.height,
        edit.straighten,
    );

    return {
        rotation: ((edit.quarterTurns * 90 + edit.straighten) * Math.PI) / 180,
        scaleX: cover * (edit.flipX ? -1 : 1),
        scaleY: cover * (edit.flipY ? -1 : 1),
    };
};

export type RenderedImage = { file: File; width: number; height: number };

/**
 * Draws the edited image onto a canvas and encodes it. The longest side is
 * capped at 4096px, or scaled to exactly `outputSide` when one is given (an
 * avatar is always 512px, even from a smaller source).
 */
export const renderImageEdit = (
    image: HTMLImageElement,
    edit: MediaEdit,
    output: { fileName: string; mimeType: string; outputSide?: number },
): Promise<RenderedImage> => {
    const natural = { width: image.naturalWidth, height: image.naturalHeight };
    const size = workingSize(natural, edit);
    const crop = cropSelection(natural, edit);
    const longestSide = Math.max(crop.sw, crop.sh);
    const scale =
        output.outputSide !== undefined
            ? output.outputSide / longestSide
            : Math.min(1, MAX_OUTPUT_SIDE / longestSide);
    const width = Math.max(1, Math.round(crop.sw * scale));
    const height = Math.max(1, Math.round(crop.sh * scale));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');

    if (!context) {
        return Promise.reject(new Error('Canvas is unavailable'));
    }

    const transform = imageTransform(natural, edit);
    context.filter = cssFilter(edit) || 'none';
    context.scale(scale, scale);
    context.translate(size.width / 2 - crop.sx, size.height / 2 - crop.sy);
    context.rotate(transform.rotation);
    context.scale(transform.scaleX, transform.scaleY);
    context.drawImage(image, -natural.width / 2, -natural.height / 2);
    context.setTransform(1, 0, 0, 1, 0, 0);

    const overlay = warmthOverlay(edit);
    if (overlay) {
        context.filter = 'none';
        context.fillStyle = overlay;
        context.fillRect(0, 0, width, height);
    }

    const mime = resolveOutputMime(output.mimeType);

    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    reject(new Error('Image could not be encoded'));

                    return;
                }

                resolve({
                    file: new File(
                        [blob],
                        resolveOutputFileName(output.fileName, mime),
                        { type: mime },
                    ),
                    width,
                    height,
                });
            },
            mime,
            0.92,
        );
    });
};

export const renderMediaEdit = (
    image: HTMLImageElement,
    item: MediaItem,
    edit: MediaEdit,
): Promise<RenderedImage> =>
    renderImageEdit(image, edit, {
        fileName: item.original_filename ?? 'image.png',
        mimeType: item.mime_type ?? 'image/png',
    });

export const normalizeUsername = (value: string): string =>
    value.trim().replace(/^@+/, '');

export const isValidInstagramUsername = (value: string): boolean =>
    /^[A-Za-z0-9._]{1,30}$/.test(normalizeUsername(value));
