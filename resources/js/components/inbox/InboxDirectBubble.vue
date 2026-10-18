<script setup lang="ts">
import { IconAt, IconExternalLink, IconHistory, IconLoader, IconMovie, IconPaperclip } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import MediaLightbox from '@/components/media/MediaLightbox.vue';
import { Button } from '@/components/ui/button';
import { useAvatarFallback } from '@/composables/inbox/useAvatarFallback';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { hasSharedPreview, isSharedReel, isUnavailableMessage, unavailableMessageKeys } from '@/lib/inbox';
import { isImage, isVideo } from '@/lib/mediaType';
import { safeHref } from '@/lib/safeHref';
import type { InboxMessage } from '@/types/inbox';

const props = defineProps<{
    message: InboxMessage;
    platform: string;
    participantName: string;
    canRetry: boolean;
    retrying: boolean;
    retryError?: string;
}>();

const emit = defineEmits<{
    retry: [message: InboxMessage];
}>();

const { avatarSrc } = useAvatarFallback();

const isOutbound = computed(() => props.message.direction === 'outbound');
const isSending = computed(() => props.message.delivery_status === 'sending');
const isFailed = computed(() => props.message.delivery_status === 'failed');
const isRemoved = computed(() => Boolean(props.message.removed_at));
const media = computed(() => props.message.media ?? []);
const content = computed(() => props.message.content ?? null);
const unavailableKeys = computed(() => unavailableMessageKeys(props.platform));
const sharedPost = computed(() =>
    content.value?.type === 'shared_post' && (hasSharedPreview(content.value) || content.value.url) ? content.value : null,
);
const sharedPostUrl = computed(() => safeHref(sharedPost.value?.url));
const isReel = computed(() => (sharedPost.value ? isSharedReel(sharedPost.value) : false));
const isVideoThumbnail = computed(() => isVideo({ type: sharedPost.value?.thumbnail_type }));
const story = computed(() =>
    content.value?.type === 'story_reply' || content.value?.type === 'story_mention' ? content.value : null,
);
const storyUrl = computed(() => safeHref(story.value?.url));
const attachment = computed(() => (content.value?.type === 'attachment' ? content.value : null));
const attachmentUrl = computed(() => safeHref(attachment.value?.url));
const visuals = computed(() => media.value.filter((item) => isImage(item) || isVideo(item)));
const hasThumbnailFailed = ref(false);
const sharedVisual = computed(() =>
    sharedPost.value?.thumbnail_url && !hasThumbnailFailed.value
        ? { type: isVideoThumbnail.value ? ('video' as const) : ('image' as const), url: sharedPost.value.thumbnail_url, thumbnail_url: null }
        : null,
);
const hasSharedPostText = computed(() => Boolean(sharedPost.value?.title || sharedPostUrl.value || !sharedVisual.value));
const lightboxItems = computed(() => (sharedVisual.value ? [...visuals.value, sharedVisual.value] : visuals.value));
const isLightboxOpen = ref(false);
const lightboxIndex = ref(0);

const openLightbox = (index: number): void => {
    lightboxIndex.value = index;
    isLightboxOpen.value = true;
};

const openSharedVisual = (): void => {
    openLightbox(visuals.value.length);
};
const isUnavailable = computed(() => isUnavailableMessage(props.message));
const isCardOnly = computed(() => Boolean(sharedPost.value) && !props.message.body && media.value.length === 0);
const reactions = computed(() =>
    Object.entries(
        (props.message.reactions ?? []).reduce<Record<string, number>>(
            (counts, emoji) => ({ ...counts, [emoji]: (counts[emoji] ?? 0) + 1 }),
            {},
        ),
    ).map(([emoji, count]) => ({ emoji, count })),
);

const bubbleClass = computed(() => {
    if (isCardOnly.value) {
        return 'p-0';
    }

    if (isUnavailable.value) {
        return 'bg-secondary px-3 py-2 text-foreground';
    }

    return isOutbound.value ? 'bg-primary-subtle px-3 py-2 text-foreground' : 'bg-secondary px-3 py-2 text-foreground';
});

const hideThumbnail = (): void => {
    hasThumbnailFailed.value = true;
};

const retryMessage = (): void => {
    emit('retry', props.message);
};
</script>

