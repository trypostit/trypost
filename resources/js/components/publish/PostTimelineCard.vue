<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconArrowsMaximize,
    IconCircleDashedPlus,
    IconEdit,
    IconExternalLink,
    IconGripVertical,
    IconListNumbers,
    IconPencil,
    IconPlayerPlayFilled,
    IconRepeat,
    IconSend,
    IconVideo,
    IconX,
} from '@tabler/icons-vue';
import { type Component, computed, inject, ref, watch } from 'vue';

import { reject as rejectPostRoute } from '@/actions/App/Http/Controllers/App/PostApprovalController';
import { edit as editPostRoute } from '@/actions/App/Http/Controllers/App/PostController';
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import MediaLightbox from '@/components/media/MediaLightbox.vue';
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import PostNotesPopover from '@/components/posts/PostNotesPopover.vue';
import PostScheduleModeBadge from '@/components/posts/PostScheduleModeBadge.vue';
import ApprovePostButton from '@/components/publish/ApprovePostButton.vue';
import PostCardLabels from '@/components/publish/PostCardLabels.vue';
import PostCardMenu from '@/components/publish/PostCardMenu.vue';
import PostDetailsDialog from '@/components/publish/PostDetailsDialog.vue';
import PostFailurePopover from '@/components/publish/PostFailurePopover.vue';
import PostMetricsBand from '@/components/publish/PostMetricsBand.vue';
import PostRecurrenceDialog from '@/components/publish/PostRecurrenceDialog.vue';
import PostRecurrenceSummary from '@/components/publish/PostRecurrenceSummary.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useDisplayTimezone } from '@/composables/useDisplayTimezone';
import {
    getContentTypeBadgeKey,
    getPlatformLabel,
} from '@/composables/usePlatformLogo';
import {
    deletePostCardKey,
    duplicatePostCard,
    editPostCardUrlKey,
    schedulePostCard,
    toastFirstError,
} from '@/composables/usePostCardActions';
import { getPostStatusConfig } from '@/composables/usePostStatus';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';
import dayjs from '@/dayjs';
import { isImage, isVideo } from '@/lib/mediaType';
import { compactPublicationMetrics } from '@/lib/publicationMetrics';
import { recurrenceRuleOf } from '@/lib/recurrence';
import { videoFrameUrl } from '@/lib/videoFrame';
import { Platform } from '@/types/platform';
import {
    PostOrigin,
    PostPlatformStatus,
    PostStatus,
    ScheduleMode,
} from '@/types/post';
import type {
    PostCard,
    PostCardMenuAction,
    PostCardMove,
    PublishTab,
} from '@/types/publish';

const props = withDefaults(
    defineProps<{
        post: PostCard;
        tab: PublishTab;
        displayTimezone: string;
        canMoveUp?: boolean;
        canMoveDown?: boolean;
        draggable?: boolean;
        gutterHandle?: boolean;
        movable?: boolean;
        popover?: boolean;
    }>(),
    {
        canMoveUp: false,
        canMoveDown: false,
        draggable: false,
        gutterHandle: false,
        movable: true,
        popover: false,
    },
);

const emit = defineEmits<{
    move: [direction: PostCardMove];
    details: [open: boolean];
}>();

const MAX_THUMBNAILS = 4;

const page = usePage();
const { canApprove, canCreatePost, canPublishDirectly, requiresApproval } =
    useWorkspaceAbilities();
const deletePost = inject(deletePostCardKey, () => {});
const editUrl = inject(editPostCardUrlKey, (post: PostCard) =>
    editPostRoute.url(post.id),
);
const { timezone, formatTime } = useDisplayTimezone(props.displayTimezone, []);

watch(
    () => props.displayTimezone,
    (value) => {
        timezone.value = value;
    },
);

const testKey = computed(() => props.post.card_key ?? props.post.id);

const authUserId = computed(() => String(page.props.authUserId ?? ''));
const openPostNotesId = computed(
    () => (page.props.openPostNotesId as string | null | undefined) ?? null,
);
const highlightNoteId = computed(
    () => (page.props.highlightNoteId as string | null | undefined) ?? null,
);

