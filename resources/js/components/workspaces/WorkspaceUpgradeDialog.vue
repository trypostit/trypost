<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import PlanPicker from '@/components/billing/PlanPicker.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { changePlan as changePlanRoute } from '@/routes/app/billing';
import { create as createWorkspaceRoute } from '@/routes/app/workspaces';
import type { SharedData } from '@/types';
import {
    DEFAULT_BILLING_INTERVAL,
    deniedPlanIdsFor,
    type BillingInterval,
    type PlanOption,
} from '@/types/plan';

const open = defineModel<boolean>('open', { default: false });

const page = usePage<SharedData>();

const plans = computed((): PlanOption[] => page.props.plans ?? []);

const deniedPlanIds = computed((): string[] =>
    deniedPlanIdsFor(
        plans.value,
        page.props.usage?.workspaceCount ?? page.props.auth.workspaces.length,
    ),
);

const authPlan = computed(() => page.props.auth.plan);

const currentInterval = computed(
    (): BillingInterval =>
        authPlan.value?.interval ?? DEFAULT_BILLING_INTERVAL,
);

const selectedInterval = ref<BillingInterval>(currentInterval.value);

watch(open, (isOpen) => {
    if (isOpen) {
        selectedInterval.value = currentInterval.value;
    }
});

const planForm = useForm<{
    plan_id: string | null;
    interval: BillingInterval;
}>({
    plan_id: null,
    interval: DEFAULT_BILLING_INTERVAL,
});

const changePlan = (planId: string): void => {
    if (planForm.processing) {
        return;
    }

    const selected = plans.value.find((plan) => plan.id === planId);

    planForm.plan_id = planId;
    planForm.interval = selectedInterval.value;
    planForm.post(changePlanRoute.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;

            if (selected?.workspace_limit === null) {
                router.visit(createWorkspaceRoute.url());
            }
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] overflow-y-auto sm:max-w-5xl"
            data-testid="workspace-upgrade-dialog"
        >
            <DialogHeader class="text-start">
                <DialogTitle>{{
                    $t('workspaces.upgrade_dialog.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{ $t('workspaces.upgrade_dialog.description') }}
                </DialogDescription>
            </DialogHeader>

            <PlanPicker
                v-if="plans.length > 0"
                :plans="plans"
                :interval="selectedInterval"
                :current-plan-id="authPlan?.id ?? null"
                :current-interval="currentInterval"
                :disabled-plan-ids="deniedPlanIds"
                :processing="planForm.processing"
                @update:interval="(value) => (selectedInterval = value)"
                @select="changePlan"
            />
        </DialogContent>
    </Dialog>
</template>
