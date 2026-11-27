<script setup lang="ts">
import { IconDiscount, IconDownload } from '@tabler/icons-vue';
import { computed } from 'vue';

import AdminDetailList from '@/components/admin/AdminDetailList.vue';
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import StripeCustomerLink from '@/components/admin/StripeCustomerLink.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import date from '@/date';
import { dateTimeOrDash, humanize, joinFilled, orDash } from '@/lib/adminFormat';
import { subscriptionState } from '@/lib/adminSubscriptions';
import { cardBrand } from '@/lib/cardBrands';
import { filled } from '@/lib/helpers';
import type { AdminDetailRow, AdminUserBillingData, BadgeVariant } from '@/types/admin';

const props = defineProps<{
    data: AdminUserBillingData;
}>();

type Discount = NonNullable<NonNullable<AdminUserBillingData['stripe']>['discount']>;

const subscription = computed(() => props.data.subscription ?? null);
const stripe = computed(() => props.data.stripe ?? null);
const account = computed(() => props.data.account);
const hasAccount = computed(() => filled(account.value));
const isCancelling = computed(() => filled(subscription.value?.cancels_at));

/** Stripe statuses where the last payment did not go through. */
const UNPAID_STATUSES = ['past_due', 'unpaid', 'incomplete', 'incomplete_expired'];

const isOverdue = computed((): boolean => {
    if (filled(subscription.value)) {
        return UNPAID_STATUSES.includes(subscription.value.status);
    }

    return false;
});

const overdueMessage = computed((): string => {
    if (subscription.value?.status === 'past_due') {
        return 'Payment overdue: the last invoice failed and Stripe is retrying it. The user keeps app access while it retries.';
    }

    if (subscription.value?.status === 'unpaid') {
        return 'Unpaid: Stripe stopped retrying the last invoice.';
    }

    return 'Incomplete: the first payment never went through.';
});

const stripeUrl = computed(() => account.value?.stripe_url);
const stripeId = computed(() => account.value?.stripe_id);
const hasAppAccess = computed((): boolean => {
    if (filled(account.value)) {
        return account.value.has_app_access;
    }

    return false;
});

const subscriptionStatus = computed((): string => {
    if (filled(subscription.value)) {
        return subscription.value.status;
    }

    return '';
});

/**
 * Where the subscription stands, as the Subscriptions list shows it: a
 * payment that failed first, then a cancellation at the period end.
 */
const subscriptionBadge = computed((): { label: string; variant: BadgeVariant } => {
    if (isOverdue.value) {
        return subscriptionState(subscriptionStatus.value);
    }

    if (isCancelling.value) {
        return { label: 'Cancels at period end', variant: 'warning' };
    }

    return subscriptionState(subscriptionStatus.value);
});

const planName = computed((): string => {
    if (filled(props.data.plan)) {
        return props.data.plan;
    }

    return 'No plan';
});

const planRows = computed((): AdminDetailRow[] => [
    { key: 'plan', label: 'Plan', value: planName.value },
    { key: 'status', label: 'Subscription' },
    { key: 'interval', label: 'Billed', value: humanize(subscription.value?.interval) },
    { key: 'started', label: 'Subscribed', value: dateTimeOrDash(subscription.value?.started_at) },
    { key: 'trial', label: 'Trial ends', value: dateTimeOrDash(subscription.value?.trial_ends_at) },
    { key: 'cancels', label: 'Cancels on', value: dateTimeOrDash(subscription.value?.cancels_at) },
    { key: 'generic-trial', label: 'Trial without a card ends', value: dateTimeOrDash(account.value?.generic_trial_ends_at) },
    { key: 'access', label: 'App access' },
    { key: 'stripe-id', label: 'Stripe customer' },
]);

const discount = computed(() => stripe.value?.discount ?? null);

/** What the discount takes off; a coupon Stripe gave no amount for leaves it empty. */
const discountAmount = (current: Discount): string => {
    if (filled(current.percent_off)) {
        return `${current.percent_off}% off`;
    }

    if (filled(current.amount_off)) {
        return `${current.amount_off} off`;
    }

    return '';
};