const targets = computed(() =>
    props.post.post_platforms.filter((target) => target.enabled),
);
const primaryTarget = computed(() => targets.value[0] ?? null);
const account = computed(() => primaryTarget.value?.social_account ?? null);

const time = computed(() =>
    props.tab === 'sent'
        ? (props.post.published_at ??
          props.post.scheduled_at ??
          props.post.created_at)
        : props.post.scheduled_at,
);

const isPending = computed(
    () => props.post.status === PostStatus.PendingApproval,
);

const isEditable = computed(
    () =>
        props.post.status === PostStatus.Draft ||
        props.post.status === PostStatus.Scheduled ||
        isPending.value,
);

const timePassed = computed(
    () =>
        isPending.value &&
        props.post.schedule_mode !== ScheduleMode.Queue &&
        props.post.scheduled_at !== null &&
        dayjs(props.post.scheduled_at).isBefore(dayjs()),
);

const openPostDetailsId = computed(
    () => (page.props.openPostDetailsId as string | null | undefined) ?? null,
);

const preview = computed(() => props.post.content?.trim() ?? '');

const visuals = computed(() =>
    (props.post.media ?? []).filter((item) => isImage(item) || isVideo(item)),
);

const thumbnails = computed(() => visuals.value.slice(0, MAX_THUMBNAILS));

const hiddenVisuals = computed(() =>
    Math.max(0, visuals.value.length - MAX_THUMBNAILS),
);

const lightboxOpen = ref(false);
const lightboxIndex = ref(0);

const openLightbox = (index: number): void => {
    lightboxIndex.value = index;
    lightboxOpen.value = true;
};

const CONTENT_TYPE_ICONS: Record<string, Component> = {
    instagram_story: IconCircleDashedPlus,
    facebook_story: IconCircleDashedPlus,
    instagram_reel: IconVideo,
    facebook_reel: IconVideo,
};

const contentTypeIcon = computed(() =>
    CONTENT_TYPE_ICONS[primaryTarget.value?.content_type ?? ''] ?? null,
);

const contentTypeKey = computed(() =>
    primaryTarget.value
        ? getContentTypeBadgeKey(
              primaryTarget.value.platform,
              primaryTarget.value.content_type ?? null,
          )
        : null,
);

const canQueue = computed(
    () => account.value?.has_posting_schedule === true,
);

const permalink = computed(() => primaryTarget.value?.platform_url ?? null);

const metricsDetail = computed(() => {
    const detail = primaryTarget.value
        ? props.post.metrics?.[primaryTarget.value.id]
        : null;

    return detail && detail.available ? detail : null;
});

const hasMetrics = computed(() =>
    metricsDetail.value
        ? compactPublicationMetrics(metricsDetail.value).length > 0
        : false,
);

const showStatus = computed(
    () =>
        props.post.status !== PostStatus.Scheduled &&
        props.post.status !== PostStatus.Published &&
        !isPending.value,
);

const hasFailure = computed(
    () =>
        props.post.status === PostStatus.Failed ||
        (props.post.status === PostStatus.PartiallyPublished &&
            targets.value.some(
                (target) =>
                    target.status === PostPlatformStatus.Failed ||
                    target.status === PostPlatformStatus.Rejected,
            )),
);

const APPROVAL_RELOAD = {
    only: ['posts', 'counts', 'queue'],
    reset: ['posts'],
};

const rejectPost = (): void => {
    router.put(
        rejectPostRoute.url(props.post.id),
        {},
        { ...APPROVAL_RELOAD, preserveScroll: true, onError: toastFirstError },
    );
};

const revertRequest = (): void => {
    schedulePostCard(props.post.id, 'draft', APPROVAL_RELOAD);
};

const addToQueue = (): void => {
    schedulePostCard(props.post.id, 'queue_next', {
        requestsApproval: requiresApproval.value,
    });
};

const recurrence = computed(() =>
    props.post.status === PostStatus.Scheduled && props.post.scheduled_at
        ? recurrenceRuleOf(props.post)
        : null,
);

const detailsOpen = ref(openPostDetailsId.value === props.post.id);

const openDetails = (): void => {
    detailsOpen.value = true;
};

const recurrenceOpen = ref(false);

watch(detailsOpen, (open) => emit('details', open));
const recurrencePost = ref<PostCard>(props.post);

