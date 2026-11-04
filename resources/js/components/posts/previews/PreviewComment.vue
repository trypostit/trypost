<script setup lang="ts">
import { computed } from 'vue';

import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';
import type { PreviewAccount } from '@/components/posts/previews/types';
import { isImage } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    account: PreviewAccount;
    text: string;
    media: MediaItem[];
}>();

const image = computed(() => props.media.find((item) => isImage(item)) ?? null);
</script>

<template>
    <div class="flex gap-2 border-t pt-3 pb-1">
        <PreviewAvatar :account="account" class="size-8 text-[11px]" />
        <div class="min-w-0 flex-1 space-y-2">
            <div class="rounded-lg bg-muted px-3 py-2">
                <p class="truncate text-[13px] font-semibold text-foreground">
                    {{ account.display_name || account.display_label }}
                </p>
                <p
                    class="text-[13px] break-words whitespace-pre-line text-foreground"
                >
                    {{ text }}
                </p>
            </div>
            <img
                v-if="image"
                :src="image.url"
                :alt="image.meta?.alt_text ?? ''"
                class="max-h-56 max-w-full rounded-lg object-contain"
            />
        </div>
    </div>
</template>
