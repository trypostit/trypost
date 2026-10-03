<script setup lang="ts">
import {
    IconMessageCircle,
    IconMessageCircleFilled,
    IconX,
} from '@tabler/icons-vue';
import { ref, watch } from 'vue';

import PostNotesPanel from '@/components/posts/editor/PostNotesPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

const props = withDefaults(
    defineProps<{
        postId: string;
        count: number;
        currentUserId: string;
        initialOpen?: boolean;
        highlightNoteId?: string | null;
    }>(),
    {
        initialOpen: false,
        highlightNoteId: null,
    },
);

const open = ref(props.initialOpen);

const closePopover = (): void => {
    open.value = false;
};

const noteCount = ref(props.count);

const changeNoteCount = (delta: number): void => {
    noteCount.value = Math.max(0, noteCount.value + delta);
};

watch(
    () => props.count,
    (count) => {
        noteCount.value = count;
    },
);

watch(
    () => props.initialOpen,
    (initialOpen) => {
        open.value = initialOpen;
    },
);
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                size="icon"
                class="size-10 data-[state=open]:bg-accent"
                :aria-label="$t('notes.title')"
                :aria-expanded="open"
                :data-testid="`post-notes-trigger-${postId}`"
            >
                <IconMessageCircleFilled
                    v-if="noteCount > 0"
                    class="size-4 text-foreground"
                    :data-testid="`post-notes-filled-icon-${postId}`"
                />
                <IconMessageCircle
                    v-else
                    class="size-4 text-muted-foreground"
                    :data-testid="`post-notes-outline-icon-${postId}`"
                />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            class="flex w-[min(23.75rem,calc(100vw-2rem))] flex-col p-0"
            data-testid="post-notes-popover"
        >
            <div
                class="flex items-center gap-2 border-b border-border py-2 ps-4 pe-2"
            >
                <h3 class="text-sm font-emphasis text-foreground">
                    {{ $t('notes.title') }}
                </h3>
                <span
                    v-if="noteCount > 0"
                    class="flex h-5 min-w-5 items-center justify-center rounded-full bg-accent px-1.5 text-xs font-medium text-muted-foreground tabular-nums"
                    data-testid="post-notes-count"
                    >{{ noteCount }}</span
                >
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="ms-auto size-8 text-muted-foreground"
                    :aria-label="$t('common.close')"
                    @click="closePopover"
                >
                    <IconX class="size-4" />
                </Button>
            </div>
            <PostNotesPanel
                v-if="open"
                :post-id="postId"
                :current-user-id="currentUserId"
                :highlight-note-id="highlightNoteId"
                @count-change="changeNoteCount($event)"
            />
        </PopoverContent>
    </Popover>
</template>
