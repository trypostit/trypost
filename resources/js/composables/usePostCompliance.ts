import { trans } from 'laravel-vue-i18n';
import { computed, type ComputedRef, type Ref } from 'vue';


import { getMediaItemIssue, getMediaValidationWarning } from '@/composables/useMedia';
import { getMediaRulesForContentType } from '@/composables/useMediaRules';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useXLinkDefuser } from '@/composables/useXLinkDefuser';
import { ContentType } from '@/types/content-type';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';

export interface CompliancePostPlatform {
    id: string;
    platform: string;
    platform_name: string | null;
    social_account_id: string | null;
    content_type: string | null;
}

export interface CompliancePost {
    post_platforms: CompliancePostPlatform[];
}

export const PLATFORM_VARIANTS: Record<string, string[]> = {
    [Platform.Facebook]: [ContentType.FacebookPost, ContentType.FacebookReel, ContentType.FacebookStory],
    [Platform.Instagram]: [ContentType.InstagramFeed, ContentType.InstagramReel, ContentType.InstagramStory],
    [Platform.InstagramFacebook]: [ContentType.InstagramFeed, ContentType.InstagramReel, ContentType.InstagramStory],
    [Platform.LinkedIn]: [ContentType.LinkedInPost],
    [Platform.LinkedInPage]: [ContentType.LinkedInPagePost],
    [Platform.TikTok]: [ContentType.TikTokVideo, ContentType.TikTokPhoto],
    [Platform.Pinterest]: [ContentType.PinterestPin, ContentType.PinterestVideoPin, ContentType.PinterestCarousel],
};

// Content types whose post needs text to publish — YouTube Shorts derive their
// required title from the post content, so an empty post can't be scheduled.
const CONTENT_TYPES_REQUIRING_TEXT = new Set<string>([ContentType.YouTubeShort]);

type MetaRule = (meta: Record<string, any>) => { valid: boolean; tooltipKey: string | null };

