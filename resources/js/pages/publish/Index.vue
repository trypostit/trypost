<script setup lang="ts">
import { Head, InfiniteScroll, Link, router, usePage } from '@inertiajs/vue3';
import {
    IconDotsVertical,
    IconFileText,
    IconLayoutGrid,
    IconPlus,
    IconUsers,
} from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, provide, ref, shallowRef, watch } from 'vue';
import { toast } from 'vue-sonner';

import {
    moveToSlot as moveChannelPostToSlot,
    reorder as reorderChannelQueue,
} from '@/actions/App/Http/Controllers/App/ChannelQueueController';
import {
    destroy as destroyPost,
    store as storePost,
    update as updatePost,
} from '@/actions/App/Http/Controllers/App/PostController';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import LabelFilter from '@/components/labels/LabelFilter.vue';
import InviteMemberDialog from '@/components/members/InviteMemberDialog.vue';
import PostComposerDialog from '@/components/posts/composer/PostComposerDialog.vue';
import PostChannelFilter from '@/components/posts/PostChannelFilter.vue';
import ScheduleViewSwitch from '@/components/posts/ScheduleViewSwitch.vue';
import DayHeading from '@/components/publish/DayHeading.vue';
import NewPostButton from '@/components/publish/NewPostButton.vue';
import PostTimelineCard from '@/components/publish/PostTimelineCard.vue';
import PublishEmptyIllustration from '@/components/publish/PublishEmptyIllustration.vue';
import PublishHeader from '@/components/publish/PublishHeader.vue';
import PublishTabs from '@/components/publish/PublishTabs.vue';
import QueueTimeline from '@/components/publish/QueueTimeline.vue';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { useDisplayTimezone } from '@/composables/useDisplayTimezone';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import {
    deletePostCardKey,
    editPostCardUrlKey,
    postCardLabelsKey,
    schedulePostCard,
} from '@/composables/usePostCardActions';
import type {
    ComposerAccount,
    ComposerInitialDraft,
    ComposerInitialPost,
    PostComposition,
} from '@/composables/usePostComposition';
import { useShowPostingSlots } from '@/composables/useShowPostingSlots';
import type { QueueSlotTarget } from '@/composables/useSortableQueue';
import { provideViewTimezone } from '@/composables/useViewTimezone';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';
import dayjs from '@/dayjs';
import AppLayout from '@/layouts/AppLayout.vue';
import { calendar } from '@/routes/app';
import {
    calendar as channelCalendar,
    grid,
    publish,
    settings,
} from '@/routes/app/channels';
import { index as postsIndex } from '@/routes/app/posts';
import type { User } from '@/types';
import { PostStatus, ScheduleMode } from '@/types/post';
import type { TimezoneOption } from '@/types/posting-schedule';
import type {
    PostCard,
    PostCardLabel,
    PublishChannel,
    PublishCounts,
    PublishQueue,
    QueueItem,
    QueueView,
    PublishScope,
    PublishSocialAccount,
    PublishTab,
    QueueDay,
    ScrollPostCards,
} from '@/types/publish';

interface Props {
    scope: PublishScope;
    channel: PublishChannel | null;
    tab: PublishTab;
    counts: PublishCounts;
    displayTimezone: string;
    timezones: TimezoneOption[];
    queue?: PublishQueue;
    posts?: ScrollPostCards;
    labels: PostCardLabel[];
    filters: {
        labels: string[];
        untagged: boolean;
        channels: string[];
    };
    filterAccounts: PublishSocialAccount[];
    openComposer?: boolean;
    openComposerAssistant?: boolean;
    initialComposerDate?: string | null;
    editPost?: PostCard | null;
    socialAccounts?: ComposerAccount[];
    platformConfigs?: Record<string, any>;
    pinterestBoards?: Record<string, any>;
    tiktokCreatorInfos?: Record<string, any>;
    signatures?: { id: string; name: string; content: string }[];
}

interface CardGroup {
    key: string;
    posts: PostCard[];
}

const NO_TIME = 'no-time';
const SENT_STATUSES: readonly string[] = [
    PostStatus.Published,
    PostStatus.PartiallyPublished,
    PostStatus.Failed,
];

const props = defineProps<Props>();
const page = usePage();
const { canManageAccounts, canManageTeam, canPublishDirectly } =
    useWorkspaceAbilities();

const userTimezone = computed(
    () => (page.props.auth.user as User).timezone || props.displayTimezone,
);

