<script setup lang="ts">
import { IconPhoto, IconStack2 } from '@tabler/icons-vue';
import { computed } from 'vue';

import VideoPreview from "@/components/posts/previews/VideoPreview.vue";
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
    contentType?: string;
    meta?: Record<string, any>;
}

const props = defineProps<Props>();

const isCarousel = computed(() => props.contentType === 'pinterest_carousel');

const pinTitle = computed(() => (props.meta?.title as string | undefined) || '');
const pinLink = computed(() => (props.meta?.link as string | undefined) || '');
</script>

<template>
    <div class="w-full h-full bg-white dark:bg-[#121212] text-[#111111] dark:text-white overflow-hidden flex flex-col">
        <!-- Pinterest Header -->
        <div class="flex-shrink-0 h-11 border-b border-[#cdcdcd] dark:border-[#262626] flex items-center justify-center px-3">
            <!-- Pinterest Logo -->
            <svg class="h-6 w-6 text-[#e60023]" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 0C5.373 0 0 5.373 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738.098.119.112.224.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/>
            </svg>
        </div>

        <!-- Main Content - Pin Card -->
        <div class="flex-1 overflow-y-auto">
            <div class="p-3">
                <!-- Pin Card -->
                <div class="bg-white dark:bg-[#1e1e1e] rounded-2xl overflow-hidden shadow-[0_1px_8px_0_rgba(0,0,0,0.1)] group">
                    <!-- Pin Image/Video Area -->
                    <div class="relative">
                        <!-- Media Display -->
                        <div v-if="media.length > 0" class="relative">
                            <!-- Single Image or Video -->
                            <div v-if="!isCarousel || media.length === 1" class="relative">
                                <img
                                    v-if="!isVideoMedia(media[0])"
                                    :src="media[0].url"
                                    :alt="media[0].original_filename"
                                    class="w-full aspect-[2/3] object-cover"
                                />
                                <VideoPreview
                                    v-else
                                    :src="media[0].url"
                                    video-class="w-full aspect-[2/3] object-cover bg-black"
                                />
                                <!-- Video indicator -->
                                <div v-if="isVideoMedia(media[0])" class="absolute bottom-2 left-2 bg-black/60 text-white text-[10px] px-2 py-0.5 rounded-full flex items-center gap-1">
                                    <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="currentColor">
                                        <polygon points="5,3 19,12 5,21"/>
                                    </svg>
                                    Video
                                </div>
                            </div>

                            <!-- Carousel -->
                            <div v-else class="relative">
                                <div class="flex overflow-x-auto snap-x snap-mandatory scrollbar-hide">
                                    <div
                                        v-for="item in media"
                                        :key="item.id"
                                        class="relative shrink-0 w-full snap-center"
                                    >
                                        <img
                                            :src="item.url"
                                            :alt="item.original_filename"
                                            class="w-full aspect-[2/3] object-cover"
                                        />
                                    </div>
                                </div>
                                <!-- Carousel indicator -->
                                <div class="absolute bottom-2 left-1/2 -translate-x-1/2 flex gap-1">
                                    <div
                                        v-for="(_, index) in media"
                                        :key="index"
                                        class="w-1.5 h-1.5 rounded-full"
                                        :class="index === 0 ? 'bg-white' : 'bg-white/50'"
                                    />
                                </div>
                                <!-- Carousel count badge -->
                                <div class="absolute top-2 left-2 bg-black/60 text-white text-[10px] px-2 py-0.5 rounded-full flex items-center gap-1">
                                    <IconStack2 class="h-2.5 w-2.5" />
                                    {{ media.length }}
                                </div>
                            </div>

                            <!-- Pin Hover Actions -->
                            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors pointer-events-none">
                                <div class="absolute top-2 right-2 flex gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-auto">
                                    <button class="bg-[#e60023] hover:bg-[#ad081b] text-white rounded-full px-3 py-1.5 font-semibold text-[11px] transition-colors">
                                        Save
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Empty State - No Media -->
                        <div v-else class="aspect-[2/3] bg-[#efefef] dark:bg-[#2a2a2a] flex items-center justify-center">
                            <IconPhoto class="h-8 w-8 text-[#cdcdcd] dark:text-[#555]" />
                        </div>
                    </div>

                    <!-- Pin Info -->
                    <div class="p-2.5">
                        <div v-if="pinTitle" class="text-[14px] font-semibold text-[#111111] dark:text-[#e0e0e0] line-clamp-2 leading-[18px]">
                            {{ pinTitle }}
                        </div>
                        <div
                            v-if="content"
                            class="text-[13px] text-[#111111] dark:text-[#e0e0e0] line-clamp-2 leading-[17px]"
                            :class="pinTitle ? 'mt-1' : ''"
                        >
                            {{ content }}
                        </div>
                        <a
                            v-if="pinLink"
                            :href="pinLink"
                            class="mt-1 block truncate text-[11px] text-[#767676] dark:text-[#a0a0a0]"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ pinLink }}
                        </a>

                        <!-- User Info -->
                        <div class="flex items-center gap-2 mt-2">
                            <img
                                v-if="socialAccount.avatar_url"
                                :src="socialAccount.avatar_url"
                                :alt="socialAccount.display_name"
                                class="h-6 w-6 rounded-full object-cover"
                            />
                            <div v-else class="h-6 w-6 rounded-full bg-[#e60023] flex items-center justify-center text-white font-semibold text-[10px]">
                                {{ socialAccount.display_name?.charAt(0) }}
                            </div>
                            <span class="text-[12px] font-medium text-[#111111] dark:text-[#e0e0e0] truncate">
                                {{ socialAccount.display_name }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
