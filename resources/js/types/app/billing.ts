export interface Subscription {
    stripe_status: string;
    ends_at: string | null;
}

export interface PaymentMethod {
    id: string;
    brand: string;
    last4: string;
    exp_month: number;
    exp_year: number;
    is_default: boolean;
}

export interface SubscriptionDiscount {
    value: string;
    duration: 'once' | 'repeating' | 'forever';
    ends_at: string | null;
}

export interface Invoice {
    id: string;
    date: string;
    total: string;
    status: string;
    invoice_pdf: string | null;
}
