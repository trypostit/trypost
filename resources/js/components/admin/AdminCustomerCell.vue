<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { customerName } from '@/lib/adminUsers';

/**
 * A customer in an admin table: their name, linking to their profile
 * while the user still exists, over a muted line (their email, a plan).
 */
const props = defineProps<{
    name: string | null;
    /** Null once the user was deleted: the name shows muted, linking nowhere. */
    href: string | null;
    detail: string | null;
}>();

const shownName = computed(() => customerName(props.name));
</script>

<template>
    <div class="grid min-w-0">
        <Link
            v-if="href"
            :href="href"
            class="truncate text-sm font-emphasis text-foreground hover:underline"
            >{{ shownName }}</Link
        >
        <span v-else class="truncate text-sm text-muted-foreground">{{ shownName }}</span>
        <span v-if="detail" class="truncate text-xs text-muted-foreground">{{ detail }}</span>
    </div>
</template>
