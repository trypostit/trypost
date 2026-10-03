<script setup lang="ts">
import { IconDots, IconPencil, IconTrash } from '@tabler/icons-vue';
import { computed, nextTick, ref } from 'vue';

import NoteBody from '@/components/NoteBody.vue';
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
import type { PostNote } from '@/types/post-note';

const props = withDefaults(
    defineProps<{
        note: PostNote;
        currentUserId: string;
        highlighted?: boolean;
    }>(),
    {
        highlighted: false,
    },
);

const emit = defineEmits<{
    save: [body: string];
    delete: [];
}>();

const viewTimezone = useViewTimezone();

const editing = ref(false);
const editBody = ref('');
const editInput = ref<HTMLTextAreaElement | null>(null);

const isOwn = computed(() => props.note.user_id === props.currentUserId);
const wasEdited = computed(
    () => props.note.updated_at !== props.note.created_at,
);

const startEdit = async () => {
    editBody.value = props.note.body;
    editing.value = true;
    await nextTick();
    editInput.value?.focus();
    editInput.value?.parentElement?.scrollIntoView({ block: 'nearest' });
};

const cancelEdit = () => {
    editing.value = false;
    editBody.value = '';
};

const saveEdit = () => {
    const body = editBody.value.trim();

    if (!body) {
        return;
    }

    emit('save', body);
    cancelEdit();
};

const handleEditKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.stopPropagation();
        cancelEdit();

        return;
    }

    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        saveEdit();
    }
};
</script>

<template>
    <div
        :data-note-id="note.id"
        class="group/note relative flex gap-2.5 rounded-lg px-2 py-2 transition-colors"
        :class="
            highlighted
                ? 'bg-primary-subtle ring-2 ring-primary-strong'
                : 'hover:bg-accent has-[[data-state=open]]:bg-accent'
        "
        data-testid="note-item"
    >
        <Avatar
            :src="note.user.photo_url"
            :name="note.user.name"
            class="size-7 text-[11px]"
            fallback-class="bg-sidebar-accent text-sidebar-accent-foreground"
        />

        <div class="min-w-0 flex-1">
            <div class="flex min-h-7 items-center gap-1.5">
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
                    >· {{ $t('notes.edited') }}</span
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

            <div v-if="editing" class="mt-1 flex flex-col gap-2">
                <textarea
                    ref="editInput"
                    v-model="editBody"
                    rows="1"
                    class="field-sizing-content max-h-28 min-h-0 w-full resize-none rounded-lg border border-input bg-card px-2.5 py-1.5 text-sm text-foreground outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    data-testid="note-edit-input"
                    @keydown="handleEditKeydown"
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
                        :disabled="!editBody.trim()"
                        data-testid="note-edit-save"
                        @click="saveEdit"
                    >
                        {{ $t('notes.save') }}
                    </Button>
                </div>
            </div>

            <NoteBody
                v-else
                :body="note.body"
                class="leading-relaxed text-foreground"
            />
        </div>
    </div>
</template>
