<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconPlus, IconSettings, IconTag } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import FilterEmptyState from '@/components/FilterEmptyState.vue';
import CreateDialog from '@/components/labels/CreateDialog.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { index as labelsIndex } from '@/routes/app/labels';

interface Label {
    id: string;
    name: string;
    color: string;
}

const props = withDefaults(
    defineProps<{
        labels: Label[];
        testId?: string;
        showUntagged?: boolean;
        align?: 'start' | 'center' | 'end';
    }>(),
    { testId: 'posts-label', showUntagged: true, align: 'end' },
);
const emit = defineEmits<{ created: [label: Label] }>();
const selectedIds = defineModel<string[]>({ required: true });
const untagged = defineModel<boolean>('untagged', { default: false });

const options = computed(() =>
    props.labels.map((label) => ({ id: label.id, label: label.name })),
);

const labelsById = computed(
    () => new Map(props.labels.map((label) => [label.id, label])),
);

const labelFor = (id: string): Label => labelsById.value.get(id)!;

const matches = (text: string, search: string): boolean =>
    text.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase());

const createDialogOpen = ref(false);

const openCreateDialog = (): void => {
    createDialogOpen.value = true;
};

const clear = (): void => {
    selectedIds.value = [];
    untagged.value = false;
};
</script>

<template>
    <MultiSelectFilter
        v-model="selectedIds"
        :options="options"
        :label="$t('posts.filter_by_label')"
        :search-placeholder="$t('posts.label_search_placeholder')"
        :empty-message="$t('posts.no_labels')"
        :select-all-label="$t('posts.composer.select_all')"
        :deselect-all-label="$t('posts.composer.deselect_all')"
        :test-id="testId"
        :show-header="false"
        :extra-count="untagged ? 1 : 0"
        compact
        content-class="w-64"
        checkbox-position="start"
        :align="align"
    >
        <template v-if="$slots.trigger" #trigger="slotProps">
            <slot name="trigger" v-bind="slotProps" />
        </template>
        <template #icon>
            <IconTag class="size-4" />
        </template>
        <template #before-options="{ search }">
            <div v-if="showUntagged && labels.length > 0" class="pt-2">
                <label
                    v-if="matches($t('posts.label_filter_untagged'), search)"
                    class="flex min-h-8 cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 text-sm leading-5 transition-control hover:bg-accent"
                    :class="{ 'bg-accent': untagged }"
                    :data-testid="`${testId}-untagged`"
                >
                    <Checkbox
                        v-model="untagged"
                        :data-testid="`${testId}-untagged-checkbox`"
                    />
                    <span
                        class="flex min-w-0 flex-1 items-center gap-2.5 text-foreground"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-full border border-dashed border-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{
                            $t('posts.label_filter_untagged')
                        }}</span>
                    </span>
                </label>
            </div>
        </template>
        <template #option="{ option }">
            <span
                class="flex min-w-0 items-center gap-2.5 text-sm leading-5 text-foreground"
            >
                <span
                    class="size-2.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: labelFor(option.id).color }"
                    aria-hidden="true"
                />
                <span class="truncate">{{ labelFor(option.id).name }}</span>
            </span>
        </template>
        <template v-if="labels.length === 0" #empty>
            <FilterEmptyState
                :icon="IconTag"
                :title="$t('posts.no_labels')"
                :test-id="`${testId}-empty`"
            >
                <Button
                    type="button"
                    size="sm"
                    :data-testid="`${testId}-create`"
                    @click="openCreateDialog"
                >
                    <IconPlus class="size-4" />
                    {{ $t('labels.create.submit') }}
                </Button>
            </FilterEmptyState>
        </template>
        <template v-if="labels.length > 0" #footer>
            <div
                class="-mx-3 mt-2 flex items-center justify-between border-t border-border px-3 pt-2"
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
                <Button
                    as-child
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="$t('posts.label_filter_manage')"
                >
                    <Link
                        :href="labelsIndex.url()"
                        :data-testid="`${testId}-settings`"
                    >
                        <IconSettings class="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </template>
    </MultiSelectFilter>
    <CreateDialog
        v-model:open="createDialogOpen"
        @created="emit('created', $event)"
    />
</template>