// Platforms whose `meta` blob has publish-time requirements. `valid` gates
// scheduling; `tooltipKey` (when set) surfaces a platform-specific message
// — null means "blocks the publish but no dedicated message, fall through
// to the generic incomplete tooltip".
const PLATFORM_META_RULES: Record<string, MetaRule> = {
    [Platform.TikTok]: (meta) => {
        const disclosureIncomplete = Boolean(meta.disclose)
            && !meta.brand_organic_toggle
            && !meta.brand_content_toggle;
        const privacyLevelMissing = !meta.privacy_level;
        let tooltipKey: string | null = null;
        if (disclosureIncomplete) {
            tooltipKey = 'posts.form.tiktok.compliance_incomplete';
        } else if (privacyLevelMissing) {
            tooltipKey = 'posts.form.tiktok.privacy_required';
        }
        return {
            valid: !disclosureIncomplete && !privacyLevelMissing,
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
};

/**
 * Evaluates a platform's publish-time meta requirements. Single source of truth
 * shared by the post editor's compliance gate and the automation Generate node.
 */
export const evaluatePlatformMeta = (
    platform: string,
    meta: Record<string, any>,
): { valid: boolean; tooltipKey: string | null } => {
    const rule = PLATFORM_META_RULES[platform];
    if (!rule) return { valid: true, tooltipKey: null };
    return rule(meta ?? {});
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

// The editor's compliance copy keyed by the shared media-warning core's key.
// Detection + rule evaluation live once in getMediaValidationWarning; only the
// wording (and a couple of params) differ between the settings dialogs and this
// publish gate.
const COMPLIANCE_KEY_BY_WARNING: Record<string, string> = {
    no_variant: 'no_content_type',
    requires_media: 'requires_media',
    max_files_exceeded: 'too_many_files',
    min_files_required: 'too_few_files',
    no_video_allowed: 'no_videos',
    no_image_allowed: 'no_images',
    no_document_allowed: 'no_documents',
    no_mixed_media: 'no_mixed_media',
    document_not_alone: 'document_not_alone',
    gif_not_allowed: 'no_gifs',
    image_too_large: 'image_too_large',
    video_too_large: 'video_too_large',
    document_too_large: 'document_too_large',
    video_too_long: 'video_too_long',
    aspect_ratio_too_narrow: 'aspect_ratio_invalid',
    aspect_ratio_too_wide: 'aspect_ratio_invalid',
};

export const getMediaIncompatibilityReason = (
    contentType: string,
    mediaItems: MediaItem[],
): string | null => {
    const warning = getMediaValidationWarning(contentType, mediaItems);
    if (!warning) return null;

    const complianceKey = COMPLIANCE_KEY_BY_WARNING[warning.key];
    if (!complianceKey) return trans('posts.edit.compliance_incomplete');

    const params: Record<string, string> = {};
    if (warning.key === 'max_files_exceeded') params.max = warning.params.max;
    if (warning.key === 'min_files_required') params.min = warning.params.min;
    if (warning.key === 'video_too_long') {
        params.seconds = String(getMediaRulesForContentType(contentType).maxVideoDurationSec ?? '');
    }

    return trans(`posts.edit.compliance.${complianceKey}`, params);
};

export const firstCompatibleVariant = (
    platform: string,
    mediaItems: MediaItem[],
): string | null => {
    const variants = PLATFORM_VARIANTS[platform];
    if (!variants) return null;
    return variants.find((ct) => !getMediaIncompatibilityReason(ct, mediaItems)) ?? null;
};

interface UsePostComplianceOptions {
    post: ComputedRef<CompliancePost>;
    content: Ref<string>;
    media: Ref<MediaItem[]>;
    selectedPlatformIds: Ref<string[]>;
    platformContentTypes: Ref<Record<string, string>>;
    platformMeta: Ref<Record<string, Record<string, any>>>;
    platformConfigs: Record<string, { maxContentLength?: number | null }>;
}

export const usePostCompliance = (opts: UsePostComplianceOptions) => {
    const { contentFor } = useXLinkDefuser();

    const { post, content, media, selectedPlatformIds, platformContentTypes, platformMeta, platformConfigs } = opts;

    const selectedPlatforms = computed(() => post.value.post_platforms.filter(
        (pp) => selectedPlatformIds.value.includes(pp.id),
    ));

    const platformLimits = computed(() => {
        const seen = new Set<string>();
        const result: { platform: string; maxLength: number }[] = [];
        for (const pp of selectedPlatforms.value) {
            if (seen.has(pp.platform)) continue;
            const max = pp.social_account_id ? platformConfigs[pp.social_account_id]?.maxContentLength : null;
            if (typeof max === 'number' && max > 0) {
                seen.add(pp.platform);
                result.push({ platform: pp.platform, maxLength: max });
            }
        }
        return result;
    });

    const mediaIssues = computed<Record<string, { platform: string; reason: string }[]>>(() => {
        const result: Record<string, { platform: string; reason: string }[]> = {};
        for (const item of media.value) {
            const issues: { platform: string; reason: string }[] = [];
            const seen = new Set<string>();
            for (const pp of selectedPlatforms.value) {
                if (seen.has(pp.platform)) continue;
                const contentType = platformContentTypes.value[pp.id] ?? pp.content_type ?? '';
                const reason = getMediaItemIssue(item, contentType);
                if (reason) {
                    seen.add(pp.platform);
                    issues.push({ platform: pp.platform, reason });
                }
            }
            if (issues.length > 0) result[item.id] = issues;
        }
        return result;
    });

    const platformIssues = computed<Record<string, string>>(() => {
        const issues: Record<string, string> = {};

        for (const pp of post.value.post_platforms) {
            const contentType = platformContentTypes.value[pp.id];
            if (!contentType) {
                issues[pp.id] = trans('posts.edit.compliance.no_content_type');
                continue;
            }

            if (CONTENT_TYPES_REQUIRING_TEXT.has(contentType) && content.value.trim() === '') {
                issues[pp.id] = trans('posts.edit.compliance.requires_text');
                continue;
            }

            const reason = getMediaIncompatibilityReason(contentType, media.value);
            if (!reason) continue;

            const isSelected = selectedPlatformIds.value.includes(pp.id);
            if (!isSelected && firstCompatibleVariant(pp.platform, media.value)) continue;

            issues[pp.id] = reason;
        }

        return issues;
    });

    const platformMetaResults = computed(() => selectedPlatforms.value.map(
        (pp) => evaluatePlatformMeta(pp.platform, platformMeta.value[pp.id] ?? {}),
    ));

    const hasContentOrMedia = computed(
        () => content.value.trim().length > 0 || media.value.length > 0,
    );

    const contentLengthOverflows = computed(() => {
        return platformLimits.value
            .map((p) => ({ p, len: contentFor(content.value, p.platform).length }))
            .filter(({ p, len }) => len > p.maxLength)
            .map(({ p, len }) => ({ platform: p.platform, limit: p.maxLength, over: len - p.maxLength }));
    });

    const canSchedule = computed(() => {
        const mediaValid = selectedPlatformIds.value.every((id) => !platformIssues.value[id]);
        const metaValid = platformMetaResults.value.every((r) => r.valid);
        return mediaValid
            && metaValid
            && hasContentOrMedia.value
            && contentLengthOverflows.value.length === 0;
    });

    const postActionTooltip = computed(() => {
        if (canSchedule.value) return '';

        const mediaReasons = selectedPlatforms.value
            .filter((pp) => platformIssues.value[pp.id])
            .map((pp) => `${pp.platform_name ?? pp.platform}: ${platformIssues.value[pp.id]}`);

        const lengthReasons = contentLengthOverflows.value.map((overflow) => trans('posts.form.content_exceeds_platform', {
            platform: getPlatformLabel(overflow.platform),
            limit: String(overflow.limit),
            over: String(overflow.over),
        }));

        const combined = [...mediaReasons, ...lengthReasons].join('\n');
        if (combined) return combined;

        const metaTooltipKey = platformMetaResults.value.find((r) => r.tooltipKey)?.tooltipKey;
        if (metaTooltipKey) return trans(metaTooltipKey);

        if (!hasContentOrMedia.value) return trans('posts.edit.compliance.requires_content_or_media');

        return trans('posts.edit.compliance_incomplete');
    });

    return {
        platformLimits,
        mediaIssues,
        platformIssues,
        canSchedule,
        postActionTooltip,
    };
};
