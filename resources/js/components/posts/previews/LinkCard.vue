<script setup lang="ts">
import type { LinkCard } from '@/composables/useLinkCard';

defineProps<{ card: LinkCard }>();
</script>

<template>
    <div class="mt-3 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <img
            v-if="card.image"
            :src="card.image"
            :alt="card.title"
            class="aspect-[1.91/1] w-full object-cover"
        />
        <div class="px-3 py-2">
            <div class="text-[13px] text-neutral-500 dark:text-neutral-400">{{ card.domain }}</div>
            <div
                v-if="card.title"
                class="mt-0.5 text-[15px] font-semibold text-neutral-900 dark:text-neutral-100 line-clamp-2"
            >
                {{ card.title }}
            </div>
            <div
                v-if="card.description"
                class="mt-0.5 text-[14px] text-neutral-500 dark:text-neutral-400 line-clamp-2"
            >
                {{ card.description }}
            </div>
        </div>
    </div>
</template>
