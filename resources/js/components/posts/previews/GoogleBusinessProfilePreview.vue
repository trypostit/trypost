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
}>();

const typeLabel = computed(
    () =>
        ({
            google_business_profile_standard: 'Update',
            google_business_profile_event: 'Event',
            google_business_profile_offer: 'Offer',
            google_business_profile_alert: 'Alert',
        })[props.contentType ?? ''] ?? 'Update',
);

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
            <div
                v-if="media.length"
                class="relative aspect-[4/3] w-full overflow-hidden"
            >
                <PostMediaPreview :media="media" />
            </div>
            <div class="grid gap-3 p-4">
                <div
                    class="flex items-center justify-between gap-3 text-xs text-[#5f6368] dark:text-[#bdc1c6]"
                >
                    <span>{{ socialAccount.display_label }}</span>
                    <span>{{ typeLabel }}</span>
                </div>
                <h3 v-if="meta?.event_title" class="text-base font-semibold">
                    {{ meta.event_title }}
                </h3>
                <p class="text-sm leading-5 whitespace-pre-wrap">
                    {{
                        content ||
                        'Your Google Business Profile post will appear here.'
                    }}
                </p>
                <div
                    v-if="meta?.offer_coupon_code"
                    class="rounded-lg bg-[#f1f3f4] px-3 py-2 text-sm dark:bg-[#3c4043]"
                >
                    Offer code: <strong>{{ meta.offer_coupon_code }}</strong>
                </div>
                <button
                    v-if="buttonLabel"
                    type="button"
                    class="rounded border border-[#dadce0] px-3 py-2 text-sm font-medium text-[#1a73e8] dark:border-[#5f6368] dark:text-[#8ab4f8]"
                >
                    {{ buttonLabel }}
                </button>
            </div>
        </article>
    </div>
</template>
