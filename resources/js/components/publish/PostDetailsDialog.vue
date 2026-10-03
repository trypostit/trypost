<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconExternalLink,
    IconFileTypePdf,
    IconLayoutSidebarLeftCollapse,
    IconLayoutSidebarLeftExpand,
    IconPencil,
    IconPlayerPlayFilled,
    IconRepeat,
    IconSend,
} from '@tabler/icons-vue';
import { useResizeObserver } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

import { show as showPostGroup } from '@/actions/App/Http/Controllers/App/PostGroupController';
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import MediaLightbox from '@/components/media/MediaLightbox.vue';
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import PostCardLabels from '@/components/publish/PostCardLabels.vue';
import PostCardMenu from '@/components/publish/PostCardMenu.vue';
import PostMetricsBand from '@/components/publish/PostMetricsBand.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
    getPlatformLabel,
} from '@/composables/usePlatformLogo';
import { schedulePostCard } from '@/composables/usePostCardActions';
import {
    getPlatformStatusConfig,
    getPostStatusConfig,
} from '@/composables/usePostStatus';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';
import dayjs from '@/dayjs';
import { isDocument, isVideo } from '@/lib/mediaType';
import { compactPublicationMetrics } from '@/lib/publicationMetrics';
import { isRecurring } from '@/lib/recurrence';
import { videoFrameUrl } from '@/lib/videoFrame';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';
import { PostOrigin, PostStatus } from '@/types/post';
import type { PostCard, PostCardMenuAction } from '@/types/publish';

const props = defineProps<{
    post: PostCard;
    testKey: string;
    timezone: string;
}>();

const emit = defineEmits<{
    select: [action: PostCardMenuAction, post: PostCard];
    edit: [post: PostCard];
}>();

const open = defineModel<boolean>('open', { required: true });

const { canPublishDirectly } = useWorkspaceAbilities();

const http = useHttp<Record<string, never>, PostCard[]>({});
const group = ref<PostCard[] | null>(null);
const selectedId = ref(props.post.id);

const selectSibling = (id: typeof selectedId.value): void => {
    selectedId.value = id;
};

const railCollapsed = ref(false);

const collapseRail = (): void => {
    railCollapsed.value = true;
};

const expandRail = (): void => {
    railCollapsed.value = false;
};

const loadGroup = async (): Promise<void> => {
    try {
        const response = await http.get(showPostGroup.url(props.post.id));

        group.value = response ?? [];
    } catch {
        group.value = [];
    }
};

const isGrouped = computed(() => (props.post.group_posts_count ?? 0) > 1);

watch(open, (value) => {
    if (!value) {
        return;
    }

    selectedId.value = props.post.id;
    group.value = null;

    if (isGrouped.value) {
        loadGroup();
    }
}, { immediate: true });

watch(
    () => props.post,
    () => {
        if (open.value && isGrouped.value) {
            loadGroup();
        }
    },
);

const siblings = computed(() =>
    group.value && group.value.length > 1 ? group.value : [],
);

const showRail = computed(() => siblings.value.length > 0);

const current = computed<PostCard>(() =>
    selectedId.value === props.post.id
        ? props.post
        : (siblings.value.find((sibling) => sibling.id === selectedId.value) ??
          props.post),
);

const currentKey = computed(() =>
    current.value.id === props.post.id ? props.testKey : current.value.id,
);

const targets = computed(() =>
    current.value.post_platforms.filter((target) => target.enabled),
);

const permalink = computed(() => targets.value[0]?.platform_url ?? null);

const metricsDetail = computed(() => {
    const detail = targets.value[0]
        ? current.value.metrics?.[targets.value[0].id]
        : null;

    return detail &&
        detail.available &&
        compactPublicationMetrics(detail).length > 0
        ? detail
        : null;
});

const moment = computed(() => {
    if (current.value.published_at) {
        return {
            key: 'posts.show.published_on',
            at: current.value.published_at,
        };
    }

    if (current.value.scheduled_at) {
        return {
            key: 'posts.show.scheduled_for',
            at: current.value.scheduled_at,
        };
    }

    return null;
});

const content = computed(() => current.value.content?.trim() ?? '');
const media = computed<MediaItem[]>(() => current.value.media ?? []);

const textElement = ref<HTMLElement | null>(null);
const textExpanded = ref(false);

const expandText = (): void => {
    textExpanded.value = true;
};

const textClamped = ref(false);

const measureText = (): void => {
    const element = textElement.value;

    textClamped.value =
        element !== null && element.scrollHeight > element.clientHeight + 1;
};

