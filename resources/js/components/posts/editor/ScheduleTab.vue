<script setup lang="ts">
import { IconExternalLink, IconLoader2 } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelConfigurator from '@/components/ChannelConfigurator.vue';
import LabelBadge from '@/components/labels/LabelBadge.vue';
import { Badge } from '@/components/ui/badge';
import { usePageErrors } from '@/composables/usePageErrors';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { isVideo } from '@/lib/mediaType';
import type { PinterestBoard } from '@/types';
import type { Channel } from '@/types/channel';
import type { MediaItem } from '@/types/media';
import { PostPlatformStatus } from '@/types/post';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    avatar_url: string | null;
}

interface PostPlatform {
    id: string;
    social_account_id: string | null;
    enabled: boolean;
    platform: string;
    platform_name: string | null;
    platform_username: string | null;
    platform_avatar: string | null;
    content_type: string | null;
    status: string;
    platform_url: string | null;
    error_message: string | null;
    published_at: string | null;
    social_account: SocialAccount | null;
    meta?: Record<string, any>;
}

interface Label {
    id: string;
    name: string;
    color: string;
}

interface PlatformConfig {
    id: string;
    platform: string;
    maxContentLength: number;
    maxImages: number;
    allowedMediaTypes: string[];
    supportsTextOnly: boolean;
    requiresContent: boolean;
    publishConfig: Record<string, any>;
}

interface TikTokCreatorInfo {
    creator_nickname: string | null;
    creator_username: string | null;
    creator_avatar_url: string | null;
    privacy_level_options: string[];
    comment_disabled: boolean;
    duet_disabled: boolean;
    stitch_disabled: boolean;
    max_video_post_duration_sec: number | null;
}

const props = defineProps<{
    postPlatforms: PostPlatform[];
    selectedPlatformIds: string[];
    labels: Label[];
    selectedLabelIds: string[];
    isReadOnly: boolean;
    platformConfigs: Record<string, PlatformConfig>;
    platformMeta: Record<string, Record<string, any>>;
    platformContentTypes: Record<string, string>;
    platformIssues?: Record<string, string>;
    tiktokCreatorInfos?: Record<string, TikTokCreatorInfo> | null;
    pinterestBoards?: Record<string, PinterestBoard[]> | null;
    media?: MediaItem[];
}>();

const emit = defineEmits<{
    togglePlatform: [platformId: string];
    toggleLabel: [labelId: string];
    'update:platformMeta': [platformId: string, meta: Record<string, any>];
    'update:platformContentType': [platformId: string, contentType: string];
}>();

const getPublishConfig = (pp: PostPlatform): Record<string, any> | null =>
    pp.social_account_id ? props.platformConfigs[pp.social_account_id]?.publishConfig ?? null : null;

const getCreatorInfo = (pp: PostPlatform): TikTokCreatorInfo | null =>
    pp.social_account_id ? props.tiktokCreatorInfos?.[pp.social_account_id] ?? null : null;

const getBoards = (pp: PostPlatform): PinterestBoard[] =>
    pp.social_account_id ? props.pinterestBoards?.[pp.social_account_id] ?? [] : [];

const videoDurationSec = computed(() => {
    const video = props.media?.find((m) => isVideo(m));
    const duration = video?.meta?.duration;
    return typeof duration === 'number' ? Math.ceil(duration) : null;
});

const getPlatformDisplayName = (pp: PostPlatform): string =>
    pp.social_account?.display_name ?? pp.platform_name ?? pp.platform;

const getPlatformAvatar = (pp: PostPlatform): string | null =>
    pp.social_account?.avatar_url ?? pp.platform_avatar ?? null;

// Map pp.id → submit-array index, matching what Edit.vue sends as `platforms[i]`.
// Backend validation errors are keyed `platforms.{i}.content_type`, so we use this
// to surface the right error under each platform's variant picker.
const errors = usePageErrors();
const submitIndexByPpId = computed<Record<string, number>>(() => {
    const map: Record<string, number> = {};
    props.postPlatforms
        .filter((pp) => props.selectedPlatformIds.includes(pp.id))
        .forEach((pp, index) => { map[pp.id] = index; });
    return map;
});

