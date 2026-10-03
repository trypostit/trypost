import type { MediaType } from '@/lib/mediaType';

export type MediaSource = 'ai' | 'unsplash' | 'google_drive' | 'google_photos' | 'canva';

export type SourceMetaValue = string | number | boolean | null | SourceMetaValue[];

export interface MediaUserTag {
    username: string;
    x: number;
    y: number;
}

export interface MediaItem {
    id: string;
    url: string;
    path?: string;
    type?: MediaType;
    mime_type?: string;
    original_filename?: string;
    size?: number;
    upload_token?: string | null;
    created_at?: string;
    source?: MediaSource;
    source_meta?: Record<string, SourceMetaValue>;
    meta?: {
        width?: number;
        height?: number;
        duration?: number;
        alt_text?: string;
        user_tags?: MediaUserTag[];
        cover_offset_ms?: number;
        source?: MediaSource;
        source_meta?: Record<string, SourceMetaValue>;
    };
}
