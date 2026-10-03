<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';

import InputError from '@/components/InputError.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Avatar } from '@/components/ui/avatar';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePageErrors } from '@/composables/usePageErrors';
import { ContentType } from '@/types/content-type';
import {
    isTikTokPrivacyLevel,
    tiktokPrivacyLabelKey,
    TikTokPrivacyLevel,
    type TikTokPrivacyLevelValue,
} from '@/types/tiktok-privacy';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

interface CreatorInfo {
    creator_nickname: string | null;
    creator_username: string | null;
    creator_avatar_url: string | null;
    privacy_level_options: TikTokPrivacyLevelValue[];
    comment_disabled: boolean;
    duet_disabled: boolean;
    stitch_disabled: boolean;
    max_video_post_duration_sec: number | null;
}

interface Props {
    socialAccount: SocialAccount | null;
    publishConfig: Record<string, any> | null;
    creatorInfo?: CreatorInfo | null;
    videoDurationSec?: number | null;
    contentType: string;
    meta: Record<string, any>;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    creatorInfo: null,
    videoDurationSec: null,
    disabled: false,
});

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();

const errors = usePageErrors();
const privacyError = computed<string | undefined>(() => {
    if (isTikTokPrivacyLevel(props.meta?.privacy_level)) {
        return undefined;
    }

    return Object.entries(errors.value).find(([key]) =>
        key.endsWith('.meta.privacy_level'),
    )?.[1];
});

const updateMeta = (patch: Record<string, any>) => {
    emit('update:meta', { ...props.meta, ...patch });
};

const privacyLevel = computed({
    get: () => props.meta?.privacy_level ?? '',
    set: (value: string) => updateMeta({ privacy_level: value }),
});

const autoAddMusic = computed({
    get: () => ((props.meta?.auto_add_music ?? false) ? 'yes' : 'no'),
    set: (value: string) => updateMeta({ auto_add_music: value === 'yes' }),
});

const allowComments = computed({
    get: () => props.meta?.allow_comments ?? false,
    set: (value: boolean) => updateMeta({ allow_comments: value }),
});

const allowDuet = computed({
    get: () => props.meta?.allow_duet ?? false,
    set: (value: boolean) => updateMeta({ allow_duet: value }),
});

const allowStitch = computed({
    get: () => props.meta?.allow_stitch ?? false,
    set: (value: boolean) => updateMeta({ allow_stitch: value }),
});

const discloseOpen = computed({
    get: () => props.meta?.disclose ?? false,
    set: (value: boolean) => {
        if (value) {
            updateMeta({ disclose: true });
            return;
        }
        // turning disclosure off clears its sub-toggles
        updateMeta({
            disclose: false,
            brand_organic_toggle: false,
            brand_content_toggle: false,
        });
    },
});

const brandOrganicToggle = computed({
    get: () => props.meta?.brand_organic_toggle ?? false,
    set: (value: boolean) => updateMeta({ brand_organic_toggle: value }),
});

const brandContentToggle = computed({
    get: () => props.meta?.brand_content_toggle ?? false,
    set: (value: boolean) => updateMeta({ brand_content_toggle: value }),
});

// Prefer the creator_info API response; fall back to the static list from the Platform enum.
// Every option is rendered. SelfOnly is shown but disabled when Branded Content is
// checked (TikTok UX Guideline Point 3b — must show interaction, not hide it).
const privacyOptions = computed<TikTokPrivacyLevelValue[]>(() => {
    const fromApi = (props.creatorInfo?.privacy_level_options ?? []).filter(
        isTikTokPrivacyLevel,
    );
    const fallback = (props.publishConfig?.privacyLevelOptions ?? []).filter(
        isTikTokPrivacyLevel,
    );

    return fromApi.length > 0 ? fromApi : fallback;
});

const isSelfOnlyDisabled = (option: TikTokPrivacyLevelValue): boolean =>
    option === TikTokPrivacyLevel.SelfOnly && brandContentToggle.value;

const commentDisabled = computed(() =>
    Boolean(props.creatorInfo?.comment_disabled),
);
const duetDisabled = computed(() => Boolean(props.creatorInfo?.duet_disabled));
const stitchDisabled = computed(() =>
    Boolean(props.creatorInfo?.stitch_disabled),
);

// Derive from the user's explicit variant choice (contentType), not from attached
// media. The variant selector is the single source of truth — user picks it, the
// gating responds immediately, and validation enforces media↔content_type matching.
const isPhotoPost = computed(
    () => props.contentType === ContentType.TikTokPhoto,
);
const isVideoPost = computed(
    () => props.contentType === ContentType.TikTokVideo,
);

// Max video duration check (when creator_info is available and we have duration).
const maxDurationSec = computed(
    () => props.creatorInfo?.max_video_post_duration_sec ?? null,
);
const exceedsMaxDuration = computed(() => {
    if (maxDurationSec.value === null || props.videoDurationSec === null)
        return false;
    return props.videoDurationSec > maxDurationSec.value;
});

const hasAnyBrandToggle = computed(
    () => brandOrganicToggle.value || brandContentToggle.value,
);

