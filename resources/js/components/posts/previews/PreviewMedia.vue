<script setup lang="ts">
import { IconVolumeOff } from '@tabler/icons-vue';
import { computed } from 'vue';

import PostMediaPreview from '@/components/posts/previews/PostMediaPreview.vue';
import VideoPreview from '@/components/posts/previews/VideoPreview.vue';
import { isDocument, isVideo } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';

import type { PreviewMediaLayout } from './types';

const props = withDefaults(
    defineProps<{
        media: MediaItem[];
        layout?: PreviewMediaLayout;
        bleed?: boolean;
        muteBadge?: boolean;
    }>(),
    { layout: 'grid', bleed: false, muteBadge: false },
);

const first = computed((): MediaItem | null => props.media[0] ?? null);

const ratioOf = (item: MediaItem): number => {
    if (isDocument(item)) {
        return 1.25;
    }

    const { width = 0, height = 0 } = item.meta ?? {};
    const ratio = width > 0 && height > 0 ? height / width : 1;

    return Math.min(Math.max(ratio, 0.5625), isVideo(item) ? 1.78 : 1.25);
};

const firstRatio = computed(
    (): number => first.value ? ratioOf(first.value) : 1,
);

const mode = computed((): PreviewMediaLayout | 'single' =>
    props.media.length === 1 ? 'single' : props.layout,
);

const collageTiles = computed(() => {
    const count = props.media.length;
    const visible = props.media.slice(0, 5);

    return visible.map((item, index) => {
        const span = (() => {
            if (count === 3) {
                return index === 0 ? 'col-span-6 aspect-[2/1]' : 'col-span-3 aspect-square';
            }

            if (count >= 5) {
                return index < 2 ? 'col-span-3 aspect-square' : 'col-span-2 aspect-square';
            }

            return count === 2 ? 'col-span-3 aspect-[1/2]' : 'col-span-3 aspect-square';
        })();

        return {
            item,
            span,
            more: count > 5 && index === 4 ? count - 5 : 0,
        };
    });
});

const frameClass = computed((): string =>
    props.bleed ? '' : 'rounded-xl border',
);
</script>

<template>
    <div
        v-if="first"
        data-testid="preview-media"
        class="relative"
        :data-layout="mode"
    >
        <div
            v-if="mode === 'single' || mode === 'carousel'"
            class="relative w-full overflow-hidden bg-muted"
            :class="frameClass"
            :style="{ paddingBottom: `${firstRatio * 100}%` }"
        >
            <div class="absolute inset-0">
                <iframe
                    v-if="isDocument(first)"
                    :src="`${first.url}#toolbar=0&navpanes=0&view=FitH`"
                    :title="first.original_filename || 'PDF'"
                    class="absolute -top-2 -left-2 h-[calc(100%+1rem)] w-[calc(100%+1rem)] max-w-none border-0"
                    loading="lazy"
                />
                <PostMediaPreview
                    v-else
                    :media="media"
                    :show-arrows="media.length > 1"
                    media-class="size-full object-cover bg-black"
                />
            </div>
        </div>
        <div
            v-else-if="mode === 'collage'"
            class="grid grid-cols-6 gap-0.5 overflow-hidden"
            :class="frameClass"
            data-testid="preview-media-collage"
        >
            <div
                v-for="tile in collageTiles"
                :key="tile.item.id"
                class="relative overflow-hidden bg-muted"
                :class="tile.span"
                data-testid="preview-media-collage-tile"
            >
                <VideoPreview
                    v-if="isVideo(tile.item)"
                    :src="tile.item.url"
                    video-class="size-full object-cover bg-black"
                />
                <img
                    v-else
                    :src="tile.item.url"
                    :alt="tile.item.original_filename"
                    class="size-full object-cover"
                />
                <span
                    v-if="tile.more"
                    class="absolute inset-0 flex items-center justify-center bg-black/60 text-xl font-semibold text-white"
                    data-testid="preview-media-collage-more"
                    >+{{ tile.more }}</span
                >
            </div>
        </div>
        <div v-else-if="mode === 'peek'" class="flex gap-1 overflow-hidden">
            <div
                v-for="item in media"
                :key="item.id"
                class="relative aspect-[4/5] w-[70%] shrink-0 overflow-hidden bg-muted"
                :class="frameClass"
            >
                <VideoPreview
                    v-if="isVideo(item)"
                    :src="item.url"
                    video-class="size-full object-cover bg-black"
                />
                <img
                    v-else
                    :src="item.url"
                    :alt="item.original_filename"
                    class="size-full object-cover"
                />
            </div>
        </div>
        <div
            v-else
            class="grid grid-cols-2 gap-0.5 overflow-hidden"
            :class="frameClass"
        >
            <div
                v-for="(item, index) in media.slice(0, 4)"
                :key="item.id"
                class="relative overflow-hidden"
                :class="
                    media.length === 3 && index === 0
                        ? 'row-span-2'
                        : 'aspect-square'
                "
            >
                <VideoPreview
                    v-if="isVideo(item)"
                    :src="item.url"
                    video-class="size-full object-cover bg-black"
                />
                <img
                    v-else
                    :src="item.url"
                    :alt="item.original_filename"
                    class="size-full object-cover"
                />
                <span
                    v-if="media.length > 4 && index === 3"
                    class="absolute inset-0 flex items-center justify-center bg-black/60 text-xl font-semibold text-white"
                    >+{{ media.length - 4 }}</span
                >
            </div>
        </div>
        <span
            v-if="muteBadge && isVideo(first)"
            class="pointer-events-none absolute right-3 bottom-3 flex size-6 items-center justify-center rounded-full bg-black/70 text-white"
        >
            <IconVolumeOff class="size-3.5" />
        </span>
    </div>
</template>
