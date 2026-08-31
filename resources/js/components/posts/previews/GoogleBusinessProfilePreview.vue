<script setup lang="ts">
import { computed } from 'vue';

import PostMediaPreview from '@/components/posts/previews/PostMediaPreview.vue';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    socialAccount: { display_label: string };
    content: string;
    media: MediaItem[];
    contentType?: string;
    meta?: Record<string, any>;
    postedAt?: string | null;
}>();

const typeLabel = computed(
    () =>
        ({
            google_business_profile_standard: 'Update',
            google_business_profile_event: 'Event',
            google_business_profile_offer: 'Offer',
            google_business_profile_alert: 'Legacy alert',
        })[props.contentType ?? ''] ?? 'Update',
);

const formattedDate = computed(() => {
    const date = props.postedAt ? new Date(props.postedAt) : new Date();
    return Number.isNaN(date.getTime()) ? 'Just now' : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
});

const eventRange = computed(() => {
    if (!props.meta?.event_start_at) return null;
    const start = new Date(props.meta.event_start_at);
    const end = props.meta?.event_end_at ? new Date(props.meta.event_end_at) : null;
    if (Number.isNaN(start.getTime())) return null;

    const format = (date: Date) => date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    return end && !Number.isNaN(end.getTime()) ? `${format(start)} – ${format(end)}` : format(start);
});

const buttonLabel = computed(
    () =>
        ({
            BOOK: 'Book',
            ORDER: 'Order',
            SHOP: 'Shop',
            LEARN_MORE: 'Learn more',
            SIGN_UP: 'Sign up',
            CALL: 'Call',
        })[props.meta?.cta_action_type as string],
);
</script>

<template>
    <div
        class="flex h-full w-full items-center justify-center overflow-hidden bg-[#f8f9fa] p-4 text-[#202124] dark:bg-[#202124] dark:text-[#e8eaed]"
    >
        <article
            class="w-full max-w-sm overflow-hidden rounded-xl border border-[#dadce0] bg-white shadow-sm dark:border-[#5f6368] dark:bg-[#303134]"
        >
            <div class="grid gap-3 p-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full border border-[#dadce0] bg-white">
                        <img src="/images/accounts/google-business-profile.svg" alt="" class="size-7" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">{{ socialAccount.display_label }}</p>
                        <p class="text-xs text-[#5f6368] dark:text-[#bdc1c6]">Google Business Profile · {{ formattedDate }}</p>
                    </div>
                    <span class="rounded-full bg-[#f1f3f4] px-2 py-1 text-[11px] font-medium dark:bg-[#3c4043]">{{ typeLabel }}</span>
                </div>
                <div v-if="contentType === 'google_business_profile_alert'" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    This is a historical alert preview. Google no longer allows new alerts to be authored.
                </div>
                <h3 v-if="meta?.event_title" class="text-base font-semibold">
                    {{ meta.event_title }}
                </h3>
                <p v-if="eventRange" class="text-xs font-medium text-[#5f6368] dark:text-[#bdc1c6]">{{ eventRange }}</p>
                <p class="text-sm leading-5 whitespace-pre-wrap">
                    {{
                        content ||
                        'Your Google Business Profile post will appear here.'
                    }}
                </p>
                <div
                    v-if="media.length"
                    class="relative aspect-[4/3] w-full overflow-hidden rounded-lg bg-[#f1f3f4]"
                >
                    <PostMediaPreview :media="media" />
                </div>
                <div
                    v-if="meta?.offer_coupon_code"
                    class="rounded-lg bg-[#f1f3f4] px-3 py-2 text-sm dark:bg-[#3c4043]"
                >
                    Offer code: <strong>{{ meta.offer_coupon_code }}</strong>
                </div>
                <p v-if="meta?.offer_terms" class="text-xs leading-4 text-[#5f6368] dark:text-[#bdc1c6]">{{ meta.offer_terms }}</p>
                <button
                    v-if="buttonLabel"
                    type="button"
                    class="w-full rounded border border-[#dadce0] px-3 py-2 text-sm font-medium text-[#1a73e8] dark:border-[#5f6368] dark:text-[#8ab4f8]"
                >
                    {{ buttonLabel }}
                </button>
            </div>
        </article>
    </div>
</template>
