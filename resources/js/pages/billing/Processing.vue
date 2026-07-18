<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { IconLoader2 } from '@tabler/icons-vue';
import { onMounted, onUnmounted, ref, watch } from 'vue';

import { useTracking } from '@/composables/useTracking';
import { accounts } from '@/routes/app';
import type { Auth } from '@/types';

const props = defineProps<{
    subscriptionActive: boolean;
    fromCheckout: boolean;
    persona?: string | null;
    conversion?: { value: number; currency: string; transaction_id: string } | null;
}>();

// Hold on the processing screen after firing the purchase event so PostHog and
// the ad pixels (Google/Meta via dataLayer → GTM) have time to send before we
// navigate away — an immediate redirect can cut those requests off.
const REDIRECT_DELAY_MS = 5000;

const page = usePage();

// Polls `auth` alongside so `auth.plan.interval` is fresh once the Stripe
// webhook creates the local Subscription row — at /billing/processing's
// initial render that row doesn't exist yet, so the interval would default
// to 'monthly' even for a yearly purchase.
const { stop } = usePoll(2000, {
    only: ['subscriptionActive', 'auth'],
});

const { trackPurchase } = useTracking();

const finishing = ref(false);
let redirectTimer: ReturnType<typeof setTimeout> | null = null;

const goToAccounts = () => router.visit(accounts.url());

// Fires `checkout.completed` exactly once for a real checkout. A trial-with-card
// subscription is already `subscribed()` (status `trialing`) by the time the
// webhook lands, so the user frequently reaches this page already active — the
// false → true poll transition never happens. We therefore complete the purchase
// from whichever path runs first (immediate active state or poll transition),
// gated on `fromCheckout` so back-button/refresh visits don't over-count.
const completePurchase = () => {
    if (finishing.value) {
        return;
    }
    finishing.value = true;
    stop();

    const plan = (page.props.auth as Auth | undefined)?.plan;

    if (props.fromCheckout && plan) {
        trackPurchase(
            { name: plan.name, interval: plan.interval },
            props.conversion ?? null,
            props.persona ?? null,
        );
    }

    // Always hold for the same window before navigating, so PostHog and the ad
    // pixels (Google/Meta via dataLayer → GTM) reliably flush.
    redirectTimer = setTimeout(goToAccounts, REDIRECT_DELAY_MS);
};

watch(
    () => props.subscriptionActive,
    (active) => {
        if (active) {
            completePurchase();
        }
    },
);

onMounted(() => {
    if (props.subscriptionActive) {
        completePurchase();
    }
});

onUnmounted(() => {
    if (redirectTimer) {
        clearTimeout(redirectTimer);
    }
});
</script>

<template>
    <Head :title="$t('billing.processing.page_title')" />

    <section class="relative flex min-h-screen items-center justify-center overflow-hidden bg-background px-6">
        <!-- Dot pattern overlay -->
        <div
            class="pointer-events-none absolute inset-0 opacity-[0.06]"
            style="background-image: radial-gradient(circle, #0a0a0a 1px, transparent 1px); background-size: 28px 28px;"
        />

        <!-- Soft violet glow blobs -->
        <div class="pointer-events-none absolute -top-24 -left-24 size-[440px] rounded-full bg-violet-200/50 blur-3xl" />
        <div class="pointer-events-none absolute -bottom-32 -right-24 size-[440px] rounded-full bg-fuchsia-200/30 blur-3xl" />

        <!-- Mockup window -->
        <div class="relative w-full max-w-md -rotate-1 overflow-hidden rounded-xl border-2 border-foreground bg-card shadow-xl">
            <!-- Title bar -->
            <div class="flex items-center gap-3 border-b-2 border-foreground bg-muted px-4 py-2.5">
                <div class="flex gap-1.5">
                    <span class="size-3 rounded-full border border-foreground bg-rose-300" />
                    <span class="size-3 rounded-full border border-foreground bg-amber-300" />
                    <span class="size-3 rounded-full border border-foreground bg-emerald-300" />
                </div>
                <div class="ml-2 min-w-0 truncate text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
                    trypost.it · checkout
                </div>
                <span class="ml-auto inline-flex items-center gap-1.5 rounded-md border-2 border-foreground bg-foreground px-2 py-0.5 text-[10px] font-black uppercase tracking-widest text-background shadow-2xs">
                    <span class="relative flex size-1.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/80" />
                        <span class="relative inline-flex size-1.5 rounded-full bg-emerald-400" />
                    </span>
                    Live
                </span>
            </div>

            <!-- Body -->
            <div class="flex flex-col items-center gap-5 px-8 py-12 text-center">
                <div class="flex size-16 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-sm -rotate-2">
                    <IconLoader2 class="size-8 animate-spin text-foreground" />
                </div>
                <div>
                    <h2 class="text-2xl font-normal tracking-tight text-foreground" style="font-family: var(--font-display);">
                        {{ $t('billing.processing.title') }}
                    </h2>
                    <p class="mt-2 text-sm leading-relaxed text-foreground/70">
                        {{ $t('billing.processing.description') }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
