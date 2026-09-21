<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { getInitials } from '@/composables/useInitials';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import {
    GOOGLE_BUSINESS_EVENT_TOPIC_TYPES,
    googleBusinessAllowsCallToAction,
    googleBusinessCtaLabelKey,
    resolveGoogleBusinessTopicType,
} from '@/lib/googleBusiness';
import { isImage } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

const props = defineProps<{
    socialAccount: SocialAccount;
    content: string;
    media: MediaItem[];
    meta?: Record<string, any>;
}>();

const topicType = computed(() =>
    resolveGoogleBusinessTopicType(props.meta?.topic_type),
);

const image = computed(() => props.media.find((item) => isImage(item)) ?? null);

const ctaLabel = computed(() => {
    if (!googleBusinessAllowsCallToAction(topicType.value)) {
        return null;
    }

    const key = googleBusinessCtaLabelKey(
        props.meta?.call_to_action?.action_type,
    );

    return key ? trans(key) : null;
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

    if (!title && !range && !coupon) {
        return null;
    }

    return { title, range, coupon };
});
</script>

<template>
    <div
        class="flex h-full w-full flex-col overflow-hidden bg-white dark:bg-[#202124]"
    >
        <div
            class="flex items-center gap-3 border-b border-black/10 px-4 py-3 dark:border-white/10"
        >
            <img
                v-if="socialAccount.avatar_url"
                :src="socialAccount.avatar_url"
                :alt="socialAccount.display_label"
                class="h-9 w-9 rounded-full object-cover"
            />
            <div
                v-else
                class="flex h-9 w-9 items-center justify-center rounded-full bg-[#4285F4] font-semibold text-white"
            >
                {{ getInitials(socialAccount.display_label) }}
            </div>
            <div class="min-w-0 flex-1">
                <div
                    class="truncate text-sm font-semibold text-[#202124] dark:text-white"
                >
                    {{ socialAccount.display_label }}
                </div>
                <div class="text-xs text-[#5f6368] dark:text-[#9aa0a6]">
                    {{ getPlatformLabel('google_business') }}
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div
                v-if="image"
                class="aspect-video w-full overflow-hidden bg-black/5"
            >
                <img
                    :src="image.url"
                    :alt="image.original_filename"
                    class="h-full w-full object-cover"
                />
            </div>

            <div class="space-y-3 px-4 py-3">
                <div v-if="event" class="space-y-0.5">
                    <p
                        v-if="event.title"
                        class="text-sm font-semibold text-[#202124] dark:text-white"
                    >
                        {{ event.title }}
                    </p>
                    <p
                        v-if="event.range"
                        class="text-xs text-[#5f6368] dark:text-[#9aa0a6]"
                    >
                        {{ event.range }}
                    </p>
                    <p
                        v-if="event.coupon"
                        class="text-xs font-medium text-[#5f6368] dark:text-[#9aa0a6]"
                    >
                        {{ event.coupon }}
                    </p>
                </div>

                <p
                    v-if="content"
                    class="text-sm whitespace-pre-wrap text-[#202124] dark:text-[#e8eaed]"
                >
                    {{ content }}
                </p>

                <button
                    v-if="ctaLabel"
                    type="button"
                    class="rounded-full bg-[#4285F4] px-4 py-1.5 text-xs font-semibold text-white"
                >
                    {{ ctaLabel }}
                </button>
            </div>
        </div>
    </div>
</template>