const { timezone, setTimezone, dayKey } = useDisplayTimezone(
    props.displayTimezone,
    props.timezones.map((option) => option.value),
    () => props.tab,
);

provideViewTimezone(timezone);

watch(
    () => props.displayTimezone,
    (value) => {
        timezone.value = value;
    },
);

const selectedLabelIds = ref<string[]>(props.filters.labels ?? []);
const selectedUntagged = ref<boolean>(props.filters.untagged ?? false);
const selectedChannelIds = ref<string[]>(props.filters.channels ?? []);

const listUrl = (
    tab: PublishTab = props.tab,
    extra: Record<string, string> = {},
): string => {
    const query = {
        tab,
        labels: selectedLabelIds.value.length
            ? selectedLabelIds.value
            : undefined,
        untagged: selectedUntagged.value ? '1' : undefined,
        channels:
            props.scope === 'all' && selectedChannelIds.value.length
                ? selectedChannelIds.value
                : undefined,
        tz: timezone.value !== userTimezone.value ? timezone.value : undefined,
        ...extra,
    };

    return props.channel
        ? publish.url(props.channel.id, { query })
        : postsIndex.url({ query });
};

const calendarUrl = computed((): string => {
    const query = {
        labels: selectedLabelIds.value.length
            ? selectedLabelIds.value
            : undefined,
        untagged: selectedUntagged.value ? '1' : undefined,
        channels:
            props.scope === 'all' && selectedChannelIds.value.length
                ? selectedChannelIds.value
                : undefined,
        tz: timezone.value !== userTimezone.value ? timezone.value : undefined,
    };

    return props.channel
        ? channelCalendar.url(
              { account: props.channel.id, view: 'month' },
              { query },
          )
        : calendar.url({ view: 'month' }, { query });
});

const applyFilters = (): void => {
    router.get(
        listUrl(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['posts', 'queue', 'counts', 'filters'],
            reset: ['posts'],
        },
    );
};

watch(selectedLabelIds, applyFilters, { deep: true });
watch(selectedUntagged, applyFilters);
watch(selectedChannelIds, applyFilters, { deep: true });

const { showSlots, setShowSlots } = useShowPostingSlots();

const channelTitle = computed(() =>
    props.channel
        ? (props.channel.display_name ?? props.channel.username)
        : null,
);

const newPost = (): void => {
    openPostComposer(
        props.channel ? { socialAccountIds: [props.channel.id] } : {},
    );
};

const splitSettledTargets = (post: PostCard): PostCard[] => {
    const targets = post.post_platforms.filter((target) => target.enabled);

    if (!SENT_STATUSES.includes(post.status) || targets.length <= 1) {
        return [post];
    }

    return targets.map((target) => ({
        ...post,
        card_key: `${post.id}:${target.id}`,
        post_platforms: [target],
    }));
};

const listGroups = computed<CardGroup[]>(() => {
    const groups = new Map<string, PostCard[]>();

    for (const post of (props.posts?.data ?? []).flatMap(splitSettledTargets)) {
        const at =
            props.tab === 'drafts' || props.tab === 'approvals'
                ? post.scheduled_at
                : (post.published_at ?? post.scheduled_at ?? post.created_at);
        const key = at ? dayKey(at) : NO_TIME;

        groups.set(key, [...(groups.get(key) ?? []), post]);
    }

    return [...groups].map(([key, posts]) => ({ key, posts }));
});

const queueTarget = (post: PostCard): string | null =>
    post.post_platforms.find((target) => target.enabled)?.social_account_id ??
    null;

const itemTime = (item: QueueItem): number => dayjs.utc(item.at).valueOf();

const groupQueueItems = (items: QueueItem[]): QueueDay[] => {
    const groups = new Map<string, QueueItem[]>();
    const sorted = [...items].sort(
        (a, b) =>
            itemTime(a) - itemTime(b) ||
            Number(a.type === 'slot') - Number(b.type === 'slot'),
    );

    for (const item of sorted) {
        const key = dayKey(item.at);

        groups.set(key, [...(groups.get(key) ?? []), item]);
    }

    return [...groups].map(([key, items]) => ({ date: key, items }));
};

const hasMorePosts = computed(
    () => page.scrollProps?.posts?.nextPage != null,
);

