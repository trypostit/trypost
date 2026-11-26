import { canvasToFile } from '@/lib/canvasFile';
import {
    mediaRuleFor,
    toMediaRules,
    type MediaRules,
} from '@/lib/contentTypeMediaRules';
import { filled, isNumber } from '@/lib/helpers';
import {
    fullSelection,
    resolveOutputFileName,
    resolveOutputMime,
} from '@/lib/imageCrop';
import { isGif, isImage, isVideo } from '@/lib/mediaType';
import {
    CropPreset,
    type CropPresetValue,
    UNFIXED_CROP_PRESETS,
} from '@/types/crop-preset';
import type { ImageSize, SourceRect } from '@/types/image-crop';
import type { CoverImage, CoverSource, MediaItem } from '@/types/media';
import {
    ADJUSTMENTS,
    type CropPresetOption,
    type EditorTab,
    type FilterPreset,
    type MediaEdit,
} from '@/types/media-editor';

type FixedCropPresetValue = Exclude<
    CropPresetValue,
    typeof CropPreset.Freeform | typeof CropPreset.Original
>;

/** Each filter at an intensity from 0 (no effect) to 1 (full effect). */
const FILTER_CSS: Record<FilterPreset, (intensity: number) => string> = {
    original: () => '',
    vivid: (intensity) =>
        `saturate(${1 + 0.3 * intensity}) contrast(${1 + 0.08 * intensity})`,
    mono: (intensity) => `grayscale(${intensity})`,
    sepia: (intensity) => `sepia(${intensity})`,
};

/** Instagram Reels, YouTube Shorts and Facebook Reels show a cover at 9:16 and crop anything else to its middle. */
export const COVER_CROP_PRESET: CropPresetValue = CropPreset.Portrait9x16;

/**
 * How an uploaded cover image is framed (`ContentType::coverCropPreset()`):
 * at its own ratio only when every network taking it keeps that ratio (a
 * Facebook feed video), else at 9:16.
 */
export const coverCropPresetFor = (rules: MediaRules[]): CropPresetValue => {
    const coverRules = rules.filter((rule) => rule.supportsCustomVideoCover);

    if (!filled(coverRules)) {
        return COVER_CROP_PRESET;
    }

    if (
        coverRules.every((rule) => rule.coverCropPreset === CropPreset.Original)
    ) {
        return CropPreset.Original;
    }

    return COVER_CROP_PRESET;
};

export const USER_TAGS_MAX = 20;

const RATIO_TOLERANCE = 0.0001;

/** How far a stored cover's ratio may be from its preset's and still count as framed (rounding of real image sizes). */
const COVER_RATIO_TOLERANCE = 0.01;
const MAX_OUTPUT_SIDE = 4096;

const FIXED_PRESETS: Record<
    FixedCropPresetValue,
    CropPresetOption & { ratio: number }
> = {
    [CropPreset.Portrait9x16]: {
        value: CropPreset.Portrait9x16,
        ratio: 9 / 16,
        shape: 'portrait',
    },
    [CropPreset.Portrait2x3]: {
        value: CropPreset.Portrait2x3,
        ratio: 2 / 3,
        shape: 'portrait',
    },
    [CropPreset.Portrait3x4]: {
        value: CropPreset.Portrait3x4,
        ratio: 3 / 4,
        shape: 'portrait',
    },
    [CropPreset.Portrait4x5]: {
        value: CropPreset.Portrait4x5,
        ratio: 4 / 5,
        shape: 'portrait',
    },
    [CropPreset.Square]: {
        value: CropPreset.Square,
        ratio: 1,
        shape: 'square',
    },
    [CropPreset.Landscape4x3]: {
        value: CropPreset.Landscape4x3,
        ratio: 4 / 3,
        shape: 'landscape',
    },
    [CropPreset.Landscape191x100]: {
        value: CropPreset.Landscape191x100,
        ratio: 1.91,
        shape: 'landscape',
    },
    [CropPreset.Landscape16x9]: {
        value: CropPreset.Landscape16x9,
        ratio: 16 / 9,
        shape: 'landscape',
    },
    [CropPreset.Landscape2x1]: {
        value: CropPreset.Landscape2x1,
        ratio: 2,
        shape: 'landscape',
    },
};

/**
 * Freeform and Original always lead; then the given values in the given
 * order (from the `crop_presets` prop), filtered by the bounds when given.
 */
