<script setup lang="ts">
import { IconExternalLink } from '@tabler/icons-vue';
import StripeBadge from '@thesvg/vue/stripe-badge';

defineProps<{
    url: string;
    customerId?: string | null;
}>();
</script>

<template>
    <a
        :href="url"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-2 rounded-lg border border-border bg-card py-1 ps-1.5 pe-2.5 text-sm font-emphasis text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
        data-testid="admin-user-stripe-link"
    >
        <StripeBadge aria-hidden="true" class="size-5 shrink-0" />
        <span v-if="customerId" class="font-mono text-xs">{{ customerId }}</span>
        <span v-else>Open in Stripe</span>
        <IconExternalLink class="size-3.5 text-muted-foreground" />
    </a>
</template>
