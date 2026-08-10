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

// Mock reactions so the preview mirrors how a real Telegram channel post looks.
const sampleReactions = [
    { emoji: '❤️', count: 12, reacted: true },
    { emoji: '🔥', count: 7, reacted: false },
    { emoji: '👍', count: 4, reacted: false },
];
</script>

<template>
    <div
        class="flex h-full w-full flex-col overflow-hidden bg-[#a4bce0] dark:bg-[#0e1621]"
    >
        <!-- Header -->
        <div
            class="flex items-center gap-3 bg-white px-4 py-2.5 dark:bg-[#17212b]"
        >
            <img
                v-if="socialAccount.avatar_url"
                :src="socialAccount.avatar_url"
                :alt="socialAccount.display_label"
                class="h-9 w-9 rounded-full object-cover"
            />
            <div
                v-else
                class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-[#2aabee] to-[#229ed9] font-semibold text-white"
            >
                {{ getInitials(socialAccount.display_label) }}
            </div>
            <div class="min-w-0 flex-1">
                <div
                    class="truncate text-[15px] font-semibold text-[#1f232b] dark:text-white"
                >
                    {{ socialAccount.display_label }}
                </div>
                <div class="text-[13px] text-[#707991] dark:text-[#708499]">
                    channel
                </div>
            </div>
        </div>

        <!-- Chat area -->
        <div class="flex-1 overflow-y-auto px-3 py-4">
            <div
                class="max-w-[90%] overflow-hidden rounded-2xl rounded-bl-sm bg-white shadow-[0_1px_2px_rgba(0,0,0,0.12)] dark:bg-[#182533]"
            >
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

                <!-- Body -->
                <div class="px-3 py-2">
                    <div
                        v-if="content"
                        class="text-[15px] leading-[20px] whitespace-pre-wrap text-[#1f232b] dark:text-white"
                    >
                        {{ content }}
                    </div>

                    <!-- Reactions -->
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span
                            v-for="reaction in sampleReactions"
                            :key="reaction.emoji"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[13px] font-medium"
                            :class="
                                reaction.reacted
                                    ? 'bg-[#3390ec]/15 text-[#3390ec]'
                                    : 'bg-black/[0.06] text-[#5a6570] dark:bg-white/10 dark:text-[#aeb9c4]'
                            "
                        >
                            <span class="text-sm leading-none">{{
                                reaction.emoji
                            }}</span>
                            <span class="tabular-nums">{{
                                reaction.count
                            }}</span>
                        </span>
                    </div>

                    <!-- Meta -->
                    <div
                        class="mt-1.5 flex items-center justify-end gap-1 text-[12px] text-[#707991] dark:text-[#708499]"
                    >
                        <svg
                            class="h-3.5 w-3.5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"
                            />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        <span>1.2K</span>
                        <span class="ml-1">4:30 PM</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
