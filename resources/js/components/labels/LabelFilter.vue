<script setup lang="ts">
import { IconCheck, IconChevronDown, IconTag, IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import LabelBadge from '@/components/labels/LabelBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

interface Label {
    id: string;
    name: string;
    color: string;
}

interface Props {
    labels: Label[];
}

const props = defineProps<Props>();

const selectedIds = defineModel<string[]>({ required: true });

const open = ref(false);

const selectedLabels = computed<Label[]>(() =>
    props.labels.filter((l) => selectedIds.value.includes(l.id)),
);

const isSelected = (id: string) => selectedIds.value.includes(id);

const toggle = (id: string) => {
    selectedIds.value = isSelected(id)
        ? selectedIds.value.filter((existing) => existing !== id)
        : [...selectedIds.value, id];
};

const clear = () => {
    selectedIds.value = [];
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
                class="w-full justify-between gap-2 font-normal sm:w-auto"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <IconTag class="size-4 shrink-0 opacity-60" />

                    <template v-if="selectedLabels.length === 0">
                        <span class="text-foreground/70">{{ trans('posts.filter_by_label') }}</span>
                    </template>
                    <template v-else>
                        <div class="flex flex-wrap items-center gap-1">
                            <LabelBadge
                                v-for="label in selectedLabels.slice(0, 3)"
                                :key="label.id"
                                :label="label"
                            />
                            <span
                                v-if="selectedLabels.length > 3"
                                class="text-xs font-bold text-foreground/60"
                            >+{{ selectedLabels.length - 3 }}</span>
                        </div>
                    </template>
                </div>

                <TooltipProvider v-if="selectedIds.length" :delay-duration="200">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="ml-1 inline-flex size-4 shrink-0 cursor-pointer items-center justify-center rounded text-foreground/60 hover:text-foreground"
                                :aria-label="trans('posts.clear_label_filter')"
                                @click.stop="clear"
                                @pointerdown.stop
                                @mousedown.stop
                            >
                                <IconX class="size-4" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {{ trans('posts.clear_label_filter') }}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <IconChevronDown v-else class="size-4 shrink-0 opacity-50" />
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-(--reka-popover-trigger-width) min-w-[220px] p-0" align="start">
            <Command>
                <CommandInput :placeholder="trans('posts.label_search_placeholder')" />
                <CommandList>
                    <CommandEmpty>{{ trans('posts.no_labels') }}</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="label in labels"
                            :key="label.id"
                            :value="label.name"
                            @select="toggle(label.id)"
                        >
                            <LabelBadge :label="label" />
                            <IconCheck
                                :class="cn('ml-auto size-4', isSelected(label.id) ? 'opacity-100' : 'opacity-0')"
                            />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
