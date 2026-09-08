<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import WelcomeChoicePill from '@/components/welcome/WelcomeChoicePill.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { goalMeta, welcomeOptionMeta } from '@/lib/welcomeOptions';
import { store } from '@/routes/app/welcome/goals';
import type { WelcomeSummary } from '@/types';

const props = defineProps<{
    goals: string[];
    selected?: string[] | null;
    welcome: WelcomeSummary;
}>();

const EXCLUSIVE_GOAL = 'just_exploring';

// Drop removed/legacy goal values so mid-welcome users aren't soft-locked
// with selections that fail Rule::enum(Goal::class) on submit.
const form = useForm<{ goals: string[] }>({
    goals: (props.selected ?? []).filter((goal) => props.goals.includes(goal)),
});

const goalLabel = (value: string): string => trans(`welcome.goals.${value}`);

const isSelected = (value: string): boolean => form.goals.includes(value);

const toggle = (value: string): void => {
    if (value === EXCLUSIVE_GOAL) {
        form.goals = isSelected(value) ? [] : [value];

        return;
    }

    const withoutExclusive = form.goals.filter(
        (goal) => goal !== EXCLUSIVE_GOAL,
    );

    form.goals = isSelected(value)
        ? withoutExclusive.filter((goal) => goal !== value)
        : [...withoutExclusive, value];
};

const submit = (): void => {
    if (form.goals.length === 0 || form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.goals_title')" />

    <WelcomeLayout
        :title="$t('welcome.goals_title')"
        :description="$t('welcome.goals_description')"
        :step="2"
    >
        <div class="flex flex-wrap gap-2.5">
            <WelcomeChoicePill
                v-for="goal in goals"
                :key="goal"
                :label="goalLabel(goal)"
                :meta="welcomeOptionMeta(goalMeta, goal)"
                :selected="isSelected(goal)"
                :testid="`welcome-goal-${goal}`"
                @select="toggle(goal)"
            />
        </div>

        <template #actions>
            <div
                class="flex flex-col gap-2 sm:flex-row-reverse sm:items-center sm:gap-4"
            >
                <Button
                    type="button"
                    size="lg"
                    class="w-full sm:w-auto sm:min-w-48"
                    :disabled="form.goals.length === 0 || form.processing"
                    data-testid="welcome-goals-continue"
                    @click="submit"
                >
                    {{ $t('welcome.continue') }}
                </Button>
                <InputError :message="form.errors.goals" />
            </div>
        </template>
    </WelcomeLayout>
</template>
