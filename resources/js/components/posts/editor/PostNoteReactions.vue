<script setup lang="ts">
import { IconMoodPlus } from '@tabler/icons-vue';
import { ref } from 'vue';

import EmojiPicker from '@/components/posts/EmojiPicker.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { blank, transform } from '@/lib/helpers';
import type { PostNoteReaction } from '@/types/post-note';

/**
 * A note's reactions as chips (emoji and count, who reacted on hover);
 * clicking a chip adds or takes back the viewer's reaction, and the smiley
 * opens the emoji picker.
 */
const props = defineProps<{
    reactions: PostNoteReaction[];
    currentUserId: string;
}>();

const emit = defineEmits<{
    toggle: [emoji: string, reacted: boolean];
}>();

const pickerOpen = ref(false);

const hasReacted = (reaction: PostNoteReaction) =>
    reaction.users.some((user) => user.id === props.currentUserId);

const reactorNames = (reaction: PostNoteReaction) =>
    reaction.users.map((user) => user.name).join(', ');

const toggle = (reaction: PostNoteReaction) => {
    emit('toggle', reaction.emoji, hasReacted(reaction));
};

const pick = (emoji: string) => {
    pickerOpen.value = false;

    const existing = props.reactions.find(
        (reaction) => reaction.emoji === emoji,
    );

    emit('toggle', emoji, transform(existing, hasReacted, false));
};
</script>

<template>
    <div
        class="mt-1 flex flex-wrap items-center gap-1"
        data-testid="note-reactions"
    >
        <TooltipProvider :delay-duration="300">
            <Tooltip v-for="reaction in reactions" :key="reaction.emoji">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="inline-flex h-6 items-center gap-1 rounded-full border px-2 text-xs transition-colors"
                        :class="
                            hasReacted(reaction)
                                ? 'border-primary bg-primary-subtle text-foreground'
                                : 'border-border bg-card text-muted-foreground hover:border-border-strong'
                        "
                        :aria-pressed="hasReacted(reaction)"
                        :aria-label="`${reaction.emoji} ${reactorNames(reaction)}`"
                        :data-testid="`note-reaction-${reaction.emoji}`"
                        @click="toggle(reaction)"
                    >
                        <span class="text-sm leading-none">{{
                            reaction.emoji
                        }}</span>
                        <span class="tabular-nums">{{ reaction.count }}</span>
                    </button>
                </TooltipTrigger>
                <TooltipContent>{{ reactorNames(reaction) }}</TooltipContent>
            </Tooltip>
        </TooltipProvider>

        <Popover v-model:open="pickerOpen">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="inline-flex size-6 items-center justify-center rounded-full text-muted-foreground transition-[color,opacity] hover:bg-muted hover:text-foreground data-[state=open]:opacity-100"
                    :class="{
                        'lg:opacity-0 lg:group-focus-within/note:opacity-100 lg:group-hover/note:opacity-100':
                            blank(reactions),
                    }"
                    :aria-label="$t('notes.react')"
                    data-testid="note-react"
                >
                    <IconMoodPlus class="size-4" />
                </button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
                <EmojiPicker @select="pick" />
            </PopoverContent>
        </Popover>
    </div>
</template>
