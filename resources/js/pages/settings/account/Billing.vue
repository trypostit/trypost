<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { IconCreditCard, IconDownload, IconFileText } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import PlanPicker from '@/components/billing/PlanPicker.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { edit as accountEdit } from '@/routes/app/account';
import {
    index as billingIndex,
    changePlan as changePlanRoute,
    portal,
} from '@/routes/app/billing';
import type { AuthPlan, SharedData } from '@/types';
import {
    DEFAULT_BILLING_INTERVAL,
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
    plans: PlanOption[];
    deniedPlanIds: string[];
    workspaceCount: number;
    invoices: Invoice[];
    defaultPaymentMethod: PaymentMethod | null;
}>();

const tabs = computed(() => [
    {
        name: 'account',
        label: trans('settings.account.tabs.account'),
        href: accountEdit().url,
    },
    {
        name: 'billing',
        label: trans('settings.account.tabs.billing'),
        href: billingIndex().url,
    },
]);

const page = usePage<SharedData>();
const authPlan = computed((): AuthPlan | null => page.props.auth.plan);
const currentInterval = computed(
    (): BillingInterval =>
        authPlan.value?.interval ?? DEFAULT_BILLING_INTERVAL,
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

    <AppLayout>
        <div class="mx-auto max-w-5xl space-y-8 px-6 py-8">
            <PageHeader
                :title="$t('settings.hub.title')"
                :description="$t('settings.hub.description')"
            />

            <SettingsTabsNav :tabs="tabs" active="billing" />

            <section class="space-y-12">
                <div v-if="hasSubscription" class="space-y-6">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <HeadingSmall
                            :title="$t('billing.plans.title')"
                            :description="$t('billing.plans.description')"
                        />
                        <div
                            v-if="
                                subscriptionStatus === 'trial' ||
                                subscriptionStatus === 'cancelling'
                            "
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Badge
                                v-if="subscriptionStatus === 'trial'"
                                variant="secondary"
                            >
                                {{ $t('billing.plan.trial') }}
                            </Badge>
                            <Badge
                                v-else-if="subscriptionStatus === 'cancelling'"
                                variant="secondary"
                            >
                                {{ $t('billing.plan.cancelling') }}
                            </Badge>
                            <p
                                v-if="onTrial && trialEndsAt"
                                class="text-sm font-medium text-foreground/70"
                            >
                                {{ $t('billing.plan.trial_ends') }}:
                                <span class="text-foreground">{{
                                    date.formatDate(trialEndsAt)
                                }}</span>
                            </p>
                        </div>
                    </div>

                    <PlanPicker
                        :plans="plans"
                        :interval="selectedInterval"
                        :current-plan-id="plan?.id ?? null"
                        :current-interval="currentInterval"
                        :disabled-plan-ids="deniedPlanIds"
                        :processing="planForm.processing"
                        @update:interval="(value) => (selectedInterval = value)"
                        @select="
                            (planId) => changePlan(planId, selectedInterval)
                        "
                    />
                </div>

                <div v-if="hasSubscription" class="space-y-6">
                    <HeadingSmall
                        :title="$t('billing.subscription.title')"
                        :description="$t('billing.subscription.description')"
                    />

                    <div
                        class="flex flex-wrap items-center gap-4 rounded-2xl border-2 border-foreground bg-card p-4 shadow-2xs"
                    >
                        <span
                            class="inline-flex size-12 rotate-2 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-2xs"
                        >
                            <IconCreditCard
                                class="size-6 text-foreground"
                                stroke-width="2"
                            />
                        </span>
                        <div v-if="defaultPaymentMethod" class="min-w-0 flex-1">
                            <p
                                class="text-base font-bold text-foreground capitalize"
                            >
                                {{ defaultPaymentMethod.brand }} ••••
                                {{ defaultPaymentMethod.last4 }}
                            </p>
                            <p class="text-xs font-medium text-foreground/60">
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
                        <div v-else class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-foreground/70">
                                {{
                                    $t('billing.subscription.no_payment_method')
                                }}
                            </p>
                        </div>
                        <Button as="a" :href="portal.url()" class="shrink-0">
                            {{ $t('billing.subscription.manage_stripe') }}
                        </Button>
                    </div>
                </div>

                <div v-if="invoices.length > 0" class="space-y-6">
                    <HeadingSmall
                        :title="$t('billing.invoices.title')"
                        :description="$t('billing.invoices.description')"
                    />

                    <div class="space-y-3">
                        <div
                            v-for="invoice in invoices"
                            :key="invoice.id"
                            class="flex items-center gap-4 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs"
                        >
                            <span
                                class="inline-flex size-10 -rotate-2 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-100 shadow-2xs"
                            >
                                <IconFileText
                                    class="size-5 text-foreground"
                                    stroke-width="2"
                                />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-foreground">
                                    {{ date.formatDate(invoice.date) }}
                                </p>
                                <p
                                    class="text-xs font-medium text-foreground/60 tabular-nums"
                                >
                                    {{ invoice.total }}
                                </p>
                            </div>
                            <Badge
                                :variant="
                                    invoice.status === 'paid'
                                        ? 'success'
                                        : 'outline'
                                "
                            >
                                {{
                                    invoice.status === 'paid'
                                        ? $t('billing.invoices.paid')
                                        : invoice.status
                                }}
                            </Badge>
                            <Button
                                variant="outline"
                                size="icon"
                                as="a"
                                :href="invoice.invoice_pdf"
                                target="_blank"
                            >
                                <IconDownload class="size-4" />
                            </Button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
