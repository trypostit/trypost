<script setup lang="ts">
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import type { ComposerAccount } from '@/composables/usePostComposition';

defineProps<{
    accounts: ComposerAccount[];
}>();
</script>

<template>
    <span class="flex items-center -space-x-1.5 grayscale" aria-hidden="true">
        <ChannelAvatar
            :status="account.status"
            :account-id="account.id"
            v-for="account in accounts.slice(0, 2)"
            :key="account.id"
            :platform="account.platform"
            :src="account.avatar_url"
            :name="account.display_name || account.username"
            :size="24"
            :reserve-space="false"
            avatar-class="border border-background"
        />
        <span
            v-if="accounts.length > 2"
            class="relative flex size-6 shrink-0 items-center justify-center rounded-full border border-background bg-muted text-[10px] font-medium text-muted-foreground"
        >
            +{{ accounts.length - 2 }}
        </span>
    </span>
</template>
