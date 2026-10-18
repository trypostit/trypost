<script setup lang="ts">
import {
    IconChevronDown,
    IconChevronUp,
    IconLayoutSidebarLeftExpand,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import { Button } from '@/components/ui/button';
import type { InboxPostConversation } from '@/types/inbox';

const props = defineProps<{
    conversation: InboxPostConversation;
    hasPrevious: boolean;
    hasNext: boolean;
}>();

const emit = defineEmits<{
    toggle: [];
    previous: [];
    next: [];
}>();

const total = computed(() => props.conversation.inbound_count);

/** The same number the status line says it replied to, so the two never disagree. */
const repliedCount = computed(() =>
    Math.min(
        props.conversation.replied_count ??
            total.value - Math.min(props.conversation.open_count, total.value),
        total.value,
    ),
);

const answeredShare = computed(() =>
    total.value <= 0 ? 100 : Math.round((repliedCount.value / total.value) * 100),
);

const togglePosts = (): void => {
    emit('toggle');
};

const goToPrevious = (): void => {
    emit('previous');
};

const goToNext = (): void => {
    emit('next');
};
</script>

<template>
    <div class="flex h-8 min-w-0 items-center gap-3" data-testid="inbox-post-navigator">
        <InboxTooltip :label="$t('inbox.posts.show')">
            <Button
                variant="outline"
                size="icon"
                class="shrink-0 border-border text-muted-foreground"
                :aria-label="$t('inbox.posts.show')"
                aria-expanded="false"
                data-testid="inbox-posts-toggle"
                @click="togglePosts"
            >
                <IconLayoutSidebarLeftExpand class="size-4 rtl:-scale-x-100" />
            </Button>
        </InboxTooltip>

        <div
            class="flex h-[21px] min-w-0 flex-1 items-center gap-2"
            :aria-description="
                $t('inbox.posts.progress', {
                    percent: String(answeredShare),
                    count: String(conversation.open_count),
                })
            "
        >
            <span
                class="h-[3px] min-w-0 flex-1 overflow-hidden rounded-full bg-secondary"
                aria-hidden="true"
            >
                <span
                    class="block h-full rounded-full bg-primary-text"
                    :style="{ width: `${answeredShare}%` }"
                />
            </span>
            <span
                class="shrink-0 text-sm leading-[21px] text-foreground tabular-nums"
                data-testid="inbox-post-navigator-count"
                >{{ repliedCount
                }}<span class="text-muted-foreground">/{{ total }}</span></span
            >
            <span
                v-if="conversation.open_count > 0"
                class="inline-flex h-[18px] min-w-[18px] shrink-0 items-center justify-center rounded-full bg-primary-subtle px-1 text-sm font-medium text-primary-text tabular-nums"
                :aria-label="
                    $tChoice('inbox.posts.open', conversation.open_count, {
                        count: String(conversation.open_count),
                    })
                "
                >{{ conversation.open_count }}</span
            >
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <InboxTooltip :label="$t('inbox.posts.previous')">
                <Button
                    variant="outline"
                    size="icon"
                    class="rounded-md border-border"
                    :disabled="!hasPrevious"
                    :aria-label="$t('inbox.posts.previous')"
                    data-testid="inbox-post-previous"
                    @click="goToPrevious"
                >
                    <IconChevronUp class="size-4" />
                </Button>
            </InboxTooltip>
            <InboxTooltip :label="$t('inbox.posts.next')">
                <Button
                    variant="outline"
                    size="icon"
                    class="rounded-md border-border"
                    :disabled="!hasNext"
                    :aria-label="$t('inbox.posts.next')"
                    data-testid="inbox-post-next"
                    @click="goToNext"
                >
                    <IconChevronDown class="size-4" />
                </Button>
            </InboxTooltip>
        </div>
    </div>
</template>
