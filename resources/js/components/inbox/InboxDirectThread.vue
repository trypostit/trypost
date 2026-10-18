<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCircleCheck,
    IconCircleCheckFilled,
    IconClock,
    IconExternalLink,
} from '@tabler/icons-vue';
import { useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onMounted, useTemplateRef } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import InboxDirectBubble from '@/components/inbox/InboxDirectBubble.vue';
import InboxDirectComposer from '@/components/inbox/InboxDirectComposer.vue';
import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import { Button } from '@/components/ui/button';
import { TableLoadMore } from '@/components/ui/table';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { useInboxReplies } from '@/composables/inbox/useInboxReplies';
import { useInboxResolutions } from '@/composables/inbox/useInboxResolutions';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import dayjs from '@/dayjs';
import { safeHref } from '@/lib/safeHref';
import type { InboxChannel, InboxConversation, InboxMessage } from '@/types/inbox';

const props = defineProps<{
    conversation: InboxConversation;
    messages: InboxMessage[];
    channel: InboxChannel | null;
    canReply: boolean;
}>();

const emit = defineEmits<{
    back: [];
}>();

const scroller = useTemplateRef<HTMLElement>('scroller');
const items = useTemplateRef<HTMLElement>('items');

const { retry, isSending, errorFor } = useInboxReplies();
const { avatarSrc } = useAvatarFallback();
const { resolveMany, toggleResolved, isPending } = useInboxResolutions();

const platform = computed(() => props.conversation.channel.platform ?? '');
const networkLabel = computed(() => getPlatformLabel(platform.value));
const subjectUrl = computed(() => safeHref(props.conversation.subject.url));

const participantName = computed(
    () => props.conversation.participant.name || props.conversation.participant.username || networkLabel.value,
);

const ourChannel = computed(() => {
    if (!props.channel) {
        return '';
    }

    return props.channel.username ? `@${props.channel.username}` : props.channel.display_name;
});

const person = computed(() =>
    props.conversation.participant.username ? `@${props.conversation.participant.username}` : participantName.value,
);

/** The server pages newest first; the thread reads oldest first. */
const ordered = computed(() =>
    [...props.messages].sort(
        (a, b) =>
            (a.platform_created_at ?? '').localeCompare(b.platform_created_at ?? '') || a.id.localeCompare(b.id),
    ),
);

const days = computed(() => {
    const groups: { day: string; label: string; messages: InboxMessage[] }[] = [];

    ordered.value.forEach((message) => {
        const day = message.platform_created_at ? date.formatDate(message.platform_created_at) : '';
        const last = groups[groups.length - 1];

        if (last && last.day === day) {
            last.messages.push(message);
        } else {
            groups.push({ day, label: day, messages: [message] });
        }
    });

    return groups;
});

const resolvedInbound = computed(() =>
    props.messages.find((message) => message.direction === 'inbound' && message.resolved_at),
);

const isResolved = computed(() => props.conversation.open_count === 0 && Boolean(resolvedInbound.value));

const resolveKey = computed(() => (resolvedInbound.value ? resolvedInbound.value.id : 'bulk-conversation'));

const toggleResolve = (): void => {
    if (isResolved.value && resolvedInbound.value) {
        toggleResolved(resolvedInbound.value);

        return;
    }

    resolveMany('conversation', { conversationId: props.conversation.id });
};

/** Closed means the network's time to answer ran out (or the person never wrote); unavailable means the channel cannot send messages at all. */
const windowState = computed<'none' | 'open' | 'closed' | 'unavailable'>(() => {
    if (props.conversation.can_send_message) {
        return props.conversation.reply_window_ends_at ? 'open' : 'none';
    }

    return props.channel?.is_connected && props.channel.supports_direct_messages && props.channel.has_direct_message_access
        ? 'closed'
        : 'unavailable';
});

const windowHours = computed(() =>
    props.conversation.last_inbound_at && props.conversation.reply_window_ends_at
        ? Math.round(dayjs(props.conversation.reply_window_ends_at).diff(dayjs(props.conversation.last_inbound_at), 'hour'))
        : 24,
);

const retryMessage = (message: InboxMessage): void => {
    retry(message.id);
};

const goBack = (): void => {
    emit('back');
};

const isNearBottom = (): boolean => {
    const element = scroller.value;

    return !element || element.scrollHeight - element.scrollTop - element.clientHeight < 80;
};

/**
 * The thread stays on its newest message while the reader is there: pictures
 * and previews that load after the first paint, or a message that arrives,
 * grow the list and it follows them. Scrolling up to read older ones lets go.
 */
let isPinnedToBottom = true;

const pinToBottom = (): void => {
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight });
};

const trackPinned = (): void => {
    isPinnedToBottom = isNearBottom();
};

const scrollToBottom = async (): Promise<void> => {
    isPinnedToBottom = true;
    await nextTick();
    pinToBottom();
};

useResizeObserver(items, () => {
    if (isPinnedToBottom) {
        pinToBottom();
    }
});

onMounted(scrollToBottom);
</script>

