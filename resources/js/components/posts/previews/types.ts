import type { Component } from 'vue';

import type { MediaItem } from '@/types/media';
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
