<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { getDeletedOnNetworkStatusConfig } from '@/composables/usePostStatus';

const status = getDeletedOnNetworkStatusConfig();
</script>

<template>
    <Badge :variant="status.variant" class="h-6 gap-1 px-2 [&>svg]:size-4">
        <component :is="status.icon" />
        {{ status.label }}
    </Badge>
</template>
