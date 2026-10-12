<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import WelcomeChoiceCard from '@/components/welcome/WelcomeChoiceCard.vue';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { goalArt, welcomeOptionArt } from '@/lib/welcomeOptions';
import { store } from '@/routes/app/welcome/goals';

const props = defineProps<{
    goals: string[];
    selected?: string[] | null;
}>();

// Drop removed/legacy goal values so mid-welcome users aren't soft-locked
// with selections that fail Rule::enum(Goal::class) on submit.
const form = useForm<{ goals: string[] }>({
    goals: (props.selected ?? []).filter((goal) => props.goals.includes(goal)),
});

const isSelected = (value: string): boolean => form.goals.includes(value);

const toggle = (value: string): void => {
    form.goals = isSelected(value)
        ? form.goals.filter((goal) => goal !== value)
        : [...form.goals, value];
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
        step="goals"
    >
        <div class="grid gap-2 sm:grid-cols-2">
            <WelcomeChoiceCard
                multiple
                v-for="goal in goals"
                :key="goal"
                :label="$t(`welcome.goals.${goal}`)"
                :art="welcomeOptionArt(goalArt, goal)"
                :selected="isSelected(goal)"
                :testid="`welcome-goal-${goal}`"
                @select="toggle(goal)"
            />
        </div>

        <template #actions>
            <WelcomeContinueButton
                :processing="form.processing"
                :disabled="form.goals.length === 0 || form.processing"
                testid="welcome-goals-continue"
                @continue="submit"
            />
            <InputError :message="form.errors.goals" />
        </template>
    </WelcomeLayout>
</template>