export const cropPresetsFor = (
    values: CropPresetValue[],
    bounds: { min?: number; max?: number } = {},
): CropPresetOption[] => [
    { value: CropPreset.Freeform, ratio: null, shape: 'freeform' },
    { value: CropPreset.Original, ratio: null, shape: 'landscape' },
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

/** A video gets a Thumbnail tab when its network takes a frame or an image as its cover. */
const hasThumbnail = (rule: MediaRules): boolean => {
    if (rule.supportsVideoCover) {
        return true;
    }

    return rule.supportsCustomVideoCover;
};

/** A tab and the rule that offers it. */
type OptionalTab = {
    tab: EditorTab;
    offeredBy: (rule: MediaRules) => boolean;
};

const VIDEO_TABS: OptionalTab[] = [
    { tab: 'thumbnail', offeredBy: hasThumbnail },
    { tab: 'alt', offeredBy: (rule) => rule.supportsVideoAltText },
];

const IMAGE_TABS: OptionalTab[] = [
    { tab: 'alt', offeredBy: (rule) => rule.supportsImageAltText },
    { tab: 'tags', offeredBy: (rule) => rule.supportsUserTags },
];

/** The tabs a network of the post offers, in order. */
const offeredTabs = (tabs: OptionalTab[], rules: MediaRules[]): EditorTab[] =>
    tabs
        .filter(({ offeredBy }) => rules.some(offeredBy))
        .map(({ tab }) => tab);

/** Tabs the editor offers for an item; an empty list means no pencil. */
export const editorTabsFor = (
    item: MediaItem,
    rules: MediaRules[],
): EditorTab[] => {
    if (isVideo(item)) {
        return offeredTabs(VIDEO_TABS, rules);
    }

    if (!isImage(item)) {
        return [];
    }

    if (isGif(item)) {
        return [];
    }

    return ['edit', ...offeredTabs(IMAGE_TABS, rules)];
};

/** An untouched edit: no crop, turn, flip, filter or metadata. */
export const blankMediaEdit = (
    preset: CropPresetValue = CropPreset.Original,
): MediaEdit => ({
    preset,
    selection: null,
    quarterTurns: 0,
    flipX: false,
    flipY: false,
    straighten: 0,
    filter: 'original',
    filterIntensity: 100,
    adjustments: { brightness: 0, contrast: 0, saturation: 0, warmth: 0 },
    altText: '',
    userTags: [],
    coverOffsetMs: null,
    coverImage: null,
    coverSource: 'frame',
});

export const storedCoverImage = (
    item: MediaItem,
    coverPreset: CropPresetValue,
): CoverImage | null => {
    const cover = item.meta?.cover;

    if (!filled(cover)) {
        return null;
    }

    return {
        ...newCoverImage(cover.url, cover, null, coverPreset),
        natural: storedSize(cover),
    };
};

/**
 * A stored image's size, as the server measured it, so a cover saved at
 * another ratio is known to need a new crop before the stage draws it.
 */
const storedSize = (item: MediaItem): ImageSize | null => {
    const width = item.meta?.width;
    const height = item.meta?.height;

    if (!isNumber(width)) {
        return null;
    }

    if (!isNumber(height)) {
        return null;
    }

    return { width, height };
};

/** A cover image as the editor opens it: framed at the cover's preset (`coverCropPresetFor()`), 9:16 by default, in its middle. */
export const newCoverImage = (
    url: string,
    item: MediaItem | null,
    file: File | null,
    preset: CropPresetValue,
): CoverImage => ({
    url,
    item,
    file,
    crop: blankMediaEdit(preset),
    natural: null,
});

/**
 * Whether the user reframed the cover. Its 9:16 ratio is where it starts, so
 * only a moved or resized frame, a turn, a flip or an adjustment counts.
 */
export const hasCoverCropChanges = (crop: MediaEdit): boolean =>
    hasPixelChanges({ ...crop, preset: CropPreset.Original });

/**
 * Where the item's cover comes from. A cover sent without its source (the
 * API or MCP) is an uploaded image.
 */
export const storedCoverSource = (item: Pick<MediaItem, 'meta'>): CoverSource => {
    const source = item.meta?.cover_source;

    if (filled(source)) {
        return source;
    }

    if (filled(item.meta?.cover)) {
        return 'image';
    }

    return 'frame';
};

/** The item's edit as the editor opens it; a stored cover is framed at `coverPreset` (`coverCropPresetFor()`). */
export const createMediaEdit = (
    item: MediaItem,
    coverPreset: CropPresetValue,
): MediaEdit => ({
    ...blankMediaEdit(),
    altText: item.meta?.alt_text ?? '',
    userTags: [...(item.meta?.user_tags ?? [])],
    coverOffsetMs: item.meta?.cover_offset_ms ?? null,
    coverImage: storedCoverImage(item, coverPreset),
    coverSource: storedCoverSource(item),
});

/** The source the edit saves: an image picked but never uploaded leaves the source as it was saved. */
export const savedCoverSource = (edit: MediaEdit, item: Pick<MediaItem, 'meta'>): CoverSource => {
    if (filled(edit.coverImage)) {
        return edit.coverSource;
    }

    if (edit.coverSource === 'image') {
        return storedCoverSource(item);
    }

    return 'frame';
};

/**
 * Whether a cover image needs cropping to save: a new file, a reframed
 * crop, or a stored image whose ratio is not its preset's (a feed video's
 * cover reused on a reel), which the stage shows cropped.
 */
export const coverNeedsRender = (cover: CoverImage): boolean => {
    if (filled(cover.file)) {
        return true;
    }

    if (hasCoverCropChanges(cover.crop)) {
        return true;
    }

    if (!filled(cover.natural)) {
        return false;
    }

    return !fitsCropPreset(cover.crop.preset, cover.natural);
};

/** Whether an image of this size already has the preset's ratio. */
const fitsCropPreset = (preset: CropPresetValue, size: ImageSize): boolean => {
    const ratio = presetRatio(preset, size);

    if (ratio === null) {
        return true;
    }

    return Math.abs(size.width / size.height - ratio) <= COVER_RATIO_TOLERANCE;
};

/**
 * Whether an edit without a cover image drops the item's cover: picking a
 * frame does, while picking an image and not uploading one yet leaves the
 * cover as it was saved.
 */
const dropsCover = (edit: MediaEdit, item: MediaItem): boolean => {
    if (edit.coverSource === 'image') {
        return false;
    }

    return filled(item.meta?.cover);
};

/** Whether the edit picks another cover image than the item has. */
export const hasCoverImageChange = (edit: MediaEdit, item: MediaItem): boolean => {
    const stored = item.meta?.cover;

    if (!filled(edit.coverImage)) {
        return dropsCover(edit, item);
    }

    if (coverNeedsRender(edit.coverImage)) {
        return true;
    }

    return edit.coverImage.item?.id !== stored?.id;
};

/**
 * A network that takes a cover image but no frame time (YouTube) needs the
 * picked frame as an image. One that takes the frame time (Instagram, TikTok,
 * Pinterest) shows that exact frame from the time alone.
 */
const takesFrameAsImage = (rule: MediaRules): boolean => {
    if (!rule.supportsCustomVideoCover) {
        return false;
    }

    return !rule.supportsVideoCover;
};

/**
 * Whether applying the edit takes a screenshot of the video as its cover: a
 * network that takes no frame time (YouTube) gets the picked frame as an
 * image, unless the stored cover is already that frame.
 */
export const needsFrameCapture = (
    item: MediaItem,
    edit: MediaEdit,
    rules: MediaRules[],
): boolean => {
    if (!isVideo(item)) {
        return false;
    }

    if (savedCoverSource(edit, item) !== 'frame') {
        return false;
    }

    if (!rules.some(takesFrameAsImage)) {
        return false;
    }

    if (!filled(edit.coverOffsetMs)) {
        return false;
    }

    if (!filled(edit.coverImage) && edit.coverSource === 'frame') {
        return true;
    }

    return edit.coverOffsetMs !== item.meta?.cover_offset_ms;
};

export const hasPixelChanges = (edit: MediaEdit): boolean =>
    edit.selection !== null ||
    !UNFIXED_CROP_PRESETS.has(edit.preset) ||
    edit.quarterTurns % 4 !== 0 ||
    edit.flipX ||
    edit.flipY ||
    edit.straighten !== 0 ||
    (edit.filter !== 'original' && edit.filterIntensity > 0) ||
    ADJUSTMENTS.some((key) => edit.adjustments[key] !== 0);

export const hasMetaChanges = (edit: MediaEdit, item: MediaItem): boolean => {
    if (edit.altText.trim() !== (item.meta?.alt_text ?? '').trim()) {
        return true;
    }

    if (JSON.stringify(edit.userTags) !== JSON.stringify(item.meta?.user_tags ?? [])) {
        return true;
    }

    if ((edit.coverOffsetMs ?? 0) !== (item.meta?.cover_offset_ms ?? 0)) {
        return true;
    }

    if (savedCoverSource(edit, item) !== storedCoverSource(item)) {
        return true;
    }

    return hasCoverImageChange(edit, item);
};

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
    if (preset === CropPreset.Freeform) {
        return null;
    }

    if (preset === CropPreset.Original) {
        return size.width / size.height;
    }

    return FIXED_PRESETS[preset].ratio;
};

export const cssFilter = (edit: MediaEdit): string =>
    [
        FILTER_CSS[edit.filter](edit.filterIntensity / 100),
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

    return canvasToFile(
        canvas,
        resolveOutputFileName(output.fileName, mime),
        mime,
        0.92,
    ).then((file) => ({ file, width, height }));
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
