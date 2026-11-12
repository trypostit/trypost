<script setup lang="ts">
import { IconDots, IconPencil, IconTrash } from '@tabler/icons-vue';
import { computed, nextTick, ref } from 'vue';

import NoteBody from '@/components/NoteBody.vue';
import PostNoteEditor from '@/components/posts/editor/PostNoteEditor.vue';
import PostNoteReactions from '@/components/posts/editor/PostNoteReactions.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useViewTimezone } from '@/composables/useViewTimezone';
import date from '@/date';
import { blank } from '@/lib/helpers';
import type { PostNote, PostNoteMentionable } from '@/types/post-note';

const props = withDefaults(
    defineProps<{
        note: PostNote;
        currentUserId: string;
        mentionables: PostNoteMentionable[] | null;
        highlighted?: boolean;
    }>(),
    {
        highlighted: false,
    },
);

const emit = defineEmits<{
    save: [body: string, done: (saved: boolean) => void];
    delete: [];
    react: [emoji: string, reacted: boolean];
    needPeople: [];
}>();

const viewTimezone = useViewTimezone();

const editing = ref(false);
const saving = ref(false);
const editBody = ref('');
const editInput = ref<InstanceType<typeof PostNoteEditor> | null>(null);
const editContainer = ref<HTMLDivElement | null>(null);

const isOwn = computed(() => props.note.user_id === props.currentUserId);
const wasEdited = computed(
    () => props.note.updated_at !== props.note.created_at,
);

const startEdit = async () => {
    editBody.value = props.note.body;
    editing.value = true;
    await nextTick();
    editInput.value?.focus();
    editContainer.value?.scrollIntoView({ block: 'nearest' });
};

const cancelEdit = () => {
    editing.value = false;
    editBody.value = '';
};

/** Keeps the editor open, with the text, until the server takes the edit. */
const saveEdit = () => {
    const body = editBody.value.trim();

    if (blank(body) || saving.value) {
        return;
    }

    saving.value = true;
    emit('save', body, (saved) => {
        saving.value = false;

        if (saved) {
            cancelEdit();
        }
    });
};

const react = (emoji: string, reacted: boolean) => {
    emit('react', emoji, reacted);
};

const requestPeople = () => {
    emit('needPeople');
};
</script>

<template>
    <div
        :data-note-id="note.id"
        class="group/note relative -mx-2 flex gap-3 rounded-lg px-2 py-1 transition-colors"
        :class="{ 'bg-primary-subtle': highlighted }"
        :data-highlighted="highlighted"
        data-testid="note-item"
    >
        <Avatar
            :src="note.user.photo_url"
            :name="note.user.name"
            class="size-7 shrink-0 text-xs"
            fallback-class="bg-sidebar-accent text-sidebar-accent-foreground"
        />

        <div class="min-w-0 flex-1">
            <div class="flex min-h-5 items-center gap-1.5">
                <span
                    class="truncate text-sm font-medium text-foreground"
                    data-testid="note-author"
                    >{{ note.user.name }}</span
                >
                <time
                    class="shrink-0 text-xs text-muted-foreground"
                    :datetime="note.created_at"
                    :title="date.formatDateTimeInTimezone(note.created_at, viewTimezone)"
                    data-testid="note-created-at"
                    >{{ date.diffForHumans(note.created_at) }}</time
                >
                <span
                    v-if="wasEdited"
                    class="shrink-0 text-xs text-muted-foreground"
                    >({{ $t('notes.edited') }})</span
                >

                <div
                    v-if="!editing && isOwn"
                    class="ms-auto flex shrink-0 items-center gap-0.5 transition-opacity lg:opacity-0 lg:group-focus-within/note:opacity-100 lg:group-hover/note:opacity-100 lg:has-[[data-state=open]]:opacity-100"
                >
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-xs"
                                class="text-muted-foreground"
                                :aria-label="$t('notes.actions')"
                                data-testid="note-actions"
                            >
                                <IconDots class="size-3.5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                data-testid="note-edit"
                                @select="startEdit"
                            >
                                <IconPencil class="size-4" />
                                {{ $t('notes.edit') }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                data-testid="note-delete"
                                @select="emit('delete')"
                            >
                                <IconTrash class="size-4" />
                                {{ $t('notes.delete') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <div
                v-if="editing"
                ref="editContainer"
                class="mt-1 flex flex-col gap-2"
            >
                <PostNoteEditor
                    ref="editInput"
                    v-model="editBody"
                    :people="mentionables"
                    :current-user-id="currentUserId"
                    :mentions="note.mentions"
                    :aria-label="$t('notes.edit')"
                    class="max-h-28 min-h-0 w-full overflow-y-auto rounded-lg border border-input bg-card px-2.5 py-1.5 text-sm text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    data-testid="note-edit-input"
                    @submit="saveEdit"
                    @cancel="cancelEdit"
                    @need-people="requestPeople"
                />
                <div class="flex items-center justify-end gap-1.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="cancelEdit"
                    >
                        {{ $t('notes.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="blank(editBody)"
                        :loading="saving"
                        data-testid="note-edit-save"
                        @click="saveEdit"
                    >
                        {{ $t('notes.save') }}
                    </Button>
                </div>
            </div>

            <template v-else>
                <NoteBody
                    :body="note.body"
                    :mentions="note.mentions"
                    :people="mentionables"
                    class="mt-0.5 leading-relaxed text-foreground"
                    @need-people="requestPeople"
                />
                <PostNoteReactions
                    :reactions="note.reactions"
                    :current-user-id="currentUserId"
                    @toggle="react"
                />
            </template>
        </div>
    </div>
</template>
