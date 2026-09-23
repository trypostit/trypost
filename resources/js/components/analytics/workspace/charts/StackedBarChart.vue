<script setup lang="ts">
import { computed } from 'vue';

import { accountColor, type PostAccount, type PostBucket } from '../types';

const props = defineProps<{ accounts: PostAccount[]; buckets: PostBucket[] }>();
const maximum = computed(() =>
    Math.max(1, ...props.buckets.map((bucket) => bucket.total)),
);
</script>

<template>
    <div class="overflow-x-auto">
        <div
            class="flex h-56 min-w-[440px] items-end gap-3 border-b border-border px-3"
        >
            <div
                v-for="bucket in buckets"
                :key="bucket.start"
                class="flex min-w-0 flex-1 flex-col items-center gap-2"
            >
                <div
                    class="flex h-44 w-full max-w-24 flex-col justify-end overflow-hidden rounded-t-sm bg-muted/30"
                    :title="`${bucket.label}: ${bucket.total}`"
                >
                    <div
                        v-for="(account, index) in accounts"
                        :key="account.social_account_key"
                        :style="{
                            height: `${((bucket.accounts[account.social_account_key] ?? 0) / maximum) * 100}%`,
                            backgroundColor: accountColor(index),
                        }"
                    />
                </div>
                <span
                    class="w-full truncate text-center text-[11px] text-muted-foreground"
                    :title="bucket.label"
                    >{{ bucket.label }}</span
                >
            </div>
        </div>
    </div>
</template>
