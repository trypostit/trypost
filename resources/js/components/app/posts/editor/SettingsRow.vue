<script setup lang="ts">
withDefaults(
    defineProps<{
        label?: string;
        labelFor?: string;
        alignTop?: boolean;
    }>(),
    { label: '', labelFor: undefined, alignTop: false },
);
</script>

<template>
    <div
        class="grid grid-cols-1 gap-1.5 sm:grid-cols-[130px_minmax(0,1fr)] sm:gap-3"
        :class="alignTop ? 'sm:items-start' : 'sm:items-center'"
        data-testid="channel-settings-row"
    >
        <label
            v-if="labelFor"
            :for="labelFor"
            class="text-[13px] font-medium text-foreground"
            :class="{ 'sm:pt-1.5': alignTop }"
            >{{ label }}</label
        >
        <span
            v-else
            class="text-[13px] font-medium text-foreground"
            :class="{ 'sm:pt-1.5': alignTop, 'max-sm:hidden': !label }"
            ><slot name="label">{{ label }}</slot></span
        >
        <div class="min-w-0 space-y-1.5">
            <slot />
        </div>
    </div>
</template>
