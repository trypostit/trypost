<script setup lang="ts">
import {
    ADJUSTMENTS,
    blankMediaEdit,
    cssFilter,
    FILTER_PRESETS,
    type MediaEdit,
} from '@/lib/mediaEditor';

defineProps<{
    src: string;
}>();

const edit = defineModel<MediaEdit>('edit', { required: true });

const selectFilter = (filter: MediaEdit['filter']): void => {
    edit.value.filter = filter;
};

const previewFilter = (filter: MediaEdit['filter']): string =>
    cssFilter({ ...blankMediaEdit(), filter });
</script>

<template>
    <div class="space-y-6">
        <section class="space-y-2">
            <h3 class="text-sm font-medium">
                {{ $t('posts.composer.media_editor.filters_heading') }}
            </h3>
            <div
                class="grid grid-cols-4 gap-1 rounded-lg border border-border p-1"
            >
                <button
                    v-for="filter in FILTER_PRESETS"
                    :key="filter"
                    type="button"
                    :data-testid="`media-filter-${filter}`"
                    :aria-pressed="edit.filter === filter"
                    class="overflow-hidden rounded-md p-1 text-xs transition-colors"
                    :class="
                        edit.filter === filter
                            ? 'bg-primary-selected ring-1 ring-primary-strong'
                            : 'hover:bg-muted'
                    "
                    @click="selectFilter(filter)"
                >
                    <img
                        :src="src"
                        alt=""
                        class="aspect-square w-full rounded object-cover"
                        :style="{ filter: previewFilter(filter) }"
                    />
                    <span class="block truncate px-1 py-1">{{
                        $t(`posts.composer.media_editor.filter_${filter}`)
                    }}</span>
                </button>
            </div>
        </section>

        <section class="space-y-4">
            <h3 class="text-sm font-medium">
                {{ $t('posts.composer.media_editor.adjust_heading') }}
            </h3>
            <label
                v-for="key in ADJUSTMENTS"
                :key="key"
                class="block space-y-1 text-sm"
            >
                <span class="flex justify-between">
                    <span>{{ $t(`posts.composer.media_editor.${key}`) }}</span>
                    <span class="text-muted-foreground"
                        >{{ edit.adjustments[key] }}%</span
                    >
                </span>
                <input
                    v-model.number="edit.adjustments[key]"
                    :data-testid="`media-adjust-${key}`"
                    type="range"
                    min="-100"
                    max="100"
                    class="editor-range w-full"
                />
            </label>
        </section>
    </div>
</template>
