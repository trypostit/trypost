/**
 * Google Business Profile Local Post helpers shared by the editor settings
 * panel, the preview, and the publish compliance gate.
 */

import date from '@/date';
import dayjs from '@/dayjs';
import {
    GOOGLE_BUSINESS_CTA_ACTION_VALUES,
    GoogleBusinessCtaAction,
    GoogleBusinessTopicType,
    googleBusinessCtaActionLabelKey,
    googleBusinessTopicTypeLabelKey,
    resolveGoogleBusinessCtaAction,
    resolveGoogleBusinessTopicType,
    type GoogleBusinessCtaActionValue,
    type GoogleBusinessTopicTypeValue,
} from '@/types/google-business';

export {
    GOOGLE_BUSINESS_CTA_ACTION_VALUES,
    GOOGLE_BUSINESS_EVENT_TITLE_MAX,
    GOOGLE_BUSINESS_EVENT_TOPIC_TYPES,
    GoogleBusinessCtaAction,
    GoogleBusinessTopicType,
    googleBusinessCtaActionLabelKey,
    googleBusinessTopicTypeLabelKey,
    isGoogleBusinessCtaAction,
    isGoogleBusinessTopicType,
    resolveGoogleBusinessCtaAction,
    resolveGoogleBusinessTopicType,
    type GoogleBusinessCtaActionValue,
    type GoogleBusinessTopicTypeValue,
} from '@/types/google-business';

export interface GoogleBusinessTopicTypeOption {
    value: GoogleBusinessTopicTypeValue;
    labelKey: string;
}

/**
 * Local Post topic types, in the order the editor lists them: What's New,
 * Offer, Event. STANDARD is Google's API name for What's New.
 */
export const GOOGLE_BUSINESS_TOPIC_TYPES: readonly GoogleBusinessTopicTypeOption[] = [
    { value: GoogleBusinessTopicType.Standard, labelKey: googleBusinessTopicTypeLabelKey[GoogleBusinessTopicType.Standard] },
    { value: GoogleBusinessTopicType.Offer, labelKey: googleBusinessTopicTypeLabelKey[GoogleBusinessTopicType.Offer] },
    { value: GoogleBusinessTopicType.Event, labelKey: googleBusinessTopicTypeLabelKey[GoogleBusinessTopicType.Event] },
];

/** Event and Offer start today and run a week in the user's time zone, like the reference. */
const seededSchedule = (event: Record<string, any> | null | undefined): Record<string, any> => {
    const today = dayjs().tz(date.getUserTimezone());

    return {
        ...event,
        start_date: event?.start_date ?? today.format('YYYY-MM-DD'),
        end_date: event?.end_date ?? today.add(7, 'day').format('YYYY-MM-DD'),
    };
};

/** Meta after switching the Local Post type. An Offer has no times or button. */
export const googleBusinessTopicMeta = (
    meta: Record<string, any>,
    topicType: GoogleBusinessTopicTypeValue,
): Record<string, any> => {
    if (topicType === GoogleBusinessTopicType.Standard) {
        return { ...meta, topic_type: topicType, event: null, offer: null };
    }

    if (topicType === GoogleBusinessTopicType.Event) {
        return { ...meta, topic_type: topicType, event: seededSchedule(meta.event), offer: null };
    }

    return {
        ...meta,
        topic_type: topicType,
        call_to_action: null,
        event: { ...seededSchedule(meta.event), start_time: null, end_time: null },
        offer: {
            ...meta.offer,
            redeem_online_url:
                meta.offer?.redeem_online_url ?? meta.call_to_action?.url ?? null,
        },
    };
};

/** Google ignores `callToAction` on OFFER posts. */
export const googleBusinessAllowsCallToAction = (topicType?: string | null): boolean =>
    resolveGoogleBusinessTopicType(topicType) !== GoogleBusinessTopicType.Offer;

export interface GoogleBusinessCtaOption {
    value: GoogleBusinessCtaActionValue;
    labelKey: string;
}

/**
 * Call-to-action button types, in the order the editor lists them. `NONE` is the
 * "None" choice and has no preview label.
 */
export const GOOGLE_BUSINESS_CTA_OPTIONS: readonly GoogleBusinessCtaOption[] =
    GOOGLE_BUSINESS_CTA_ACTION_VALUES.map((value) => ({
        value,
        labelKey: googleBusinessCtaActionLabelKey[value],
    }));

export interface GoogleBusinessEventSchedule {
    start_date?: string | null;
    end_date?: string | null;
    start_time?: string | null;
    end_time?: string | null;
}

/**
 * Whether the event/offer schedule ends before it starts. Same-day times
 * count — mirrors PostPlatformMetaRules::googleBusinessEventEndsBeforeStart().
 */
export const googleBusinessEventEndsBeforeStart = (event?: GoogleBusinessEventSchedule | null): boolean => {
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
    if (!actionType || actionType === GoogleBusinessCtaAction.None) {
        return null;
    }

    const resolved = resolveGoogleBusinessCtaAction(actionType);

    if (resolved === GoogleBusinessCtaAction.None) {
        return null;
    }

    return googleBusinessCtaActionLabelKey[resolved];
};