const lastLoadedPostAt = computed<number | null>(() => {
    const times = (props.posts?.data ?? [])
        .filter((post) => post.scheduled_at)
        .map((post) => dayjs.utc(post.scheduled_at).valueOf());

    return props.channel && props.tab === 'queue' && times.length
        ? Math.max(...times)
        : null;
});

watch(
    lastLoadedPostAt,
    (at) => {
        const queue = props.queue;

        if (at === null || !queue) {
            return;
        }

        const neededDays = Math.min(
            queue.maxQueueDays,
            Math.ceil(dayjs.utc(at).diff(dayjs(), 'day', true)) + 1,
        );

        if (neededDays > queue.queueDays) {
            router.reload({
                data: { queue_days: neededDays },
                only: ['queue'],
            });
        }
    },
    { immediate: true },
);

const channelSlots = computed<QueueItem[]>(() =>
    props.channel
        ? (props.queue?.days ?? [])
              .flatMap((day) => day.items)
              .filter((item) => item.type === 'slot')
        : [],
);

const queueSource = computed<QueueView>(() => {
    const scheduled =
        props.tab === 'queue'
            ? [
                  ...new Map(
                      (props.posts?.data ?? []).map((post) => [post.id, post]),
                  ).values(),
              ]
            : [];

    const pending = props.tab === 'queue' ? (props.queue?.pending ?? []) : [];

    if (scheduled.length === 0 && pending.length === 0) {
        return { days: props.queue?.days ?? [], posts: {} };
    }

    const postItems: QueueItem[] = [];

    for (const post of scheduled) {
        const channelId = queueTarget(post);

        if (!post.scheduled_at || !channelId) {
            continue;
        }

        postItems.push({
            type: 'post',
            at: post.scheduled_at,
            channel_id: channelId,
            post_id: post.id,
        });
    }

    const lastLoaded = postItems.reduce(
        (latest, item) => Math.max(latest, itemTime(item)),
        Number.NEGATIVE_INFINITY,
    );
    const loaded = (item: QueueItem): boolean =>
        !hasMorePosts.value || itemTime(item) <= lastLoaded;
    const timelineItems =
        scheduled.length === 0
            ? (props.queue?.days ?? []).flatMap((day) => day.items)
            : channelSlots.value.filter(loaded);
    const pendingItems = pending.flatMap((post): QueueItem[] => {
        const channelId = queueTarget(post);

        return post.scheduled_at && channelId
            ? [
                  {
                      type: 'post',
                      at: post.scheduled_at,
                      channel_id: channelId,
                      post_id: post.id,
                  },
              ]
            : [];
    });

    return {
        days: groupQueueItems([
            ...postItems,
            ...timelineItems,
            ...pendingItems.filter(loaded),
        ]),
        posts: Object.fromEntries(
            [...scheduled, ...pending].map((post) => [post.id, post]),
        ),
    };
});

const queueHasPosts = computed(() =>
    Object.values(queueSource.value.posts).some(
        (post) => post.status === PostStatus.Scheduled,
    ),
);

const localQueue = shallowRef<QueueView>(queueSource.value);

watch(queueSource, (queue) => {
    localQueue.value = queue;
});

const visibleQueueDays = computed<QueueDay[]>(() =>
    localQueue.value.days
        .map((day) => ({
            ...day,
            items: day.items.filter(
                (item) =>
                    item.type === 'slot' ||
                    (item.post_id !== null &&
                        localQueue.value.posts[item.post_id] !== undefined),
            ),
        }))
        .map((day) =>
            showSlots.value || !props.channel
                ? day
                : {
                      ...day,
                      items: day.items.filter((item) => item.type === 'post'),
                  },
        )
        .filter((day) => day.items.length > 0),
);

const canLoadMoreTimes = computed(
    () =>
        (props.channel
            ? !hasMorePosts.value &&
              showSlots.value &&
              channelSlots.value.length > 0
            : !queueHasPosts.value && visibleQueueDays.value.length > 0) &&
        (props.queue?.queueDays ?? 0) < (props.queue?.maxQueueDays ?? 0),
);

const queueChannels = computed<Record<string, PublishSocialAccount>>(() =>
    Object.fromEntries(
        (props.channel ? [props.channel] : props.filterAccounts).map(
            (account) => [account.id, account],
        ),
    ),
);

