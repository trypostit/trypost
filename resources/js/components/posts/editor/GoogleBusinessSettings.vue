<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import DatePicker from '@/components/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { usePageErrors } from '@/composables/usePageErrors';
import {
    GOOGLE_BUSINESS_CTA_OPTIONS,
    GoogleBusinessCtaAction,
    GoogleBusinessTopicType,
    googleBusinessAllowsCallToAction,
    resolveGoogleBusinessCtaAction,
    resolveGoogleBusinessTopicType,
    type GoogleBusinessCtaActionValue,
} from '@/lib/googleBusiness';

interface Props {
    /** This panel's position in the submitted `destinations` (composer) or `platforms` array — see findError. */
    platformIndex: number;
    meta: Record<string, any>;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
});

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();

const updateMeta = (patch: Record<string, any>) => {
    emit('update:meta', { ...props.meta, ...patch });
};

const updateEvent = (patch: Record<string, any>) => {
    updateMeta({ event: { ...props.meta?.event, ...patch } });
};

const topicType = computed(() =>
    resolveGoogleBusinessTopicType(props.meta?.topic_type),
);
const isOffer = computed(
    () => topicType.value === GoogleBusinessTopicType.Offer,
);
const isEvent = computed(
    () => topicType.value === GoogleBusinessTopicType.Event,
);

const addTime = ref(
    Boolean(props.meta?.event?.start_time || props.meta?.event?.end_time),
);
watch(isEvent, (event) => {
    if (event) {
        addTime.value = Boolean(
            props.meta?.event?.start_time || props.meta?.event?.end_time,
        );
    }
});
const setAddTime = (value: boolean): void => {
    addTime.value = value;
    if (!value) {
        updateEvent({ start_time: null, end_time: null });
    }
};

const hasOfferDetails = (): boolean =>
    Boolean(
        props.meta?.offer?.coupon_code ||
            props.meta?.offer?.redeem_online_url ||
            props.meta?.offer?.terms_conditions,
    );
const addMoreDetails = ref(hasOfferDetails());
watch(isOffer, (offer) => {
    if (offer) {
        addMoreDetails.value = hasOfferDetails();
    }
});
const setAddMoreDetails = (value: boolean): void => {
    addMoreDetails.value = value;
    if (!value) {
        updateMeta({
            offer: {
                coupon_code: null,
                redeem_online_url: null,
                terms_conditions: null,
            },
        });
    }
};

const ctaActionType = computed<GoogleBusinessCtaActionValue>({
    get: () =>
        resolveGoogleBusinessCtaAction(props.meta?.call_to_action?.action_type),
    set: (value: GoogleBusinessCtaActionValue) =>
        updateMeta({
            call_to_action: {
                ...props.meta?.call_to_action,
                action_type: value,
            },
        }),
});

const ctaLabelKey = computed(
    () =>
        GOOGLE_BUSINESS_CTA_OPTIONS.find(
            (option) => option.value === ctaActionType.value,
        )?.labelKey ?? GOOGLE_BUSINESS_CTA_OPTIONS[0].labelKey,
);

const showCallToAction = computed(() =>
    googleBusinessAllowsCallToAction(topicType.value),
);

const showCtaUrl = computed(
    () =>
        showCallToAction.value &&
        ctaActionType.value !== GoogleBusinessCtaAction.None &&
        ctaActionType.value !== GoogleBusinessCtaAction.Call,
);

const ctaUrl = computed<string>({
    get: () => props.meta?.call_to_action?.url || '',
    set: (value: string) =>
        updateMeta({
            call_to_action: {
                ...props.meta?.call_to_action,
                url: value.trim() === '' ? null : value,
            },
        }),
});

const eventTitle = computed<string>({
    get: () => props.meta?.event?.title || '',
    set: (value: string) =>
        updateEvent({ title: value.trim() === '' ? null : value }),
});

const eventField = (
    key: 'start_date' | 'end_date' | 'start_time' | 'end_time',
) =>
    computed({
        get: (): string => props.meta?.event?.[key] || '',
        set: (value: string | null) =>
            updateEvent({ [key]: value?.trim() ? value : null }),
    });

const eventStartDate = eventField('start_date');
const eventEndDate = eventField('end_date');
const eventStartTime = eventField('start_time');
const eventEndTime = eventField('end_time');

const eventTitleLabelKey = computed(() =>
    topicType.value === GoogleBusinessTopicType.Offer
        ? 'posts.form.google_business.offer_title'
        : 'posts.form.google_business.event_title',
);

