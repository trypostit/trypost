<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';

import type { WelcomeOptionMeta } from '@/lib/welcomeOptions';

defineProps<{
    label: string;
    meta: WelcomeOptionMeta;
    selected: boolean;
    testid: string;
}>();

const emit = defineEmits<{
    (event: 'select'): void;
}>();
</script>

<template>
    <button
        type="button"
        :aria-pressed="selected"
        :data-testid="testid"
        :class="[
            'inline-flex cursor-pointer items-center gap-3 rounded-full border-2 border-foreground py-2 ps-2 pe-4 text-start transition-[box-shadow,background-color,transform] duration-150 motion-reduce:transition-none',
            selected
                ? 'bg-violet-100 shadow-sm'
                : 'bg-card shadow-2xs hover:-translate-y-px hover:shadow-sm',
        ]"
        @click="emit('select')"
    >
        <span
            :class="[
                'inline-flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-foreground',
                meta.badge,
            ]"
        >
            <img
                v-if="meta.logo"
                :src="meta.logo"
                :alt="label"
                class="size-5"
            />
            <component
                :is="meta.icon"
                v-else
                :class="[meta.iconClass, 'size-5']"
                stroke-width="2"
            />
        </span>
        <span class="text-sm font-bold tracking-tight text-foreground">
            {{ label }}
        </span>
        <span
            :class="[
                'inline-flex size-5 shrink-0 items-center justify-center rounded-full border-2 border-foreground transition-colors',
                selected ? 'bg-foreground' : 'bg-card',
            ]"
            aria-hidden="true"
        >
            <IconCheck
                v-if="selected"
                class="size-3 text-background"
                stroke-width="3"
            />
        </span>
    </button>
</template>
