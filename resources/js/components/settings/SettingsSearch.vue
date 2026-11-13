<script setup lang="ts">
import { IconSearch } from '@tabler/icons-vue';

import { Input } from '@/components/ui/input';

const query = defineModel<string>({ required: true });

withDefaults(
    defineProps<{
        placeholder: string;
        /** The height of the inputs and filters around it, for a toolbar row. */
        compact?: boolean;
    }>(),
    { compact: false },
);
</script>

<template>
    <div class="relative">
        <IconSearch
            class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <Input
            v-model="query"
            :aria-label="placeholder"
            :placeholder="placeholder"
            class="ps-9"
            :class="compact ? 'h-8' : 'h-10'"
            data-testid="header-search-input"
        />
    </div>
</template>
