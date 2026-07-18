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

const username = computed(() => props.socialAccount.username || 'username');

const { card: linkCard, loading: linkCardLoading } = useLinkCard(
    toRef(props, 'content'),
    toRef(props, 'media'),
);
</script>

<template>
    <div class="w-full h-full bg-white dark:bg-black text-[#0f1419] dark:text-[#e7e9ea] overflow-hidden flex flex-col">
        <!-- Mobile Header -->
        <div
            class="flex-shrink-0 h-11 flex items-center justify-between px-4 border-b border-[#eff3f4] dark:border-[#2f3336]">
            <!-- Back arrow -->
            <button class="p-1 -ml-1">
                <svg class="h-5 w-5 text-[#0f1419] dark:text-[#e7e9ea]" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7" />
                </svg>
            </button>
            <!-- Title -->
            <span class="text-[17px] font-bold">Post</span>
            <!-- Tabs icon -->
            <button class="p-1 -mr-1">
                <svg class="h-5 w-5 text-[#0f1419] dark:text-[#e7e9ea]" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M4 4.5C4 3.12 5.12 2 6.5 2h11C18.88 2 20 3.12 20 4.5v15c0 1.38-1.12 2.5-2.5 2.5h-11C5.12 22 4 20.88 4 19.5v-15zM6.5 4c-.28 0-.5.22-.5.5v15c0 .28.22.5.5.5h11c.28 0 .5-.22.5-.5v-15c0-.28-.22-.5-.5-.5h-11zM8 7h8v2H8V7z" />
                </svg>
            </button>
        </div>

        <!-- Main Content -->
        <div class="flex-1 overflow-y-auto">
            <!-- Post -->
            <div class="px-4 pt-3">
                <!-- Author row -->
                <div class="flex items-center gap-2.5">
                    <!-- Avatar -->
                    <img v-if="socialAccount.avatar_url" :src="socialAccount.avatar_url"
                        :alt="socialAccount.display_name" class="h-10 w-10 rounded-full object-cover flex-shrink-0" />
                    <div v-else
                        class="h-10 w-10 rounded-full bg-[#1d9bf0] flex items-center justify-center text-white font-bold flex-shrink-0">
                        {{ socialAccount.display_name?.charAt(0).toUpperCase() }}
                    </div>

                    <!-- Name + Username column -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1">
                            <span class="font-bold text-[15px] text-[#0f1419] dark:text-[#e7e9ea] truncate">
                                {{ socialAccount.display_name }}
                            </span>
                            <!-- Verified Badge -->
                            <svg class="h-[18px] w-[18px] text-[#1d9bf0] flex-shrink-0" viewBox="0 0 22 22"
                                fill="currentColor">
                                <path
                                    d="M20.396 11c-.018-.646-.215-1.275-.57-1.816-.354-.54-.852-.972-1.438-1.246.223-.607.27-1.264.14-1.897-.131-.634-.437-1.218-.882-1.687-.47-.445-1.053-.75-1.687-.882-.633-.13-1.29-.083-1.897.14-.273-.587-.704-1.086-1.245-1.44S11.647 1.62 11 1.604c-.646.017-1.273.213-1.813.568s-.969.854-1.24 1.44c-.608-.223-1.267-.272-1.902-.14-.635.13-1.22.436-1.69.882-.445.47-.749 1.055-.878 1.688-.13.633-.08 1.29.144 1.896-.587.274-1.087.705-1.443 1.245-.356.54-.555 1.17-.574 1.817.02.647.218 1.276.574 1.817.356.54.856.972 1.443 1.245-.224.606-.274 1.263-.144 1.896.13.634.433 1.218.877 1.688.47.443 1.054.747 1.687.878.633.132 1.29.084 1.897-.136.274.586.705 1.084 1.246 1.439.54.354 1.17.551 1.816.569.647-.016 1.276-.213 1.817-.567s.972-.854 1.245-1.44c.604.239 1.266.296 1.903.164.636-.132 1.22-.447 1.68-.907.46-.46.776-1.044.908-1.681s.075-1.299-.165-1.903c.586-.274 1.084-.705 1.439-1.246.354-.54.551-1.17.569-1.816zM9.662 14.85l-3.429-3.428 1.293-1.302 2.072 2.072 4.4-4.794 1.347 1.246z" />
                            </svg>
                        </div>
                        <div class="text-[14px] text-[#536471] dark:text-[#71767b]">
                            @{{ username }}
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div v-if="content" class="mt-3 text-[17px] text-[#0f1419] dark:text-[#e7e9ea] whitespace-pre-wrap leading-[22px]">
                    {{ content }}
                </div>

                <!-- Media -->
                <div v-if="media.length > 0" class="mt-3">
                    <div class="rounded-2xl overflow-hidden border border-[#cfd9de] dark:border-[#2f3336]" :class="{
                        'grid grid-cols-2 gap-0.5': media.length >= 2,
                    }">
                        <div v-for="(item, index) in media.slice(0, 4)" :key="item.id"
                            class="relative overflow-hidden" :class="{
                                'aspect-[16/9]': media.length === 1,
                                'aspect-square': media.length > 1,
                            }">
                            <img v-if="!isVideoMedia(item)" :src="item.url" :alt="item.original_filename"
                                class="w-full h-full object-cover" />
                            <VideoPreview v-else :src="item.url" video-class="w-full h-full object-cover bg-black" />
                            <!-- Video duration badge -->
                            <div v-if="isVideoMedia(item)"
                                class="absolute bottom-2 left-2 bg-black/70 text-white text-[13px] px-1.5 py-0.5 rounded">
                                0:27
                            </div>
                            <div v-if="media.length > 4 && index === 3"
                                class="absolute inset-0 bg-black/60 flex items-center justify-center">
                                <span class="text-white text-2xl font-bold">+{{ media.length - 4 }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Link preview card -->
                <div
                    v-if="media.length === 0 && linkCardLoading"
                    class="mt-3 h-24 animate-pulse rounded-2xl border border-[#cfd9de] bg-[#f7f9f9] dark:border-[#2f3336] dark:bg-[#16181c]"
                ></div>
                <LinkCard v-else-if="media.length === 0 && linkCard" :card="linkCard" />

                <!-- Timestamp & Views -->
                <div class="mt-3 text-[15px] text-[#536471] dark:text-[#71767b]">
                    <span>4:21 PM · Jan 20, 2026</span>
                    <span class="mx-1">·</span>
                    <span class="text-[#0f1419] dark:text-[#e7e9ea] font-bold">3.5M</span>
                    <span> Views</span>
                </div>
            </div>

            <!-- Actions -->
            <div class="border-t border-[#eff3f4] dark:border-[#2f3336] mt-3">
                <div class="flex items-center justify-around py-1">
                    <!-- Comment -->
                    <button class="flex items-center gap-1 p-2 group">
                        <svg class="h-5 w-5 text-[#536471] dark:text-[#71767b] group-hover:text-[#1d9bf0]"
                            viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M1.751 10c0-4.42 3.584-8 8.005-8h4.366c4.49 0 8.129 3.64 8.129 8.13 0 2.96-1.607 5.68-4.196 7.11l-8.054 4.46v-3.69h-.067c-4.49.1-8.183-3.51-8.183-8.01zm8.005-6c-3.317 0-6.005 2.69-6.005 6 0 3.37 2.77 6.08 6.138 6.01l.351-.01h1.761v2.3l5.087-2.81c1.951-1.08 3.163-3.13 3.163-5.36 0-3.39-2.744-6.13-6.129-6.13H9.756z" />
                        </svg>
                        <span
                            class="text-[13px] text-[#536471] dark:text-[#71767b] group-hover:text-[#1d9bf0]">1.2K</span>
                    </button>
                    <!-- Repost -->
                    <button class="flex items-center gap-1 p-2 group">
                        <svg class="h-5 w-5 text-[#536471] dark:text-[#71767b] group-hover:text-[#00ba7c]"
                            viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M4.5 3.88l4.432 4.14-1.364 1.46L5.5 7.55V16c0 1.1.896 2 2 2H13v2H7.5c-2.209 0-4-1.79-4-4V7.55L1.432 9.48.068 8.02 4.5 3.88zM16.5 6H11V4h5.5c2.209 0 4 1.79 4 4v8.45l2.068-1.93 1.364 1.46-4.432 4.14-4.432-4.14 1.364-1.46 2.068 1.93V8c0-1.1-.896-2-2-2z" />
                        </svg>
                        <span
                            class="text-[13px] text-[#536471] dark:text-[#71767b] group-hover:text-[#00ba7c]">2.5K</span>
                    </button>
                    <!-- Like -->
                    <button class="flex items-center gap-1 p-2 group">
                        <svg class="h-5 w-5 text-[#536471] dark:text-[#71767b] group-hover:text-[#f91880]"
                            viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M16.697 5.5c-1.222-.06-2.679.51-3.89 2.16l-.805 1.09-.806-1.09C9.984 6.01 8.526 5.44 7.304 5.5c-1.243.07-2.349.78-2.91 1.91-.552 1.12-.633 2.78.479 4.82 1.074 1.97 3.257 4.27 7.129 6.61 3.87-2.34 6.052-4.64 7.126-6.61 1.111-2.04 1.03-3.7.477-4.82-.561-1.13-1.666-1.84-2.908-1.91zm4.187 7.69c-1.351 2.48-4.001 5.12-8.379 7.67l-.503.3-.504-.3c-4.379-2.55-7.029-5.19-8.382-7.67-1.36-2.5-1.41-4.86-.514-6.67.887-1.79 2.647-2.91 4.601-3.01 1.651-.09 3.368.56 4.798 2.01 1.429-1.45 3.146-2.1 4.796-2.01 1.954.1 3.714 1.22 4.601 3.01.896 1.81.846 4.17-.514 6.67z" />
                        </svg>
                        <span
                            class="text-[13px] text-[#536471] dark:text-[#71767b] group-hover:text-[#f91880]">11K</span>
                    </button>
                    <!-- Bookmark -->
                    <button class="flex items-center gap-1 p-2 group">
                        <svg class="h-5 w-5 text-[#536471] dark:text-[#71767b] group-hover:text-[#1d9bf0]"
                            viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M4 4.5C4 3.12 5.119 2 6.5 2h11C18.881 2 20 3.12 20 4.5v18.44l-8-5.71-8 5.71V4.5zM6.5 4c-.276 0-.5.22-.5.5v14.56l6-4.29 6 4.29V4.5c0-.28-.224-.5-.5-.5h-11z" />
                        </svg>
                        <span
                            class="text-[13px] text-[#536471] dark:text-[#71767b] group-hover:text-[#1d9bf0]">4.6K</span>
                    </button>
                </div>
            </div>

        </div>
    </div>
</template>