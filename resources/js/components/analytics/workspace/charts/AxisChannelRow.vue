<script setup lang="ts">
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { type AccountIdentityData, accountLabel } from '@/types/analytics';

defineProps<{
    account: AccountIdentityData;
    /** The channel's number as the chart prints it. */
    value: string;
}>();
</script>

<template>
    <div class="flex h-full items-center gap-2">
        <span class="min-w-0 flex-1 truncate text-xs text-muted-foreground">
            {{ accountLabel(account) }}
        </span>
        <span
            class="shrink-0 text-sm font-medium text-foreground tabular-nums"
            data-testid="analytics-account-value"
        >
            {{ value }}
        </span>
        <ChannelAvatar
            class="ms-1"
            :platform="account.platform"
            :src="account.avatar_url"
            :name="accountLabel(account)"
            :size="24"
            ring="card"
            :status="account.status ?? null"
            :account-id="account.social_account_key"
            :verified="account.verified_badge ?? null"
        />
    </div>
</template>
