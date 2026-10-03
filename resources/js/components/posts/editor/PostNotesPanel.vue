<script setup lang="ts">
import { IconArrowUp, IconLoader2, IconMessage } from '@tabler/icons-vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import PostNoteItem from '@/components/posts/editor/PostNoteItem.vue';
import { Button } from '@/components/ui/button';
import { usePostEcho } from '@/composables/echo/usePostEcho';
import {
    destroy as destroyNote,
    index as fetchNotes,
    store as storeNote,
    update as updateNote,
} from '@/routes/app/posts/notes';
import type { PostNote } from '@/types/post-note';

interface PaginatedResponse {
    data: PostNote[];
    current_page: number;
    last_page: number;
}

const props = defineProps<{
    postId: string;
    currentUserId: string;
    highlightNoteId?: string | null;
}>();

const emit = defineEmits<{
    countChange: [delta: number];
}>();

const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';

const notes = ref<PostNote[]>([]);
const currentPage = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const sending = ref(false);

const newBody = ref('');
const highlightedId = ref<string | null>(null);

const scrollContainer = ref<HTMLDivElement | null>(null);
const composerInput = ref<HTMLTextAreaElement | null>(null);

const hasOlderNotes = computed(() => currentPage.value < lastPage.value);
const canSend = computed(() => newBody.value.trim() !== '' && !sending.value);

const request = (url: string, method = 'GET', body?: object) =>
    fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body ? JSON.stringify(body) : undefined,
    });

const scrollToBottom = () => {
    if (scrollContainer.value) {
        scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
    }
};

const loadNotes = async (page = 1) => {
    loading.value = true;

    try {
        const response = await request(
            fetchNotes.url(props.postId, { query: { page } }),
        );

        if (!response.ok) {
            return;
        }

        const data: PaginatedResponse = await response.json();
        const chronological = [...data.data].reverse();
        currentPage.value = data.current_page;
        lastPage.value = data.last_page;

        if (page === 1) {
            notes.value = chronological;
            await nextTick();
            scrollToBottom();
        } else {
            notes.value = [...chronological, ...notes.value];
        }
    } finally {
        loading.value = false;
    }
};

usePostEcho(props.postId, '.post.note.changed', () => {
    void loadNotes(1);
});

const loadOlderNotes = () => {
    if (hasOlderNotes.value && !loading.value) {
        void loadNotes(currentPage.value + 1);
    }
};

const focusComposer = () => {
    composerInput.value?.focus();
};

const sendNote = async () => {
    const body = newBody.value.trim();

    if (!body || sending.value) {
        return;
    }

    sending.value = true;

    try {
        const response = await request(storeNote.url(props.postId), 'POST', {
            body,
        });

        if (!response.ok) {
            return;
        }

        const created: PostNote = await response.json();
        notes.value.push(created);

        emit('countChange', 1);
        newBody.value = '';
        await nextTick();
        scrollToBottom();
        focusComposer();
    } finally {
        sending.value = false;
    }
};

const saveNote = async (note: PostNote, body: string) => {
    const response = await request(
        updateNote.url({ post: props.postId, note: note.id }),
        'PUT',
        { body },
    );

    if (!response.ok) {
        return;
    }

    const updated: PostNote = await response.json();
    note.body = updated.body;
    note.updated_at = updated.updated_at;
};

const deleteNote = async (note: PostNote) => {
    const response = await request(
        destroyNote.url({ post: props.postId, note: note.id }),
        'DELETE',
    );

    if (!response.ok) {
        return;
    }

    notes.value = notes.value.filter((item) => item.id !== note.id);
    emit('countChange', -1);
};

const handleComposerKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void sendNote();
    }
};

const focusNote = async (noteId: string) => {
    await nextTick();
    const selector = `[data-note-id="${noteId}"]`;
    let element = scrollContainer.value?.querySelector<HTMLElement>(selector);

    while (!element && hasOlderNotes.value) {
        const previousPage = currentPage.value;
        await loadNotes(currentPage.value + 1);

        if (currentPage.value === previousPage) {
            break;
        }

        await nextTick();
        element = scrollContainer.value?.querySelector<HTMLElement>(selector);
    }

    if (!element) {
        return;
    }

    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    highlightedId.value = noteId;
    setTimeout(() => {
        if (highlightedId.value === noteId) {
            highlightedId.value = null;
        }
    }, 4000);
};

onMounted(async () => {
    await loadNotes(1);

    if (props.highlightNoteId) {
        await focusNote(props.highlightNoteId);
    }
});

watch(
    () => props.highlightNoteId,
    (noteId) => {
        if (noteId) {
            void focusNote(noteId);
        }
    },
);

watch(
    () => props.postId,
    () => {
        notes.value = [];
        currentPage.value = 1;
        void loadNotes(1);
    },
);
</script>

<template>
    <div class="flex min-h-0 flex-col">
        <div
            ref="scrollContainer"
            class="max-h-80 min-h-0 overflow-y-auto overscroll-contain"
            data-testid="notes-list"
        >
            <div v-if="hasOlderNotes" class="flex justify-center pt-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground"
                    :disabled="loading"
                    @click="loadOlderNotes"
                >
                    <IconLoader2
                        v-if="loading"
                        class="size-3.5 animate-spin"
                    />
                    {{ $t('notes.load_more') }}
                </Button>
            </div>

            <div
                v-if="loading && notes.length === 0"
                class="flex items-center justify-center py-10"
            >
                <IconLoader2
                    class="size-5 animate-spin text-muted-foreground"
                />
            </div>

            <div
                v-else-if="notes.length === 0"
                class="flex flex-col items-center px-6 py-8 text-center"
                data-testid="notes-empty"
            >
                <div
                    class="mb-3 flex size-9 items-center justify-center rounded-full bg-accent text-muted-foreground"
                >
                    <IconMessage class="size-4.5" stroke-width="1.75" />
                </div>
                <p class="text-sm font-emphasis text-foreground">
                    {{ $t('notes.empty_title') }}
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ $t('notes.empty_description') }}
                </p>
            </div>

            <ul v-else class="flex flex-col gap-0.5 p-2">
                <li v-for="note in notes" :key="note.id">
                    <PostNoteItem
                        :note="note"
                        :current-user-id="currentUserId"
                        :highlighted="highlightedId === note.id"
                        @save="saveNote(note, $event)"
                        @delete="deleteNote(note)"
                    />
                </li>
            </ul>
        </div>

        <div class="shrink-0 border-t border-border p-3">
            <div class="flex items-end gap-2">
                <textarea
                    ref="composerInput"
                    v-model="newBody"
                    rows="1"
                    class="flex field-sizing-content max-h-28 min-h-8 w-full min-w-0 resize-none rounded-md border border-input bg-card px-2 py-[5px] text-sm leading-5 text-foreground transition-[color,box-shadow] outline-none placeholder:text-subtle-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring dark:bg-input/30"
                    :placeholder="$t('notes.placeholder')"
                    :aria-label="$t('notes.placeholder')"
                    data-testid="note-input"
                    @keydown="handleComposerKeydown"
                />
                <Button
                    type="button"
                    size="icon"
                    class="shrink-0"
                    :disabled="!canSend"
                    :aria-label="$t('notes.send')"
                    data-testid="note-send"
                    @click="sendNote"
                >
                    <IconLoader2 v-if="sending" class="size-4 animate-spin" />
                    <IconArrowUp v-else class="size-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
