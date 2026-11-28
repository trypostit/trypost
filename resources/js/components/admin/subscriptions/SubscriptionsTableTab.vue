<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { computed } from 'vue';

import AdminCustomerCell from '@/components/admin/AdminCustomerCell.vue';
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableLoadMore,
    TableRow,
} from '@/components/ui/table';
import date from '@/date';
import { subscriptionPlanLabel, subscriptionState } from '@/lib/adminSubscriptions';
import { billingUrl } from '@/lib/adminUsers';
import { blank, filled, transform } from '@/lib/helpers';
import { MISSING_VALUE } from '@/lib/utils';
import type { AdminSubscription, BadgeVariant, ScrollPage, SubscriptionStatusFilter } from '@/types/admin';

const props = defineProps<{
    subscriptions: ScrollPage<AdminSubscription>;
    /** The search and the status tab the list came with, which word an empty list. */
    search: string;
    status: SubscriptionStatusFilter;
}>();

/** What each status tab says when it holds no subscription. */
const EMPTY_MESSAGES: Record<SubscriptionStatusFilter, string> = {
    all: 'No subscriptions yet.',
    active: 'No active subscriptions.',
    cancelling: 'No subscription is cancelling.',
    past_due: 'No subscription is past due.',
    trialing: 'No subscription is trialing.',
    canceled: 'No canceled subscriptions.',
};

const hasNoResults = computed(() => blank(props.subscriptions.data));

const emptyMessage = computed((): string => {
    if (filled(props.search)) {
        return 'No subscriptions match this search.';
    }

    return EMPTY_MESSAGES[props.status];
});

const customerUrl = (subscription: AdminSubscription): string | null => transform(subscription.user_id, billingUrl);

const started = (subscription: AdminSubscription): string => {
    if (filled(subscription.started_at)) {
        return date.formatDate(subscription.started_at);
    }

    return MISSING_VALUE;
};

const planDetail = (subscription: AdminSubscription): string => {
    if (subscription.quantity > 1) {
        return `${subscriptionPlanLabel(subscription.plan)} × ${subscription.quantity}`;
    }

    return subscriptionPlanLabel(subscription.plan);
};

/** A canceled subscription has ended; any other with an end is cancelling and still runs until then. */
/** The filter state the subscription falls in, else Stripe's own status. */
const stateBadge = (subscription: AdminSubscription): { label: string; variant: BadgeVariant } => {
    if (filled(subscription.state)) {
        return subscriptionState(subscription.state);
    }

    return subscriptionState(subscription.stripe_status);
};

const endLabel = (subscription: AdminSubscription, endsAt: string): string => {
    if (subscription.state === 'canceled') {
        return `Ended ${date.formatDate(endsAt)}`;
    }

    return `Ends ${date.formatDate(endsAt)}`;
};

/** When it ends or ended, or when its trial does; an open-ended subscription renews. */
const until = (subscription: AdminSubscription): string => {
    if (filled(subscription.ends_at)) {
        return endLabel(subscription, subscription.ends_at);
    }

    if (subscription.state !== 'trialing') {
        return 'Renews';
    }

    if (filled(subscription.trial_ends_at)) {
        return `Trial ends ${date.formatDate(subscription.trial_ends_at)}`;
    }

    return 'Renews';
};
</script>

<template>
    <div>
        <AdminEmptyState v-if="hasNoResults" variant="page" test-id="admin-subscriptions-empty">
            {{ emptyMessage }}
        </AdminEmptyState>

        <div v-else class="overflow-hidden rounded-xl border border-border bg-card">
            <InfiniteScroll data="subscriptions" items-element="#admin-subscriptions-body" preserve-url>
                <div class="overflow-x-auto">
                    <Table data-testid="admin-subscriptions">
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="h-11 border-r-0 px-4 sm:px-5">Customer</TableHead>
                                <TableHead class="h-11 border-r-0 px-4">Plan</TableHead>
                                <TableHead class="h-11 border-r-0 px-4">Status</TableHead>
                                <TableHead class="h-11 border-r-0 px-4">Started</TableHead>
                                <TableHead class="h-11 border-r-0 px-4">Until</TableHead>
                                <TableHead class="h-11 border-r-0 px-4 sm:px-5">Offer</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody id="admin-subscriptions-body">
                            <TableRow
                                v-for="subscription in subscriptions.data"
                                :key="subscription.id"
                                class="last:border-b-0"
                                :data-testid="`admin-subscription-${subscription.id}`"
                            >
                                <TableCell class="border-r-0 px-4 py-3 sm:px-5">
                                    <AdminCustomerCell
                                        :name="subscription.user_name"
                                        :href="customerUrl(subscription)"
                                        :detail="subscription.user_email"
                                    />
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 text-sm whitespace-nowrap text-foreground">
                                    {{ planDetail(subscription) }}
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3">
                                    <Badge :variant="stateBadge(subscription).variant">{{
                                        stateBadge(subscription).label
                                    }}</Badge>
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 text-sm whitespace-nowrap text-muted-foreground">
                                    {{ started(subscription) }}
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 text-sm whitespace-nowrap text-muted-foreground">
                                    {{ until(subscription) }}
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 sm:px-5">
                                    <span class="flex flex-wrap gap-1">
                                        <Badge
                                            v-for="offer in subscription.offers"
                                            :key="offer"
                                            variant="secondary"
                                            class="font-mono"
                                            >{{ offer }}</Badge
                                        >
                                    </span>
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
    </div>
</template>
