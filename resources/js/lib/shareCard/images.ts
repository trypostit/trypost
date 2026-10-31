import { getPlatformLogo } from '@/composables/usePlatformLogo';

import { heading, sans } from './theme';
import type { ShareCardOptions, ShareChannel, ShareImages, ShareMetricOption } from './types';

/**
 * How long a font or an image may take before the card draws without it, and
 * how long a failed image is kept as failed before the next card asks again:
 * a stalled request never holds the preview, and a missing one is not asked
 * for again on every repaint.
 */
const LOADING = { timeoutMs: 4000, retryAfterMs: 30000 };

/** The faces the card writes in. */
const FACES = [sans(500, 24), sans(600, 24), heading(500, 64), heading(600, 36)];

/** `promise`, or `fallback` once `ms` pass without it settling. */
const withTimeout = <Value>(promise: Promise<Value>, ms: number, fallback: Value): Promise<Value> =>
    Promise.race([promise, new Promise<Value>((resolve) => window.setTimeout(() => resolve(fallback), ms))]);

/** What an option writes, so the fonts load the glyphs it needs ("ł", "ş"), not only the Latin ones. */
const optionText = (option: Partial<ShareCardOptions> & Pick<ShareMetricOption, 'points' | 'lines'>): string =>
    [
        option.subject,
        option.range,
        option.mark,
        option.label,
        option.figure,
        ...option.points.flatMap((point) => [point.label, point.figure]),
        ...option.lines.map((line) => line.figure),
        ...(option.series ?? []).flatMap((series) => [series.label, series.figure, series.previousFigure]),
        ...(option.tiles ?? []).flatMap((tile) => [tile.label, tile.figure]),
    ]
        .filter((text): text is string => Boolean(text))
        .join(' ');

/** Canvas draws in the fallback face unless the page's fonts are loaded first, with the glyphs the card writes. */
const loadFonts = async (text: string): Promise<void> => {
    if (!('fonts' in document)) {
        return;
    }

    await Promise.all(
        FACES.map((face) => withTimeout(document.fonts.load(face, text).catch(() => []), LOADING.timeoutMs, [])),
    );
};

/** Each image once per page: the preview repaints on every tint or metric change. */
const loadedImages = new Map<string, Promise<HTMLImageElement | null>>();

/** An image ready to draw, or null when it cannot load in time, so a missing or slow avatar never stops the card. */
const loadImage = (url: string): Promise<HTMLImageElement | null> => {
    const cached = loadedImages.get(url);

    if (cached) {
        return cached;
    }

    const image = new Image();

    image.crossOrigin = 'anonymous';
    image.src = url;

    const loading = withTimeout(
        image.decode().then(
            () => image,
            () => null,
        ),
        LOADING.timeoutMs,
        null,
    ).then((loaded) => {
        if (!loaded) {
            window.setTimeout(() => loadedImages.delete(url), LOADING.retryAfterMs);
        }

        return loaded;
    });

    loadedImages.set(url, loading);

    return loading;
};

/** A channel's network logo and, when it has one, its avatar. */
const channelImageUrls = (channel: ShareChannel): string[] => {
    const logo = getPlatformLogo(channel.platform);

    if (!channel.avatar) {
        return [logo];
    }

    return [channel.avatar, logo];
};

type LoadedImage = { url: string; image: HTMLImageElement };

/** The channels an option draws, as rows or as lines. */
const optionChannels = (option: Pick<ShareMetricOption, 'points' | 'lines'>): ShareChannel[] =>
    [...option.points.map((point) => point.channel), ...option.lines.map((line) => line.channel)].filter(
        (channel): channel is ShareChannel => channel !== undefined,
    );

/**
 * The fonts and images a card draws, loaded once per page: called while the
 * page is idle, it warms them so the first preview paints at once.
 */
export const prepareShareCard = async (
    option: Partial<ShareCardOptions> & Pick<ShareMetricOption, 'points' | 'lines'>,
): Promise<ShareImages> => {
    const urls = [...new Set(optionChannels(option).flatMap(channelImageUrls))];
    const [, images] = await Promise.all([
        loadFonts(optionText(option)),
        Promise.all(urls.map(async (url) => ({ url, image: await loadImage(url) }))),
    ]);

    return new Map(
        images
            .filter((loaded): loaded is LoadedImage => loaded.image !== null)
            .map(({ url, image }) => [url, image]),
    );
};
