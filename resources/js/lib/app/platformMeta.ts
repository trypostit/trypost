import { trans } from 'laravel-vue-i18n';

import {
    GOOGLE_BUSINESS_EVENT_TITLE_MAX,
    GOOGLE_BUSINESS_EVENT_TOPIC_TYPES,
    GoogleBusinessCtaAction,
    GoogleBusinessTopicType,
    googleBusinessAllowsCallToAction,
    googleBusinessEventEndsBeforeStart,
    resolveGoogleBusinessCtaAction,
    resolveGoogleBusinessTopicType,
} from '@/lib/app/googleBusiness';
import { getYouTubeDescriptionIssue } from '@/lib/app/youtubeDescription';
import { Platform } from '@/types/app/platform';
import { isTikTokPrivacyLevel, TikTokPrivacyLevel } from '@/types/app/tiktok-privacy';

type MetaRule = (
    meta: Record<string, any>,
    content: string | null,
) => { valid: boolean; tooltipKey: string | null };

const PLATFORM_META_RULES: Record<string, MetaRule> = {
    [Platform.YouTube]: (meta, content) => {
        const titleMissing = content !== null
            && !String(meta.title ?? '').trim()
            && !content.split('\n').some((line) => line.replace(/[<>]/g, '').trim() !== '');
        const tooltipKey = titleMissing
            ? 'posts.form.youtube.title_required'
            : getYouTubeDescriptionIssue(meta.description);
        return { valid: tooltipKey === null, tooltipKey };
    },
    [Platform.TikTok]: (meta) => {
        const disclosureIncomplete = Boolean(meta.disclose)
            && !meta.brand_organic_toggle
            && !meta.brand_content_toggle;
        const privacyLevelMissing = !isTikTokPrivacyLevel(meta.privacy_level);
        const brandedPrivate = meta.privacy_level === TikTokPrivacyLevel.SelfOnly
            && Boolean(meta.brand_content_toggle);
        let tooltipKey: string | null = null;
        if (disclosureIncomplete) {
            tooltipKey = 'posts.form.tiktok.compliance_incomplete';
        } else if (brandedPrivate) {
            tooltipKey = 'posts.form.tiktok.privacy.private_disabled_branded';
        } else if (privacyLevelMissing) {
            tooltipKey = 'posts.form.tiktok.privacy_required';
        }
        return {
            valid: !disclosureIncomplete && !privacyLevelMissing && !brandedPrivate,
            tooltipKey,
        };
    },
    [Platform.Pinterest]: (meta) => ({
        valid: Boolean(meta.board_id),
        tooltipKey: meta.board_id ? null : 'posts.form.pinterest.board_required',
    }),
    [Platform.Discord]: (meta) => ({
        valid: Boolean(meta.channel_id),
        tooltipKey: meta.channel_id ? null : 'posts.form.discord.channel_required',
    }),
    [Platform.GoogleBusiness]: (meta) => {
        const topicType = resolveGoogleBusinessTopicType(meta.topic_type);
        const needsEvent = GOOGLE_BUSINESS_EVENT_TOPIC_TYPES.includes(topicType);
        const ctaActionType = resolveGoogleBusinessCtaAction(meta.call_to_action?.action_type);
        const ctaNeedsUrl = googleBusinessAllowsCallToAction(topicType)
            && ctaActionType !== GoogleBusinessCtaAction.None
            && ctaActionType !== GoogleBusinessCtaAction.Call;
        let tooltipKey: string | null = null;
        if (needsEvent && !meta.event?.title?.trim()) {
            tooltipKey = topicType === GoogleBusinessTopicType.Offer
                ? 'posts.form.google_business.offer_title_required'
                : 'posts.form.google_business.event_title_required';
        } else if (needsEvent && (meta.event?.title?.length ?? 0) > GOOGLE_BUSINESS_EVENT_TITLE_MAX) {
            tooltipKey = 'posts.form.google_business.title_max';
        } else if (needsEvent && !meta.event?.start_date) {
            tooltipKey = 'posts.form.google_business.event_start_date_required';
        } else if (needsEvent && !meta.event?.end_date) {
            tooltipKey = 'posts.form.google_business.event_end_date_required';
        } else if (needsEvent && googleBusinessEventEndsBeforeStart(meta.event)) {
            const sameDayTimes = meta.event?.start_date === meta.event?.end_date
                && meta.event?.start_time
                && meta.event?.end_time;
            tooltipKey = sameDayTimes
                ? 'posts.form.google_business.event_end_time_before_start'
                : 'posts.form.google_business.event_end_date_before_start';
        } else if (ctaNeedsUrl && !meta.call_to_action?.url) {
            tooltipKey = 'posts.form.google_business.cta_url_required';
        }
        return {
            valid: tooltipKey === null,
            tooltipKey,
        };
    },
};

/**
 * Evaluates a platform's publish-time meta requirements. Single source of truth
 * for the post editor's compliance gate. `content` is the post's plain text;
 * without it, requirements the text can satisfy are not checked.
 */
export const evaluatePlatformMeta = (
    platform: string,
    meta: Record<string, any>,
    content: string | null = null,
): { valid: boolean; tooltipKey: string | null } => {
    const rule = PLATFORM_META_RULES[platform];
    if (!rule) return { valid: true, tooltipKey: null };
    return rule(meta ?? {}, content);
};

/**
 * Translated meta issue for a platform (or null when compliant) — the same
 * requirement the post editor enforces before scheduling.
 */
export const getPlatformMetaIssue = (platform: string, meta: Record<string, any>): string | null => {
    const result = evaluatePlatformMeta(platform, meta);
    if (result.valid) return null;
    return result.tooltipKey ? trans(result.tooltipKey) : trans('posts.edit.compliance_incomplete');
};