/** The discount in one line, as the billing page shows it: what it takes off and its coupon. */
const discountSummary = computed((): string => {
    if (filled(discount.value)) {
        return joinFilled([discountAmount(discount.value), discount.value.name]);
    }

    return '';
});

const discountRows = computed((): AdminDetailRow[] => {
    if (filled(discount.value)) {
        return [
            { key: 'coupon', label: 'Coupon', value: orDash(discount.value.name) },
            { key: 'amount', label: 'Discount', value: discountAmount(discount.value) },
            { key: 'duration', label: 'Duration', value: humanize(discount.value.duration) },
            { key: 'ends', label: 'Ends', value: dateTimeOrDash(discount.value.ends_at) },
            { key: 'code', label: 'Promotion code', value: orDash(discount.value.promotion_code) },
        ];
    }

    return [];
});

const cards = computed(() => stripe.value?.cards ?? []);
const invoices = computed(() => stripe.value?.invoices ?? []);
const offers = computed(() => props.data.offers ?? []);
const hasCards = computed(() => filled(cards.value));
const hasInvoices = computed(() => filled(invoices.value));
const hasOffers = computed(() => filled(offers.value));
const stripeError = computed(() => stripe.value?.error ?? null);
const readsStripe = computed(() => filled(stripe.value));

const expiry = (month: number, year: number): string =>
    `Expires ${String(month).padStart(2, '0')}/${year}`;

const offerState = (redeemedAt: string | null): string => {
    if (filled(redeemedAt)) {
        return `Redeemed ${date.formatDate(redeemedAt)}`;
    }

    return 'Claimed, not redeemed';
};

/** Stripe's invoice statuses: paid is green, one still owed (open) or given up on (uncollectible) is red. */
const INVOICE_VARIANTS: Record<string, BadgeVariant> = {
    paid: 'success',
    open: 'destructive-subtle',
    uncollectible: 'destructive-subtle',
};

const invoiceVariant = (status: string): BadgeVariant => {
    const variant = INVOICE_VARIANTS[status];

    if (filled(variant)) {
        return variant;
    }

    return 'secondary';
};

const usedDiscounts = computed(() => stripe.value?.used_discounts ?? []);
const hasUsedDiscounts = computed(() => filled(usedDiscounts.value));

const usedDiscountLabel = (used: { name: string | null; amount_off: string; invoice_date: string }): string => {
    const off = `${used.amount_off} off the invoice of ${date.formatDateOnly(used.invoice_date)}`;

    if (filled(used.name)) {
        return `${used.name}: ${off}`;
    }

    return `Coupon: ${off}`;
};
</script>

