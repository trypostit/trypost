<script setup lang="ts">
import { IconChevronDown, IconSearch } from '@tabler/icons-vue';
import { createReusableTemplate } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { filterBySearch } from '@/lib/search';

interface FilterOption {
    id: string;
    label: string;
    searchText?: string;
    ariaLabel?: string;
    disabled?: boolean;
    disabledReason?: string;
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
        iconOnlyOnMobile?: boolean;
        bareOnMobile?: boolean;
        sheetBelowSm?: boolean;
    }>(),
    {
        contentClass: 'w-72',
        checkboxPosition: 'end',
        showHeader: true,
        extraCount: 0,
        align: 'end',
        compact: false,
        iconOnlyOnMobile: false,
        bareOnMobile: false,
        sheetBelowSm: false,
    },
);

const [DefinePanel, ReusePanel] = createReusableTemplate();
const [DefineOption, ReuseOption] = createReusableTemplate<{
    option: FilterOption;
}>();
const belowSm = useBelowBreakpoint('sm');
const asSheet = computed(() => props.sheetBelowSm && belowSm.value);

const emit = defineEmits<{ clear: []; close: [] }>();

const selectedIds = defineModel<string[]>({ required: true });
const open = ref(false);
const search = ref('');

watch(open, (isOpen) => {
    if (!isOpen) {
        search.value = '';
        emit('close');
    }
});

const visibleOptions = computed(() =>
    filterBySearch(props.options, search.value, (option) => [
        option.searchText ?? option.label,
    ]),
);

/** A disabled option says why on hover and focus; a sheet has no hover, so it shows the reason under the label. */
const showsReasonTooltip = (option: FilterOption): boolean =>
    Boolean(option.disabled && option.disabledReason) && !asSheet.value;

const reasonId = (option: FilterOption): string =>
    `${props.testId}-reason-text-${option.id}`;

const disabledIds = computed(
    () =>
        new Set(
            props.options
                .filter((option) => option.disabled)
                .map((option) => option.id),
        ),
);

const toggle = (id: string): void => {
    if (disabledIds.value.has(id)) {
        return;
    }

    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((selected) => selected !== id)
        : [...selectedIds.value, id];
};

const selectedCount = computed(
    () => selectedIds.value.length + props.extraCount,
);

const close = (): void => {
    open.value = false;
};

const toggleAll = (): void => {
    if (selectedCount.value) {
        selectedIds.value = [];
        emit('clear');

        return;
    }

    selectedIds.value = props.options
        .filter((option) => !option.disabled)
        .map((option) => option.id);
};
</script>

<template>
    <DefineOption v-slot="{ option }">
        <div
            class="flex items-center gap-3 rounded-lg text-sm transition-control"
            :class="[
                compact ? 'min-h-8 px-2 py-1.5 leading-5' : 'min-h-12 p-2',
                option.disabled
                    ? 'cursor-not-allowed opacity-60'
                    : 'cursor-pointer hover:bg-accent',
            ]"
            :aria-disabled="option.disabled ? 'true' : undefined"
            :data-disabled="option.disabled ? '' : undefined"
            :data-testid="`${testId}-option-${option.id}`"
            @click="toggle(option.id)"
        >
            <Checkbox
                v-if="checkboxPosition === 'start'"
                :model-value="selectedIds.includes(option.id)"
                :aria-label="option.ariaLabel ?? option.label"
                :disabled="option.disabled"
                :aria-describedby="option.disabledReason ? reasonId(option) : undefined"
                :data-testid="`${testId}-checkbox-${option.id}`"
                @click.stop
                @update:model-value="toggle(option.id)"
            />
            <span class="min-w-0 flex-1">
                <slot name="option" :option="option">{{
                    option.label
                }}</slot>
                <span
                    v-if="option.disabled && option.disabledReason"
                    :id="reasonId(option)"
                    :class="
                        asSheet
                            ? 'mt-0.5 block text-xs leading-[18px] text-muted-foreground'
                            : 'sr-only'
                    "
                    :data-testid="`${testId}-unavailable-${option.id}`"
                    >{{ option.disabledReason }}</span
                >
            </span>
            <Checkbox
                v-if="checkboxPosition === 'end'"
                :model-value="selectedIds.includes(option.id)"
                :aria-label="option.ariaLabel ?? option.label"
                :disabled="option.disabled"
                :aria-describedby="option.disabledReason ? reasonId(option) : undefined"
                :data-testid="`${testId}-checkbox-${option.id}`"
                @click.stop
                @update:model-value="toggle(option.id)"
            />
        </div>
    </DefineOption>
    <DefinePanel>
        <slot v-if="$slots.panel" name="panel" />
        <template v-else>
        <slot name="header" :sheet="asSheet" :close="close" />
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
            <TooltipProvider :delay-duration="0">
                <template v-for="option in visibleOptions" :key="option.id">
                    <Tooltip v-if="showsReasonTooltip(option)">
                        <TooltipTrigger
                            as="div"
                            tabindex="0"
                            class="rounded-lg focus-visible:outline-2 focus-visible:outline-ring"
                            :data-testid="`${testId}-reason-trigger-${option.id}`"
                        >
                            <ReuseOption :option="option" />
                        </TooltipTrigger>
                        <TooltipContent
                            side="top"
                            :data-testid="`${testId}-reason-${option.id}`"
                        >
                            {{ option.disabledReason }}
                        </TooltipContent>
                    </Tooltip>
                    <ReuseOption v-else :option="option" />
                </template>
            </TooltipProvider>
        </div>

        <slot name="footer" />
        </template>
    </DefinePanel>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <slot name="trigger" :open="open">
                <Button
                    type="button"
                    variant="ghost"
                    role="combobox"
                    :aria-expanded="open"
                    :aria-label="iconOnlyOnMobile ? label : undefined"
                    :class="[
                        'shrink-0 data-[state=open]:bg-accent',
                        { 'max-sm:border max-sm:border-border-strong': iconOnlyOnMobile && !bareOnMobile },
                        { 'max-sm:px-2': bareOnMobile },
                    ]"
                    :data-testid="`${testId}-filter`"
                >
                    <span
                        class="inline-flex size-4 shrink-0 items-center text-muted-foreground"
                    >
                        <slot name="icon" />
                    </span>
                    <span :class="{ 'max-sm:hidden': iconOnlyOnMobile }">{{
                        label
                    }}</span>
                    <span
                        v-if="selectedCount"
                        class="inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-primary px-1 text-xs font-medium text-primary-foreground"
                        :data-testid="`${testId}-count`"
                        >{{ selectedCount }}</span
                    >
                    <IconChevronDown
                        class="size-4 shrink-0 text-muted-foreground"
                        :class="{ 'max-sm:hidden': bareOnMobile }"
                    />
                </Button>
            </slot>
        </PopoverTrigger>

        <PopoverContent
            v-if="!asSheet"
            :class="['max-w-[calc(100vw-2rem)] p-3', contentClass]"
            :align="align"
        >
            <ReusePanel />
        </PopoverContent>
    </Popover>
    <BottomSheet
        v-if="asSheet"
        v-model:open="open"
        :title="label"
        :show-header="false"
        :test-id="`${testId}-sheet`"
        content-class="p-3"
    >
        <ReusePanel />
    </BottomSheet>
</template>
