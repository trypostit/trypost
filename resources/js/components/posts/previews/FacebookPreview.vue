<script setup lang="ts">
import { IconDots, IconPhoto } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import PostMediaPreview from '@/components/posts/previews/PostMediaPreview.vue';
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
    contentType?: string;
    meta?: Record<string, any>;
    charCount?: number;
    maxLength?: number;
    isValid?: boolean;
    validationMessage?: string;
    postedAt?: string | null;
}

const props = defineProps<Props>();

const postedAtLabel = computed(() =>
    date.formatFacebookPreview(props.postedAt, trans('common.just_now')),
);

// Content type helpers
const isReel = computed(() => props.contentType === 'facebook_reel');
const isStory = computed(() => props.contentType === 'facebook_story');
const isFeed = computed(() => !isReel.value && !isStory.value);

// Padding-bottom percentage = height/width. Reflects the user's chosen crop in
// the feed preview. `null` (original / unset) keeps the natural fill layout.
const ASPECT_PADDING: Record<string, number> = {
    '1:1': 100,
    '4:5': 125,
    '16:9': 56.25,
};
const feedAspectPadding = computed<number | null>(() => ASPECT_PADDING[props.meta?.aspect_ratio ?? ''] ?? null);

const feedMediaProps = {
    placeholderIcon: IconPhoto,
    dotActiveClass: 'bg-[#1877f2]',
    placeholderClass: 'w-full h-full flex items-center justify-center bg-[#f0f2f5] dark:bg-[#3a3b3c]',
};

// Format numbers like Facebook
const formatNumber = (num: number): string => {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    }
    if (num >= 1000) {
        return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    }
    return num.toString();
};

// Truncate content for display
const truncatedContent = computed(() => {
    if (!props.content) return '';
    if (props.content.length <= 100) return props.content;
    return props.content.substring(0, 100) + '...';
});

const displayName = computed(() => props.socialAccount.display_name || props.socialAccount.username);
</script>