<template>
    <div class="flex flex-col gap-10" data-testid="admin-user-billing">
        <AdminEmptyState v-if="!hasAccount">This user has no account.</AdminEmptyState>

        <template v-else>
            <p
                v-if="stripeError"
                class="rounded-xl border border-destructive/40 bg-destructive/5 px-5 py-3 text-sm text-destructive"
                data-testid="admin-user-billing-stripe-error"
            >
                Couldn't read Stripe: {{ stripeError }}
            </p>

            <p
                v-if="isOverdue"
                class="rounded-xl border border-destructive/40 bg-destructive/5 px-5 py-3 text-sm text-destructive"
                data-testid="admin-user-billing-overdue"
            >
                {{ overdueMessage }}
            </p>

            <SettingsSection title="Subscription">
                <AdminDetailList :rows="planRows" test-id="admin-user-billing-plan">
                    <template #row-status>
                        <Badge v-if="subscription" :variant="subscriptionBadge.variant">{{
                            subscriptionBadge.label
                        }}</Badge>
                        <span v-else>No subscription</span>
                    </template>
                    <template #row-stripe-id>
                        <StripeCustomerLink
                            v-if="stripeUrl"
                            :url="stripeUrl"
                            :customer-id="stripeId"
                        />
                        <span v-else>—</span>
                    </template>
                    <template #row-access>
                        <Badge v-if="hasAppAccess" variant="success">Yes</Badge>
                        <Badge v-else variant="secondary">No</Badge>
                    </template>
                </AdminDetailList>
            </SettingsSection>

            <SettingsSection
                v-if="readsStripe"
                title="Discount"
                description="Read from Stripe."
            >
                <div v-if="discount" class="flex flex-col gap-3">
                    <p
                        class="flex items-center gap-1.5 text-sm font-emphasis text-foreground"
                        data-testid="admin-user-billing-discount-summary"
                    >
                        <IconDiscount class="size-4 shrink-0 text-success-text" />
                        {{ discountSummary }}
                    </p>
                    <AdminDetailList :rows="discountRows" test-id="admin-user-billing-discount" />
                </div>
                <AdminEmptyState v-else>No discount on the subscription now.</AdminEmptyState>
                <div v-if="hasUsedDiscounts" class="mt-4 flex flex-col gap-2">
                    <p class="text-xs font-medium text-muted-foreground">Used on past invoices</p>
                    <ul class="flex flex-col gap-2" data-testid="admin-user-billing-used-discounts">
                        <li
                            v-for="(used, index) in usedDiscounts"
                            :key="index"
                            class="flex items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 text-sm text-foreground"
                        >
                            <IconDiscount class="size-4 shrink-0 text-muted-foreground" />
                            {{ usedDiscountLabel(used) }}
                        </li>
                    </ul>
                </div>
            </SettingsSection>

            <SettingsSection
                title="Cards"
                description="Read from Stripe."
            >
                <AdminEmptyState v-if="!readsStripe">No Stripe customer.</AdminEmptyState>
                <AdminEmptyState v-else-if="!hasCards">No card saved in Stripe.</AdminEmptyState>
                <ul v-else class="flex flex-col gap-2" data-testid="admin-user-billing-cards">
                    <SettingsListRow v-for="card in cards" :key="card.id">
                        <template #media>
                            <img
                                :src="cardBrand(card.brand).logo"
                                alt=""
                                class="h-7 w-auto shrink-0 rounded-[5px] shadow-xs"
                            />
                        </template>
                        <p class="text-sm font-emphasis text-foreground">
                            {{ cardBrand(card.brand).name }} •••• {{ card.last4 }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ expiry(card.exp_month, card.exp_year) }}
                        </p>
                        <template #actions>
                            <Badge v-if="card.is_default">Default</Badge>
                        </template>
                    </SettingsListRow>
                </ul>
            </SettingsSection>

            <SettingsSection v-if="readsStripe" title="Invoices">
                <AdminEmptyState v-if="!hasInvoices">No invoices.</AdminEmptyState>
                <ul
                    v-else
                    class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
                >
                    <li
                        v-for="invoice in invoices"
                        :key="invoice.id"
                        class="flex items-center gap-3 px-5 py-3 sm:px-6"
                    >
                        <span class="flex-1 text-sm text-foreground tabular-nums">{{
                            date.formatDate(invoice.date)
                        }}</span>
                        <span class="text-sm text-foreground tabular-nums">{{
                            invoice.total
                        }}</span>
                        <Badge :variant="invoiceVariant(invoice.status)">{{
                            humanize(invoice.status)
                        }}</Badge>
                        <Button
                            v-if="invoice.invoice_pdf"
                            variant="ghost"
                            size="icon"
                            as="a"
                            :href="invoice.invoice_pdf"
                            target="_blank"
                            rel="noopener"
                            class="text-muted-foreground"
                            aria-label="Download invoice"
                        >
                            <IconDownload class="size-4" />
                        </Button>
                    </li>
                </ul>
            </SettingsSection>

            <SettingsSection title="Offers">
                <AdminEmptyState v-if="!hasOffers">No offers claimed.</AdminEmptyState>
                <ul
                    v-else
                    class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
                >
                    <li
                        v-for="offer in offers"
                        :key="offer.slug"
                        class="flex items-center justify-between gap-3 px-5 py-3 sm:px-6"
                    >
                        <span class="text-sm font-emphasis text-foreground">{{
                            offer.slug
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            offerState(offer.redeemed_at)
                        }}</span>
                    </li>
                </ul>
            </SettingsSection>
        </template>
    </div>
</template>
