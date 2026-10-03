<script setup lang="ts">
import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';

import type { PreviewAccount } from './types';

defineProps<{
    account: PreviewAccount;
    name: string;
}>();
</script>

<template>
    <div class="absolute inset-x-0 top-0 space-y-2.5 p-3 drop-shadow-sm">
        <div class="h-0.5 overflow-hidden rounded-full bg-white/35">
            <div class="h-full w-1/3 rounded-full bg-white" />
        </div>
        <div class="flex items-center gap-2 font-semibold">
            <PreviewAvatar :account="account" class="size-8 text-xs" />
            <span class="truncate">{{ name }}</span>
        </div>
    </div>
</template>
