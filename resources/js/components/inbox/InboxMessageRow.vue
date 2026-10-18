<script setup lang="ts">
import { IconCheck, IconExternalLink, IconEyeOff, IconLink, IconLoader } from '@tabler/icons-vue';
import { useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import InboxReviewReplyState from '@/components/inbox/InboxReviewReplyState.vue';
import InboxReviewStars from '@/components/inbox/InboxReviewStars.vue';
import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import { Button } from '@/components/ui/button';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { safeHref } from '@/lib/safeHref';
import { copyToClipboard } from '@/lib/utils';
import type { InboxMessage } from '@/types/inbox';

const props = defineProps<{
    message: InboxMessage;
    platform: string;
    connected?: boolean;
    canReply?: boolean;
    retrying?: boolean;
    retryError?: string;
    rating?: number | null;
    hidesActions?: boolean;
}>();

const emit = defineEmits<{
    retry: [message: InboxMessage];
}>();

const bodyEl = useTemplateRef<HTMLElement>('bodyEl');
const isExpanded = ref(false);
const isClamped = ref(false);
const isLinkCopied = ref(false);

const { avatarSrc } = useAvatarFallback();

const COPIED_MS = 2000;
let copiedTimer: number | undefined;

const platformUrl = computed(() => safeHref(props.message.platform_url));

const isOutbound = computed(() => props.message.direction === 'outbound');
const isSending = computed(() => props.message.delivery_status === 'sending');
const isFailed = computed(() => props.message.delivery_status === 'failed');

const isAnonymousReviewer = computed(
    () => Boolean(props.rating) && !props.message.author.name && !props.message.author.username,
);

const authorName = computed(
    () =>
        props.message.author.name ||
        props.message.author.username ||
        getPlatformLabel(props.platform),
);

const networkLabel = computed(() => getPlatformLabel(props.platform));

const measureClamp = async (): Promise<void> => {
    await nextTick();

    if (bodyEl.value && !isExpanded.value) {
        isClamped.value = bodyEl.value.scrollHeight > bodyEl.value.clientHeight + 1;
    }
};

onMounted(measureClamp);
watch(() => props.message.body, measureClamp);
useResizeObserver(bodyEl, measureClamp);

const toggleExpanded = (): void => {
    isExpanded.value = !isExpanded.value;
};

const copyLink = async (): Promise<void> => {
    if (!platformUrl.value) {
        return;
    }

    isLinkCopied.value = await copyToClipboard(platformUrl.value, undefined, {
        showSuccessToast: false,
    });

    window.clearTimeout(copiedTimer);
    copiedTimer = window.setTimeout(() => {
        isLinkCopied.value = false;
    }, COPIED_MS);
};

onBeforeUnmount(() => window.clearTimeout(copiedTimer));

const retryReply = (): void => {
    emit('retry', props.message);
};
</script>

<template>
    <div
        class="group/message relative flex min-w-0 gap-3"
        :class="isSending ? 'opacity-60' : ''"
        :data-testid="isOutbound ? 'inbox-outbound-message' : 'inbox-message'"
        :data-status="message.delivery_status ?? undefined"
        :data-message-id="message.id"
    >
        <div
            class="relative flex shrink-0 flex-col items-center"
            data-testid="inbox-message-avatar"
        >
            <ChannelAvatar
                :platform="platform"
                :name="isAnonymousReviewer ? $t('inbox.review.anonymous') : authorName"
                :src="avatarSrc(message.author.avatar_url)"
                :size="28"
                :badge="!isOutbound"
                :reserve-space="false"
                ring="card"
            />
            <span
                v-if="connected"
                class="mt-1 w-px flex-1 bg-border-strong"
                aria-hidden="true"
            />
        </div>

        <div class="min-w-0 flex-1" :class="connected ? 'pb-4' : ''">
            <div class="flex min-h-7 min-w-0 items-center gap-1.5 max-md:flex-wrap">
                <span
                    class="truncate text-sm leading-[21px] font-semibold text-foreground max-md:text-base max-md:leading-6"
                    data-testid="inbox-message-author"
                    >{{ isAnonymousReviewer ? $t('inbox.review.anonymous') : authorName }}</span
                >
                <InboxTooltip
                    v-if="message.platform_created_at"
                    :label="date.formatDateTime(message.platform_created_at)"
                >
                    <time
                        class="shrink-0 text-xs leading-[18px] text-muted-foreground"
                        :datetime="message.platform_created_at"
                        tabindex="0"
                        data-testid="inbox-message-time"
                        >{{ date.diffForHumans(message.platform_created_at) }}</time
                    >
                </InboxTooltip>
                <span
                    v-if="isOutbound"
                    class="shrink-0 text-xs leading-[18px] text-muted-foreground"
                    data-testid="inbox-message-origin"
                    :data-origin="message.origin"
                    >· {{ $t('inbox.comment.sent_via', { via: message.origin === 'trypost' ? 'TryPost' : networkLabel }) }}</span
                >
                <InboxTooltip
                    v-if="message.hidden_at"
                    :label="$t('inbox.comment.hidden', { network: networkLabel })"
                >
                    <span
                        class="inline-flex shrink-0 text-muted-foreground"
                        tabindex="0"
                        :aria-label="$t('inbox.comment.hidden', { network: networkLabel })"
                        data-testid="inbox-message-hidden"
                    >
                        <IconEyeOff class="size-3.5" />
                    </span>
                </InboxTooltip>
                <InboxReviewReplyState
                    v-if="isOutbound && message.review_reply_state"
                    :state="message.review_reply_state"
                />
                <IconLoader
                    v-if="isSending"
                    class="size-3.5 shrink-0 animate-spin text-muted-foreground"
                    :aria-label="$t('inbox.comment.sending')"
                    data-testid="inbox-message-sending"
                />
                <span
                    v-if="platformUrl && !hidesActions"
                    class="ms-auto flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-focus-within/message:opacity-100 group-hover/message:opacity-100 [@media(hover:none)]:opacity-100"
                    data-testid="inbox-message-actions"
                >
                    <InboxTooltip :label="$t('inbox.comment.view.comment')">
                    <a
                        :href="platformUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-accent hover:text-foreground"
                        :aria-label="$t('inbox.comment.view.comment')"
                        data-testid="inbox-message-open"
                        @click.stop
                    >
                        <IconExternalLink class="size-4" />
                    </a>
                    </InboxTooltip>
                    <InboxTooltip :label="$t('inbox.comment.copy_link')">
                    <button
                        type="button"
                        class="inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-accent hover:text-foreground"
                        :aria-label="$t('inbox.comment.copy_link')"
                        data-testid="inbox-message-copy-link"
                        :data-copied="isLinkCopied ? 'true' : 'false'"
                        @click.stop="copyLink"
                    >
                        <IconCheck v-if="isLinkCopied" class="size-4" />
                        <IconLink v-else class="size-4" />
                    </button>
                    </InboxTooltip>
                </span>
            </div>

            <div v-if="rating" class="mt-1 flex h-[14px] items-center">
                <InboxReviewStars :rating="rating" />
            </div>

            <p
                v-if="message.body"
                ref="bodyEl"
                data-testid="inbox-message-body"
                class="text-sm leading-[21px] break-words whitespace-pre-line text-foreground max-md:text-base max-md:leading-6"
                :class="[rating ? 'mt-1.5' : 'mt-0.5', isExpanded ? '' : 'line-clamp-3']"
            >
                {{ message.body }}
            </p>
            <button
                v-if="isClamped || isExpanded"
                type="button"
                class="mt-2 text-xs font-medium text-foreground hover:underline max-md:text-sm"
                data-testid="inbox-message-see-more"
                @click.stop="toggleExpanded"
            >
                {{
                    isExpanded
                        ? $t('inbox.comment.see_less')
                        : $t('inbox.comment.see_more')
                }}
            </button>

            <p
                v-if="isOutbound && message.rejection_reason"
                class="mt-2 text-xs leading-[18px] text-destructive-text"
                data-testid="inbox-review-rejected-reason"
            >
                {{ message.rejection_reason }}
            </p>

            <div
                v-if="isFailed"
                class="mt-2 flex flex-wrap items-center gap-2 text-xs leading-[18px] text-destructive-text"
                data-testid="inbox-message-failed"
            >
                <span data-testid="inbox-message-failed-reason">{{
                    retryError ||
                    message.delivery_error ||
                    $t('inbox.comment.failed', { network: networkLabel })
                }}</span>
                <Button
                    v-if="canReply"
                    variant="outline"
                    size="sm"
                    :loading="retrying"
                    data-testid="inbox-message-retry"
                    @click.stop="retryReply"
                >
                    {{ $t('inbox.comment.try_again') }}
                </Button>
            </div>
        </div>
    </div>
</template>
