import { ref, type Ref } from 'vue';

import type { MediaEditChange } from '@/components/posts/composer/MediaEditorDialog.vue';
import { filled } from '@/lib/helpers';
import { replacementFor } from '@/lib/mediaEdits';
import { storeChunked as mediaStoreChunked } from '@/routes/app/media';
import type { CoverImage, MediaItem } from '@/types/media';
import { uploadChunked } from '@/utils/chunkedUpload';

const editedMeta = (
    meta: MediaItem['meta'],
    change: MediaEditChange,
    cover: MediaItem | null,
): MediaItem['meta'] => {
    const next = { ...(meta ?? {}) };
    delete next.alt_text;
    delete next.user_tags;
    delete next.cover_offset_ms;
    delete next.cover;
    delete next.cover_source;

    if (filled(change.width) && filled(change.height)) {
        next.width = change.width;
        next.height = change.height;
    }

    if (filled(change.altText)) {
        next.alt_text = change.altText;
    }

    if (filled(change.userTags)) {
        next.user_tags = change.userTags;
    }

    if (filled(change.coverOffsetMs)) {
        next.cover_offset_ms = change.coverOffsetMs;
    }

    if (filled(cover)) {
        next.cover = cover;
        next.cover_source = change.coverSource;
    }

    return next;
};

/** The cover image to store: the picked file uploaded, or the item it already was. */
const coverItem = async (cover: CoverImage | null): Promise<MediaItem | null> => {
    if (!filled(cover)) {
        return null;
    }

    if (!filled(cover.file)) {
        return cover.item;
    }

    const uploaded = await uploadChunked({
        file: cover.file,
        url: mediaStoreChunked.url(),
    });
    URL.revokeObjectURL(cover.url);

    return uploaded;
};

const editedItem = async (change: MediaEditChange): Promise<MediaItem> => {
    const meta = editedMeta(change.media.meta, change, await coverItem(change.coverImage));
    if (!change.file) {
        return { ...change.media, meta };
    }

    const uploaded = await uploadChunked({
        file: change.file,
        url: mediaStoreChunked.url(),
    });

    return { ...uploaded, meta };
};

/** The edited item in its place, keeping the item as it was first added when asked to. */
const swappedItem = (
    edited: MediaItem,
    current: MediaItem,
    keepsOriginal: boolean,
): MediaItem => {
    if (!keepsOriginal) {
        return edited;
    }

    if (filled(current.original)) {
        return { ...edited, original: current.original };
    }

    return { ...edited, original: current };
};

/**
 * Adds a finished import to a list. With `replaces`, it takes that item's
 * place (`replacementFor`); when that item is gone it is appended.
 */
export const withMediaAdded = (
    items: MediaItem[],
    item: MediaItem,
    replaces: string | null = null,
): MediaItem[] => {
    const index = replaces
        ? items.findIndex((existing) => existing.id === replaces)
        : -1;
    if (index === -1) return [...items, item];

    const replacement = replacementFor(items[index], item);

    return items.map((existing, position) =>
        position === index ? replacement : existing,
    );
};

/**
 * Applies media editor changes to a list: pixel edits upload as temporary
 * uploads, then every edited item replaces the one still at its index. On a
 * failed upload nothing is replaced and `failed` is set. With `keepsOriginal`
 * each edited item keeps the item as it was first added (`original`), so a
 * network added later starts from it.
 */
export const useMediaEditSwap = (): {
    uploading: Ref<boolean>;
    failed: Ref<boolean>;
    swap: (options: {
        changes: MediaEditChange[];
        indexes: number[];
        items: () => MediaItem[];
        write: (items: MediaItem[]) => void;
        keepsOriginal?: boolean;
    }) => Promise<void>;
} => {
    const uploading = ref(false);
    const failed = ref(false);

    const swap = async ({
        changes,
        indexes,
        items,
        write,
        keepsOriginal = false,
    }: {
        changes: MediaEditChange[];
        indexes: number[];
        items: () => MediaItem[];
        write: (items: MediaItem[]) => void;
        keepsOriginal?: boolean;
    }): Promise<void> => {
        if (changes.length === 0) return;
        uploading.value = true;
        failed.value = false;
        try {
            const edited = await Promise.all(
                changes.map(async (change) => ({
                    change,
                    item: await editedItem(change),
                })),
            );
            const next = [...items()];
            for (const { change, item } of edited) {
                const position = indexes[change.index];
                const current = next[position];
                if (current?.id !== change.media.id) {
                    continue;
                }
                next[position] = swappedItem(item, current, keepsOriginal);
            }
            write(next);
        } catch {
            failed.value = true;
        } finally {
            uploading.value = false;
        }
    };

    return { uploading, failed, swap };
};
