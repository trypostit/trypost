<script setup lang="ts">
import { Head, InfiniteScroll } from '@inertiajs/vue3';
import {
    IconBoxMultipleFilled,
    IconLayoutGrid,
    IconMovie,
} from '@tabler/icons-vue';
import { ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import MediaLightbox from '@/components/media/MediaLightbox.vue';
import ScheduleViewSwitch from '@/components/posts/ScheduleViewSwitch.vue';
import NewPostButton from '@/components/publish/NewPostButton.vue';
import PublishHeader from '@/components/publish/PublishHeader.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { isVideo } from '@/lib/mediaType';
import { videoFrameUrl } from '@/lib/videoFrame';
import {
    calendar as channelCalendar,
    grid,
    publish,
} from '@/routes/app/channels';
import { channelName } from '@/types/channel';
import type { MediaItem } from '@/types/media';
import type { PublishChannel } from '@/types/publish';

interface GridTile {
    id: string;
    published_at: string | null;
    kind: 'single' | 'carousel' | 'reel';
    items: Pick<MediaItem, 'url' | 'type' | 'mime_type' | 'meta'>[];
}

defineProps<{
    channel: PublishChannel;
    posts: { data: GridTile[] };
}>();

const lightboxOpen = ref(false);
const lightboxItems = ref<GridTile['items']>([]);

const openLightbox = (tile: GridTile): void => {
    lightboxItems.value = tile.items;
    lightboxOpen.value = true;
};
</script>

<template>
    <Head :title="channelName(channel)" />

    <AppLayout full-width>
        <template #header>
            <PublishHeader :channel="channel" />
        </template>

        <template #header-actions>
            <div class="flex items-center gap-2">
                <ScheduleViewSwitch
                    active-view="grid"
                    :list-href="publish.url(channel.id)"
                    :calendar-href="
                        channelCalendar.url({
                            account: channel.id,
                            view: 'month',
                        })
                    "
                    :grid-href="grid.url(channel.id)"
                />
                <NewPostButton :social-account-ids="[channel.id]" />
            </div>
        </template>

        <div
            class="flex min-h-0 flex-1 flex-col overflow-hidden"
            data-testid="channel-grid-page"
        >
            <div
                class="mx-4 mt-2 shrink-0 border-b border-border-strong md:mx-8"
                data-testid="channel-grid-divider"
            />

            <div
                class="min-h-0 min-w-0 flex-1 overflow-auto overscroll-contain px-4 pt-6 pb-18 md:px-8"
                data-testid="channel-grid-scroll"
            >
                <EmptyState
                    v-if="!posts.data.length"
                    :icon="IconLayoutGrid"
                    :title="$t('channels.grid.empty_title')"
                    :description="$t('channels.grid.empty_description')"
                />

                <InfiniteScroll
                    v-else
                    data="posts"
                    items-element="#channel-grid-body"
                    preserve-url
                >
                    <TooltipProvider :delay-duration="0">
                        <ul
                            id="channel-grid-body"
                            class="mx-auto grid w-full max-w-[930px] grid-cols-3 gap-0.5"
                            data-testid="channel-grid"
                        >
                            <li v-for="tile in posts.data" :key="tile.id">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <button
                                            type="button"
                                            class="relative block aspect-[3/4] w-full overflow-hidden bg-secondary focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring disabled:cursor-default"
                                            :disabled="!tile.items.length"
                                            :aria-label="$t('common.media_lightbox.open')"
                                            :data-testid="`grid-tile-${tile.id}`"
                                            @click="openLightbox(tile)"
                                        >
                                            <template v-if="tile.items[0]">
                                                <video
                                                    v-if="isVideo(tile.items[0])"
                                                    :src="
                                                        videoFrameUrl(tile.items[0])
                                                    "
                                                    class="size-full object-cover"
                                                    muted
                                                    playsinline
                                                    preload="metadata"
                                                />
                                                <img
                                                    v-else
                                                    :src="tile.items[0].url"
                                                    alt=""
                                                    class="size-full object-cover"
                                                    loading="lazy"
                                                />
                                            </template>
                                            <IconMovie
                                                v-if="tile.kind === 'reel'"
                                                class="absolute top-2 right-2 size-5 text-white drop-shadow-md"
                                                :aria-label="$t('channels.grid.reel')"
                                                role="img"
                                                :data-testid="`grid-tile-reel-${tile.id}`"
                                            />
                                            <IconBoxMultipleFilled
                                                v-else-if="tile.kind === 'carousel'"
                                                class="absolute top-2 right-2 size-5 text-white drop-shadow-md"
                                                :aria-label="
                                                    $t('channels.grid.carousel')
                                                "
                                                role="img"
                                                :data-testid="`grid-tile-carousel-${tile.id}`"
                                            />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        v-if="tile.published_at"
                                        :data-testid="`grid-tile-tooltip-${tile.id}`"
                                    >
                                        {{
                                            $t('channels.grid.sent_at', {
                                                date: date.formatDate(
                                                    tile.published_at,
                                                ),
                                                time: date.formatTime(
                                                    tile.published_at,
                                                ),
                                            })
                                        }}
                                    </TooltipContent>
                                </Tooltip>
                            </li>
                        </ul>
                    </TooltipProvider>
                </InfiniteScroll>
            </div>
        </div>

        <MediaLightbox v-model:open="lightboxOpen" :items="lightboxItems" />
    </AppLayout>
</template>
