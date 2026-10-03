<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { IconCreditCard, IconDownload, IconFileText } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import PlanPicker from '@/components/billing/PlanPicker.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import {
    changePlan as changePlanRoute,
    portal,
} from '@/routes/app/billing';
import type { AuthPlan, SharedData } from '@/types';
import {
    DEFAULT_BILLING_INTERVAL,
    deniedPlanIdsFor,
    type BillingInterval,
    type PlanOption,
} from '@/types/plan';

interface Subscription {
    stripe_status: string;
    ends_at: string | null;
}

interface PaymentMethod {
    brand: string;
    last4: string;
    exp_month: number;
    exp_year: number;
}

interface Invoice {
    id: string;
    date: string;
    total: string;
    status: string;
    invoice_pdf: string;
}

const props = defineProps<{
    hasSubscription: boolean;
    onTrial: boolean;
    trialEndsAt: string | null;
    subscription: Subscription | null;
    plan: PlanOption | null;
    workspaceCount: number;
    invoices: Invoice[];
    defaultPaymentMethod: PaymentMethod | null;
}>();

const page = usePage<SharedData>();
const plans = computed((): PlanOption[] => page.props.plans ?? []);
const authPlan = computed((): AuthPlan | null => page.props.auth.plan);
const currentInterval = computed(
    (): BillingInterval => authPlan.value?.interval ?? DEFAULT_BILLING_INTERVAL,
);

const subscriptionStatus = computed(() => {
    if (props.onTrial) {
        return 'trial' as const;
    }

    if (props.subscription?.stripe_status === 'past_due') {
        return 'past_due' as const;
    }

    if (props.subscription?.ends_at) {
        return 'cancelling' as const;
    }

    if (props.subscription?.stripe_status === 'active') {
        return 'active' as const;
    }

    return null;
});

const deniedPlanIds = computed((): string[] =>
    deniedPlanIdsFor(plans.value, props.workspaceCount),
);

const selectedInterval = ref<BillingInterval>(currentInterval.value);

const planForm = useForm<{
    plan_id: string | null;
    interval: BillingInterval;
}>({
    plan_id: null,
    interval: DEFAULT_BILLING_INTERVAL,
});

const changePlan = (planId: string, interval: BillingInterval): void => {
    if (planForm.processing) {
        return;
    }

    planForm.plan_id = planId;
    planForm.interval = interval;
    planForm.post(changePlanRoute.url(), { preserveScroll: true });
};
</script>

<template>
    <Head :title="$t('billing.title')" />

    <SettingsLayout :title="$t('billing.title')">
        <div class="flex flex-col gap-10">
            <SettingsSection
                v-if="hasSubscription"
                :title="$t('billing.plans.title')"
                :description="$t('billing.plans.description')"
            >
                <div
                    v-if="
                        subscriptionStatus === 'trial' ||
                        subscriptionStatus === 'cancelling'
                    "
                    class="-mt-2 flex flex-wrap items-center gap-2"
                >
                    <Badge variant="secondary">
                        {{
                            subscriptionStatus === 'trial'
                                ? $t('billing.plan.trial')
                                : $t('billing.plan.cancelling')
                        }}
                    </Badge>
                    <p
                        v-if="onTrial && trialEndsAt"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('billing.plan.trial_ends') }}:
                        <span class="font-emphasis text-foreground">{{
                            date.formatDate(trialEndsAt)
                        }}</span>
                    </p>
                </div>

                <PlanPicker
                    :plans="plans"
                    :interval="selectedInterval"
                    :current-plan-id="plan?.id ?? null"
                    :current-interval="currentInterval"
                    :disabled-plan-ids="deniedPlanIds"
                    :processing="planForm.processing"
                    @update:interval="(value) => (selectedInterval = value)"
                    @select="(planId) => changePlan(planId, selectedInterval)"
                />
            </SettingsSection>

            <Separator v-if="hasSubscription" />

            <SettingsSection
                v-if="hasSubscription"
                :title="$t('billing.subscription.title')"
                :description="$t('billing.subscription.description')"
            >
                <div
                    class="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-card px-4 py-6"
                >
                    <span
                        class="inline-flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary-subtle text-primary-text"
                    >
                        <IconCreditCard class="size-6" />
                    </span>
                    <div
                        v-if="defaultPaymentMethod"
                        class="flex min-w-0 flex-1 flex-col gap-1"
                    >
                        <p
                            class="text-sm leading-tight font-emphasis text-foreground capitalize"
                        >
                            {{ defaultPaymentMethod.brand }} ••••
                            {{ defaultPaymentMethod.last4 }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                $t('billing.subscription.expires_on', {
                                    month: defaultPaymentMethod.exp_month
                                        .toString()
                                        .padStart(2, '0'),
                                    year: defaultPaymentMethod.exp_year.toString(),
                                })
                            }}
                        </p>
                    </div>
                    <p v-else class="min-w-0 flex-1 text-sm text-muted-foreground">
                        {{ $t('billing.subscription.no_payment_method') }}
                    </p>
                    <Button
                        as="a"
                        variant="outline"
                        :href="portal.url()"
                        class="shrink-0"
                    >
                        {{ $t('billing.subscription.manage_stripe') }}
                    </Button>
                </div>
            </SettingsSection>

            <Separator v-if="hasSubscription && invoices.length > 0" />

            <SettingsSection
                v-if="invoices.length > 0"
                :title="$t('billing.invoices.title')"
                :description="$t('billing.invoices.description')"
            >
                <ul class="flex flex-col gap-2">
                    <SettingsListRow
                        v-for="invoice in invoices"
                        :key="invoice.id"
                        :icon="IconFileText"
                    >
                        <p
                            class="text-sm leading-tight font-emphasis text-foreground"
                        >
                            {{ date.formatDate(invoice.date) }}
                        </p>
                        <p class="text-sm text-muted-foreground tabular-nums">
                            {{ invoice.total }}
                        </p>
                        <template #actions>
                            <Badge
                                :variant="
                                    invoice.status === 'paid' ? 'success' : 'secondary'
                                "
                            >
                                {{
                                    invoice.status === 'paid'
                                        ? $t('billing.invoices.paid')
                                        : invoice.status
                                }}
                            </Badge>
                            <Button
                                variant="ghost"
                                size="icon"
                                as="a"
                                :href="invoice.invoice_pdf"
                                target="_blank"
                                class="text-muted-foreground"
                                :aria-label="$t('billing.invoices.download')"
                            >
                                <IconDownload class="size-4" />
                            </Button>
                        </template>
                    </SettingsListRow>
                </ul>
            </SettingsSection>
        </div>
    </SettingsLayout>
</template>