const withChannelOrder = (
    queue: QueueView,
    channelId: string,
    orderedPostIds: string[],
): QueueView => {
    const reordered = new Set(orderedPostIds);
    const posts = { ...queue.posts };
    let next = 0;

    const days = queue.days.map((day) => ({
        ...day,
        items: day.items.map((item) => {
            if (
                item.type !== 'post' ||
                item.channel_id !== channelId ||
                !item.post_id ||
                !reordered.has(item.post_id)
            ) {
                return item;
            }

            const postId = orderedPostIds[next++];
            posts[postId] = { ...posts[postId], scheduled_at: item.at };

            return { ...item, post_id: postId };
        }),
    }));

    return { ...queue, days, posts };
};

const QUEUE_RELOAD = { only: ['queue', 'posts', 'counts'], reset: ['posts'] };

const reordering = ref(false);

const reorderQueue = (channelId: string, orderedPostIds: string[]): void => {
    const previous = localQueue.value;

    if (reordering.value) {
        return;
    }

    reordering.value = true;

    const rollback = (message: string): void => {
        localQueue.value = queueSource.value;
        toast.error(message, { testId: 'queue-reorder-error-toast' });
    };

    localQueue.value = withChannelOrder(previous, channelId, orderedPostIds);

    router.put(
        reorderChannelQueue.url(channelId),
        { post_ids: orderedPostIds },
        {
            preserveScroll: true,
            preserveState: true,
            ...QUEUE_RELOAD,
            onFinish: () => {
                reordering.value = false;
            },
            onError: (errors) =>
                rollback(
                    errors.post_ids ??
                        errors.queue ??
                        trans('posts.errors.queue_busy'),
                ),
            onHttpException: () => {
                rollback(trans('posts.errors.queue_busy'));

                return false;
            },
        },
    );
};

const withPostInSlot = (
    queue: QueueView,
    postId: string,
    slot: QueueSlotTarget,
): QueueView => {
    const post = queue.posts[postId];
    const items = queue.days.flatMap((day) => day.items);
    const isTarget = (item: QueueItem): boolean =>
        item.type === 'slot' &&
        item.channel_id === slot.channelId &&
        itemTime(item) === dayjs.utc(slot.at).valueOf();
    const freed: QueueItem[] =
        post?.schedule_mode === ScheduleMode.Queue && post.scheduled_at
            ? [
                  {
                      type: 'slot',
                      at: post.scheduled_at,
                      channel_id: slot.channelId,
                      post_id: null,
                  },
              ]
            : [];

    return {
        days: groupQueueItems([
            ...items.filter((item) => item.post_id !== postId && !isTarget(item)),
            ...freed,
            {
                type: 'post',
                at: slot.at,
                channel_id: slot.channelId,
                post_id: postId,
            },
        ]),
        posts: {
            ...queue.posts,
            [postId]: {
                ...post,
                scheduled_at: slot.at,
                schedule_mode: ScheduleMode.Queue,
            },
        },
    };
};

const moveToSlot = (postId: string, slot: QueueSlotTarget): void => {
    if (reordering.value || !localQueue.value.posts[postId]) {
        return;
    }

    reordering.value = true;

    const rollback = (message: string): void => {
        localQueue.value = queueSource.value;
        toast.error(message, { testId: 'queue-reorder-error-toast' });
    };

    localQueue.value = withPostInSlot(localQueue.value, postId, slot);

    router.put(
        moveChannelPostToSlot.url(slot.channelId),
        { post_id: postId, slot_at: slot.at },
        {
            preserveScroll: true,
            preserveState: true,
            ...QUEUE_RELOAD,
            onFinish: () => {
                reordering.value = false;
            },
            onError: (errors) =>
                rollback(
                    errors.slot_at ??
                        errors.queue ??
                        trans('posts.errors.queue_busy'),
                ),
            onHttpException: () => {
                rollback(trans('posts.errors.queue_busy'));

                return false;
            },
        },
    );
};

const hasActiveFilters = computed(
    () =>
        selectedLabelIds.value.length > 0 ||
        selectedUntagged.value ||
        selectedChannelIds.value.length > 0,
);

const hasChannels = computed(
    () => props.channel !== null || props.filterAccounts.length > 0,
);

const { open: openConnectDialog } = useConnectChannelDialog();
const inviteMemberDialogOpen = ref(false);

const openInviteMemberDialog = (): void => {
    inviteMemberDialogOpen.value = true;
};

const isEmpty = computed(() =>
    props.tab === 'queue'
        ? visibleQueueDays.value.length === 0
        : (props.posts?.data.length ?? 0) === 0,
);

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

