<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { IconChartBar, IconCircleCheck } from '@tabler/icons-vue';
import { computed, ref, useTemplateRef, watch } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import InboxCommentCard from '@/components/inbox/InboxCommentCard.vue';
import InboxNewActivityPill from '@/components/inbox/InboxNewActivityPill.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { TableLoadMore } from '@/components/ui/table';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { isInbound, threadOf } from '@/lib/inbox';
import type {
    InboxChannel,
    InboxConversation,
    InboxMessage,
} from '@/types/inbox';

const props = withDefaults(
    defineProps<{
        messages: InboxMessage[];
        channels: InboxChannel[];
        canReply: boolean;
        repliedRecentlyCount: number;
        openCount: number;
        mode?: 'comments' | 'mentions';
        reviewsOnly?: boolean;
        platform?: string | null;
        hasNew?: boolean;
        reordering?: boolean;
    }>(),
    { mode: 'comments', reviewsOnly: false, platform: null, hasNew: false, reordering: false },
);

const emit = defineEmits<{
    showNew: [];
}>();

const feedEl = useTemplateRef<HTMLElement>('feedEl');

const showNew = (): void => {
    emit('showNew');
};

watch(
    () => props.reordering,
    (reordering, wasReordering) => {
        if (wasReordering && !reordering) {
            feedEl.value?.scrollIntoView({ block: 'start' });
        }
    },
);

const isMentions = computed(() => props.mode === 'mentions');

const chosenTargetId = ref<string | null>(null);

const threads = computed(() => props.messages.map(threadOf));

const channelFor = (message: InboxMessage): InboxChannel | null =>
    props.channels.find(
        (channel) => channel.id === message.conversation?.channel.id,
    ) ?? null;

const platformFor = (message: InboxMessage): string =>
    message.conversation?.channel.platform ?? '';

/** A run of comments on the same post shows that post once, on its first card; a mention that is the post itself shows no separate post. */
const subjectFor = (index: number): InboxConversation | null => {
    const root = threads.value[index]?.root;
    const conversation = root?.conversation ?? null;
    const previous = threads.value[index - 1]?.root.conversation ?? null;

    if (!conversation || root?.is_subject) {
        return null;
    }

    return conversation.id !== previous?.id ? conversation : null;
};

/** Caught up means nothing open in the tab, not only on the loaded page. */
const isCaughtUp = computed(() => props.openCount === 0);

const openComments = computed(() =>
    threads.value.flatMap((thread) =>
        thread.messages.filter((message) => isInbound(message) && message.is_open),
    ),
);

const replyTargetId = computed(() => {
    const chosen = chosenTargetId.value;

    if (
        chosen &&
        threads.value.some((thread) =>
            thread.messages.some((message) => message.id === chosen),
        )
    ) {
        return chosen;
    }

    return openComments.value[0]?.id ?? null;
});

const targetMessage = (message: InboxMessage): void => {
    chosenTargetId.value = message.id;
};

const moveTarget = (direction: -1 | 1): void => {
    const list = openComments.value;
    const current = list.findIndex((message) => message.id === replyTargetId.value);
    const next = list[current === -1 ? 0 : current + direction];

    if (next) {
        targetMessage(next);
    }
};
</script>

<template>
    <div
        ref="feedEl"
        class="mx-auto flex w-full max-w-[757px] flex-col gap-3 px-4 pt-4 pb-10"
        data-testid="inbox-feed"
    >
        <InboxNewActivityPill
            v-if="hasNew || reordering"
            :label="isMentions ? $t('inbox.new_activity.mentions') : $t('inbox.conversation.new_available')"
            :loading="reordering"
            test-id="inbox-feed-new-pill"
            @show="showNew"
        />
        <EmptyState
            v-if="isCaughtUp"
            :icon="IconCircleCheck"
            :title="$t('inbox.feed.caught_up_title')"
            :description="
                isMentions
                    ? $t('inbox.mentions.caught_up_description')
                    : reviewsOnly
                      ? $t('inbox.feed.caught_up_description_reviews')
                      : $t('inbox.feed.caught_up_description')
            "
            class="flex-none p-6"
            data-testid="inbox-caught-up"
        />

        <p
            v-if="isCaughtUp && !isMentions"
            class="flex items-center justify-between gap-3 px-1 text-xs leading-[18px] text-foreground"
            data-testid="inbox-replied-recently"
        >
            <span class="flex min-w-0 items-center gap-2">
                <IconChartBar class="size-4 shrink-0 text-muted-foreground" />
                <span class="truncate">{{
                    $tChoice(
                        reviewsOnly ? 'inbox.feed.replied_recently_reviews' : 'inbox.feed.replied_recently',
                        repliedRecentlyCount,
                        { count: String(repliedRecentlyCount) },
                    )
                }}</span>
            </span>
            <span
                v-if="platform"
                class="flex shrink-0 items-center gap-1 text-muted-foreground"
                data-testid="inbox-replied-includes"
            >
                <PlatformLogo :platform="platform" :size="14" :title="null" class="shrink-0" />
                {{ $t('inbox.conversation.includes_replies', { network: getPlatformLabel(platform) }) }}
            </span>
        </p>

        <InfiniteScroll data="messages" items-element="#inbox-feed-items" preserve-url>
            <div id="inbox-feed-items" class="flex flex-col gap-3">
                <InboxCommentCard
                    v-for="(thread, index) in threads"
                    :key="thread.root.id"
                    :thread="thread"
                    :platform="platformFor(thread.root)"
                    :channel="channelFor(thread.root)"
                    :can-reply="canReply"
                    :reply-target-id="replyTargetId"
                    :subject="subjectFor(index)"
                    :kind="thread.root.conversation?.kind ?? null"
                    :rating="thread.root.conversation?.rating ?? null"
                    :autofocus-reply="chosenTargetId !== null"
                    @target="targetMessage"
                    @move="moveTarget"
                />
            </div>
            <template #next="{ loading }">
                <TableLoadMore v-if="loading" />
            </template>
        </InfiniteScroll>
    </div>
</template>
