<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconExternalLink,
    IconPencil,
    IconRepeat,
    IconSend,
} from '@tabler/icons-vue';
import { useResizeObserver } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

import { show as showPostGroup } from '@/actions/App/Http/Controllers/App/PostGroupController';
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import MediaLightbox from '@/components/media/MediaLightbox.vue';
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import ThreadView from '@/components/posts/ThreadView.vue';
import PostCardLabels from '@/components/publish/PostCardLabels.vue';
import PostCardMenu from '@/components/publish/PostCardMenu.vue';
import PostDetailsMedia from '@/components/publish/PostDetailsMedia.vue';
import PostMetricsBand from '@/components/publish/PostMetricsBand.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
    getPlatformLabel,
} from '@/composables/usePlatformLogo';
import { schedulePostCard } from '@/composables/usePostCardActions';
import { getPostStatusConfig } from '@/composables/usePostStatus';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';
import dayjs from '@/dayjs';
import { compactPublicationMetrics } from '@/lib/publicationMetrics';
import { isRecurring } from '@/lib/recurrence';
import { type ThreadReply, threadRepliesOf } from '@/lib/threadReplies';
import type { MediaItem } from '@/types/media';
import { THREAD_PLATFORMS } from '@/types/network-options';
import { Platform } from '@/types/platform';
import { PostOrigin, PostStatus, PublishStatus } from '@/types/post';
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

const isInGoogleReview = computed(
    () =>
        current.value.status === PostStatus.Publishing &&
        current.value.publish_status === PublishStatus.PendingReview,
);

const currentStatusConfig = computed(() =>
    getPostStatusConfig(
        isInGoogleReview.value
            ? PublishStatus.PendingReview
            : current.value.status,
    ),
);

const currentKey = computed(() =>
    current.value.id === props.post.id ? props.testKey : current.value.id,
);

const channel = computed(() =>
    current.value.platform
        ? {
              platform: current.value.platform,
              social_account: current.value.social_account,
          }
        : null,
);

const permalink = computed(() => current.value.platform_url ?? null);

