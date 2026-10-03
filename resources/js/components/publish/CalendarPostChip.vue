<script setup lang="ts">
import { IconPlayerPlayFilled, IconRepeat } from '@tabler/icons-vue';
import { Presence } from 'reka-ui';
import { computed, ref, watch } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import PostScheduleModeBadge from '@/components/posts/PostScheduleModeBadge.vue';
import PostTimelineCard from '@/components/publish/PostTimelineCard.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { isImage, isVideo } from '@/lib/mediaType';
import { htmlToPlainText } from '@/lib/utils';
import { videoFrameUrl } from '@/lib/videoFrame';
import { PostStatus } from '@/types/post';
import type { CalendarPost, PublishTab } from '@/types/publish';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        post: CalendarPost;
        timezone: string;
        layout?: 'month' | 'week';
    }>(),
    { layout: 'month' },
);

const MAX_ICONS = 4;

const open = ref(false);
const detailsOpen = ref(false);

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
    PostStatus.PartiallyPublished,
    PostStatus.Failed,
];

const STATUS_CLASSES: Record<string, string> = {
    draft: 'border-dashed',
    partially_published: 'border-warning/40 bg-warning/5',
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

const visibleTargets = computed(() =>
    props.post.post_platforms.slice(
        0,
        props.post.post_platforms.length > MAX_ICONS
            ? MAX_ICONS - 1
            : MAX_ICONS,
    ),
);

const hiddenTargets = computed(
    () => props.post.post_platforms.length - visibleTargets.value.length,
);

const channels = computed(() =>
    props.post.post_platforms
        .map(
            (target) =>
                target.social_account?.display_label ??
                getPlatformLabel(target.platform),
        )
        .join(', '),
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

const scheduleMode = computed(() =>
    props.post.status === PostStatus.Scheduled
        ? (props.post.schedule_mode ?? null)
        : null,
);
</script>

<template>
    <Popover v-model:open="open">
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
        <PopoverTrigger as-child>
            <button
                type="button"
                v-bind="$attrs"
                class="flex min-w-0 shrink-0 gap-1.5 rounded-lg border border-border-strong bg-card text-start transition-control hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring data-[state=open]:bg-secondary"
                :class="[
                    STATUS_CLASSES[post.status] ?? '',
                    layout === 'week'
                        ? 'items-start p-1.5'
                        : 'h-7 items-center px-1',
                ]"
                :aria-label="`${channels} · ${time} · ${text || $t('calendar.no_content')}`"
                :data-testid="`calendar-post-${post.id}`"
            >
                <span class="flex min-w-0 flex-1 flex-col gap-1">
                    <span class="flex min-w-0 items-center gap-1">
                        <PlatformBrandIcon
                            v-for="target in visibleTargets"
                            :key="target.id"
                            :platform="target.platform"
                            class="size-4 shrink-0"
                        />
                        <span
                            v-if="hiddenTargets > 0"
                            class="text-xs text-muted-foreground"
                            >+{{ hiddenTargets }}</span
                        >
                        <span
                            class="truncate text-xs font-medium text-muted-foreground"
                            >{{ time }}</span
                        >
                        <IconRepeat
                            v-if="scheduleMode && post.recurrence_frequency"
                            class="size-3 shrink-0 text-muted-foreground"
                            :aria-label="$t('posts.recurrence.marker')"
                            :data-testid="`calendar-post-recurring-${post.id}`"
                        />
                        <PostScheduleModeBadge
                            v-else-if="scheduleMode"
                            :post-id="post.id"
                            :mode="scheduleMode"
                            compact
                            plain
                        />
                    </span>
                    <span
                        v-if="layout === 'week'"
                        class="line-clamp-2 text-xs break-words text-foreground"
                        :class="{ 'text-muted-foreground': !text }"
                        :data-testid="`calendar-post-text-${post.id}`"
                        >{{ text || $t('calendar.no_content') }}</span
                    >
                </span>
                <span
                    v-if="thumbnail"
                    class="relative shrink-0 overflow-hidden rounded bg-secondary"
                    :class="layout === 'week' ? 'size-9' : 'size-5'"
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
        </PopoverTrigger>
        <PopoverContent
            side="right"
            align="start"
            :collision-padding="16"
            class="max-h-(--reka-popover-content-available-height) w-[min(28rem,calc(100vw-2rem))] overflow-y-auto border border-border-strong p-0"
            :class="{ hidden: detailsOpen && !open }"
            :force-mount="detailsOpen"
            :data-testid="`calendar-post-popover-${post.id}`"
            @open-auto-focus="focusPopover"
        >
            <PostTimelineCard
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
