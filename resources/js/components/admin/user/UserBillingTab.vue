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
import { humanize, joinFilled, orDash } from '@/lib/adminFormat';
import { subscriptionState, UNPAID_STATES } from '@/lib/adminSubscriptions';
import { planName } from '@/lib/adminUsers';
import { cardBrand } from '@/lib/cardBrands';
import { blank, filled } from '@/lib/helpers';
import { MISSING_VALUE } from '@/lib/utils';
import type { AdminAccountBilling, AdminDetailRow, BadgeVariant } from '@/types/admin';

/** The billing of a user's account; a user without one never reaches this tab's content. */
const props = defineProps<{
    data: AdminAccountBilling;
}>();

type Subscription = NonNullable<AdminAccountBilling['subscription']>;
type Stripe = NonNullable<AdminAccountBilling['stripe']>;
type Discount = NonNullable<Stripe['discount']>;

const subscription = computed(() => props.data.subscription);
const stripe = computed(() => props.data.stripe);
const account = computed(() => props.data.account);

/** A payment that did not go through. */
const isOverdue = (current: Subscription): boolean => UNPAID_STATES.includes(current.status);

const OVERDUE_MESSAGES: Record<string, string> = {
    past_due: 'Payment overdue: the last invoice failed and Stripe is retrying it. The user keeps app access while it retries.',
    unpaid: 'Unpaid: Stripe stopped retrying the last invoice.',
};

/** What went wrong with the subscription's last payment; none while the payments go through. */
const overdueReason = (current: Subscription): string | null => {
    if (!isOverdue(current)) {
        return null;
    }

    const message = OVERDUE_MESSAGES[current.status];

    if (filled(message)) {
        return message;
    }

    return 'Incomplete: the first payment never went through.';
};

const overdueMessage = computed((): string | null => {
    if (filled(subscription.value)) {
        return overdueReason(subscription.value);
    }

    return null;
});

/** Why Stripe could not be read, when it could not. */
const stripeError = computed((): string | null => {
    if (filled(stripe.value)) {
        return stripe.value.error;
    }

    return null;
});

/** A cancellation shows while the payments go through; a failed payment says more. */
const showsCancellation = (current: Subscription): boolean => {
    if (isOverdue(current)) {
        return false;
    }

    return filled(current.cancels_at);
};

/** Where the subscription stands, as the Subscriptions list shows it. */
const subscriptionBadge = (current: Subscription): { label: string; variant: BadgeVariant } => {
    if (showsCancellation(current)) {
        return { label: 'Cancels at period end', variant: 'warning' };
    }

    return subscriptionState(current.status);
};

/** When and how the subscription bills; an account without one has none of these. */
const subscriptionRows = computed((): AdminDetailRow[] => {
    const current = subscription.value;

    if (filled(current)) {
        return [
            { key: 'interval', label: 'Billed', value: humanize(current.interval) },
            { key: 'started', label: 'Subscribed', value: date.formatDateTime(current.started_at) },
            { key: 'trial', label: 'Trial ends', value: date.formatDateTime(current.trial_ends_at) },
            { key: 'cancels', label: 'Cancels on', value: date.formatDateTime(current.cancels_at) },
        ];
    }

    return [];
});

const planRows = computed((): AdminDetailRow[] => [
    { key: 'plan', label: 'Plan', value: planName(props.data.plan) },
    { key: 'status', label: 'Subscription' },
    ...subscriptionRows.value,
    { key: 'generic-trial', label: 'Trial without a card ends', value: date.formatDateTime(account.value.generic_trial_ends_at) },
    { key: 'access', label: 'App access' },
    { key: 'stripe-id', label: 'Stripe customer' },
]);

/** What the discount takes off; a coupon Stripe gave no amount for leaves it empty. */
const discountAmount = (discount: Discount): string => {
    if (filled(discount.percent_off)) {
        return `${discount.percent_off}% off`;
    }

    if (filled(discount.amount_off)) {
        return `${discount.amount_off} off`;
    }

    return '';
};

/** The discount in one line, as the billing page shows it: what it takes off and its coupon. */
const discountSummary = (discount: Discount): string => joinFilled([discountAmount(discount), discount.name]);

const discountRows = (discount: Discount): AdminDetailRow[] => [
    { key: 'coupon', label: 'Coupon', value: orDash(discount.name) },
    { key: 'amount', label: 'Discount', value: discountAmount(discount) },
    { key: 'duration', label: 'Duration', value: humanize(discount.duration) },
    { key: 'ends', label: 'Ends', value: date.formatDateTime(discount.ends_at) },
    { key: 'code', label: 'Promotion code', value: orDash(discount.promotion_code) },
];

