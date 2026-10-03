<script setup lang="ts">
import { IconPlayerPlayFilled } from '@tabler/icons-vue';

import VerticalMediaCanvas from '@/components/posts/previews/VerticalMediaCanvas.vue';
import type { MediaItem } from '@/types/media';

import type { PreviewAction } from './types';

withDefaults(
    defineProps<{
        media: MediaItem[];
        actions?: PreviewAction[];
    }>(),
    { actions: () => [] },
);
</script>

<template>
    <div
        class="relative aspect-[9/16] w-full overflow-hidden bg-black text-[13px] leading-[18px] text-white"
    >
        <VerticalMediaCanvas :media="media">
            <template #placeholder>
                <div
                    class="flex size-full items-center justify-center bg-gradient-to-b from-white/5 to-white/15"
                >
                    <slot name="placeholder">
                        <IconPlayerPlayFilled class="size-10 text-white/25" />
                    </slot>
                </div>
            </template>
        </VerticalMediaCanvas>
        <slot name="top" />
        <div
            class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 via-black/25 to-transparent"
        />
        <div
            class="pointer-events-none absolute inset-x-0 bottom-0 flex items-end gap-3 p-3 [&_button]:pointer-events-auto [&_details]:pointer-events-auto"
        >
            <div class="min-w-0 flex-1 space-y-2 drop-shadow-sm">
                <slot name="footer" />
            </div>
            <div
                class="flex w-12 shrink-0 flex-col items-center gap-4 drop-shadow-sm"
                data-testid="preview-rail"
            >
                <slot name="rail-top" />
                <span
                    v-for="(action, index) in actions"
                    :key="index"
                    class="flex flex-col items-center gap-1 text-xs font-medium"
                    data-testid="preview-rail-action"
                >
                    <component :is="action.icon" class="size-7" stroke-width="1.75" />
                    <span v-if="action.labelKey">{{ $t(action.labelKey) }}</span>
                </span>
                <slot name="corner" />
            </div>
        </div>
    </div>
</template>
