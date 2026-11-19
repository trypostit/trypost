import type { ImageSize } from '@/composables/useImageCrop';
import type { MediaEdit } from '@/lib/mediaEditor';
import type { MediaType } from '@/lib/mediaType';

export type MediaSource = 'ai' | 'unsplash' | 'giphy' | 'google_drive' | 'google_photos' | 'canva';

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
        cover?: MediaItem;
        cover_source?: CoverSource;
        source?: MediaSource;
        source_meta?: Record<string, SourceMetaValue>;
    };
}

/**
 * Where a video's cover comes from (`App\Enums\Media\CoverSource`): a frame
 * of the video, or an image. A video shared by several networks keeps its
 * frame time either way, for the networks that only take a frame.
 */
export type CoverSource = 'frame' | 'image';

/**
 * An image picked as a video's cover in the media editor: the stored item,
 * or a file picked there and uploaded when the edit is applied. `url` is what
 * the stage shows, `crop` how the user framed it (the stage edits it like an
 * image) and `natural` its size once the stage has loaded it.
 */
export type CoverImage = {
    url: string;
    item: MediaItem | null;
    file: File | null;
    crop: MediaEdit;
    natural: ImageSize | null;
};
