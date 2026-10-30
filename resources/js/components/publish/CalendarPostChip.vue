<script setup lang="ts">
import {
    IconAlertCircle,
    IconCircleCheck,
    IconHourglass,
    IconListNumbers,
    IconPencil,
    IconPin,
    IconPlayerPlayFilled,
    IconRepeat,
    IconSend,
} from '@tabler/icons-vue';
import { computed, nextTick, ref, type Component } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import PostTimelineCard from '@/components/publish/PostTimelineCard.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCalendarDragContext } from '@/composables/calendar/useCalendarDrag';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { isImage, isVideo } from '@/lib/mediaType';
import { htmlToPlainText } from '@/lib/utils';
import { videoFrameUrl } from '@/lib/videoFrame';
import { PostStatus, ScheduleMode } from '@/types/post';
import type { CalendarPost, PublishTab } from '@/types/publish';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    post: CalendarPost;
    timezone: string;
    compact?: boolean;
}>();

const { draggingPostId, vCalendarPost } = useCalendarDragContext();

const isDragging = computed(() => draggingPostId.value === props.post.id);

const card = ref<InstanceType<typeof PostTimelineCard> | null>(null);
const cardMounted = ref(false);

/**
 * A click opens the post's details. The card that holds the details dialog
 * mounts on the first click only, so a busy calendar doesn't mount one per chip.
 */
const openDetails = async (): Promise<void> => {
    cardMounted.value = true;
    await nextTick();
    card.value?.openDetails();
};

const SENT_STATUSES: readonly string[] = [
    PostStatus.Published,
    PostStatus.Failed,
];

const tab = computed((): PublishTab => {
    if (SENT_STATUSES.includes(props.post.status)) {
        return 'sent';
    }

    if (props.post.status === PostStatus.Draft) {
        return 'drafts';
    }

    return props.post.status === PostStatus.PendingApproval
        ? 'approvals'
        : 'queue';
});

const channels = computed(() =>
    props.post.platform
        ? (props.post.social_account?.display_label ??
          getPlatformLabel(props.post.platform))
        : '',
);

const time = computed(() =>
    date.formatTimeInTimezone(props.post.calendar_at, props.timezone),
);

const text = computed(() => htmlToPlainText(props.post.content ?? ''));

const thumbnail = computed(
    () =>
        (props.post.media ?? []).find(
            (item) => isImage(item) || isVideo(item),
        ) ?? null,
);

type StatusKey =
    | 'draft'
    | 'pending_approval'
    | 'queue'
    | 'custom'
    | 'recurring'
    | 'publishing'
    | 'published'
    | 'failed';

interface StatusMarker {
    icon: Component;
    tone: string;
    testId: string;
}

const STATUS_MARKERS: Record<StatusKey, StatusMarker> = {
    draft: { icon: IconPencil, tone: 'text-muted-foreground', testId: 'calendar-post-draft' },
    pending_approval: { icon: IconHourglass, tone: 'text-muted-foreground', testId: 'calendar-post-pending' },
    queue: { icon: IconListNumbers, tone: 'text-muted-foreground', testId: 'post-schedule-mode' },
    custom: { icon: IconPin, tone: 'text-muted-foreground', testId: 'post-schedule-mode' },
    recurring: { icon: IconRepeat, tone: 'text-muted-foreground', testId: 'calendar-post-recurring' },
    publishing: { icon: IconSend, tone: 'text-muted-foreground', testId: 'calendar-post-publishing' },
    published: { icon: IconCircleCheck, tone: 'text-success', testId: 'calendar-post-published' },
    failed: { icon: IconAlertCircle, tone: 'text-destructive', testId: 'calendar-post-failed' },
};

const statusKey = computed((): StatusKey => {
    const { status, schedule_mode, recurrence_frequency } = props.post;

    if (status !== PostStatus.Scheduled) {
        return status as StatusKey;
    }

    if (recurrence_frequency) {
        return 'recurring';
    }

    return schedule_mode === ScheduleMode.Queue ? 'queue' : 'custom';
});

const statusMarker = computed(() => STATUS_MARKERS[statusKey.value]);

const statusLabelKey = computed(
    () => `calendar.post_status.${statusKey.value}`,
);

const chipClass = computed(() => [
    props.compact ? 'justify-center gap-0.5' : 'gap-1.5',
    isDragging.value
        ? 'border-transparent bg-muted *:invisible hover:bg-muted'
        : '',
]);
</script>

<template>
    <div class="contents">
        <button
            v-calendar-post="post"
            type="button"
            v-bind="$attrs"
            class="flex h-7 min-w-0 shrink-0 items-center rounded-lg border border-border-strong bg-card px-1 text-start transition-control hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring [&[data-calendar-draggable=true]]:cursor-grab"
            :class="chipClass"
            :aria-label="
                [
                    channels,
                    time,
                    $t(statusLabelKey),
                    text || $t('calendar.no_content'),
                ].join(' · ')
            "
            :data-dragging="isDragging || undefined"
            :data-testid="`calendar-post-${post.id}`"
            @click="openDetails"
        >
            <span
                class="flex min-w-0 items-center gap-1"
                :class="{ 'flex-1': !compact }"
            >
                <PlatformBrandIcon
                    v-if="post.platform"
                    :platform="post.platform"
                    colored
                    class="size-4 shrink-0"
                    :data-testid="`calendar-post-platform-${post.id}`"
                />
                <span
                    v-if="!compact"
                    class="min-w-0 truncate text-xs font-medium text-muted-foreground"
                    >{{ time }}</span
                >
            </span>
            <Tooltip :delay-duration="200">
                <TooltipTrigger as-child>
                    <span
                        class="flex shrink-0 items-center"
                        :class="statusMarker.tone"
                        :aria-label="$t(statusLabelKey)"
                        :data-testid="`${statusMarker.testId}-${post.id}`"
                        :data-mode="statusKey"
                    >
                        <component
                            :is="statusMarker.icon"
                            :class="compact ? 'size-3' : 'size-3.5'"
                            aria-hidden="true"
                        />
                    </span>
                </TooltipTrigger>
                <TooltipContent>{{ $t(statusLabelKey) }}</TooltipContent>
            </Tooltip>
            <span
                v-if="!compact && thumbnail"
                class="relative size-5 shrink-0 overflow-hidden rounded bg-secondary"
                :data-testid="`calendar-post-thumbnail-${post.id}`"
            >
                <img
                    v-if="isImage(thumbnail)"
                    :src="thumbnail.url"
                    alt=""
                    loading="lazy"
                    class="size-full object-cover"
                />
                <template v-else>
                    <video
                        :src="videoFrameUrl(thumbnail)"
                        class="size-full object-cover"
                        muted
                        playsinline
                        preload="metadata"
                    />
                    <IconPlayerPlayFilled
                        aria-hidden="true"
                        class="absolute top-1/2 left-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black/60 p-0.5 text-white"
                    />
                </template>
            </span>
        </button>
        <div v-if="cardMounted" hidden>
            <PostTimelineCard
                ref="card"
                :post="post"
                :tab="tab"
                :display-timezone="timezone"
                :movable="false"
            />
        </div>
    </div>
</template>
