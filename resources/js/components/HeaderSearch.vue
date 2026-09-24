<script setup lang="ts">
import { IconSearch } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

const query = defineModel<string>({ required: true });

defineProps<{
    placeholder: string;
}>();
</script>

<template>
    <div class="contents">
        <Popover>
            <PopoverTrigger as-child>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    class="md:hidden"
                    :aria-label="placeholder"
                    data-testid="header-search-trigger"
                >
                    <IconSearch class="size-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" class="w-64 p-3 md:hidden">
                <Input
                    v-model="query"
                    :aria-label="placeholder"
                    :placeholder="placeholder"
                    data-testid="header-search-mobile-input"
                />
            </PopoverContent>
        </Popover>

        <div class="relative hidden md:block">
            <IconSearch
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="query"
                :aria-label="placeholder"
                :placeholder="placeholder"
                class="w-64 pl-9"
                data-testid="header-search-input"
            />
        </div>
    </div>
</template>
