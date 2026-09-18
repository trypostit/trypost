<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import PlanPicker, {
    type PlanOption,
} from '@/components/billing/PlanPicker.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/plan';
import type { WelcomeSummary } from '@/types';

defineProps<{
    plans: PlanOption[];
    welcome: WelcomeSummary;
}>();

const form = useForm<{ plan_id: string | null }>({
    plan_id: null,
});

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
        step="plan"
        size="4xl"
        centered
    >
        <PlanPicker
            :plans="plans"
            interval="monthly"
            :allow-yearly="false"
            :offer-first-month="true"
            :processing="form.processing"
            @select="select"
        />
    </WelcomeLayout>
</template>
