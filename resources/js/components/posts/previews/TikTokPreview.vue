<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';
import { computed } from 'vue';

import VerticalMediaCanvas from '@/components/posts/previews/VerticalMediaCanvas.vue';
import { getInitials } from '@/composables/useInitials';
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

const props = defineProps<Props>();
const hasPreviewContent = computed(
    () => props.media.length > 0 || props.content.trim().length > 0,
);

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
</script>

<template>
    <div
        class="relative flex h-full w-full flex-col overflow-hidden bg-black text-white"
    >
        <!-- Video/Media Area - Full screen -->
        <VerticalMediaCanvas :media="media">
            <template #placeholder>
                <div
                    class="flex h-full w-full items-center justify-center bg-[#161823]"
                >
                    <svg
                        class="h-12 w-12 text-white/20"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                    >
                        <path
                            d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z"
                        />
                    </svg>
                </div>
            </template>
        </VerticalMediaCanvas>

        <!-- Top Header -->
        <div
            v-if="hasPreviewContent"
            class="absolute top-0 right-0 left-0 z-10 flex items-center justify-between px-3 pt-1 pb-2"
        >
            <!-- Live button -->
            <button
                class="flex items-center gap-1 rounded-full bg-white/10 px-2.5 py-1 backdrop-blur-sm"
            >
                <svg
                    class="h-3.5 w-3.5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <circle cx="12" cy="12" r="3" />
                    <path
                        d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49m11.31-2.82a10 10 0 0 1 0 14.14m-14.14 0a10 10 0 0 1 0-14.14"
                    />
                </svg>
                <span class="text-[11px] font-semibold">Live</span>
            </button>

            <!-- Following / For You tabs -->
            <div class="flex items-center gap-4">
                <button class="text-[15px] font-medium text-white/60">
                    Following
                </button>
                <button class="relative text-[15px] font-bold text-white">
                    For You
                    <div
                        class="absolute -bottom-1 left-1/2 h-[2px] w-6 -translate-x-1/2 rounded-full bg-white"
                    ></div>
                </button>
            </div>

            <!-- Search icon -->
            <button class="p-1">
                <svg
                    class="h-5 w-5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.35-4.35" />
                </svg>
            </button>
        </div>

        <!-- Gradient overlay at bottom -->
        <div
            v-if="hasPreviewContent"
            class="pointer-events-none absolute inset-x-0 bottom-0 z-[5] h-48 bg-gradient-to-t from-black/60 via-black/30 to-transparent"
        />

        <!-- Right side actions -->
        <div
            v-if="hasPreviewContent"
            class="absolute right-2 bottom-[72px] z-10 flex flex-col items-center gap-3.5"
        >
            <!-- Profile with follow button -->
            <div class="relative mb-1">
                <div
                    class="h-11 w-11 overflow-hidden rounded-full border-[1.5px] border-white"
                >
                    <img
                        v-if="socialAccount.avatar_url"
                        :src="socialAccount.avatar_url"
                        :alt="socialAccount.display_label"
                        class="h-full w-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-full w-full items-center justify-center bg-[#2f2f2f] text-sm font-bold text-white"
                    >
                        {{ getInitials(socialAccount.display_label) }}
                    </div>
                </div>
                <div
                    class="absolute -bottom-1.5 left-1/2 flex h-5 w-5 -translate-x-1/2 items-center justify-center rounded-full bg-[#fe2c55] shadow-lg"
                >
                    <IconPlus class="h-3 w-3 text-white" stroke-width="3" />
                </div>
            </div>

            <!-- Like -->
            <button class="flex flex-col items-center">
                <div class="flex h-10 w-10 items-center justify-center">
                    <svg
                        class="h-7 w-7 text-white drop-shadow-md"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                    >
                        <path
                            d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"
                        />
                    </svg>
                </div>
                <span class="text-[11px] font-medium text-white drop-shadow">{{
                    formatNumber(1200000)
                }}</span>
            </button>

            <!-- Comment -->
            <button class="flex flex-col items-center">
                <div class="flex h-10 w-10 items-center justify-center">
                    <svg
                        class="h-7 w-7 text-white drop-shadow-md"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                    >
                        <path
                            d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"
                        />
                    </svg>
                </div>
                <span class="text-[11px] font-medium text-white drop-shadow">{{
                    formatNumber(8523)
                }}</span>
            </button>

            <!-- Bookmark -->
            <button class="flex flex-col items-center">
                <div class="flex h-10 w-10 items-center justify-center">
                    <svg
                        class="h-7 w-7 text-white drop-shadow-md"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                    >
                        <path
                            d="M17 3H7c-1.1 0-2 .9-2 2v16l7-3 7 3V5c0-1.1-.9-2-2-2z"
                        />
                    </svg>
                </div>
                <span class="text-[11px] font-medium text-white drop-shadow">{{
                    formatNumber(45200)
                }}</span>
            </button>

            <!-- Share -->
            <button class="flex flex-col items-center">
                <div class="flex h-10 w-10 items-center justify-center">
                    <svg
                        class="h-7 w-7 text-white drop-shadow-md"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                    >
                        <path
                            d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"
                            transform="rotate(90 12 12)"
                        />
                    </svg>
                </div>
                <span class="text-[11px] font-medium text-white drop-shadow"
                    >Share</span
                >
            </button>
        </div>

        <!-- Bottom info -->
        <div
            v-if="hasPreviewContent"
            class="absolute right-14 bottom-[72px] left-3 z-10 text-white"
        >
            <!-- Username -->
            <p class="mb-1 text-[14px] font-bold drop-shadow-lg">
                @{{ socialAccount.handle_label }}
            </p>

            <!-- Caption -->
            <div
                v-if="content"
                class="mb-2 line-clamp-2 text-[13px] leading-[18px] text-white drop-shadow-lg"
            >
                {{ content }}
            </div>

            <!-- Music ticker -->
            <div class="flex items-center gap-1.5">
                <svg
                    class="h-3 w-3 shrink-0"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                >
                    <path
                        d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"
                    />
                </svg>
                <div class="flex items-center overflow-hidden">
                    <p class="text-[12px] whitespace-nowrap">
                        <span class="animate-marquee inline-flex">
                            Original sound -
                            {{ socialAccount.display_label }} &nbsp;&nbsp;&nbsp;
                        </span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Bottom Navigation Bar -->
        <div
            v-if="hasPreviewContent"
            class="absolute right-0 bottom-0 left-0 z-20 flex h-[52px] items-center justify-around border-t border-white/5 bg-black px-2"
        >
            <!-- Home -->
            <button class="flex flex-col items-center justify-center py-1">
                <svg
                    class="h-6 w-6 text-white"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                >
                    <path d="M12 3L4 9v12h16V9l-8-6z" />
                </svg>
                <span class="mt-0.5 text-[10px] text-white">Home</span>
            </button>

            <!-- Friends -->
            <button class="flex flex-col items-center justify-center py-1">
                <svg
                    class="h-6 w-6 text-white/60"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                >
                    <path
                        d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"
                    />
                </svg>
                <span class="mt-0.5 text-[10px] text-white/60">Friends</span>
            </button>

            <!-- Create (center button) -->
            <button class="flex items-center justify-center">
                <div class="relative h-7 w-11">
                    <div
                        class="absolute inset-0 translate-x-[3px] rounded-lg bg-[#00f2ea]"
                    ></div>
                    <div
                        class="absolute inset-0 -translate-x-[3px] rounded-lg bg-[#fe2c55]"
                    ></div>
                    <div
                        class="absolute inset-0 flex items-center justify-center rounded-lg bg-white"
                    >
                        <IconPlus
                            class="h-5 w-5 text-black"
                            stroke-width="2.5"
                        />
                    </div>
                </div>
            </button>

            <!-- Inbox -->
            <button class="flex flex-col items-center justify-center py-1">
                <svg
                    class="h-6 w-6 text-white/60"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                >
                    <path
                        d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"
                    />
                </svg>
                <span class="mt-0.5 text-[10px] text-white/60">Inbox</span>
            </button>

            <!-- Profile -->
            <button class="flex flex-col items-center justify-center py-1">
                <svg
                    class="h-6 w-6 text-white/60"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                >
                    <path
                        d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"
                    />
                </svg>
                <span class="mt-0.5 text-[10px] text-white/60">Profile</span>
            </button>
        </div>

        <!-- Video progress bar (above nav) -->
        <div
            v-if="hasPreviewContent"
            class="absolute right-0 bottom-[52px] left-0 z-20 h-[2px] bg-white/20"
        >
            <div class="h-full w-1/3 rounded-full bg-white"></div>
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
