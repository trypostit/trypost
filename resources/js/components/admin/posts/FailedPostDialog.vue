<script setup lang="ts">
import { computed } from 'vue';

import AdminDetailList, { type AdminDetailRow } from '@/components/admin/AdminDetailList.vue';
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
import { errorCategoryLabel } from '@/lib/adminErrorCategories';
import { filled } from '@/lib/helpers';
import { isVideo } from '@/lib/mediaType';
import type { AdminFailedPost } from '@/types/admin';

/** A failed post as it was sent: its text, media, when and how, and what the network answered. */
const props = defineProps<{
    post: AdminFailedPost | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const isOpen = computed(() => filled(props.post));

const when = (value: string | null | undefined): string => {
    if (filled(value)) {
        return date.formatDateTime(value);
    }

    return '—';
};

const orDash = (value: string | null | undefined): string => {
    if (filled(value)) {
        return value;
    }

    return '—';
};

const channelLabel = computed((): string => {
    if (filled(props.post?.channel_name)) {
        return props.post.channel_name;
    }

    return getPlatformLabel(props.post?.platform ?? '');
});

const subtitle = computed((): string => {
    const workspace = props.post?.workspace_name;

    if (filled(workspace)) {
        return `${getPlatformLabel(props.post?.platform ?? '')} · ${workspace}`;
    }

    return getPlatformLabel(props.post?.platform ?? '');
});

const hasContent = computed(() => filled(props.post?.content));
const hasMedia = computed(() => filled(props.post?.media));

const detailRows = computed((): AdminDetailRow[] => [
    { key: 'type', label: 'Post type', value: orDash(props.post?.content_type_label) },
    { key: 'via', label: 'Created via', value: orDash(props.post?.created_via) },
    { key: 'scheduled', label: 'Scheduled for', value: when(props.post?.scheduled_at) },
    { key: 'failed', label: 'Failed', value: when(props.post?.failed_at) },
    { key: 'channel', label: 'Channel status', value: orDash(props.post?.channel_status) },
]);

/** What the network answered, as TryPost stored it, for debugging. */
const rawContext = computed((): string => JSON.stringify(props.post?.error_context ?? {}, null, 2));

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="isOpen" @update:open="onOpenChange">
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
                        <a
                            v-for="(item, index) in post.media"
                            :key="index"
                            :href="item.url ?? undefined"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="aspect-square overflow-hidden rounded-lg border border-border bg-muted"
                        >
                            <video
                                v-if="isVideo(item)"
                                :src="item.url ?? undefined"
                                class="size-full object-cover"
                                muted
                                preload="metadata"
                            />
                            <img
                                v-else
                                :src="item.url ?? undefined"
                                alt=""
                                class="size-full object-cover"
                                loading="lazy"
                            />
                        </a>
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
