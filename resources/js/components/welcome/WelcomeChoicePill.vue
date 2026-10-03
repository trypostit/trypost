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
            'inline-flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border py-2 ps-2 pe-3 text-start transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
            selected
                ? 'border-primary-strong bg-primary-subtle'
                : 'border-border-strong bg-card hover:bg-accent',
        ]"
        @click="emit('select')"
    >
        <span
            :class="[
                'inline-flex size-8 shrink-0 items-center justify-center rounded-lg',
                meta.badge,
            ]"
        >
            <img
                v-if="meta.logo"
                :src="meta.logo"
                :alt="label"
                class="size-4"
            />
            <component
                :is="meta.icon"
                v-else
                :class="[meta.iconClass, 'size-4']"
            />
        </span>
        <span class="text-sm font-medium text-foreground">
            {{ label }}
        </span>
        <span
            :class="[
                'inline-flex size-4 shrink-0 items-center justify-center rounded-sm border transition-control',
                selected
                    ? 'border-primary-strong bg-primary-strong text-primary-strong-foreground'
                    : 'border-input bg-card',
            ]"
            aria-hidden="true"
        >
            <IconCheck v-if="selected" class="size-3" stroke-width="3" />
        </span>
    </button>
</template>
