<script setup lang="ts">
import { IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Avatar } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { usePageErrors } from '@/composables/usePageErrors';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { GOOGLE_BUSINESS_CTA_OPTIONS, GOOGLE_BUSINESS_EVENT_TOPIC_TYPES } from '@/lib/googleBusiness';

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

const topicTypes = [
    { value: 'STANDARD', labelKey: 'posts.form.google_business.topic_type.standard' },
    { value: 'EVENT', labelKey: 'posts.form.google_business.topic_type.event' },
    { value: 'OFFER', labelKey: 'posts.form.google_business.topic_type.offer' },
] as const;

const topicType = computed<string>({
    get: () => props.meta?.topic_type || 'STANDARD',
    set: (value: string) => emit('update:meta', { ...props.meta, topic_type: value }),
});

const ctaActionType = computed<string>({
    get: () => props.meta?.call_to_action?.action_type || 'NONE',
    set: (value: string) => emit('update:meta', {
        ...props.meta,
        call_to_action: { ...props.meta?.call_to_action, action_type: value },
    }),
});

const showCtaUrl = computed(() => ctaActionType.value !== 'NONE' && ctaActionType.value !== 'CALL');

const showEventFields = computed(() => GOOGLE_BUSINESS_EVENT_TOPIC_TYPES.includes(topicType.value));

const ctaUrl = computed<string>({
    get: () => props.meta?.call_to_action?.url || '',
    set: (value: string) => emit('update:meta', {
        ...props.meta,
        call_to_action: { ...props.meta?.call_to_action, url: value.trim() === '' ? null : value },
    }),
});

const eventField = (key: 'title' | 'start_date' | 'end_date' | 'start_time' | 'end_time') => computed<string>({
    get: () => props.meta?.event?.[key] || '',
    set: (value: string) => emit('update:meta', {
        ...props.meta,
        event: { ...props.meta?.event, [key]: value.trim() === '' ? null : value },
    }),
});

const eventTitle = eventField('title');
const eventStartDate = eventField('start_date');
const eventEndDate = eventField('end_date');
const eventStartTime = eventField('start_time');
const eventEndTime = eventField('end_time');

const offerField = (key: 'coupon_code' | 'redeem_online_url' | 'terms_conditions') => computed<string>({
    get: () => props.meta?.offer?.[key] || '',
    set: (value: string) => emit('update:meta', {
        ...props.meta,
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
const ctaUrlError = findError('call_to_action.url');
</script>

<template>
    <div class="rounded-xl border-2 border-foreground bg-card shadow-2xs">
        <button
            type="button"
            class="flex w-full cursor-pointer items-center justify-between gap-3 p-4 text-sm"
            @click="open = !open"
        >
            <span class="flex min-w-0 items-center gap-2">
                <span class="inline-flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                    <img :src="getPlatformLogo('google_business')" alt="Google Business Profile" class="size-full object-cover" />
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
                        v-for="type in topicTypes"
                        :key="type.value"
                        type="button"
                        class="cursor-pointer rounded-full border-2 px-3 py-1 text-xs font-bold uppercase tracking-widest transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="topicType === type.value
                            ? 'border-foreground bg-violet-100 text-foreground shadow-2xs'
                            : 'border-foreground/30 text-foreground/70 hover:border-foreground hover:text-foreground'"
                        :disabled="disabled || previewOnly"
                        @click="topicType = type.value"
                    >
                        {{ $t(type.labelKey) }}
                    </button>
                </div>
            </div>

            <div v-if="showEventFields" class="grid grid-cols-2 gap-3">
                <div class="col-span-2 space-y-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.event_title') }}</p>
                    <Input v-model="eventTitle" type="text" :placeholder="$t('posts.form.google_business.event_title_placeholder')" :disabled="disabled || previewOnly" :class="eventTitleError ? 'border-rose-500' : undefined" />
                    <InputError :message="eventTitleError" />
                </div>
                <div class="space-y-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.event_start_date') }}</p>
                    <Input v-model="eventStartDate" type="date" :disabled="disabled || previewOnly" :class="eventStartDateError ? 'border-rose-500' : undefined" />
                    <InputError :message="eventStartDateError" />
                </div>
                <div class="space-y-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.event_end_date') }}</p>
                    <Input v-model="eventEndDate" type="date" :disabled="disabled || previewOnly" :class="eventEndDateError ? 'border-rose-500' : undefined" />
                    <InputError :message="eventEndDateError" />
                </div>
                <div class="space-y-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.event_start_time') }}</p>
                    <Input v-model="eventStartTime" type="time" :disabled="disabled || previewOnly" />
                </div>
                <div class="space-y-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.event_end_time') }}</p>
                    <Input v-model="eventEndTime" type="time" :disabled="disabled || previewOnly" />
                </div>
            </div>

            <div v-if="topicType === 'OFFER'" class="space-y-3">
                <div class="space-y-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.offer_coupon_code') }}</p>
                    <Input v-model="offerCouponCode" type="text" :disabled="disabled || previewOnly" />
                </div>
                <div class="space-y-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.offer_redeem_url') }}</p>
                    <Input v-model="offerRedeemUrl" type="text" :disabled="disabled || previewOnly" />
                </div>
                <div class="space-y-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.offer_terms') }}</p>
                    <Textarea v-model="offerTerms" :disabled="disabled || previewOnly" rows="2" />
                </div>
            </div>

            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.cta_label') }}</p>
                <select
                    v-model="ctaActionType"
                    class="w-full rounded-lg border-2 border-foreground/30 bg-card px-3 py-2 text-sm font-medium text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="disabled || previewOnly"
                >
                    <option v-for="option in GOOGLE_BUSINESS_CTA_OPTIONS" :key="option.value" :value="option.value">{{ $t(option.labelKey) }}</option>
                </select>
            </div>

            <div v-if="showCtaUrl" class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.google_business.cta_url') }}</p>
                <Input v-model="ctaUrl" type="text" :placeholder="$t('posts.form.google_business.cta_url_placeholder')" :disabled="disabled || previewOnly" :class="ctaUrlError ? 'border-rose-500' : undefined" />
                <InputError :message="ctaUrlError" />
            </div>
        </div>
    </div>
</template>
