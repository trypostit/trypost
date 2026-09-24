<script setup lang="ts">
interface Label {
    id: string;
    name: string;
    color: string;
}

withDefaults(
    defineProps<{
        label: Label;
        interactive?: boolean;
        selected?: boolean;
        disabled?: boolean;
    }>(),
    {
        interactive: false,
        selected: false,
        disabled: false,
    },
);

defineEmits<{ click: [] }>();
</script>

<template>
    <component
        :is="interactive ? 'button' : 'span'"
        :type="interactive ? 'button' : undefined"
        :disabled="interactive && disabled"
        class="inline-flex items-center gap-1.5 rounded-full border border-border px-2 py-0.5 text-[10px] font-bold tracking-widest text-foreground uppercase shadow-2xs"
        :class="[
            selected
                ? 'border-amber-300 bg-amber-100 text-amber-950 shadow-sm'
                : 'bg-card',
            interactive
                ? 'cursor-pointer transition-transform hover:-translate-y-0.5 disabled:cursor-not-allowed'
                : '',
            interactive && !selected && !disabled
                ? 'opacity-80 hover:opacity-100'
                : '',
        ]"
        @click="interactive && !disabled ? $emit('click') : undefined"
    >
        <span
            class="size-2 shrink-0 rounded-full"
            :style="{ backgroundColor: label.color }"
        />
        <span class="truncate">{{ label.name }}</span>
    </component>
</template>
