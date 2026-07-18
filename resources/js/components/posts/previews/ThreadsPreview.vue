<script setup lang="ts">
import { computed, toRef } from 'vue';

import LinkCard from "@/components/posts/previews/LinkCard.vue";
import VideoPreview from "@/components/posts/previews/VideoPreview.vue";
import { useLinkCard } from '@/composables/useLinkCard';
import { isVideoMedia } from '@/composables/useMedia';
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
}

const props = defineProps<Props>();

const username = computed(() => props.socialAccount.username || props.socialAccount.display_name);

const { card: linkCard, loading: linkCardLoading } = useLinkCard(
    toRef(props, 'content'),
    toRef(props, 'media'),
);
</script>

<template>
    <div
        class="w-full h-full bg-white dark:bg-[#101010] text-[#000000] dark:text-[#f5f5f5] overflow-hidden flex flex-col">
        <!-- Mobile Header -->
        <div
            class="flex-shrink-0 h-11 flex items-center justify-between px-4 border-b border-[#e0e0e0] dark:border-[#262626]">
            <!-- Back arrow -->
            <button class="p-1 -ml-1">
                <svg class="h-6 w-6 text-[#000000] dark:text-[#f5f5f5]" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7" />
                </svg>
            </button>
            <!-- Threads Logo -->
            <svg class="w-7 h-7" viewBox="0 0 192 192" fill="currentColor">
                <path
                    d="M141.537 88.9883C140.71 88.5919 139.87 88.2104 139.019 87.8451C137.537 60.5382 122.616 44.905 97.5619 44.745C97.4484 44.7443 97.3349 44.7443 97.2214 44.7443C82.2635 44.7443 69.7598 50.9181 62.0019 62.7124L77.3095 72.8084C83.1212 63.9548 91.7464 62.0206 97.2728 62.0206C97.3508 62.0206 97.4289 62.0206 97.5063 62.0213C106.347 62.0785 112.987 64.4804 117.321 69.1467C120.495 72.5682 122.635 77.4129 123.711 83.6087C116.923 82.3915 109.583 81.8491 101.772 81.9949C79.8064 82.3799 65.3203 94.1014 66.2476 111.253C66.7191 119.978 70.8908 127.556 78.0361 132.581C84.0521 136.829 91.5785 138.981 99.4574 138.752C110.047 138.449 118.558 134.531 124.713 127.122C129.313 121.588 132.401 114.47 133.963 105.889C139.917 109.378 144.254 113.94 146.599 119.461C150.463 128.405 150.675 143.054 138.575 155.153C128.01 165.719 115.048 170.176 96.2496 170.343C75.1149 170.156 59.0699 163.64 48.4969 151.014C38.4904 139.058 33.2931 121.85 33.0691 100.054C33.2931 78.2575 38.4904 61.0498 48.4969 49.0929C59.0699 36.4672 75.1149 29.9506 96.2496 29.7631C117.502 29.9513 133.728 36.5004 144.542 49.0964C149.82 55.2421 153.77 62.7749 156.325 71.4739L172.775 67.0265C169.456 56.0724 164.188 46.5832 157.081 38.6884C143.36 22.8619 123.353 14.6343 96.3549 14.4314C96.3198 14.4313 96.2847 14.4313 96.2496 14.4313C96.2145 14.4313 96.1794 14.4313 96.1443 14.4314C69.1466 14.6343 49.1398 22.8619 35.4188 38.6884C22.4674 53.6261 15.8782 74.3068 15.6523 100.007L15.6523 100.054L15.6523 100.1C15.8782 125.801 22.4674 146.481 35.4188 161.419C49.1398 177.245 69.1466 185.473 96.1443 185.676C96.1794 185.676 96.2145 185.676 96.2496 185.676C96.2847 185.676 96.3198 185.676 96.3549 185.676C119.718 185.446 137.054 179.474 150.393 166.134C167.788 148.739 167.297 126.992 161.539 113.128C157.178 102.609 148.783 94.0696 137.012 88.0016C137.032 87.9922 137.051 87.9828 137.071 87.9734C137.071 87.9734 137.071 87.9734 137.071 87.9734ZM99.0146 121.609C88.7879 121.863 82.5113 116.366 82.1765 109.508C81.7961 101.655 89.3498 96.2856 101.943 96.0483C106.946 95.9536 111.679 96.3391 116.093 97.1042C115.07 115.486 107.863 121.392 99.0146 121.609Z" />
            </svg>
            <!-- Menu -->
            <button class="p-1 -mr-1">
                <svg class="h-6 w-6 text-[#000000] dark:text-[#f5f5f5]" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <line x1="3" y1="6" x2="21" y2="6" />
                    <line x1="3" y1="12" x2="21" y2="12" />
                    <line x1="3" y1="18" x2="21" y2="18" />
                </svg>
            </button>
        </div>

        <!-- Main Content -->
        <div class="flex-1 overflow-y-auto">
            <!-- Post -->
            <div class="px-4 pt-3 pb-2">
                <!-- Author row -->
                <div class="flex items-center gap-2.5">
                    <!-- Avatar -->
                    <div class="w-9 h-9 rounded-full overflow-hidden flex-shrink-0">
                        <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                            :alt="socialAccount.display_name" class="w-full h-full object-cover" />
                        <div v-else
                            class="w-full h-full bg-gradient-to-br from-[#833ab4] via-[#fd1d1d] to-[#fcb045] flex items-center justify-center text-white font-bold text-sm">
                            {{ socialAccount.display_name?.charAt(0).toUpperCase() }}
                        </div>
                    </div>

                    <!-- Name + verified -->
                    <div class="flex items-center gap-1 min-w-0">
                        <span class="font-semibold text-[15px] text-[#000000] dark:text-[#f5f5f5] truncate">
                            {{ username }}
                        </span>
                        <!-- Verified badge -->
                        <svg class="h-3.5 w-3.5 text-[#0095f6] flex-shrink-0" viewBox="0 0 40 40" fill="currentColor">
                            <path
                                d="M19.998 3.094L14.638 0l-2.972 5.15H5.432v6.354L0 14.64 3.094 20 0 25.359l5.432 3.137v5.905h5.975L14.638 40l5.36-3.094L25.358 40l3.232-5.6h6.162v-6.01L40 25.359 36.905 20 40 14.641l-5.248-3.03v-6.46h-6.419L25.358 0l-5.36 3.094zm7.415 11.225l2.254 2.287-11.43 11.5-6.835-6.93 2.244-2.258 4.587 4.581 9.18-9.18z" />
                        </svg>
                    </div>
                </div>

                <!-- Content -->
                <div v-if="content" class="mt-3 text-[15px] text-[#000000] dark:text-[#f5f5f5] whitespace-pre-wrap leading-[22px]">
                    {{ content }}
                </div>

                <!-- Media -->
                <div v-if="media.length > 0" class="mt-3">
                    <div class="rounded-xl overflow-hidden" :class="{
                        'grid grid-cols-2 gap-0.5': media.length >= 2,
                    }">
                        <div v-for="(item, index) in media.slice(0, 4)" :key="item.id"
                            class="relative overflow-hidden" :class="{
                                'aspect-[4/3]': media.length === 1,
                                'aspect-square': media.length > 1,
                            }">
                            <img v-if="!isVideoMedia(item)" :src="item.url" :alt="item.original_filename"
                                class="w-full h-full object-cover" />
                            <VideoPreview v-else :src="item.url" video-class="w-full h-full object-cover bg-black" />
                            <div v-if="media.length > 4 && index === 3"
                                class="absolute inset-0 bg-black/60 flex items-center justify-center">
                                <span class="text-white text-xl font-semibold">+{{ media.length - 4 }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Link preview card -->
                <div
                    v-if="media.length === 0 && linkCardLoading"
                    class="mt-3 h-24 animate-pulse rounded-2xl border border-[#e0e0e0] bg-neutral-100 dark:border-[#262626] dark:bg-[#181818]"
                ></div>
                <LinkCard v-else-if="media.length === 0 && linkCard" :card="linkCard" />

                <!-- Location (after media) -->
                <div v-if="media.length > 0" class="mt-2 flex items-center gap-1">
                    <svg class="h-4 w-4 text-[#999999]" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                        <circle cx="12" cy="10" r="3" />
                    </svg>
                    <span class="text-xs text-[#999999]">Around The World</span>
                </div>
            </div>

            <!-- Actions -->
            <div class="border-t border-[#e0e0e0] dark:border-[#262626]">
                <div class="flex items-center justify-around py-2">
                    <!-- Like -->
                    <button class="p-2">
                        <svg class="h-6 w-6 text-[#000000] dark:text-[#f5f5f5]" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <path
                                d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
                        </svg>
                    </button>
                    <!-- Comment -->
                    <button class="p-2">
                        <svg class="h-6 w-6 text-[#000000] dark:text-[#f5f5f5]" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <path
                                d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                        </svg>
                    </button>
                    <!-- Repost -->
                    <button class="p-2">
                        <svg class="h-6 w-6 text-[#000000] dark:text-[#f5f5f5]" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <path d="M17 1l4 4-4 4" />
                            <path d="M3 11V9a4 4 0 0 1 4-4h14" />
                            <path d="M7 23l-4-4 4-4" />
                            <path d="M21 13v2a4 4 0 0 1-4 4H3" />
                        </svg>
                    </button>
                    <!-- Share -->
                    <button class="p-2">
                        <svg class="h-6 w-6 text-[#000000] dark:text-[#f5f5f5]" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5">
                            <line x1="22" y1="2" x2="11" y2="13" />
                            <polygon points="22 2 15 22 11 13 2 9 22 2" />
                        </svg>
                    </button>
                </div>
            </div>

        </div>
    </div>
</template>