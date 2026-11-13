<script setup lang="ts">
import type { InfiniteScrollRef } from '@inertiajs/core';
import { InfiniteScroll, router, useHttp, usePage } from '@inertiajs/vue3';
import { IconArrowUp, IconLoader2 } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import {
    computed,
    nextTick,
    onMounted,
    ref,
    watch,
    type ComponentPublicInstance,
} from 'vue';
import { toast } from 'vue-sonner';

import PostNoteEditor from '@/components/posts/editor/PostNoteEditor.vue';
import PostNoteItem from '@/components/posts/editor/PostNoteItem.vue';
import { Avatar } from '@/components/ui/avatar';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
} from '@/components/ui/input-group';
import { usePostEcho } from '@/composables/echo/usePostEcho';
import { blank, filled } from '@/lib/helpers';
import {
    destroy as destroyNote,
    show as showNote,
    store as storeNote,
    update as updateNote,
} from '@/routes/app/posts/notes';
import { index as fetchMentionables } from '@/routes/app/posts/notes/mentionables';
import {
    destroy as removeReaction,
    update as addReaction,
} from '@/routes/app/posts/notes/reactions';
import type { SharedData } from '@/types';
import type {
    PostNote,
    PostNoteMentionable,
    PostNotesPage,
} from '@/types/post-note';

/**
 * The notes thread of the post whose notes the page has open: the notes come
 * from the page's `postNotes` scroll prop (newest first, shown oldest first),
 * and scrolling up loads older pages through `<InfiniteScroll reverse>`. A
 * change touches only its note, from the request's answer or from the
 * `post.note.changed` broadcast (which names the note), so older pages and
 * the scroll stay where they are.
 */
const props = defineProps<{
    postId: string;
    currentUserId: string;
    highlightNoteId?: string | null;
}>();

const emit = defineEmits<{
    count: [total: number];
}>();

const page = usePage<
    SharedData & {
        openPostNotesId?: string | null;
        postNotes?: PostNotesPage | null;
    }
>();
const currentUser = computed(() => page.props.auth.user);

const sendHttp = useHttp<{ body: string }, PostNote>({ body: '' });
const noteHttp = useHttp<{ body: string }, PostNote>({ body: '' });
const fetchHttp = useHttp<Record<string, never>, PostNote>({});
const deleteHttp = useHttp({});
const reactionHttp = useHttp<{ emoji: string }, PostNote>({ emoji: '' });
const mentionablesHttp = useHttp<
    Record<string, never>,
    PostNoteMentionable[]
>({});

/** This post's notes as the page holds them (newest first), once loaded. */
const thread = computed(() =>
    page.props.openPostNotesId === props.postId ? page.props.postNotes : null,
);

/** Oldest first, each note once: a page loaded after a new note can repeat the last one. */
const notes = computed(() => [
    ...new Map(
        [...(thread.value?.data ?? [])].reverse().map((note) => [note.id, note]),
    ).values(),
]);

const newBody = ref('');
const highlightedId = ref<string | null>(null);

const scrollContainer = ref<HTMLDivElement | null>(null);
const notesList = ref<HTMLOListElement | null>(null);
const noteElements = new Map<string, Element>();

/** Keeps each rendered note's element by id, for scrolling to a linked note. */
const trackNote = (
    noteId: string,
    element: Element | ComponentPublicInstance | null,
): void => {
    if (element instanceof Element) {
        noteElements.set(noteId, element);
    } else {
        noteElements.delete(noteId);
    }
};
const infiniteScroll = ref<InfiniteScrollRef | null>(null);
const composerInput = ref<InstanceType<typeof PostNoteEditor> | null>(
    null,
);
const mentionables = ref<PostNoteMentionable[] | null>(null);

watch(
    () => thread.value?.meta.total,
    (total) => {
        if (filled(total)) {
            emit('count', total);
        }
    },
);

const canSend = computed(() => filled(newBody.value) && !sendHttp.processing);

const scrollToBottom = () => {
    if (scrollContainer.value) {
        scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
    }
};

/** Whether the list shows its last note, so a new one should keep it in view. */
const isAtBottom = (): boolean => {
    const container = scrollContainer.value;

    return (
        !container ||
        container.scrollHeight - container.scrollTop - container.clientHeight < 48
    );
};

/**
 * Puts a note in the thread: replaces it wherever it shows (a page loaded after
 * a new note can repeat one), or, for a new note, adds it as the newest.
 */
