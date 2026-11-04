<script setup lang="ts">
import { IconTrashX } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';
</script>

<template>
    <Badge variant="destructive-subtle" class="h-6 gap-1 px-2 [&>svg]:size-4">
        <IconTrashX />
        {{ $t('posts.publish.deleted_on_network.badge') }}
    </Badge>
</template>