<template>
    <div class="flex h-full min-h-0 w-full min-w-0 flex-1 flex-col" data-testid="inbox-dm-thread">
        <div
            class="flex h-16 min-w-0 shrink-0 items-center gap-3 border-b border-border bg-card px-4 max-md:ps-2 md:px-6"
            data-testid="inbox-dm-header"
        >
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0 md:hidden"
                :aria-label="$t('inbox.direct.back')"
                data-testid="inbox-back"
                @click="goBack"
            >
                <IconArrowLeft class="size-4 rtl:-scale-x-100" />
            </Button>
            <ChannelAvatar
                :platform="platform"
                :name="participantName"
                :src="avatarSrc(conversation.participant.avatar_url)"
                :size="40"
                :reserve-space="false"
                ring="card"
            />
            <div class="flex min-w-0 flex-1 flex-col">
                <span class="truncate text-base leading-6 font-semibold text-foreground" data-testid="inbox-dm-participant">{{
                    participantName
                }}</span>
                <span class="flex min-w-0 items-center gap-1.5 text-sm leading-5 text-muted-foreground">
                    <span v-if="conversation.participant.username" class="truncate">@{{ conversation.participant.username }}</span>
                    <template v-if="ourChannel">
                        <span v-if="conversation.participant.username" class="shrink-0" aria-hidden="true">·</span>
                        <span class="truncate text-xs" data-testid="inbox-dm-via">{{
                            $t('inbox.direct.via_channel', { channel: ourChannel })
                        }}</span>
                    </template>
                </span>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <InboxTooltip
                    v-if="canReply"
                    :label="isResolved ? $t('inbox.direct.unresolve') : $t('inbox.direct.resolve')"
                >
                    <Button
                        variant="outline"
                        class="max-md:size-9 max-md:px-0"
                        :class="isResolved ? 'text-success-text' : ''"
                        :loading="isPending(resolveKey)"
                        :aria-label="isResolved ? $t('inbox.direct.unresolve') : $t('inbox.direct.resolve')"
                        :data-resolved="isResolved ? 'true' : 'false'"
                        data-testid="inbox-dm-resolve"
                        @click="toggleResolve"
                    >
                        <IconCircleCheckFilled v-if="isResolved" class="size-4" />
                        <IconCircleCheck v-else class="size-4 text-muted-foreground" />
                        <span class="max-md:sr-only">{{
                            isResolved ? $t('inbox.comment.resolved') : $t('inbox.comment.resolve')
                        }}</span>
                    </Button>
                </InboxTooltip>
                <InboxTooltip v-if="subjectUrl" :label="$t('inbox.comment.open_on', { network: networkLabel })">
                    <Button
                        as="a"
                        :href="subjectUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        variant="outline"
                        class="max-md:size-9 max-md:px-0"
                        :aria-label="$t('inbox.comment.open_on', { network: networkLabel })"
                        data-testid="inbox-dm-open"
                    >
                        <IconExternalLink class="size-4" />
                        <span class="max-md:sr-only">{{ $t('inbox.comment.open_on', { network: networkLabel }) }}</span>
                    </Button>
                </InboxTooltip>
            </div>
        </div>

        <div
            ref="scroller"
            class="min-h-0 w-full flex-1 overflow-y-auto overscroll-contain"
            data-testid="inbox-dm-messages"
            @scroll.passive="trackPinned"
        >
            <InfiniteScroll
                data="directMessages"
                items-element="#inbox-dm-message-items"
                :params="{ data: { thread_conversation: conversation.id } }"
                reverse
                preserve-url
            >
                <div id="inbox-dm-message-items" ref="items" class="flex flex-col gap-3 px-4 py-4 md:px-6">
                    <template v-for="group in days" :key="group.day">
                        <p class="text-center text-xs leading-[18px] text-muted-foreground" role="separator">
                            {{ group.label }}
                        </p>
                        <InboxDirectBubble
                            v-for="message in group.messages"
                            :key="message.id"
                            :message="message"
                            :platform="platform"
                            :participant-name="participantName"
                            :can-retry="canReply && conversation.can_send_message"
                            :retrying="isSending(message.id)"
                            :retry-error="errorFor(message.id)"
                            @retry="retryMessage"
                        />
                    </template>
                </div>
                <template #next="{ loading }">
                    <TableLoadMore v-if="loading" />
                </template>
            </InfiniteScroll>
        </div>

        <div
            v-if="canReply"
            class="flex max-h-[40%] shrink-0 flex-col gap-2 overflow-y-auto border-t border-border bg-card p-4 max-md:pb-[max(1rem,env(safe-area-inset-bottom))]"
            data-testid="inbox-dm-composer-bar"
        >
            <div
                v-if="windowState === 'closed'"
                class="flex flex-col gap-1"
                data-testid="inbox-dm-window"
                data-state="closed"
            >
                <p class="text-sm leading-[21px] font-medium text-foreground">
                    {{ $t('inbox.direct.window_closed_title', { person }) }}
                </p>
                <p class="text-sm leading-[21px] text-muted-foreground">
                    {{
                        $t('inbox.direct.window_closed_description', {
                            network: networkLabel,
                            window: $tChoice('inbox.errors.window_hours', windowHours, { count: String(windowHours) }),
                        })
                    }}
                </p>
            </div>
            <template v-else-if="windowState !== 'unavailable'">
                <InboxTooltip
                    v-if="windowState === 'open'"
                    :label="
                        $t('inbox.direct.window_tooltip', {
                            network: networkLabel,
                            window: $tChoice('inbox.errors.window_hours', windowHours, { count: String(windowHours) }),
                        })
                    "
                    side="top"
                >
                    <p
                        class="ms-10 inline-flex w-fit items-center gap-1 text-xs leading-[18px] text-muted-foreground"
                        tabindex="0"
                        data-testid="inbox-dm-window"
                        data-state="open"
                    >
                        <IconClock class="size-3.5 shrink-0" aria-hidden="true" />
                        {{ $t('inbox.direct.window_open', { time: date.formatMonthDayTime(conversation.reply_window_ends_at) }) }}
                    </p>
                </InboxTooltip>
                <InboxDirectComposer :conversation="conversation" :channel="channel" @sent="scrollToBottom" />
            </template>
        </div>
    </div>
</template>
