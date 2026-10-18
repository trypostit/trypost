<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import {
    IconArrowsSort,
    IconLayoutSidebarLeftCollapse,
    IconLoader2,
    IconStack2,
} from '@tabler/icons-vue';
import { computed, useTemplateRef, watch } from 'vue';

import InboxPostCard from '@/components/inbox/InboxPostCard.vue';
import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { TableLoadMore } from '@/components/ui/table';
import { inboxItemKey, isSameInboxItem } from '@/lib/inbox';
import type { InboxPostConversation } from '@/types/inbox';

const props = defineProps<{
    conversations: InboxPostConversation[];
    selected: InboxPostConversation | null;
    hasNewElsewhere: boolean;
    reordering: boolean;
    allPosts: boolean;
    showsHint: boolean;
}>();

const emit = defineEmits<{
    select: [conversation: InboxPostConversation];
    reorder: [];
    toggleAllPosts: [];
    dismissHint: [];
    collapse: [];
}>();

const isHintOpen = computed(() => props.showsHint && props.conversations.length > 1);

const selectConversation = (conversation: InboxPostConversation): void => {
    emit('select', conversation);
};

const reorderList = (): void => {
    emit('reorder');
};

const toggleAllPosts = (): void => {
    emit('toggleAllPosts');
};

const dismissHint = (): void => {
    emit('dismissHint');
};

const collapseList = (): void => {
    emit('collapse');
};

const changeHintOpen = (open: boolean): void => {
    if (!open) {
        dismissHint();
    }
};

const scroller = useTemplateRef<{ fetchNext: (options?: { onSuccess?: () => void }) => void }>('scroller');

/** Loads the next page of posts (the mobile navigator reached the last loaded one). */
const loadMore = (onLoaded: () => void): void => {
    scroller.value?.fetchNext({ onSuccess: onLoaded });
};

const itemsScroller = useTemplateRef<HTMLElement>('itemsScroller');

watch(
    () => props.reordering,
    (reordering, wasReordering) => {
        if (wasReordering && !reordering) {
            itemsScroller.value?.scrollTo({ top: 0 });
        }
    },
);

defineExpose({ loadMore });
</script>

<template>
    <div class="flex min-h-0 flex-col" data-testid="inbox-post-list">
        <div class="flex h-[33px] shrink-0 items-start justify-between gap-2">
            <h2 class="pt-0.5 text-sm leading-[21px] font-medium text-foreground max-md:pt-0 max-md:text-base max-md:leading-6">
                {{ $t('inbox.posts.title') }}
            </h2>
            <div class="flex items-center gap-1">
            <InboxTooltip :label="$t(allPosts ? 'inbox.posts.show_commented' : 'inbox.posts.show_all')">
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-6 rounded-full"
                    :class="allPosts ? 'bg-primary-selected text-primary-text hover:bg-primary-selected' : 'text-muted-foreground'"
                    :aria-label="$t('inbox.posts.show_all')"
                    :aria-pressed="allPosts ? 'true' : 'false'"
                    data-testid="inbox-all-posts"
                    @click="toggleAllPosts"
                >
                    <IconStack2 class="size-4" />
                </Button>
            </InboxTooltip>
            <Popover :open="isHintOpen" @update:open="changeHintOpen">
                <PopoverAnchor as-child>
                    <span class="inline-flex">
                        <InboxTooltip :label="$t('inbox.posts.reorder')">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-6 rounded-full"
                                :class="hasNewElsewhere ? 'text-primary-text' : 'text-muted-foreground'"
                                :aria-label="$t('inbox.posts.reorder')"
                                :data-new="hasNewElsewhere ? 'true' : 'false'"
                                :disabled="reordering"
                                :aria-busy="reordering"
                                data-testid="inbox-reorder"
                                @click="reorderList"
                            >
                                <IconLoader2
                                    v-if="reordering"
                                    class="size-4 animate-spin"
                                    data-testid="inbox-reorder-loading"
                                />
                                <IconArrowsSort v-else class="size-4" />
                                <span
                                    v-if="hasNewElsewhere"
                                    class="absolute end-0 top-0 size-2 rounded-full bg-primary"
                                    aria-hidden="true"
                                />
                            </Button>
                        </InboxTooltip>
                    </span>
                </PopoverAnchor>
                <PopoverContent
                    side="right"
                    align="start"
                    class="w-[300px] rounded-xl p-4"
                    data-testid="inbox-reorder-hint"
                >
                    <h4 class="text-base leading-5 font-medium text-foreground">
                        {{ $t('inbox.hint.title') }}
                    </h4>
                    <p class="mt-2 text-sm leading-[21px] text-muted-foreground">
                        {{ $t('inbox.hint.description') }}
                    </p>
                    <div class="mt-4 flex justify-end gap-2">
                        <Button
                            variant="ghost"
                            data-testid="inbox-reorder-hint-dismiss"
                            @click="dismissHint"
                        >
                            {{ $t('inbox.hint.dismiss') }}
                        </Button>
                        <Button
                            data-testid="inbox-reorder-hint-reorder"
                            @click="reorderList"
                        >
                            {{ $t('inbox.hint.reorder') }}
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
            <InboxTooltip :label="$t('inbox.posts.hide')">
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-6 rounded-full text-muted-foreground md:hidden"
                    :aria-label="$t('inbox.posts.hide')"
                    aria-expanded="true"
                    data-testid="inbox-posts-collapse"
                    @click="collapseList"
                >
                    <IconLayoutSidebarLeftCollapse class="size-4 rtl:-scale-x-100" />
                </Button>
            </InboxTooltip>
            </div>
        </div>

        <div
            v-if="conversations.length === 0"
            class="flex flex-col items-center gap-2 px-2 pt-8 pb-6 text-center"
            data-testid="inbox-posts-empty"
        >
            <p class="text-base leading-6 font-medium text-foreground">
                {{ $t(allPosts ? 'inbox.posts.empty_all_title' : 'inbox.posts.empty_title') }}
            </p>
            <p class="text-xs leading-[18px] text-muted-foreground">
                {{ $t(allPosts ? 'inbox.posts.empty_all_description' : 'inbox.posts.empty_description') }}
            </p>
        </div>

        <div
            v-else
            ref="itemsScroller"
            class="-me-4 min-h-0 flex-1 overflow-y-auto pe-4 pb-4"
            data-testid="inbox-post-items-scroller"
        >
            <InfiniteScroll
                ref="scroller"
                data="conversations"
                items-element="#inbox-post-items"
                preserve-url
            >
                <ul
                    id="inbox-post-items"
                    class="flex flex-col gap-2"
                >
                    <li
                        v-for="conversation in conversations"
                        :key="inboxItemKey(conversation)"
                    >
                        <InboxPostCard
                            :conversation="conversation"
                            :selected="isSameInboxItem(conversation, selected)"
                            @select="selectConversation"
                        />
                    </li>
                </ul>
                <template #next="{ loading }">
                    <TableLoadMore v-if="loading" />
                </template>
            </InfiniteScroll>
        </div>
    </div>
</template>
