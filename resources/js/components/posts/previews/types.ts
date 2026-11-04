import type { Component } from 'vue';

import type { MediaItem } from '@/types/media';
import type { PublishConfig } from '@/types/platform';
import type { VerifiedBadge } from '@/types/social-account';

export interface PreviewAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
    verified_badge?: VerifiedBadge | null;
}

export interface PreviewProps {
    socialAccount: PreviewAccount;
    content: string;
    media: MediaItem[];
    contentType?: string;
    meta?: Record<string, any>;
    postedAt?: string | null;
    /** The account's `publishConfig` (`Platform::publishConfig()`), e.g. X's poll limits. */
    publishConfig?: PublishConfig | null;
    /** The thread replies a network publishes as comments on the post (LinkedIn). */
    comments?: { text: string }[];
}

export interface PreviewAction {
    icon: Component;
    labelKey?: string;
}

export type PreviewMediaLayout =
    | 'grid'
    | 'peek'
    | 'carousel'
    | 'collage';
