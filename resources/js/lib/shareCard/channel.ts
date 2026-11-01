import { IconRosetteDiscountCheckFilled } from '@tabler/icons-vue';

import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { surface } from '@/lib/shareCard/colors';
import { iconPaths } from '@/lib/shareCard/icons';
import { type ShareTint } from '@/lib/shareCard/theme';
import type { Point, ShareChannel, ShareImages, SharePoint } from '@/lib/shareCard/types';
import type { VerifiedBadge } from '@/types/social-account';

/** A channel's avatar beside its name, at most the page's 24px at 2x, with the network badge at its corner. */
export const AVATAR = { size: 48, fill: 0.8, radius: 0.25, gap: 14, badge: 0.5, verified: 0.42 };

/** The network badge's white ring (a share of the badge, never thinner than `ringMin`) and how far it sits in from the avatar's corner. */
const BADGE = { ring: 0.12, ringMin: 1.5, insetX: 0.66, insetY: 0.8 };

/** The verified rosette: how far it overhangs the avatar's top right, its white backing, and the 24px grid its path is drawn on. */
const VERIFIED = { overhang: 0.7, rise: 0.3, backing: 0.42, grid: 24 };

/** `VerifiedBadge`'s colors. */
const VERIFIED_COLORS: Record<VerifiedBadge, string> = { blue: '#1d9bf0', business: '#e2b719', government: '#829aab' };

/** A channel's own color, the one the page draws it in, else the tint's strong color. */
export const rowColor = (point: SharePoint, tint: ShareTint): string => {
    if (!point.color) {
        return tint.strong;
    }

    return point.color;
};

const avatarImage = (channel: ShareChannel, images: ShareImages): HTMLImageElement | undefined => {
    if (!channel.avatar) {
        return undefined;
    }

    return images.get(channel.avatar);
};

/** An image scaled to cover a square, cropped to its middle, inside the current clip. */
const drawCover = (context: CanvasRenderingContext2D, image: HTMLImageElement, at: Point, size: number): void => {
    const scale = size / Math.min(image.naturalWidth, image.naturalHeight);
    const width = image.naturalWidth * scale;
    const height = image.naturalHeight * scale;

    context.drawImage(image, at.x + (size - width) / 2, at.y + (size - height) / 2, width, height);
};

/** An image in a rounded square, on a white ground so a transparent logo reads on any tint. */
const paintTile = (context: CanvasRenderingContext2D, image: HTMLImageElement, at: Point, size: number): void => {
    context.save();
    context.beginPath();
    context.roundRect(at.x, at.y, size, size, size * AVATAR.radius);
    context.fillStyle = surface();
    context.fill();
    context.clip();
    drawCover(context, image, at, size);
    context.restore();
};

/** The network's logo on the avatar's bottom-end corner, ringed in white like `ChannelAvatar`'s badge. */
const paintNetworkBadge = (context: CanvasRenderingContext2D, logo: HTMLImageElement, avatar: Point, size: number): void => {
    const badge = size * AVATAR.badge;
    const ring = Math.max(BADGE.ringMin, badge * BADGE.ring);
    const at = { x: avatar.x + size - badge * BADGE.insetX, y: avatar.y + size - badge * BADGE.insetY };

    context.beginPath();
    context.roundRect(at.x - ring, at.y - ring, badge + ring * 2, badge + ring * 2, (badge + ring * 2) * AVATAR.radius);
    context.fillStyle = surface();
    context.fill();
    paintTile(context, logo, at, badge);
};

/** The verified rosette on the avatar's top-end corner, on a white disc like `VerifiedBadge`. */
const paintVerified = (context: CanvasRenderingContext2D, badge: VerifiedBadge, avatar: Point, size: number): void => {
    const mark = size * AVATAR.verified;
    const at = { x: avatar.x + size - mark * VERIFIED.overhang, y: avatar.y - mark * VERIFIED.rise };

    context.beginPath();
    context.arc(at.x + mark / 2, at.y + mark / 2, mark * VERIFIED.backing, 0, Math.PI * 2);
    context.fillStyle = surface();
    context.fill();

    context.save();
    context.translate(at.x, at.y);
    context.scale(mark / VERIFIED.grid, mark / VERIFIED.grid);
    context.fillStyle = VERIFIED_COLORS[badge];
    iconPaths(IconRosetteDiscountCheckFilled).forEach((path) => context.fill(new Path2D(path)));
    context.restore();
};

/**
 * The channel as the page's chart shows it: its avatar with the network's logo
 * at the corner and the verified mark when it has one; a channel without an
 * avatar shows the network's logo in its place.
 */
export const paintChannel = (
    context: CanvasRenderingContext2D,
    channel: ShareChannel,
    images: ShareImages,
    at: Point,
    size: number,
): void => {
    const logo = images.get(getPlatformLogo(channel.platform));
    const avatar = avatarImage(channel, images);

    if (avatar) {
        paintTile(context, avatar, at, size);

        if (logo) {
            paintNetworkBadge(context, logo, at, size);
        }
    } else if (logo) {
        paintTile(context, logo, at, size);
    }

    if (channel.verified) {
        paintVerified(context, channel.verified, at, size);
    }
};

/** The room a row's avatar takes before its bar: none when the row has no channel. */
export const avatarSpace = (point: SharePoint, avatarSize: number): number => {
    if (!point.channel) {
        return 0;
    }

    return avatarSize + AVATAR.gap;
};