provide(editPostCardUrlKey, (post: PostCard) =>
    listUrl(props.tab, { edit: post.id }),
);

provide(
    postCardLabelsKey,
    computed(() => props.labels),
);

provide(deletePostCardKey, (post: PostCard) => {
    deleteModal.value?.open({ url: destroyPost.url(post.id) });
});

const refresh = (): void =>
    router.reload({
        only: ['queue', 'posts', 'counts', 'channels'],
        reset: ['posts'],
    });

useWorkspaceEcho(
    ['.post.created', '.post.deleted', '.post.platform.status.updated'],
    refresh,
);

useWorkspaceEcho<{ post_id: string; change: string }>(
    '.post.note.changed',
    ({ change }) => {
        if (change === 'created' || change === 'deleted') refresh();
    },
);

const composerOpen = ref(Boolean(props.openComposer));
const composerSubmitting = ref(false);

const initialPost = computed<ComposerInitialPost | null>(() => {
    const post = props.editPost;
    const target = post?.post_platforms.find((platform) => platform.enabled);
    if (!post || !target?.social_account_id) return null;
    return {
        content: post.content ?? '',
        media: post.media ?? [],
        scheduled_at: date.formatUtcForDateTimeLocalInput(post.scheduled_at),
        status: post.status,
        schedule_mode: post.schedule_mode ?? null,
        queue_position: post.approval_queue_position ?? null,
        social_account_id: target.social_account_id,
        content_type: target.content_type ?? '',
        meta: target.meta ?? {},
        label_ids: post.labels?.map((label) => label.id) ?? [],
    };
});

const recoveryDraft = computed<ComposerInitialDraft | null>(() => {
    const post = props.editPost;
    if (!post || post.post_platforms.some((target) => target.enabled))
        return null;

    return {
        content: post.content ?? '',
        media: post.media ?? [],
        scheduled_at: date.formatUtcForDateTimeLocalInput(post.scheduled_at),
        label_ids: post.labels?.map((label) => label.id) ?? [],
    };
});

watch(
    () => props.openComposer,
    (open) => {
        composerOpen.value = Boolean(open);
    },
);

const onComposerOpenChange = (open: boolean): void => {
    if (open) return;

    composerOpen.value = false;
    router.visit(listUrl(), { replace: true, preserveScroll: true });
};

const submitComposition = (
    composition: PostComposition,
    createAnother: boolean,
): void => {
    composerSubmitting.value = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            composerOpen.value = false;
            if (createAnother) {
                openPostComposer();
            }
        },
        onFinish: () => {
            composerSubmitting.value = false;
        },
    };
    const createOptions = {
        ...options,
        onSuccess: () => {
            if (composition.queue) {
                const count = composition.destinations.length;
                toast.success(
                    transChoice('posts.composer.queue.added', count, {
                        count: String(count),
                    }),
                    { testId: 'queue-added-toast' },
                );
            }
            options.onSuccess();
        },
    };
    if (props.editPost) {
        if (!initialPost.value) {
            const data: Record<string, any> = {
                ...composition,
                recover_post_id: props.editPost.id,
            };
            router.post(storePost.url(), data, createOptions);
            return;
        }
        const destination = composition.destinations[0];
        const data: Record<string, any> = {
            status: composition.status,
            content: destination.content ?? composition.content,
            media: destination.media ?? composition.media,
            content_type: destination.content_type,
            meta: destination.meta,
            label_ids: composition.label_ids,
            ...(composition.queue
                ? { queue: composition.queue }
                : { scheduled_at: composition.scheduled_at }),
        };
        router.put(updatePost.url(props.editPost.id), data, {
            ...options,
            onSuccess: () => {
                options.onSuccess();

                if (new URLSearchParams(window.location.search).has('edit')) {
                    router.visit(listUrl(), {
                        replace: true,
                        preserveScroll: true,
                    });
                }
            },
        });
        return;
    }
    const data: Record<string, any> = { ...composition };
    router.post(storePost.url(), data, createOptions);
};
</script>

