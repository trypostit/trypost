<script setup lang="ts">
import type { LinkCard } from '@/composables/useLinkCard';

withDefaults(
    defineProps<{
        card: LinkCard;
        bleed?: boolean;
        band?: boolean;
        titleFirst?: boolean;
        boldTitle?: boolean;
    }>(),
    { bleed: false, band: false, titleFirst: false, boldTitle: false },
);
</script>

<template>
    <div
        data-testid="link-card"
        class="overflow-hidden"
        :class="bleed ? 'border-y' : 'rounded-xl border'"
    >
        <img
            v-if="card.image"
            :src="card.image"
            :alt="card.title"
            class="aspect-[1.91/1] w-full object-cover"
        />
        <div
            class="flex gap-0.5 px-3 py-2.5"
            :class="[
                titleFirst ? 'flex-col-reverse' : 'flex-col',
                { 'bg-muted': band, 'px-4': bleed },
            ]"
        >
            <div class="text-[13px] leading-4 text-muted-foreground">
                {{ card.domain }}
            </div>
            <div
                v-if="card.title"
                data-testid="link-card-title"
                class="text-sm"
                :class="boldTitle ? 'line-clamp-2 font-semibold' : 'truncate'"
            >
                {{ card.title }}
            </div>
        </div>
    </div>
</template>