const eventTitlePlaceholderKey = computed(() =>
    topicType.value === GoogleBusinessTopicType.Offer
        ? 'posts.form.google_business.offer_title_placeholder'
        : 'posts.form.google_business.event_title_placeholder',
);

const offerField = (
    key: 'coupon_code' | 'redeem_online_url' | 'terms_conditions',
) =>
    computed<string>({
        get: () => props.meta?.offer?.[key] || '',
        set: (value: string) =>
            updateMeta({
                offer: {
                    ...props.meta?.offer,
                    [key]: value.trim() === '' ? null : value,
                },
            }),
    });

const offerCouponCode = offerField('coupon_code');
const offerRedeemUrl = offerField('redeem_online_url');
const offerTerms = offerField('terms_conditions');

// The composer's errors are keyed `destinations.{index}.meta.*` and other
// forms' `platforms.{index}.meta.*`. Matching the full key keeps a
// location's error off the other locations' panels when a post targets more
// than one Google Business Profile.
const errors = usePageErrors();
const findError = (field: string) =>
    computed<string | undefined>(
        () =>
            errors.value[`destinations.${props.platformIndex}.meta.${field}`] ??
            errors.value[`platforms.${props.platformIndex}.meta.${field}`],
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
    <SettingsSection>
        <template v-if="isOffer || isEvent">
            <SettingsRow
                :label="$t(eventTitleLabelKey)"
                :label-for="`google-business-event-title-${platformIndex}`"
                align-top
            >
                <Input
                    :id="`google-business-event-title-${platformIndex}`"
                    v-model="eventTitle"
                    data-testid="google-business-title"
                    type="text"
                    :placeholder="$t(eventTitlePlaceholderKey)"
                    :disabled="disabled"
                    :aria-invalid="eventTitleError ? true : undefined"
                />
                <InputError :message="eventTitleError" />
            </SettingsRow>
            <SettingsRow v-if="isEvent">
                <label
                    class="flex min-h-8 items-center justify-end gap-2 text-sm"
                >
                    <Switch
                        size="sm"
                        :model-value="addTime"
                        data-testid="google-business-add-time"
                        :disabled="disabled"
                        @update:model-value="setAddTime(Boolean($event))"
                    />
                    <span data-single-line>{{
                        $t('posts.form.google_business.add_time')
                    }}</span>
                </label>
            </SettingsRow>
            <SettingsRow
                :label="$t('posts.form.google_business.event_start_date')"
                align-top
            >
                <div class="flex flex-wrap items-center gap-3">
                    <div class="w-40" data-testid="google-business-start">
                        <DatePicker
                            v-model="eventStartDate"
                            align="start"
                            :show-time="false"
                            :disabled="disabled"
                            :placeholder="
                                $t('posts.form.google_business.event_start_date')
                            "
                            :class="
                                eventStartDateError
                                    ? 'border-destructive'
                                    : undefined
                            "
                        />
                    </div>
                    <template v-if="isEvent && addTime">
                        <label
                            :for="`google-business-start-time-${platformIndex}`"
                            class="text-[13px] font-medium text-foreground"
                            data-single-line
                            >{{
                                $t('posts.form.google_business.event_start_time')
                            }}</label
                        >
                        <Input
                            :id="`google-business-start-time-${platformIndex}`"
                            v-model="eventStartTime"
                            data-testid="google-business-start-time"
                            type="time"
                            class="w-32"
                            :disabled="disabled"
                            :aria-invalid="eventStartTimeError ? true : undefined"
                        />
                    </template>
                </div>
                <InputError
                    :message="eventStartDateError || eventStartTimeError"
                />
            </SettingsRow>
            <SettingsRow
                :label="$t('posts.form.google_business.event_end_date')"
                align-top
            >
                <div class="flex flex-wrap items-center gap-3">
                    <div class="w-40" data-testid="google-business-end">
                        <DatePicker
                            v-model="eventEndDate"
                            align="start"
                            :show-time="false"
                            :disabled="disabled"
                            :placeholder="
                                $t('posts.form.google_business.event_end_date')
                            "
                            :class="
                                eventEndDateError
                                    ? 'border-destructive'
                                    : undefined
                            "
                        />
                    </div>
                    <template v-if="isEvent && addTime">
                        <label
                            :for="`google-business-end-time-${platformIndex}`"
                            class="text-[13px] font-medium text-foreground"
                            data-single-line
                            >{{
                                $t('posts.form.google_business.event_end_time')
                            }}</label
                        >
                        <Input
                            :id="`google-business-end-time-${platformIndex}`"
                            v-model="eventEndTime"
                            data-testid="google-business-end-time"
                            type="time"
                            class="w-32"
                            :disabled="disabled"
                            :aria-invalid="eventEndTimeError ? true : undefined"
                        />
                    </template>
                </div>
                <InputError :message="eventEndDateError || eventEndTimeError" />
                <p
                    v-if="isEvent && addTime"
                    class="text-xs text-muted-foreground"
                    data-testid="google-business-event-timezone-hint"
                >
                    {{
                        $t(
                            'posts.form.google_business.event_times_use_location',
                        )
                    }}
                </p>
            </SettingsRow>
            <template v-if="isOffer">
                <SettingsRow>
                    <label
                        class="flex min-h-8 items-center justify-end gap-2 text-sm"
                    >
                        <Switch
                            size="sm"
                            :model-value="addMoreDetails"
                            data-testid="google-business-add-details"
                            :disabled="disabled"
                            @update:model-value="
                                setAddMoreDetails(Boolean($event))
                            "
                        />
                        <span data-single-line>{{
                            $t('posts.form.google_business.add_more_details')
                        }}</span>
                    </label>
                </SettingsRow>
                <template v-if="addMoreDetails">
                    <SettingsRow
                        :label="
                            $t('posts.form.google_business.offer_coupon_code')
                        "
                        :label-for="`google-business-coupon-${platformIndex}`"
                        align-top
                    >
                        <Input
                            :id="`google-business-coupon-${platformIndex}`"
                            v-model="offerCouponCode"
                            data-testid="google-business-coupon"
                            type="text"
                            :disabled="disabled"
                            :aria-invalid="
                                offerCouponCodeError ? true : undefined
                            "
                        />
                        <InputError :message="offerCouponCodeError" />
                    </SettingsRow>
                    <SettingsRow
                        :label="
                            $t('posts.form.google_business.offer_redeem_url')
                        "
                        :label-for="`google-business-redeem-${platformIndex}`"
                        align-top
                    >
                        <Input
                            :id="`google-business-redeem-${platformIndex}`"
                            v-model="offerRedeemUrl"
                            data-testid="google-business-redeem"
                            type="text"
                            :disabled="disabled"
                            :aria-invalid="
                                offerRedeemUrlError ? true : undefined
                            "
                        />
                        <InputError :message="offerRedeemUrlError" />
                    </SettingsRow>
                    <SettingsRow
                        :label="$t('posts.form.google_business.offer_terms')"
                        :label-for="`google-business-terms-${platformIndex}`"
                        align-top
                    >
                        <Input
                            :id="`google-business-terms-${platformIndex}`"
                            v-model="offerTerms"
                            data-testid="google-business-terms"
                            type="text"
                            :disabled="disabled"
                            :aria-invalid="offerTermsError ? true : undefined"
                        />
                        <InputError :message="offerTermsError" />
                    </SettingsRow>
                </template>
            </template>
        </template>

        <SettingsRow
            v-if="showCallToAction"
            :label="$t('posts.form.google_business.cta_label')"
            :label-for="`google-business-cta-${platformIndex}`"
            align-top
        >
            <Select v-model="ctaActionType" :disabled="disabled">
                <SelectTrigger
                    :id="`google-business-cta-${platformIndex}`"
                    class="w-full"
                    data-testid="google-business-cta"
                    :aria-invalid="ctaActionTypeError ? true : undefined"
                >
                    <SelectValue>{{ $t(ctaLabelKey) }}</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in GOOGLE_BUSINESS_CTA_OPTIONS"
                        :key="option.value"
                        :value="option.value"
                        :data-testid="`google-business-cta-option-${option.value}`"
                    >
                        {{ $t(option.labelKey) }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="ctaActionTypeError" />
        </SettingsRow>

        <SettingsRow
            v-if="showCtaUrl"
            :label="$t('posts.form.google_business.cta_url')"
            :label-for="`google-business-cta-url-${platformIndex}`"
            align-top
        >
            <Input
                :id="`google-business-cta-url-${platformIndex}`"
                v-model="ctaUrl"
                data-testid="google-business-cta-url"
                type="text"
                :placeholder="
                    $t('posts.form.google_business.cta_url_placeholder')
                "
                :disabled="disabled"
                :aria-invalid="ctaUrlError ? true : undefined"
            />
            <InputError :message="ctaUrlError" />
        </SettingsRow>
    </SettingsSection>
</template>
