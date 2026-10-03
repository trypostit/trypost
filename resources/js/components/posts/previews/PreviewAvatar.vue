<script setup lang="ts">
import { getInitials } from '@/composables/useInitials';

import type { PreviewAccount } from './types';

withDefaults(
    defineProps<{
        account: PreviewAccount;
        square?: boolean;
    }>(),
    { square: false },
);
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center overflow-hidden bg-primary-strong text-sm font-medium text-primary-strong-foreground"
        :class="square ? 'rounded-md' : 'rounded-full'"
    >
        <img
            v-if="account.avatar_url"
            :src="account.avatar_url"
            :alt="account.display_label"
            class="size-full object-cover"
        />
        <template v-else>{{ getInitials(account.display_label) }}</template>
    </span>
</template>
