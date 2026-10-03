<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';
import { nextTick, ref, watch } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { useXLinkDefuser } from '@/composables/useXLinkDefuser';
import { characterCount } from '@/lib/characters';

const props = withDefaults(
    defineProps<{
        platform: string;
        limit: number;
        disabled?: boolean;
        errors?: Record<number, string>;
    }>(),
    { disabled: false, errors: () => ({}) },
);

const replies = defineModel<string[]>({ required: true });
const active = defineModel<number>('active', { required: true });

const activateReply = (index: number): void => {
    active.value = index;
};

const { contentFor } = useXLinkDefuser();
const editor = ref<HTMLTextAreaElement[]>([]);

const remaining = (reply: string): number =>
    props.limit - characterCount(contentFor(reply, props.platform));

const setReply = (index: number, value: string): void => {
    replies.value = replies.value.map((reply, position) =>
        position === index ? value : reply,
    );
};

const removeReply = (index: number): void => {
    replies.value = replies.value.filter((_, position) => position !== index);
    if (index < active.value) {
        active.value -= 1;
    } else if (index === active.value) {
        active.value = Math.min(index, replies.value.length - 1);
    }
};

watch(
    active,
    async () => {
        await nextTick();
        editor.value[0]?.focus();
    },
    { immediate: true },
);
</script>

<template>
    <ol
        class="-ms-9 flex flex-col gap-4"
        :class="{ 'flex-1': active >= 0 }"
        data-testid="thread-replies"
    >
        <li
            v-for="(reply, index) in replies"
            :key="index"
            class="relative flex items-start gap-3"
            :class="{ 'flex-1': active === index }"
        >
            <span
                aria-hidden="true"
                class="absolute start-[11px] -top-4 h-4 w-0.5 bg-border"
            />
            <PlatformLogo :platform="platform" :size="24" class="shrink-0" />
            <div class="flex min-w-0 flex-1 flex-col gap-1 self-stretch">
                <textarea
                    v-if="active === index"
                    ref="editor"
                    :value="reply"
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
                    class="min-h-40 w-full flex-1 resize-none bg-transparent px-[9px] pt-0.5 pb-1 text-sm outline-none placeholder:text-subtle-foreground"
                    @input="
                        setReply(
                            index,
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
                <button
                    v-else
                    type="button"
                    :data-testid="`thread-reply-collapsed-${index}`"
                    class="min-w-0 truncate px-[9px] text-start text-sm text-muted-foreground"
                    @click="activateReply(index)"
                >
                    {{ reply || $t('posts.form.thread.reply_placeholder') }}
                </button>
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
