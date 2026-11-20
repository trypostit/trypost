<script setup lang="ts">
defineProps<{
    offerKey: string;
}>();
</script>

<template>
    <div class="mb-6 flex justify-center" data-testid="welcome-plan-offer">
        <p
            class="inline-flex items-center gap-3 rounded-full bg-foreground py-1.5 ps-1.5 pe-5 text-start text-sm text-background sm:text-base"
        >
            <span
                class="shrink-0 rounded-full bg-background px-3 py-1 text-xs font-strong tracking-wider text-foreground uppercase sm:text-sm"
                data-testid="welcome-plan-offer-name"
                >{{ $t(`offers.${offerKey}.name`) }}</span
            >
            <span data-testid="welcome-plan-offer-headline">{{
                $t(`offers.${offerKey}.headline`)
            }}</span>
        </p>
    </div>
</template>
