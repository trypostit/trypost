<script setup lang="ts">
import { IconPlayerPlayFilled } from '@tabler/icons-vue';
import { useElementVisibility } from '@vueuse/core';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import PostApprovalActions from '@/components/publish/PostApprovalActions.vue';
import PostCardLabels from '@/components/publish/PostCardLabels.vue';
import PostCardMenu from '@/components/publish/PostCardMenu.vue';
import PostDetailsDialog from '@/components/publish/PostDetailsDialog.vue';
import PostRecurrenceDialog from '@/components/publish/PostRecurrenceDialog.vue';
import { Badge } from '@/components/ui/badge';
import { usePostCardMenuActions } from '@/composables/publish/usePostCardMenuActions';
import { getContentTypeBadgeKey } from '@/composables/usePlatformLogo';
import { getPostCardStatusConfig } from '@/composables/usePostStatus';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';
import { limitText } from '@/lib/characters';
import { filled, transform } from '@/lib/helpers';
import { isImage } from '@/lib/mediaType';
import { postAttemptAt, postChannelLabel, postThumbnail } from '@/lib/postCard';
import { htmlToPlainText } from '@/lib/utils';
import { videoFrameUrl } from '@/lib/videoFrame';
import { PostStatus } from '@/types/post';
import type { PostCard } from '@/types/publish';

const props = defineProps<{
    post: PostCard;
    showStatus: boolean;
    timezone: string;
}>();

const { canCreatePost } = useWorkspaceAbilities();

/** The details and recurrence dialogs mount on the first click only, so a full board doesn't mount them per post. */
const {
    dialogsMounted,
    detailsOpen,
    recurrenceOpen,
    recurrenceTarget,
    openDetails,
    edit,
    runPostAction,
    runMenuAction,
} = usePostCardMenuActions(() => props.post, { lazy: true });

const account = computed(() => props.post.social_account);

const channelLabel = computed(() => postChannelLabel(props.post));

const recurrenceTimezone = computed(
    () => account.value?.timezone ?? props.timezone,
);

const attemptAt = computed(() => postAttemptAt(props.post));

const time = computed(() =>
    date.formatMonthDayTimeInTimezone(attemptAt.value, props.timezone),
);

const text = computed(() => htmlToPlainText(props.post.content ?? ''));

/** The overlay's name: channel, time and the start of the post, never its whole text. */
const openLabel = computed(() =>
    [channelLabel.value, time.value, limitText(text.value, 100)]
        .filter(Boolean)
        .join(' · '),
);

const thumbnail = computed(() => postThumbnail(props.post));

const thumbnailFrame = ref<HTMLElement | null>(null);

/**
 * A video loads its frame once its card first shows, never for the whole board at once; an
 * image is lazy on its own and is not observed. The 200px margin widens the viewport only:
 * the column's scroller still clips, so a card scrolled out of its column counts as hidden.
 */
const videoLoaded = useElementVisibility(
    computed(() =>
        transform(thumbnail.value, (item) =>
            isImage(item) ? null : thumbnailFrame.value,
        ),
    ),
    { rootMargin: '200px', once: true },
);

const contentTypeKey = computed(() =>
    transform(props.post.platform, (platform) =>
        getContentTypeBadgeKey(platform, props.post.content_type ?? null),
    ),
);

const statusConfig = computed(() => getPostCardStatusConfig(props.post));

const isPending = computed(
    () => props.post.status === PostStatus.PendingApproval,
);

const hasMenu = computed(
    () => canCreatePost.value && props.post.status !== PostStatus.Publishing,
);
</script>

<template>
    <article
        class="relative flex flex-col gap-2 rounded-lg border border-border-strong bg-card p-3 transition-control [contain-intrinsic-size:auto_160px] [content-visibility:auto] hover:bg-secondary"
        :data-testid="`board-card-${post.id}`"
    >
        <button
            type="button"
            class="absolute inset-0 rounded-lg focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
            :aria-label="
                filled(text)
                    ? openLabel
                    : `${openLabel} · ${$t('calendar.no_content')}`
            "
            :data-testid="`board-card-open-${post.id}`"
            @click="openDetails"
        />

        <div class="flex min-h-7 items-center gap-2" :class="{ 'pe-8': hasMenu }">
            <ChannelAvatar
                v-if="post.platform"
                :platform="post.platform"
                :src="account?.avatar_url"
                :name="channelLabel"
                :status="account?.status"
                :account-id="account?.id"
                :size="24"
                ring="card"
            />
            <p
                class="min-w-0 truncate text-sm leading-tight font-emphasis text-foreground"
            >
                {{ channelLabel || $t('posts.board.columns.no_channel') }}
            </p>
        </div>

        <div
            class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
        >
            <time
                :datetime="attemptAt"
                :data-testid="`board-card-time-${post.id}`"
                >{{ time }}</time
            >
            <Badge
                v-if="contentTypeKey"
                variant="secondary"
                class="h-5 px-1.5"
                :data-testid="`board-card-content-type-${post.id}`"
            >
                {{ $t(contentTypeKey) }}
            </Badge>
            <Badge
                v-if="showStatus"
                :variant="statusConfig.variant"
                class="h-5 gap-1 px-1.5 [&>svg]:size-3.5"
                :data-testid="`board-card-status-${post.id}`"
            >
                <component
                    :is="statusConfig.icon"
                    :class="statusConfig.iconClass"
                    aria-hidden="true"
                />
                {{ $t(statusConfig.labelKey) }}
            </Badge>
        </div>

        <p
            class="line-clamp-6 text-sm break-words whitespace-pre-line"
            :class="text ? 'text-foreground' : 'text-muted-foreground'"
        >
            {{ text || $t('calendar.no_content') }}
        </p>

        <div
            v-if="thumbnail"
            ref="thumbnailFrame"
            class="relative aspect-video overflow-hidden rounded-md border border-border-strong bg-secondary"
            :data-testid="`board-card-thumbnail-${post.id}`"
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
                    v-if="videoLoaded"
                    :src="videoFrameUrl(thumbnail)"
                    class="size-full object-cover"
                    muted
                    playsinline
                    preload="metadata"
                />
                <IconPlayerPlayFilled
                    aria-hidden="true"
                    class="absolute top-1/2 left-1/2 size-6 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black/60 p-1 text-white"
                />
            </template>
        </div>

        <div class="relative z-10 flex empty:hidden">
            <PostCardLabels
                :post-id="post.id"
                :labels="post.labels"
                :test-key="`board-${post.id}`"
            />
        </div>

        <div
            v-if="isPending"
            class="relative z-10 flex flex-wrap gap-2"
            :data-testid="`board-card-approval-${post.id}`"
        >
            <PostApprovalActions :post="post" :test-key="`board-${post.id}`" />
        </div>

        <div v-if="hasMenu" class="absolute top-2 end-2 z-10">
            <PostCardMenu
                :post="post"
                :test-key="`board-${post.id}`"
                :trigger-test-id="`board-card-menu-${post.id}`"
                :movable="false"
                @select="runMenuAction"
            />
        </div>

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
    </article>
</template>
