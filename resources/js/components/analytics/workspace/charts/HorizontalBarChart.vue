<script setup lang="ts">
import { computed } from 'vue';

import { formatNumberCompact } from '@/lib/utils';

import AccountIdentity from '../AccountIdentity.vue';
import { accountColor, type AccountIdentityData } from '../types';

const props = defineProps<{
    rows: { account: AccountIdentityData; value: number | null }[];
    growth?: boolean;
}>();

const maximum = computed(() =>
    Math.max(1, ...props.rows.map((row) => Math.abs(row.value ?? 0))),
);
const barStyle = (
    value: number | null,
    index: number,
): Record<string, string> => ({
    width: `${Math.max(value === 0 ? 1 : 0, (Math.abs(value ?? 0) / maximum.value) * (props.growth ? 50 : 100))}%`,
    backgroundColor: accountColor(index),
});
</script>

<template>
    <div class="space-y-4 py-2">
        <div
            v-for="(row, index) in rows"
            :key="row.account.social_account_key"
            class="grid grid-cols-[minmax(8rem,13rem)_minmax(0,1fr)_auto] items-center gap-3"
        >
            <AccountIdentity
                :account="row.account"
                :color="accountColor(index)"
            />
            <div
                class="relative h-9 rounded-sm bg-muted/40"
                :class="growth ? 'flex items-center' : ''"
            >
                <span
                    v-if="growth"
                    class="absolute top-0 bottom-0 left-1/2 w-px bg-foreground/40"
                />
                <span
                    v-if="row.value !== null"
                    class="absolute top-1 bottom-1 rounded-sm"
                    :class="
                        growth && row.value < 0
                            ? 'right-1/2'
                            : growth
                              ? 'left-1/2'
                              : 'left-0'
                    "
                    :style="barStyle(row.value, index)"
                    :title="String(row.value)"
                />
            </div>
            <span
                class="w-12 text-right text-xs font-semibold text-foreground tabular-nums"
                >{{
                    row.value === null ? '—' : formatNumberCompact(row.value)
                }}</span
            >
        </div>
    </div>
</template>
