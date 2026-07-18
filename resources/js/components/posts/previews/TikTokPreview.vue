<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';
import { computed } from 'vue';

import VerticalMediaCanvas from '@/components/posts/previews/VerticalMediaCanvas.vue';
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

// Format engagement numbers like TikTok does
const formatNumber = (num: number): string => {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    }
    if (num >= 1000) {
        return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    }
    return num.toString();
};

const username = computed(() => props.socialAccount.username || props.socialAccount.display_name);
</script>

<template>
    <div class="w-full h-full bg-black text-white overflow-hidden flex flex-col relative">
        <!-- Video/Media Area - Full screen -->
        <VerticalMediaCanvas :media="media">
            <template #placeholder>
                <div class="flex h-full w-full items-center justify-center bg-[#161823]">
                    <svg class="h-12 w-12 text-white/20" viewBox="0 0 24 24" fill="currentColor">
                        <path
                            d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z" />
                    </svg>
                </div>
            </template>
        </VerticalMediaCanvas>

        <!-- Top Header (only when media exists) -->
        <div v-if="media.length > 0"
            class="absolute top-0 left-0 right-0 px-3 pt-1 pb-2 flex items-center justify-between z-10">
            <!-- Live button -->
            <button class="flex items-center gap-1 bg-white/10 backdrop-blur-sm rounded-full px-2.5 py-1">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3" />
                    <path
                        d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49m11.31-2.82a10 10 0 0 1 0 14.14m-14.14 0a10 10 0 0 1 0-14.14" />
                </svg>
                <span class="text-[11px] font-semibold">Live</span>
            </button>

            <!-- Following / For You tabs -->
            <div class="flex items-center gap-4">
                <button class="text-white/60 text-[15px] font-medium">Following</button>
                <button class="text-white text-[15px] font-bold relative">
                    For You
                    <div class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-6 h-[2px] bg-white rounded-full"></div>
                </button>
            </div>

            <!-- Search icon -->
            <button class="p-1">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.35-4.35" />
                </svg>
            </button>
        </div>

        <!-- Gradient overlay at bottom -->
        <div v-if="media.length > 0"
            class="absolute inset-x-0 bottom-0 h-48 bg-gradient-to-t from-black/60 via-black/30 to-transparent pointer-events-none z-[5]" />

        <!-- Right side actions -->
        <div v-if="media.length > 0" class="absolute right-2 bottom-[72px] flex flex-col items-center gap-3.5 z-10">
            <!-- Profile with follow button -->
            <div class="relative mb-1">
                <div class="w-11 h-11 rounded-full border-[1.5px] border-white overflow-hidden">
                    <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                        :alt="socialAccount.display_name" class="w-full h-full object-cover" />
                    <div v-else
                        class="w-full h-full bg-[#2f2f2f] flex items-center justify-center text-white font-bold text-sm">
                        {{ socialAccount.display_name?.charAt(0).toUpperCase() }}
                    </div>
                </div>
                <div
                    class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 w-5 h-5 bg-[#fe2c55] rounded-full flex items-center justify-center shadow-lg">
                    <IconPlus class="h-3 w-3 text-white" stroke-width="3" />
                </div>
            </div>

            <!-- Like -->
            <button class="flex flex-col items-center">
                <div class="w-10 h-10 flex items-center justify-center">
                    <svg class="w-7 h-7 text-white drop-shadow-md" viewBox="0 0 24 24" fill="currentColor">
                        <path
                            d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
                    </svg>
                </div>
                <span class="text-white text-[11px] font-medium drop-shadow">{{ formatNumber(1200000) }}</span>
            </button>

            <!-- Comment -->
            <button class="flex flex-col items-center">
                <div class="w-10 h-10 flex items-center justify-center">
                    <svg class="w-7 h-7 text-white drop-shadow-md" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" />
                    </svg>
                </div>
                <span class="text-white text-[11px] font-medium drop-shadow">{{ formatNumber(8523) }}</span>
            </button>

            <!-- Bookmark -->
            <button class="flex flex-col items-center">
                <div class="w-10 h-10 flex items-center justify-center">
                    <svg class="w-7 h-7 text-white drop-shadow-md" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17 3H7c-1.1 0-2 .9-2 2v16l7-3 7 3V5c0-1.1-.9-2-2-2z" />
                    </svg>
                </div>
                <span class="text-white text-[11px] font-medium drop-shadow">{{ formatNumber(45200) }}</span>
            </button>

            <!-- Share -->
            <button class="flex flex-col items-center">
                <div class="w-10 h-10 flex items-center justify-center">
                    <svg class="w-7 h-7 text-white drop-shadow-md" viewBox="0 0 24 24" fill="currentColor">
                        <path
                            d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"
                            transform="rotate(90 12 12)" />
                    </svg>
                </div>
                <span class="text-white text-[11px] font-medium drop-shadow">Share</span>
            </button>

        </div>

        <!-- Bottom info -->
        <div v-if="media.length > 0" class="absolute left-3 right-14 bottom-[72px] text-white z-10">
            <!-- Username -->
            <p class="font-bold text-[14px] drop-shadow-lg mb-1">@{{ username }}</p>

            <!-- Caption -->
            <div v-if="content" class="text-[13px] text-white drop-shadow-lg line-clamp-2 leading-[18px] mb-2">
                {{ content }}
            </div>

            <!-- Music ticker -->
            <div class="flex items-center gap-1.5">
                <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" />
                </svg>
                <div class="flex items-center overflow-hidden">
                    <p class="text-[12px] whitespace-nowrap">
                        <span class="inline-flex animate-marquee">
                            Original sound - {{ socialAccount.display_name }} &nbsp;&nbsp;&nbsp;
                        </span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Bottom Navigation Bar -->
        <div v-if="media.length > 0"
            class="absolute bottom-0 left-0 right-0 h-[52px] bg-black flex items-center justify-around px-2 z-20 border-t border-white/5">
            <!-- Home -->
            <button class="flex flex-col items-center justify-center py-1">
                <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 3L4 9v12h16V9l-8-6z" />
                </svg>
                <span class="text-[10px] text-white mt-0.5">Home</span>
            </button>

            <!-- Friends -->
            <button class="flex flex-col items-center justify-center py-1">
                <svg class="w-6 h-6 text-white/60" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" />
                </svg>
                <span class="text-[10px] text-white/60 mt-0.5">Friends</span>
            </button>

            <!-- Create (center button) -->
            <button class="flex items-center justify-center">
                <div class="w-11 h-7 relative">
                    <div class="absolute inset-0 bg-[#00f2ea] rounded-lg translate-x-[3px]"></div>
                    <div class="absolute inset-0 bg-[#fe2c55] rounded-lg -translate-x-[3px]"></div>
                    <div class="absolute inset-0 bg-white rounded-lg flex items-center justify-center">
                        <IconPlus class="w-5 h-5 text-black" stroke-width="2.5" />
                    </div>
                </div>
            </button>

            <!-- Inbox -->
            <button class="flex flex-col items-center justify-center py-1">
                <svg class="w-6 h-6 text-white/60" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" />
                </svg>
                <span class="text-[10px] text-white/60 mt-0.5">Inbox</span>
            </button>

            <!-- Profile -->
            <button class="flex flex-col items-center justify-center py-1">
                <svg class="w-6 h-6 text-white/60" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                </svg>
                <span class="text-[10px] text-white/60 mt-0.5">Profile</span>
            </button>
        </div>

        <!-- Video progress bar (above nav) -->
        <div v-if="media.length > 0" class="absolute bottom-[52px] left-0 right-0 h-[2px] bg-white/20 z-20">
            <div class="h-full w-1/3 bg-white rounded-full"></div>
        </div>
    </div>
</template>

<style scoped>
@keyframes marquee {
    0% {
        transform: translateX(0);
    }

    100% {
        transform: translateX(-50%);
    }
}

.animate-marquee {
    animation: marquee 8s linear infinite;
}
</style>