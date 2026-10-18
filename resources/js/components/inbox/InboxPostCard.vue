<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { inboxItemKey } from '@/lib/inbox';
import { isImage, isVideo } from '@/lib/mediaType';
import { videoFrameUrl } from '@/lib/videoFrame';
import type { InboxPostConversation } from '@/types/inbox';

const props = defineProps<{
    conversation: InboxPostConversation;
    selected: boolean;
}>();

const emit = defineEmits<{
    select: [conversation: InboxPostConversation];
}>();

const postMedia = computed(() =>
    (props.conversation.post?.media ?? []).filter((item) => isImage(item) || isVideo(item)),
);

/** The post's own media, as the open post shows it; the stored subject for a post not linked yet. */
const media = computed(() => (postMedia.value.length > 0 ? postMedia.value : (props.conversation.subject.media ?? [])));

const thumbnail = computed(() => {
    const first = media.value[0];

    if (!first) {
        return null;
    }

    return 'thumbnail_url' in first && first.thumbnail_url ? first.thumbnail_url : isVideo(first) ? null : first.url;
});

/** A video with no still: its first frame stands in, as on the open post. */
const videoFrame = computed(() => {
    const first = media.value[0];

    return first && !thumbnail.value && isVideo(first) ? videoFrameUrl(first) : null;
});

const extraMedia = computed(() => Math.max(media.value.length - 1, 0));

/** A post from "Show all posts" nobody has commented on yet. */
const hasNoComments = computed(() => props.conversation.id === null || props.conversation.inbound_count === 0);

const isAnswered = computed(() => props.conversation.open_count === 0);

const testId = computed(() => `inbox-post-card-${inboxItemKey(props.conversation)}`);

const statusLabel = computed((): { key: string; replacements: Record<string, string> } => {
    if (hasNoComments.value) {
        return { key: 'inbox.posts.no_comments', replacements: {} };
    }

    return isAnswered.value
        ? { key: 'inbox.posts.all_answered', replacements: {} }
        : {
              key: 'inbox.posts.progress',
              replacements: { percent: String(answeredShare.value), count: String(props.conversation.open_count) },
          };
});

const answeredShare = computed(() => {
    const total = props.conversation.inbound_count;

    if (total <= 0) {
        return 100;
    }

    return Math.round(
        ((total - Math.min(props.conversation.open_count, total)) / total) * 100,
    );
});

const selectCard = (): void => {
    emit('select', props.conversation);
};
</script>

<template>
    <button
        type="button"
        class="flex w-full flex-col gap-2 rounded-lg border bg-card px-3 py-2 text-start transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
        :class="
            selected
                ? 'border-primary-text'
                : 'border-border hover:border-border-strong'
        "
        :aria-current="selected ? 'true' : undefined"
        :aria-description="$t(statusLabel.key, statusLabel.replacements)"
        :data-testid="testId"
        @click="selectCard"
    >
        <span class="flex w-full min-w-0 gap-3">
            <span class="flex min-w-0 flex-1 flex-col gap-1">
                <span class="flex h-4 min-w-0 items-center gap-1">
                    <PlatformLogo
                        v-if="conversation.channel.platform"
                        :platform="conversation.channel.platform"
                        :size="16"
                        class="rounded-md"
                        :class="isAnswered ? 'opacity-60 grayscale' : ''"
                    />
                    <span
                        class="truncate text-xs leading-3 font-medium max-md:text-sm max-md:leading-[14px]"
                        :class="
                            isAnswered
                                ? 'text-muted-foreground'
                                : 'text-foreground'
                        "
                        >{{ conversation.channel.username }}</span
                    >
                </span>
                <span
                    class="line-clamp-2 h-[42px] min-w-0 text-sm leading-[21px] break-words max-md:h-12 max-md:text-base max-md:leading-6"
                    :class="
                        isAnswered ? 'text-muted-foreground' : 'text-foreground'
                    "
                    >{{ conversation.subject.text }}</span
                >
            </span>
            <span
                v-if="thumbnail || videoFrame"
                class="relative size-12 shrink-0 overflow-hidden rounded bg-muted"
            >
                <img
                    v-if="thumbnail"
                    :src="thumbnail"
                    alt=""
                    class="size-full object-cover"
                    :class="isAnswered ? 'opacity-60' : ''"
                    loading="lazy"
                />
                <video
                    v-else-if="videoFrame"
                    :src="videoFrame"
                    class="size-full object-cover"
                    :class="isAnswered ? 'opacity-60' : ''"
                    muted
                    playsinline
                    preload="metadata"
                    data-testid="inbox-post-card-video-frame"
                />
                <span
                    v-if="extraMedia > 0"
                    class="absolute start-0.5 top-7 rounded-full bg-black/60 px-1 text-[10px] leading-[18px] font-semibold text-white"
                    >{{
                        $t('inbox.posts.media_more', {
                            count: String(extraMedia),
                        })
                    }}</span
                >
            </span>
        </span>

        <span
            v-if="hasNoComments"
            class="flex h-[18px] w-full items-center justify-end truncate text-xs leading-[18px] text-muted-foreground"
            data-testid="inbox-post-card-no-comments"
        >
            {{ $t('inbox.posts.no_comments') }}
        </span>
        <span
            v-else-if="isAnswered"
            class="flex h-[18px] w-full items-center justify-end"
        >
            <IconCheck
                class="size-3 text-muted-foreground"
                :aria-label="$t('inbox.posts.all_answered')"
                data-testid="inbox-post-card-answered"
            />
        </span>
        <span v-else class="flex h-[21px] w-full items-center gap-2">
            <span
                class="h-[3px] flex-1 overflow-hidden rounded-full bg-secondary"
                aria-hidden="true"
            >
                <span
                    class="block h-full rounded-full bg-primary-text"
                    :style="{ width: `${answeredShare}%` }"
                />
            </span>
            <span
                class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-1 text-xs font-medium tabular-nums max-md:text-sm"
                :class="
                    selected
                        ? 'bg-primary-subtle text-primary-text'
                        : 'bg-secondary text-foreground'
                "
                :aria-label="
                    $tChoice('inbox.posts.open', conversation.open_count, {
                        count: String(conversation.open_count),
                    })
                "
                >{{ conversation.open_count }}</span
            >
        </span>
    </button>
</template>
