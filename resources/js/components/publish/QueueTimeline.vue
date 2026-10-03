<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref } from 'vue';

import QueueDayGroup from '@/components/publish/QueueDayGroup.vue';
import {
    useSortableQueue,
    type QueueSlotTarget,
    type SortableQueueItem,
} from '@/composables/useSortableQueue';
import dayjs from '@/dayjs';
import { PostStatus, ScheduleMode } from '@/types/post';
import type {
    PostCard,
    PostCardMove,
    PublishSocialAccount,
    QueueDay,
    QueuePostPosition,
} from '@/types/publish';

const props = defineProps<{
    days: QueueDay[];
    posts: Record<string, PostCard>;
    queueDays: number;
    canLoadMoreTimes: boolean;
    channels: Record<string, PublishSocialAccount>;
    displayTimezone: string;
    reorderable: boolean;
    slotDrop: boolean;
}>();

const emit = defineEmits<{
    reorder: [channelId: string, orderedPostIds: string[]];
    moveTop: [post: PostCard];
    moveToSlot: [postId: string, slot: QueueSlotTarget];
}>();

const MORE_DAYS = 14;
const IMMINENCE_TICK_MS = 30_000;

const now = ref(dayjs());
const imminenceTimer = window.setInterval(() => {
    now.value = dayjs();
}, IMMINENCE_TICK_MS);

onBeforeUnmount(() => window.clearInterval(imminenceTimer));

const { register, registerSlot, dropIndicator, slotDropKey } =
    useSortableQueue({
        onReorder: (channelId, orderedPostIds) =>
            emit('reorder', channelId, orderedPostIds),
        onMoveToSlot: (postId, slot) => emit('moveToSlot', postId, slot),
    });

const channelQueues = computed<Record<string, string[]>>(() => {
    const movableAfter = now.value.add(1, 'minute');
    const queues: Record<string, string[]> = {};

    for (const item of props.days.flatMap((day) => day.items)) {
        const post = item.post_id ? props.posts[item.post_id] : undefined;

        if (
            post &&
            post.status === PostStatus.Scheduled &&
            post.schedule_mode === ScheduleMode.Queue &&
            dayjs.utc(item.at).isAfter(movableAfter)
        ) {
            queues[item.channel_id] = [
                ...(queues[item.channel_id] ?? []),
                post.id,
            ];
        }
    }

    return queues;
});

const channelOf = computed<Record<string, string>>(() =>
    Object.fromEntries(
        Object.entries(channelQueues.value).flatMap(([channelId, ids]) =>
            ids.map((id) => [id, channelId]),
        ),
    ),
);

const positions = computed<Record<string, QueuePostPosition>>(() =>
    Object.fromEntries(
        Object.values(channelQueues.value).flatMap((ids) =>
            ids.map((id, index) => [
                id,
                {
                    canMoveUp: props.reorderable && index > 0,
                    canMoveDown: props.reorderable && index < ids.length - 1,
                },
            ]),
        ),
    ),
);

const draggables = computed<Record<string, SortableQueueItem>>(() => {
    if (!props.reorderable) {
        return {};
    }

    const movableAfter = now.value.add(1, 'minute');
    const items: Record<string, SortableQueueItem> = {};

    for (const [channelId, ids] of Object.entries(channelQueues.value)) {
        if (ids.length > 1 || props.slotDrop) {
            for (const postId of ids) {
                items[postId] = { postId, channelId, queued: true };
            }
        }
    }

    if (!props.slotDrop) {
        return items;
    }

    for (const item of props.days.flatMap((day) => day.items)) {
        const post = item.post_id ? props.posts[item.post_id] : undefined;

        if (
            post &&
            !(post.id in items) &&
            post.status === PostStatus.Scheduled &&
            dayjs.utc(item.at).isAfter(movableAfter)
        ) {
            items[post.id] = {
                postId: post.id,
                channelId: item.channel_id,
                queued: false,
            };
        }
    }

    return items;
});

const onMove = (post: PostCard, direction: PostCardMove): void => {
    if (direction === 'top') {
        emit('moveTop', post);
        return;
    }

    const channelId = channelOf.value[post.id];
    const ids = channelId ? [...channelQueues.value[channelId]] : [];
    const index = ids.indexOf(post.id);
    const swapWith = direction === 'up' ? index - 1 : index + 1;

    if (index === -1 || swapWith < 0 || swapWith >= ids.length) {
        return;
    }

    [ids[index], ids[swapWith]] = [ids[swapWith], ids[index]];
    emit('reorder', channelId, ids);
};

const loadMoreTimes = (): void => {
    router.reload({
        data: { queue_days: props.queueDays + MORE_DAYS },
        only: ['queue'],
    });
};
</script>

<template>
    <div class="flex flex-col gap-10">
        <QueueDayGroup
            v-for="day in days"
            :key="day.date"
            :date="day.date"
            :items="day.items"
            :posts="posts"
            :channels="channels"
            :positions="positions"
            :draggables="draggables"
            :display-timezone="displayTimezone"
            :drop-indicator="dropIndicator"
            :slot-drop-key="slotDropKey"
            :slot-drop="slotDrop && reorderable"
            :register="register"
            :register-slot="registerSlot"
            @move="onMove"
        />
        <div
            v-if="canLoadMoreTimes"
            class="-mt-6 flex justify-center p-4"
        >
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-md text-base leading-4 text-muted-foreground transition-control hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                data-testid="queue-more-times"
                @click="loadMoreTimes"
            >
                <IconPlus class="size-4" />
                {{ $t('posts.publish.more_times') }}
            </button>
        </div>
    </div>
</template>
