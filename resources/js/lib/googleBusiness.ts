/**
 * Google Business Profile Local Post constants shared by the editor settings
 * panel, the preview, and the publish compliance gate.
 */

/**
 * Topic types whose Local Post requires an `event` object (title + date range).
 * Mirrors PostPlatformMetaRules::GOOGLE_BUSINESS_EVENT_TOPIC_TYPES.
 */
export const GOOGLE_BUSINESS_EVENT_TOPIC_TYPES: string[] = ['EVENT', 'OFFER'];

export interface GoogleBusinessTopicTypeOption {
    value: string;
    labelKey: string;
}

/**
 * Local Post topic types, in the order the editor lists them: What's New,
 * Offer, Event. STANDARD is Google's API name for What's New.
 */
export const GOOGLE_BUSINESS_TOPIC_TYPES: readonly GoogleBusinessTopicTypeOption[] = [
    { value: 'STANDARD', labelKey: 'posts.form.google_business.topic_type.standard' },
    { value: 'OFFER', labelKey: 'posts.form.google_business.topic_type.offer' },
    { value: 'EVENT', labelKey: 'posts.form.google_business.topic_type.event' },
];

/** Google ignores `callToAction` on OFFER posts. */
export const googleBusinessAllowsCallToAction = (topicType?: string | null): boolean =>
    (topicType ?? 'STANDARD') !== 'OFFER';

export interface GoogleBusinessCtaOption {
    value: string;
    labelKey: string;
}

/**
 * Call-to-action button types, in the order the editor lists them. `NONE` is the
 * "None" choice and has no preview label. `GET_OFFER` is omitted: Google
 * deprecated it and ignores `callToAction` entirely on OFFER posts.
 */
export const GOOGLE_BUSINESS_CTA_OPTIONS: readonly GoogleBusinessCtaOption[] = [
    { value: 'NONE', labelKey: 'posts.form.google_business.cta_none' },
    { value: 'BOOK', labelKey: 'posts.form.google_business.cta.book' },
    { value: 'ORDER', labelKey: 'posts.form.google_business.cta.order' },
    { value: 'SHOP', labelKey: 'posts.form.google_business.cta.shop' },
    { value: 'LEARN_MORE', labelKey: 'posts.form.google_business.cta.learn_more' },
    { value: 'SIGN_UP', labelKey: 'posts.form.google_business.cta.sign_up' },
    { value: 'CALL', labelKey: 'posts.form.google_business.cta.call' },
];

/**
 * Combine stored event date + time for the DatePicker (`YYYY-MM-DD` or
 * `YYYY-MM-DDTHH:mm:00`). Times are optional: Google treats a date-only
 * schedule as the full day.
 */
export const googleBusinessEventDateTimeValue = (date?: string | null, time?: string | null): string => {
    if (!date) {
        return '';
    }

    return time ? `${date}T${time}:00` : date;
};

/**
 * Split a DatePicker value back into the `event.start_date` / `event.start_time`
 * (or end) meta fields the API, MCP, and publisher persist.
 */
export const googleBusinessEventDateTimeParts = (value: string | null): { date: string | null; time: string | null } => {
    if (!value?.trim()) {
        return { date: null, time: null };
    }

    const match = /^(?<date>\d{4}-\d{2}-\d{2})(?:T(?<time>\d{2}:\d{2}))?/.exec(value);

    return {
        date: match?.groups?.date ?? null,
        time: match?.groups?.time ?? null,
    };
};

/**
 * Whether the event/offer schedule ends before it starts. Same-day times
 * count — mirrors PostPlatformMetaRules::googleBusinessEventEndsBeforeStart().
 */
export const googleBusinessEventEndsBeforeStart = (event?: {
    start_date?: string | null;
    end_date?: string | null;
    start_time?: string | null;
    end_time?: string | null;
} | null): boolean => {
    const startDate = event?.start_date;
    const endDate = event?.end_date;

    if (!startDate || !endDate) {
        return false;
    }

    if (endDate < startDate) {
        return true;
    }

    if (endDate !== startDate) {
        return false;
    }

    const startTime = event.start_time;
    const endTime = event.end_time;

    return Boolean(startTime && endTime && endTime < startTime);
};

/** The i18n key for a CTA action type's button label, or null when it has none. */
export const googleBusinessCtaLabelKey = (actionType?: string | null): string | null => {
    if (!actionType || actionType === 'NONE') return null;

    if (actionType === 'GET_OFFER') {
        return 'posts.form.google_business.cta.get_offer';
    }

    return GOOGLE_BUSINESS_CTA_OPTIONS.find((option) => option.value === actionType)?.labelKey ?? null;
};
