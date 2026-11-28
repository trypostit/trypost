<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

export type PasswordRequirement =
    | { key: 'min'; value: number }
    | { key: 'mixed_case' | 'number' | 'symbol' };

const props = defineProps<{
    password: string;
    requirements: PasswordRequirement[];
}>();

const isMet = (requirement: PasswordRequirement, password: string): boolean => {
    switch (requirement.key) {
        case 'min':
            return [...password].length >= requirement.value;
        case 'mixed_case':
            return /\p{Ll}/u.test(password) && /\p{Lu}/u.test(password);
        case 'number':
            return /\p{N}/u.test(password);
        case 'symbol':
            return /[\p{Z}\p{S}\p{P}]/u.test(password);
    }
};

const rows = computed(() =>
    props.requirements.map((requirement) => ({
        key: requirement.key,
        count: 'value' in requirement ? String(requirement.value) : '',
        met: isMet(requirement, props.password),
    })),
);
</script>

<template>
    <ul
        class="flex flex-col gap-1 text-sm"
        aria-live="polite"
        data-testid="password-requirements"
    >
        <li
            v-for="row in rows"
            :key="row.key"
            class="flex items-center gap-2 transition-colors"
            :class="row.met ? 'text-success-text' : 'text-muted-foreground'"
            :data-testid="`password-requirement-${row.key}`"
            :data-met="row.met"
        >
            <span class="flex size-4 shrink-0 items-center justify-center">
                <IconCheck v-if="row.met" class="size-4" aria-hidden="true" />
                <span
                    v-else
                    class="size-1.5 rounded-full bg-muted-foreground/50"
                    aria-hidden="true"
                />
            </span>
            <span>
                {{
                    $t(`auth.register.password_requirements.${row.key}`, {
                        count: row.count,
                    })
                }}
            </span>
        </li>
    </ul>
</template>
