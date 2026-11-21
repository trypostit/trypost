import { filled } from '@/lib/helpers';
import { threadRepliesOf } from '@/lib/threadReplies';

/**
 * The features a network may only allow once the account grants a scope
 * beyond its publish scopes (`App\Enums\SocialAccount\GatedFeature`).
 */
export const GatedFeature = {
    ThreadReplies: 'thread_replies',
} as const;

export type GatedFeatureValue = (typeof GatedFeature)[keyof typeof GatedFeature];

/** The copy a channel lacking a feature's scope is shown: `message`, and `member_message` for someone who cannot reconnect it. */
export type MissingPermission = { message: string; member_message: string };

/** The features a channel lacks the scope for (`missingPermissions` of its platform config). */
export type MissingPermissions = Partial<Record<GatedFeatureValue, MissingPermission>>;

/** Whether a post uses the feature, as `GatedFeature::isUsedBy()` says on the server. */
export const featureIsUsedBy = (
    feature: GatedFeatureValue,
    meta: Record<string, any> | null,
): boolean => {
    if (feature === GatedFeature.ThreadReplies) {
        return filled(threadRepliesOf(meta));
    }

    return false;
};

/**
 * Why a post cannot go out on a channel that lacks the scope of a feature
 * it uses, as `ChannelPermissions::violation()` says on the server: the
 * feature's `message`, or its `member_message` for someone who cannot
 * reconnect the channel. Null when nothing holds it back.
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

        if (canManageAccounts) {
            return permission.message;
        }

        return permission.member_message;
    }

    return null;
};