const edit = (post: PostCard = props.post): void => {
    router.visit(editUrl(post));
};

const runPostAction = (action: PostCardMenuAction, post: PostCard): void => {
    switch (action) {
        case 'publish_now':
            schedulePostCard(post.id, 'publish_now');
            break;
        case 'duplicate':
            duplicatePostCard(post);
            break;
        case 'recurrence':
            recurrencePost.value = post;
            recurrenceOpen.value = true;
            break;
        case 'move_drafts':
            schedulePostCard(post.id, 'draft');
            break;
        case 'details':
            detailsOpen.value = true;
            break;
        case 'delete':
            deletePost(post);
            break;
    }
};

const onMenuSelect = (action: PostCardMenuAction): void => {
    switch (action) {
        case 'move_top':
            emit('move', 'top');
            break;
        case 'move_up':
            emit('move', 'up');
            break;
        case 'move_down':
            emit('move', 'down');
            break;
        default:
            runPostAction(action, props.post);
    }
};
</script>

<template>
    <div
        :data-testid="`post-card-${testKey}`"
        :data-post-id="post.id"
        class="group/post relative"
        :class="
            popover
                ? 'flex flex-col'
                : 'grid grid-cols-[minmax(0,1fr)_2.5rem] gap-x-3 gap-y-2 md:grid-cols-[71px_minmax(0,1fr)] md:gap-x-8'
        "
    >
        <div
            class="flex flex-wrap items-center gap-2"
            :class="
                popover
                    ? 'border-b border-border-strong px-4 py-3'
                    : 'col-span-2 md:col-span-1 md:flex-col md:flex-nowrap md:items-start'
            "
        >
            <span class="inline-flex items-center gap-1">
                <IconGripVertical
                    v-if="draggable"
                    class="size-4 cursor-grab text-muted-foreground"
                    :class="
                        gutterHandle
                            ? 'shrink-0 transition-opacity active:cursor-grabbing md:absolute md:top-0.5 md:left-[79px] md:opacity-0 md:group-focus-within/post:opacity-100 md:group-hover/post:opacity-100 md:in-data-dragging:opacity-100'
                            : 'md:-ms-5'
                    "
                    :data-testid="`post-drag-handle-${testKey}`"
                    aria-hidden="true"
                />
                <time
                    v-if="time"
                    class="text-sm text-foreground"
                    :class="popover ? 'font-emphasis' : 'font-medium'"
                    :datetime="time"
                    :data-testid="`post-time-${testKey}`"
                >
                    {{
                        popover
                            ? date.formatDateTimeInTimezone(time, timezone)
                            : formatTime(time)
                    }}
                </time>
                <span v-else class="text-sm font-medium text-muted-foreground">
                    {{ $t('posts.publish.no_time') }}
                </span>
            </span>
            <Badge
                v-if="isPending"
                variant="warning"
                class="h-6 gap-1 px-2 [&>svg]:size-4"
                :data-testid="`post-approval-badge-${testKey}`"
            >
                <IconEdit />
                {{ $t('posts.approvals.badge') }}
            </Badge>
            <span
                v-if="timePassed"
                class="text-xs text-destructive-text"
                :data-testid="`post-time-passed-${testKey}`"
            >
                {{ $t('posts.approvals.time_passed') }}
            </span>
            <span
                v-if="recurrence"
                class="inline-flex items-center gap-0.5 text-xs text-muted-foreground"
                :data-testid="`post-recurring-${testKey}`"
            >
                <IconRepeat class="size-3" />
                {{ $t('posts.recurrence.marker') }}
            </span>
            <PostScheduleModeBadge
                v-else-if="
                    post.schedule_mode &&
                    (tab === 'sent' ||
                        (post.status === PostStatus.Scheduled &&
                            post.schedule_mode === ScheduleMode.Custom))
                "
                :post-id="post.id"
                :mode="post.schedule_mode"
                plain
            />
            <PostFailurePopover
                v-if="showStatus && hasFailure"
                :post="post"
                :test-key="testKey"
                :attempted-at="time"
                :timezone="timezone"
            />
            <Badge
                v-else-if="showStatus"
                :variant="getPostStatusConfig(post.status).variant"
                class="h-6 gap-1 px-2 [&>svg]:size-4"
                :data-testid="`post-status-${testKey}`"
            >
                <component :is="getPostStatusConfig(post.status).icon" />
                {{ $t(`posts.status.${post.status}`) }}
            </Badge>
            <Button
                v-if="popover && canCreatePost"
                variant="ghost"
                size="icon"
                class="-my-1 ms-auto -me-2"
                :aria-label="$t('posts.show.title')"
                :data-testid="`post-details-expand-${testKey}`"
                @click="openDetails"
            >
                <IconArrowsMaximize class="size-4" />
            </Button>
        </div>

        <article
            class="min-w-0 overflow-hidden"
            :class="{
                'rounded-xl border border-border-strong bg-card': !popover,
            }"
        >
            <p
                v-if="recurrence && post.scheduled_at"
                class="flex items-start gap-2 border-b border-border-strong bg-secondary px-4 py-2.5 text-sm text-foreground"
                :data-testid="`post-recurrence-banner-${testKey}`"
            >
                <IconRepeat class="mt-0.5 size-4 shrink-0" />
                <PostRecurrenceSummary
                    :scheduled-at="post.scheduled_at"
                    :timezone="timezone"
                    :rule="recurrence"
                    :remaining="recurrence.times"
                    :origin-at="post.recurrence_origin_at"
                />
            </p>
            <div class="relative flex gap-4 p-4 md:gap-6">
                <component
                    :is="isEditable ? Link : 'div'"
                    :href="isEditable ? editUrl(post) : undefined"
                    :draggable="draggable ? 'false' : undefined"
                    class="flex min-w-0 flex-1 flex-col gap-4 outline-none after:absolute after:inset-0 focus-visible:after:outline-2 focus-visible:after:outline-offset-[-2px] focus-visible:after:outline-ring"
                    :data-testid="`post-open-${testKey}`"
                >
                    <div class="flex items-center gap-3">
                        <ChannelAvatar
                            :status="account?.status"
                            :account-id="account?.id"
                            v-if="primaryTarget"
                            :platform="primaryTarget.platform"
                            :src="account?.avatar_url"
                            :name="
                                account?.display_label ??
                                getPlatformLabel(primaryTarget.platform)
                            "
                            ring="card"
                            @dragstart="draggable ? $event.preventDefault() : undefined"
                        />
                        <div class="min-w-0">
                            <p
                                class="truncate text-sm leading-tight font-emphasis text-foreground"
                            >
                                {{
                                    account?.display_label ??
                                    getPlatformLabel(primaryTarget?.platform ?? '')
                                }}
                            </p>
                            <p
                                v-if="popover && account?.handle_label"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ account.handle_label }}
                            </p>
                        </div>
                        <span
                            v-if="targets.length > 1"
                            class="text-xs font-medium text-muted-foreground"
                            >+{{ targets.length - 1 }}</span
                        >
                        <Badge
                            v-if="contentTypeKey"
                            variant="secondary"
                            class="ms-auto h-6 gap-1 px-2"
                            :data-testid="`post-content-type-${testKey}`"
                        >
                            <component
                                :is="contentTypeIcon"
                                v-if="contentTypeIcon"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            {{ $t(contentTypeKey) }}
                        </Badge>
                    </div>
                    <p
                        class="line-clamp-4 text-sm whitespace-pre-line text-foreground"
                        :class="{
                            'text-muted-foreground': !preview,
                            'leading-relaxed': popover,
                        }"
                    >
                        {{ preview || $t('calendar.no_content') }}
                    </p>
                </component>
                <div
                    v-if="thumbnails.length"
                    class="relative z-10 grid w-24 shrink-0 content-start gap-2"
                    :class="[
                        thumbnails.length > 1 ? 'grid-cols-2' : '',
                        popover ? 'w-36' : 'md:w-[180px]',
                    ]"
                >
                    <button
                        v-for="(thumbnail, index) in thumbnails"
                        :key="thumbnail.url"
                        type="button"
                        :aria-label="$t('common.media_lightbox.open')"
                        :draggable="draggable ? 'false' : undefined"
                        class="group/thumbnail relative block aspect-square cursor-zoom-in overflow-hidden rounded-md border border-border-strong bg-secondary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                        :data-testid="`post-thumbnail-${testKey}-${index}`"
                        @click="openLightbox(index)"
                    >
                        <img
                            v-if="isImage(thumbnail)"
                            :draggable="draggable ? 'false' : undefined"
                            :src="thumbnail.url"
                            alt=""
                            class="size-full object-cover"
                            loading="lazy"
                        />
                        <template v-else>
                            <video
                                :src="videoFrameUrl(thumbnail)"
                                class="size-full object-cover"
                                muted
                                playsinline
                                preload="metadata"
                                :data-testid="`post-thumbnail-video-${testKey}-${index}`"
                            />
                            <IconPlayerPlayFilled
                                aria-hidden="true"
                                class="absolute top-1/2 left-1/2 size-8 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white transition-opacity group-hover/thumbnail:opacity-0"
                            />
                        </template>
                        <span
                            aria-hidden="true"
                            class="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover/thumbnail:bg-black/30"
                        >
                            <IconArrowsMaximize
                                class="size-6 text-white opacity-0 drop-shadow transition-opacity group-hover/thumbnail:opacity-100"
                                :data-testid="`post-thumbnail-expand-${testKey}-${index}`"
                            />
                        </span>
                    </button>
                    <button
                        v-if="hiddenVisuals > 0"
                        type="button"
                        :aria-label="$t('common.media_lightbox.open')"
                        class="absolute right-1.5 bottom-1.5 inline-flex size-6 cursor-zoom-in items-center justify-center rounded-full bg-foreground/60 text-xs font-medium text-background transition-colors hover:bg-foreground/80 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                        :data-testid="`post-thumbnail-more-${testKey}`"
                        @click="openLightbox(MAX_THUMBNAILS)"
                    >
                        +{{ hiddenVisuals }}
                    </button>
                </div>
                <MediaLightbox
                    v-model:open="lightboxOpen"
                    :items="visuals"
                    :start-index="lightboxIndex"
                />
            </div>

            <PostCardLabels
                class="-mt-1 px-4 pb-4"
                :post-id="post.id"
                :labels="post.labels ?? []"
                :test-key="testKey"
            />

            <PostMetricsBand
                v-if="tab === 'sent' && metricsDetail && hasMetrics"
                :detail="metricsDetail"
                :channel-id="account?.id ?? null"
                :metrics-test-id="`post-metrics-${testKey}`"
                :insights-test-id="`post-insights-${testKey}`"
            />

            <div
                class="flex min-h-14 flex-wrap items-center gap-x-4 gap-y-2 border-t border-border-strong px-4 py-3"
                :class="popover ? 'justify-end' : 'justify-between'"
            >
                <p v-if="!popover" class="min-w-0 truncate text-sm text-foreground">
                    <TooltipProvider
                        v-if="post.origin === PostOrigin.Network && primaryTarget"
                        :delay-duration="200"
                    >
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span
                                    class="inline-flex cursor-default items-center gap-1"
                                    :data-testid="`post-published-via-${testKey}`"
                                >
                                    {{ $t('posts.publish.published_via') }}
                                    <PlatformBrandIcon
                                        :platform="primaryTarget.platform"
                                        :data-testid="`post-published-via-icon-${testKey}`"
                                    />
                                    <template v-if="primaryTarget.platform !== Platform.X">
                                        {{ getPlatformLabel(primaryTarget.platform) }}
                                    </template>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent :data-testid="`post-published-via-tooltip-${testKey}`">
                                {{
                                    $t('posts.publish.published_directly_from', {
                                        network: getPlatformLabel(primaryTarget.platform),
                                    })
                                }}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                    <span
                        v-else-if="post.user?.name"
                        :data-testid="`post-created-by-${testKey}`"
                        >{{
                        $t('posts.publish.created_by', {
                            name: post.user.name,
                            when: date.diffForHumans(post.created_at),
                        })
                    }}</span>
                </p>
                <div
                    class="flex max-w-full shrink-0 flex-wrap items-center gap-1"
                    :data-testid="`post-actions-${testKey}`"
                >
                    <template v-if="isPending && canApprove">
                        <ApprovePostButton :post="post" :test-key="testKey" />
                        <Button
                            variant="outline"
                            class="text-destructive-text"
                            :data-testid="`post-reject-${testKey}`"
                            @click="rejectPost"
                        >
                            <IconX class="size-4" />
                            {{ $t('posts.approvals.reject') }}
                        </Button>
                    </template>
                    <Button
                        v-else-if="isPending"
                        variant="outline"
                        :data-testid="`post-revert-${testKey}`"
                        @click="revertRequest"
                    >
                        <IconX class="size-4" />
                        {{ $t('posts.approvals.revert') }}
                    </Button>
                    <template v-if="canCreatePost && tab === 'drafts'">
                        <Button
                            v-if="canQueue"
                            variant="outline"
                            :data-testid="`post-add-to-queue-${testKey}`"
                            @click="addToQueue"
                        >
                            <IconListNumbers class="size-4" />
                            {{ $t('posts.publish.actions.add_to_queue') }}
                        </Button>
                        <TooltipProvider v-else :delay-duration="200">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <span tabindex="0">
                                        <Button
                                            variant="outline"
                                            disabled
                                            :data-testid="`post-add-to-queue-${testKey}`"
                                        >
                                            <IconListNumbers class="size-4" />
                                            {{
                                                $t(
                                                    'posts.publish.actions.add_to_queue',
                                                )
                                            }}
                                        </Button>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{
                                        $t(
                                            'posts.publish.actions.add_to_queue_disabled',
                                        )
                                    }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </template>
                    <Tooltip v-if="permalink && primaryTarget">
                        <TooltipTrigger as-child>
                            <Button
                                as="a"
                                :href="permalink"
                                target="_blank"
                                rel="noopener noreferrer"
                                variant="outline"
                                :data-testid="`post-view-${testKey}`"
                            >
                                <IconExternalLink class="size-4" />
                                {{ $t('posts.publish.actions.view_post') }}
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent
                            :data-testid="`post-view-tooltip-${testKey}`"
                        >
                            {{
                                $t('posts.publish.actions.open_on_network', {
                                    network: getPlatformLabel(
                                        primaryTarget.platform,
                                    ),
                                })
                            }}
                        </TooltipContent>
                    </Tooltip>
                    <Button
                        v-if="canPublishDirectly && post.status === PostStatus.Scheduled"
                        variant="outline"
                        :data-testid="`post-publish-now-${testKey}`"
                        @click="schedulePostCard(post.id, 'publish_now')"
                    >
                        <IconSend class="size-4" />
                        {{ $t('posts.publish.actions.publish_now') }}
                    </Button>
                    <Tooltip v-if="canCreatePost && isEditable">
                        <TooltipTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon"
                                :aria-label="$t('posts.publish.actions.edit')"
                                :data-testid="`post-edit-${testKey}`"
                                @click="edit()"
                            >
                                <IconPencil class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {{ $t('posts.publish.actions.edit') }}
                        </TooltipContent>
                    </Tooltip>
                    <PostCardMenu
                        v-if="canCreatePost"
                        :post="post"
                        :test-key="testKey"
                        :can-move-up="canMoveUp"
                        :can-move-down="canMoveDown"
                        :movable="movable"
                        @select="onMenuSelect"
                    />
                    <PostDetailsDialog
                        v-if="canCreatePost"
                        v-model:open="detailsOpen"
                        :post="post"
                        :test-key="testKey"
                        :timezone="timezone"
                        @select="runPostAction"
                        @edit="edit"
                    />
                    <PostRecurrenceDialog
                        v-if="canCreatePost"
                        v-model:open="recurrenceOpen"
                        :post="
                            recurrencePost.id === post.id ? post : recurrencePost
                        "
                        :test-key="testKey"
                        :timezone="timezone"
                    />
                </div>
            </div>
        </article>

        <div v-if="!popover" class="md:absolute md:top-0 md:left-full md:ms-1">
            <PostNotesPopover
                :post-id="post.id"
                :count="post.notes_count"
                :current-user-id="authUserId"
                :initial-open="openPostNotesId === post.id"
                :highlight-note-id="highlightNoteId"
            />
        </div>
    </div>
</template>
