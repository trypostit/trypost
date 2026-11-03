<script setup lang="ts">
import { IconListDetails, IconMoodSmile, IconSignature } from '@tabler/icons-vue';
import { ref } from 'vue';

import MediaSourceMenu from '@/components/posts/composer/MediaSourceMenu.vue';
import EmojiPicker from '@/components/posts/EmojiPicker.vue';
import SignaturePicker from '@/components/signatures/SignaturePicker.vue';
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

withDefaults(
    defineProps<{
        testIdPrefix: string;
        signatures: { id: string; name: string; content: string }[];
        mediaDisabled?: boolean;
        /** Why the media button is off, shown in its tooltip. */
        mediaDisabledReasonKey?: string;
        /** Shows the poll button: an X reply can turn into a poll. */
        pollAvailable?: boolean;
        /** Why the poll button is off (the reply has media), or null when it is on. */
        pollDisabledReasonKey?: string | null;
    }>(),
    {
        mediaDisabled: false,
        mediaDisabledReasonKey: 'posts.composer.media_not_supported',
        pollAvailable: false,
        pollDisabledReasonKey: null,
    },
);

const emit = defineEmits<{
    (event: 'import-started', payload: { importId: string; label: string }): void;
    (event: 'open-unsplash'): void;
    (event: 'upload'): void;
    (event: 'add-poll'): void;
    (event: 'select-emoji', emoji: string): void;
    (
        event: 'select-signature',
        signature: { id: string; name: string; content: string },
    ): void;
    (
        event: 'save-signature',
        signature: { id: string; name: string; content: string },
    ): void;
}>();

const emojiOpen = ref(false);
const signaturesOpen = ref(false);

const selectSignature = (signature: {
    id: string;
    name: string;
    content: string;
}): void => {
    emit('select-signature', signature);
    signaturesOpen.value = false;
};

const requestUpload = (): void => {
    emit('upload');
};

const addPoll = (): void => {
    emit('add-poll');
};

const selectEmoji = (emoji: string): void => {
    emit('select-emoji', emoji);
    emojiOpen.value = false;
};
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <div
            class="-me-3 -mb-2 flex flex-wrap items-center gap-y-1 pt-4"
            :data-testid="`${testIdPrefix}-toolbar`"
        >
            <MediaSourceMenu
                :test-id-prefix="testIdPrefix"
                :disabled="mediaDisabled"
                :disabled-reason="$t(mediaDisabledReasonKey)"
                @import-started="emit('import-started', $event)"
                @open-unsplash="emit('open-unsplash')"
                @upload="requestUpload"
            />
            <template v-if="pollAvailable">
                <Tooltip v-if="pollDisabledReasonKey">
                    <TooltipTrigger as-child>
                        <span
                            class="flex size-8 cursor-not-allowed items-center justify-center text-subtle-foreground"
                            :aria-label="$t(pollDisabledReasonKey)"
                            :data-testid="`${testIdPrefix}-poll-disabled`"
                        >
                            <IconListDetails class="size-4" />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>{{ $t(pollDisabledReasonKey) }}</TooltipContent>
                </Tooltip>
                <Tooltip v-else>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            :data-testid="`${testIdPrefix}-poll`"
                            :aria-label="$t('posts.form.x.poll.add')"
                            class="flex size-8 items-center justify-center rounded-lg text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                            @click="addPoll"
                        >
                            <IconListDetails class="size-4" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent :data-testid="`${testIdPrefix}-poll-tooltip`">
                        {{ $t('posts.form.x.poll.add') }}
                    </TooltipContent>
                </Tooltip>
            </template>
            <span class="mx-1.5 h-6 w-px bg-border" aria-hidden="true" />
            <Tooltip>
                <TooltipTrigger as-child>
                    <span class="inline-flex">
                        <Popover v-model:open="emojiOpen">
                            <PopoverTrigger as-child>
                                <button
                                    type="button"
                                    :data-testid="`${testIdPrefix}-emoji`"
                                    :aria-label="$t('posts.edit.emoji_picker.search')"
                                    class="flex size-8 items-center justify-center rounded-lg text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                                >
                                    <IconMoodSmile class="size-4" />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent class="w-auto p-0" align="start">
                                <EmojiPicker @select="selectEmoji" />
                            </PopoverContent>
                        </Popover>
                    </span>
                </TooltipTrigger>
                <TooltipContent :data-testid="`${testIdPrefix}-emoji-tooltip`">
                    {{ $t('posts.edit.emoji_picker.search') }}
                </TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger as-child>
                    <span class="inline-flex">
                        <Popover v-model:open="signaturesOpen">
                            <PopoverTrigger as-child>
                                <button
                                    type="button"
                                    :data-testid="`${testIdPrefix}-signature`"
                                    :aria-label="$t('posts.edit.signatures')"
                                    class="flex size-8 items-center justify-center rounded-lg text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                                >
                                    <IconSignature class="size-4" />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent class="w-auto p-0" align="start">
                                <SignaturePicker
                                    :signatures="signatures"
                                    @select="selectSignature"
                                    @saved="emit('save-signature', $event)"
                                />
                            </PopoverContent>
                        </Popover>
                    </span>
                </TooltipTrigger>
                <TooltipContent :data-testid="`${testIdPrefix}-signature-tooltip`">
                    {{ $t('posts.edit.signatures') }}
                </TooltipContent>
            </Tooltip>
            <div class="ms-auto flex items-center gap-3 pe-3">
                <slot />
            </div>
        </div>
    </TooltipProvider>
</template>
