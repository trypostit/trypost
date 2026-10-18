<script setup lang="ts">
import { IconExternalLink, IconPlayerPlayFilled } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import InboxCardFooter from '@/components/inbox/InboxCardFooter.vue';
import InboxNetworkVia from '@/components/inbox/InboxNetworkVia.vue';
import InboxPostDetails from '@/components/inbox/InboxPostDetails.vue';
import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import MediaLightbox from '@/components/media/MediaLightbox.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { isImage, isVideo } from '@/lib/mediaType';
import { safeHref } from '@/lib/safeHref';
import { videoFrameUrl } from '@/lib/videoFrame';
import type { InboxPostConversation } from '@/types/inbox';

const props = defineProps<{
    conversation: InboxPostConversation;
    nested?: boolean;
}>();

const thumbnail = computed(() => {
    const first = props.conversation.subject.media?.[0];

    return first ? (first.thumbnail_url ?? first.url) : null;
});

const { avatarSrc } = useAvatarFallback();

const platform = computed(() => props.conversation.channel.platform);

const subjectUrl = computed(() => safeHref(props.conversation.subject.url));

const networkLabel = computed(() =>
    platform.value ? getPlatformLabel(platform.value) : '',
);

const channelName = computed(
    () =>
        props.conversation.channel.display_name ||
        props.conversation.channel.username ||
        networkLabel.value,
);

const post = computed(() => props.conversation.post);

const createdBy = computed(() =>
    post.value?.origin === 'trypost' && post.value.created_by && post.value.created_at
        ? { name: post.value.created_by, at: post.value.created_at }
        : null,
);

const postVisuals = computed(() =>
    (post.value?.media ?? []).filter((item) => isImage(item) || isVideo(item)),
);

const postVisual = computed(() => postVisuals.value[0] ?? null);

const hiddenVisuals = computed(() => Math.max(0, postVisuals.value.length - 1));

const lightboxVisuals = computed(() =>
    (postVisuals.value.length > 0 ? postVisuals.value : (props.conversation.subject.media ?? []))
        .filter((item) => isImage(item) || isVideo(item))
        .map(({ url, type }) => ({ url, type: type ?? undefined })),
);

const isLightboxOpen = ref(false);

const openLightbox = (): void => {
    isLightboxOpen.value = true;
};

const detailsOpen = ref(false);
const isLoadingDetails = ref(false);

const openDetails = (): void => {
    if (post.value) {
        detailsOpen.value = true;
    }
};

const openNestedDetails = (event: MouseEvent): void => {
    if (post.value) {
        event.stopPropagation();
        detailsOpen.value = true;
    }
};

const nestedThumbnail = computed(() =>
    postVisual.value && isImage(postVisual.value) ? postVisual.value.url : thumbnail.value,
);

const setLoadingDetails = (isLoading: boolean): void => {
    isLoadingDetails.value = isLoading;
};
</script>

