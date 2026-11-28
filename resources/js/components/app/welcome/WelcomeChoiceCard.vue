<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';

import type { WelcomeOptionArt } from '@/lib/welcomeOptions';

withDefaults(
    defineProps<{
        label: string;
        art: WelcomeOptionArt;
        selected: boolean;
        testid: string;
        multiple?: boolean;
    }>(),
    { multiple: false },
);

const emit = defineEmits<{
    (event: 'select'): void;
}>();

const select = (): void => {
    emit('select');
};
</script>

<template>
    <button
        type="button"
        :aria-pressed="selected"
        :data-testid="testid"
        :class="[
            'flex min-h-14 w-full cursor-pointer items-center gap-3 rounded-xl border p-3 text-start transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
            selected
                ? 'border-primary-strong bg-primary-subtle dark:bg-primary-selected'
                : 'border-border bg-card hover:border-border-strong',
        ]"
        @click="select"
    >
        <span
            :class="[
                'inline-flex size-9 shrink-0 items-center justify-center rounded-lg transition-control',
                selected && art.emoji ? 'bg-card' : art.tint,
            ]"
            aria-hidden="true"
        >
            <img
                v-if="art.logo"
                :src="art.logo"
                alt=""
                class="size-5"
                :data-testid="`${testid}-logo`"
            />
            <component
                :is="art.icon"
                v-else-if="art.icon"
                class="size-5 text-neutral-500"
                :data-testid="`${testid}-icon`"
            />
            <span v-else class="text-xl leading-none">{{ art.emoji }}</span>
        </span>
        <span
            class="min-w-0 flex-1 text-sm font-medium text-foreground"
            :data-testid="`${testid}-label`"
        >
            {{ label }}
        </span>
        <span
            v-if="multiple"
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