const contentTypeErrorFor = (pp: PostPlatform): string | undefined => {
    const index = submitIndexByPpId.value[pp.id];
    if (index === undefined) return undefined;
    return errors.value[`platforms.${index}.content_type`];
};

const channels = computed<Channel[]>(() =>
    props.postPlatforms.map((pp) => ({
        id: pp.id,
        platform: pp.platform,
        displayName: getPlatformDisplayName(pp),
        username: pp.social_account?.username ?? pp.platform_username ?? null,
        avatarUrl: getPlatformAvatar(pp),
        socialAccount: pp.social_account,
        contentType: props.platformContentTypes[pp.id] ?? pp.content_type ?? '',
        meta: props.platformMeta[pp.id] ?? {},
        issue: props.platformIssues?.[pp.id] ?? null,
        status: pp.status,
        contentTypeError: contentTypeErrorFor(pp),
        publishConfig: getPublishConfig(pp),
        creatorInfo: getCreatorInfo(pp),
        boards: getBoards(pp),
    })),
);
</script>

<template>
    <div class="space-y-6">
        <div>
            <p class="mb-3 text-[11px] font-black uppercase tracking-widest text-foreground/60">
                {{ $t('posts.edit.publish_to') }}
            </p>
            <ChannelConfigurator
                :channels="channels"
                :selected-ids="selectedPlatformIds"
                :media="media ?? []"
                :video-duration-sec="videoDurationSec"
                :disabled="isReadOnly"
                @toggle="(id: string) => emit('togglePlatform', id)"
                @update:content-type="(id: string, value: string) => emit('update:platformContentType', id, value)"
                @update:meta="(id: string, value: Record<string, any>) => emit('update:platformMeta', id, value)"
            >
                <div v-if="postPlatforms.some(pp => pp.status !== PostPlatformStatus.Pending)">
                    <p class="mb-2 text-[11px] font-black uppercase tracking-widest text-foreground/60">
                        {{ $t('posts.edit.platform_status') }}
                    </p>
                    <div class="space-y-2">
                        <div
                            v-for="pp in postPlatforms.filter(p => p.enabled)"
                            :key="pp.id"
                            class="flex items-center justify-between rounded-xl border-2 border-foreground bg-card p-3 shadow-2xs"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="inline-flex size-5 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card">
                                    <img :src="getPlatformLogo(pp.platform)" :alt="pp.platform" class="size-full object-cover" />
                                </span>
                                <span class="truncate text-sm font-bold text-foreground">{{ getPlatformDisplayName(pp) }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge v-if="pp.status === PostPlatformStatus.Published" variant="success">{{ $t('posts.edit.status.published') }}</Badge>
                                <Badge v-else-if="pp.status === PostPlatformStatus.Publishing" variant="warning">
                                    <IconLoader2 class="size-3 animate-spin" />
                                    {{ $t('posts.edit.status.publishing') }}
                                </Badge>
                                <Badge v-else-if="pp.status === PostPlatformStatus.Failed" variant="destructive">{{ $t('posts.edit.status.failed') }}</Badge>
                                <a
                                    v-if="pp.platform_url"
                                    :href="pp.platform_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex size-7 items-center justify-center rounded-full border-2 border-foreground bg-card text-foreground shadow-2xs transition-transform hover:rotate-3 hover:bg-violet-100"
                                >
                                    <IconExternalLink class="size-3.5" stroke-width="2.5" />
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </ChannelConfigurator>
        </div>

        <div>
            <p class="mb-3 text-[11px] font-black uppercase tracking-widest text-foreground/60">
                {{ $t('posts.edit.labels') }}
            </p>
            <div v-if="labels.length > 0" class="flex flex-wrap gap-2">
                <LabelBadge
                    v-for="label in labels"
                    :key="label.id"
                    :label="label"
                    interactive
                    :selected="selectedLabelIds.includes(label.id)"
                    :disabled="isReadOnly"
                    @click="emit('toggleLabel', label.id)"
                />
            </div>
            <p v-else class="text-sm font-medium text-foreground/60">{{ $t('posts.edit.no_labels') }}</p>
        </div>
    </div>
</template>