// Label required by TikTok: "Paid partnership" when branded content (with or without organic),
// otherwise "Promotional content".
const promotionalTitleKey = computed(() =>
    brandContentToggle.value
        ? 'posts.form.tiktok.promotional_paid_title'
        : 'posts.form.tiktok.promotional_organic_title',
);

// If user flips branded content ON while privacy is SELF_ONLY, clear it so they must re-pick.
// Surface a toast so the user understands why the field reset (TikTok UX Guideline Point 3b
// requires informing the user when an auto-switch happens).
watch(brandContentToggle, (value) => {
    if (value && privacyLevel.value === TikTokPrivacyLevel.SelfOnly) {
        privacyLevel.value = '';
        toast.warning(trans('posts.form.tiktok.branded_cleared_private'));
    }
});

// When creator_info reveals that an interaction is disabled for this account, force the meta
// flag off so we never submit a disallowed value.
watch(
    () =>
        [
            commentDisabled.value,
            duetDisabled.value,
            stitchDisabled.value,
        ] as const,
    ([comment, duet, stitch]) => {
        const patch: Record<string, any> = {};
        if (comment && allowComments.value) patch.allow_comments = false;
        if (duet && allowDuet.value) patch.allow_duet = false;
        if (stitch && allowStitch.value) patch.allow_stitch = false;
        if (Object.keys(patch).length > 0) updateMeta(patch);
    },
);
</script>

