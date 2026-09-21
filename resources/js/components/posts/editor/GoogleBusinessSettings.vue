<script setup lang="ts">
import { IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import DatePicker from '@/components/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import { Avatar } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePageErrors } from '@/composables/usePageErrors';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import {
    GOOGLE_BUSINESS_CTA_OPTIONS,
    GOOGLE_BUSINESS_EVENT_TOPIC_TYPES,
    GOOGLE_BUSINESS_TOPIC_TYPES,
    GoogleBusinessCtaAction,
    GoogleBusinessTopicType,
    googleBusinessAllowsCallToAction,
    googleBusinessEventDateTimeParts,
    googleBusinessEventDateTimeValue,
    resolveGoogleBusinessCtaAction,
    resolveGoogleBusinessTopicType,
    type GoogleBusinessCtaActionValue,
    type GoogleBusinessTopicTypeValue,
} from '@/lib/googleBusiness';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

interface Props {
    socialAccount: SocialAccount | null;
    /** This panel's position in the submitted `platforms` array — see findError. */
    platformIndex: number;
    meta: Record<string, any>;
    disabled?: boolean;
    previewOnly?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
    previewOnly: false,
});

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();

const open = ref(false);
const isLocked = computed(() => props.disabled || props.previewOnly);

const updateMeta = (patch: Record<string, any>) => {
    emit('update:meta', { ...props.meta, ...patch });
};

const updateEvent = (patch: Record<string, any>) => {
    updateMeta({ event: { ...props.meta?.event, ...patch } });
};

const topicType = computed<GoogleBusinessTopicTypeValue>({
    get: () => resolveGoogleBusinessTopicType(props.meta?.topic_type),
    set: (value: GoogleBusinessTopicTypeValue) => {
        if (value === GoogleBusinessTopicType.Standard) {
            updateMeta({ topic_type: value, event: null, offer: null });
            return;
        }

        if (value === GoogleBusinessTopicType.Event) {
            updateMeta({ topic_type: value, offer: null });
            return;
        }

        updateMeta({ topic_type: value, call_to_action: null });
    },
});

const ctaActionType = computed<GoogleBusinessCtaActionValue>({
    get: () => resolveGoogleBusinessCtaAction(props.meta?.call_to_action?.action_type),
    set: (value: GoogleBusinessCtaActionValue) => updateMeta({
        call_to_action: { ...props.meta?.call_to_action, action_type: value },
    }),
});

const showCallToAction = computed(() => googleBusinessAllowsCallToAction(topicType.value));

const showCtaUrl = computed(() => showCallToAction.value
    && ctaActionType.value !== GoogleBusinessCtaAction.None
    && ctaActionType.value !== GoogleBusinessCtaAction.Call);

const showEventFields = computed(() => GOOGLE_BUSINESS_EVENT_TOPIC_TYPES.includes(topicType.value));

const ctaUrl = computed<string>({
    get: () => props.meta?.call_to_action?.url || '',
    set: (value: string) => updateMeta({
        call_to_action: { ...props.meta?.call_to_action, url: value.trim() === '' ? null : value },
    }),
});

const eventTitle = computed<string>({
    get: () => props.meta?.event?.title || '',
    set: (value: string) => updateEvent({ title: value.trim() === '' ? null : value }),
});

const eventDateTime = (dateKey: 'start_date' | 'end_date', timeKey: 'start_time' | 'end_time') => computed({
    get: (): string => googleBusinessEventDateTimeValue(props.meta?.event?.[dateKey], props.meta?.event?.[timeKey]),
    set: (value: string | null) => {
        const parts = googleBusinessEventDateTimeParts(value);
        updateEvent({ [dateKey]: parts.date, [timeKey]: parts.time });
    },
});

const eventStart = eventDateTime('start_date', 'start_time');
const eventEnd = eventDateTime('end_date', 'end_time');

const eventTitleLabelKey = computed(() => topicType.value === GoogleBusinessTopicType.Offer
    ? 'posts.form.google_business.offer_title'
    : 'posts.form.google_business.event_title');

const eventTitlePlaceholderKey = computed(() => topicType.value === GoogleBusinessTopicType.Offer
    ? 'posts.form.google_business.offer_title_placeholder'
    : 'posts.form.google_business.event_title_placeholder');

const offerField = (key: 'coupon_code' | 'redeem_online_url' | 'terms_conditions') => computed<string>({
    get: () => props.meta?.offer?.[key] || '',
    set: (value: string) => updateMeta({
        offer: { ...props.meta?.offer, [key]: value.trim() === '' ? null : value },
    }),
});

const offerCouponCode = offerField('coupon_code');
const offerRedeemUrl = offerField('redeem_online_url');
const offerTerms = offerField('terms_conditions');

// Backend validation errors are keyed `platforms.{index}.meta.*`. Matching the
// full key keeps a location's error off the other locations' panels when a post
// targets more than one Google Business Profile.
const errors = usePageErrors();
const findError = (field: string) => computed<string | undefined>(
    () => errors.value[`platforms.${props.platformIndex}.meta.${field}`],
);
const eventTitleError = findError('event.title');
const eventStartDateError = findError('event.start_date');
const eventEndDateError = findError('event.end_date');
const eventStartTimeError = findError('event.start_time');
const eventEndTimeError = findError('event.end_time');
const offerCouponCodeError = findError('offer.coupon_code');
const offerRedeemUrlError = findError('offer.redeem_online_url');
const offerTermsError = findError('offer.terms_conditions');
const ctaActionTypeError = findError('call_to_action.action_type');
const ctaUrlError = findError('call_to_action.url');
</script>

