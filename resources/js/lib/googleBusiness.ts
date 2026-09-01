/**
 * Google Business Profile Local Post constants shared by the editor settings
 * panel, the preview, and the publish compliance gate.
 */

/**
 * Topic types whose Local Post requires an `event` object (title + date range).
 * Mirrors PostPlatformMetaRules::GOOGLE_BUSINESS_EVENT_TOPIC_TYPES.
 */
export const GOOGLE_BUSINESS_EVENT_TOPIC_TYPES: string[] = ['EVENT', 'OFFER'];

export interface GoogleBusinessCtaOption {
    value: string;
    labelKey: string;
}

/**
 * Call-to-action button types, in the order the editor lists them. `NONE` is the
 * "no button" choice and has no preview label.
 */
export const GOOGLE_BUSINESS_CTA_OPTIONS: readonly GoogleBusinessCtaOption[] = [
    { value: 'NONE', labelKey: 'posts.form.google_business.cta_none' },
    { value: 'BOOK', labelKey: 'posts.form.google_business.cta.book' },
    { value: 'ORDER', labelKey: 'posts.form.google_business.cta.order' },
    { value: 'SHOP', labelKey: 'posts.form.google_business.cta.shop' },
    { value: 'LEARN_MORE', labelKey: 'posts.form.google_business.cta.learn_more' },
    { value: 'SIGN_UP', labelKey: 'posts.form.google_business.cta.sign_up' },
    { value: 'GET_OFFER', labelKey: 'posts.form.google_business.cta.get_offer' },
    { value: 'CALL', labelKey: 'posts.form.google_business.cta.call' },
];

/** The i18n key for a CTA action type's button label, or null when it has none. */
export const googleBusinessCtaLabelKey = (actionType?: string | null): string | null => {
    if (!actionType || actionType === 'NONE') return null;

    return GOOGLE_BUSINESS_CTA_OPTIONS.find((option) => option.value === actionType)?.labelKey ?? null;
};