<template>
    <SettingsSection>
        <SettingsRow
            v-if="socialAccount"
            :label="$t('posts.form.tiktok.posting_to')"
        >
            <div class="flex min-h-8 min-w-0 items-center gap-2 text-sm">
                <Avatar
                    :src="socialAccount.avatar_url"
                    :name="socialAccount.display_label"
                    class="size-6 shrink-0 rounded-full border border-border"
                />
                <span class="truncate font-medium text-foreground">{{
                    socialAccount.display_label
                }}</span>
                <span
                    v-if="socialAccount.username"
                    class="truncate text-muted-foreground"
                    >@{{ socialAccount.username }}</span
                >
            </div>
        </SettingsRow>

        <SettingsRow
            :label="$t('posts.form.tiktok.privacy_level')"
            :label-for="`tiktok-privacy-${socialAccount?.id ?? 'account'}`"
            align-top
        >
            <Select v-model="privacyLevel" :disabled="props.disabled">
                <SelectTrigger
                    :id="`tiktok-privacy-${socialAccount?.id ?? 'account'}`"
                    class="w-full"
                    data-testid="tiktok-privacy-level"
                    :aria-invalid="privacyError ? true : undefined"
                >
                    <SelectValue v-if="isTikTokPrivacyLevel(privacyLevel)">{{
                        $t(tiktokPrivacyLabelKey[privacyLevel])
                    }}</SelectValue>
                    <SelectValue
                        v-else
                        :placeholder="
                            $t('posts.form.tiktok.privacy_placeholder')
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in privacyOptions"
                        :key="option"
                        :value="option"
                        :disabled="isSelfOnlyDisabled(option)"
                        :title="
                            isSelfOnlyDisabled(option)
                                ? $t(
                                      'posts.form.tiktok.privacy.private_disabled_branded',
                                  )
                                : undefined
                        "
                    >
                        {{ $t(tiktokPrivacyLabelKey[option]) }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="privacyError" />
            <p class="text-xs text-muted-foreground">
                {{ $t('posts.form.tiktok.privacy_hint') }}
            </p>
            <p
                v-if="brandContentToggle"
                class="flex items-start gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
            >
                <IconAlertTriangle
                    class="mt-0.5 size-4 shrink-0 text-warning"
                />
                {{ $t('posts.form.tiktok.privacy.private_disabled_branded') }}
            </p>
        </SettingsRow>

        <p
            v-if="isVideoPost && exceedsMaxDuration"
            role="status"
            class="flex items-start gap-2 rounded-md bg-destructive/10 px-3 py-1.5 text-sm text-destructive-text"
        >
            <IconAlertTriangle class="mt-0.5 size-4 shrink-0" />
            {{
                $t('posts.form.tiktok.max_duration_exceeded', {
                    duration: String(videoDurationSec ?? 0),
                    max: String(maxDurationSec ?? 0),
                })
            }}
        </p>

        <SettingsRow
            v-if="isPhotoPost"
            :label="$t('posts.form.tiktok.auto_add_music')"
            :label-for="`tiktok-music-${socialAccount?.id ?? 'account'}`"
            align-top
        >
            <Select v-model="autoAddMusic" :disabled="props.disabled">
                <SelectTrigger
                    :id="`tiktok-music-${socialAccount?.id ?? 'account'}`"
                    class="w-full"
                >
                    <SelectValue>{{
                        autoAddMusic === 'yes'
                            ? $t('posts.form.tiktok.yes')
                            : $t('posts.form.tiktok.no')
                    }}</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="yes">{{
                        $t('posts.form.tiktok.yes')
                    }}</SelectItem>
                    <SelectItem value="no">{{
                        $t('posts.form.tiktok.no')
                    }}</SelectItem>
                </SelectContent>
            </Select>
            <p class="text-xs text-muted-foreground">
                {{ $t('posts.form.tiktok.auto_add_music_hint') }}
            </p>
        </SettingsRow>

        <SettingsRow :label="$t('posts.form.tiktok.allow_users')">
            <div class="flex min-h-8 flex-wrap items-center gap-x-6 gap-y-2">
                <label
                    class="flex items-center gap-2 text-sm"
                    :class="{ 'opacity-50': commentDisabled }"
                    :title="
                        commentDisabled
                            ? $t(
                                  'posts.form.tiktok.interaction_disabled_by_creator',
                              )
                            : ''
                    "
                >
                    <Checkbox
                        v-model="allowComments"
                        :disabled="props.disabled || commentDisabled"
                    />
                    {{ $t('posts.form.tiktok.comments') }}
                </label>
                <template v-if="!isPhotoPost">
                    <label
                        class="flex items-center gap-2 text-sm"
                        :class="{ 'opacity-50': duetDisabled }"
                        :title="
                            duetDisabled
                                ? $t(
                                      'posts.form.tiktok.interaction_disabled_by_creator',
                                  )
                                : ''
                        "
                    >
                        <Checkbox
                            v-model="allowDuet"
                            :disabled="props.disabled || duetDisabled"
                        />
                        {{ $t('posts.form.tiktok.duet') }}
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm"
                        :class="{ 'opacity-50': stitchDisabled }"
                        :title="
                            stitchDisabled
                                ? $t(
                                      'posts.form.tiktok.interaction_disabled_by_creator',
                                  )
                                : ''
                        "
                    >
                        <Checkbox
                            v-model="allowStitch"
                            :disabled="props.disabled || stitchDisabled"
                        />
                        {{ $t('posts.form.tiktok.stitch') }}
                    </label>
                </template>
            </div>
        </SettingsRow>

        <SettingsRow>
            <div class="space-y-3">
                <div class="space-y-1">
                    <label class="flex min-h-8 items-center gap-2 text-sm">
                        <Checkbox
                            v-model="discloseOpen"
                            :disabled="props.disabled"
                        />
                        {{ $t('posts.form.tiktok.disclose') }}
                    </label>
                    <p class="ml-6 text-xs text-muted-foreground">
                        {{ $t('posts.form.tiktok.disclose_hint') }}
                    </p>
                </div>

                <div
                    v-if="hasAnyBrandToggle"
                    class="ml-6 flex items-start gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
                >
                    <IconAlertTriangle
                        class="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    <div>
                        <p class="font-medium">{{ $t(promotionalTitleKey) }}</p>
                        <p class="text-xs">
                            {{
                                $t('posts.form.tiktok.promotional_description')
                            }}
                        </p>
                    </div>
                </div>

                <p
                    v-else-if="discloseOpen"
                    class="ml-6 text-xs font-medium text-foreground"
                >
                    {{ $t('posts.form.tiktok.compliance_incomplete') }}
                </p>

                <div v-if="discloseOpen" class="ml-6 space-y-3">
                    <div class="space-y-1">
                        <label class="flex items-center gap-2 text-sm">
                            <Checkbox
                                v-model="brandOrganicToggle"
                                :disabled="props.disabled"
                            />
                            {{ $t('posts.form.tiktok.brand_organic') }}
                        </label>
                        <p class="ml-6 text-xs text-muted-foreground">
                            {{ $t('posts.form.tiktok.brand_organic_hint') }}
                        </p>
                    </div>
                    <div class="space-y-1">
                        <label class="flex items-center gap-2 text-sm">
                            <Checkbox
                                v-model="brandContentToggle"
                                :disabled="props.disabled"
                            />
                            {{ $t('posts.form.tiktok.brand_content') }}
                        </label>
                        <p class="ml-6 text-xs text-muted-foreground">
                            {{ $t('posts.form.tiktok.brand_content_hint') }}
                        </p>
                    </div>
                </div>
            </div>
        </SettingsRow>

        <div class="space-y-1 text-xs text-muted-foreground">
            <p>
                {{ $t('posts.form.tiktok.compliance.agree') }}
                <template v-if="brandContentToggle">
                    <a
                        :href="publishConfig?.brandedContentPolicyUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-medium text-primary-text underline-offset-2 hover:text-primary-text-hover hover:underline"
                    >
                        {{ $t('posts.form.tiktok.compliance.branded_policy') }}
                    </a>
                    {{ ' ' + $t('posts.form.tiktok.compliance.and') + ' ' }}
                </template>
                <a
                    :href="publishConfig?.musicUsageConfirmationUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-primary-text underline-offset-2 hover:text-primary-text-hover hover:underline"
                >
                    {{ $t('posts.form.tiktok.compliance.music_usage') }}
                </a>
            </p>
            <p>{{ $t('posts.form.tiktok.processing_hint') }}</p>
        </div>
    </SettingsSection>
</template>