const putNote = async (note: PostNote, isNew = false): Promise<void> => {
    const loaded = thread.value;

    if (!loaded) {
        return;
    }

    if (loaded.data.some((item) => item.id === note.id)) {
        loaded.data = loaded.data.map((item) =>
            item.id === note.id ? note : item,
        );

        return;
    }

    if (!isNew) {
        return;
    }

    const follow = isAtBottom();

    loaded.data.unshift(note);
    loaded.meta.total++;

    if (follow) {
        await nextTick();
        scrollToBottom();
    }
};

/** Takes a note out of the thread, every copy of it. */
const dropNote = (noteId: string): void => {
    const loaded = thread.value;

    if (!loaded?.data.some((item) => item.id === noteId)) {
        return;
    }

    loaded.data = loaded.data.filter((item) => item.id !== noteId);
    loaded.meta.total--;
};

const failed = (message: string): void => {
    toast.error(message, { testId: 'note-error-toast' });
};

/** Another member (or this page) changed a note: refresh that note alone. */
usePostEcho<{ note_id: string | null; change: string }>(
    props.postId,
    '.post.note.changed',
    async ({ note_id: noteId, change }) => {
        if (!filled(noteId)) {
            return;
        }

        if (change === 'deleted') {
            dropNote(noteId);

            return;
        }

        const note = await fetchHttp
            .get(showNote.url({ post: props.postId, note: noteId }))
            .catch(() => null);

        if (filled(note)) {
            await putNote(note, change === 'created');
        }
    },
);

const focusComposer = () => {
    composerInput.value?.focus();
};

/** The people a note can mention, loaded once when the first `@` is typed. */
const loadMentionables = async () => {
    if (mentionables.value !== null || mentionablesHttp.processing) {
        return;
    }

    const people = await mentionablesHttp
        .get(fetchMentionables.url(props.postId))
        .catch(() => null);

    if (people) {
        mentionables.value = people;
    }
};

const toggleReaction = async (
    note: PostNote,
    emoji: string,
    reacted: boolean,
) => {
    reactionHttp.emoji = emoji;

    const updated = await reactionHttp
        .submit((reacted ? removeReaction : addReaction)({
            post: props.postId,
            note: note.id,
        }))
        .catch(() => null);

    if (filled(updated)) {
        await putNote(updated);
    } else {
        failed(reactionHttp.errors.emoji ?? trans('notes.errors.not_reacted'));
    }
};

const sendNote = async () => {
    const body = newBody.value.trim();

    if (blank(body) || sendHttp.processing) {
        return;
    }

    sendHttp.body = body;

    const created = await sendHttp
        .post(storeNote.url(props.postId))
        .catch(() => null);

    if (!filled(created)) {
        failed(sendHttp.errors.body ?? trans('notes.errors.not_saved'));

        return;
    }

    if (newBody.value.trim() === body) {
        newBody.value = '';
    }

    await putNote(created, true);
    scrollToBottom();
    focusComposer();
};

/** Saves an edit; `done(false)` keeps the editor open with the text. */
const saveNote = async (
    note: PostNote,
    body: string,
    done: (saved: boolean) => void,
) => {
    noteHttp.body = body;

    const updated = await noteHttp
        .put(updateNote.url({ post: props.postId, note: note.id }))
        .catch(() => null);

    if (filled(updated)) {
        await putNote(updated);
    } else {
        failed(noteHttp.errors.body ?? trans('notes.errors.not_saved'));
    }

    done(filled(updated));
};

const deleteNote = async (note: PostNote) => {
    const deleted = await deleteHttp
        .delete(destroyNote.url({ post: props.postId, note: note.id }))
        .then(() => true)
        .catch(() => false);

    if (deleted) {
        dropNote(note.id);
    } else {
        failed(trans('notes.errors.not_deleted'));
    }
};

/**
 * Loads the next older page; false when there is none or it fails. It settles
 * when the next visit finishes, which also covers a load the scroll started.
 */
const loadOlderNotes = (): Promise<boolean> =>
    new Promise((resolve) => {
        const scroll = infiniteScroll.value;

        if (!scroll?.hasNext()) {
            resolve(false);

            return;
        }

        const stop = router.on('finish', (event) => {
            stop();
            resolve(event.detail.visit.completed);
        });

        scroll.fetchNext();
    });

/** The note's element, loading older pages until it shows up or none are left. */
const findNote = async (noteId: string): Promise<Element | null> => {
    await nextTick();

    const element = noteElements.get(noteId);

    if (element) {
        return element;
    }

    return (await loadOlderNotes()) ? findNote(noteId) : null;
};

