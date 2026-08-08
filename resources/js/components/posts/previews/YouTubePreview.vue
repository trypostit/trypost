<script setup lang="ts">
import VideoPreview from "@/components/posts/previews/VideoPreview.vue";
import { getInitials } from '@/composables/useInitials';
import { isVideoMedia } from '@/composables/useMedia';
import type { MediaItem } from '@/types/media';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
}

interface Props {
    socialAccount: SocialAccount;
    content: string;
    media: MediaItem[];
}

defineProps<Props>();

// Format engagement numbers like YouTube does
const formatNumber = (num: number): string => {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    }
    if (num >= 1000) {
        return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    }
    return num.toString();
};
</script>

<template>
    <div class="w-full h-full bg-[#0f0f0f] text-white overflow-hidden flex flex-col relative">
        <!-- Video/Media Area - Full screen -->
        <div class="absolute inset-0">
            <!-- Video content -->
            <div v-if="media.length > 0 && isVideoMedia(media[0])" class="w-full h-full">
                <VideoPreview :src="media[0].url" />
            </div>
            <div v-else-if="media.length > 0" class="w-full h-full">
                <img :src="media[0].url" :alt="media[0].original_filename" class="w-full h-full object-cover" />
            </div>
            <div v-else class="w-full h-full flex items-center justify-center bg-[#0f0f0f]">
                <!-- YouTube Shorts icon -->
                <svg class="h-12 w-12 text-white/20" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M10 14.65v-5.3L15 12l-5 2.65zm7.77-4.33c-.77-.32-1.2-.5-1.2-.5L18 9.06c1.84-.96 2.53-3.23 1.56-5.06s-3.24-2.53-5.07-1.56L6 6.94c-1.29.68-2.07 2.04-2 3.49.07 1.42.93 2.67 2.22 3.25.03.01 1.2.5 1.2.5L6 14.93c-1.83.97-2.53 3.24-1.56 5.07.97 1.83 3.24 2.53 5.07 1.56l8.5-4.5c1.29-.68 2.06-2.04 1.99-3.49-.07-1.42-.94-2.68-2.23-3.25z" />
                </svg>
            </div>
        </div>

        <!-- Top Header -->
        <div v-if="media.length > 0"
            class="absolute top-0 left-0 right-0 px-3 pt-1 flex items-center justify-between z-10">
            <!-- Shorts logo -->
            <div class="flex items-center gap-1">
                <svg class="h-5 w-auto" viewBox="0 0 24 24" fill="#ff0000">
                    <path
                        d="M10 14.65v-5.3L15 12l-5 2.65zm7.77-4.33c-.77-.32-1.2-.5-1.2-.5L18 9.06c1.84-.96 2.53-3.23 1.56-5.06s-3.24-2.53-5.07-1.56L6 6.94c-1.29.68-2.07 2.04-2 3.49.07 1.42.93 2.67 2.22 3.25.03.01 1.2.5 1.2.5L6 14.93c-1.83.97-2.53 3.24-1.56 5.07.97 1.83 3.24 2.53 5.07 1.56l8.5-4.5c1.29-.68 2.06-2.04 1.99-3.49-.07-1.42-.94-2.68-2.23-3.25z" />
                </svg>
                <span class="text-white font-medium text-[14px]">Shorts</span>
            </div>

            <!-- Search and camera icons -->
            <div class="flex items-center gap-4">
                <button>
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.35-4.35" />
                    </svg>
                </button>
                <button>
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path
                            d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Gradient overlay at bottom -->
        <div v-if="media.length > 0"
            class="absolute inset-x-0 bottom-0 h-56 bg-gradient-to-t from-black/80 via-black/40 to-transparent pointer-events-none z-[5]" />

        <!-- Right side actions -->
        <div v-if="media.length > 0" class="absolute right-2 bottom-[72px] flex flex-col items-center gap-3 z-10">
            <!-- Like -->
            <button class="flex flex-col items-center">
                <div class="w-11 h-11 bg-[#272727]/80 backdrop-blur-sm rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path
                            d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z" />
                    </svg>
                </div>
                <span class="text-white text-[11px] mt-1 font-medium">{{ formatNumber(12400) }}</span>
            </button>

            <!-- Dislike -->
            <button class="flex flex-col items-center">
                <div class="w-11 h-11 bg-[#272727]/80 backdrop-blur-sm rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path
                            d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z" />
                    </svg>
                </div>
                <span class="text-white text-[11px] mt-1 font-medium">Dislike</span>
            </button>

            <!-- Comments -->
            <button class="flex flex-col items-center">
                <div class="w-11 h-11 bg-[#272727]/80 backdrop-blur-sm rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M21.99 4c0-1.1-.89-2-1.99-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h14l4 4-.01-18z" />
                    </svg>
                </div>
                <span class="text-white text-[11px] mt-1 font-medium">{{ formatNumber(234) }}</span>
            </button>

            <!-- Share -->
            <button class="flex flex-col items-center">
                <div class="w-11 h-11 bg-[#272727]/80 backdrop-blur-sm rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path
                            d="M13.12 2.06L7.58 7.6c-.37.37-.58.88-.58 1.41V19c0 1.1.9 2 2 2h9c.8 0 1.52-.48 1.84-1.21l3.26-7.61C23.94 10.2 22.49 8 20.34 8h-5.65l.95-4.58c.1-.5-.05-1.01-.41-1.37-.59-.58-1.53-.58-2.11.01zM3 21c1.1 0 2-.9 2-2v-8c0-1.1-.9-2-2-2s-2 .9-2 2v8c0 1.1.9 2 2 2z" />
                    </svg>
                </div>
                <span class="text-white text-[11px] mt-1 font-medium">Share</span>
            </button>

            <!-- Remix -->
            <button class="flex flex-col items-center">
                <div class="w-11 h-11 bg-[#272727]/80 backdrop-blur-sm rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path
                            d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" />
                    </svg>
                </div>
                <span class="text-white text-[11px] mt-1 font-medium">Remix</span>
            </button>
        </div>

        <!-- Bottom info -->
        <div v-if="media.length > 0" class="absolute left-3 right-14 bottom-[72px] text-white z-10">
            <!-- Channel info and subscribe -->
            <div class="flex items-center gap-2 mb-2">
                <div class="w-8 h-8 rounded-full overflow-hidden flex-shrink-0">
                    <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                        :alt="socialAccount.display_label" class="w-full h-full object-cover" />
                    <div v-else
                        class="w-full h-full bg-[#ff0000] flex items-center justify-center text-white font-bold text-[10px]">
                        {{ getInitials(socialAccount.display_label) }}
                    </div>
                </div>
                <span class="text-[13px] font-medium">@{{ socialAccount.handle_label }}</span>
                <button class="ml-auto bg-white text-black text-[12px] font-medium px-3 py-1 rounded-full">
                    Subscribe
                </button>
            </div>

            <!-- Video title -->
            <div class="text-[13px] text-white line-clamp-2 leading-[18px] mb-1.5">
                {{ content || 'No title' }}
            </div>

            <!-- Music info -->
            <div class="flex items-center gap-1.5">
                <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" />
                </svg>
                <span class="text-[11px] text-white/80 truncate">{{ socialAccount.display_label }} - Original
                    audio</span>
            </div>
        </div>

        <!-- Bottom Navigation Bar -->
        <div v-if="media.length > 0"
            class="absolute bottom-0 left-0 right-0 h-[52px] bg-[#0f0f0f] flex items-center justify-around px-1 z-20 border-t border-white/10">
            <!-- Home -->
            <button class="flex flex-col items-center justify-center py-1 min-w-[48px]">
                <svg class="w-5 h-5 text-white/60" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 3L4 9v12h5v-7h6v7h5V9l-8-6z" />
                </svg>
                <span class="text-[9px] text-white/60 mt-0.5">Home</span>
            </button>

            <!-- Shorts (active) -->
            <button class="flex flex-col items-center justify-center py-1 min-w-[48px]">
                <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M10 14.65v-5.3L15 12l-5 2.65zm7.77-4.33c-.77-.32-1.2-.5-1.2-.5L18 9.06c1.84-.96 2.53-3.23 1.56-5.06s-3.24-2.53-5.07-1.56L6 6.94c-1.29.68-2.07 2.04-2 3.49.07 1.42.93 2.67 2.22 3.25.03.01 1.2.5 1.2.5L6 14.93c-1.83.97-2.53 3.24-1.56 5.07.97 1.83 3.24 2.53 5.07 1.56l8.5-4.5c1.29-.68 2.06-2.04 1.99-3.49-.07-1.42-.94-2.68-2.23-3.25z" />
                </svg>
                <span class="text-[9px] text-white mt-0.5">Shorts</span>
            </button>

            <!-- Create -->
            <button class="flex items-center justify-center">
                <div class="w-10 h-7 bg-white rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-black" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                    </svg>
                </div>
            </button>

            <!-- Subscriptions -->
            <button class="flex flex-col items-center justify-center py-1 min-w-[48px]">
                <svg class="w-5 h-5 text-white/60" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M4 6h16v2H4zm2-4h12v2H6zm14 8H4c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2v-8c0-1.1-.9-2-2-2zm-8 12.5c-2.49 0-4.5-2.01-4.5-4.5s2.01-4.5 4.5-4.5 4.5 2.01 4.5 4.5-2.01 4.5-4.5 4.5zm0-6.5l-2 2.5h4l-2-2.5z" />
                </svg>
                <span class="text-[9px] text-white/60 mt-0.5">Subscriptions</span>
            </button>

            <!-- Library -->
            <button class="flex flex-col items-center justify-center py-1 min-w-[48px]">
                <svg class="w-5 h-5 text-white/60" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8 12.5v-9l6 4.5-6 4.5z" />
                </svg>
                <span class="text-[9px] text-white/60 mt-0.5">Library</span>
            </button>
        </div>

        <!-- Video progress bar (above nav) -->
        <div v-if="media.length > 0" class="absolute bottom-[52px] left-0 right-0 h-[3px] bg-white/20 z-20">
            <div class="h-full w-2/5 bg-[#ff0000]"></div>
        </div>
    </div>
</template>