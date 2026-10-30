<script setup lang="ts">
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { useVerifiedBadges } from '@/composables/analytics/useVerifiedBadges';
import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import type { AccountIdentityData } from '@/types/analytics';

const props = defineProps<{
    account: AccountIdentityData;
    withAvatar?: boolean;
    avatarSize?: 24 | 32;
    color?: string;
    /** The name on top and the @handle below it. */
    withName?: boolean;
}>();
const verifiedBadge = useVerifiedBadges();
const label = computed(() =>
    props.account.username
        ? `@${props.account.username}`
        : props.account.name || getPlatformLabel(props.account.platform),
);
const name = computed(
    () =>
        props.account.name ||
        label.value,
);
const handle = computed(() =>
    props.account.name && props.account.username
        ? `@${props.account.username}`
        : null,
);
</script>

<template>
    <span
        class="inline-flex min-w-0 items-center"
        :class="withAvatar && !withName ? 'gap-3' : 'gap-2'"
    >
        <span
            v-if="color"
            data-testid="analytics-legend-swatch"
            class="-mr-0.5 size-2.5 shrink-0 rounded-[3px]"
            :style="{ backgroundColor: color }"
        />
        <ChannelAvatar
            v-if="withAvatar"
            :platform="account.platform"
            :src="account.avatar_url"
            :name="withName ? name : label"
            ring="card"
            :size="avatarSize ?? 32"
            :status="account.status"
            :account-id="account.social_account_key"
            :verified="verifiedBadge(account.social_account_key)"
        />
        <img
            v-else
            :src="getPlatformLogo(account.platform)"
            :alt="getPlatformLabel(account.platform)"
            class="size-4 shrink-0 rounded-sm object-contain"
        />
        <span v-if="withName" class="flex min-w-0 flex-col">
            <span class="truncate text-xs font-emphasis text-foreground">{{
                name
            }}</span>
            <span
                v-if="handle"
                class="truncate text-xs text-muted-foreground"
                >{{ handle }}</span
            >
        </span>
        <span
            v-else
            class="truncate text-sm text-foreground"
            :title="`${label} · ${getPlatformLabel(account.platform)}`"
            >{{ label }}</span
        >
        <span class="sr-only">{{ getPlatformLabel(account.platform) }}</span>
    </span>
</template>
