/**
 * Google Business Profile Local Post helpers shared by the editor settings
 * panel, the preview, and the publish compliance gate.
 */

import {
    GOOGLE_BUSINESS_CTA_ACTION_VALUES,
    GOOGLE_BUSINESS_EVENT_TOPIC_TYPES,
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

/**
 * Combine stored event date + time for the DatePicker (`YYYY-MM-DD` or
 * `YYYY-MM-DDTHH:mm:00`). The editor DatePicker always writes a time (default
 * 09:00). API/MCP may omit it — Google then treats the schedule as the start
 * of the day at the location.
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
