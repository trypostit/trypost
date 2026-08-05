<script setup lang="ts">
import { computed } from 'vue';

import VideoPreview from "@/components/posts/previews/VideoPreview.vue";
import { isVideoMedia } from '@/composables/useMedia';
import date from '@/date';
import type { MediaItem } from '@/types/media';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    avatar_url: string | null;
}

interface Props {
    socialAccount: SocialAccount;
    content: string;
    media: MediaItem[];
    postedAt?: string | null;
}

const props = defineProps<Props>();

const postedAtLabel = computed(() => date.formatMastodonPreview(props.postedAt));
</script>

<template>
    <div class="w-full h-full bg-white dark:bg-[#191b22] text-[#1f232b] dark:text-white overflow-hidden flex flex-col">

        <!-- Main Content -->
        <div class="flex-1 overflow-y-auto mt-4">
            <!-- Post -->
            <div class="px-4 pb-3">
                <!-- Author -->
                <div class="flex items-center gap-3 mb-3">
                    <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                        :alt="socialAccount.display_name" class="h-10 w-10 rounded-full object-cover" />
                    <div v-else
                        class="h-10 w-10 rounded-full bg-gradient-to-br from-[#6364ff] to-[#563acc] flex items-center justify-center text-white font-semibold">
                        {{ socialAccount.display_name?.charAt(0) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-[15px]">
                            {{ socialAccount.display_name }}
                        </div>
                        <div class="text-[14px] text-[#606984] dark:text-[#9baec8] truncate">
                            @{{ socialAccount.username || 'user' }}@mastodon.social
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div v-if="content" class="text-[16px] whitespace-pre-wrap leading-[22px] mb-3">
                    {{ content }}
                </div>

                <!-- Media -->
                <div v-if="media.length > 0" class="mb-3">
                    <div class="rounded-lg overflow-hidden" :class="{
                        'grid grid-cols-2 gap-0.5': media.length >= 2,
                    }">
                        <div v-for="(item, index) in media.slice(0, 4)" :key="item.id" class="relative overflow-hidden"
                            :class="{
                                'aspect-[4/3]': media.length === 1,
                                'aspect-square': media.length > 1,
                            }">
                            <img v-if="!isVideoMedia(item)" :src="item.url" :alt="item.original_filename"
                                class="w-full h-full object-cover" />
                            <VideoPreview v-else :src="item.url" video-class="w-full h-full object-cover bg-black" />
                            <!-- Hide button -->
                            <button
                                class="absolute top-2 right-2 bg-black/60 text-white text-[12px] px-2 py-0.5 rounded">
                                Hide
                            </button>
                            <div v-if="media.length > 4 && index === 3"
                                class="absolute inset-0 bg-black/60 flex items-center justify-center">
                                <span class="text-white text-xl font-semibold">+{{ media.length - 4 }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Timestamp -->
                <div class="text-[14px] text-[#606984] dark:text-[#9baec8] mb-2">
                    <span>{{ postedAtLabel }}</span>
                    <span class="mx-1.5">·</span>
                    <svg class="inline h-4 w-4 align-text-bottom" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="2" y1="12" x2="22" y2="12" />
                        <path
                            d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
                    </svg>
                    <span class="mx-1.5">·</span>
                    <span>Web</span>
                </div>

                <!-- Stats -->
                <div class="text-[14px] text-[#606984] dark:text-[#9baec8]">
                    <span class="font-semibold text-[#1f232b] dark:text-white">0</span> boosts
                    <span class="mx-1.5">·</span>
                    <span class="font-semibold text-[#1f232b] dark:text-white">0</span> quotes
                    <span class="mx-1.5">·</span>
                    <span class="font-semibold text-[#1f232b] dark:text-white">0</span> favorites
                </div>
            </div>

            <!-- Actions -->
            <div class="border-t border-[#c9d4de] dark:border-[#313543]">
                <div class="flex items-center justify-around py-2">
                    <!-- Reply -->
                    <button class="p-2 hover:bg-[#6364ff]/10 rounded-full">
                        <svg class="h-5 w-5 text-[#606984] dark:text-[#9baec8]" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <path d="M3 10h10a5 5 0 0 1 5 5v6M3 10l6 6M3 10l6-6" />
                        </svg>
                    </button>
                    <!-- Boost -->
                    <button class="p-2 hover:bg-[#8c8dff]/10 rounded-full">
                        <svg class="h-5 w-5 text-[#606984] dark:text-[#9baec8]" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <path d="M17 1l4 4-4 4" />
                            <path d="M3 11V9a4 4 0 0 1 4-4h14" />
                            <path d="M7 23l-4-4 4-4" />
                            <path d="M21 13v2a4 4 0 0 1-4 4H3" />
                        </svg>
                    </button>
                    <!-- Favourite (Star) -->
                    <button class="p-2 hover:bg-[#ca8f04]/10 rounded-full">
                        <svg class="h-5 w-5 text-[#606984] dark:text-[#9baec8]" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <polygon
                                points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                        </svg>
                    </button>
                    <!-- Bookmark -->
                    <button class="p-2 hover:bg-[#6364ff]/10 rounded-full">
                        <svg class="h-5 w-5 text-[#606984] dark:text-[#9baec8]" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
                        </svg>
                    </button>
                    <!-- More -->
                    <button class="p-2 hover:bg-neutral-100 dark:hover:bg-[#313543] rounded-full">
                        <svg class="h-5 w-5 text-[#606984] dark:text-[#9baec8]" viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="12" cy="12" r="1.5" />
                            <circle cx="6" cy="12" r="1.5" />
                            <circle cx="18" cy="12" r="1.5" />
                        </svg>
                    </button>
                </div>
            </div>

        </div>
    </div>
</template>