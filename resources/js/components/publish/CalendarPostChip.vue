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
    IconTrashX,
} from '@tabler/icons-vue';
import { computed, type Component } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import PostDetailsDialog from '@/components/publish/PostDetailsDialog.vue';
import PostRecurrenceDialog from '@/components/publish/PostRecurrenceDialog.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCalendarDragContext } from '@/composables/calendar/useCalendarDrag';
import { usePostCardMenuActions } from '@/composables/publish/usePostCardMenuActions';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';
import { isImage } from '@/lib/mediaType';
import { postChannelLabel, postThumbnail } from '@/lib/postCard';
import { isDeletedOnNetwork } from '@/lib/postNetwork';
import { htmlToPlainText } from '@/lib/utils';
import { videoFrameUrl } from '@/lib/videoFrame';
import { PostStatus, ScheduleMode } from '@/types/post';
import type { CalendarPost } from '@/types/publish';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    post: CalendarPost;
    timezone: string;
    compact?: boolean;
}>();

const { draggingPostId, vCalendarPost } = useCalendarDragContext();

const isDragging = computed(() => draggingPostId.value === props.post.id);

const { canCreatePost } = useWorkspaceAbilities();

/**
 * A click opens the post's details. The details dialog (and the recurrence
 * dialog its menu opens) mounts on the first click only, so a busy calendar
 * doesn't mount them per chip.
 */
const {
    dialogsMounted,
    detailsOpen,
    recurrenceOpen,
    recurrenceTarget,
    recurrenceTimezone,
    openDetails,
    edit,
    runPostAction,
} = usePostCardMenuActions(() => props.post, {
    timezone: () => props.timezone,
    lazy: true,
});

const channels = computed(() => postChannelLabel(props.post));

const time = computed(() =>
    date.formatTimeInTimezone(props.post.calendar_at, props.timezone),
);

const text = computed(() => htmlToPlainText(props.post.content ?? ''));

const thumbnail = computed(() => postThumbnail(props.post));

type StatusKey =
    | 'draft'
    | 'pending_approval'
    | 'queue'
    | 'custom'
    | 'recurring'
    | 'publishing'
    | 'published'
    | 'deleted'
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
    deleted: { icon: IconTrashX, tone: 'text-destructive', testId: 'calendar-post-deleted' },
    failed: { icon: IconAlertCircle, tone: 'text-destructive', testId: 'calendar-post-failed' },
};

const statusKey = computed((): StatusKey => {
    if (isDeletedOnNetwork(props.post)) {
        return 'deleted';
    }

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
        <template v-if="canCreatePost && dialogsMounted">
            <PostDetailsDialog
                v-model:open="detailsOpen"
                :post="post"
                :test-key="post.id"
                :timezone="timezone"
                @select="runPostAction"
                @edit="edit"
            />
            <PostRecurrenceDialog
                v-model:open="recurrenceOpen"
                :post="recurrenceTarget"
                :test-key="post.id"
                :timezone="recurrenceTimezone"
            />
        </template>
    </div>
</template>
