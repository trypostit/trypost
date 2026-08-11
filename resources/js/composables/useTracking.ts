const push = (data: Record<string, unknown>) => {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(data);
};

/**
 * GTM-only now — the PostHog side of these conversion events (sign_up,
 * begin_checkout, purchase) is fired from the backend instead of captured
 * client-side, so it isn't lost to ad blockers or a cut-short page unload.
 * See App\Actions\User\CreateUser, App\Http\Controllers\App\WelcomeController,
 * and App\Jobs\PostHog\TrackCheckoutCompleted.
 */
export const useTracking = () => ({
    trackSignUp: (authProvider: string) => {
        push({
            event: 'sign_up',
            method: authProvider,
        });
    },

    trackBeginCheckout: (plan: { name: string; interval: string }) => {
        push({
            event: 'begin_checkout',
            plan_name: plan.name,
            plan_interval: plan.interval,
        });
    },

    trackPurchase: (
        plan: { name: string; interval: string },
        conversion?: { value: number; currency: string; transaction_id: string } | null,
        persona?: string | null,
    ) => {
        push({
            event: 'purchase',
            plan_name: plan.name,
            plan_interval: plan.interval,
            ...(persona ? { persona } : {}),
            ...(conversion ? {
                conversion_value: conversion.value,
                conversion_currency: conversion.currency,
                conversion_transaction_id: conversion.transaction_id,
            } : {}),
        });
    },
});
