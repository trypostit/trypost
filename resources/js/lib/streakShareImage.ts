/**
 * The image a posting streak shares: the top of the streak popover drawn large
 * (the violet band with its light blob, darker wedge and confetti, the number's
 * circle and the flame's), with the streak and TryPost's signature under it.
 * Drawn on a canvas like the Insights share cards.
 */

import { IconFlameFilled } from '@tabler/icons-vue';

import { canvasToFile } from '@/lib/canvasFile';
import { filled } from '@/lib/helpers';
import { paintLogo } from '@/lib/shareCard/frame';
import { iconPaths } from '@/lib/shareCard/icons';
import { loadFonts } from '@/lib/shareCard/images';
import { writeText } from '@/lib/shareCard/text';
import { heading, sans } from '@/lib/shareCard/theme';

/** A square, which every network shows whole. */
const SIZE = 1080;

/** The popover's band is 228px wide; the image draws it this many times larger. */
const SCALE = 1000 / 228;

/** The popover's colors, as Tailwind draws them. */
const COLORS = {
    surface: '#ffffff',
    band: '#ede9fe',
    blob: '#f5f3ff',
    wedge: '#c4b5fd',
    circle: '#ddd6fe',
    flameCircle: '#ffedd5',
    flame: '#f97316',
    ink: '#292928',
    muted: '#6b6966',
    dots: { light: '#a78bfa', white: '#ffffff', dark: '#2e1065' },
};

/** The band, and everything on it, in the popover's pixels. */
const BAND = { x: 40, y: 40, width: 228, height: 66, radius: 8 };

/** The confetti on the band, in the popover's pixels: left, top and size of each dot. */
const DOTS: { x: number; y: number; size: number; color: string }[] = [
    { x: 12, y: 10, size: 4, color: COLORS.dots.light },
    { x: 36, y: 36, size: 6, color: COLORS.dots.white },
    { x: 144, y: 8, size: 4, color: COLORS.dots.dark },
    { x: 192, y: 44, size: 4, color: COLORS.dots.white },
    { x: 210, y: 16, size: 6, color: COLORS.dots.dark },
];

const px = (value: number): number => value * SCALE;

const bandBox = () => ({
    x: BAND.x,
    y: BAND.y,
    width: px(BAND.width),
    height: px(BAND.height),
});

/** The band: a light blob on the left, a darker wedge on the right that reaches the flame, and the confetti. */
const paintBand = (context: CanvasRenderingContext2D): void => {
    const band = bandBox();

    context.save();
    context.beginPath();
    context.roundRect(band.x, band.y, band.width, band.height, px(BAND.radius));
    context.clip();

    context.fillStyle = COLORS.band;
    context.fillRect(band.x, band.y, band.width, band.height);

    context.beginPath();
    context.ellipse(
        band.x + px(32),
        band.y + px(12),
        px(48),
        px(24),
        0,
        0,
        Math.PI * 2,
    );
    context.fillStyle = COLORS.blob;
    context.fill();

    context.beginPath();
    context.moveTo(band.x + band.width * 0.6, band.y);
    context.lineTo(band.x + band.width, band.y);
    context.lineTo(band.x + band.width, band.y + band.height);
    context.lineTo(band.x + band.width * 0.57, band.y + band.height);
    context.closePath();
    context.fillStyle = COLORS.wedge;
    context.fill();

    DOTS.forEach((dot) => {
        context.beginPath();
        context.arc(
            band.x + px(dot.x + dot.size / 2),
            band.y + px(dot.y + dot.size / 2),
            px(dot.size / 2),
            0,
            Math.PI * 2,
        );
        context.fillStyle = dot.color;
        context.fill();
    });

    context.restore();
};

/** Three digits shrink so the number stays inside its circle, as on the popover. */
const numberSize = (weeks: number): number => {
    if (weeks >= 100) {
        return px(30);
    }

    return px(40);
};

/** The number's circle over the band's edge, and the flame's circle on its corner. */
const paintCircles = (
    context: CanvasRenderingContext2D,
    weeks: number,
    number: string,
): void => {
    const circle = { x: BAND.x + px(69), y: BAND.y + px(16), size: px(80) };
    const flame = { x: BAND.x + px(119), y: BAND.y + px(60), size: px(40) };

    context.beginPath();
    context.arc(
        circle.x + circle.size / 2,
        circle.y + circle.size / 2,
        circle.size / 2,
        0,
        Math.PI * 2,
    );
    context.fillStyle = COLORS.circle;
    context.fill();

    writeText(
        context,
        number,
        { x: circle.x + circle.size / 2, y: circle.y + circle.size / 2 },
        {
            font: heading(400, numberSize(weeks)),
            color: COLORS.ink,
            align: 'center',
            baseline: 'middle',
        },
    );

    context.beginPath();
    context.arc(
        flame.x + flame.size / 2,
        flame.y + flame.size / 2,
        flame.size / 2 + px(2),
        0,
        Math.PI * 2,
    );
    context.fillStyle = COLORS.surface;
    context.fill();

    context.beginPath();
    context.arc(
        flame.x + flame.size / 2,
        flame.y + flame.size / 2,
        flame.size / 2,
        0,
        Math.PI * 2,
    );
    context.fillStyle = COLORS.flameCircle;
    context.fill();

    const icon = px(24);

    context.save();
    context.translate(
        flame.x + (flame.size - icon) / 2,
        flame.y + (flame.size - icon) / 2,
    );
    context.scale(icon / 24, icon / 24);
    context.fillStyle = COLORS.flame;
    iconPaths(IconFlameFilled).forEach((path) =>
        context.fill(new Path2D(path)),
    );
    context.restore();
};

/** The streak and the line under it, centred below the circles. */
const paintTexts = (
    context: CanvasRenderingContext2D,
    title: string,
    tagline: string,
): void => {
    writeText(
        context,
        title,
        { x: SIZE / 2, y: 640 },
        {
            font: heading(500, 76),
            color: COLORS.ink,
            align: 'center',
        },
    );
    writeText(
        context,
        tagline,
        { x: SIZE / 2, y: 720 },
        {
            font: sans(400, 44),
            color: COLORS.muted,
            align: 'center',
        },
    );
};

const paintSignature = (context: CanvasRenderingContext2D): void => {
    const logo = 56;
    const font = heading(600, 44);

    context.font = font;

    const width = logo + 16 + context.measureText('TryPost').width;
    const left = (SIZE - width) / 2;
    const centre = SIZE - 110;

    paintLogo(context, { x: left, y: centre - logo / 2 }, logo);
    writeText(
        context,
        'TryPost',
        { x: left + logo + 16, y: centre + 2 },
        {
            font,
            color: COLORS.ink,
            baseline: 'middle',
        },
    );
};

/** The streak image as a PNG file, named after the streak: `trypost-streak-20-weeks.png`. */
export const streakShareFile = async (
    weeks: number,
    texts: { number: string; title: string; tagline: string },
): Promise<File> => {
    await loadFonts(`${texts.number} ${texts.title} ${texts.tagline} TryPost`);

    const canvas = document.createElement('canvas');

    canvas.width = SIZE;
    canvas.height = SIZE;

    const context = canvas.getContext('2d');

    if (!filled(context)) {
        throw new Error('Canvas is not available');
    }

    context.fillStyle = COLORS.surface;
    context.fillRect(0, 0, SIZE, SIZE);
    paintBand(context);
    paintCircles(context, weeks, texts.number);
    paintTexts(context, texts.title, texts.tagline);
    paintSignature(context);

    return canvasToFile(
        canvas,
        `trypost-streak-${weeks}-weeks.png`,
        'image/png',
    );
};
