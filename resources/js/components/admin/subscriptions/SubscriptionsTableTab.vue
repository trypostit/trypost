<script setup lang="ts">
import { InfiniteScroll, Link } from '@inertiajs/vue3';

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
import { planLabel, subscriptionState } from '@/lib/adminSubscriptions';
import { blank, filled } from '@/lib/helpers';
import { show as userShow } from '@/routes/admin/users';
import type { AdminSubscription, ScrollPage } from '@/types/admin';

const props = defineProps<{
    subscriptions: ScrollPage<AdminSubscription>;
}>();

const isEmpty = (): boolean => blank(props.subscriptions.data);

const userName = (subscription: AdminSubscription): string => {
    if (filled(subscription.user_name)) {
        return subscription.user_name;
    }

    return 'Deleted user';
};

const billingUrl = (userId: string): string =>
    userShow.url(userId, { query: { tab: 'billing' } });

const planDetail = (subscription: AdminSubscription): string => {
    if (subscription.quantity > 1) {
        return `${planLabel(subscription.plan)} × ${subscription.quantity}`;
    }

    return planLabel(subscription.plan);
};

const started = (subscription: AdminSubscription): string => {
    if (filled(subscription.started_at)) {
        return date.formatDate(subscription.started_at);
    }

    return '—';
};

/** When it ends, or when its trial does; an open-ended subscription renews. */
const until = (subscription: AdminSubscription): string => {
    if (filled(subscription.ends_at)) {
        return `Ends ${date.formatDate(subscription.ends_at)}`;
    }

    if (subscription.state === 'trialing' && filled(subscription.trial_ends_at)) {
        return `Trial ends ${date.formatDate(subscription.trial_ends_at)}`;
    }

    return 'Renews';
};
</script>

<template>
    <p
        v-if="isEmpty()"
        class="rounded-xl border border-dashed border-border px-4 py-12 text-center text-sm text-muted-foreground"
        data-testid="admin-subscriptions-empty"
    >
        No subscriptions match.
    </p>

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
                                <Link
                                    v-if="subscription.user_id"
                                    :href="billingUrl(subscription.user_id)"
                                    class="grid min-w-0"
                                >
                                    <span class="truncate text-sm font-emphasis text-foreground hover:underline">{{
                                        userName(subscription)
                                    }}</span>
                                    <span class="truncate text-xs text-muted-foreground">{{
                                        subscription.user_email
                                    }}</span>
                                </Link>
                                <span v-else class="text-sm text-muted-foreground">{{
                                    userName(subscription)
                                }}</span>
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-3 text-sm whitespace-nowrap text-foreground">
                                {{ planDetail(subscription) }}
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-3">
                                <Badge :variant="subscriptionState(subscription.state).variant">{{
                                    subscriptionState(subscription.state).label
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
</template>
