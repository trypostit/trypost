<script setup lang="ts">
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import type { Component } from 'vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * An option shows `label` as is (a weekday from dayjs) or resolves `labelKey`
 * with `$t` at render time, never in script.
 */
export interface SettingsSelectOption {
    value: string;
    label?: string;
    labelKey?: string;
    icon?: Component;
    image?: string;
}

const props = defineProps<{
    options: SettingsSelectOption[];
    testid: string;
    label: string;
}>();

const model = defineModel<string>({ required: true });

const selected = computed(() =>
    props.options.find((option) => option.value === model.value),
);

const choose = (value: unknown): void => {
    if (typeof value === 'string' && value !== model.value) {
        model.value = value;
    }
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="outline"
                class="max-w-full data-[state=open]:bg-accent"
                :aria-label="label"
                :data-testid="`${testid}-trigger`"
            >
                <component
                    :is="selected.icon"
                    v-if="selected?.icon"
                    class="size-4 text-muted-foreground"
                />
                <img
                    v-else-if="selected?.image"
                    :src="selected.image"
                    alt=""
                    class="h-3.5 w-5 shrink-0 rounded-xs object-cover"
                />
                <span class="truncate" :data-testid="`${testid}-value`">{{
                    selected?.labelKey
                        ? $t(selected.labelKey)
                        : (selected?.label ?? model)
                }}</span>
                <IconChevronDown class="size-4 text-muted-foreground" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="max-h-80 min-w-36">
            <DropdownMenuRadioGroup
                :model-value="model"
                @update:model-value="choose"
            >
                <DropdownMenuRadioItem
                    v-for="option in options"
                    :key="option.value"
                    :value="option.value"
                    :data-testid="`${testid}-option-${option.value}`"
                >
                    <template #indicator-icon>
                        <IconCheck class="size-4" />
                    </template>
                    <component
                        :is="option.icon"
                        v-if="option.icon"
                        class="size-4 text-muted-foreground"
                    />
                    <img
                        v-else-if="option.image"
                        :src="option.image"
                        alt=""
                        class="h-3.5 w-5 shrink-0 rounded-xs object-cover"
                    />
                    {{ option.labelKey ? $t(option.labelKey) : option.label }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
