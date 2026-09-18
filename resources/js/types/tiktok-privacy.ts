export const TikTokPrivacyLevel = {
    PublicToEveryone: 'PUBLIC_TO_EVERYONE',
    MutualFollowFriends: 'MUTUAL_FOLLOW_FRIENDS',
    FollowerOfCreator: 'FOLLOWER_OF_CREATOR',
    SelfOnly: 'SELF_ONLY',
} as const;

export type TikTokPrivacyLevelValue = (typeof TikTokPrivacyLevel)[keyof typeof TikTokPrivacyLevel];

export const TIKTOK_PRIVACY_LEVELS: TikTokPrivacyLevelValue[] = Object.values(TikTokPrivacyLevel);

export const isTikTokPrivacyLevel = (value: unknown): value is TikTokPrivacyLevelValue =>
    typeof value === 'string' && (TIKTOK_PRIVACY_LEVELS as string[]).includes(value);

export const tiktokPrivacyLabelKey: Record<TikTokPrivacyLevelValue, string> = {
    [TikTokPrivacyLevel.PublicToEveryone]: 'posts.form.tiktok.privacy.public',
    [TikTokPrivacyLevel.MutualFollowFriends]: 'posts.form.tiktok.privacy.friends',
    [TikTokPrivacyLevel.FollowerOfCreator]: 'posts.form.tiktok.privacy.followers',
    [TikTokPrivacyLevel.SelfOnly]: 'posts.form.tiktok.privacy.private',
};
