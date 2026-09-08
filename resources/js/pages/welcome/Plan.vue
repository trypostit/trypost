<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import PlanPicker, { type PlanOption } from '@/components/billing/PlanPicker.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/plan';

defineProps<{
    plans: PlanOption[];
}>();

const form = useForm<{ plan_id: string | null; interval: 'monthly' | 'yearly' }>({
    plan_id: null,
    interval: 'monthly',
});

const setInterval = (interval: 'monthly' | 'yearly'): void => {
    form.interval = interval;
};

const select = (planId: string): void => {
    if (form.processing) {
        return;
    }

    form.plan_id = planId;
    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.plan_title')" />

    <WelcomeLayout
        :title="$t('welcome.plan_title')"
        :description="$t('welcome.plan_description')"
        :step="5"
        size="2xl"
    >
        <PlanPicker
            :plans="plans"
            :interval="form.interval"
            :processing="form.processing"
            @update:interval="setInterval"
            @select="select"
        />
    </WelcomeLayout>
</template>
