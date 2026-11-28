/** The Subscriptions list's tabs (`App\Enums\Billing\SubscriptionStatusFilter`). */
export type SubscriptionStatusFilter = 'all' | 'active' | 'cancelling' | 'past_due' | 'trialing' | 'canceled';

/** Where an offer stands (`App\Enums\Offer\OfferState`). */
export type OfferState = 'scheduled' | 'live' | 'full' | 'ended';

export interface AdminSubscriptionPlan {
    plan: string;
    name: string;
    interval: string;
}

export interface AdminSubscriptionOverview {
    previous: { new: number; canceled: number; churn_rate: number; mrr_cents: number };
    now: { alive: number; active: number; past_due: number; trialing: number; cancelling: number };
    period: { new: number; canceled: number; alive_at_start: number; churn_rate: number };
    days: string[];
    alive_by_day: number[];
    new_by_day: number[];
    canceled_by_day: number[];
    churn_rate_by_day: number[];
    plans: (AdminSubscriptionPlan & { count: number })[];
    mrr: { cents: number; unpriced: number };
    at_risk: { past_due_cents: number; cancelling_cents: number };
    offers: { slug: string; redeemed: number; still_subscribed: number }[];
}

export interface AdminSubscriptionChurn {
    summary: { canceled: number; churn_rate: number; average_lifetime_days: number; cancelling: number };
    days: string[];
    canceled_by_day: number[];
    churn_rate_by_day: number[];
    recent: (AdminSubscription & { lifetime_days: number })[];
    lifetimes: { label: string; count: number }[];
    cohorts: { month: string; started: number; alive: number; retention: number }[];
}

export interface AdminSubscription {
    id: string;
    user_id: string | null;
    user_name: string | null;
    user_email: string | null;
    plan: AdminSubscriptionPlan;
    quantity: number;
    /** The status filter it falls in; null for a Stripe status no filter covers (incomplete, unpaid…). */
    state: Exclude<SubscriptionStatusFilter, 'all'> | null;
    stripe_status: string;
    started_at: string | null;
    trial_ends_at: string | null;
    ends_at: string | null;
    offers: string[];
}

export type AdminNetMrr = { cents: number } | { error: string };

export interface AdminOffer {
    id: string;
    slug: string;
    stripe_coupon_id: string | null;
    trial_days: number | null;
    starts_at: string | null;
    ends_at: string | null;
    starts_on: string | null;
    ends_on: string | null;
    max_redemptions: number | null;
    allow_promotion_codes: boolean;
    first_subscription_only: boolean;
    show_banner: boolean;
    requires_card: boolean;
    state: OfferState;
    claimed: number;
    redeemed: number;
    missing_copy: string[];
}

export interface AdminBreakdownRow {
    value: string;
    label: string;
    count: number;
    subscribed: number;
    subscribed_rate: number;
}

export interface AdminSignupTotals {
    signups: number;
    subscribed: number;
    subscribed_rate: number;
    new_mrr_cents: number;
    /** The median from signing up to subscribing; null when nobody subscribed. */
    minutes_to_subscribe: number | null;
}

export interface AdminSignupInsights {
    totals: AdminSignupTotals;
    previous: AdminSignupTotals;
    days: string[];
    signups_by_day: number[];
    subscribed_by_day: number[];
    funnel: { key: string; label: string; count: number }[];
    personas: AdminBreakdownRow[];
    software: AdminBreakdownRow[];
    goals: AdminBreakdownRow[];
    referral_sources: AdminBreakdownRow[];
    signup_methods: AdminBreakdownRow[];
    answered: { persona: number; software: number; goals: number; referral_source: number };
}
