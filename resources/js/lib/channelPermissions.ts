import { blank, filled } from '@/lib/helpers';
import { threadRepliesOf, threadUnsupportedKey } from '@/lib/threadReplies';
import {
    GatedFeature,
    type GatedFeatureValue,
    type MissingPermission,
    type MissingPermissions,
} from '@/types/channel-permission';
import {
    COVER_AFTER_PUBLISH_CONTENT_TYPES,
    type ContentTypeValue,
} from '@/types/content-type';
import type { MediaItem } from '@/types/media';
import type { PlatformConfig } from '@/types/platform';

/** Whether the feature is on for a channel's network (not in `disabledFeatures` of its platform config). */
export const featureIsEnabled = (
    platformConfig: PlatformConfig | undefined,
    feature: GatedFeatureValue,
): boolean => {
    if (!filled(platformConfig)) {
        return true;
    }

    if (!filled(platformConfig.disabledFeatures)) {
        return true;
    }

    return !platformConfig.disabledFeatures.includes(feature);
};

/** Whether a post uses the feature, as `GatedFeature::isRequiredToPublish()` says on the server: every post publishes, a Reel with a cover image uses the video cover, analytics are never part of one. */
const requiresFeatureToPublish = (
    feature: GatedFeatureValue,
    meta: Record<string, unknown> | null,
    usesVideoCover: boolean,
): boolean => {
    if (feature === GatedFeature.Publish) {
        return true;
    }

    if (feature === GatedFeature.ThreadReplies) {
        return filled(threadRepliesOf(meta));
    }

    if (feature === GatedFeature.VideoCover) {
        return usesVideoCover;
    }

    return false;
};

/** Whether the post's video cover is set after publishing, which only a type like the Facebook Reel does (`GatedFeature::usesVideoCover()`). */
const usesVideoCover = (
    contentType: ContentTypeValue | null,
    hasVideoCover: boolean,
): boolean => {
    if (!hasVideoCover) {
        return false;
    }

    if (!filled(contentType)) {
        return false;
    }

    return COVER_AFTER_PUBLISH_CONTENT_TYPES.has(contentType);
};

/** The copy for a missing permission: the reconnect ask, or who can reconnect for someone who cannot. */
export const permissionMessageKey = (
    permission: MissingPermission,
    canManageAccounts: boolean,
): string => {
    if (canManageAccounts) {
        return permission.message;
    }

    return permission.member_message;
};

/**
 * Why a post cannot go out on a channel that lacks the scope of a feature
 * it uses, as `ChannelPermissions::featureBlockingPost()` says on the server. Null
 * when nothing holds it back.
 */
export const missingPermissionKey = (
    meta: Record<string, unknown> | null,
    missing: MissingPermissions | null | undefined,
    canManageAccounts: boolean,
    repliesSupported = true,
    videoCoverUsed = false,
): string | null => {
    for (const feature of Object.values(GatedFeature)) {
        if (feature === GatedFeature.ThreadReplies && !repliesSupported) {
            continue;
        }

        const permission = missing?.[feature];

        if (!filled(permission)) {
            continue;
        }

        if (!requiresFeatureToPublish(feature, meta, videoCoverUsed)) {
            continue;
        }

        return permissionMessageKey(permission, canManageAccounts);
    }

    return null;
};

/** Whether a post's media carries a cover image (`MediaItem::covers()` on the server). */
export const mediaHasVideoCover = (media: MediaItem[]): boolean =>
    media.some((item) => filled(item.meta?.cover));

/** `missingPermissionKey()` for a channel, read from its platform config. */
export const channelPermissionKey = (
    platformConfig: PlatformConfig | undefined,
    contentType: ContentTypeValue | null,
    meta: Record<string, unknown> | null,
    canManageAccounts: boolean,
    hasVideoCover = false,
): string | null => {
    if (!filled(platformConfig)) {
        return null;
    }

    const repliesSupported = blank(
        threadUnsupportedKey(
            platformConfig.platform,
            contentType,
            featureIsEnabled(platformConfig, GatedFeature.ThreadReplies),
        ),
    );

    return missingPermissionKey(
        meta,
        platformConfig.missingPermissions,
        canManageAccounts,
        repliesSupported,
        usesVideoCover(contentType, hasVideoCover),
    );
};
