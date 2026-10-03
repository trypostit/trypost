<script setup lang="ts">
import { IconDotsVertical, IconRosetteDiscountCheckFilled, IconShare } from '@tabler/icons-vue';
import { computed } from 'vue';

import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import date from '@/date';
import {
    GOOGLE_BUSINESS_EVENT_TOPIC_TYPES,
    googleBusinessAllowsCallToAction,
    googleBusinessCtaLabelKey,
    resolveGoogleBusinessTopicType,
} from '@/lib/googleBusiness';
import { isGif, isImage } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';

import type { PreviewProps } from './types';

const props = defineProps<PreviewProps>();

const topicType = computed(() =>
    resolveGoogleBusinessTopicType(props.meta?.topic_type),
);

const images = computed((): MediaItem[] => {
    const image = props.media.find((item) => isImage(item) && !isGif(item));

    return image ? [image] : [];
});

const ctaLabelKey = computed((): string | null => {
    if (!googleBusinessAllowsCallToAction(topicType.value)) {
        return null;
    }

    return googleBusinessCtaLabelKey(props.meta?.call_to_action?.action_type);
});

const eventInstant = (day?: string, time?: string): string | null => {
    if (!day) {
        return null;
    }

    return time
        ? date.formatLocalDateTime(`${day}T${time}`)
        : date.formatDateOnly(day);
};

const event = computed(() => {
    if (!GOOGLE_BUSINESS_EVENT_TOPIC_TYPES.includes(topicType.value)) {
        return null;
    }

    const title = props.meta?.event?.title || '';
    const start = eventInstant(
        props.meta?.event?.start_date,
        props.meta?.event?.start_time,
    );
    const end = eventInstant(
        props.meta?.event?.end_date,
        props.meta?.event?.end_time,
    );
    const range = start && end && end !== start ? `${start} – ${end}` : start;
    const coupon = props.meta?.offer?.coupon_code || '';
    const redeem = props.meta?.offer?.redeem_online_url || '';
    const terms = props.meta?.offer?.terms_conditions || '';

    if (!title && !range && !coupon && !redeem && !terms) {
        return null;
    }

    return { title, range, coupon, redeem, terms };
});
</script>

<template>
    <PreviewTextPost
        data-testid="google-business-preview"
        variant="stacked"
        :account="socialAccount"
        :content="content"
        :media="images"
        :caption="date.formatPreviewDate(postedAt)"
        avatar-class="size-8"
        avatar-square
        text-class="text-muted-foreground"
        link-tone="info"
        tags="plain"
        :trailing-actions="[{ icon: IconShare }]"
        action-class="text-info"
    >
        <template #avatar-badge>
            <IconRosetteDiscountCheckFilled
                class="absolute -right-1.5 -bottom-1.5 size-4 rounded-full bg-card text-info"
            />
        </template>
        <template #aside>
            <IconDotsVertical class="size-5 shrink-0 text-muted-foreground" />
        </template>
        <template v-if="event" #before-media>
            <div class="space-y-0.5 text-[13px] leading-[18px] text-muted-foreground">
                <p v-if="event.title" class="text-[15px] font-semibold text-foreground">
                    {{ event.title }}
                </p>
                <p v-if="event.range">{{ event.range }}</p>
                <p v-if="event.coupon" class="font-medium">{{ event.coupon }}</p>
                <p v-if="event.redeem">{{ event.redeem }}</p>
                <p v-if="event.terms">{{ event.terms }}</p>
            </div>
        </template>
        <span
            v-if="ctaLabelKey"
            class="inline-flex rounded-full border px-4 py-1.5 text-[13px] font-semibold text-info"
            >{{ $t(ctaLabelKey) }}</span
        >
    </PreviewTextPost>
</template>
