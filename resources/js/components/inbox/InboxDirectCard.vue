<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { directMessagePreviewKey, isOnlyLink } from '@/lib/inbox';
import type { InboxConversation } from '@/types/inbox';

const props = defineProps<{
    conversation: InboxConversation;
    selected: boolean;
}>();

const emit = defineEmits<{
    select: [conversation: InboxConversation];
}>();

const { avatarSrc } = useAvatarFallback();

const platform = computed(() => props.conversation.channel.platform ?? '');

const participantName = computed(
    () =>
        props.conversation.participant.name ||
        props.conversation.participant.username ||
        getPlatformLabel(platform.value),
);

const isAnswered = computed(() => props.conversation.open_count === 0);

const lastMessage = computed(() => props.conversation.last_message ?? null);

const isOwnLastMessage = computed(() => lastMessage.value?.direction === 'outbound');

/** An unsent message never shows what it said, and a lone link is named instead. */
const previewBody = computed(() => {
    const message = lastMessage.value;

    if (!message?.body || message.removed_at || isOnlyLink(message.body)) {
        return null;
    }

    return message.body;
});

const previewKey = computed(() =>
    lastMessage.value ? directMessagePreviewKey(lastMessage.value, platform.value) : 'inbox.direct.attachment',
);

const selectCard = (): void => {
    emit('select', props.conversation);
};
</script>

<template>
    <button
        type="button"
        class="flex w-full flex-col gap-2 rounded-lg border bg-card px-3 py-2 text-start transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
        :class="selected ? 'border-primary-text' : 'border-border hover:border-border-strong'"
        :aria-current="selected ? 'true' : undefined"
        :data-testid="`inbox-dm-card-${conversation.id}`"
        @click="selectCard"
    >
        <span class="flex h-4 w-full min-w-0 items-center gap-1">
            <PlatformLogo
                v-if="platform"
                :platform="platform"
                :size="16"
                class="rounded-md"
                :class="isAnswered ? 'opacity-60 grayscale' : ''"
            />
            <span
                class="truncate text-xs leading-3 font-medium max-md:text-sm max-md:leading-[14px]"
                :class="isAnswered ? 'text-muted-foreground' : 'text-foreground'"
                >{{ conversation.channel.username }}</span
            >
        </span>

        <span class="flex w-full min-w-0 items-center gap-2">
            <ChannelAvatar
                :platform="platform"
                :name="participantName"
                :src="avatarSrc(conversation.participant.avatar_url)"
                :size="32"
                :badge="false"
                :reserve-space="false"
                ring="card"
                :class="isAnswered ? 'opacity-60' : ''"
            />
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="flex min-w-0 items-center gap-2">
                    <span
                        class="min-w-0 flex-1 truncate text-sm leading-[21px] font-semibold max-md:text-base max-md:leading-6"
                        :class="isAnswered ? 'text-muted-foreground' : 'text-foreground'"
                        data-testid="inbox-dm-card-name"
                        >{{ participantName }}</span
                    >
                    <span
                        v-if="conversation.last_message_at"
                        class="shrink-0 text-xs leading-[18px] text-muted-foreground"
                        >{{ date.diffForHumans(conversation.last_message_at) }}</span
                    >
                </span>
                <span class="flex min-w-0 items-center gap-2">
                    <span
                        class="min-w-0 flex-1 truncate text-sm leading-[21px] text-muted-foreground max-md:text-base max-md:leading-6"
                        data-testid="inbox-dm-card-preview"
                    >
                        <template v-if="lastMessage">{{
                            isOwnLastMessage
                                ? $t('inbox.direct.you', { text: previewBody ?? $t(previewKey) })
                                : (previewBody ?? $t(previewKey))
                        }}</template>
                    </span>
                    <IconCheck
                        v-if="isAnswered"
                        class="size-3 shrink-0 text-muted-foreground"
                        :aria-label="$t('inbox.direct.all_answered')"
                        data-testid="inbox-dm-card-answered"
                    />
                    <span
                        v-else
                        class="inline-flex h-[18px] min-w-[18px] shrink-0 items-center justify-center rounded-full px-1 text-xs font-medium tabular-nums max-md:text-sm"
                        :class="selected ? 'bg-primary-subtle text-primary-text' : 'bg-secondary text-foreground'"
                        :aria-label="
                            $tChoice('inbox.direct.open_count', conversation.open_count, {
                                count: String(conversation.open_count),
                            })
                        "
                        data-testid="inbox-dm-card-count"
                        >{{ conversation.open_count }}</span
                    >
                </span>
            </span>
        </span>
    </button>
</template>