<template>
    <div
        class="flex min-w-0 gap-2"
        :class="[isOutbound ? 'flex-row-reverse' : '', isSending ? 'opacity-60' : '']"
        data-testid="inbox-dm-message"
        :data-direction="message.direction ?? undefined"
        :data-status="message.delivery_status ?? undefined"
        :data-message-id="message.id"
    >
        <ChannelAvatar
            v-if="!isOutbound"
            :platform="platform"
            :name="participantName"
            :src="avatarSrc(message.author.avatar_url)"
            :size="32"
            :badge="false"
            :reserve-space="false"
            ring="card"
            class="shrink-0 self-end"
        />
        <div class="flex max-w-[70%] min-w-0 flex-col gap-1 max-md:max-w-[80%]" :class="isOutbound ? 'items-end' : 'items-start'">
            <div
                class="min-w-0 rounded-lg text-sm leading-[21px] break-words whitespace-pre-line max-md:text-base max-md:leading-6"
                :class="bubbleClass"
            >
                <span v-if="isRemoved" class="text-muted-foreground italic" data-testid="inbox-dm-removed">{{
                    $t('inbox.direct.removed')
                }}</span>
                <template v-else>
                    <span v-if="isUnavailable" class="flex flex-col whitespace-normal" data-testid="inbox-dm-unavailable">
                        <span class="font-semibold text-foreground">{{ $t(unavailableKeys.title) }}</span>
                        <span class="text-sm leading-[21px] text-muted-foreground">{{ $t(unavailableKeys.body) }}</span>
                    </span>
                    <span
                        v-if="sharedPost"
                        class="flex w-60 max-w-full flex-col overflow-hidden rounded-lg border border-border bg-card whitespace-normal"
                        :class="[isCardOnly ? '' : 'mb-1', hasSharedPostText ? 'pb-2' : '']"
                        data-testid="inbox-dm-shared-post"
                        :data-share-kind="isReel ? 'reel' : 'post'"
                    >
                        <button
                            v-if="sharedVisual"
                            type="button"
                            :aria-label="$t('common.media_lightbox.open')"
                            class="relative block cursor-zoom-in bg-muted"
                            data-testid="inbox-dm-shared-post-thumbnail"
                            @click="openSharedVisual"
                        >
                            <video
                                v-if="isVideoThumbnail"
                                :src="sharedVisual.url"
                                class="block max-h-96 w-full object-cover"
                                muted
                                playsinline
                                preload="metadata"
                                @error="hideThumbnail"
                            />
                            <img
                                v-else
                                :src="sharedVisual.url"
                                alt=""
                                class="block h-auto max-h-96 w-full object-cover"
                                loading="lazy"
                                @error="hideThumbnail"
                            />
                            <span
                                v-if="isReel"
                                class="absolute end-2 top-2 flex size-6 items-center justify-center rounded-full bg-black/60 text-white"
                                data-testid="inbox-dm-shared-reel-badge"
                            >
                                <IconMovie class="size-3.5" :aria-label="$t('inbox.direct.content.shared_reel')" />
                            </span>
                        </button>
                        <span v-if="sharedPost.title" class="line-clamp-2 px-3 pt-2 text-sm leading-[21px] text-foreground">{{
                            sharedPost.title
                        }}</span>
                        <span v-else-if="!sharedVisual" class="px-3 pt-2 text-sm leading-[21px] text-muted-foreground">{{
                            $t(isReel ? 'inbox.direct.content.shared_reel' : 'inbox.direct.content.shared_post')
                        }}</span>
                        <a
                            v-if="sharedPostUrl"
                            :href="sharedPostUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex items-center gap-1 px-3 pt-1 text-sm leading-[21px] text-primary-text hover:underline"
                            data-testid="inbox-dm-shared-post-link"
                        >
                            <IconExternalLink class="size-3.5 shrink-0" />
                            {{ $t('inbox.comment.open_on', { network: getPlatformLabel(platform) }) }}
                        </a>
                    </span>
                    <span
                        v-if="story"
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs leading-[18px] text-muted-foreground"
                        :class="message.body ? 'mb-1' : ''"
                        :data-testid="`inbox-dm-${story.type.replace('_', '-')}`"
                    >
                        <span class="flex items-center gap-1">
                            <IconHistory v-if="story.type === 'story_reply'" class="size-3.5 shrink-0" />
                            <IconAt v-else class="size-3.5 shrink-0" />
                            {{ $t(`inbox.direct.content.${story.type}`) }}
                        </span>
                        <a
                            v-if="storyUrl"
                            :href="storyUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-primary-text hover:underline"
                            >{{ $t('inbox.direct.content.view_story') }}</a
                        >
                    </span>
                    <component
                        :is="attachmentUrl ? 'a' : 'span'"
                        v-if="attachment"
                        :href="attachmentUrl"
                        :target="attachmentUrl ? '_blank' : undefined"
                        :rel="attachmentUrl ? 'noopener noreferrer' : undefined"
                        class="flex items-center gap-1.5 text-primary-text hover:underline"
                        data-testid="inbox-dm-attachment"
                    >
                        <IconPaperclip class="size-4 shrink-0" />
                        {{ attachment.title || $t('inbox.direct.content.open_attachment') }}
                    </component>
                    <span v-if="message.body" :class="story ? 'block' : ''">{{ message.body }}</span>
                    <span
                        v-if="message.media_pending && !visuals.length"
                        class="mt-1 block size-40 animate-pulse rounded-lg bg-muted"
                        :class="message.body ? 'pt-1' : ''"
                        role="status"
                        :aria-label="$t('common.loading')"
                        data-testid="inbox-dm-media-pending"
                    />
                    <span v-if="visuals.length" class="mt-1 flex flex-wrap gap-2" :class="message.body ? 'pt-1' : ''">
                        <button
                            v-for="(item, index) in visuals"
                            :key="`${index}-${item.url}`"
                            type="button"
                            :aria-label="$t('common.media_lightbox.open')"
                            class="block size-40 cursor-zoom-in overflow-hidden rounded-lg bg-muted"
                            data-testid="inbox-dm-media"
                            @click="openLightbox(index)"
                        >
                            <img
                                v-if="isImage(item) || item.thumbnail_url"
                                :src="item.thumbnail_url ?? item.url"
                                alt=""
                                class="size-full object-cover"
                                loading="lazy"
                            />
                            <video v-else :src="item.url" class="size-full object-cover" muted preload="metadata" />
                        </button>
                    </span>
                </template>
            </div>
            <span
                v-if="reactions.length"
                class="-mt-2 flex items-center gap-1 rounded-full border border-border bg-card px-1.5 text-sm leading-6"
                :class="isOutbound ? 'me-2' : 'ms-2'"
                data-testid="inbox-dm-reactions"
            >
                <span v-for="reaction in reactions" :key="reaction.emoji" class="flex items-center gap-0.5">
                    {{ reaction.emoji }}
                    <span v-if="reaction.count > 1" class="text-xs text-muted-foreground tabular-nums">{{
                        reaction.count
                    }}</span>
                </span>
            </span>
            <span class="flex items-center gap-1 text-xs leading-[18px] text-muted-foreground">
                <IconLoader
                    v-if="isSending"
                    class="size-3.5 animate-spin"
                    :aria-label="$t('inbox.comment.sending')"
                    data-testid="inbox-dm-sending"
                />
                <time v-if="message.platform_created_at" :datetime="message.platform_created_at" data-testid="inbox-dm-time">{{
                    date.formatTime(message.platform_created_at)
                }}</time>
                <span v-if="isOutbound && message.sent_by">· {{ $t('inbox.direct.sent_by', { name: message.sent_by.name }) }}</span>
            </span>
            <div
                v-if="isFailed"
                class="flex flex-wrap items-center gap-2 text-xs leading-[18px] text-destructive-text"
                data-testid="inbox-dm-failed"
            >
                <span data-testid="inbox-dm-failed-reason">{{
                    retryError ||
                    message.delivery_error ||
                    $t('inbox.direct.failed', { network: getPlatformLabel(platform) })
                }}</span>
                <Button
                    v-if="canRetry"
                    variant="outline"
                    size="sm"
                    :loading="retrying"
                    data-testid="inbox-dm-retry"
                    @click="retryMessage"
                >
                    {{ $t('inbox.comment.try_again') }}
                </Button>
            </div>
        </div>
        <MediaLightbox
            v-if="lightboxItems.length"
            v-model:open="isLightboxOpen"
            :items="lightboxItems"
            :start-index="lightboxIndex"
        />
    </div>
</template>
