import { getPlatformLogo } from '@/composables/usePlatformLogo';

import { heading, sans } from './theme';
import type { ShareChannel, ShareImages, ShareMetricOption } from './types';

/** Canvas draws in the fallback face unless the page's fonts are loaded first. */
const loadFonts = async (): Promise<void> => {
    if (!('fonts' in document)) {
        return;
    }

    await Promise.all(
        [sans(500, 24), sans(600, 24), heading(500, 64), heading(600, 36)].map(
            (face) => document.fonts.load(face).catch(() => []),
        ),
    );
};

/** Each image once per page: the preview repaints on every tint or metric change. A failed load is forgotten, so the next card tries again. */
const loadedImages = new Map<string, Promise<HTMLImageElement | null>>();

/** An image ready to draw, or null when it cannot load, so a missing avatar never stops the card. */
const loadImage = (url: string): Promise<HTMLImageElement | null> => {
    const cached = loadedImages.get(url);

    if (cached) {
        return cached;
    }

    const image = new Image();

    image.crossOrigin = 'anonymous';
    image.src = url;

    const loading = image.decode().then(
        () => image,
        () => {
            loadedImages.delete(url);

            return null;
        },
    );

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

/** Loads the fonts and every image the options draw, each once per page. */
const loadShareImages = async (options: Pick<ShareMetricOption, 'points' | 'lines'>[]): Promise<ShareImages> => {
    const urls = [...new Set(options.flatMap(optionChannels).flatMap(channelImageUrls))];
    const [, images] = await Promise.all([
        loadFonts(),
        Promise.all(urls.map(async (url) => ({ url, image: await loadImage(url) }))),
    ]);

    return new Map(
        images
            .filter((loaded): loaded is LoadedImage => loaded.image !== null)
            .map(({ url, image }) => [url, image]),
    );
};

/**
 * The fonts and images a card draws, loaded once per page: called while the
 * page is idle, it warms them so the first preview paints at once.
 */
export const prepareShareCard = (option: Pick<ShareMetricOption, 'points' | 'lines'>): Promise<ShareImages> => loadShareImages([option]);
