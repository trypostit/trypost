<script setup lang="ts">
import { IconExternalLink } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import MediaLightbox from '@/components/media/MediaLightbox.vue';
import PostDetailsMedia from '@/components/publish/PostDetailsMedia.vue';
import PostMetricsBand from '@/components/publish/PostMetricsBand.vue';
import PostStatusBadge from '@/components/publish/PostStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import {
    PublicationAvailability,
    type PublicationAnalyticsDetail,
} from '@/types/analytics';
import type { MediaItem } from '@/types/media';

/**
 * The details of a network publication that has no TryPost post: what the
 * analytics sync stored (text, thumbnail, metrics) and a link to the network.
 */
const props = defineProps<{
    detail: PublicationAnalyticsDetail;
    channelId: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const publication = computed(() => props.detail.publication);


const isDeletedOnNetwork = computed(
    () => publication.value.availability === PublicationAvailability.Deleted,
);

const accountName = computed(
    () =>
        publication.value.account_display_name ||
        publication.value.account_username ||
        getPlatformLabel(publication.value.platform),
);

const permalink = computed(() =>
    publication.value.permalink && /^https:\/\//i.test(publication.value.permalink)
        ? publication.value.permalink
        : null,
);

const media = computed<MediaItem[]>(() => {
    const thumbnail = publication.value.preview_metadata?.thumbnail_url;

    return typeof thumbnail === 'string' && /^https:\/\//i.test(thumbnail)
        ? [{ id: publication.value.id, url: thumbnail, type: 'image' }]
        : [];
});

const lightboxOpen = ref(false);

const openMediaPreview = (): void => {
    lightboxOpen.value = true;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="gap-0 p-0 sm:max-w-xl"
            :data-testid="`publication-details-${publication.id}`"
        >
            <div class="flex min-w-0 flex-col gap-4 px-6 pt-6 pb-4">
                <DialogHeader class="pe-8">
                    <DialogTitle>{{ $t('posts.show.title') }}</DialogTitle>
                    <DialogDescription
                        class="flex flex-wrap items-center gap-2"
                        as="div"
                    >
                        <PostStatusBadge
                            v-if="isDeletedOnNetwork"
                            status="deleted_on_network"
                            :data-testid="`publication-details-deleted-on-network-${publication.id}`"
                        />
                        <PostStatusBadge v-else status="published" />
                        <span
                            v-if="publication.provider_published_at"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                $t('posts.show.published_on', {
                                    date: date.formatDateTimeInTimezone(
                                        publication.provider_published_at,
                                        date.getUserTimezone(),
                                    ),
                                })
                            }}
                        </span>
                    </DialogDescription>
                </DialogHeader>

                <section class="flex items-center gap-3">
                    <ChannelAvatar
                        :platform="publication.platform"
                        :src="publication.account_avatar_url"
                        :name="accountName"
                        :size="40"
                    />
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span
                            class="truncate text-sm font-emphasis text-foreground"
                            >{{ accountName }}</span
                        >
                        <span
                            v-if="publication.account_username"
                            class="truncate text-xs text-muted-foreground"
                            >{{ publication.account_username }}</span
                        >
                    </span>
                </section>

                <p
                    class="text-sm break-words whitespace-pre-line"
                    :class="
                        publication.excerpt
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                    :data-testid="`publication-details-text-${publication.id}`"
                >
                    {{ publication.excerpt || $t('calendar.no_content') }}
                </p>

                <PostDetailsMedia
                    v-if="media.length"
                    :items="media"
                    :test-key="publication.id"
                    @open="openMediaPreview"
                />

                <PostMetricsBand
                    :detail="detail"
                    :channel-id="channelId"
                    class="-mx-6 px-6"
                    :metrics-test-id="`publication-details-metrics-${publication.id}`"
                />

                <DialogFooter class="sm:justify-between">
                    <p class="min-w-0 truncate text-sm text-foreground">
                        {{
                            $t('posts.publish.published_directly_from', {
                                network: getPlatformLabel(publication.platform),
                            })
                        }}
                    </p>
                    <Button
                        v-if="permalink"
                        as="a"
                        :href="permalink"
                        target="_blank"
                        rel="noopener noreferrer"
                        variant="outline"
                        :data-testid="`publication-details-view-${publication.id}`"
                    >
                        <IconExternalLink class="size-4" />
                        {{ $t('posts.publish.actions.view_post') }}
                    </Button>
                </DialogFooter>
            </div>
            <MediaLightbox
                v-model:open="lightboxOpen"
                :items="media"
                :start-index="0"
            />
        </DialogContent>
    </Dialog>
</template>