/** Resolves once the browser has laid out and painted the current changes. */
const afterPaint = (): Promise<void> =>
    new Promise((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
    );

/**
 * Scrolls to a note and highlights it. The scroll waits for the page to settle:
 * `<InfiniteScroll reverse>` keeps the reader's place after loading an older
 * page, which would otherwise scroll the note back out of view.
 */
const focusNote = async (noteId: string) => {
    const element = await findNote(noteId);

    if (!element) {
        return;
    }

    await afterPaint();
    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    highlightedId.value = noteId;
    setTimeout(() => {
        if (highlightedId.value === noteId) {
            highlightedId.value = null;
        }
    }, 4000);
};

/** Opens on the linked note once the thread has loaded, and again when the link changes. */
const focusLinkedNote = () => {
    if (filled(thread.value) && filled(props.highlightNoteId)) {
        void focusNote(props.highlightNoteId);
    }
};

watch(() => filled(thread.value), focusLinkedNote);
watch(() => props.highlightNoteId, focusLinkedNote);
onMounted(focusLinkedNote);
</script>

<template>
    <section class="flex min-h-0 flex-col gap-3">
        <div
            ref="scrollContainer"
            class="-mx-2 max-h-80 min-h-0 overflow-x-hidden overflow-y-auto overscroll-contain px-2"
            data-testid="notes-list"
        >
            <div v-if="blank(thread)" class="flex items-center gap-3 py-1">
                <IconLoader2
                    class="size-4 animate-spin text-muted-foreground"
                />
            </div>

            <p
                v-else-if="blank(notes)"
                class="py-1 text-sm text-muted-foreground"
                data-testid="notes-empty"
            >
                {{ $t('notes.empty') }}
            </p>

            <InfiniteScroll
                v-else
                ref="infiniteScroll"
                data="postNotes"
                reverse
                preserve-url
                :items-element="() => notesList"
            >
                <template #next="{ loading }">
                    <div
                        v-if="loading"
                        class="flex justify-center pb-2"
                        data-testid="notes-loading-older"
                    >
                        <IconLoader2
                            class="size-4 animate-spin text-muted-foreground"
                        />
                    </div>
                </template>

                <ol ref="notesList" class="flex flex-col">
                    <li
                        v-for="note in notes"
                        :key="note.id"
                        :ref="(element) => trackNote(note.id, element)"
                        class="relative pb-3 last:pb-0 [&:not(:last-child)]:before:absolute [&:not(:last-child)]:before:start-[13.5px] [&:not(:last-child)]:before:top-9 [&:not(:last-child)]:before:bottom-1 [&:not(:last-child)]:before:w-px [&:not(:last-child)]:before:bg-border-strong"
                    >
                        <PostNoteItem
                            :note="note"
                            :current-user-id="currentUserId"
                            :mentionables="mentionables"
                            :highlighted="highlightedId === note.id"
                            @save="(body, done) => saveNote(note, body, done)"
                            @delete="deleteNote(note)"
                            @react="
                                (emoji, reacted) =>
                                    toggleReaction(note, emoji, reacted)
                            "
                            @need-people="loadMentionables"
                        />
                    </li>
                </ol>
            </InfiniteScroll>
        </div>

        <div class="flex shrink-0 items-end gap-3">
            <Avatar
                v-if="currentUser"
                :src="currentUser.photo_url"
                :name="currentUser.name"
                class="mb-[5px] size-7 shrink-0 text-xs"
                fallback-class="bg-sidebar-accent text-sidebar-accent-foreground"
            />
            <InputGroup
                class="h-auto min-h-9 min-w-0 flex-1 items-end rounded-[18px] border-transparent bg-muted shadow-none transition-colors hover:border-border-strong has-[[data-slot=input-group-control]:focus-visible]:bg-card"
                data-testid="note-composer"
            >
                <PostNoteEditor
                    ref="composerInput"
                    v-model="newBody"
                    :people="mentionables"
                    :current-user-id="currentUserId"
                    :placeholder="$t('notes.placeholder')"
                    :aria-label="$t('notes.placeholder')"
                    data-slot="input-group-control"
                    class="max-h-28 min-h-0 w-full overflow-y-auto bg-transparent py-[7px] ps-4 pe-2 text-sm leading-5"
                    data-testid="note-input"
                    @submit="sendNote"
                    @need-people="loadMentionables"
                />
                <InputGroupAddon align="inline-end" class="pb-1.5">
                    <InputGroupButton
                        variant="default"
                        size="icon-xs"
                        class="me-0.5 rounded-full disabled:bg-transparent disabled:text-muted-foreground disabled:opacity-100"
                        :disabled="!canSend"
                        :aria-label="$t('notes.send')"
                        data-testid="note-send"
                        @click="sendNote"
                    >
                        <IconLoader2
                            v-if="sendHttp.processing"
                            class="size-3.5 animate-spin"
                        />
                        <IconArrowUp v-else class="size-3.5" />
                    </InputGroupButton>
                </InputGroupAddon>
            </InputGroup>
        </div>
    </section>
</template>
