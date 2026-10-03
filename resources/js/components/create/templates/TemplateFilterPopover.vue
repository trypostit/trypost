<script setup lang="ts">
import { IconChevronDown, IconFilter, IconFilter2 } from '@tabler/icons-vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { TemplateFacet, TemplateFacetFilters } from '@/types/template';

const props = defineProps<{
    facets: TemplateFacetFilters;
    testId: string;
    iconOnly?: boolean;
}>();

const types = defineModel<string[]>('types', { required: true });
const audiences = defineModel<string[]>('audiences', { required: true });
const formats = defineModel<string[]>('formats', { required: true });
const goals = defineModel<string[]>('goals', { required: true });

const groups = computed<
    {
        facet: TemplateFacet;
        values: string[];
        selected: string[];
        set: (value: string[]) => void;
    }[]
>(() => [
    {
        facet: 'type',
        values: props.facets.types,
        selected: types.value,
        set: (value) => (types.value = value),
    },
    {
        facet: 'audience',
        values: props.facets.audiences,
        selected: audiences.value,
        set: (value) => (audiences.value = value),
    },
    {
        facet: 'format',
        values: props.facets.formats,
        selected: formats.value,
        set: (value) => (formats.value = value),
    },
    {
        facet: 'goal',
        values: props.facets.goals,
        selected: goals.value,
        set: (value) => (goals.value = value),
    },
]);

const activeCount = computed(
    () =>
        types.value.length +
        audiences.value.length +
        formats.value.length +
        goals.value.length,
);

const toggle = (
    selected: string[],
    set: (value: string[]) => void,
    value: string,
): void => {
    set(
        selected.includes(value)
            ? selected.filter((item) => item !== value)
            : [...selected, value],
    );
};

const reset = (): void => {
    types.value = [];
    audiences.value = [];
    formats.value = [];
    goals.value = [];
};
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button
                v-if="iconOnly"
                type="button"
                variant="ghost"
                size="icon"
                class="relative size-8 shrink-0 text-muted-foreground data-[state=open]:bg-accent"
                :aria-label="$t('create.templates.filter')"
                :data-testid="testId"
            >
                <IconFilter2 class="size-4" />
                <span
                    v-if="activeCount"
                    aria-hidden="true"
                    class="absolute top-1 end-1 size-1.5 rounded-full bg-primary"
                />
            </Button>
            <Button
                v-else
                type="button"
                variant="ghost"
                class="shrink-0 data-[state=open]:bg-accent"
                :data-testid="testId"
            >
                <IconFilter class="size-4 text-muted-foreground" />
                <span class="max-sm:sr-only">{{
                    $t('create.templates.filter')
                }}</span>
                <span
                    v-if="activeCount"
                    class="inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-secondary px-1 text-xs font-medium"
                    >{{ activeCount }}</span
                >
                <IconChevronDown
                    class="size-4 shrink-0 text-muted-foreground"
                />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            class="max-h-[70dvh] w-72 max-w-[calc(100vw-2rem)] overflow-y-auto p-3"
        >
            <section
                v-for="group in groups"
                :key="group.facet"
                class="mb-3"
                :aria-label="$t(`create.templates.facets.${group.facet}.title`)"
            >
                <h4 class="px-2 pb-1 text-xs font-medium text-muted-foreground">
                    {{ $t(`create.templates.facets.${group.facet}.title`) }}
                </h4>
                <div
                    v-for="value in group.values"
                    :key="value"
                    class="flex min-h-9 cursor-pointer items-center gap-3 rounded-lg px-2 text-sm transition-control hover:bg-accent"
                    :data-testid="`${testId}-${group.facet}-${value}`"
                    @click="toggle(group.selected, group.set, value)"
                >
                    <Checkbox
                        :model-value="group.selected.includes(value)"
                        :aria-label="
                            $t(`create.templates.facets.${group.facet}.${value}`)
                        "
                        @click.stop
                        @update:model-value="
                            toggle(group.selected, group.set, value)
                        "
                    />
                    <span>{{
                        $t(`create.templates.facets.${group.facet}.${value}`)
                    }}</span>
                </div>
            </section>
            <Button
                type="button"
                variant="outline"
                class="w-full"
                :disabled="activeCount === 0"
                :data-testid="`${testId}-reset`"
                @click="reset"
            >
                {{ $t('create.templates.reset_filters') }}
            </Button>
        </PopoverContent>
    </Popover>
</template>