const hasOffers = computed(() => filled(props.data.offers));

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

const usedDiscountLabel = (used: Stripe['used_discounts'][number]): string => {
    const off = `${used.amount_off} off the invoice of ${date.formatDateOnly(used.invoice_date)}`;

    if (filled(used.name)) {
        return `${used.name}: ${off}`;
    }

    return `Coupon: ${off}`;
};
</script>

<template>
    <div class="flex flex-col gap-10" data-testid="admin-user-billing">
        <p
            v-if="stripeError"
            class="rounded-xl border border-destructive/40 bg-destructive/5 px-5 py-3 text-sm text-destructive"
            data-testid="admin-user-billing-stripe-error"
        >
            Couldn't read Stripe: {{ stripeError }}
        </p>

        <p
            v-if="overdueMessage"
            class="rounded-xl border border-destructive/40 bg-destructive/5 px-5 py-3 text-sm text-destructive"
            data-testid="admin-user-billing-overdue"
        >
            {{ overdueMessage }}
        </p>

        <SettingsSection title="Subscription">
            <AdminDetailList :rows="planRows" test-id="admin-user-billing-plan">
                <template #row-status>
                    <Badge v-if="subscription" :variant="subscriptionBadge(subscription).variant">{{
                        subscriptionBadge(subscription).label
                    }}</Badge>
                    <span v-else>No subscription</span>
                </template>
                <template #row-stripe-id>
                    <StripeCustomerLink
                        v-if="account.stripe_url"
                        :url="account.stripe_url"
                        :customer-id="account.stripe_id"
                    />
                    <span v-else>{{ MISSING_VALUE }}</span>
                </template>
                <template #row-access>
                    <Badge v-if="account.has_app_access" variant="success">Yes</Badge>
                    <Badge v-else variant="secondary">No</Badge>
                </template>
            </AdminDetailList>
        </SettingsSection>

        <template v-if="stripe">
            <SettingsSection title="Discount" description="Read from Stripe.">
                <div v-if="stripe.discount" class="flex flex-col gap-3">
                    <p
                        class="flex items-center gap-1.5 text-sm font-emphasis text-foreground"
                        data-testid="admin-user-billing-discount-summary"
                    >
                        <IconDiscount class="size-4 shrink-0 text-success-text" />
                        {{ discountSummary(stripe.discount) }}
                    </p>
                    <AdminDetailList :rows="discountRows(stripe.discount)" test-id="admin-user-billing-discount" />
                </div>
                <AdminEmptyState v-else>No discount on the subscription now.</AdminEmptyState>
                <div v-if="filled(stripe.used_discounts)" class="mt-4 flex flex-col gap-2">
                    <p class="text-xs font-medium text-muted-foreground">Used on past invoices</p>
                    <ul class="flex flex-col gap-2" data-testid="admin-user-billing-used-discounts">
                        <li
                            v-for="(used, index) in stripe.used_discounts"
                            :key="index"
                            class="flex items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 text-sm text-foreground"
                        >
                            <IconDiscount class="size-4 shrink-0 text-muted-foreground" />
                            {{ usedDiscountLabel(used) }}
                        </li>
                    </ul>
                </div>
            </SettingsSection>

            <SettingsSection title="Cards" description="Read from Stripe.">
                <AdminEmptyState v-if="blank(stripe.cards)">No card saved in Stripe.</AdminEmptyState>
                <ul v-else class="flex flex-col gap-2" data-testid="admin-user-billing-cards">
                    <SettingsListRow v-for="card in stripe.cards" :key="card.id">
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

            <SettingsSection title="Invoices">
                <AdminEmptyState v-if="blank(stripe.invoices)">No invoices.</AdminEmptyState>
                <ul
                    v-else
                    class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
                >
                    <li
                        v-for="invoice in stripe.invoices"
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
        </template>
        <SettingsSection v-else title="Cards" description="Read from Stripe.">
            <AdminEmptyState>No Stripe customer.</AdminEmptyState>
        </SettingsSection>

        <SettingsSection title="Offers">
            <AdminEmptyState v-if="!hasOffers">No offers claimed.</AdminEmptyState>
            <ul
                v-else
                class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
            >
                <li
                    v-for="offer in data.offers"
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
    </div>
</template>
