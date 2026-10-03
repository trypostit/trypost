<script setup lang="ts">
import {
    IconBadgeCc,
    IconBrandTiktokFilled,
    IconBookmarkFilled,
    IconHeartFilled,
    IconMessageCircleFilled,
    IconMusic,
    IconPhoto,
    IconPlus,
    IconSearch,
    IconShare3,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';
import PreviewText from '@/components/posts/previews/PreviewText.vue';
import PreviewVerticalCard from '@/components/posts/previews/PreviewVerticalCard.vue';
import { isImage } from '@/lib/mediaType';

import type { PreviewProps } from './types';

const props = defineProps<PreviewProps>();

const CAPTION_LIMIT = 80;

const username = computed(
    (): string => props.socialAccount.username || props.socialAccount.handle_label,
);

const isPhoto = computed((): boolean => isImage(props.media[0]));

const caption = computed((): string =>
    props.content.length > CAPTION_LIMIT
        ? props.content.slice(0, CAPTION_LIMIT).trimEnd()
        : props.content,
);
</script>

<template>
    <PreviewVerticalCard
        data-testid="tiktok-preview"
        :media="media"
        :actions="[
            { icon: IconHeartFilled },
            { icon: IconMessageCircleFilled },
            { icon: IconBookmarkFilled },
            { icon: IconShare3 },
        ]"
    >
        <template #placeholder>
            <IconBrandTiktokFilled class="size-12 text-white" />
        </template>
        <template #top>
            <div
                class="absolute inset-x-0 top-0 flex items-center justify-center gap-4 p-4 text-[13px] font-medium drop-shadow-sm"
                data-testid="tiktok-preview-header"
            >
                <span class="text-white/80">{{
                    $t('posts.composer.preview.following')
                }}</span>
                <span class="relative font-semibold"
                    >{{ $t('posts.composer.preview.for_you')
                    }}<span
                        class="absolute inset-x-1/4 -bottom-1.5 h-0.5 rounded-full bg-white"
                /></span>
                <IconSearch class="absolute right-4 size-5" />
            </div>
        </template>
        <template #rail-top>
            <span class="relative mb-1">
                <PreviewAvatar
                    :account="socialAccount"
                    class="size-10 ring-1 ring-white"
                />
                <span
                    class="absolute -bottom-2 left-1/2 flex size-5 -translate-x-1/2 items-center justify-center rounded-full bg-destructive text-destructive-foreground"
                >
                    <IconPlus class="size-3" stroke-width="3" />
                </span>
            </span>
        </template>
        <template #footer>
            <IconBadgeCc class="size-6" stroke-width="1.5" />
            <div
                v-if="isPhoto && media.length > 1"
                class="flex justify-center gap-1"
            >
                <span
                    v-for="(item, index) in media"
                    :key="item.id"
                    class="size-1.5 rounded-full"
                    :class="index === 0 ? 'bg-white' : 'bg-white/40'"
                />
            </div>
            <p class="flex items-center gap-2 font-semibold">
                {{ username }}
                <span
                    v-if="isPhoto"
                    class="inline-flex items-center gap-1 rounded bg-white/20 px-1.5 py-0.5 text-[11px] font-medium"
                    data-testid="tiktok-preview-photo"
                    ><IconPhoto class="size-3" />{{
                        $t('posts.composer.preview.photo')
                    }}</span
                >
            </p>
            <p v-if="content">
                <PreviewText :text="caption" overlay /><template
                    v-if="caption !== content"
                    >… <span class="font-semibold">{{
                        $t('posts.composer.preview.more')
                    }}</span></template
                >
            </p>
            <p
                class="flex items-center gap-1.5"
                data-testid="tiktok-preview-sound"
            >
                <IconMusic class="size-4 shrink-0" />
                <span class="truncate">{{
                    $t('posts.composer.preview.original_sound', {
                        name: username,
                    })
                }}</span>
            </p>
        </template>
        <template #corner>
            <span
                class="flex size-10 animate-[spin_4s_linear_infinite] items-center justify-center rounded-full bg-white/15 ring-4 ring-black/50"
            >
                <PreviewAvatar :account="socialAccount" class="size-6 text-[10px]" />
            </span>
        </template>
    </PreviewVerticalCard>
</template>
