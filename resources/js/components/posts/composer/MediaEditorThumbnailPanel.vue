<script setup lang="ts">
import { computed } from 'vue';

import { formatCoverOffset, type MediaEdit } from '@/lib/mediaEditor';

const props = defineProps<{
    duration: number | null;
}>();

const edit = defineModel<MediaEdit>('edit', { required: true });

const maxSeconds = computed(() =>
    Math.max(0, Math.floor((props.duration ?? 0) * 10) / 10),
);
const seconds = computed({
    get: () => (edit.value.coverOffsetMs ?? 0) / 1000,
    set: (value: number) => {
        edit.value.coverOffsetMs = Math.round(value * 1000);
    },
});
</script>

<template>
    <div class="space-y-3">
        <div class="space-y-1">
            <h3 class="text-sm font-medium">
                {{ $t('posts.composer.media_editor.thumbnail_heading') }}
            </h3>
            <p class="text-xs text-muted-foreground">
                {{ $t('posts.composer.media_editor.thumbnail_hint') }}
            </p>
        </div>
        <label class="block space-y-1 text-sm">
            <span class="flex justify-between">
                <span>{{
                    $t('posts.composer.media_editor.thumbnail_tab')
                }}</span>
                <span
                    class="text-muted-foreground tabular-nums"
                    data-testid="media-editor-cover-value"
                    >{{ formatCoverOffset(edit.coverOffsetMs ?? 0) }}</span
                >
            </span>
            <input
                v-model.number="seconds"
                data-testid="media-editor-cover"
                type="range"
                min="0"
                :max="maxSeconds"
                step="0.1"
                :aria-valuetext="
                    $t('posts.composer.media_editor.thumbnail_value', {
                        seconds: seconds.toFixed(1),
                    })
                "
                class="editor-range w-full"
            />
        </label>
    </div>
</template>
