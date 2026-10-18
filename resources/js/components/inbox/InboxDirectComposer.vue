<script setup lang="ts">
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import InboxComposerPill from '@/components/inbox/InboxComposerPill.vue';
import InputError from '@/components/InputError.vue';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { useInboxDirectMessages } from '@/composables/inbox/useInboxDirectMessages';
import { byteLength, characterCount, isBlankText } from '@/lib/characters';
import type { InboxChannel, InboxConversation } from '@/types/inbox';
import { Platform } from '@/types/platform';

const props = defineProps<{
    conversation: InboxConversation;
    channel: InboxChannel | null;
}>();

const emit = defineEmits<{
    sent: [];
}>();

const { draftFor, updateDraft, isSending, errorFor, send } = useInboxDirectMessages();

const { avatarSrc } = useAvatarFallback();

const COUNTER_THRESHOLD = 0.9;

/** A Mastodon message is a private mention, so it starts with the person's handle. */
const prefill = computed(() =>
    props.conversation.channel.platform === Platform.Mastodon && props.conversation.participant.username
        ? `@${props.conversation.participant.username} `
        : '',
);

const text = computed({
    get: () => draftFor(props.conversation.id, prefill.value),
    set: (value: string) => updateDraft(props.conversation.id, value),
});

const maxLength = computed(() => props.channel?.max_message_length ?? 0);
const length = computed(() =>
    props.channel?.message_length_unit === 'bytes' ? byteLength(text.value) : characterCount(text.value),
);
const isTooLong = computed(() => maxLength.value > 0 && length.value > maxLength.value);
const showsCounter = computed(
    () => maxLength.value > 0 && length.value >= Math.floor(maxLength.value * COUNTER_THRESHOLD),
);
const sending = computed(() => isSending(props.conversation.id));
const error = computed(() => errorFor(props.conversation.id));
const canSend = computed(() => !isBlankText(text.value) && text.value.trim() !== prefill.value.trim() && !isTooLong.value);

const notifySent = (): void => {
    emit('sent');
};

const sendMessage = (): void => {
    if (!canSend.value || sending.value) {
        return;
    }

    send(props.conversation.id, prefill.value, notifySent);
};
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1.5" data-testid="inbox-dm-composer">
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
                v-model="text"
                :placeholder="$t('inbox.direct.placeholder')"
                :send-label="$t('inbox.direct.send')"
                :person="conversation.participant"
                test-id-prefix="inbox-dm"
                :can-send="canSend"
                :sending="sending"
                :invalid="Boolean(error) || isTooLong"
                :counter="
                    showsCounter
                        ? channel?.message_length_unit === 'bytes'
                            ? $t('inbox.counter.bytes', { length: String(length), max: String(maxLength) })
                            : `${length}/${maxLength}`
                        : null
                "
                :counter-invalid="isTooLong"
                max-height-class="max-h-[min(15rem,30dvh)]"
                @send="sendMessage"
            />
        </div>
        <InputError :message="error" class="ms-10" />
    </div>
</template>
