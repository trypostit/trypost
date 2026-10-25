<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';
import { nextTick, ref, watch } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { useXLinkDefuser } from '@/composables/useXLinkDefuser';
import { autosizeTextarea } from '@/lib/autosizeTextarea';
import { displayLength } from '@/lib/characters';
import { newThreadReply, type ThreadReply } from '@/lib/threadReplies';

const props = withDefaults(
    defineProps<{
        platform: string;
        limit: number;
        max?: number;
        disabled?: boolean;
        errors?: Record<number, string>;
        /** Id of the element the active post's toolbar is teleported into. */
        toolbarTarget?: string;
    }>(),
    { max: Infinity, disabled: false, errors: () => ({}) },
);

const replies = defineModel<ThreadReply[]>({ required: true });
const active = defineModel<number>('active', { required: true });

const emit = defineEmits<{
    paste: [event: ClipboardEvent, index: number];
}>();

const activateReply = (index: number): void => {
    active.value = index;
};

const { contentFor } = useXLinkDefuser();
const editor = ref<HTMLTextAreaElement[]>([]);

const remaining = (reply: ThreadReply): number =>
    props.limit -
    displayLength(contentFor(reply.text, props.platform));

const isEmpty = (reply: ThreadReply | undefined): boolean =>
    reply !== undefined && reply.text === '' && reply.media.length === 0;

const setReply = (index: number, value: string): void => {
    replies.value = replies.value.map((reply, position) =>
        position === index ? { ...reply, text: value } : reply,
    );
};

const onReplyInput = (event: Event, index: number): void => {
    const textarea = event.target as HTMLTextAreaElement;

    autosizeTextarea(textarea);
    setReply(index, textarea.value);
};

const removeReply = (index: number): void => {
    replies.value = replies.value.filter((_, position) => position !== index);
    if (index < active.value) {
        active.value -= 1;
    } else if (index === active.value) {
        active.value = Math.min(index, replies.value.length - 1);
    }
};

/** Shift+Enter opens the next post right below; Backspace on an empty post folds it back into the one above. */
const onReplyKeydown = (event: KeyboardEvent, index: number): void => {
    const textarea = event.target as HTMLTextAreaElement;
    const collapsed = textarea.selectionStart === textarea.selectionEnd;

    if (
        event.key === 'ArrowDown' &&
        collapsed &&
        textarea.selectionEnd === textarea.value.length &&
        index < replies.value.length - 1
    ) {
        event.preventDefault();
        active.value = index + 1;

        return;
    }

    if (event.key === 'ArrowUp' && collapsed && textarea.selectionStart === 0) {
        event.preventDefault();
        active.value = index - 1;

        return;
    }

    if (event.key === 'Enter' && event.shiftKey && !event.isComposing) {
        event.preventDefault();

        if (replies.value.length >= props.max) {
            return;
        }

        replies.value = [
            ...replies.value.slice(0, index + 1),
            newThreadReply(),
            ...replies.value.slice(index + 1),
        ];
        active.value = index + 1;

        return;
    }

    if (
        event.key === 'Backspace' &&
        isEmpty(replies.value[index]) &&
        !event.repeat
    ) {
        event.preventDefault();
        replies.value = replies.value.filter(
            (_, position) => position !== index,
        );
        active.value = index - 1;
    }
};

/** Moving down lands at the start of the next post, moving up at the end of the previous one. */
watch(
    active,
    async (index, previous) => {
        await nextTick();
        const textarea = editor.value[0];

        if (!textarea) {
            return;
        }

        autosizeTextarea(textarea);
        textarea.focus();
        const caret =
            previous !== undefined && index > previous ? 0 : textarea.value.length;
        textarea.setSelectionRange(caret, caret);
    },
    { immediate: true },
);
</script>

<template>
    <ol class="-ms-9 flex flex-col gap-4" data-testid="thread-replies">
        <li
            v-for="(reply, index) in replies"
            :key="reply.key"
            class="relative flex items-start gap-3"
        >
            <span
                v-if="index < replies.length - 1"
                aria-hidden="true"
                class="absolute start-[11px] top-7 -bottom-3 w-0.5 rounded-full bg-border"
                :data-testid="`thread-connector-${index}`"
            />
            <PlatformLogo :platform="platform" :size="24" class="shrink-0" />
            <div class="flex min-w-0 flex-1 flex-col gap-1 self-stretch">
                <textarea
                    v-if="active === index"
                    ref="editor"
                    :value="reply.text"
                    :data-testid="`thread-reply-${index}`"
                    :placeholder="$t('posts.form.thread.reply_placeholder')"
                    :aria-label="
                        $t('posts.form.thread.reply_label', {
                            number: String(index + 2),
                        })
                    "
                    :aria-invalid="
                        remaining(reply) < 0 || errors[index] ? true : undefined
                    "
                    :disabled="disabled"
                    rows="1"
                    class="w-full resize-none overflow-hidden bg-transparent px-[9px] pt-0.5 pb-1 text-sm outline-none placeholder:text-subtle-foreground"
                    @keydown="onReplyKeydown($event, index)"
                    @input="onReplyInput($event, index)"
                    @paste="emit('paste', $event, index)"
                />
                <button
                    v-else
                    type="button"
                    :data-testid="`thread-reply-collapsed-${index}`"
                    class="min-w-0 px-[9px] text-start text-sm break-words whitespace-pre-line text-muted-foreground"
                    @click="activateReply(index)"
                >
                    {{
                        reply.text ||
                        (reply.media.length
                            ? ''
                            : $t('posts.form.thread.reply_placeholder'))
                    }}
                </button>
                <slot name="media" :reply="reply" :index="index" />
                <div
                    v-if="active === index && toolbarTarget"
                    :id="`${toolbarTarget}-${index}`"
                    class="px-[9px]"
                />
                <p
                    v-if="errors[index]"
                    class="px-[9px] text-sm text-destructive-text"
                    :data-testid="`thread-reply-error-${index}`"
                >
                    {{ errors[index] }}
                </p>
            </div>
            <button
                type="button"
                :data-testid="`thread-reply-remove-${index}`"
                :aria-label="$t('posts.form.thread.remove')"
                :disabled="disabled"
                class="flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-accent"
                @click="removeReply(index)"
            >
                <IconX class="size-4" />
            </button>
        </li>
    </ol>
</template>
