<script setup lang="ts">
import { computed } from 'vue';

import AdminDetailList from '@/components/admin/AdminDetailList.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { channelStatus } from '@/lib/adminChannels';
import { errorCategoryLabel } from '@/lib/adminErrorCategories';
import { joinFilled, orDash } from '@/lib/adminFormat';
import { originLabel } from '@/lib/adminPublishing';
import { filled } from '@/lib/helpers';
import { isVideo } from '@/lib/mediaType';
import { MISSING_VALUE } from '@/lib/utils';
import type { AdminDetailRow, AdminFailedPost } from '@/types/admin';

/** A failed post as it was sent: its text, media, when and how, and what the network answered. */
const props = defineProps<{
    /** The last post opened, kept while the dialog animates out. */
    post: AdminFailedPost | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const platformLabel = computed((): string => {
    if (filled(props.post)) {
        return getPlatformLabel(props.post.platform);
    }

    return '';
});

const channelLabel = computed((): string => {
    const name = props.post?.channel_name;

    if (filled(name)) {
        return name;
    }

    return platformLabel.value;
});

const subtitle = computed((): string => joinFilled([platformLabel.value, props.post?.workspace_name]));

const hasContent = computed(() => filled(props.post?.content));
const hasMedia = computed(() => filled(props.post?.media));

/** Each attachment with its file, if it is still there, and what a screen reader names it. */
const mediaItems = computed(() => {
    if (filled(props.post)) {
        return props.post.media.map((item, index) => ({
            key: index,
            url: item.url,
            isVideo: isVideo(item),
            label: `Open attachment ${index + 1} in a new tab`,
            missingLabel: `Attachment ${index + 1}: the file is gone`,
        }));
    }

    return [];
});

const viaLabel = (post: AdminFailedPost): string => {
    if (filled(post.created_via)) {
        return originLabel(post.created_via);
    }

    return MISSING_VALUE;
};

/** A post whose channel was deleted has no channel status. */
const channelStatusLabel = (post: AdminFailedPost): string => {
    if (filled(post.channel_status)) {
        return channelStatus(post.channel_status).label;
    }

    return 'Channel gone';
};

const postDetails = (post: AdminFailedPost): AdminDetailRow[] => [
    { key: 'type', label: 'Post type', value: orDash(post.content_type_label) },
    { key: 'via', label: 'Created via', value: viaLabel(post) },
    { key: 'scheduled', label: 'Scheduled for', value: date.formatDateTime(post.scheduled_at) },
    { key: 'failed', label: 'Failed', value: date.formatDateTime(post.failed_at) },
    { key: 'channel', label: 'Channel status', value: channelStatusLabel(post) },
];

const detailRows = computed((): AdminDetailRow[] => {
    if (filled(props.post)) {
        return postDetails(props.post);
    }

    return [];
});

/** What the network answered, as TryPost stored it, for debugging. */
const rawContext = computed((): string => {
    const context = props.post?.error_context;

    if (filled(context)) {
        return JSON.stringify(context, null, 2);
    }

    return '{}';
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="gap-0 p-0 sm:max-h-[min(720px,85dvh)] sm:max-w-2xl"
            data-testid="admin-failed-post-dialog"
        >
            <div v-if="post" class="flex flex-col gap-6 overflow-y-auto p-6">
                <header class="flex items-center gap-3 pe-8">
                    <PlatformLogo :platform="post.platform" :size="36" />
                    <div class="grid min-w-0">
                        <DialogTitle class="truncate text-base font-semibold">
                            {{ channelLabel }}
                        </DialogTitle>
                        <DialogDescription class="truncate text-sm text-muted-foreground">
                            {{ subtitle }}
                        </DialogDescription>
                    </div>
                </header>

                <section class="flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <Badge variant="destructive-subtle">{{
                            errorCategoryLabel(post.error_category)
                        }}</Badge>
                    </div>
                    <p class="text-sm text-foreground">{{ post.error_message }}</p>
                </section>

                <section class="flex flex-col gap-2">
                    <p class="text-xs font-medium text-muted-foreground">Post</p>
                    <p
                        v-if="hasContent"
                        class="max-h-64 overflow-y-auto rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm whitespace-pre-wrap text-foreground"
                        data-testid="admin-failed-post-content"
                    >
                        {{ post.content }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">No text.</p>
                    <div v-if="hasMedia" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        <template v-for="item in mediaItems" :key="item.key">
                            <a
                                v-if="item.url"
                                :href="item.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                :aria-label="item.label"
                                class="aspect-square overflow-hidden rounded-lg border border-border bg-muted"
                            >
                                <video
                                    v-if="item.isVideo"
                                    :src="item.url"
                                    class="size-full object-cover"
                                    muted
                                    preload="metadata"
                                />
                                <img
                                    v-else
                                    :src="item.url"
                                    alt=""
                                    class="size-full object-cover"
                                    loading="lazy"
                                />
                            </a>
                            <span
                                v-else
                                class="flex aspect-square items-center justify-center rounded-lg border border-dashed border-border p-2 text-center text-xs text-muted-foreground"
                                >{{ item.missingLabel }}</span
                            >
                        </template>
                    </div>
                </section>

                <AdminDetailList :rows="detailRows" test-id="admin-failed-post-details" />

                <details class="group">
                    <summary
                        class="cursor-pointer text-xs font-medium text-muted-foreground hover:text-foreground"
                    >
                        What the network answered
                    </summary>
                    <pre
                        class="mt-2 max-h-64 overflow-auto rounded-xl bg-muted px-4 py-3 font-mono text-[11px] text-foreground"
                    >{{ rawContext }}</pre>
                </details>
            </div>
        </DialogContent>
    </Dialog>
</template>
