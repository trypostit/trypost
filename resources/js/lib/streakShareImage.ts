/**
 * The image a posting streak shares: the streak popover's band art (violet,
 * a light blob, a darker wedge and white and dark confetti) as the whole
 * background, with translucent tiles over it, the number in the largest one,
 * the unit and a line under it, signed with the TryPost logo. Drawn on a
 * canvas like the Insights share cards.
 */

import type { Icon } from '@tabler/icons-vue';

import { canvasToFile } from '@/lib/canvasFile';
import { filled } from '@/lib/helpers';
import { POSTING_ACTIVITY_STAT_ICONS } from '@/lib/postingActivity';
import { lightColor } from '@/lib/shareCard/colors';
import { paintLogo } from '@/lib/shareCard/frame';
import { iconPaths } from '@/lib/shareCard/icons';
import { loadFonts } from '@/lib/shareCard/images';
import { writeText } from '@/lib/shareCard/text';
import { heading, sans } from '@/lib/shareCard/theme';
import type { ShareTileIcon } from '@/lib/shareCard/types';

/** A 4:5 portrait, the size feeds show whole. */
const SIZE = { width: 1080, height: 1350 };

/** The popover band's colors, as Tailwind draws them. */
const COLORS = {
    ground: '#ede9fe',
    blob: '#f5f3ff',
    wedge: '#c4b5fd',
    tile: 'rgba(255, 255, 255, 0.55)',
    ink: '#1f1633',
    muted: '#4c4466',
    dots: { light: '#a78bfa', white: '#ffffff', dark: '#2e1065' },
};

/** The confetti over the background: left, top and diameter of each dot. */
const DOTS: { x: number; y: number; size: number; color: string }[] = [
    { x: 60, y: 300, size: 18, color: COLORS.dots.light },
    { x: 210, y: 520, size: 26, color: COLORS.dots.white },
    { x: 650, y: 40, size: 18, color: COLORS.dots.dark },
    { x: 1000, y: 330, size: 26, color: COLORS.dots.dark },
    { x: 860, y: 760, size: 18, color: COLORS.dots.white },
    { x: 120, y: 1100, size: 22, color: COLORS.dots.dark },
    { x: 940, y: 1080, size: 22, color: COLORS.dots.white },
    { x: 300, y: 1210, size: 16, color: COLORS.dots.light },
];

/** The small tiles around the number, some with the posting activity's icons. */
const TILES: { x: number; y: number; icon?: ShareTileIcon }[] = [
    { x: 70, y: 70 },
    { x: 270, y: 70, icon: POSTING_ACTIVITY_STAT_ICONS.current_streak },
    { x: 70, y: 320, icon: POSTING_ACTIVITY_STAT_ICONS.best_streak },
    { x: 990, y: 70 },
    { x: 800, y: 320 },
    { x: 800, y: 530, icon: POSTING_ACTIVITY_STAT_ICONS.total },
    { x: -80, y: 530 },
];

const TILE = { size: 170, radius: 36, icon: 84 };

const NUMBER_TILE = { x: 320, y: 300, size: 440, radius: 64 };

const paintBackground = (context: CanvasRenderingContext2D): void => {
    context.fillStyle = COLORS.ground;
    context.fillRect(0, 0, SIZE.width, SIZE.height);

    context.beginPath();
    context.ellipse(140, 60, 420, 260, 0, 0, Math.PI * 2);
    context.fillStyle = COLORS.blob;
    context.fill();

    const { width, height } = SIZE;

    context.beginPath();
    context.moveTo(width * 0.64, 0);
    context.lineTo(width, 0);
    context.lineTo(width, height);
    context.lineTo(width * 0.56, height);
    context.bezierCurveTo(
        width * 0.44,
        height * 0.82,
        width * 0.66,
        height * 0.62,
        width * 0.58,
        height * 0.46,
    );
    context.bezierCurveTo(
        width * 0.5,
        height * 0.3,
        width * 0.72,
        height * 0.14,
        width * 0.64,
        0,
    );
    context.closePath();
    context.fillStyle = COLORS.wedge;
    context.fill();

    DOTS.forEach((dot) => {
        context.beginPath();
        context.arc(
            dot.x + dot.size / 2,
            dot.y + dot.size / 2,
            dot.size / 2,
            0,
            Math.PI * 2,
        );
        context.fillStyle = dot.color;
        context.fill();
    });
};

