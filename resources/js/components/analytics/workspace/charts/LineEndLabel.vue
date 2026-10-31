<script setup lang="ts">
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { type AccountIdentityData, accountLabel } from '@/types/analytics';

defineProps<{
    account: AccountIdentityData;
    /** The channel's latest number as the chart prints it. */
    value: string;
}>();
</script>

<template>
    <div class="flex items-center gap-1.5">
        <ChannelAvatar
            :platform="account.platform"
            :src="account.avatar_url"
            :name="accountLabel(account)"
            :size="20"
            ring="card"
            :status="account.status ?? null"
            :account-id="account.social_account_key"
            :verified="account.verified_badge ?? null"
        />
        <span
            class="text-xs font-medium text-foreground tabular-nums"
            data-testid="analytics-line-end-value"
        >
            {{ value }}
        </span>
    </div>
</template>
