<script setup lang="ts">
import { IconSearch } from '@tabler/icons-vue';

import { Input } from '@/components/ui/input';

const query = defineModel<string>({ required: true });

defineProps<{
    placeholder: string;
}>();
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
            class="h-10 ps-9"
            data-testid="header-search-input"
        />
    </div>
</template>
