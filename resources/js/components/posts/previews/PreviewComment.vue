<script setup lang="ts">
import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';
import type { PreviewAccount } from '@/components/posts/previews/types';

defineProps<{
    account: PreviewAccount;
    text: string;
}>();
</script>

<template>
    <div class="flex gap-2 border-t pt-3 pb-1">
        <PreviewAvatar :account="account" class="size-8 text-[11px]" />
        <div class="min-w-0 flex-1 rounded-lg bg-muted px-3 py-2">
            <p class="truncate text-[13px] font-semibold text-foreground">
                {{ account.display_label }}
            </p>
            <p
                class="text-[13px] break-words whitespace-pre-line text-foreground"
            >
                {{ text }}
            </p>
        </div>
    </div>
</template>
