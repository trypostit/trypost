<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconArrowUp, IconLoader2 } from '@tabler/icons-vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import PostNoteItem from '@/components/posts/editor/PostNoteItem.vue';
import PostNoteMentionInput from '@/components/posts/editor/PostNoteMentionInput.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
} from '@/components/ui/input-group';
import { usePostEcho } from '@/composables/echo/usePostEcho';
import {
    destroy as destroyNote,
    index as fetchNotes,
    store as storeNote,
    update as updateNote,
} from '@/routes/app/posts/notes';
import { index as fetchMentionables } from '@/routes/app/posts/notes/mentionables';
import {
    destroy as removeReaction,
    update as addReaction,
} from '@/routes/app/posts/notes/reactions';
import type { PostNote, PostNoteMentionable } from '@/types/post-note';

interface PaginatedResponse {
    data: PostNote[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}

const props = defineProps<{
    postId: string;
    currentUserId: string;
    highlightNoteId?: string | null;
}>();

const emit = defineEmits<{
    count: [total: number];
}>();

const page = usePage();
const currentUser = computed(() => page.props.auth.user);

const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';

const notes = ref<PostNote[]>([]);
const total = ref(0);
const currentPage = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const sending = ref(false);

const newBody = ref('');
const highlightedId = ref<string | null>(null);

const scrollContainer = ref<HTMLDivElement | null>(null);
const composerInput = ref<InstanceType<typeof PostNoteMentionInput> | null>(
    null,
);
const mentionables = ref<PostNoteMentionable[] | null>(null);
let loadingMentionables = false;

watch(total, (count) => emit('count', count));

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
        currentPage.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;

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

const loadMentionables = async () => {
    if (mentionables.value !== null || loadingMentionables) {
        return;
    }

    loadingMentionables = true;

    try {
        const response = await request(fetchMentionables.url(props.postId));

        if (response.ok) {
            mentionables.value = await response.json();
        }
    } finally {
        loadingMentionables = false;
    }
};

const toggleReaction = async (
    note: PostNote,
    emoji: string,
    reacted: boolean,
) => {
    const route = reacted ? removeReaction : addReaction;
    const response = await request(
        route.url({ post: props.postId, note: note.id }),
        reacted ? 'DELETE' : 'PUT',
        { emoji },
    );

    if (!response.ok) {
        return;
    }

    const updated: PostNote = await response.json();
    note.reactions = updated.reactions;
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

        total.value++;
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
    total.value = Math.max(0, total.value - 1);
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
        mentionables.value = null;
        currentPage.value = 1;
        void loadNotes(1);
    },
);
</script>

<template>
    <section class="flex min-h-0 flex-col gap-3">
        <div
            ref="scrollContainer"
            class="-mx-2 max-h-80 min-h-0 overflow-x-hidden overflow-y-auto overscroll-contain px-2"
            data-testid="notes-list"
        >
            <div v-if="hasOlderNotes" class="flex justify-start pb-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="-ms-2 text-muted-foreground"
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
                class="flex items-center gap-3 py-1"
            >
                <IconLoader2
                    class="size-4 animate-spin text-muted-foreground"
                />
            </div>

            <p
                v-else-if="notes.length === 0"
                class="py-1 text-sm text-muted-foreground"
                data-testid="notes-empty"
            >
                {{ $t('notes.empty') }}
            </p>

            <ol v-else class="flex flex-col">
                <li
                    v-for="note in notes"
                    :key="note.id"
                    class="relative pb-3 last:pb-0 [&:not(:last-child)]:before:absolute [&:not(:last-child)]:before:start-[13.5px] [&:not(:last-child)]:before:top-9 [&:not(:last-child)]:before:bottom-1 [&:not(:last-child)]:before:w-px [&:not(:last-child)]:before:bg-border-strong"
                >
                    <PostNoteItem
                        :note="note"
                        :current-user-id="currentUserId"
                        :mentionables="mentionables"
                        :highlighted="highlightedId === note.id"
                        @save="saveNote(note, $event)"
                        @delete="deleteNote(note)"
                        @react="
                            (emoji, reacted) =>
                                toggleReaction(note, emoji, reacted)
                        "
                        @need-people="loadMentionables"
                    />
                </li>
            </ol>
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
                class="min-w-0 flex-1 items-end rounded-[18px] border-transparent bg-muted shadow-none transition-colors hover:border-border-strong has-[[data-slot=input-group-control]:focus-visible]:bg-card"
                data-testid="note-composer"
            >
                <PostNoteMentionInput
                    ref="composerInput"
                    v-model="newBody"
                    :people="mentionables"
                    :placeholder="$t('notes.placeholder')"
                    :aria-label="$t('notes.placeholder')"
                    data-slot="input-group-control"
                    class="field-sizing-content flex max-h-28 min-h-0 w-full flex-1 resize-none bg-transparent py-[7px] ps-4 pe-2 text-sm leading-5 outline-none placeholder:text-muted-foreground"
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
                            v-if="sending"
                            class="size-3.5 animate-spin"
                        />
                        <IconArrowUp v-else class="size-3.5" />
                    </InputGroupButton>
                </InputGroupAddon>
            </InputGroup>
        </div>
    </section>
</template>
