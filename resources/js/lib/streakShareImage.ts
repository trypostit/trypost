/**
 * The image a posting streak shares: the number of weeks over a grid of
 * squares like the posting activity card's, in TryPost's violet, signed with
 * the logo. Drawn on a canvas like the Insights share cards.
 */

import { canvasToFile } from '@/lib/canvasFile';
import { filled } from '@/lib/helpers';
import { paintLogo } from '@/lib/shareCard/frame';
import { loadFonts } from '@/lib/shareCard/images';
import { writeText } from '@/lib/shareCard/text';
import { heading, sans } from '@/lib/shareCard/theme';

/** A 4:5 portrait, the size social feeds show whole. */
const SIZE = { width: 1080, height: 1350 };

const COLORS = {
    ground: '#ede9fe',
    panel: '#ffffff',
    ink: '#292928',
    muted: '#6b6966',
    levels: ['#ddd6fe', '#c4b5fd', '#a78bfa', '#7c3aed'],
};

const GRID = { columns: 7, rows: 9, cell: 128, gap: 24, radius: 28 };

const PANEL = { width: 760, height: 720, radius: 56 };

/** A fixed pattern of levels, so the same streak always shares the same picture. */
const levelAt = (column: number, row: number): number =>
    (column * 7 + row * 13 + column * row) % 9;

const paintGrid = (context: CanvasRenderingContext2D): void => {
    const width = GRID.columns * GRID.cell + (GRID.columns - 1) * GRID.gap;
    const height = GRID.rows * GRID.cell + (GRID.rows - 1) * GRID.gap;
    const left = (SIZE.width - width) / 2;
    const top = (SIZE.height - height) / 2;

    for (let column = 0; column < GRID.columns; column++) {
        for (let row = 0; row < GRID.rows; row++) {
            const level = levelAt(column, row);

            if (level >= COLORS.levels.length) {
                continue;
            }

            context.beginPath();
            context.roundRect(
                left + column * (GRID.cell + GRID.gap),
                top + row * (GRID.cell + GRID.gap),
                GRID.cell,
                GRID.cell,
                GRID.radius,
            );
            context.fillStyle = COLORS.levels[level];
            context.fill();
        }
    }
};

const paintPanel = (
    context: CanvasRenderingContext2D,
    weeks: string,
    unit: string,
    tagline: string,
): void => {
    const left = (SIZE.width - PANEL.width) / 2;
    const top = (SIZE.height - PANEL.height) / 2;
    const centre = SIZE.width / 2;

    context.beginPath();
    context.roundRect(left, top, PANEL.width, PANEL.height, PANEL.radius);
    context.fillStyle = COLORS.panel;
    context.fill();

    writeText(
        context,
        weeks,
        { x: centre, y: top + 380 },
        {
            font: heading(600, 320),
            color: COLORS.ink,
            align: 'center',
        },
    );
    writeText(
        context,
        unit,
        { x: centre, y: top + 520 },
        {
            font: heading(600, 130),
            color: COLORS.ink,
            align: 'center',
        },
    );
    writeText(
        context,
        tagline,
        { x: centre, y: top + 610 },
        {
            font: sans(500, 44),
            color: COLORS.muted,
            align: 'center',
        },
    );
};

const paintSignature = (context: CanvasRenderingContext2D): void => {
    const logo = 64;
    const font = heading(600, 48);

    context.font = font;

    const width = logo + 18 + context.measureText('TryPost').width;
    const left = (SIZE.width - width) / 2;
    const centre = SIZE.height - 90;

    paintLogo(context, { x: left, y: centre - logo / 2 }, logo);
    writeText(
        context,
        'TryPost',
        { x: left + logo + 18, y: centre + 2 },
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
    texts: { weeks: string; unit: string; tagline: string },
): Promise<File> => {
    await loadFonts(`${texts.weeks} ${texts.unit} ${texts.tagline} TryPost`);

    const canvas = document.createElement('canvas');

    canvas.width = SIZE.width;
    canvas.height = SIZE.height;

    const context = canvas.getContext('2d');

    if (!filled(context)) {
        throw new Error('Canvas is not available');
    }

    context.fillStyle = COLORS.ground;
    context.fillRect(0, 0, SIZE.width, SIZE.height);
    paintGrid(context);
    paintPanel(context, texts.weeks, texts.unit, texts.tagline);
    paintSignature(context);

    return canvasToFile(
        canvas,
        `trypost-streak-${weeks}-weeks.png`,
        'image/png',
    );
};
