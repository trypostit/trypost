<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref } from 'vue';

import PostTimelineCard from '@/components/publish/PostTimelineCard.vue';
import QueueDayGroup from '@/components/publish/QueueDayGroup.vue';
import {
    useSortableQueue,
    type QueueSlotTarget,
    type SortableQueueItem,
} from '@/composables/useSortableQueue';
import dayjs from '@/dayjs';
import { blank, filled } from '@/lib/helpers';
import { PostStatus, ScheduleMode } from '@/types/post';
import type {
    PostCard,
    PostCardMove,
    PublishSocialAccount,
    QueueDay,
    QueueItem,
    QueuePostPosition,
} from '@/types/publish';

const props = defineProps<{
    days: QueueDay[];
    posts: Record<string, PostCard>;
    publishing: PostCard[];
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

type QueueStep =
    | { kind: 'post'; postId: string }
    | { kind: 'slot'; slot: QueueSlotTarget };

interface QueueNeighbours {
    up?: QueueStep;
    down?: QueueStep;
}

const items = computed<QueueItem[]>(() =>
    props.days.flatMap((day) => day.items),
);

const isMovable = (item: QueueItem): boolean =>
    dayjs.utc(item.at).isAfter(now.value.add(1, 'minute'));

const postOf = (item: QueueItem): PostCard | undefined =>
    filled(item.post_id) ? props.posts[item.post_id] : undefined;

const isQueuedPost = (item: QueueItem): boolean => {
    const post = postOf(item);

    return (
        post?.status === PostStatus.Scheduled &&
        post.schedule_mode === ScheduleMode.Queue &&
        isMovable(item)
    );
};

const isFreeSlot = (item: QueueItem): boolean =>
    props.slotDrop &&
    item.type === 'slot' &&
    blank(item.post_id) &&
    isMovable(item);

const toStep = (item: QueueItem): QueueStep | null => {
    if (filled(item.post_id)) {
        return isQueuedPost(item)
            ? { kind: 'post', postId: item.post_id }
            : null;
    }

    return isFreeSlot(item)
        ? { kind: 'slot', slot: { channelId: item.channel_id, at: item.at } }
        : null;
};

const channelSteps = computed<Record<string, QueueStep[]>>(() => {
    const steps: Record<string, QueueStep[]> = {};

    for (const item of items.value) {
        const step = toStep(item);

        if (step) {
            (steps[item.channel_id] ??= []).push(step);
        }
    }

    return steps;
});

const queuedPostIds = (steps: QueueStep[]): string[] =>
    steps.flatMap((step) => (step.kind === 'post' ? [step.postId] : []));

const channelQueues = computed<Record<string, string[]>>(() =>
    Object.fromEntries(
        Object.entries(channelSteps.value).map(([channelId, steps]) => [
            channelId,
            queuedPostIds(steps),
        ]),
    ),
);

const channelOf = computed<Record<string, string>>(() =>
    Object.fromEntries(
        Object.entries(channelQueues.value).flatMap(([channelId, ids]) =>
            ids.map((id) => [id, channelId]),
        ),
    ),
);

const neighbours = computed<Record<string, QueueNeighbours>>(() => {
    const byPost: Record<string, QueueNeighbours> = {};

    for (const steps of Object.values(channelSteps.value)) {
        steps.forEach((step, index) => {
            if (step.kind === 'post') {
                byPost[step.postId] = {
                    up: steps[index - 1],
                    down: steps[index + 1],
                };
            }
        });
    }

    return byPost;
});

const positions = computed<Record<string, QueuePostPosition>>(() => {
    const byPost: Record<string, QueuePostPosition> = {};

    for (const [postId, { up, down }] of Object.entries(neighbours.value)) {
        byPost[postId] = {
            canMoveUp: props.reorderable && filled(up),
            canMoveDown: props.reorderable && filled(down),
        };
    }

    return byPost;
});

const draggables = computed<Record<string, SortableQueueItem>>(() => {
    if (!props.reorderable) {
        return {};
    }

    const draggable: Record<string, SortableQueueItem> = {};

    for (const [channelId, ids] of Object.entries(channelQueues.value)) {
        if (ids.length > 1 || props.slotDrop) {
            for (const postId of ids) {
                draggable[postId] = { postId, channelId, queued: true };
            }
        }
    }

    if (!props.slotDrop) {
        return draggable;
    }

    for (const item of items.value) {
        const post = postOf(item);

        if (
            post &&
            !(post.id in draggable) &&
            post.status === PostStatus.Scheduled &&
            isMovable(item)
        ) {
            draggable[post.id] = {
                postId: post.id,
                channelId: item.channel_id,
                queued: false,
            };
        }
    }

    return draggable;
});

const swapQueuedPosts = (postId: string, otherPostId: string): void => {
    const channelId = channelOf.value[postId];
    const ids = [...channelQueues.value[channelId]];
    const [from, to] = [ids.indexOf(postId), ids.indexOf(otherPostId)];

    [ids[from], ids[to]] = [ids[to], ids[from]];
    emit('reorder', channelId, ids);
};

const onMove = (post: PostCard, direction: PostCardMove): void => {
    if (direction === 'top') {
        emit('moveTop', post);
        return;
    }

    const neighbour = neighbours.value[post.id]?.[direction];

    if (neighbour?.kind === 'slot') {
        emit('moveToSlot', post.id, neighbour.slot);
    } else if (neighbour?.kind === 'post') {
        swapQueuedPosts(post.id, neighbour.postId);
    }
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
        <section
            v-if="publishing.length"
            class="flex flex-col gap-6"
            data-testid="queue-publishing"
        >
            <h2 class="text-base leading-5 font-emphasis text-foreground">
                {{ $t('posts.publish.publishing_now') }}
            </h2>
            <PostTimelineCard
                v-for="post in publishing"
                :key="post.id"
                :post="post"
                tab="queue"
                :display-timezone="displayTimezone"
                :movable="false"
            />
        </section>
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
