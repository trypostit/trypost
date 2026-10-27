<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { IconCreditCard, IconDownload, IconFileText } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import PlanPicker from '@/components/billing/PlanPicker.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { cardBrand } from '@/lib/cardBrands';
import {
    changePlan as changePlanRoute,
    portal,
} from '@/routes/app/billing';
import type { AuthPlan, SharedData } from '@/types';
import {
    DEFAULT_BILLING_INTERVAL,
    deniedPlanIdsFor,
    isPricedPlanSlug,
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

interface SubscriptionDiscount {
    value: string;
    duration: 'once' | 'repeating' | 'forever';
    ends_at: string | null;
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
    discount: SubscriptionDiscount | null;
}>();

const discountKey = computed((): string => {
    if (props.discount?.duration === 'once') {
        return 'billing.current_plan.discount_once';
    }

    return props.discount?.ends_at
        ? 'billing.current_plan.discount_until'
        : 'billing.current_plan.discount_forever';
});

const paymentMethodBrand = computed(() =>
    props.defaultPaymentMethod
        ? cardBrand(props.defaultPaymentMethod.brand)
        : null,
);

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
const isPlanDialogOpen = ref(false);

const priceKey = computed((): string | null => {
    if (!props.plan || !isPricedPlanSlug(props.plan.slug)) {
        return null;
    }

    return `billing.subscribe.prices.${props.plan.slug}.${currentInterval.value === 'yearly' ? 'yearly' : 'monthly'}`;
});

const priceLabelKey = computed(
    (): string => `billing.current_plan.price_${currentInterval.value}`,
);

const planForm = useForm<{
    plan_id: string | null;
    interval: BillingInterval;
}>({
    plan_id: null,
    interval: DEFAULT_BILLING_INTERVAL,
});

const openPlanDialog = (): void => {
    selectedInterval.value = currentInterval.value;
    isPlanDialogOpen.value = true;
};

const closePlanDialog = (): void => {
    isPlanDialogOpen.value = false;
};

const selectPlan = (planId: string): void => {
    if (planForm.processing) {
        return;
    }

    planForm.plan_id = planId;
    planForm.interval = selectedInterval.value;
    planForm.post(changePlanRoute.url(), {
        preserveScroll: true,
        onSuccess: closePlanDialog,
    });
};
</script>

<template>
    <Head :title="$t('billing.title')" />

    <SettingsLayout :title="$t('billing.title')">
        <div class="flex flex-col gap-10">
            <SettingsSection
                v-if="hasSubscription"
                :title="$t('billing.plans.current')"
            >
                <div
                    class="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-card px-4 py-6"
                    data-testid="billing-current-plan"
                >
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <p
                                class="text-base leading-tight font-emphasis text-foreground"
                                data-testid="billing-current-plan-name"
                            >
                                {{ plan?.name }}
                            </p>
                            <p
                                v-if="priceKey"
                                class="text-sm text-muted-foreground tabular-nums"
                                data-testid="billing-current-plan-price"
                            >
                                {{
                                    $t(priceLabelKey, {
                                        price: $t(priceKey),
                                    })
                                }}
                            </p>
                        </div>
                        <p
                            v-if="discount"
                            class="text-sm text-success-text"
                            data-testid="billing-current-plan-discount"
                        >
                            {{
                                $t(discountKey, {
                                    discount: discount.value,
                                    date: date.formatDate(discount.ends_at),
                                })
                            }}
                        </p>
                        <p
                            v-if="subscriptionStatus === 'trial' && trialEndsAt"
                            class="text-sm text-muted-foreground"
                            data-testid="billing-current-plan-status"
                        >
                            {{
                                $t('billing.current_plan.trial_until', {
                                    date: date.formatDate(trialEndsAt),
                                })
                            }}
                        </p>
                        <p
                            v-else-if="
                                subscriptionStatus === 'cancelling' &&
                                subscription?.ends_at
                            "
                            class="text-sm text-muted-foreground"
                            data-testid="billing-current-plan-status"
                        >
                            {{
                                $t('billing.current_plan.cancelled', {
                                    date: date.formatDate(subscription.ends_at),
                                })
                            }}
                        </p>
                        <p
                            v-else-if="subscriptionStatus === 'past_due'"
                            class="text-sm text-muted-foreground"
                            data-testid="billing-current-plan-status"
                        >
                            {{ $t('billing.past_due_notice.title') }}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        class="shrink-0"
                        data-testid="billing-change-plan"
                        @click="openPlanDialog"
                    >
                        {{ $t('billing.current_plan.change') }}
                    </Button>
                </div>

                <Dialog v-model:open="isPlanDialogOpen">
                    <DialogContent
                        class="sm:max-w-3xl"
                        data-testid="billing-plan-dialog"
                    >
                        <DialogHeader>
                            <DialogTitle>
                                {{ $t('billing.current_plan.change') }}
                            </DialogTitle>
                            <DialogDescription>
                                {{ $t('billing.plans.description') }}
                            </DialogDescription>
                        </DialogHeader>
                        <PlanPicker
                            :plans="plans"
                            v-model:interval="selectedInterval"
                            :current-plan-id="plan?.id ?? null"
                            :current-interval="currentInterval"
                            :disabled-plan-ids="deniedPlanIds"
                            :processing="planForm.processing"
                            @select="selectPlan"
                        />
                    </DialogContent>
                </Dialog>
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
                        v-if="paymentMethodBrand?.logo"
                        class="inline-flex h-10 w-14 shrink-0 items-center justify-center rounded-lg border border-border bg-white"
                        data-testid="billing-card-brand-logo"
                    >
                        <component
                            :is="paymentMethodBrand.logo"
                            class="h-6 w-auto"
                            aria-hidden="true"
                        />
                    </span>
                    <span
                        v-else
                        class="inline-flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary-subtle text-primary-text"
                    >
                        <IconCreditCard class="size-6" />
                    </span>
                    <div
                        v-if="defaultPaymentMethod"
                        class="flex min-w-0 flex-1 flex-col gap-1"
                    >
                        <p
                            class="text-sm leading-tight font-emphasis text-foreground"
                            data-testid="billing-card-label"
                        >
                            {{ paymentMethodBrand?.name }}
                            <span class="tabular-nums">•••• {{ defaultPaymentMethod.last4 }}</span>
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
