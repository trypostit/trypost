<script setup lang="ts">
import { IconLayoutGrid } from '@tabler/icons-vue';
import { computed } from 'vue';

import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import { Button } from '@/components/ui/button';
import type { ContentTypeOption } from '@/types/analytics';

const props = withDefaults(
    defineProps<{
        types: ContentTypeOption[];
        testId?: string;
    }>(),
    { testId: 'insights-post-type' },
);
const selectedTypes = defineModel<string[]>({ required: true });

const options = computed(() =>
    props.types.map((type) => ({ id: type.value, label: type.label })),
);

const clear = (): void => {
    selectedTypes.value = [];
};
</script>

<template>
    <MultiSelectFilter
        v-model="selectedTypes"
        :options="options"
        :label="$t('analytics.channel.filters.post_type')"
        :search-placeholder="$t('analytics.channel.filters.post_type_search')"
        :empty-message="$t('analytics.channel.filters.no_post_types')"
        :select-all-label="$t('posts.composer.select_all')"
        :deselect-all-label="$t('posts.composer.deselect_all')"
        :test-id="testId"
        :show-header="false"
        compact
        icon-only-on-mobile
        content-class="w-64"
        checkbox-position="start"
    >
        <template #icon>
            <IconLayoutGrid class="size-4" />
        </template>
        <template #footer>
            <div
                class="-mx-3 mt-2 flex items-center border-t border-border px-3 pt-2"
            >
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :data-testid="`${testId}-clear`"
                    @click="clear"
                >
                    {{ $t('posts.label_filter_clear') }}
                </Button>
            </div>
        </template>
    </MultiSelectFilter>
</template>