<template>
    <div
        class="w-full h-full bg-[#f0f2f5] dark:bg-[#18191a] text-[#050505] dark:text-[#e4e6eb] overflow-hidden flex flex-col">

        <!-- ==================== FEED POST ==================== -->
        <template v-if="isFeed">
            <!-- Facebook Header -->
            <div
                class="flex-shrink-0 h-11 bg-white dark:bg-[#242526] border-b border-[#dddfe2] dark:border-[#3e4042] flex items-center px-3">
                <svg class="h-6 w-auto text-[#1877f2]" viewBox="0 0 36 36" fill="currentColor">
                    <path
                        d="M20.18 35.87C28.872 34.632 35.87 27.1 35.87 18c0-9.882-8.01-17.89-17.9-17.89C8.09.11.08 8.12.08 18c0 8.06 5.33 14.87 12.66 17.11v-12.3h-3.81V18h3.81v-3.64c0-3.76 2.24-5.84 5.67-5.84 1.64 0 3.36.29 3.36.29v3.69h-1.89c-1.86 0-2.45 1.16-2.45 2.35V18h4.16l-.67 4.81h-3.49v12.3c1.08-.17 2.13-.44 3.14-.79l.56-.45z" />
                </svg>
                <div class="ml-auto flex items-center gap-2">
                    <!-- Search -->
                    <div class="w-8 h-8 rounded-full bg-[#e4e6eb] dark:bg-[#3a3b3c] flex items-center justify-center">
                        <svg class="w-4 h-4 text-[#050505] dark:text-[#e4e6eb]" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.35-4.35" />
                        </svg>
                    </div>
                    <!-- Messenger -->
                    <div class="w-8 h-8 rounded-full bg-[#e4e6eb] dark:bg-[#3a3b3c] flex items-center justify-center">
                        <svg class="w-4 h-4 text-[#050505] dark:text-[#e4e6eb]" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M12 2C6.48 2 2 6.04 2 11c0 2.76 1.36 5.22 3.5 6.87V22l3.75-2.06c.98.27 2.01.43 3.08.43h.34c5.52 0 10-4.04 10-9s-4.48-9-10-9h-.67zm1.13 12.13l-2.54-2.71-4.96 2.71 5.46-5.79 2.6 2.71 4.9-2.71-5.46 5.79z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Post Content -->
            <div class="flex-1 flex flex-col min-h-0 overflow-hidden">
                <!-- Post Card -->
                <div class="bg-white dark:bg-[#242526] flex-1 flex flex-col min-h-0">
                    <!-- Post Header -->
                    <div class="flex-shrink-0 flex items-center px-3 py-2">
                        <div class="flex items-center gap-2 flex-1">
                            <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url" :alt="displayName"
                                class="w-10 h-10 rounded-full object-cover" />
                            <div v-else
                                class="w-10 h-10 rounded-full bg-[#1877f2] flex items-center justify-center text-white font-bold">
                                {{ displayName?.charAt(0).toUpperCase() }}
                            </div>
                            <div class="flex flex-col min-w-0">
                                <span class="text-[13px] font-semibold leading-tight">{{ displayName }}</span>
                                <div class="flex items-center gap-1 text-[11px] text-[#65676b] dark:text-[#b0b3b8]">
                                    <span>{{ postedAtLabel }}</span>
                                    <span>·</span>
                                    <!-- Globe icon -->
                                    <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor">
                                        <path
                                            d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zm-.5 14.5v-2h1v2a6.5 6.5 0 0 1-1 0zm4.7-1.2a6.5 6.5 0 0 1-3.2.7v-1h2a1 1 0 0 0 1-1V9.5h1.5a6.5 6.5 0 0 1-1.3 3.8zM14.5 8a6.5 6.5 0 0 1-.5 2.5H12V9a1 1 0 0 0-1-1h-1V6a1 1 0 0 0-1-1H6.5V3.5h2a1 1 0 0 0 1-1V1.7a6.5 6.5 0 0 1 5 6.3zM1.5 8a6.5 6.5 0 0 1 3-5.5V4a1 1 0 0 0 1 1h1v2a1 1 0 0 0 1 1h2v3H6a1 1 0 0 0-1 1v2.5A6.5 6.5 0 0 1 1.5 8z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <IconDots class="w-5 h-5 text-[#65676b] dark:text-[#b0b3b8]" />
                    </div>

                    <!-- Caption -->
                    <div v-if="content" class="flex-shrink-0 px-3 pb-2">
                        <p class="text-[14px] leading-snug">
                            {{ truncatedContent }}
                            <span v-if="content.length > 100"
                                class="text-[#65676b] dark:text-[#b0b3b8] cursor-pointer">See more</span>
                        </p>
                    </div>

                    <!-- Post Media - Aspect ratio matches user's chosen crop -->
                    <div
                        v-if="feedAspectPadding !== null"
                        class="relative w-full shrink-0 bg-black"
                        :style="{ paddingBottom: `${feedAspectPadding}%` }"
                    >
                        <div class="absolute inset-0">
                            <PostMediaPreview :media="media" v-bind="feedMediaProps" />
                        </div>
                    </div>
                    <div v-else class="flex-1 relative bg-black min-h-0">
                        <PostMediaPreview :media="media" v-bind="feedMediaProps" />
                    </div>

                    <!-- Reactions Bar -->
                    <div
                        class="flex-shrink-0 px-3 py-1.5 flex items-center justify-between border-b border-[#dddfe2] dark:border-[#3e4042]">
                        <div class="flex items-center gap-1">
                            <div class="flex -space-x-1">
                                <!-- Like reaction -->
                                <div
                                    class="w-[18px] h-[18px] rounded-full bg-[#1877f2] flex items-center justify-center ring-2 ring-white dark:ring-[#242526]">
                                    <svg class="w-2.5 h-2.5 text-white" viewBox="0 0 16 16" fill="currentColor">
                                        <path
                                            d="M1 7.75v6.5c0 .41.34.75.75.75h2.5a.75.75 0 0 0 .75-.75v-6.5a.75.75 0 0 0-.75-.75h-2.5a.75.75 0 0 0-.75.75zm13.47-2.58l-3.25.01-.42-1.48a3.51 3.51 0 0 0-3.38-2.56H7.2a.75.75 0 0 0-.7.47l-1.32 3.45a1.75 1.75 0 0 0-.1.56V12c0 .97.78 1.75 1.75 1.75h4.67a1.75 1.75 0 0 0 1.7-1.34l1.3-5.21a1.75 1.75 0 0 0-1.03-2.08z" />
                                    </svg>
                                </div>
                                <!-- Love reaction -->
                                <div
                                    class="w-[18px] h-[18px] rounded-full bg-[#f33e58] flex items-center justify-center ring-2 ring-white dark:ring-[#242526]">
                                    <svg class="w-2.5 h-2.5 text-white" viewBox="0 0 16 16" fill="currentColor">
                                        <path
                                            d="M8 15.25s-6.75-4.47-6.75-9A3.38 3.38 0 0 1 4.62.75 3.43 3.43 0 0 1 8 2.93 3.43 3.43 0 0 1 11.38.75a3.38 3.38 0 0 1 3.37 3.5c0 4.53-6.75 9-6.75 9z" />
                                    </svg>
                                </div>
                            </div>
                            <span class="text-[12px] text-[#65676b] dark:text-[#b0b3b8] ml-1">{{ formatNumber(156)
                                }}</span>
                        </div>
                        <span class="text-[12px] text-[#65676b] dark:text-[#b0b3b8]">23 comments · 5 shares</span>
                    </div>

                    <!-- Action Buttons -->
                    <div
                        class="flex-shrink-0 flex items-center px-2 py-1 border-b border-[#dddfe2] dark:border-[#3e4042]">
                        <button
                            class="flex-1 flex items-center justify-center gap-1.5 py-1.5 text-[#65676b] dark:text-[#b0b3b8] hover:bg-[#f0f2f5] dark:hover:bg-[#3a3b3c] rounded-lg transition-colors">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path
                                    d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" />
                            </svg>
                            <span class="text-[12px] font-semibold">Like</span>
                        </button>
                        <button
                            class="flex-1 flex items-center justify-center gap-1.5 py-1.5 text-[#65676b] dark:text-[#b0b3b8] hover:bg-[#f0f2f5] dark:hover:bg-[#3a3b3c] rounded-lg transition-colors">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                            </svg>
                            <span class="text-[12px] font-semibold">Comment</span>
                        </button>
                        <button
                            class="flex-1 flex items-center justify-center gap-1.5 py-1.5 text-[#65676b] dark:text-[#b0b3b8] hover:bg-[#f0f2f5] dark:hover:bg-[#3a3b3c] rounded-lg transition-colors">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8" />
                                <polyline points="16 6 12 2 8 6" />
                                <line x1="12" y1="2" x2="12" y2="15" />
                            </svg>
                            <span class="text-[12px] font-semibold">Share</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- ==================== REELS ==================== -->
        <template v-else-if="isReel">
            <div class="relative flex-1 bg-black overflow-hidden">
                <!-- Video/Media - Full screen -->
                <div class="absolute inset-0">
                    <PostMediaPreview
                        :media="media"
                        :placeholder-icon="IconPhoto"
                        :show-arrows="false"
                        :show-dots="false"
                        placeholder-class="w-full h-full flex items-center justify-center bg-[#18191a]"
                    />
                </div>

                <!-- Gradient overlay -->
                <div v-if="media.length > 0"
                    class="absolute inset-x-0 bottom-0 h-48 bg-gradient-to-t from-black/70 via-black/30 to-transparent pointer-events-none z-[5]" />

                <!-- Top Bar -->
                <div v-if="media.length > 0"
                    class="absolute top-1 left-0 right-0 px-3 flex items-center justify-between z-10">
                    <span class="text-white text-[14px] font-semibold drop-shadow-lg">Reels</span>
                    <svg class="w-5 h-5 text-white drop-shadow-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M16.5 7.5L21 3M21 3v4M21 3h-4" />
                    </svg>
                </div>

                <!-- Right Sidebar Actions -->
                <div v-if="media.length > 0"
                    class="absolute right-2 bottom-[68px] flex flex-col items-center gap-4 z-10">
                    <!-- Like -->
                    <div class="flex flex-col items-center">
                        <div class="w-10 h-10 flex items-center justify-center">
                            <svg class="w-7 h-7 text-white drop-shadow-lg" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
                            </svg>
                        </div>
                        <span class="text-white text-[10px] font-semibold drop-shadow">{{ formatNumber(12453) }}</span>
                    </div>
                    <!-- Comment -->
                    <div class="flex flex-col items-center">
                        <div class="w-10 h-10 flex items-center justify-center">
                            <svg class="w-7 h-7 text-white drop-shadow-lg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" />
                            </svg>
                        </div>
                        <span class="text-white text-[10px] font-semibold drop-shadow">{{ formatNumber(892) }}</span>
                    </div>
                    <!-- Share -->
                    <div class="flex flex-col items-center">
                        <div class="w-10 h-10 flex items-center justify-center">
                            <svg class="w-7 h-7 text-white drop-shadow-lg" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"
                                    transform="rotate(90 12 12)" />
                            </svg>
                        </div>
                    </div>
                    <!-- More -->
                    <div class="w-10 h-10 flex items-center justify-center">
                        <IconDots class="w-6 h-6 text-white drop-shadow-lg" />
                    </div>
                    <!-- Audio spinning icon -->
                    <div
                        class="w-9 h-9 rounded-lg bg-gradient-to-br from-neutral-600 to-neutral-900 border border-white/30 overflow-hidden animate-[spin_3s_linear_infinite]">
                        <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                            class="w-full h-full object-cover" />
                        <div v-else
                            class="w-full h-full bg-[#1877f2] flex items-center justify-center text-white font-bold text-xs">
                            {{ displayName?.charAt(0).toUpperCase() }}
                        </div>
                    </div>
                </div>

                <!-- Bottom Info -->
                <div v-if="media.length > 0" class="absolute left-0 right-14 bottom-[68px] px-3 z-10">
                    <div class="flex items-center gap-2 mb-1.5">
                        <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                            class="w-8 h-8 rounded-full object-cover border border-white/30" />
                        <div v-else
                            class="w-8 h-8 rounded-full bg-[#1877f2] flex items-center justify-center text-white font-bold text-[12px] border border-white/30">
                            {{ displayName?.charAt(0).toUpperCase() }}
                        </div>
                        <span class="text-white text-[13px] font-semibold drop-shadow-lg">{{ displayName }}</span>
                        <button class="px-3 py-1 bg-[#1877f2] text-white text-[11px] font-semibold rounded-md">
                            Follow
                        </button>
                    </div>
                    <p v-if="content" class="text-white text-[12px] drop-shadow-lg line-clamp-2 mb-1.5">
                        {{ content }}
                    </p>
                    <div class="flex items-center">
                        <div class="flex items-center gap-1 bg-white/20 backdrop-blur-sm rounded-full px-2 py-0.5">
                            <svg class="w-3 h-3 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" />
                            </svg>
                            <span class="text-white text-[10px] truncate max-w-[120px]">Original audio</span>
                        </div>
                    </div>
                </div>

                <!-- Bottom Navigation Bar -->
                <div
                    class="absolute bottom-0 left-0 right-0 h-[50px] bg-black/80 backdrop-blur-sm flex items-center justify-around px-4 z-20">
                    <!-- Home -->
                    <svg class="w-6 h-6 text-white/70" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                    </svg>
                    <!-- Friends -->
                    <svg class="w-6 h-6 text-white/70" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <circle cx="9" cy="7" r="4" />
                        <path d="M2 21v-2a4 4 0 0 1 4-4h6" />
                    </svg>
                    <!-- Reels - Active -->
                    <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <rect x="2" y="2" width="20" height="20" rx="4" />
                        <polygon points="10,7 10,17 17,12" fill="black" />
                    </svg>
                    <!-- Marketplace -->
                    <svg class="w-6 h-6 text-white/70" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                    </svg>
                    <!-- Profile -->
                    <div class="w-6 h-6 rounded-full overflow-hidden ring-[1.5px] ring-white/50">
                        <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                            class="w-full h-full object-cover" />
                        <div v-else class="w-full h-full bg-neutral-600" />
                    </div>
                </div>
            </div>
        </template>

        <!-- ==================== STORIES ==================== -->
        <template v-else-if="isStory">
            <div class="relative flex-1 bg-black overflow-hidden">
                <!-- Media - Full screen -->
                <div class="absolute inset-0">
                    <PostMediaPreview
                        :media="media"
                        :placeholder-icon="IconPhoto"
                        :show-arrows="false"
                        :show-dots="false"
                        placeholder-class="w-full h-full flex items-center justify-center bg-gradient-to-b from-[#1877f2]/50 to-[#833ab4]/50"
                    />
                </div>

                <!-- Progress Bars -->
                <div v-if="media.length > 0" class="absolute top-1 left-2 right-2 flex gap-0.5 z-10">
                    <div class="flex-1 h-[2px] bg-white/30 rounded-full overflow-hidden">
                        <div class="h-full w-1/3 bg-white rounded-full" />
                    </div>
                </div>

                <!-- User Info -->
                <div v-if="media.length > 0"
                    class="absolute top-3 left-0 right-0 px-3 flex items-center justify-between z-10">
                    <div class="flex items-center gap-2">
                        <div class="p-[2px] bg-[#1877f2] rounded-full">
                            <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                                class="w-8 h-8 rounded-full object-cover border-2 border-black" />
                            <div v-else
                                class="w-8 h-8 rounded-full bg-[#1877f2] flex items-center justify-center text-white font-bold text-[11px] border-2 border-black">
                                {{ displayName?.charAt(0).toUpperCase() }}
                            </div>
                        </div>
                        <span class="text-white text-[13px] font-semibold drop-shadow-lg">{{ displayName }}</span>
                        <span class="text-white/70 text-[11px] drop-shadow">2h</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <IconDots class="w-5 h-5 text-white drop-shadow-lg" />
                    </div>
                </div>

                <!-- Caption overlay (if content exists and media) -->
                <div v-if="media.length > 0 && content" class="absolute bottom-20 left-3 right-3 z-10">
                    <p
                        class="text-white text-[13px] text-center drop-shadow-lg bg-black/20 backdrop-blur-sm rounded-xl px-3 py-2 line-clamp-2">
                        {{ content }}
                    </p>
                </div>

                <!-- Bottom Reply Bar -->
                <div v-if="media.length > 0" class="absolute bottom-3 left-3 right-3 flex items-center gap-2 z-10">
                    <div class="flex-1 bg-white/20 backdrop-blur-md rounded-full px-4 py-2.5 border border-white/20">
                        <span class="text-white/80 text-[13px]">Reply to {{ displayName }}...</span>
                    </div>
                    <svg class="w-7 h-7 text-white drop-shadow-lg" viewBox="0 0 24 24" fill="currentColor">
                        <path
                            d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
                    </svg>
                    <svg class="w-7 h-7 text-white drop-shadow-lg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M2 21l21-9L2 3v7l15 2-15 2v7z" />
                    </svg>
                </div>
            </div>
        </template>
    </div>
</template>