<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconDots, IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import AdminStatGrid from '@/components/admin/AdminStatGrid.vue';
import OfferFormDialog from '@/components/admin/offers/OfferFormDialog.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { firstError } from '@/lib/adminFormat';
import { filled, isNumber } from '@/lib/helpers';
import { formatFullNumber, formatPercent, percentOf } from '@/lib/utils';
import { destroy as offersDestroy } from '@/routes/admin/billing/offers';
import { store as offerEndStore } from '@/routes/admin/billing/offers/end';
import type { AdminOffer, AdminStat, BadgeVariant, OfferState } from '@/types/admin';

const STATES: Record<OfferState, { label: string; variant: BadgeVariant }> = {
    live: { label: 'Live', variant: 'success' },
    scheduled: { label: 'Scheduled', variant: 'info' },
    full: { label: 'Full', variant: 'warning' },
    ended: { label: 'Ended', variant: 'secondary' },
};

/** States an offer can still be ended from. */
const ENDABLE_STATES: OfferState[] = ['live', 'scheduled', 'full'];

const props = defineProps<{
    offers: AdminOffer[];
    today: string;
}>();

type OffersTab = 'all' | OfferState;

/** The tabs, each a set of states; All holds every offer. */
const TABS: { value: OffersTab; label: string; states: OfferState[] }[] = [
    { value: 'all', label: 'All', states: ['live', 'scheduled', 'full', 'ended'] },
    { value: 'live', label: 'Live', states: ['live'] },
    { value: 'scheduled', label: 'Scheduled', states: ['scheduled'] },
    { value: 'full', label: 'Full', states: ['full'] },
    { value: 'ended', label: 'Ended', states: ['ended'] },
];

const tab = ref<OffersTab>('all');

const tabStates = computed((): OfferState[] => {
    const current = TABS.find((option) => option.value === tab.value);

    if (filled(current)) {
        return current.states;
    }

    return [];
});

const shownOffers = computed(() =>
    props.offers.filter((offer) => tabStates.value.includes(offer.state)),
);

const tabCount = (states: OfferState[]): string =>
    formatFullNumber(props.offers.filter((offer) => states.includes(offer.state)).length);

const selectTab = (value: string | number): void => {
    const picked = TABS.find((option) => option.value === value);

    if (filled(picked)) {
        tab.value = picked.value;
    }
};

const hasOffers = computed(() => filled(props.offers));
const hasShownOffers = computed(() => filled(shownOffers.value));

const sum = (pick: (offer: AdminOffer) => number): number =>
    props.offers.reduce((total, offer) => total + pick(offer), 0);

const conversion = (redeemed: number, claimed: number): string => {
    if (claimed === 0) {
        return '—';
    }

    return formatPercent(percentOf(redeemed, claimed), 1);
};

const summary = computed((): AdminStat[] => [
    {
        key: 'offers-live',
        label: 'Live offers',
        value: formatFullNumber(props.offers.filter((offer) => offer.state === 'live').length),
    },
    {
        key: 'offers-claimed',
        label: 'Claimed',
        value: formatFullNumber(sum((offer) => offer.claimed)),
        hint: 'Accounts that opened an offer link',
    },
    {
        key: 'offers-redeemed',
        label: 'Redeemed',
        value: formatFullNumber(sum((offer) => offer.redeemed)),
        hint: 'Became a subscription with the offer',
    },
    {
        key: 'offers-conversion',
        label: 'Conversion',
        value: conversion(sum((offer) => offer.redeemed), sum((offer) => offer.claimed)),
        hint: 'Redeemed of claimed',
    },
]);

const state = (offer: AdminOffer): { label: string; variant: BadgeVariant } => STATES[offer.state];

/** A trial means at least one day; 0 is no trial. */
const hasTrial = (offer: AdminOffer): boolean => {
    if (isNumber(offer.trial_days)) {
        return offer.trial_days > 0;
    }

    return false;
};

/** What the offer gives, in a few words. */
const gives = (offer: AdminOffer): string => {
    const parts: string[] = [];

    if (filled(offer.stripe_coupon_id)) {
        parts.push(`Coupon ${offer.stripe_coupon_id}`);
    }

    if (hasTrial(offer)) {
        parts.push(`${offer.trial_days}-day trial`);
    }

    if (offer.allow_promotion_codes) {
        parts.push('promotion codes');
    }

    return parts.join(' · ');
};

