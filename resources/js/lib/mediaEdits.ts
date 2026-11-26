import { filled } from '@/lib/helpers';
import type { MediaItem } from '@/types/media';

/** Whether the media editor changed the item for the network it was edited on. */
export const isEditedMedia = (item: MediaItem): boolean => filled(item.original);

/** The item without the composer's `original`, as the server takes it. */
export const withoutOriginal = (item: MediaItem): MediaItem => {
    const copy = { ...item };
    delete copy.original;

    return copy;
};

/** The item as it was added, before the media editor changed it for a network. */
export const uneditedMedia = (item: MediaItem): MediaItem => {
    if (!filled(item.original)) {
        return item;
    }

    return withoutOriginal(item.original);
};

/** The id of the shared item an edit changed. */
const editedId = (edit: MediaItem): string => {
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
