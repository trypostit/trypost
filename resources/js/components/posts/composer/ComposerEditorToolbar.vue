<script setup lang="ts">
import { IconHash, IconMoodSmile, IconPlus } from '@tabler/icons-vue';
import { ref } from 'vue';

import EmojiPicker from '@/components/posts/EmojiPicker.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

defineProps<{
    testIdPrefix: string;
}>();

const emit = defineEmits<{
    (event: 'add-media'): void;
    (event: 'select-emoji', emoji: string): void;
    (event: 'open-signatures'): void;
}>();

const emojiOpen = ref(false);

const selectEmoji = (emoji: string): void => {
    emit('select-emoji', emoji);
    emojiOpen.value = false;
};
</script>

<template>
    <div
        class="flex items-center gap-1 pt-3"
        :data-testid="`${testIdPrefix}-toolbar`"
    >
        <button
            type="button"
            :data-testid="`${testIdPrefix}-add-media`"
            :aria-label="$t('posts.create.steps.media_title')"
            :title="$t('posts.create.steps.media_title')"
            class="flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            @click="emit('add-media')"
        >
            <IconPlus class="size-[18px]" stroke-width="1.8" />
        </button>
        <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
        <Popover v-model:open="emojiOpen">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    :data-testid="`${testIdPrefix}-emoji`"
                    :aria-label="$t('posts.edit.emoji_picker.search')"
                    :title="$t('posts.edit.emoji_picker.search')"
                    class="flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <IconMoodSmile class="size-[18px]" stroke-width="1.8" />
                </button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
                <EmojiPicker @select="selectEmoji" />
            </PopoverContent>
        </Popover>
        <button
            type="button"
            :data-testid="`${testIdPrefix}-signature`"
            :aria-label="$t('posts.edit.signatures')"
            :title="$t('posts.edit.signatures')"
            class="flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            @click="emit('open-signatures')"
        >
            <IconHash class="size-[18px]" stroke-width="1.8" />
        </button>
    </div>
</template>
