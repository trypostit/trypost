<script setup lang="ts">
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import type { ComposerAccount } from '@/composables/usePostComposition';

defineProps<{
    accounts: ComposerAccount[];
}>();
</script>

<template>
    <span class="flex items-center -space-x-1.5 grayscale" aria-hidden="true">
        <span
            v-for="account in accounts.slice(0, 2)"
            :key="account.id"
            class="relative block size-6 shrink-0 rounded-full border border-background"
        >
            <img
                :src="account.avatar_url || getPlatformLogo(account.platform)"
                alt=""
                class="size-full rounded-full object-cover"
            />
            <img
                :src="getPlatformLogo(account.platform)"
                alt=""
                class="absolute -right-1 -bottom-1 size-3 rounded-full border border-background bg-background"
            />
        </span>
        <span
            v-if="accounts.length > 2"
            class="relative flex size-6 shrink-0 items-center justify-center rounded-full border border-background bg-muted text-[10px] font-medium text-muted-foreground"
        >
            +{{ accounts.length - 2 }}
        </span>
    </span>
</template>
