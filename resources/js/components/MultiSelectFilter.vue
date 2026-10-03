<script setup lang="ts">
import { IconChevronDown, IconSearch } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

interface FilterOption {
    id: string;
    label: string;
    searchText?: string;
    ariaLabel?: string;
}

const props = withDefaults(
    defineProps<{
        options: FilterOption[];
        label: string;
        searchPlaceholder: string;
        emptyMessage: string;
        selectAllLabel: string;
        deselectAllLabel: string;
        testId: string;
        contentClass?: string;
        checkboxPosition?: 'start' | 'end';
        showHeader?: boolean;
        extraCount?: number;
        align?: 'start' | 'center' | 'end';
        compact?: boolean;
    }>(),
    {
        contentClass: 'w-72',
        checkboxPosition: 'end',
        showHeader: true,
        extraCount: 0,
        align: 'end',
        compact: false,
    },
);

const emit = defineEmits<{ clear: [] }>();

const selectedIds = defineModel<string[]>({ required: true });
const open = ref(false);
const search = ref('');

watch(open, (isOpen) => {
    if (!isOpen) search.value = '';
});

const visibleOptions = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    return props.options.filter((option) =>
        (option.searchText ?? option.label).toLocaleLowerCase().includes(query),
    );
});

const toggle = (id: string): void => {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((selected) => selected !== id)
        : [...selectedIds.value, id];
};

const selectedCount = computed(
    () => selectedIds.value.length + props.extraCount,
);

const toggleAll = (): void => {
    if (selectedCount.value) {
        selectedIds.value = [];
        emit('clear');

        return;
    }

    selectedIds.value = props.options.map((option) => option.id);
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <slot name="trigger" :open="open">
                <Button
                    type="button"
                    variant="ghost"
                    role="combobox"
                    :aria-expanded="open"
                    class="shrink-0 data-[state=open]:bg-accent"
                    :data-testid="`${testId}-filter`"
                >
                    <span
                        class="inline-flex size-4 shrink-0 items-center text-muted-foreground"
                    >
                        <slot name="icon" />
                    </span>
                    <span>{{ label }}</span>
                    <span
                        v-if="selectedCount"
                        class="inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-primary px-1 text-xs font-medium text-primary-foreground"
                        :data-testid="`${testId}-count`"
                        >{{ selectedCount }}</span
                    >
                    <IconChevronDown
                        class="size-4 shrink-0 text-muted-foreground"
                    />
                </Button>
            </slot>
        </PopoverTrigger>

        <PopoverContent
            :class="['max-w-[calc(100vw-2rem)] p-3', contentClass]"
            :align="align"
        >
            <div class="relative">
                <IconSearch
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    :placeholder="searchPlaceholder"
                    :aria-label="searchPlaceholder"
                    class="pl-9"
                    :data-testid="`${testId}-search`"
                />
            </div>

            <div
                v-if="showHeader"
                class="mt-2 flex items-center justify-between p-2 text-sm text-foreground"
            >
                <span>{{ label }}</span>
                <button
                    type="button"
                    class="rounded-sm transition-control hover:text-primary-text"
                    :data-testid="`${testId}-toggle-all`"
                    @click="toggleAll"
                >
                    {{ selectedCount ? deselectAllLabel : selectAllLabel }}
                </button>
            </div>

            <slot name="before-options" :search="search" />

            <div
                class="max-h-72 overflow-y-auto"
                role="group"
                :aria-label="label"
            >
                <template v-if="!visibleOptions.length">
                    <slot name="empty">
                        <p
                            class="px-2 py-6 text-center text-sm text-muted-foreground"
                        >
                            {{ emptyMessage }}
                        </p>
                    </slot>
                </template>
                <div
                    v-for="option in visibleOptions"
                    :key="option.id"
                    class="flex cursor-pointer items-center gap-3 rounded-lg text-sm transition-control hover:bg-accent"
                    :class="compact ? 'min-h-8 px-2 py-1.5 leading-5' : 'min-h-12 p-2'"
                    :data-testid="`${testId}-option-${option.id}`"
                    @click="toggle(option.id)"
                >
                    <Checkbox
                        v-if="checkboxPosition === 'start'"
                        :model-value="selectedIds.includes(option.id)"
                        :aria-label="option.ariaLabel ?? option.label"
                        :data-testid="`${testId}-checkbox-${option.id}`"
                        @click.stop
                        @update:model-value="toggle(option.id)"
                    />
                    <span class="min-w-0 flex-1">
                        <slot name="option" :option="option">{{
                            option.label
                        }}</slot>
                    </span>
                    <Checkbox
                        v-if="checkboxPosition === 'end'"
                        :model-value="selectedIds.includes(option.id)"
                        :aria-label="option.ariaLabel ?? option.label"
                        :data-testid="`${testId}-checkbox-${option.id}`"
                        @click.stop
                        @update:model-value="toggle(option.id)"
                    />
                </div>
            </div>

            <slot name="footer" />
        </PopoverContent>
    </Popover>
</template>
