<script setup lang="ts">
import {
    IconBookmark,
    IconBrandInstagram,
    IconDots,
    IconDotsVertical,
    IconHeart,
    IconMessageCircle,
    IconRepeat,
    IconSend,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import PostMediaPreview from '@/components/posts/previews/PostMediaPreview.vue';
import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';
import PreviewStoryHeader from '@/components/posts/previews/PreviewStoryHeader.vue';
import PreviewText from '@/components/posts/previews/PreviewText.vue';
import PreviewVerticalCard from '@/components/posts/previews/PreviewVerticalCard.vue';
import { ContentType } from '@/types/content-type';

import type { PreviewProps } from './types';

const props = defineProps<PreviewProps>();

const feedAspectPadding = computed((): number => {
    const { width = 0, height = 0 } = props.media[0]?.meta ?? {};

    return width > 0 && height > 0 ? (height / width) * 100 : 100;
});

const CAPTION_LIMIT = 100;

const caption = computed((): string =>
    props.content.length > CAPTION_LIMIT
        ? props.content.slice(0, CAPTION_LIMIT).trimEnd()
        : props.content,
);
</script>

<template>
    <PreviewVerticalCard
        v-if="contentType === ContentType.InstagramReel"
        data-testid="instagram-reel-preview"
        :media="media"
        :actions="[
            { icon: IconHeart },
            { icon: IconMessageCircle },
            { icon: IconRepeat },
            { icon: IconSend },
            { icon: IconDots },
        ]"
    >
        <template #footer>
            <div
                class="flex items-center gap-2 font-semibold"
                data-testid="instagram-preview-username"
            >
                <PreviewAvatar :account="socialAccount" class="size-8 text-xs" />
                <span class="truncate">{{ socialAccount.handle_label }}</span>
            </div>
            <p v-if="content" class="truncate">{{ content }}</p>
        </template>
        <template #corner>
            <PreviewAvatar
                :account="socialAccount"
                square
                class="size-6 text-[10px] ring-2 ring-white"
            />
        </template>
    </PreviewVerticalCard>
    <PreviewVerticalCard
        v-else-if="contentType === ContentType.InstagramStory"
        data-testid="instagram-story-preview"
        :media="media"
    >
        <template #top>
            <PreviewStoryHeader
                :account="socialAccount"
                :name="socialAccount.handle_label"
            />
        </template>
        <template #footer>
            <div class="flex items-center gap-3">
                <span
                    class="h-10 flex-1 rounded-full border border-white/70"
                />
                <IconHeart class="size-6" stroke-width="1.75" />
                <IconSend class="size-6" stroke-width="1.75" />
            </div>
        </template>
    </PreviewVerticalCard>
    <article
        v-else
        data-testid="instagram-preview"
        class="text-[13px] leading-[18px]"
    >
        <div
            class="flex items-center gap-2.5 px-3 py-2.5 font-semibold"
            data-testid="instagram-preview-username"
        >
            <PreviewAvatar :account="socialAccount" square class="size-8 text-xs" />
            <span class="min-w-0 flex-1 truncate">{{
                socialAccount.handle_label
            }}</span>
            <IconDotsVertical class="size-[18px]" />
        </div>
        <div
            class="relative w-full bg-black"
            :style="{ paddingBottom: `${feedAspectPadding}%` }"
            data-testid="instagram-feed-media"
        >
            <div class="absolute inset-0">
                <PostMediaPreview
                    :media="media"
                    :placeholder-icon="IconBrandInstagram"
                    :show-arrows="media.length > 1"
                    :show-dots="false"
                    placeholder-class="flex size-full items-center justify-center bg-muted"
                />
            </div>
        </div>
        <div class="flex items-center gap-3.5 px-3 pt-3">
            <IconHeart class="size-[22px]" stroke-width="1.75" />
            <IconMessageCircle class="size-[22px]" stroke-width="1.75" />
            <IconRepeat class="size-[22px]" stroke-width="1.75" />
            <IconSend class="size-[22px]" stroke-width="1.75" />
            <span
                v-if="media.length > 1"
                class="flex flex-1 justify-center gap-1"
                data-testid="instagram-preview-dots"
            >
                <span
                    v-for="(item, index) in media"
                    :key="item.id"
                    class="size-1.5 rounded-full"
                    :class="index === 0 ? 'bg-info' : 'bg-border-strong'"
                />
            </span>
            <IconBookmark class="ml-auto size-[22px]" stroke-width="1.75" />
        </div>
        <div class="px-3 pt-2 pb-3">
            <p v-if="content">
                <span class="mr-1 font-semibold">{{
                    socialAccount.handle_label
                }}</span>
                <PreviewText :text="caption" :links="false" /><template
                    v-if="caption !== content"
                    >…
                    <span class="text-muted-foreground">{{
                        $t('posts.composer.preview.more')
                    }}</span></template
                >
            </p>
        </div>
    </article>
</template>
