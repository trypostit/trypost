<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { IconArrowUp } from '@tabler/icons-vue';
import { computed, ref, useTemplateRef } from 'vue';

import InboxCommentCard from '@/components/inbox/InboxCommentCard.vue';
import InboxNewActivityPill from '@/components/inbox/InboxNewActivityPill.vue';
import InboxStatusLine from '@/components/inbox/InboxStatusLine.vue';
import InboxSubjectCard from '@/components/inbox/InboxSubjectCard.vue';
import { TableLoadMore } from '@/components/ui/table';
import { isInbound, threadOf } from '@/lib/inbox';
import type { InboxChannel, InboxMessage, InboxPostConversation } from '@/types/inbox';

const props = withDefaults(
    defineProps<{
        conversation: InboxPostConversation;
        threads: InboxMessage[];
        channel: InboxChannel | null;
        canReply: boolean;
        newMessageIds?: string[];
        hasNewComments?: boolean;
        hidesStatusLineOnMobile?: boolean;
    }>(),
    { newMessageIds: () => [], hasNewComments: false, hidesStatusLineOnMobile: false },
);

const emit = defineEmits<{
    showNew: [];
}>();

const topEl = useTemplateRef<HTMLElement>('topEl');

/** Newest thread first, as the server pages them; a reload merges new threads in by id, so the order is kept here. */
const threads = computed(() =>
    props.threads
        .map(threadOf)
        .sort(
            (a, b) =>
                (b.root.platform_created_at ?? '').localeCompare(a.root.platform_created_at ?? '') ||
                b.root.id.localeCompare(a.root.id),
        ),
);
const messages = computed(() => threads.value.flatMap((thread) => thread.messages));
const platform = computed(() => props.conversation.channel.platform ?? '');

/** A post from "Show all posts" nobody has commented on yet. */
const hasNoComments = computed(() => props.conversation.id === null);

const openComments = computed(() =>
    threads.value.flatMap((thread) =>
        thread.messages.filter((message) => isInbound(message) && message.is_open),
    ),
);

const chosenTargetId = ref<string | null>(null);
const hasChosenTarget = ref(false);

const replyTargetId = computed(() => {
    const chosen = chosenTargetId.value;

    if (chosen && messages.value.some((message) => message.id === chosen)) {
        return chosen;
    }

    return openComments.value[0]?.id ?? null;
});

const targetMessage = (message: InboxMessage): void => {
    chosenTargetId.value = message.id;
    hasChosenTarget.value = true;
};

const moveTarget = (direction: -1 | 1): void => {
    const list = openComments.value;

    if (list.length === 0) {
        return;
    }

    const current = list.findIndex((message) => message.id === replyTargetId.value);
    const next = list[current === -1 ? 0 : current + direction];

    if (next) {
        targetMessage(next);
    }
};

/** Threads are newest first, so the divider goes under the last thread holding a comment that just arrived. */
const dividerAfterIndex = computed(() => {
    const fresh = new Set(props.newMessageIds);

    if (fresh.size === 0) {
        return -1;
    }

    const last = threads.value.findLastIndex((thread) =>
        thread.messages.some((message) => fresh.has(message.id)),
    );

    return last < threads.value.length - 1 ? last : -1;
});

const showNewComments = (): void => {
    topEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    emit('showNew');
};

</script>

<template>
    <div
        ref="topEl"
        class="relative mx-auto flex w-full max-w-[725px] flex-col gap-4 pb-10"
        data-testid="inbox-conversation"
    >
        <InboxNewActivityPill
            v-if="hasNewComments"
            :label="$t('inbox.conversation.new_available')"
            test-id="inbox-new-comments-pill"
            @show="showNewComments"
        />

        <InboxSubjectCard :conversation="conversation" />

        <div
            v-if="hasNoComments"
            class="flex flex-col items-center gap-1 px-2 pt-6 text-center"
            data-testid="inbox-post-no-comments"
        >
            <p class="text-base leading-6 font-medium text-foreground">
                {{ $t('inbox.conversation.no_comments_title') }}
            </p>
            <p class="text-sm leading-[21px] text-muted-foreground">
                {{ $t('inbox.conversation.no_comments_description') }}
            </p>
        </div>

        <InboxStatusLine
            v-else-if="conversation.kind !== 'review'"
            :replied-count="conversation.replied_count ?? 0"
            :total-count="conversation.inbound_count"
            :class="hidesStatusLineOnMobile ? 'max-md:hidden' : ''"
        />

        <InfiniteScroll
            v-if="!hasNoComments"
            data="conversationThreads"
            items-element="#inbox-conversation-items"
            :params="{ data: { thread_conversation: conversation.id } }"
            preserve-url
        >
            <div id="inbox-conversation-items" class="flex flex-col gap-3">
                <template v-for="(thread, index) in threads" :key="thread.root.id">
                    <InboxCommentCard
                        :thread="thread"
                        :platform="platform"
                        :channel="channel"
                        :can-reply="canReply"
                        :reply-target-id="replyTargetId"
                        :autofocus-reply="hasChosenTarget"
                        :kind="conversation.kind"
                        :rating="conversation.rating"
                        @target="targetMessage"
                        @move="moveTarget"
                    />
                    <div
                        v-if="index === dividerAfterIndex"
                        class="flex h-[18px] items-center gap-1 text-xs leading-[18px] font-medium text-primary-text"
                        role="separator"
                        :aria-label="$t('inbox.conversation.new_divider')"
                        data-testid="inbox-new-comments-divider"
                    >
                        <IconArrowUp class="size-4 shrink-0" aria-hidden="true" />
                        <span class="shrink-0">{{ $t('inbox.conversation.new_divider') }}</span>
                        <span class="ms-1 h-0.5 flex-1 rounded-full bg-primary-text/60" aria-hidden="true" />
                    </div>
                </template>
            </div>
            <template #next="{ loading }">
                <TableLoadMore v-if="loading" />
            </template>
        </InfiniteScroll>
    </div>
</template>