const metricsDetail = computed(() => {
    const detail = current.value.metrics ?? null;

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

const threadReplies = computed((): ThreadReply[] => {
    return channel.value && THREAD_PLATFORMS.includes(channel.value.platform)
        ? threadRepliesOf(current.value.meta ?? {}).filter(
              (reply) => reply.text.trim() !== '' || reply.media.length > 0,
          )
        : [];
});

const showsThread = computed(
    () =>
        threadReplies.value.length > 0 &&
        Boolean(channel.value?.social_account),
);

const lightboxOpen = ref(false);
const lightboxIndex = ref(0);
const lightboxItems = ref<MediaItem[]>([]);

const openMediaPreview = (items: MediaItem[], index: number): void => {
    lightboxItems.value = items;
    lightboxIndex.value = index;
    lightboxOpen.value = true;
};

const isEditable = computed(
    () =>
        current.value.status === PostStatus.Draft ||
        current.value.status === PostStatus.Scheduled ||
        current.value.status === PostStatus.PendingApproval,
);

const siblingAccount = (sibling: PostCard) => sibling.social_account ?? null;

const siblingPlatform = (sibling: PostCard): string => sibling.platform ?? '';

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
            class="gap-0 p-0 sm:overflow-hidden"
            :class="
                isGrouped ? 'sm:max-w-4xl' : 'sm:max-w-xl'
            "
            :data-testid="`post-details-${testKey}`"
        >
            <div class="flex min-h-0 min-w-0 flex-col sm:max-h-[85dvh] sm:flex-row">
                <aside
                    v-if="showRail"
                    class="flex shrink-0 flex-col gap-2 border-b border-border p-4 sm:w-64 sm:overflow-y-auto sm:border-e sm:border-b-0"
                    :data-testid="`post-details-rail-${testKey}`"
                >
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t('posts.group.channels', {
                                count: String(siblings.length),
                            })
                        }}
                    </p>
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
                            :verified="siblingAccount(sibling)?.verified_badge"
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
                                    :class="getPostStatusConfig(sibling.status).iconClass"
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

                <div
                    class="flex min-w-0 flex-1 flex-col gap-4 px-6 pt-6 pb-4 sm:overflow-y-auto"
                    :data-testid="`post-details-body-${testKey}`"
                >
                    <DialogHeader class="pe-8">
                        <DialogTitle>{{ $t('posts.show.title') }}</DialogTitle>
                        <DialogDescription
                            class="flex flex-wrap items-center gap-2"
                            as="div"
                        >
                            <Badge
                                :variant="currentStatusConfig.variant"
                                class="h-6 gap-1 px-2 [&>svg]:size-4"
                                :data-testid="`post-details-status-${testKey}`"
                            >
                                <component
                                    :is="currentStatusConfig.icon"
                                    :class="currentStatusConfig.iconClass"
                                />
                                {{
                                    isInGoogleReview
                                        ? $t('posts.publish.in_google_review')
                                        : $t(`posts.status.${current.status}`)
                                }}
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
                        v-if="!showsThread && channel"
                        class="flex items-center gap-3"
                        :data-testid="`post-details-target-${current.id}`"
                    >
                        <ChannelAvatar
                            :status="channel.social_account?.status"
                            :account-id="channel.social_account?.id"
                            :platform="channel.platform"
                            :src="channel.social_account?.avatar_url"
                            :verified="channel.social_account?.verified_badge"
                            :name="
                                channel.social_account?.display_label ??
                                getPlatformLabel(channel.platform)
                            "
                            :size="40"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-sm font-emphasis text-foreground"
                                >{{
                                    channel.social_account?.display_label ??
                                    getPlatformLabel(channel.platform)
                                }}</span
                            >
                            <span
                                v-if="channel.social_account?.handle_label"
                                class="truncate text-xs text-muted-foreground"
                                >{{ channel.social_account.handle_label }}</span
                            >
                        </span>
                    </section>

                    <ThreadView
                        v-if="showsThread && channel?.social_account"
                        :account="channel.social_account"
                        :posts="[{ text: content, media }, ...threadReplies]"
                        :test-key="currentKey"
                        @open-media="openMediaPreview"
                    />
                    <template v-else>
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

                    <PostDetailsMedia
                        v-if="media.length"
                        :items="media"
                        :test-key="currentKey"
                        @open="openMediaPreview(media, $event)"
                    />
                    </template>


                    <PostCardLabels
                        :key="current.id"
                        :post-id="current.id"
                        :labels="current.labels ?? []"
                        :test-key="`details-${currentKey}`"
                    />

                    <PostMetricsBand
                        v-if="metricsDetail"
                        :detail="metricsDetail"
                        :channel-id="channel?.social_account?.id ?? null"
                        class="-mx-6 px-6"
                        :metrics-test-id="`post-details-metrics-${currentKey}`"
                        :insights-test-id="`post-details-insights-${currentKey}`"
                    />

                    <DialogFooter
                        class="sm:justify-between"
                        :data-testid="`post-details-footer-${currentKey}`"
                    >
                        <p
                            class="min-w-0 truncate text-sm text-foreground"
                            :class="{
                                'max-sm:hidden':
                                    current.origin === PostOrigin.Network,
                            }"
                        >
                            <TooltipProvider
                                v-if="
                                    current.origin === PostOrigin.Network &&
                                    channel
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
                                                :platform="channel.platform"
                                                :data-testid="`post-details-published-via-icon-${currentKey}`"
                                            />
                                            <template v-if="channel.platform !== Platform.X">
                                                {{ getPlatformLabel(channel.platform) }}
                                            </template>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent :data-testid="`post-details-published-via-tooltip-${currentKey}`">
                                        {{
                                            $t('posts.publish.published_directly_from', {
                                                network: getPlatformLabel(channel.platform),
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
                        <div
                            class="flex shrink-0 items-center gap-1 max-sm:ms-auto"
                            :data-testid="`post-details-actions-${currentKey}`"
                        >
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
                    </DialogFooter>
                </div>
            </div>
            <MediaLightbox
                v-model:open="lightboxOpen"
                :items="lightboxItems"
                :start-index="lightboxIndex"
            />
        </DialogContent>
    </Dialog>
</template>