const rules = (offer: AdminOffer): string[] => {
    const tags: string[] = [];

    if (!offer.requires_card) {
        tags.push('No card');
    }

    if (offer.first_subscription_only) {
        tags.push('First subscription');
    }

    if (offer.show_banner) {
        tags.push('Banner');
    }

    return tags;
};

const dates = (offer: AdminOffer): string => {
    const starts = date.formatDate(offer.starts_at);

    if (filled(offer.ends_at)) {
        return `${starts} – ${date.formatDate(offer.ends_at)}`;
    }

    return `From ${starts}`;
};

const redemptions = (offer: AdminOffer): string => {
    if (filled(offer.max_redemptions)) {
        return `${formatFullNumber(offer.redeemed)} of ${formatFullNumber(offer.max_redemptions)}`;
    }

    return formatFullNumber(offer.redeemed);
};

const hasMissingCopy = (offer: AdminOffer): boolean => filled(offer.missing_copy);

const missingCopyLabel = (offer: AdminOffer): string => {
    if (offer.missing_copy.length === 1) {
        return 'Copy missing in 1 locale';
    }

    return `Copy missing in ${offer.missing_copy.length} locales`;
};

const missingCopyList = (offer: AdminOffer): string =>
    `Add it to lang/<locale>/offers.php: ${offer.missing_copy.join(', ')}`;

const canEnd = (offer: AdminOffer): boolean => ENDABLE_STATES.includes(offer.state);
const canDelete = (offer: AdminOffer): boolean => offer.redeemed === 0;

const formOpen = ref(false);
const editing = ref<AdminOffer | null>(null);

const openCreate = (): void => {
    editing.value = null;
    formOpen.value = true;
};

const openEdit = (offer: AdminOffer): void => {
    editing.value = offer;
    formOpen.value = true;
};

type Confirmation = { offer: AdminOffer; action: 'end' | 'delete' };

/** Kept after the dialog closes, so its text holds while it animates out. */
const confirming = ref<Confirmation | null>(null);
const confirmOpen = ref(false);
const isConfirming = ref(false);

const ask = (confirmation: Confirmation): void => {
    confirming.value = confirmation;
    confirmOpen.value = true;
};

const askEnd = (offer: AdminOffer): void => {
    ask({ offer, action: 'end' });
};

const askDelete = (offer: AdminOffer): void => {
    ask({ offer, action: 'delete' });
};

const isDeleting = computed(() => confirming.value?.action === 'delete');

const confirmingSlug = computed((): string => {
    if (filled(confirming.value)) {
        return confirming.value.offer.slug;
    }

    return '';
});

const confirmTitle = computed((): string => {
    if (isDeleting.value) {
        return `Delete ${confirmingSlug.value}?`;
    }

    return `End ${confirmingSlug.value} now?`;
});

const confirmDescription = computed((): string => {
    if (isDeleting.value) {
        return 'Nobody redeemed it. Its claims go with it, and its links stop giving anything.';
    }

    return 'Nobody can claim or redeem it from now on. Who already redeemed it keeps what they got.';
});

const confirmVariant = computed((): 'destructive' | 'default' => {
    if (isDeleting.value) {
        return 'destructive';
    }

    return 'default';
});

const confirmLabel = computed((): string => {
    if (isDeleting.value) {
        return 'Delete offer';
    }

    return 'End offer';
});

const showError = (errors: Record<string, string>): void => {
    toast.error(firstError(errors, 'Could not change this offer.'));
};

const send = (current: Confirmation): void => {
    const options = {
        preserveScroll: true,
        onStart: () => {
            isConfirming.value = true;
        },
        onFinish: () => {
            isConfirming.value = false;
            confirmOpen.value = false;
        },
        onError: showError,
    };

    if (current.action === 'delete') {
        router.delete(offersDestroy.url(current.offer.id), options);

        return;
    }

    router.post(offerEndStore.url(current.offer.id), {}, options);
};

const confirm = (): void => {
    if (filled(confirming.value)) {
        send(confirming.value);
    }
};
</script>

