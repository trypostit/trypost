<script setup lang="ts">
import { IconSearch, IconX } from '@tabler/icons-vue';
import { watchDebounced } from '@vueuse/core';
import { nextTick, ref, useTemplateRef } from 'vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const search = defineModel<string>({ required: true });

const expanded = ref(search.value !== '');
const input = useTemplateRef<InstanceType<typeof Input>>('input');
const draft = ref(search.value);

const emit = defineEmits<{ commit: [] }>();

watchDebounced(
    draft,
    (value) => {
        if (value !== search.value) {
            search.value = value;
            emit('commit');
        }
    },
    { debounce: 300 },
);

const expand = async (): Promise<void> => {
    expanded.value = true;
    await nextTick();
    (input.value?.$el as HTMLInputElement | undefined)?.focus();
};

const collapse = (): void => {
    draft.value = '';
    expanded.value = false;
};
</script>

<template>
    <div class="flex items-center" data-testid="templates-search-wrapper">
        <Button
            v-if="!expanded"
            type="button"
            variant="ghost"
            data-testid="templates-search"
            @click="expand"
        >
            <IconSearch class="size-4 text-muted-foreground" />
            <span class="max-sm:sr-only">{{
                $t('create.templates.search')
            }}</span>
        </Button>
        <div v-else class="relative">
            <IconSearch
                class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                ref="input"
                v-model="draft"
                type="text"
                :placeholder="$t('create.templates.search_placeholder')"
                :aria-label="$t('create.templates.search_placeholder')"
                class="h-8 w-48 ps-8 pe-8 sm:w-56"
                data-testid="templates-search-input"
                @keydown.esc="collapse"
            />
            <button
                type="button"
                class="absolute end-2 top-1/2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
                :aria-label="$t('common.close')"
                data-testid="templates-search-clear"
                @click="collapse"
            >
                <IconX class="size-4" />
            </button>
        </div>
    </div>
</template>