const paintIcon = (
    context: CanvasRenderingContext2D,
    icon: Icon,
    color: string,
    at: { x: number; y: number },
    size: number,
): void => {
    context.save();
    context.translate(at.x, at.y);
    context.scale(size / 24, size / 24);
    context.fillStyle = color;
    iconPaths(icon).forEach((path) => context.fill(new Path2D(path)));
    context.restore();
};

const paintTiles = (context: CanvasRenderingContext2D): void => {
    TILES.forEach((tile) => {
        context.beginPath();
        context.roundRect(tile.x, tile.y, TILE.size, TILE.size, TILE.radius);
        context.fillStyle = COLORS.tile;
        context.fill();

        if (!filled(tile.icon)) {
            return;
        }

        const inset = (TILE.size - TILE.icon) / 2;

        paintIcon(
            context,
            tile.icon.icon,
            lightColor(tile.icon.color),
            { x: tile.x + inset, y: tile.y + inset },
            TILE.icon,
        );
    });
};

/** Three digits shrink so the number stays inside its tile. */
const numberSize = (weeks: number): number => {
    if (weeks >= 100) {
        return 230;
    }

    return 300;
};

const paintNumber = (
    context: CanvasRenderingContext2D,
    weeks: number,
    number: string,
): void => {
    context.beginPath();
    context.roundRect(
        NUMBER_TILE.x,
        NUMBER_TILE.y,
        NUMBER_TILE.size,
        NUMBER_TILE.size,
        NUMBER_TILE.radius,
    );
    context.fillStyle = COLORS.tile;
    context.fill();

    writeText(
        context,
        number,
        {
            x: NUMBER_TILE.x + NUMBER_TILE.size / 2,
            y: NUMBER_TILE.y + NUMBER_TILE.size / 2 + 12,
        },
        {
            font: heading(600, numberSize(weeks)),
            color: COLORS.ink,
            align: 'center',
            baseline: 'middle',
        },
    );
};

const paintTexts = (
    context: CanvasRenderingContext2D,
    unit: string,
    tagline: string,
): void => {
    writeText(
        context,
        unit,
        { x: SIZE.width / 2, y: 900 },
        {
            font: heading(600, 170),
            color: COLORS.ink,
            align: 'center',
        },
    );
    writeText(
        context,
        tagline,
        { x: SIZE.width / 2, y: 990 },
        {
            font: sans(500, 52),
            color: COLORS.muted,
            align: 'center',
        },
    );
};

const paintSignature = (context: CanvasRenderingContext2D): void => {
    const logo = 60;
    const font = heading(600, 46);

    context.font = font;

    const width = logo + 16 + context.measureText('TryPost').width;
    const left = (SIZE.width - width) / 2;
    const centre = SIZE.height - 150;

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
    texts: { number: string; unit: string; tagline: string },
): Promise<File> => {
    await loadFonts(`${texts.number} ${texts.unit} ${texts.tagline} TryPost`);

    const canvas = document.createElement('canvas');

    canvas.width = SIZE.width;
    canvas.height = SIZE.height;

    const context = canvas.getContext('2d');

    if (!filled(context)) {
        throw new Error('Canvas is not available');
    }

    paintBackground(context);
    paintTiles(context);
    paintNumber(context, weeks, texts.number);
    paintTexts(context, texts.unit, texts.tagline);
    paintSignature(context);

    return canvasToFile(
        canvas,
        `trypost-streak-${weeks}-weeks.png`,
        'image/png',
    );
};
