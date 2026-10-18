<script setup lang="ts">
import { IconArrowUp, IconClipboardText, IconLoader2, IconMoodSmile } from '@tabler/icons-vue';
import { nextTick, ref, useTemplateRef } from 'vue';

import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import SavedReplyPicker from '@/components/inbox/SavedReplyPicker.vue';
import EmojiPicker from '@/components/posts/EmojiPicker.vue';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
} from '@/components/ui/input-group';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { insertAtSelection, type SavedReplyPerson } from '@/lib/savedReplies';

const props = withDefaults(
    defineProps<{
        placeholder: string;
        sendLabel: string;
        person: SavedReplyPerson;
        testIdPrefix: string;
        canSend: boolean;
        sending: boolean;
        invalid?: boolean;
        counter?: string | null;
        counterInvalid?: boolean;
        maxHeightClass?: string;
    }>(),
    { invalid: false, counter: null, counterInvalid: false, maxHeightClass: 'max-h-40' },
);

const emit = defineEmits<{
    send: [];
    keydown: [event: KeyboardEvent];
}>();

const text = defineModel<string>({ required: true });

const textarea = useTemplateRef<HTMLTextAreaElement>('textarea');
const emojiOpen = ref(false);
const pendingCaret = ref<number | null>(null);

const sendText = (): void => {
    if (props.canSend && !props.sending) {
        emit('send');
    }
};

const handleKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        sendText();

        return;
    }

    emit('keydown', event);
};

const placeCaret = (caret: number): void => {
    nextTick(() => {
        const element = textarea.value;

        if (element) {
            element.focus();
            element.setSelectionRange(caret, caret);
        }
    });
};

const focusAtEnd = async (): Promise<void> => {
    await nextTick();
    placeCaret(textarea.value?.value.length ?? 0);
};

const insertText = (insertion: string): number => {
    const result = insertAtSelection(text.value, insertion, textarea.value);

    text.value = result.text;

    return result.caret;
};

const selectEmoji = (emoji: string): void => {
    const caret = insertText(emoji);

    emojiOpen.value = false;
    placeCaret(caret);
};

const insertSavedReply = (content: string): void => {
    pendingCaret.value = insertText(content);
};

const focusAfterSavedReply = (): void => {
    placeCaret(pendingCaret.value ?? text.value.length);
    pendingCaret.value = null;
};

defineExpose({ focusAtEnd });
</script>

<template>
    <InputGroup
        class="min-w-0 flex-1 items-end rounded-[18px] border-transparent bg-muted shadow-none transition-colors hover:border-border-strong has-[[data-slot=input-group-control]:focus-visible]:bg-card"
        :class="invalid ? 'border-destructive hover:border-destructive' : ''"
        :data-testid="`${testIdPrefix}-composer`"
    >
        <textarea
            ref="textarea"
            v-model="text"
            rows="1"
            data-slot="input-group-control"
            class="block min-h-0 w-full min-w-0 flex-1 resize-none bg-transparent py-[7px] ps-4 text-sm leading-5 text-foreground outline-none [field-sizing:content] placeholder:text-muted-foreground max-md:text-base max-md:leading-6"
            :class="maxHeightClass"
            :placeholder="placeholder"
            :aria-label="placeholder"
            :aria-invalid="invalid ? 'true' : undefined"
            :data-testid="`${testIdPrefix}-input`"
            @keydown="handleKeydown"
        />
        <InputGroupAddon align="inline-end" class="gap-1 pb-1.5" :data-testid="`${testIdPrefix}-footer`">
            <span
                v-if="counter"
                class="me-1 text-xs tabular-nums"
                :class="counterInvalid ? 'text-destructive-text' : 'text-muted-foreground'"
                :data-testid="`${testIdPrefix}-counter`"
                >{{ counter }}</span
            >
            <InboxTooltip :label="$t('inbox.reply.emoji')" wrap>
                <Popover v-model:open="emojiOpen">
                    <PopoverTrigger as-child>
                        <InputGroupButton
                            size="icon-xs"
                            class="rounded-full text-muted-foreground hover:text-foreground"
                            :aria-label="$t('inbox.reply.emoji')"
                            :data-testid="`${testIdPrefix}-emoji`"
                        >
                            <IconMoodSmile class="size-4" />
                        </InputGroupButton>
                    </PopoverTrigger>
                    <PopoverContent class="w-auto p-0" align="end">
                        <EmojiPicker @select="selectEmoji" />
                    </PopoverContent>
                </Popover>
            </InboxTooltip>
            <InboxTooltip :label="$t('saved_replies.picker.open')" wrap>
                <SavedReplyPicker
                    :person="person"
                    :test-id-prefix="`${testIdPrefix}-saved-replies`"
                    @insert="insertSavedReply"
                    @restore-focus="focusAfterSavedReply"
                >
                    <template #trigger="{ label }">
                        <InputGroupButton
                            size="icon-xs"
                            class="rounded-full text-muted-foreground hover:text-foreground"
                            :aria-label="label"
                            :data-testid="`${testIdPrefix}-saved-replies`"
                        >
                            <IconClipboardText class="size-4" />
                        </InputGroupButton>
                    </template>
                </SavedReplyPicker>
            </InboxTooltip>
            <InboxTooltip :label="sendLabel" wrap>
                <InputGroupButton
                    variant="default"
                    size="icon-xs"
                    class="me-0.5 rounded-full disabled:bg-transparent disabled:text-muted-foreground disabled:opacity-100 dark:disabled:bg-transparent dark:disabled:text-muted-foreground"
                    :disabled="!canSend || sending"
                    :aria-label="sendLabel"
                    :aria-busy="sending ? 'true' : undefined"
                    :data-testid="`${testIdPrefix}-send`"
                    @click="sendText"
                >
                    <IconLoader2 v-if="sending" class="size-3.5 animate-spin" />
                    <IconArrowUp v-else class="size-3.5" />
                </InputGroupButton>
            </InboxTooltip>
        </InputGroupAddon>
    </InputGroup>
</template>
