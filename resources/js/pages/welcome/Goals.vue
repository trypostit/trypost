<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconCalendar,
    IconCheck,
    IconClock,
    IconCoin,
    IconCompass,
    IconDots,
    IconPalette,
    IconPlug,
    IconSparkles,
    IconTrendingUp,
    IconUsersGroup,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/goals';

const props = defineProps<{
    goals: string[];
    selected?: string[] | null;
}>();

const EXCLUSIVE_GOAL = 'just_exploring';

// Drop removed/legacy goal values so mid-welcome users aren't soft-locked
// with selections that fail Rule::enum(Goal::class) on submit.
const form = useForm<{ goals: string[] }>({
    goals: (props.selected ?? []).filter((goal) => props.goals.includes(goal)),
});

const goalMeta: Record<
    string,
    { icon: FunctionalComponent; iconClass: string; badge: string }
> = {
    save_time: {
        icon: IconClock,
        iconClass: 'text-amber-700',
        badge: 'bg-amber-100',
    },
    ai_content: {
        icon: IconSparkles,
        iconClass: 'text-violet-700',
        badge: 'bg-violet-100',
    },
    use_mcp: {
        icon: IconPlug,
        iconClass: 'text-teal-700',
        badge: 'bg-teal-100',
    },
    plan_calendar: {
        icon: IconCalendar,
        iconClass: 'text-blue-700',
        badge: 'bg-blue-100',
    },
    stay_on_brand: {
        icon: IconPalette,
        iconClass: 'text-orange-700',
        badge: 'bg-orange-100',
    },
    grow_audience: {
        icon: IconTrendingUp,
        iconClass: 'text-rose-700',
        badge: 'bg-rose-100',
    },
    drive_sales: {
        icon: IconCoin,
        iconClass: 'text-emerald-700',
        badge: 'bg-emerald-100',
    },
    manage_clients: {
        icon: IconUsersGroup,
        iconClass: 'text-cyan-700',
        badge: 'bg-cyan-100',
    },
    just_exploring: {
        icon: IconCompass,
        iconClass: 'text-sky-700',
        badge: 'bg-sky-100',
    },
    other: {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    },
};

const metaFor = (value: string) =>
    goalMeta[value] ?? {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    };

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
        wide
    >
        <div class="flex flex-wrap justify-center gap-2.5">
            <button
                v-for="goal in goals"
                :key="goal"
                type="button"
                :aria-pressed="isSelected(goal)"
                :data-testid="`welcome-goal-${goal}`"
                :class="[
                    'inline-flex cursor-pointer items-center gap-3 rounded-full border-2 border-foreground py-2.5 ps-2.5 pe-5 text-start shadow-2xs transition-shadow hover:shadow-md',
                    isSelected(goal) ? 'bg-violet-100' : 'bg-card',
                ]"
                @click="toggle(goal)"
            >
                <span
                    :class="[
                        'inline-flex size-11 shrink-0 items-center justify-center rounded-full border-2 border-foreground shadow-2xs',
                        metaFor(goal).badge,
                    ]"
                >
                    <component
                        :is="metaFor(goal).icon"
                        :class="[metaFor(goal).iconClass, 'size-6']"
                        stroke-width="2"
                    />
                </span>
                <span
                    class="text-sm font-bold tracking-tight text-foreground sm:text-base"
                >
                    {{ goalLabel(goal) }}
                </span>
                <span
                    v-if="isSelected(goal)"
                    class="inline-flex size-5 shrink-0 items-center justify-center rounded-full border-2 border-foreground bg-foreground"
                >
                    <IconCheck
                        class="size-3 text-background"
                        stroke-width="3"
                    />
                </span>
            </button>
        </div>

        <div class="mx-auto flex w-full max-w-sm flex-col items-center gap-3">
            <InputError :message="form.errors.goals" />
            <Button
                type="button"
                size="lg"
                class="w-full rounded-full"
                :disabled="form.goals.length === 0 || form.processing"
                data-testid="welcome-goals-continue"
                @click="submit"
            >
                {{ $t('welcome.continue') }}
            </Button>
        </div>
    </WelcomeLayout>
</template>
