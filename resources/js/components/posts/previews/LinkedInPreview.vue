<script setup lang="ts">
import VideoPreview from "@/components/posts/previews/VideoPreview.vue";
import { getInitials } from '@/composables/useInitials';
import { isDocumentMedia, isVideoMedia } from '@/composables/useMedia';
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
        class="w-full h-full bg-white dark:bg-[#1b1f23] text-[#000000e6] dark:text-[#ffffffe6] overflow-hidden flex flex-col">
        <!-- Post Content -->
        <div class="flex-1 overflow-y-auto mt-4">
            <!-- Post Header -->
            <div class="px-4 pb-3">
                <div class="flex items-start gap-3">
                    <div class="relative flex-shrink-0">
                        <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                            :alt="socialAccount.display_label" class="h-12 w-12 rounded-full object-cover" />
                        <div v-else
                            class="h-12 w-12 rounded-full bg-[#0a66c2] flex items-center justify-center text-white font-semibold text-lg">
                            {{ getInitials(socialAccount.display_label) }}
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1">
                            <p class="font-semibold text-[15px] text-[#000000e6] dark:text-[#ffffffe6]">
                                {{ socialAccount.display_label }}
                            </p>
                            <span class="text-[14px] text-[#00000099] dark:text-[#ffffff99]">• 1st</span>
                        </div>
                        <p class="text-[13px] text-[#00000099] dark:text-[#ffffff99] leading-[18px] line-clamp-1">
                            CEO
                        </p>
                        <div class="flex items-center gap-1 text-[13px] text-[#00000099] dark:text-[#ffffff99]">
                            <span>1h</span>
                            <span>•</span>
                            <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="currentColor">
                                <path
                                    d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zm-.5 14.5v-2h1v2a6.5 6.5 0 0 1-1 0zm4.7-1.2a6.5 6.5 0 0 1-3.2.7v-1h2a1 1 0 0 0 1-1V9.5h1.5a6.5 6.5 0 0 1-1.3 3.8zM14.5 8a6.5 6.5 0 0 1-.5 2.5H12V9a1 1 0 0 0-1-1h-1V6a1 1 0 0 0-1-1H6.5V3.5h2a1 1 0 0 0 1-1V1.7a6.5 6.5 0 0 1 5 6.3zM1.5 8a6.5 6.5 0 0 1 3-5.5V4a1 1 0 0 0 1 1h1v2a1 1 0 0 0 1 1h2v3H6a1 1 0 0 0-1 1v2.5A6.5 6.5 0 0 1 1.5 8z" />
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div v-if="content" class="px-4 pb-3">
                <div class="text-[15px] text-[#000000e6] dark:text-[#ffffffe6] whitespace-pre-wrap leading-[22px]">
                    {{ content }}
                </div>
            </div>

            <!-- Media -->
            <div v-if="media.length > 0">
                <div class="grid" :class="{
                    'grid-cols-1': media.length === 1,
                    'grid-cols-2': media.length >= 2,
                }">
                    <div v-for="(item, index) in media.slice(0, 4)" :key="item.id"
                        class="relative overflow-hidden" :class="{
                            'col-span-2': media.length === 1,
                            'row-span-2': media.length === 3 && index === 0,
                            'aspect-square': media.length > 1,
                        }">
                        <div v-if="isDocumentMedia(item)" class="aspect-[4/5] w-full bg-[#f4f2ee] dark:bg-[#38434f]">
                            <iframe :src="`${item.url}#toolbar=0&navpanes=0&view=FitH`"
                                :title="item.original_filename || 'PDF document'"
                                class="h-full w-full border-0" loading="lazy" />
                        </div>
                        <img v-else-if="!isVideoMedia(item)" :src="item.url" :alt="item.original_filename"
                            class="w-full h-full object-cover" />
                        <VideoPreview v-else :src="item.url" video-class="w-full h-full object-cover bg-black" />
                        <div v-if="media.length > 4 && index === 3"
                            class="absolute inset-0 bg-black/70 flex items-center justify-center">
                            <span class="text-white text-3xl font-semibold">+{{ media.length - 4 }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Engagement Stats -->
            <div
                class="px-4 py-2.5 flex items-center justify-between text-[13px] text-[#00000099] dark:text-[#ffffff99]">
                <div class="flex items-center gap-1.5">
                    <div class="flex -space-x-0.5">
                        <!-- Like (blue) -->
                        <div class="h-[18px] w-[18px] rounded-full bg-[#0a66c2] flex items-center justify-center">
                            <svg class="w-2.5 h-2.5 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" />
                            </svg>
                        </div>
                        <!-- Love (red) -->
                        <div class="h-[18px] w-[18px] rounded-full bg-[#df704d] flex items-center justify-center">
                            <svg class="w-2.5 h-2.5 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
                            </svg>
                        </div>
                    </div>
                    <span>Tim and 8 others</span>
                </div>
                <span>1 comment</span>
            </div>

            <!-- Divider -->
            <div class="border-t border-[#e0dfdc] dark:border-[#38434f]" />

            <!-- Comment Input -->
            <div class="px-2 py-4 flex items-center gap-2">
                <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url" :alt="socialAccount.display_label"
                    class="h-8 w-8 rounded-full object-cover shrink-0" />
                <div v-else
                    class="h-8 w-8 rounded-full bg-[#0a66c2] flex items-center justify-center text-white font-semibold text-sm shrink-0">
                    {{ getInitials(socialAccount.display_label) }}
                </div>
                <div class="flex-1 bg-[#f4f2ee] dark:bg-[#38434f] rounded-full px-4 py-2">
                    <span class="text-[14px] text-[#00000099] dark:text-[#ffffff99]">Leave your thoughts...</span>
                </div>
                <button class="p-1">
                    <svg class="w-6 h-6 text-[#00000099] dark:text-[#ffffff99]" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="4" />
                        <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>