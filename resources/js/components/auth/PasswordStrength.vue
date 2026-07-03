<script setup lang="ts">
import { IconCheck, IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

// Mirrors the production backend rules (Password::defaults): 12+, upper and
// lower case, a number and a symbol. The "uncompromised" (HaveIBeenPwned) check
// is server-only, so it's left out of the meter.
const props = defineProps<{
    password: string;
}>();

interface Requirement {
    key: string;
    label: string;
    met: boolean;
}

const requirements = computed<Requirement[]>(() => {
    const value = props.password ?? '';

    return [
        { key: 'length', label: trans('auth.password_strength.length'), met: value.length >= 12 },
        { key: 'case', label: trans('auth.password_strength.case'), met: /[a-z]/.test(value) && /[A-Z]/.test(value) },
        { key: 'number', label: trans('auth.password_strength.number'), met: /\d/.test(value) },
        { key: 'symbol', label: trans('auth.password_strength.symbol'), met: /[^A-Za-z0-9]/.test(value) },
    ];
});

const metCount = computed(() => requirements.value.filter((r) => r.met).length);

const level = computed(() => {
    if (!props.password) return 0;
    return metCount.value;
});

const barColor = computed(() => {
    if (level.value <= 1) return 'bg-red-500';
    if (level.value === 2) return 'bg-orange-500';
    if (level.value === 3) return 'bg-yellow-500';
    return 'bg-green-500';
});

const label = computed(() => {
    if (!props.password) return '';
    if (level.value <= 1) return trans('auth.password_strength.weak');
    if (level.value === 2) return trans('auth.password_strength.fair');
    if (level.value === 3) return trans('auth.password_strength.good');
    return trans('auth.password_strength.strong');
});
</script>

<template>
    <div v-if="password" class="grid gap-2" dusk="password-strength">
        <div class="flex items-center gap-2">
            <div class="flex h-1.5 flex-1 gap-1">
                <div
                    v-for="segment in 4"
                    :key="segment"
                    class="h-full flex-1 rounded-full transition-colors"
                    :class="segment <= level ? barColor : 'bg-muted'"
                />
            </div>
            <span class="min-w-16 text-right text-xs font-medium text-muted-foreground">{{ label }}</span>
        </div>

        <ul class="grid gap-1">
            <li
                v-for="requirement in requirements"
                :key="requirement.key"
                class="flex items-center gap-1.5 text-xs"
                :class="requirement.met ? 'text-green-600' : 'text-muted-foreground'"
            >
                <IconCheck v-if="requirement.met" class="size-3.5 shrink-0" stroke-width="3" />
                <IconX v-else class="size-3.5 shrink-0" />
                {{ requirement.label }}
            </li>
        </ul>
    </div>
</template>
