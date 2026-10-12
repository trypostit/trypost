<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import PlanPicker, {
    type PlanOption,
} from '@/components/billing/PlanPicker.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/plan';

defineProps<{
    plans: PlanOption[];
}>();

const form = useForm({});

const select = (): void => {
    if (form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.plan_title')" />

    <WelcomeLayout :title="$t('welcome.plan_title')" step="plan" size="lg">
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
