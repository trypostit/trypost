<script setup lang="ts">
import { IconDots, IconPhoto, IconUpload } from '@tabler/icons-vue';
import { computed } from 'vue';

import PostMediaPreview from '@/components/posts/previews/PostMediaPreview.vue';
import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';

import type { PreviewProps } from './types';

const props = defineProps<PreviewProps>();

const linkDomain = computed((): string | null => {
    const link = props.meta?.link;

    if (typeof link !== 'string' || !link.trim()) {
        return null;
    }

    try {
        return new URL(link).hostname.replace(/^www\./, '');
    } catch {
        return link;
    }
});
</script>

<template>
    <article
        data-testid="pinterest-preview"
        class="space-y-3 p-4 text-[15px] leading-5"
    >
        <div
            class="relative aspect-[2/3] overflow-hidden rounded-2xl bg-muted"
        >
            <PostMediaPreview
                :media="media"
                :placeholder-icon="IconPhoto"
                :show-arrows="media.length > 1"
                media-class="size-full object-cover bg-black"
            />
        </div>
        <p
            v-if="linkDomain"
            class="truncate text-[13px] text-muted-foreground"
        >
            {{ linkDomain }}
        </p>
        <div class="flex items-center gap-4">
            <IconDots class="size-5" />
            <IconUpload class="size-5" stroke-width="1.75" />
            <span
                class="ml-auto rounded-full bg-destructive px-4 py-2 text-sm font-semibold text-destructive-foreground"
                data-testid="pinterest-preview-save"
                >{{ $t('posts.composer.preview.save') }}</span
            >
        </div>
        <p
            v-if="meta?.title"
            class="line-clamp-2 text-[17px] leading-6 font-semibold"
        >
            {{ meta.title }}
        </p>
        <p v-if="content" class="line-clamp-3 text-muted-foreground">
            {{ content }}
        </p>
        <div class="flex items-center gap-2 text-sm font-medium">
            <PreviewAvatar :account="socialAccount" class="size-8 text-xs" />
            <span class="truncate">{{ socialAccount.display_label }}</span>
        </div>
    </article>
</template>
