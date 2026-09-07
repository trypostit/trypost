<script setup lang="ts">
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { ContentLanguageOption } from '@/types';

interface Props {
    /** Selectable languages as { value: locale code, label: native name, englishName: name in English }. */
    options: ContentLanguageOption[];
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Select a language…',
    searchPlaceholder: 'Search language…',
    emptyText: 'No language found.',
    disabled: false,
});

const value = defineModel<string | null>({ required: true });

const open = ref(false);

const selectedLabel = computed(() => props.options.find((option) => option.value === value.value)?.label ?? '');

const select = (code: string) => {
    value.value = code;
    open.value = false;
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                :disabled="disabled"
                class="w-full justify-between font-normal"
            >
                <span :class="selectedLabel ? '' : 'text-muted-foreground'">
                    {{ selectedLabel || placeholder }}
                </span>
                <IconChevronDown class="ms-2 size-4 shrink-0 opacity-50" />
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-(--reka-popover-trigger-width) p-0" align="start">
            <Command>
                <CommandInput :placeholder="searchPlaceholder" />
                <CommandList>
                    <CommandEmpty>{{ emptyText }}</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="option in options"
                            :key="option.value"
                            :value="option.value"
                            @select="select(option.value)"
                        >
                            {{ option.label }}
                            <span v-if="option.englishName" class="sr-only">{{ option.englishName }}</span>
                            <IconCheck :class="cn('ms-auto size-4', value === option.value ? 'opacity-100' : 'opacity-0')" />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
