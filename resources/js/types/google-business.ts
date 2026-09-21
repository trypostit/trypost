/**
 * Google Business Profile Local Post topic types and call-to-action buttons.
 * Mirrors the v4 enums we persist on `platforms.*.meta` (STANDARD/EVENT/OFFER,
 * NONE/BOOK/…). Same shape as `tiktok-privacy.ts`.
 */

export const GoogleBusinessTopicType = {
    Standard: 'STANDARD',
    Offer: 'OFFER',
    Event: 'EVENT',
} as const;

export type GoogleBusinessTopicTypeValue =
    (typeof GoogleBusinessTopicType)[keyof typeof GoogleBusinessTopicType];

export const GOOGLE_BUSINESS_TOPIC_TYPE_VALUES: GoogleBusinessTopicTypeValue[] =
    Object.values(GoogleBusinessTopicType);

export const isGoogleBusinessTopicType = (value: unknown): value is GoogleBusinessTopicTypeValue =>
    typeof value === 'string' && (GOOGLE_BUSINESS_TOPIC_TYPE_VALUES as string[]).includes(value);

export const resolveGoogleBusinessTopicType = (value: unknown): GoogleBusinessTopicTypeValue =>
    isGoogleBusinessTopicType(value) ? value : GoogleBusinessTopicType.Standard;

/** Topic types whose Local Post requires an `event` object (title + date range). */
export const GOOGLE_BUSINESS_EVENT_TOPIC_TYPES: GoogleBusinessTopicTypeValue[] = [
    GoogleBusinessTopicType.Event,
    GoogleBusinessTopicType.Offer,
];

export const googleBusinessTopicTypeLabelKey: Record<GoogleBusinessTopicTypeValue, string> = {
    [GoogleBusinessTopicType.Standard]: 'posts.form.google_business.topic_type.standard',
    [GoogleBusinessTopicType.Offer]: 'posts.form.google_business.topic_type.offer',
    [GoogleBusinessTopicType.Event]: 'posts.form.google_business.topic_type.event',
};

export const GoogleBusinessCtaAction = {
    None: 'NONE',
    Book: 'BOOK',
    Order: 'ORDER',
    Shop: 'SHOP',
    LearnMore: 'LEARN_MORE',
    SignUp: 'SIGN_UP',
    Call: 'CALL',
} as const;

export type GoogleBusinessCtaActionValue =
    (typeof GoogleBusinessCtaAction)[keyof typeof GoogleBusinessCtaAction];

export const GOOGLE_BUSINESS_CTA_ACTION_VALUES: GoogleBusinessCtaActionValue[] =
    Object.values(GoogleBusinessCtaAction);

export const isGoogleBusinessCtaAction = (value: unknown): value is GoogleBusinessCtaActionValue =>
    typeof value === 'string' && (GOOGLE_BUSINESS_CTA_ACTION_VALUES as string[]).includes(value);

export const resolveGoogleBusinessCtaAction = (value: unknown): GoogleBusinessCtaActionValue =>
    isGoogleBusinessCtaAction(value) ? value : GoogleBusinessCtaAction.None;

export const googleBusinessCtaActionLabelKey: Record<GoogleBusinessCtaActionValue, string> = {
    [GoogleBusinessCtaAction.None]: 'posts.form.google_business.cta_none',
    [GoogleBusinessCtaAction.Book]: 'posts.form.google_business.cta.book',
    [GoogleBusinessCtaAction.Order]: 'posts.form.google_business.cta.order',
    [GoogleBusinessCtaAction.Shop]: 'posts.form.google_business.cta.shop',
    [GoogleBusinessCtaAction.LearnMore]: 'posts.form.google_business.cta.learn_more',
    [GoogleBusinessCtaAction.SignUp]: 'posts.form.google_business.cta.sign_up',
    [GoogleBusinessCtaAction.Call]: 'posts.form.google_business.cta.call',
};