<template>
    <Head title="Offers" />

    <AdminLayout
        title="Offers"
        description="What the Welcome checkout gives instead of the full price."
    >
        <template #actions>
            <Button data-testid="admin-offer-new" @click="openCreate">
                <IconPlus class="size-4" />
                New offer
            </Button>
        </template>

        <AdminEmptyState v-if="!hasOffers" variant="page">No offers yet.</AdminEmptyState>

        <div v-else class="flex flex-col gap-6">
            <AnalyticsSection title="All offers" flush>
                <AdminStatGrid :stats="summary" />
            </AnalyticsSection>

            <Tabs :model-value="tab" @update:model-value="selectTab">
                <TabsList variant="line">
                    <TabsTrigger
                        v-for="option in TABS"
                        :key="option.value"
                        :value="option.value"
                        :data-testid="`admin-offers-tab-${option.value}`"
                    >
                        {{ option.label }}
                        <span class="text-xs text-muted-foreground tabular-nums">{{
                            tabCount(option.states)
                        }}</span>
                    </TabsTrigger>
                </TabsList>
            </Tabs>

            <AdminEmptyState v-if="!hasShownOffers" variant="page">No offers here.</AdminEmptyState>

            <div v-else class="overflow-hidden rounded-xl border border-border bg-card">
                <div class="overflow-x-auto">
                    <Table data-testid="admin-offers">
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="h-11 border-r-0 px-4 sm:px-5">Offer</TableHead>
                                <TableHead class="h-11 border-r-0 px-4">Gives</TableHead>
                                <TableHead class="h-11 border-r-0 px-4">Dates</TableHead>
                                <TableHead class="h-11 border-r-0 px-4 text-right">Redeemed</TableHead>
                                <TableHead class="h-11 border-r-0 px-4 text-right">Conversion</TableHead>
                                <TableHead class="h-11 w-12 border-r-0 px-4 sm:px-5">
                                    <span class="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="offer in shownOffers"
                                :key="offer.id"
                                class="last:border-b-0"
                                :data-testid="`admin-offer-${offer.slug}`"
                            >
                                <TableCell class="border-r-0 px-4 py-3 align-top sm:px-5">
                                    <div class="flex flex-col gap-1.5">
                                        <span class="flex items-center gap-2">
                                            <code class="font-mono text-sm font-emphasis text-foreground">{{
                                                offer.slug
                                            }}</code>
                                            <Badge :variant="state(offer).variant">{{ state(offer).label }}</Badge>
                                        </span>
                                        <Tooltip v-if="hasMissingCopy(offer)">
                                            <TooltipTrigger as-child>
                                                <span
                                                    class="w-fit cursor-default text-xs text-warning"
                                                    :data-testid="`admin-offer-missing-copy-${offer.slug}`"
                                                    >{{ missingCopyLabel(offer) }}</span
                                                >
                                            </TooltipTrigger>
                                            <TooltipContent class="max-w-sm">
                                                {{ missingCopyList(offer) }}
                                            </TooltipContent>
                                        </Tooltip>
                                    </div>
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 align-top">
                                    <div class="flex flex-col gap-1.5">
                                        <span class="text-sm text-foreground">{{ gives(offer) }}</span>
                                        <span class="flex flex-wrap gap-1">
                                            <Badge
                                                v-for="tag in rules(offer)"
                                                :key="tag"
                                                variant="secondary"
                                                >{{ tag }}</Badge
                                            >
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 align-top text-sm whitespace-nowrap text-muted-foreground">
                                    {{ dates(offer) }}
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 text-right align-top">
                                    <span class="block text-sm font-medium text-foreground tabular-nums">{{
                                        redemptions(offer)
                                    }}</span>
                                    <span class="block text-xs text-muted-foreground tabular-nums"
                                        >{{ formatFullNumber(offer.claimed) }} claimed</span
                                    >
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 text-right align-top text-sm text-foreground tabular-nums">
                                    {{ conversion(offer.redeemed, offer.claimed) }}
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 text-right align-top sm:px-5">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                :aria-label="`Actions for ${offer.slug}`"
                                                :data-testid="`admin-offer-actions-${offer.slug}`"
                                            >
                                                <IconDots class="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem @select="openEdit(offer)">Edit</DropdownMenuItem>
                                            <DropdownMenuItem v-if="canEnd(offer)" @select="askEnd(offer)">
                                                End now
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="canDelete(offer)"
                                                variant="destructive"
                                                @select="askDelete(offer)"
                                            >
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </div>
        </div>
    </AdminLayout>

    <OfferFormDialog v-model:open="formOpen" :offer="editing" :today="today" />

    <AlertDialog v-model:open="confirmOpen">
        <AlertDialogContent data-testid="admin-offer-confirm">
            <AlertDialogHeader>
                <AlertDialogTitle>{{ confirmTitle }}</AlertDialogTitle>
                <AlertDialogDescription>{{ confirmDescription }}</AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <Button
                    :variant="confirmVariant"
                    :loading="isConfirming"
                    data-testid="admin-offer-confirm-submit"
                    @click="confirm"
                >
                    {{ confirmLabel }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
