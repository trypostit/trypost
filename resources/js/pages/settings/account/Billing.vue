<script setup lang="ts">
import { Head, InfiniteScroll, router, useForm, usePage } from '@inertiajs/vue3';
import {
    IconCreditCard,
    IconDiscount,
    IconDownload,
    IconFileText,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import PlanPicker from '@/components/billing/PlanPicker.vue';
import EmptyState from '@/components/EmptyState.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge, type BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableLoadMore,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { cardBrand } from '@/lib/cardBrands';
import {
    changePlan as changePlanRoute,
    index,
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
    invoice_pdf: string | null;
}

interface ScrollInvoices {
    data: Invoice[];
}

type BillingTab = 'plan' | 'invoices';

const BILLING_TABS: BillingTab[] = ['plan', 'invoices'];

type SubscriptionStatus = 'trial' | 'past_due' | 'cancelling' | 'active';

const STATUS_BADGES: Record<SubscriptionStatus, BadgeVariants['variant']> = {
    active: 'success',
    trial: 'info',
    cancelling: 'warning',
    past_due: 'destructive-subtle',
};

const props = defineProps<{
    hasSubscription: boolean;
    onTrial: boolean;
    trialEndsAt: string | null;
    subscription: Subscription | null;
    plan: PlanOption | null;
    workspaceCount: number;
    invoices: ScrollInvoices;
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

const subscriptionStatus = computed((): SubscriptionStatus | null => {
    if (props.onTrial) {
        return 'trial';
    }

    if (props.subscription?.stripe_status === 'past_due') {
        return 'past_due';
    }

    if (props.subscription?.ends_at) {
        return 'cancelling';
    }

    if (props.subscription?.stripe_status === 'active') {
        return 'active';
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

const priceSuffixKey = computed((): string =>
    currentInterval.value === 'yearly'
        ? 'billing.plans.per_year'
        : 'billing.plans.per_month',
);

const billingTab = (value: unknown): BillingTab =>
    BILLING_TABS.find((tab) => tab === value) ?? 'plan';

const tab = ref<BillingTab>(
    billingTab(
        new URL(page.url, window.location.origin).searchParams.get('tab'),
    ),
);

const selectTab = (value: string | number): void => {
    tab.value = billingTab(value);

    router.replace({
        url: index({
            query: tab.value === 'plan' ? {} : { tab: tab.value },
        }).url,
        preserveScroll: true,
        preserveState: true,
    });
};

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
        <Tabs
            :model-value="tab"
            class="flex flex-col gap-8"
            @update:model-value="selectTab"
        >
            <TabsList variant="line">
                <TabsTrigger
                    v-for="value in BILLING_TABS"
                    :key="value"
                    :value="value"
                    :data-testid="`billing-tab-${value}`"
                >
                    {{ $t(`billing.tabs.${value}`) }}
                </TabsTrigger>
            </TabsList>

            <TabsContent value="plan" class="flex flex-col gap-10">
                <SettingsSection
                    v-if="hasSubscription"
                    :title="$t('billing.plans.current')"
                >
                    <div
                        class="overflow-hidden rounded-xl border border-border bg-card"
                        data-testid="billing-current-plan"
                    >
                        <div class="flex flex-col gap-5 p-5 sm:p-6">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="flex min-w-0 flex-col gap-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p
                                            class="text-lg leading-tight font-emphasis text-foreground"
                                            data-testid="billing-current-plan-name"
                                        >
                                            {{ plan?.name }}
                                        </p>
                                        <Badge
                                            v-if="subscriptionStatus"
                                            :variant="STATUS_BADGES[subscriptionStatus]"
                                            data-testid="billing-current-plan-badge"
                                        >
                                            {{
                                                $t(
                                                    `billing.current_plan.statuses.${subscriptionStatus}`,
                                                )
                                            }}
                                        </Badge>
                                    </div>
                                    <p
                                        v-if="priceKey"
                                        class="flex items-baseline gap-1 tabular-nums"
                                        data-testid="billing-current-plan-price"
                                    >
                                        <span
                                            class="text-3xl leading-none font-emphasis text-foreground"
                                        >
                                            {{ $t(priceKey) }}
                                        </span>
                                        <span class="text-sm text-muted-foreground">
                                            {{ $t(priceSuffixKey) }}
                                        </span>
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
                            <p
                                v-if="discount"
                                class="flex items-center gap-2 self-start rounded-lg bg-success-subtle px-3 py-2 text-sm text-success-text"
                                data-testid="billing-current-plan-discount"
                            >
                                <IconDiscount class="size-4 shrink-0" />
                                {{
                                    $t(discountKey, {
                                        discount: discount.value,
                                        date: date.formatDate(discount.ends_at),
                                    })
                                }}
                            </p>
                        </div>
                        <p
                            v-if="subscriptionStatus === 'trial' && trialEndsAt"
                            class="border-t border-border bg-muted/40 px-5 py-3 text-sm text-muted-foreground sm:px-6"
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
                            class="border-t border-border bg-muted/40 px-5 py-3 text-sm text-muted-foreground sm:px-6"
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
                            class="border-t border-border bg-muted/40 px-5 py-3 text-sm text-muted-foreground sm:px-6"
                            data-testid="billing-current-plan-status"
                        >
                            {{ $t('billing.past_due_notice.description') }}
                        </p>
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
                        class="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-card p-5 sm:p-6"
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
                            class="inline-flex h-10 w-14 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                        >
                            <IconCreditCard class="size-5" />
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
                            <p class="text-sm text-muted-foreground tabular-nums">
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
            </TabsContent>

            <TabsContent value="invoices">
                <div
                    v-if="invoices.data.length === 0"
                    class="rounded-xl border border-border bg-card"
                >
                    <EmptyState
                        :icon="IconFileText"
                        :title="$t('billing.invoices.empty_title')"
                        :description="$t('billing.invoices.empty_description')"
                        data-testid="billing-invoices-empty"
                    />
                </div>

                <div
                    v-else
                    class="overflow-hidden rounded-xl border border-border bg-card"
                >
                    <InfiniteScroll
                        data="invoices"
                        items-element="#billing-invoices-body"
                        preserve-url
                    >
                        <div class="overflow-x-auto">
                            <Table data-testid="billing-invoices">
                                <TableHeader>
                                    <TableRow class="hover:bg-transparent">
                                        <TableHead class="border-r-0 h-11 px-4 sm:px-5">
                                            {{ $t('billing.invoices.columns.date') }}
                                        </TableHead>
                                        <TableHead class="border-r-0 h-11 px-4">
                                            {{ $t('billing.invoices.columns.amount') }}
                                        </TableHead>
                                        <TableHead class="border-r-0 h-11 px-4">
                                            {{ $t('billing.invoices.columns.status') }}
                                        </TableHead>
                                        <TableHead class="border-r-0 h-11 w-14 px-4">
                                            <span class="sr-only">
                                                {{ $t('billing.invoices.download') }}
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody id="billing-invoices-body">
                                    <TableRow
                                        v-for="invoice in invoices.data"
                                        :key="invoice.id"
                                        class="last:border-b-0"
                                        :data-testid="`billing-invoice-${invoice.id}`"
                                    >
                                        <TableCell
                                            class="border-r-0 px-4 py-3 font-emphasis text-foreground sm:px-5"
                                        >
                                            {{ date.formatDate(invoice.date) }}
                                        </TableCell>
                                        <TableCell class="border-r-0 px-4 py-3 tabular-nums">
                                            {{ invoice.total }}
                                        </TableCell>
                                        <TableCell class="border-r-0 px-4 py-3">
                                            <Badge variant="success">
                                                {{ $t('billing.invoices.paid') }}
                                            </Badge>
                                        </TableCell>
                                        <TableCell class="border-r-0 px-2 py-2 text-right">
                                            <Button
                                                v-if="invoice.invoice_pdf"
                                                variant="ghost"
                                                size="icon"
                                                as="a"
                                                :href="invoice.invoice_pdf"
                                                target="_blank"
                                                rel="noopener"
                                                class="text-muted-foreground"
                                                :aria-label="$t('billing.invoices.download')"
                                                :data-testid="`billing-invoice-download-${invoice.id}`"
                                            >
                                                <IconDownload class="size-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>

                        <template #next="{ loading }">
                            <TableLoadMore v-if="loading" />
                        </template>
                    </InfiniteScroll>
                </div>
            </TabsContent>
        </Tabs>
    </SettingsLayout>
</template>