useResizeObserver(textElement, measureText);

watch(
    () => current.value.id,
    () => {
        textExpanded.value = false;
    },
);

const lightboxOpen = ref(false);
const lightboxIndex = ref(0);

const openMediaPreview = (index: number): void => {
    lightboxIndex.value = index;
    lightboxOpen.value = true;
};

const isEditable = computed(
    () =>
        current.value.status === PostStatus.Draft ||
        current.value.status === PostStatus.Scheduled ||
        current.value.status === PostStatus.PendingApproval,
);

const siblingAccount = (sibling: PostCard) =>
    sibling.post_platforms[0]?.social_account ?? null;

const siblingPlatform = (sibling: PostCard): string =>
    sibling.post_platforms[0]?.platform ?? '';

const siblingMoment = (sibling: PostCard): string | null => {
    const at = sibling.published_at ?? sibling.scheduled_at;

    return at
        ? date.formatLocalMonthDayTime(
              dayjs.utc(at).tz(props.timezone).format('YYYY-MM-DDTHH:mm'),
          )
        : null;
};

</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="gap-0 p-0"
            :class="
                isGrouped && !railCollapsed ? 'sm:max-w-4xl' : 'sm:max-w-xl'
            "
            :data-testid="`post-details-${testKey}`"
        >
            <div class="flex min-h-0 min-w-0 flex-col sm:flex-row">
                <aside
                    v-if="showRail && !railCollapsed"
                    class="flex shrink-0 flex-col gap-2 border-b border-border p-4 sm:w-64 sm:border-e sm:border-b-0"
                    :data-testid="`post-details-rail-${testKey}`"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm text-muted-foreground">
                            {{
                                $t('posts.group.channels', {
                                    count: String(siblings.length),
                                })
                            }}
                        </p>
                        <Button
                            variant="ghost"
                            size="icon"
                            :aria-label="$t('posts.group.collapse')"
                            :data-testid="`post-details-rail-collapse-${testKey}`"
                            @click="collapseRail"
                        >
                            <IconLayoutSidebarLeftCollapse class="size-4" />
                        </Button>
                    </div>
                    <button
                        v-for="sibling in siblings"
                        :key="sibling.id"
                        type="button"
                        class="flex items-center gap-3 rounded-lg p-2 text-start transition-control hover:bg-secondary"
                        :class="sibling.id === current.id ? 'bg-secondary' : ''"
                        :aria-current="sibling.id === current.id ? 'true' : undefined"
                        :data-testid="`post-details-rail-item-${sibling.id}`"
                        @click="selectSibling(sibling.id)"
                    >
                        <ChannelAvatar
                            :status="siblingAccount(sibling)?.status"
                            :account-id="siblingAccount(sibling)?.id"
                            :platform="siblingPlatform(sibling)"
                            :src="siblingAccount(sibling)?.avatar_url"
                            :name="
                                siblingAccount(sibling)?.display_label ??
                                getPlatformLabel(siblingPlatform(sibling))
                            "
                        />
                        <span class="flex min-w-0 flex-col">
                            <span
                                class="truncate text-sm font-emphasis text-foreground"
                                >{{
                                    siblingAccount(sibling)?.display_label ??
                                    getPlatformLabel(siblingPlatform(sibling))
                                }}</span
                            >
                            <span
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                :data-testid="`post-details-rail-status-${sibling.id}`"
                                :data-status="sibling.status"
                            >
                                <component
                                    :is="getPostStatusConfig(sibling.status).icon"
                                    class="size-3.5 shrink-0"
                                    :aria-label="$t(`posts.status.${sibling.status}`)"
                                />
                                {{
                                    siblingMoment(sibling) ??
                                    $t('posts.publish.unscheduled')
                                }}
                            </span>
                        </span>
                    </button>
                </aside>
                <div
                    v-else-if="isGrouped && group === null"
                    class="hidden shrink-0 flex-col gap-3 border-e border-border p-4 sm:flex sm:w-64"
                    :data-testid="`post-details-rail-loading-${testKey}`"
                >
                    <Skeleton class="h-4 w-24" />
                    <Skeleton class="h-10 w-full" />
                    <Skeleton class="h-10 w-full" />
                </div>

                <div class="flex min-w-0 flex-1 flex-col gap-4 px-6 pt-6 pb-4">
                    <DialogHeader class="pe-8">
                        <div class="flex items-center gap-2">
                            <Button
                                v-if="showRail && railCollapsed"
                                variant="ghost"
                                size="icon"
                                :aria-label="$t('posts.group.expand')"
                                :data-testid="`post-details-rail-expand-${testKey}`"
                                @click="expandRail"
                            >
                                <IconLayoutSidebarLeftExpand class="size-4" />
                            </Button>
                            <DialogTitle>{{ $t('posts.show.title') }}</DialogTitle>
                        </div>
                        <DialogDescription
                            class="flex flex-wrap items-center gap-2"
                            as="div"
                        >
                            <Badge
                                :variant="getPostStatusConfig(current.status).variant"
                                class="h-6 gap-1 px-2 [&>svg]:size-4"
                                :data-testid="`post-details-status-${testKey}`"
                            >
                                <component
                                    :is="getPostStatusConfig(current.status).icon"
                                />
                                {{ $t(`posts.status.${current.status}`) }}
                            </Badge>
                            <span
                                class="text-sm text-muted-foreground"
                                :data-testid="`post-details-time-${testKey}`"
                            >
                                {{
                                    moment
                                        ? $t(moment.key, {
                                              date: date.formatDateTimeInTimezone(
                                                  moment.at,
                                                  timezone,
                                              ),
                                          })
                                        : $t('posts.publish.unscheduled')
                                }}
                            </span>
                            <span
                                v-if="isRecurring(current)"
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                :data-testid="`post-details-recurring-${testKey}`"
                            >
                                <span aria-hidden="true">&bull;</span>
                                <IconRepeat class="size-3.5" />
                                {{ $t('posts.recurrence.marker') }}
                            </span>
                        </DialogDescription>
                    </DialogHeader>

                    <section
                        v-for="target in targets"
                        :key="target.id"
                        class="flex items-center gap-3"
                        :data-testid="`post-details-target-${target.id}`"
                    >
                        <ChannelAvatar
                            :status="target.social_account?.status"
                            :account-id="target.social_account?.id"
                            :platform="target.platform"
                            :src="target.social_account?.avatar_url"
                            :name="
                                target.social_account?.display_label ??
                                getPlatformLabel(target.platform)
                            "
                            :size="40"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-sm font-emphasis text-foreground"
                                >{{
                                    target.social_account?.display_label ??
                                    getPlatformLabel(target.platform)
                                }}</span
                            >
                            <span
                                v-if="target.social_account?.handle_label"
                                class="truncate text-xs text-muted-foreground"
                                >{{ target.social_account.handle_label }}</span
                            >
                        </span>
                        <Badge
                            :variant="
                                getPlatformStatusConfig(target.status).variant
                            "
                            class="h-6 px-2"
                        >
                            {{ $t(`posts.edit.status.${target.status}`) }}
                        </Badge>
                    </section>

                    <div class="flex flex-col items-start gap-1">
                        <p
                            ref="textElement"
                            class="w-full text-sm break-words whitespace-pre-line"
                            :class="[
                                content ? 'text-foreground' : 'text-muted-foreground',
                                textExpanded ? '' : 'line-clamp-6',
                            ]"
                            :data-testid="`post-details-text-${currentKey}`"
                        >
                            {{ content || $t('calendar.no_content') }}
                        </p>
                        <button
                            v-if="textClamped && !textExpanded"
                            type="button"
                            class="text-sm font-medium text-muted-foreground hover:text-foreground"
                            :data-testid="`post-details-see-more-${currentKey}`"
                            @click="expandText"
                        >
                            {{ $t('posts.composer.preview.see_more') }}
                        </button>
                    </div>

                    <PostCardLabels
                        :key="current.id"
                        :post-id="current.id"
                        :labels="current.labels ?? []"
                        :test-key="`details-${currentKey}`"
                    />

                    <div
                        v-if="media.length"
                        class="-mx-6 flex gap-2 overflow-x-auto px-6"
                        :data-testid="`post-details-media-${currentKey}`"
                    >
                        <component
                            :is="isDocument(item) ? 'a' : 'button'"
                            v-for="(item, index) in media"
                            :key="item.id ?? item.url"
                            v-bind="
                                isDocument(item)
                                    ? {
                                          href: item.url,
                                          target: '_blank',
                                          rel: 'noopener noreferrer',
                                      }
                                    : {
                                          type: 'button',
                                          'aria-label': $t(
                                              'common.media_lightbox.open',
                                          ),
                                      }
                            "
                            class="relative size-22 shrink-0 overflow-hidden rounded-md border border-border-strong bg-secondary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                            :class="isDocument(item) ? '' : 'cursor-zoom-in'"
                            :data-testid="`post-details-media-item-${currentKey}-${index}`"
                            @click="isDocument(item) || openMediaPreview(index)"
                        >
                            <template v-if="isVideo(item)">
                                <video
                                    :src="videoFrameUrl(item)"
                                    class="size-full object-cover"
                                    muted
                                    playsinline
                                    preload="metadata"
                                />
                                <IconPlayerPlayFilled
                                    aria-hidden="true"
                                    class="absolute top-1/2 left-1/2 size-8 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white"
                                />
                            </template>
                            <span
                                v-else-if="isDocument(item)"
                                class="flex size-full flex-col items-center justify-center gap-1 p-2 text-center"
                            >
                                <IconFileTypePdf
                                    class="size-6 text-muted-foreground"
                                />
                                <span
                                    class="line-clamp-2 text-xs break-all text-muted-foreground"
                                    >{{ item.original_filename || 'PDF' }}</span
                                >
                            </span>
                            <img
                                v-else
                                :src="item.url"
                                :alt="item.meta?.alt_text ?? ''"
                                class="size-full object-cover"
                                loading="lazy"
                            />
                        </component>
                    </div>

                    <PostMetricsBand
                        v-if="metricsDetail"
                        :detail="metricsDetail"
                        :channel-id="targets[0]?.social_account?.id ?? null"
                        class="px-0"
                        :metrics-test-id="`post-details-metrics-${currentKey}`"
                        :insights-test-id="`post-details-insights-${currentKey}`"
                    />

                    <div
                        class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-border pt-4"
                    >
                        <p class="min-w-0 truncate text-sm text-foreground">
                            <TooltipProvider
                                v-if="
                                    current.origin === PostOrigin.Network &&
                                    targets[0]
                                "
                                :delay-duration="200"
                            >
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span
                                            class="inline-flex cursor-default items-center gap-1"
                                            :data-testid="`post-details-published-via-${currentKey}`"
                                        >
                                            {{ $t('posts.publish.published_via') }}
                                            <PlatformBrandIcon
                                                :platform="targets[0].platform"
                                                :data-testid="`post-details-published-via-icon-${currentKey}`"
                                            />
                                            <template v-if="targets[0].platform !== Platform.X">
                                                {{ getPlatformLabel(targets[0].platform) }}
                                            </template>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent :data-testid="`post-details-published-via-tooltip-${currentKey}`">
                                        {{
                                            $t('posts.publish.published_directly_from', {
                                                network: getPlatformLabel(targets[0].platform),
                                            })
                                        }}
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                            <span
                                v-else-if="current.user?.name"
                                :data-testid="`post-details-created-by-${currentKey}`"
                                >{{
                                    $t('posts.publish.created_by', {
                                        name: current.user.name,
                                        when: date.diffForHumans(current.created_at),
                                    })
                                }}</span
                            >
                        </p>
                        <div class="flex shrink-0 items-center gap-1">
                            <Button
                                v-if="permalink"
                                as="a"
                                :href="permalink"
                                target="_blank"
                                rel="noopener noreferrer"
                                variant="outline"
                                :data-testid="`post-details-view-${currentKey}`"
                            >
                                <IconExternalLink class="size-4" />
                                {{ $t('posts.publish.actions.view_post') }}
                            </Button>
                            <Button
                                v-if="
                                    current.status === PostStatus.Scheduled &&
                                    canPublishDirectly
                                "
                                variant="outline"
                                :data-testid="`post-details-publish-now-${currentKey}`"
                                @click="schedulePostCard(current.id, 'publish_now')"
                            >
                                <IconSend class="size-4" />
                                {{ $t('posts.publish.actions.publish_now') }}
                            </Button>
                            <Button
                                v-if="isEditable"
                                variant="outline"
                                size="icon"
                                :aria-label="$t('posts.publish.actions.edit')"
                                :data-testid="`post-details-edit-${currentKey}`"
                                @click="emit('edit', current)"
                            >
                                <IconPencil class="size-4" />
                            </Button>
                            <PostCardMenu
                                :post="current"
                                :test-key="`details-${currentKey}`"
                                :movable="false"
                                :details="false"
                                @select="(action) => emit('select', action, current)"
                            />
                        </div>
                    </div>
                </div>
            </div>
            <MediaLightbox
                v-model:open="lightboxOpen"
                :items="media"
                :start-index="lightboxIndex"
            />
        </DialogContent>
    </Dialog>
</template>
