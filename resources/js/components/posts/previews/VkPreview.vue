<script setup lang="ts">
import VideoPreview from '@/components/posts/previews/VideoPreview.vue';
import { getInitials } from '@/composables/useInitials';
import { isVideoMedia } from '@/composables/useMedia';
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
}

defineProps<Props>();
</script>

<template>
    <div
        class="flex h-full w-full flex-col overflow-hidden bg-[#edeef0] dark:bg-[#0a0a0a]"
    >
        <div class="flex-1 overflow-y-auto p-3">
            <div
                class="overflow-hidden rounded-2xl bg-white shadow-[0_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#19191a]"
            >
                <!-- Header -->
                <div class="flex items-center gap-3 px-4 pt-3 pb-2">
                    <img
                        v-if="socialAccount.avatar_url"
                        :src="socialAccount.avatar_url"
                        :alt="socialAccount.display_label"
                        class="h-10 w-10 rounded-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-[#0077FF] font-semibold text-white"
                    >
                        {{ getInitials(socialAccount.display_label) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div
                            class="truncate text-[14px] font-semibold text-[#000] dark:text-[#e1e3e6]"
                        >
                            {{ socialAccount.display_label }}
                        </div>
                        <div class="text-[13px] text-[#818c99] dark:text-[#76787a]">
                            {{ $t('common.just_now') }}
                        </div>
                    </div>
                </div>

                <!-- Text -->
                <div
                    v-if="content"
                    class="px-4 pb-2 text-[14px] leading-[19px] whitespace-pre-wrap text-[#000] dark:text-[#e1e3e6]"
                >
                    {{ content }}
                </div>

                <!-- Media -->
                <div v-if="media.length > 0">
                    <div
                        class="overflow-hidden"
                        :class="{
                            'grid grid-cols-2 gap-0.5': media.length >= 2,
                        }"
                    >
                        <div
                            v-for="(item, index) in media.slice(0, 4)"
                            :key="item.id"
                            class="relative overflow-hidden"
                            :class="{
                                'aspect-[4/3]': media.length === 1,
                                'aspect-square': media.length > 1,
                            }"
                        >
                            <img
                                v-if="!isVideoMedia(item)"
                                :src="item.url"
                                :alt="item.original_filename"
                                class="h-full w-full object-cover"
                            />
                            <VideoPreview
                                v-else
                                :src="item.url"
                                video-class="w-full h-full object-cover bg-black"
                            />
                            <div
                                v-if="media.length > 4 && index === 3"
                                class="absolute inset-0 flex items-center justify-center bg-black/60"
                            >
                                <span class="text-xl font-semibold text-white"
                                    >+{{ media.length - 4 }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action row -->
                <div
                    class="flex items-center gap-2 px-3 py-2.5 text-[13px] font-medium text-[#818c99] dark:text-[#76787a]"
                >
                    <span
                        class="flex items-center gap-1 rounded-full bg-[#f2f3f5] px-3 py-1.5 dark:bg-[#232324]"
                    >
                        &#10084;&#65039; 12
                    </span>
                    <span
                        class="flex items-center gap-1 rounded-full bg-[#f2f3f5] px-3 py-1.5 dark:bg-[#232324]"
                    >
                        &#128172; 3
                    </span>
                    <span
                        class="flex items-center gap-1 rounded-full bg-[#f2f3f5] px-3 py-1.5 dark:bg-[#232324]"
                    >
                        &#8618;
                    </span>
                    <span class="ml-auto flex items-center gap-1">
                        &#128065; 245
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
