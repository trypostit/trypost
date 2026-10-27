import AmericanExpress from '@thesvg/vue/american-express';
import DinersClub from '@thesvg/vue/diners-club';
import Discover from '@thesvg/vue/discover';
import Jcb from '@thesvg/vue/jcb';
import Mastercard from '@thesvg/vue/mastercard';
import Visa from '@thesvg/vue/visa';
import type { Component } from 'vue';

interface CardBrand {
    name: string;
    logo: Component | null;
}

/**
 * Card brands as Stripe names them (`card.brand`), with the name people know
 * and its logo. A brand without a logo here shows the generic card icon.
 */
const CARD_BRANDS: Record<string, CardBrand> = {
    amex: { name: 'American Express', logo: AmericanExpress },
    diners: { name: 'Diners Club', logo: DinersClub },
    discover: { name: 'Discover', logo: Discover },
    eftpos_au: { name: 'eftpos', logo: null },
    jcb: { name: 'JCB', logo: Jcb },
    mastercard: { name: 'Mastercard', logo: Mastercard },
    unionpay: { name: 'UnionPay', logo: null },
    visa: { name: 'Visa', logo: Visa },
};

export const cardBrand = (brand: string): CardBrand =>
    CARD_BRANDS[brand] ?? {
        name: brand.charAt(0).toUpperCase() + brand.slice(1),
        logo: null,
    };
