import { filled } from '@/lib/helpers';
import type { MediaItem } from '@/types/media';

/** What the media editor sets on an item for one network: its alt text, tagged people and cover. */
const NETWORK_META: readonly string[] = [
    'alt_text',
    'user_tags',
    'cover_offset_ms',
    'cover',
    'cover_source',
];

/** Whether the media editor changed the item for the network it was edited on. */
export const isEditedMedia = (item: MediaItem): boolean => {
    if (filled(item.original)) {
        return true;
    }

    return NETWORK_META.some((key) => filled(item.meta?.[key as keyof NonNullable<MediaItem['meta']>]));
};

/** The item without the composer's `original`, as the server and the stored draft take it. */
export const withoutOriginal = (item: MediaItem): MediaItem => {
    const copy = { ...item };
    delete copy.original;

    return copy;
};

/** The item as it was added: its original file, without what the editor set for a network. */
export const uneditedMedia = (item: MediaItem): MediaItem => {
    const base = withoutOriginal(item.original ?? item);

    if (!filled(base.meta)) {
        return base;
    }

    const meta = { ...base.meta };
    NETWORK_META.forEach((key) => {
        delete meta[key as keyof typeof meta];
    });

    return { ...base, meta };
};

/** The id of the shared item an edit changed. */
const editedId = (edit: MediaItem): string => {
    if (filled(edit.original)) {
        return edit.original.id;
    }

    return edit.id;
};

/** The shared media with one network's edits in place of the items they changed. */
export const withMediaEdits = (
    shared: MediaItem[],
    edits: MediaItem[],
): MediaItem[] =>
    shared.map(
        (item) => edits.find((edit) => editedId(edit) === item.id) ?? item,
    );
