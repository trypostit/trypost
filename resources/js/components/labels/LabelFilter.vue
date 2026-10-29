<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { IconPlus, IconSettings, IconTag, IconX } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import FilterEmptyState from '@/components/FilterEmptyState.vue';
import LabelForm from '@/components/labels/LabelForm.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { matchesSearch } from '@/lib/search';
import {
    index as labelsIndex,
    store as labelsStore,
} from '@/routes/app/labels';
import type { FlashData } from '@/types';

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
        sheetBelowSm?: boolean;
    }>(),
    {
        testId: 'posts-label',
        showUntagged: true,
        align: 'end',
        sheetBelowSm: false,
    },
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

const DEFAULT_COLOR = '#7c3aed';

const creating = ref(false);
const form = useForm({ name: '', color: DEFAULT_COLOR });

const startCreate = (): void => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};

const showList = (): void => {
    creating.value = false;
};

const saveLabel = (): void => {
    form.post(labelsStore.url(), {
        preserveState: true,
        preserveScroll: true,
        onFlash: (flash) => {
            const { createdLabel } = flash as FlashData;

            if (createdLabel) {
                emit('created', createdLabel);
            }
        },
        onSuccess: () => {
            form.reset();
            creating.value = false;
        },
    });
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
        icon-only-on-mobile
        content-class="w-80"
        checkbox-position="start"
        :align="align"
        :sheet-below-sm="sheetBelowSm"
        @close="showList"
    >
        <template v-if="creating" #panel>
            <h3 class="mb-3 text-sm font-semibold">
                {{ $t('labels.create.title') }}
            </h3>
            <LabelForm
                v-model:name="form.name"
                v-model:color="form.color"
                :id-prefix="`${testId}-new`"
                compact
                :errors="form.errors"
                :processing="form.processing"
                @submit="saveLabel"
                @cancel="showList"
            />
        </template>
        <template
            v-if="labels.length > 0 || sheetBelowSm"
            #header="{ sheet, close }"
        >
            <div
                v-if="labels.length > 0 || sheet"
                class="mb-3 flex items-center justify-between gap-2"
            >
                <h3 class="text-sm font-semibold">
                    {{ $t('labels.title') }}
                </h3>
                <div class="flex items-center gap-1">
                    <Button
                        v-if="labels.length > 0"
                        type="button"
                        size="icon-sm"
                        variant="outline"
                        :aria-label="$t('labels.create.title')"
                        :data-testid="`${testId}-create`"
                        @click="startCreate"
                    >
                        <IconPlus class="size-4" />
                    </Button>
                    <Button
                        v-if="sheet"
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        :aria-label="$t('common.close')"
                        :data-testid="`${testId}-close`"
                        @click="close"
                    >
                        <IconX class="size-4" />
                    </Button>
                </div>
            </div>
        </template>
        <template v-if="$slots.trigger" #trigger="slotProps">
            <slot name="trigger" v-bind="slotProps" />
        </template>
        <template #icon>
            <IconTag class="size-4" />
        </template>
        <template #before-options="{ search }">
            <div v-if="showUntagged && labels.length > 0">
                <label
                    v-if="matchesSearch(search, [$t('posts.label_filter_untagged')])"
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
                    :data-testid="`${testId}-empty-create`"
                    @click="startCreate"
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
</template>