<template>
    <div
        v-if="conversation.kind === 'review'"
        class="flex min-w-0 items-center gap-2 rounded-lg bg-muted p-2 leading-[18px]"
        data-testid="inbox-subject-review"
    >
        <PlatformLogo
            v-if="platform"
            :platform="platform"
            :size="16"
            class="rounded-md"
        />
        <span
            class="truncate text-xs leading-[18px] font-medium text-muted-foreground"
            >{{ channelName }}</span
        >
        <span
            v-if="conversation.subject.published_at"
            class="shrink-0 text-xs leading-[18px] font-medium text-muted-foreground"
            >{{ date.formatMonthDayTime(conversation.subject.published_at) }}</span
        >
        <InboxTooltip
            v-if="subjectUrl"
            :label="$t('inbox.conversation.open_post', { network: networkLabel })"
        >
            <a
                :href="subjectUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="ms-auto inline-flex size-[18px] shrink-0 items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-accent hover:text-foreground"
                :aria-label="$t('inbox.conversation.open_post', { network: networkLabel })"
                data-testid="inbox-subject-link"
                @click.stop
            >
                <IconExternalLink class="size-3.5" />
            </a>
        </InboxTooltip>
    </div>
    <div
        v-else-if="nested"
        class="flex min-w-0 gap-3 rounded-lg bg-muted p-3"
        :class="post ? 'cursor-pointer' : ''"
        :data-linked="post ? 'true' : 'false'"
        data-testid="inbox-subject-card"
        @click="openNestedDetails"
    >
        <div class="min-w-0 flex-1">
            <div class="flex min-h-[18px] min-w-0 items-center gap-2">
                <PlatformLogo
                    v-if="platform"
                    :platform="platform"
                    :size="16"
                    class="rounded-md"
                />
                <span
                    class="truncate text-xs leading-[18px] font-medium text-muted-foreground"
                    >{{ conversation.channel.username }}</span
                >
                <span
                    v-if="conversation.subject.published_at"
                    class="shrink-0 text-xs leading-[18px] text-muted-foreground"
                    >{{ date.formatMonthDayTime(conversation.subject.published_at) }}</span
                >
                <InboxTooltip
                    v-if="subjectUrl"
                    :label="$t('inbox.conversation.open_post', { network: networkLabel })"
                >
                    <a
                        :href="subjectUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ms-auto inline-flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-secondary hover:text-foreground"
                        :aria-label="$t('inbox.conversation.open_post', { network: networkLabel })"
                        data-testid="inbox-subject-link"
                        @click.stop
                    >
                        <IconExternalLink class="size-4" />
                    </a>
                </InboxTooltip>
            </div>
            <p
                class="mt-1.5 line-clamp-2 text-sm leading-5 break-words text-foreground"
            >
                {{ conversation.subject.text }}
            </p>
        </div>
        <img
            v-if="nestedThumbnail"
            :src="nestedThumbnail"
            alt=""
            class="size-12 shrink-0 rounded-md border border-border-strong object-cover"
            loading="lazy"
        />
        <InboxPostDetails
            v-if="post"
            v-model:open="detailsOpen"
            :post-id="post.id"
        />
    </div>
    <article
        v-else
        class="min-w-0 overflow-hidden rounded-xl border border-border-strong bg-card"
        :data-linked="post ? 'true' : 'false'"
        data-testid="inbox-subject-card"
    >
        <div
            class="flex min-w-0 gap-4 px-4 pt-4 pb-2"
            :class="post ? 'cursor-pointer' : ''"
            :data-testid="post ? 'inbox-subject-open-details' : undefined"
            @click="openDetails"
        >
            <div class="min-w-0 flex-1">
                <div class="flex min-w-0 items-center gap-2">
                    <ChannelAvatar
                        v-if="platform"
                        :platform="platform"
                        :name="channelName"
                        :src="avatarSrc(conversation.channel.avatar_url)"
                        :size="32"
                        :reserve-space="false"
                        ring="card"
                    />
                    <div class="flex min-w-0 flex-col">
                        <span
                            class="truncate text-sm font-semibold text-foreground"
                            data-testid="inbox-subject-channel"
                            >{{ channelName }}</span
                        >
                        <time
                            v-if="conversation.subject.published_at"
                            class="truncate text-xs text-muted-foreground"
                            :datetime="conversation.subject.published_at"
                            >{{ date.formatMonthDayTime(conversation.subject.published_at) }}</time
                        >
                    </div>
                </div>
                <p
                    class="mt-3 line-clamp-3 text-sm leading-[21px] break-words whitespace-pre-line text-foreground max-md:text-base max-md:leading-6"
                    data-testid="inbox-subject-text"
                >
                    {{ conversation.subject.text }}
                </p>
            </div>
            <button
                v-if="postVisual"
                type="button"
                class="relative size-20 shrink-0 cursor-zoom-in overflow-hidden rounded-md border border-border-strong bg-secondary md:size-24"
                :aria-label="$t('common.media_lightbox.open')"
                data-testid="inbox-subject-post-media"
                @click.stop="openLightbox"
            >
                <img
                    v-if="isImage(postVisual)"
                    :src="postVisual.url"
                    alt=""
                    class="size-full object-cover"
                    loading="lazy"
                />
                <template v-else>
                    <video
                        :src="videoFrameUrl(postVisual)"
                        class="size-full object-cover"
                        muted
                        playsinline
                        preload="metadata"
                    />
                    <IconPlayerPlayFilled
                        aria-hidden="true"
                        class="absolute top-1/2 left-1/2 size-7 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black/60 p-1.5 text-white"
                    />
                </template>
                <span
                    v-if="hiddenVisuals > 0"
                    class="absolute end-1 bottom-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-foreground/60 px-1 text-[11px] font-medium text-background"
                    >+{{ hiddenVisuals }}</span
                >
            </button>
            <button
                v-else-if="thumbnail"
                type="button"
                class="size-20 shrink-0 cursor-zoom-in overflow-hidden rounded-md border border-border-strong md:size-24"
                :aria-label="$t('common.media_lightbox.open')"
                :disabled="lightboxVisuals.length === 0"
                data-testid="inbox-subject-thumbnail"
                @click.stop="openLightbox"
            >
                <img :src="thumbnail" alt="" class="size-full object-cover" loading="lazy" />
            </button>
        </div>
        <InboxCardFooter data-testid="inbox-subject-footer">
            <template #meta>
                <span
                    v-if="createdBy"
                    class="truncate"
                    data-testid="inbox-subject-created-by"
                    >{{
                        $t('posts.publish.created_by', {
                            name: createdBy.name,
                            when: date.diffForHumans(createdBy.at),
                        })
                    }}</span
                >
                <InboxTooltip
                    v-else-if="platform && post"
                    :label="$t('posts.publish.published_directly_from', { network: networkLabel })"
                >
                    <span
                        class="inline-flex min-w-0 cursor-default"
                        tabindex="0"
                        data-testid="inbox-subject-published-via"
                    >
                        <InboxNetworkVia
                            :label="$t('posts.publish.published_via')"
                            :platform="platform"
                        />
                    </span>
                </InboxTooltip>
                <InboxNetworkVia
                    v-else-if="platform"
                    :label="$t('posts.publish.published_via')"
                    :platform="platform"
                    data-testid="inbox-subject-published-via"
                />
            </template>
            <Button
                v-if="post"
                variant="outline"
                :loading="isLoadingDetails"
                data-testid="inbox-subject-details"
                @click="openDetails"
            >
                {{ $t('posts.show.title') }}
            </Button>
            <InboxTooltip
                v-else-if="subjectUrl"
                :label="$t('posts.publish.actions.open_on_network', { network: networkLabel })"
            >
                <Button
                    as="a"
                    :href="subjectUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    variant="outline"
                    data-testid="inbox-subject-link"
                >
                    <IconExternalLink class="size-4" />
                    {{ $t('posts.publish.actions.view_post') }}
                </Button>
            </InboxTooltip>
        </InboxCardFooter>
        <MediaLightbox
            v-if="lightboxVisuals.length"
            v-model:open="isLightboxOpen"
            :items="lightboxVisuals"
        />
        <InboxPostDetails
            v-if="post"
            v-model:open="detailsOpen"
            :post-id="post.id"
            @loading="setLoadingDetails"
        />
    </article>
</template>
