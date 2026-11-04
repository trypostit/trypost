import type { XPollLimits } from '@/types/x';

export const Platform = {
    LinkedIn: 'linkedin',
    LinkedInPage: 'linkedin-page',
    X: 'x',
    TikTok: 'tiktok',
    YouTube: 'youtube',
    Facebook: 'facebook',
    Instagram: 'instagram',
    InstagramFacebook: 'instagram-facebook',
    Threads: 'threads',
    Pinterest: 'pinterest',
    Bluesky: 'bluesky',
    Mastodon: 'mastodon',
    Telegram: 'telegram',
    Discord: 'discord',
    GoogleBusiness: 'google_business',
} as const;

export type PlatformValue = (typeof Platform)[keyof typeof Platform];

/** What `FirstComment::limitsFor()` serves for an account that can post a first comment. */
export interface FirstCommentLimits {
    maxLength: number;
}

/** What `Platform::publishConfig()` serves for an account: each network fills only its own keys. */
export interface PublishConfig {
    /** TikTok: the privacy levels the creator may pick, and the policy links. */
    privacyLevelOptions?: string[];
    musicUsageConfirmationUrl?: string;
    brandedContentPolicyUrl?: string;
    /** YouTube: the category list and the default category. */
    categoryOptions?: { value: string; labelKey: string }[];
    defaultCategoryId?: string;
    /** X: the poll limits. */
    poll?: XPollLimits;
}
