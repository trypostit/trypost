<script setup lang="ts">
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { computed, onMounted, ref, watch } from 'vue';

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
import { cn } from '@/lib/utils';

interface Props {
    /** List of font family names. Each will be loaded via Google Fonts so the
     *  preview text in the dropdown renders in the actual typeface. */
    fonts: string[];
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    name?: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Select a font…',
    searchPlaceholder: 'Search font…',
    emptyText: 'No fonts match.',
    disabled: false,
});

const value = defineModel<string>({ required: true });

const open = ref(false);

// Build a single Google Fonts URL that loads ALL options at once. This way the
// dropdown preview shows each family in its own typeface without firing a
// request per item. We only request the regular weight to keep payload small.
const googleFontsUrl = computed(() => {
    const families = props.fonts
        .map((f) => `family=${encodeURIComponent(f)}:wght@400`)
        .join('&');
    return `https://fonts.googleapis.com/css2?${families}&display=swap`;
});

let injectedLink: HTMLLinkElement | null = null;

const ensureFontsLoaded = () => {
    if (injectedLink || typeof document === 'undefined') return;

    injectedLink = document.createElement('link');
    injectedLink.rel = 'stylesheet';
    injectedLink.href = googleFontsUrl.value;
    injectedLink.dataset.fontPicker = 'true';
    document.head.appendChild(injectedLink);
};

onMounted(ensureFontsLoaded);

// Reload the stylesheet if the list of fonts changes (rare).
watch(googleFontsUrl, (next) => {
    if (injectedLink) {
        injectedLink.href = next;
    }
});

const select = (font: string) => {
    value.value = font;
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
                <span :style="{ fontFamily: `'${value}', sans-serif` }">
                    {{ value || placeholder }}
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
                            v-for="font in fonts"
                            :key="font"
                            :value="font"
                            @select="select(font)"
                        >
                            <span :style="{ fontFamily: `'${font}', sans-serif` }">{{ font }}</span>
                            <IconCheck
                                :class="cn('ms-auto size-4', value === font ? 'opacity-100' : 'opacity-0')"
                            />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>

    <input v-if="name" type="hidden" :name="name" :value="value" />
</template>
