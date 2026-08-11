import { captureEvent } from '@/posthog';

/**
 * PostHog-only now — the GTM side of these conversion events (sign_up,
 * begin_checkout, purchase) is fired from the backend instead of pushed to
 * the client-side dataLayer, so it isn't lost to ad blockers or a cut-short
 * page unload. See App\Services\GtmServerService.
 */
export const useTracking = () => ({
    trackSignUp: (authProvider: string) => {
        captureEvent('user.signed_up', {
            auth_provider: authProvider,
        });
    },

    trackBeginCheckout: (plan: { name: string; interval: string }) => {
        captureEvent('checkout.started', {
            plan_name: plan.name,
            interval: plan.interval,
        });
    },

    trackPurchase: (
        plan: { name: string; interval: string },
        conversion?: { value: number; currency: string; transaction_id: string } | null,
        persona?: string | null,
    ) => {
        captureEvent('checkout.completed', {
            plan_name: plan.name,
            interval: plan.interval,
            ...(persona ? { persona } : {}),
            ...(conversion ? {
                conversion_value: conversion.value,
                conversion_currency: conversion.currency,
                conversion_transaction_id: conversion.transaction_id,
            } : {}),
        });
    },
});
