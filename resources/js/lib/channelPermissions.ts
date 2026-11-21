import { filled } from '@/lib/helpers';
import { threadRepliesOf } from '@/lib/threadReplies';
import {
    GatedFeature,
    type GatedFeatureValue,
    type MissingPermission,
    type MissingPermissions,
} from '@/types/channel-permission';

/** The features a channel lacks a scope for, read from its platform config (`missingPermissions`). */
export const missingPermissionsOf = (
    platformConfig: Record<string, any> | undefined,
): MissingPermissions | null => {
    if (!filled(platformConfig)) {
        return null;
    }

    if (!filled(platformConfig.missingPermissions)) {
        return null;
    }

    return platformConfig.missingPermissions;
};

/** Whether a post uses the feature, as `GatedFeature::isUsedBy()` says on the server: every post publishes, analytics are never part of one. */
export const featureIsUsedBy = (
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
 * it uses, as `ChannelPermissions::violation()` says on the server. Null
 * when nothing holds it back.
 */
export const missingPermissionKey = (
    meta: Record<string, any> | null,
    missing: MissingPermissions | null,
    canManageAccounts: boolean,
): string | null => {
    for (const feature of Object.values(GatedFeature)) {
        const permission = missing?.[feature];

        if (!filled(permission)) {
            continue;
        }

        if (!featureIsUsedBy(feature, meta)) {
            continue;
        }

        return permissionMessageKey(permission, canManageAccounts);
    }

    return null;
};

/** `missingPermissionKey()` for a channel, read from its platform config. */
export const channelPermissionKey = (
    platformConfig: Record<string, any> | undefined,
    meta: Record<string, any> | null,
    canManageAccounts: boolean,
): string | null =>
    missingPermissionKey(
        meta,
        missingPermissionsOf(platformConfig),
        canManageAccounts,
    );
