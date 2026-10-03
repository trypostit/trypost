import type { Component } from 'vue';

import type { MediaItem } from '@/types/media';

export interface PreviewAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
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

export type PreviewMediaLayout = 'grid' | 'stack' | 'peek' | 'carousel';
