<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { getInitials } from '@/composables/useInitials';
import { googleBusinessCtaLabelKey } from '@/lib/googleBusiness';
import type { MediaItem } from '@/types/media';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

interface Props {
    socialAccount: SocialAccount;
    content: string;
    media: MediaItem[];
    meta?: Record<string, any>;
}

const props = defineProps<Props>();

const ctaLabel = computed(() => {
    const labelKey = googleBusinessCtaLabelKey(props.meta?.call_to_action?.action_type);

    return labelKey ? trans(labelKey) : null;
});
</script>

<template>
    <div class="flex h-full w-full flex-col overflow-hidden bg-white dark:bg-[#202124]">
        <div class="flex items-center gap-3 border-b border-black/10 px-4 py-3 dark:border-white/10">
            <img
                v-if="socialAccount.avatar_url"
                :src="socialAccount.avatar_url"
                :alt="socialAccount.display_label"
                class="h-9 w-9 rounded-full object-cover"
            />
            <div v-else class="flex h-9 w-9 items-center justify-center rounded-full bg-[#4285F4] font-semibold text-white">
                {{ getInitials(socialAccount.display_label) }}
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-semibold text-[#202124] dark:text-white">{{ socialAccount.display_label }}</div>
                <div class="text-xs text-[#5f6368] dark:text-[#9aa0a6]">Google Business Profile</div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div v-if="media.length > 0" class="aspect-video w-full overflow-hidden bg-black/5">
                <img :src="media[0].url" :alt="media[0].original_filename" class="h-full w-full object-cover" />
            </div>

            <div class="space-y-3 px-4 py-3">
                <p v-if="content" class="whitespace-pre-wrap text-sm text-[#202124] dark:text-[#e8eaed]">{{ content }}</p>

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
