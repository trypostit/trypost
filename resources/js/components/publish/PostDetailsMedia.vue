<script setup lang="ts">
import { IconFileTypePdf, IconPlayerPlayFilled } from '@tabler/icons-vue';

import VideoStill from '@/components/media/VideoStill.vue';
import { isDocument, isVideo } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';

withDefaults(
    defineProps<{
        items: MediaItem[];
        testKey: string;
        /** Bleeds the row into the dialog padding so it scrolls edge to edge. */
        bleed?: boolean;
    }>(),
    { bleed: true },
);

const emit = defineEmits<{ open: [index: number] }>();

const open = (index: number): void => {
    emit('open', index);
};
</script>

<template>
    <div
            class="flex gap-2 overflow-x-auto"
        :class="{ '-mx-6 px-6': bleed }"
        :data-testid="`post-details-media-${testKey}`"
    >
        <component
            :is="isDocument(item) ? 'a' : 'button'"
            v-for="(item, index) in items"
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
            :data-testid="`post-details-media-item-${testKey}-${index}`"
            @click="isDocument(item) || open(index)"
        >
            <template v-if="isVideo(item)">
                <VideoStill :item="item" class="size-full object-cover" />
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
</template>
