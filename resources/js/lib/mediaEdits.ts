import { blank, filled } from '@/lib/helpers';
import type { MediaItem } from '@/types/media';

/** What the media editor sets on an item for one network: its alt text, tagged people and cover. */
const NETWORK_META = [
    'alt_text',
    'user_tags',
    'cover_offset_ms',
    'cover',
    'cover_source',
] as const;

/** Whether the media editor changed the item for the network it was edited on. */
export const isEditedMedia = (item: MediaItem): boolean => filled(item.original);

/** The item without the composer's `original`, as the server takes it. */
export const withoutOriginal = (item: MediaItem): MediaItem => {
    const copy = { ...item };
    delete copy.original;

    return copy;
};

/**
 * The item's own file with nothing set for a network: what a network that
 * never edited it starts from, when the file it was added as is gone (an
 * expired upload) or was replaced (an Edit in Canva).
 */
export const asAddedFile = (item: MediaItem): MediaItem => {
    const file = withoutOriginal(item);

    if (!filled(file.meta)) {
        return file;
    }

    const meta = { ...file.meta };
    NETWORK_META.forEach((key) => {
        delete meta[key];
    });

    return { ...file, meta };
};

/** The item as it was added, before the media editor changed it for a network. */
export const uneditedMedia = (item: MediaItem): MediaItem => {
    if (!filled(item.original)) {
        return item;
    }

    return withoutOriginal(item.original);
};

/** The id of the shared item an edit changed. */
export const editedId = (edit: MediaItem): string => {
    if (filled(edit.original)) {
        return edit.original.id;
    }

    return edit.id;
};

/** One network's edit of a shared item, or the shared item when that network left it as added. */
const editedItem = (item: MediaItem, edits: MediaItem[]): MediaItem => {
    const edit = edits.find((candidate) => editedId(candidate) === item.id);

    if (!filled(edit)) {
        return item;
    }

    return edit;
};

/** The shared media with one network's edits in place of the items they changed. */
export const withMediaEdits = (
    shared: MediaItem[],
    edits: MediaItem[],
): MediaItem[] => shared.map((item) => editedItem(item, edits));

/**
 * The import that replaces an item: it keeps the item's alt text and tagged
 * people, like an editor swap. An item a network edited stays that network's
 * edit, with the new file as the one a network that never edited it gets.
 */
export const replacementFor = (
    replaced: MediaItem,
    item: MediaItem,
): MediaItem => {
    const meta = { ...item.meta };
    const altText = replaced.meta?.alt_text;
    const userTags = replaced.meta?.user_tags;

    if (filled(altText)) {
        meta.alt_text = altText;
    }

    if (filled(userTags)) {
        meta.user_tags = userTags;
    }

    const replacement: MediaItem = { ...item, meta };

    if (blank(replaced.original)) {
        return replacement;
    }

    return { ...replacement, original: asAddedFile(item) };
};

/** A network's edit of a shared item, carried onto the file that replaces that item. */
export const replacedEdit = (
    edit: MediaItem,
    replaces: string,
    item: MediaItem,
): MediaItem => {
    if (editedId(edit) !== replaces) {
        return edit;
    }

    return replacementFor(edit, item);
};
