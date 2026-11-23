import { filled } from '@/lib/helpers';
import { threadRepliesOf } from '@/lib/threadReplies';
import {
    GatedFeature,
    type GatedFeatureValue,
    type MissingPermission,
    type MissingPermissions,
} from '@/types/channel-permission';
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

/** Whether a post uses the feature, as `GatedFeature::isRequiredToPublish()` says on the server: every post publishes, analytics are never part of one. */
const requiresFeatureToPublish = (
    feature: GatedFeatureValue,
    meta: Record<string, any> | null,
): boolean => {
    if (feature === GatedFeature.Publish) {
        return true;
    }

    if (feature === GatedFeature.ThreadReplies) {
        return filled(threadRepliesOf(meta));
    }

    return false;
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
    meta: Record<string, any> | null,
    missing: MissingPermissions | null | undefined,
    canManageAccounts: boolean,
): string | null => {
    for (const feature of Object.values(GatedFeature)) {
        const permission = missing?.[feature];

        if (!filled(permission)) {
            continue;
        }

        if (!requiresFeatureToPublish(feature, meta)) {
            continue;
        }

        return permissionMessageKey(permission, canManageAccounts);
    }

    return null;
};

/** `missingPermissionKey()` for a channel, read from its platform config. */
export const channelPermissionKey = (
    platformConfig: PlatformConfig | undefined,
    meta: Record<string, any> | null,
    canManageAccounts: boolean,
): string | null =>
    missingPermissionKey(
        meta,
        platformConfig?.missingPermissions,
        canManageAccounts,
    );
