interface CardBrand {
    name: string;
    logo: string;
}

/**
 * Card brands as Stripe names them (`card.brand`), with the name people know
 * and its card-shaped logo. A brand without its own logo gets the generic card.
 */
const CARD_BRANDS: Record<string, CardBrand> = {
    amex: { name: 'American Express', logo: '/images/card-brands/amex.svg' },
    cartes_bancaires: { name: 'Cartes Bancaires', logo: '/images/card-brands/generic.svg' },
    diners: { name: 'Diners Club', logo: '/images/card-brands/diners.svg' },
    discover: { name: 'Discover', logo: '/images/card-brands/discover.svg' },
    eftpos_au: { name: 'eftpos', logo: '/images/card-brands/generic.svg' },
    interac: { name: 'Interac', logo: '/images/card-brands/generic.svg' },
    jcb: { name: 'JCB', logo: '/images/card-brands/jcb.svg' },
    link: { name: 'Link', logo: '/images/card-brands/generic.svg' },
    mastercard: { name: 'Mastercard', logo: '/images/card-brands/mastercard.svg' },
    unionpay: { name: 'UnionPay', logo: '/images/card-brands/unionpay.svg' },
    visa: { name: 'Visa', logo: '/images/card-brands/visa.svg' },
};

export const cardBrand = (brand: string): CardBrand =>
    CARD_BRANDS[brand] ?? {
        name: brand.charAt(0).toUpperCase() + brand.slice(1),
        logo: '/images/card-brands/generic.svg',
    };
