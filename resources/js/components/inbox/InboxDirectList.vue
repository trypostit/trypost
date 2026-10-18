<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { useTemplateRef, watch } from 'vue';

import InboxDirectCard from '@/components/inbox/InboxDirectCard.vue';
import InboxNewActivityPill from '@/components/inbox/InboxNewActivityPill.vue';
import { TableLoadMore } from '@/components/ui/table';
import type { InboxConversation } from '@/types/inbox';

const props = withDefaults(
    defineProps<{
        conversations: InboxConversation[];
        selectedId: string | null;
        hasNew?: boolean;
        reordering?: boolean;
    }>(),
    { hasNew: false, reordering: false },
);

const emit = defineEmits<{
    select: [conversation: InboxConversation];
    showNew: [];
}>();

const scroller = useTemplateRef<HTMLElement>('scroller');

const showNew = (): void => {
    emit('showNew');
};

watch(
    () => props.reordering,
    (reordering, wasReordering) => {
        if (wasReordering && !reordering) {
            scroller.value?.scrollTo({ top: 0 });
        }
    },
);

const selectConversation = (conversation: InboxConversation): void => {
    emit('select', conversation);
};
</script>

<template>
    <div class="flex min-h-0 flex-col" data-testid="inbox-dm-list">
        <div class="flex h-[33px] shrink-0 items-start">
            <h2 class="pt-0.5 text-sm leading-[21px] font-medium text-foreground max-md:pt-0 max-md:text-base max-md:leading-6">
                {{ $t('inbox.direct.title') }}
            </h2>
        </div>

        <div ref="scroller" class="relative -me-4 min-h-0 flex-1 overflow-y-auto pe-4 pb-4">
            <InboxNewActivityPill
                v-if="hasNew || reordering"
                class="mb-2"
                :label="$t('inbox.new_activity.messages')"
                :loading="reordering"
                test-id="inbox-dm-new-pill"
                @show="showNew"
            />
            <InfiniteScroll data="conversations" items-element="#inbox-dm-items" preserve-url>
                <ul id="inbox-dm-items" class="flex flex-col gap-2">
                    <li v-for="conversation in conversations" :key="conversation.id">
                        <InboxDirectCard
                            :conversation="conversation"
                            :selected="conversation.id === selectedId"
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