<template>
    <div class="rounded-xl border-2 border-foreground bg-card shadow-2xs">
        <button
            type="button"
            class="flex w-full cursor-pointer items-center justify-between gap-3 p-4 text-sm"
            data-testid="google-business-settings-toggle"
            @click="open = !open"
        >
            <span class="flex min-w-0 items-center gap-2">
                <span class="inline-flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                    <img :src="getPlatformLogo('google_business')" :alt="$t('accounts.google_business.title')" class="size-full object-cover" />
                </span>
                <span class="truncate font-bold text-foreground">{{ $t('posts.form.google_business.settings') }}</span>
                <span v-if="socialAccount?.display_label" class="truncate font-medium text-foreground/60">·&nbsp;{{ socialAccount.display_label }}</span>
            </span>
            <IconChevronUp v-if="open" class="size-4 shrink-0 text-foreground/60" />
            <IconChevronDown v-else class="size-4 shrink-0 text-foreground/60" />
        </button>

        <div v-if="open" class="space-y-5 border-t-2 border-foreground/10 px-4 pb-4 pt-4">
            <div v-if="socialAccount" class="flex items-center gap-3 rounded-lg bg-foreground/5 p-3">
                <Avatar :src="socialAccount.avatar_url" :name="socialAccount.display_label" class="size-9 shrink-0 rounded-full border-2 border-foreground shadow-2xs" />
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.posting_to') }}</p>
                    <p class="truncate text-sm font-bold text-foreground">{{ socialAccount.display_label }}</p>
                </div>
            </div>

            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.topic_type_label') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="type in GOOGLE_BUSINESS_TOPIC_TYPES"
                        :key="type.value"
                        type="button"
                        class="cursor-pointer rounded-full border-2 px-3 py-1 text-xs font-bold uppercase tracking-widest transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="topicType === type.value
                            ? 'border-foreground bg-violet-100 text-foreground shadow-2xs'
                            : 'border-foreground/30 text-foreground/70 hover:border-foreground hover:text-foreground'"
                        :disabled="isLocked"
                        :data-testid="`google-business-topic-${type.value}`"
                        @click="topicType = type.value"
                    >
                        {{ $t(type.labelKey) }}
                    </button>
                </div>
            </div>

            <div v-if="showEventFields" class="grid grid-cols-2 gap-3">
                <div class="col-span-2 space-y-2">
                    <Label class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t(eventTitleLabelKey) }}</Label>
                    <Input v-model="eventTitle" type="text" :placeholder="$t(eventTitlePlaceholderKey)" :disabled="isLocked" :class="eventTitleError ? 'border-rose-500' : undefined" />
                    <InputError :message="eventTitleError" />
                </div>
                <div class="space-y-2">
                    <Label class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.event_start_date') }}</Label>
                    <DatePicker
                        v-model="eventStart"
                        align="start"
                        :show-time="true"
                        :disabled="isLocked"
                        :placeholder="$t('posts.form.google_business.event_start_date')"
                        :class="eventStartDateError || eventStartTimeError ? 'border-rose-500' : undefined"
                    />
                    <InputError :message="eventStartDateError || eventStartTimeError" />
                </div>
                <div class="space-y-2">
                    <Label class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.event_end_date') }}</Label>
                    <DatePicker
                        v-model="eventEnd"
                        align="start"
                        :show-time="true"
                        :disabled="isLocked"
                        :placeholder="$t('posts.form.google_business.event_end_date')"
                        :class="eventEndDateError || eventEndTimeError ? 'border-rose-500' : undefined"
                    />
                    <InputError :message="eventEndDateError || eventEndTimeError" />
                </div>
                <p class="col-span-2 text-xs font-medium text-foreground/60" data-testid="google-business-event-timezone-hint">{{ $t('posts.form.google_business.event_times_use_location') }}</p>
            </div>

            <div v-if="topicType === GoogleBusinessTopicType.Offer" class="space-y-3">
                <div class="space-y-2">
                    <Label class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.offer_coupon_code') }}</Label>
                    <Input v-model="offerCouponCode" type="text" :disabled="isLocked" :class="offerCouponCodeError ? 'border-rose-500' : undefined" />
                    <InputError :message="offerCouponCodeError" />
                </div>
                <div class="space-y-2">
                    <Label class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.offer_redeem_url') }}</Label>
                    <Input v-model="offerRedeemUrl" type="text" :disabled="isLocked" :class="offerRedeemUrlError ? 'border-rose-500' : undefined" />
                    <InputError :message="offerRedeemUrlError" />
                </div>
                <div class="space-y-2">
                    <Label class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.offer_terms') }}</Label>
                    <Input v-model="offerTerms" type="text" :disabled="isLocked" :class="offerTermsError ? 'border-rose-500' : undefined" />
                    <InputError :message="offerTermsError" />
                </div>
            </div>

            <div v-if="showCallToAction" class="space-y-2">
                <Label class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.cta_label') }}</Label>
                <Select v-model="ctaActionType" :disabled="isLocked">
                    <SelectTrigger class="w-full" :aria-invalid="ctaActionTypeError ? true : undefined">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in GOOGLE_BUSINESS_CTA_OPTIONS"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ $t(option.labelKey) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="ctaActionTypeError" />
            </div>

            <div v-if="showCtaUrl" class="space-y-2">
                <Label class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.cta_url') }}</Label>
                <Input v-model="ctaUrl" type="text" :placeholder="$t('posts.form.google_business.cta_url_placeholder')" :disabled="isLocked" :class="ctaUrlError ? 'border-rose-500' : undefined" />
                <InputError :message="ctaUrlError" />
            </div>
        </div>
    </div>
</template>