<template>
    <Head :title="channelTitle ?? $t('posts.publish.all_channels')" />

    <AppLayout full-width>
        <template #header>
            <PublishHeader :channel="channel" />
        </template>

        <template #header-actions>
            <div class="flex items-center gap-2">
                <ScheduleViewSwitch
                    active-view="list"
                    :list-href="listUrl()"
                    :calendar-href="calendarUrl"
                    :grid-href="
                        channel?.has_grid ? grid.url(channel.id) : undefined
                    "
                />
                <NewPostButton
                    :social-account-ids="channel ? [channel.id] : []"
                />
            </div>
        </template>

        <div
            class="flex min-h-0 flex-1 flex-col overflow-hidden"
            data-testid="publish-page"
        >
            <div
                class="mx-4 mt-2 flex shrink-0 flex-col md:mx-8 md:h-12 md:flex-row md:items-center md:justify-between md:gap-4 md:border-b md:border-border-strong"
            >
                <PublishTabs :tab="tab" :counts="counts" :href-for="listUrl" />

                <div
                    class="-mx-1 flex min-w-0 items-center gap-2 overflow-x-auto px-1 py-2 md:mx-0 md:overflow-visible md:px-0 md:py-0"
                    data-testid="publish-filters"
                >
                    <PostChannelFilter
                        v-if="scope === 'all' && filterAccounts.length"
                        v-model="selectedChannelIds"
                        :channels="filterAccounts"
                    />
                    <LabelFilter
                        v-model="selectedLabelIds"
                        v-model:untagged="selectedUntagged"
                        :labels="labels"
                    />
                    <div
                        class="shrink-0"
                        data-testid="publish-timezone-select"
                        role="group"
                        :aria-label="$t('posts.publish.timezone.label')"
                    >
                        <TimezoneSelect
                            :model-value="timezone"
                            :options="timezones"
                            testid="publish-timezone"
                            variant="ghost"
                            compact
                            @update:model-value="setTimezone"
                        />
                    </div>
                    <DropdownMenu v-if="channel">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="ms-auto shrink-0 data-[state=open]:bg-accent md:ms-0"
                                :aria-label="$t('posts.table.actions')"
                                data-testid="publish-menu"
                            >
                                <IconDotsVertical class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuCheckboxItem
                                :model-value="showSlots"
                                data-testid="publish-toggle-slots"
                                @update:model-value="setShowSlots"
                            >
                                {{
                                    $t('posts.publish.menu.show_posting_times')
                                }}
                            </DropdownMenuCheckboxItem>
                            <DropdownMenuItem
                                v-if="channel && canManageAccounts"
                                as-child
                            >
                                <Link
                                    :href="settings.url(channel.id)"
                                    data-testid="publish-manage-slots"
                                >
                                    {{
                                        $t(
                                            'posts.publish.menu.manage_posting_times',
                                        )
                                    }}
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <EmptyState
                v-if="isEmpty && hasActiveFilters"
                :icon="IconFileText"
                :title="$t('posts.no_search_results')"
                :description="$t('posts.try_different_search')"
            />

            <EmptyState
                v-else-if="isEmpty && !hasChannels"
                :title="$t('posts.publish.welcome.title')"
                :description="$t('posts.publish.welcome.description')"
                data-testid="publish-welcome"
            >
                <template #illustration>
                    <PublishEmptyIllustration />
                </template>
                <template v-if="canManageAccounts" #action>
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        <Button
                            data-testid="publish-welcome-connect"
                            @click="openConnectDialog()"
                        >
                            <IconPlus class="size-4" />
                            {{ $t('channels.connect') }}
                        </Button>
                        <Button
                            v-if="canManageTeam"
                            variant="outline"
                            data-testid="publish-welcome-invite"
                            @click="openInviteMemberDialog"
                        >
                            <IconUsers class="size-4" />
                            {{ $t('posts.publish.welcome.invite') }}
                        </Button>
                    </div>
                </template>
            </EmptyState>

            <EmptyState
                v-else-if="isEmpty"
                :title="$t(`posts.publish.empty.${tab}.title`)"
                :description="$t(`posts.publish.empty.${tab}.description`)"
            >
                <template #illustration>
                    <PublishEmptyIllustration />
                </template>
                <template #action>
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        <Button
                            :data-testid="`publish-empty-new-post-${tab}`"
                            @click="newPost"
                        >
                            <IconPlus class="size-4" />
                            {{ $t('posts.publish.new_post') }}
                        </Button>
                        <Button
                            v-if="canManageAccounts"
                            variant="outline"
                            data-testid="publish-empty-connect-more"
                            @click="openConnectDialog()"
                        >
                            <IconLayoutGrid class="size-4" />
                            {{ $t('posts.publish.welcome.connect_more') }}
                        </Button>
                    </div>
                </template>
            </EmptyState>

            <div
                v-else-if="tab === 'queue'"
                class="min-h-0 min-w-0 flex-1 overflow-auto overscroll-contain pb-px"
                data-testid="posts-scroll"
            >
                <InfiniteScroll
                    data="posts"
                    items-element="#posts-body"
                    preserve-url
                >
                    <div
                        id="posts-body"
                        class="mx-auto flex w-full max-w-[900px] flex-col px-4 pt-6 pb-12 md:px-12"
                    >
                        <QueueTimeline
                            :days="visibleQueueDays"
                            :posts="localQueue.posts"
                            :queue-days="queue?.queueDays ?? 0"
                            :can-load-more-times="canLoadMoreTimes"
                            :channels="queueChannels"
                            :display-timezone="timezone"
                            :reorderable="
                                canPublishDirectly &&
                                selectedLabelIds.length === 0 &&
                                !selectedUntagged &&
                                !reordering
                            "
                            :slot-drop="channel !== null"
                            @reorder="reorderQueue"
                            @move-to-slot="moveToSlot"
                            @move-top="
                                (post) =>
                                    schedulePostCard(
                                        post.id,
                                        'queue_top',
                                        QUEUE_RELOAD,
                                    )
                            "
                        />
                    </div>

                    <template #next="{ loading }">
                        <p
                            v-if="loading"
                            class="py-5 text-center text-sm text-muted-foreground"
                            role="status"
                        >
                            {{ $t('common.loading_more') }}
                        </p>
                    </template>
                </InfiniteScroll>
            </div>

            <div
                v-else
                class="min-h-0 min-w-0 flex-1 overflow-auto overscroll-contain pb-px"
                data-testid="posts-scroll"
            >
                <InfiniteScroll
                    data="posts"
                    items-element="#posts-body"
                    preserve-url
                >
                    <div
                        id="posts-body"
                        class="mx-auto flex w-full max-w-[900px] flex-col gap-10 px-4 pt-6 pb-12 md:px-12"
                    >
                        <section
                            v-for="group in listGroups"
                            :key="group.key"
                            class="flex flex-col gap-6"
                            :data-testid="`publish-day-${group.key}`"
                        >
                            <h2
                                v-if="group.key === NO_TIME"
                                class="text-base leading-5 font-emphasis text-foreground"
                            >
                                {{ $t('posts.publish.unscheduled') }}
                            </h2>
                            <DayHeading
                                v-else
                                :date-key="group.key"
                                :timezone="timezone"
                            />
                            <PostTimelineCard
                                v-for="post in group.posts"
                                :key="post.card_key ?? post.id"
                                :post="post"
                                :tab="tab"
                                :display-timezone="timezone"
                            />
                        </section>
                    </div>

                    <template #next="{ loading }">
                        <p
                            v-if="loading"
                            class="py-5 text-center text-sm text-muted-foreground"
                            role="status"
                        >
                            {{ $t('common.loading_more') }}
                        </p>
                    </template>
                </InfiniteScroll>
            </div>
        </div>
        <InviteMemberDialog
            v-if="canManageTeam"
            v-model:open="inviteMemberDialogOpen"
        />
    </AppLayout>

    <ConfirmDeleteModal
        ref="deleteModal"
        :title="$t('posts.edit.delete_modal.title')"
        :description="$t('posts.edit.delete_modal.description')"
        :action="$t('posts.edit.delete_modal.action')"
        :cancel="$t('posts.edit.delete_modal.cancel')"
    />

    <PostComposerDialog
        v-if="openComposer"
        :key="editPost?.id ?? 'new'"
        v-model:open="composerOpen"
        :social-accounts="socialAccounts ?? []"
        :initial-post="initialPost"
        :initial-draft="recoveryDraft"
        :post-id="editPost?.id"
        :open-assistant="openComposerAssistant"
        :labels="labels"
        :signatures="signatures ?? []"
        :initial-date="initialComposerDate"
        :submitting="composerSubmitting"
        :platform-configs="platformConfigs ?? {}"
        :pinterest-boards="pinterestBoards ?? {}"
        :tiktok-creator-infos="tiktokCreatorInfos ?? {}"
        @update:open="onComposerOpenChange"
        @submit="submitComposition"
    />
</template>
