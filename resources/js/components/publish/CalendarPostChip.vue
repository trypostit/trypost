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
import { Presence } from 'reka-ui';
import { computed, ref, watch, type Component } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import PostTimelineCard from '@/components/publish/PostTimelineCard.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    HINT_ID_PREFIX,
    useCalendarDragContext,
} from '@/composables/calendar/useCalendarDrag';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
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

const open = ref(false);
const detailsOpen = ref(false);
const isPhone = useBelowBreakpoint('sm');
const card = ref<InstanceType<typeof PostTimelineCard> | null>(null);

const onOpenChange = (value: boolean): void => {
    if (value && isPhone.value) {
        card.value?.openDetails();

        return;
    }

    open.value = value;
};

watch(
    () => [props.post.status, props.post.calendar_at, props.post.schedule_mode],
    () => {
        open.value = false;
    },
);

const onDetails = (value: boolean): void => {
    detailsOpen.value = value;

    if (value) {
        open.value = false;
    }
};

const SENT_STATUSES: readonly string[] = [
    PostStatus.Published,
    PostStatus.Failed,
];

const STATUS_CLASSES: Record<string, string> = {
    failed: 'border-destructive/40 bg-destructive/5',
};

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

const focusPopover = (event: Event): void => {
    event.preventDefault();
    (event.target as HTMLElement).focus();
};

type StatusKey =
    | 'draft'
    | 'pending_approval'
    | 'queue'
    | 'custom'
    | 'recurring'
    | 'publishing'
    | 'published'
    | 'failed';

const STATUS_MARKERS: Record<StatusKey, { icon: Component; tone: string }> = {
    draft: { icon: IconPencil, tone: 'text-muted-foreground' },
    pending_approval: { icon: IconHourglass, tone: 'text-warning' },
    queue: { icon: IconListNumbers, tone: 'text-muted-foreground' },
    custom: { icon: IconPin, tone: 'text-muted-foreground' },
    recurring: { icon: IconRepeat, tone: 'text-muted-foreground' },
    publishing: { icon: IconSend, tone: 'text-muted-foreground' },
    published: { icon: IconCircleCheck, tone: 'text-success' },
    failed: { icon: IconAlertCircle, tone: 'text-destructive' },
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

const statusTestId = computed(() =>
    statusKey.value === 'queue' || statusKey.value === 'custom'
        ? `post-schedule-mode-${props.post.id}`
        : `calendar-post-${statusKey.value.replace('_approval', '')}-${props.post.id}`,
);
</script>

<template>
    <Popover :open="open" @update:open="onOpenChange">
        <Teleport to="body">
            <Presence :present="open">
                <div
                    :data-state="open ? 'open' : 'closed'"
                    class="motion-dialog-overlay fixed inset-0 z-50 bg-black/50"
                    :data-testid="`calendar-post-overlay-${post.id}`"
                    aria-hidden="true"
                />
            </Presence>
        </Teleport>
        <span
            :id="`${HINT_ID_PREFIX}${post.id}`"
            hidden
            :data-testid="`calendar-drag-hint-${post.id}`"
            >{{ $t('calendar.reschedule.drag_hint') }}</span
        >
        <PopoverTrigger as-child>
            <button
                v-calendar-post="post"
                type="button"
                v-bind="$attrs"
                class="flex min-w-0 shrink-0 gap-1.5 rounded-lg border border-border-strong bg-card text-start transition-control hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring data-[state=open]:bg-secondary"
                :class="[
                    isDragging
                        ? 'border-transparent bg-muted *:invisible hover:bg-muted'
                        : (STATUS_CLASSES[post.status] ?? ''),
                    '[&[data-calendar-draggable=true]]:cursor-grab',
                    compact
                        ? 'size-7 items-center justify-center'
                        : 'h-7 items-center px-1',
                ]"
                :aria-label="`${channels} · ${time} · ${$t(`calendar.post_status.${statusKey}`)} · ${text || $t('calendar.no_content')}`"
                :data-dragging="isDragging || undefined"
                :data-testid="`calendar-post-${post.id}`"
            >
                <template v-if="compact">
                    <PlatformBrandIcon
                        v-if="post.platform"
                        :platform="post.platform"
                        colored
                        class="size-4 shrink-0"
                        :data-testid="`calendar-post-platform-${post.id}`"
                    />
                </template>
                <template v-else>
                    <span class="flex min-w-0 flex-1 items-center gap-1">
                        <PlatformBrandIcon
                            v-if="post.platform"
                            :platform="post.platform"
                            colored
                            class="size-4 shrink-0"
                            :data-testid="`calendar-post-platform-${post.id}`"
                        />
                        <span
                            class="shrink-0 text-xs font-medium text-muted-foreground"
                            >{{ time }}</span
                        >
                    </span>
                    <TooltipProvider :delay-duration="200">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span
                                    class="flex shrink-0 items-center"
                                    :class="statusMarker.tone"
                                    :aria-label="
                                        $t(`calendar.post_status.${statusKey}`)
                                    "
                                    :data-testid="statusTestId"
                                    :data-mode="statusKey"
                                >
                                    <component
                                        :is="statusMarker.icon"
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>{{
                                $t(`calendar.post_status.${statusKey}`)
                            }}</TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                    <span
                        v-if="thumbnail"
                        class="relative size-5 shrink-0 overflow-hidden rounded bg-secondary"
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
                </template>
            </button>
        </PopoverTrigger>
        <PopoverContent
            side="right"
            align="start"
            :collision-padding="16"
            class="max-h-(--reka-popover-content-available-height) w-[min(28rem,calc(100vw-2rem))] overflow-y-auto border border-border-strong p-0"
            :class="{ hidden: !open }"
            :force-mount="detailsOpen || isPhone"
            :data-testid="`calendar-post-popover-${post.id}`"
            @open-auto-focus="focusPopover"
        >
            <PostTimelineCard
                ref="card"
                :post="post"
                :tab="tab"
                :display-timezone="timezone"
                :movable="false"
                popover
                @details="onDetails"
            />
        </PopoverContent>
    </Popover>
</template>
