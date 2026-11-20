<script setup lang="ts">
import { IconChevronDown } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import LanguagePicker from '@/components/LanguagePicker.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { Language } from '@/types';

const props = defineProps<{
    languages: Language[];
    testid?: string;
    label?: string;
    triggerClass?: string;
}>();

const model = defineModel<string>({ required: true });
const open = ref(false);
const id = computed(() => props.testid ?? 'language');

const selected = computed(() =>
    props.languages.find((language) => language.code === model.value),
);

const choose = (code: string): void => {
    if (code !== model.value) {
        model.value = code;
    }

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
                :aria-label="label"
                :class="
                    cn('max-w-full data-[state=open]:bg-accent', triggerClass)
                "
                :data-testid="`${id}-trigger`"
            >
                <img
                    v-if="selected?.flag"
                    :src="selected.flag"
                    alt=""
                    class="h-3.5 w-5 shrink-0 rounded-xs object-cover"
                />
                <span class="truncate" :data-testid="`${id}-value`">{{
                    selected?.name ?? model
                }}</span>
                <IconChevronDown class="size-4 text-muted-foreground" />
            </Button>
        </PopoverTrigger>

        <PopoverContent
            align="end"
            class="w-[306px] max-w-[calc(100vw-2rem)] p-3"
        >
            <LanguagePicker
                :languages="languages"
                :selected="model"
                :testid="id"
                @select="choose"
            />
        </PopoverContent>
    </Popover>
</template>
