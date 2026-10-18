<script setup lang="ts">
import { computed, onMounted, useTemplateRef, watch } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import InboxComposerPill from '@/components/inbox/InboxComposerPill.vue';
import InputError from '@/components/InputError.vue';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { useInboxReplies } from '@/composables/inbox/useInboxReplies';
import { byteLength, characterCount, isBlankText } from '@/lib/characters';
import type { InboxChannel, InboxMessage } from '@/types/inbox';

const props = defineProps<{
    target: InboxMessage;
    channel: InboxChannel | null;
    autofocus?: boolean;
    replacesReply?: boolean;
    initialText?: string | null;
}>();

const emit = defineEmits<{
    move: [direction: -1 | 1];
}>();

const COUNTER_THRESHOLD = 0.9;

const { draftFor, updateDraft, isSending, errorFor, send } = useInboxReplies();

const { avatarSrc } = useAvatarFallback();

const pill = useTemplateRef<InstanceType<typeof InboxComposerPill>>('pill');

const username = computed(() => props.target.author.username ?? '');

const prefill = computed(() => {
    if (props.initialText) {
        return props.initialText;
    }

    return props.channel?.prefills_mention && username.value
        ? `@${username.value} `
        : '';
});

const text = computed({
    get: () => draftFor(props.target.id, prefill.value),
    set: (value: string) => updateDraft(props.target.id, value),
});

const maxLength = computed(() => props.channel?.max_reply_length ?? 0);
const length = computed(() =>
    props.channel?.reply_length_unit === 'bytes'
        ? byteLength(text.value)
        : characterCount(text.value),
);
const isTooLong = computed(() => maxLength.value > 0 && length.value > maxLength.value);
const showsCounter = computed(
    () => maxLength.value > 0 && length.value >= Math.floor(maxLength.value * COUNTER_THRESHOLD),
);
const sending = computed(() => isSending(props.target.id));
const error = computed(() => errorFor(props.target.id));
/** Only the prefilled @mention is not a reply yet; a rejected review reply may go again as it is. */
const isOnlyMention = computed(
    () => !props.initialText && prefill.value !== '' && text.value.trim() === prefill.value.trim(),
);
const canSend = computed(() => !isBlankText(text.value) && !isOnlyMention.value && !isTooLong.value);

const focusEditor = (): void => {
    pill.value?.focusAtEnd();
};

onMounted(() => {
    if (props.autofocus) {
        focusEditor();
    }
});

watch(
    () => props.target.id,
    () => {
        if (props.autofocus) {
            focusEditor();
        }
    },
);

const sendReply = (): void => {
    if (!canSend.value || sending.value) {
        return;
    }

    send(props.target.id, prefill.value);
};

const handleKeydown = (event: KeyboardEvent): void => {
    const element = event.target as HTMLTextAreaElement;

    if (event.key === 'ArrowUp' && element.selectionStart === 0 && element.selectionEnd === 0) {
        event.preventDefault();
        emit('move', -1);

        return;
    }

    if (
        event.key === 'ArrowDown' &&
        element.selectionStart === element.value.length &&
        element.selectionEnd === element.value.length
    ) {
        event.preventDefault();
        emit('move', 1);
    }
};
</script>

<template>
    <div
        class="flex min-w-0 flex-col gap-1.5"
        data-testid="inbox-reply-box"
        @click.stop
    >
        <p
            v-if="username"
            class="ms-10 flex min-w-0 items-center text-xs leading-[18px] text-muted-foreground max-md:text-sm max-md:leading-[21px]"
            data-testid="inbox-replying-to"
        >
            <span class="truncate">{{
                $t('inbox.reply.replying_to', { username: `@${username}` })
            }}</span>
        </p>
        <div class="flex min-w-0 items-end gap-3">
            <ChannelAvatar
                v-if="channel"
                :platform="channel.platform"
                :name="channel.display_name || channel.username"
                :src="avatarSrc(channel.avatar_url)"
                :size="28"
                :badge="false"
                :reserve-space="false"
                class="mb-[5px] shrink-0"
            />
            <InboxComposerPill
                ref="pill"
                v-model="text"
                :placeholder="$t('inbox.reply.placeholder')"
                :send-label="$t('inbox.reply.send')"
                :person="target.author"
                test-id-prefix="inbox-reply"
                :can-send="canSend"
                :sending="sending"
                :invalid="Boolean(error) || isTooLong"
                :counter="
                    showsCounter
                        ? channel?.reply_length_unit === 'bytes'
                            ? $t('inbox.counter.bytes', { length: String(length), max: String(maxLength) })
                            : `${length}/${maxLength}`
                        : null
                "
                :counter-invalid="isTooLong"
                @send="sendReply"
                @keydown="handleKeydown"
            />
        </div>
        <p
            v-if="replacesReply"
            class="ms-10 text-xs leading-[18px] text-muted-foreground"
            data-testid="inbox-review-replaces-reply"
        >
            {{ $t('inbox.review.replaces_reply') }}
        </p>
        <InputError :message="error" class="ms-10" />
    </div>
</template>
