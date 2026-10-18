<script setup lang="ts">
import {
    IconCircleCheck,
    IconCircleCheckFilled,
    IconDotsVertical,
    IconExternalLink,
    IconLink,
    IconRefresh,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import InboxCardFooter from '@/components/inbox/InboxCardFooter.vue';
import InboxMessageRow from '@/components/inbox/InboxMessageRow.vue';
import InboxReplyBox from '@/components/inbox/InboxReplyBox.vue';
import InboxSubjectCard from '@/components/inbox/InboxSubjectCard.vue';
import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { useInboxReplies } from '@/composables/inbox/useInboxReplies';
import { useInboxResolutions } from '@/composables/inbox/useInboxResolutions';
import { inboxChannelName, type InboxThread } from '@/lib/inbox';
import { safeHref } from '@/lib/safeHref';
import { copyToClipboard } from '@/lib/utils';
import type {
    InboxChannel,
    InboxConversation,
    InboxConversationKind,
    InboxMessage,
} from '@/types/inbox';

const props = defineProps<{
    thread: InboxThread;
    platform: string;
    channel: InboxChannel | null;
    canReply: boolean;
    replyTargetId: string | null;
    autofocusReply?: boolean;
    subject?: InboxConversation | null;
    kind?: InboxConversationKind | null;
    rating?: number | null;
}>();

const emit = defineEmits<{
    target: [message: InboxMessage];
    move: [direction: -1 | 1];
}>();

const { retry, isSending, errorFor } = useInboxReplies();
const { toggleResolved, isPending } = useInboxResolutions();

const root = computed(() => props.thread.root);
const rootUrl = computed(() => safeHref(root.value.platform_url));
const { avatarSrc } = useAvatarFallback();

const isResolved = computed(() => Boolean(root.value.resolved_at));
const isReview = computed(() => props.kind === 'review');

const kindKey = computed(() =>
    props.kind === 'review' || props.kind === 'mention' ? props.kind : 'comment',
);

const ratingFor = (message: InboxMessage): number | null =>
    isReview.value && message.id === root.value.id ? (props.rating ?? null) : null;

const replacesReply = computed(
    () =>
        isReview.value &&
        props.thread.messages.some(
            (message) =>
                message.direction === 'outbound' &&
                message.review_reply_state !== 'rejected',
        ),
);

const rejectedReply = computed(() =>
    isReview.value
        ? ([...props.thread.messages]
              .reverse()
              .find(
                  (message) =>
                      message.direction === 'outbound' &&
                      message.review_reply_state === 'rejected',
              )?.body ?? null)
        : null,
);

const failedReply = computed(
    () =>
        [...props.thread.messages]
            .reverse()
            .find((message) => message.delivery_status === 'failed') ?? null,
);

const replyTarget = computed(
    () =>
        props.thread.messages.find(
            (message) => message.id === props.replyTargetId,
        ) ?? null,
);

const isMuted = computed(
    () =>
        !replyTarget.value &&
        !props.thread.messages.some((message) => message.is_open),
);

const hasMenu = computed(
    () =>
        Boolean(rootUrl.value) ||
        (props.canReply && failedReply.value !== null),
);

/** Clicking our own reply aims the reply box at the comment it answered. */
const answeredBy = (message: InboxMessage): InboxMessage | null =>
    props.thread.messages.find(
        (candidate) => candidate.id === message.parent_id && candidate.direction === 'inbound',
    ) ?? (root.value.direction === 'inbound' ? root.value : null);

const targetMessage = (message: InboxMessage): void => {
    if (!props.canReply) {
        return;
    }

    const target = message.direction === 'inbound' ? message : answeredBy(message);

    if (target) {
        emit('target', target);
    }
};

const targetRoot = (): void => {
    targetMessage(root.value);
};

const retryReply = (message: InboxMessage): void => {
    retry(message.id);
};

const retryFailedReply = (): void => {
    if (failedReply.value) {
        retryReply(failedReply.value);
    }
};

const toggleResolve = (): void => {
    toggleResolved(root.value);
};

const copyLink = (): void => {
    if (rootUrl.value) {
        copyToClipboard(rootUrl.value);
    }
};

const moveReply = (direction: -1 | 1): void => {
    emit('move', direction);
};
</script>

<template>
    <div class="min-w-0" :data-thread-id="root.id">
        <article
            class="min-w-0 overflow-hidden rounded-xl border bg-card transition-control"
            :class="[
                replyTarget ? 'border-foreground/30 shadow-sm' : 'border-border-strong',
                canReply ? 'cursor-pointer outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring' : '',
            ]"
            :tabindex="canReply ? 0 : undefined"
            :data-muted="isMuted ? 'true' : 'false'"
            data-testid="inbox-comment-card"
            @click="targetRoot"
            @keydown.enter.self.prevent="targetRoot"
        >
            <div
                class="flex flex-col gap-4 px-4 pt-4 pb-2 transition-opacity"
                :class="isMuted ? 'opacity-60' : ''"
            >
                <InboxSubjectCard
                    v-if="subject"
                    :conversation="subject"
                    nested
                />
                <div class="flex flex-col">
                    <div
                        v-for="(message, index) in thread.messages"
                        :key="message.id"
                        @click.stop="targetMessage(message)"
                    >
                        <InboxMessageRow
                            :message="message"
                            :platform="platform"
                            :connected="index < thread.messages.length - 1"
                            :can-reply="canReply"
                            :retrying="isSending(message.id)"
                            :retry-error="errorFor(message.id)"
                            :rating="ratingFor(message)"
                            :hides-actions="message.id === root.id"
                            @retry="retryReply"
                        />
                    </div>
                </div>
                <InboxReplyBox
                    v-if="canReply && replyTarget"
                    :target="replyTarget"
                    :channel="channel"
                    :autofocus="autofocusReply"
                    :replaces-reply="replacesReply"
                    :initial-text="rejectedReply"
                    @move="moveReply"
                />
            </div>
            <InboxCardFooter data-testid="inbox-comment-footer">
                <template v-if="channel" #meta>
                    <span
                        class="inline-flex min-w-0 items-center gap-2 text-sm text-muted-foreground"
                        data-testid="inbox-comment-channel"
                    >
                        <ChannelAvatar
                            :platform="channel.platform"
                            :name="inboxChannelName(channel)"
                            :src="avatarSrc(channel.avatar_url)"
                            :size="20"
                            ring="card"
                        />
                        <span class="truncate">{{ inboxChannelName(channel) }}</span>
                    </span>
                </template>
                <InboxTooltip
                    v-if="canReply"
                    :label="isResolved ? $t('inbox.comment.unresolve') : $t('inbox.comment.resolve')"
                >
                    <Button
                        variant="outline"
                        :class="isResolved ? 'text-success-text' : ''"
                        :loading="isPending(root.id)"
                        :data-resolved="isResolved ? 'true' : 'false'"
                        data-testid="inbox-resolve"
                        @click="toggleResolve"
                    >
                        <IconCircleCheckFilled v-if="isResolved" class="size-4" />
                        <IconCircleCheck v-else class="size-4 text-muted-foreground" />
                        {{ isResolved ? $t('inbox.comment.resolved') : $t('inbox.comment.resolve') }}
                    </Button>
                </InboxTooltip>
                <Button
                    v-if="rootUrl"
                    as="a"
                    :href="rootUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    variant="outline"
                    data-testid="inbox-comment-view"
                >
                    <IconExternalLink class="size-4" />
                    {{ $t(`inbox.comment.view.${kindKey}`) }}
                </Button>
                <DropdownMenu v-if="hasMenu">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="icon"
                            class="data-[state=open]:bg-accent"
                            :aria-label="$t('posts.publish.actions.more')"
                            data-testid="inbox-comment-menu"
                        >
                            <IconDotsVertical class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        data-testid="inbox-comment-menu-content"
                    >
                        <DropdownMenuItem
                            v-if="rootUrl"
                            data-testid="inbox-comment-copy-link"
                            @select="copyLink"
                        >
                            <IconLink class="size-4" />
                            {{ $t('inbox.comment.copy_link') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="canReply && failedReply"
                            data-testid="inbox-comment-retry"
                            @select="retryFailedReply"
                        >
                            <IconRefresh class="size-4" />
                            {{ $t('inbox.comment.try_again') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </InboxCardFooter>
        </article>
    </div>
</template>
