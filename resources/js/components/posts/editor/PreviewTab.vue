<script setup lang="ts">
import { IconDeviceMobile } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import PhoneMockup from '@/components/PhoneMockup.vue';
import { PlatformPreview } from '@/components/posts/previews';
import { Avatar } from '@/components/ui/avatar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import type { MediaItem } from '@/types/media';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    avatar_url: string | null;
}

interface PostPlatform {
    id: string;
    platform: string;
    platform_name: string | null;
    platform_avatar: string | null;
    content_type: string | null;
    social_account: SocialAccount | null;
}

const props = defineProps<{
    platforms: PostPlatform[];
    content: string;
    media: MediaItem[];
    platformContentTypes: Record<string, string>;
    platformMeta?: Record<string, Record<string, any>>;
}>();

const getPlatformAvatar = (pp: PostPlatform): string | null => pp.social_account?.avatar_url ?? pp.platform_avatar ?? null;
const getPlatformDisplayName = (pp: PostPlatform): string => pp.social_account?.display_name ?? pp.platform_name ?? pp.platform;

const activeId = ref<string | null>(props.platforms[0]?.id ?? null);

watch(
    () => props.platforms,
    (next) => {
        if (!next.find((pp) => pp.id === activeId.value)) {
            activeId.value = next[0]?.id ?? null;
        }
    },
);

const activePlatform = computed(() => props.platforms.find((pp) => pp.id === activeId.value) ?? null);
const activeContentType = computed((): string | undefined => {
    if (!activePlatform.value) return undefined;
    return props.platformContentTypes[activePlatform.value.id] ?? activePlatform.value.content_type ?? undefined;
});
</script>

<template>
    <div class="flex h-full flex-col">
        <div v-if="platforms.length > 1" class="border-b-2 border-foreground/10 px-4 py-3">
            <div class="flex flex-wrap gap-3">
                <TooltipProvider v-for="pp in platforms" :key="pp.id" :delay-duration="200">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="relative cursor-pointer transition-opacity"
                                :class="activeId === pp.id ? 'opacity-100' : 'opacity-40 hover:opacity-70'"
                                @click="activeId = pp.id"
                            >
                                <Avatar
                                    :src="getPlatformAvatar(pp)"
                                    :name="getPlatformDisplayName(pp)"
                                    class="size-9 shrink-0 rounded-full border-2"
                                    :class="activeId === pp.id ? 'border-foreground shadow-2xs' : 'border-foreground/20'"
                                />
                                <span class="absolute -bottom-1 -right-1 inline-flex size-5 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs lg:size-4">
                                    <img
                                        :src="getPlatformLogo(pp.platform)"
                                        :alt="pp.platform"
                                        class="size-full object-cover"
                                    />
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent>
                            <div class="space-y-0.5 text-xs">
                                <p class="font-semibold">{{ getPlatformDisplayName(pp) }}<span v-if="pp.social_account?.username" class="font-normal opacity-80">&nbsp;·&nbsp;@{{ pp.social_account.username }}</span></p>
                                <p class="opacity-70">{{ getPlatformLabel(pp.platform) }}</p>
                            </div>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
        </div>

        <div class="flex flex-1 items-start justify-center overflow-x-hidden px-4 pb-8 pt-6">
            <PhoneMockup v-if="activePlatform">
                <PlatformPreview
                    :platform="activePlatform.platform"
                    :content="content"
                    :media="media"
                    :social-account="activePlatform.social_account"
                    :content-type="activeContentType"
                    :meta="platformMeta?.[activePlatform.id] ?? {}"
                />
            </PhoneMockup>
            <div v-else class="flex flex-col items-center gap-3 text-center">
                <div class="inline-flex size-12 -rotate-3 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-2xs">
                    <IconDeviceMobile class="size-6 text-foreground" stroke-width="2" />
                </div>
                <p
                    class="text-base font-bold text-foreground"
                    style="font-family: var(--font-display)"
                >
                    {{ $t('posts.edit.preview_empty.title') }}
                </p>
                <p class="text-xs font-medium text-foreground/60">{{ $t('posts.edit.preview_empty.description') }}</p>
            </div>
        </div>
    </div>
</template>
