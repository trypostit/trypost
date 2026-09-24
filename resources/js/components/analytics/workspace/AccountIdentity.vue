<script setup lang="ts">
import { computed } from 'vue';

import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';

import type { AccountIdentityData } from '@/types/analytics';

const props = defineProps<{ account: AccountIdentityData }>();
const label = computed(() =>
    props.account.username
        ? `@${props.account.username}`
        : props.account.name || getPlatformLabel(props.account.platform),
);
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-2">
        <img
            :src="getPlatformLogo(account.platform)"
            :alt="getPlatformLabel(account.platform)"
            class="size-4 shrink-0 rounded-sm object-contain"
        />
        <span
            class="truncate text-sm text-foreground"
            :title="`${label} · ${getPlatformLabel(account.platform)}`"
            >{{ label }}</span
        >
        <span class="sr-only">{{ getPlatformLabel(account.platform) }}</span>
    </span>
</template>
