<script setup lang="ts">
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import { IconEye, IconRefresh } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import FailedPostDialog from '@/components/admin/posts/FailedPostDialog.vue';
import NetworkFilter from '@/components/NetworkFilter.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableLoadMore,
    TableRow,
} from '@/components/ui/table';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { errorCategoryLabel } from '@/lib/adminErrorCategories';
import { firstError } from '@/lib/adminFormat';
import { blank, filled, isNumber } from '@/lib/helpers';
import { formatFullNumber } from '@/lib/utils';
import { index as failedIndex } from '@/routes/admin/posts/failed';
import { store as retryStore } from '@/routes/admin/posts/retry';
import { show as userShow } from '@/routes/admin/users';
import type { AdminFailedPost, ScrollPage } from '@/types/admin';

const props = defineProps<{
    posts: ScrollPage<AdminFailedPost>;
    networks: string[];
    categories: Record<string, number>;
    filters: { networks: string[]; category: string | null };
}>();

const selectedNetworks = ref<string[]>([...props.filters.networks]);
const selectedCategory = ref<string | null>(props.filters.category);

/** The filters as the URL carries them; All leaves the category out. */
const filterQuery = (): { networks: string[]; category?: string } => {
    if (filled(selectedCategory.value)) {
        return { networks: selectedNetworks.value, category: selectedCategory.value };
    }

    return { networks: selectedNetworks.value };
};

const reload = (): void => {
    router.get(
        failedIndex.url(),
        filterQuery(),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['posts', 'categories', 'filters'],
            reset: ['posts'],
        },
    );
};

watch(selectedNetworks, reload, { deep: true });

const selectCategory = (category: string | null): void => {
    selectedCategory.value = category;
    reload();
};

const totalFailed = computed(() =>
    Object.values(props.categories).reduce((sum, count) => sum + count, 0),
);

/**
 * The categories with failures under the networks picked; the one picked
 * stays with 0 when a network filter leaves it without any, so a chip is
 * always lit for what the list shows.
 */
const categoryCount = (category: string): number => {
    const count = props.categories[category];

    if (isNumber(count)) {
        return count;
    }

    return 0;
};

const categoryCounts = computed((): Record<string, number> => {
    const category = selectedCategory.value;

    if (filled(category)) {
        return { ...props.categories, [category]: categoryCount(category) };
    }

    return props.categories;
});

const categoryChips = computed(() =>
    Object.entries(categoryCounts.value).map(([category, count]) => ({
        category,
        label: errorCategoryLabel(category),
        count: formatFullNumber(count),
    })),
);

const chipClass = (category: string | null): string => {
    if (selectedCategory.value === category) {
        return 'border-foreground bg-foreground text-background';
    }

    return 'border-border bg-card text-foreground hover:bg-muted';
};

const chipCountClass = (category: string | null): string => {
    if (selectedCategory.value === category) {
        return 'text-background/70';
    }

    return 'text-muted-foreground';
};

/** Posts whose channel must be reconnected before a retry can work. */
const RECONNECT_STATUSES = ['token_expired', 'disconnected'];

const needsReconnect = (post: AdminFailedPost): boolean => {
    if (filled(post.channel_status)) {
        return RECONNECT_STATUSES.includes(post.channel_status);
    }

    return false;
};

const channelMissing = (post: AdminFailedPost): boolean => blank(post.channel_status);

const postMeta = (post: AdminFailedPost): string => {
    const parts: string[] = [];

    if (filled(post.content_type_label)) {
        parts.push(post.content_type_label);
    }

    if (filled(post.scheduled_at)) {
        parts.push(`scheduled ${date.formatDateTime(post.scheduled_at)}`);
    }

    if (filled(post.created_via)) {
        parts.push(`via ${post.created_via}`);
    }

    return parts.join(' · ');
};

const failedAgo = (post: AdminFailedPost): string => {
    if (filled(post.failed_at)) {
        return date.diffForHumans(post.failed_at);
    }

    return '—';
};

const failedAt = (post: AdminFailedPost): string => {
    if (filled(post.failed_at)) {
        return date.formatDateTime(post.failed_at);
    }

    return '';
};

const viewedPost = ref<AdminFailedPost | null>(null);

const viewPost = (post: AdminFailedPost): void => {
    viewedPost.value = post;
};

const closePost = (): void => {
    viewedPost.value = null;
};

const hasPosts = computed(() => filled(props.posts.data));

const retryingId = ref<string | null>(null);
const confirmingPost = ref<AdminFailedPost | null>(null);

const confirmOpen = computed({
    get: (): boolean => filled(confirmingPost.value),
    set: (open: boolean): void => {
        if (!open) {
            confirmingPost.value = null;
        }
    },
});

const sendRetry = (post: AdminFailedPost, instagramCommentChecked: boolean): void => {
    router.post(
        retryStore.url(post.id),
        { instagram_comment_checked: instagramCommentChecked },
        {
            preserveScroll: true,
            onStart: () => {
                retryingId.value = post.id;
            },
            onFinish: () => {
                retryingId.value = null;
                confirmingPost.value = null;
            },
            onSuccess: () => {
                toast.success('Retry queued');
            },
            onError: (errors) => {
                toast.error(firstError(errors, 'Could not retry this post.'));
            },
        },
    );
};

const retry = (post: AdminFailedPost): void => {
    if (post.needs_instagram_confirmation) {
        confirmingPost.value = post;

        return;
    }

    sendRetry(post, false);
};

const confirmRetry = (): void => {
    const post = confirmingPost.value;

    if (filled(post)) {
        sendRetry(post, true);
    }
};

const isRetrying = (post: AdminFailedPost): boolean => retryingId.value === post.id;
const isRetryingAny = computed(() => filled(retryingId.value));

const channelLabel = (post: AdminFailedPost): string => {
    if (filled(post.channel_name)) {
        return post.channel_name;
    }

    return getPlatformLabel(post.platform);
};
</script>

<template>
    <Head title="Failed posts" />

    <AdminLayout
        title="Failed posts"
        description="Posts a network refused, across every workspace, the latest first. Retry one once the cause is fixed."
    >
        <template #actions>
            <NetworkFilter
                v-model="selectedNetworks"
                :networks="networks"
                test-id="admin-failed-network"
                sheet-below-sm
            />
        </template>

        <div class="mb-4 flex flex-wrap gap-2" data-testid="admin-failed-categories">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors"
                :class="chipClass(null)"
                data-testid="admin-failed-category-all"
                @click="selectCategory(null)"
            >
                All
                <span class="tabular-nums" :class="chipCountClass(null)">{{
                    formatFullNumber(totalFailed)
                }}</span>
            </button>
            <button
                v-for="chip in categoryChips"
                :key="chip.category"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors"
                :class="chipClass(chip.category)"
                :data-testid="`admin-failed-category-${chip.category}`"
                @click="selectCategory(chip.category)"
            >
                {{ chip.label }}
                <span class="tabular-nums" :class="chipCountClass(chip.category)">{{
                    chip.count
                }}</span>
            </button>
        </div>

        <AdminEmptyState v-if="!hasPosts" variant="page" test-id="admin-failed-empty">
            No failed posts.
        </AdminEmptyState>

        <div
            v-else
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <InfiniteScroll
                data="posts"
                items-element="#admin-failed-body"
                preserve-url
            >
                <div class="overflow-x-auto">
                    <Table data-testid="admin-failed-posts">
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="h-11 border-r-0 px-4 sm:px-5">
                                    Channel
                                </TableHead>
                                <TableHead class="h-11 border-r-0 px-4">
                                    Error
                                </TableHead>
                                <TableHead class="h-11 border-r-0 px-4">
                                    Failed
                                </TableHead>
                                <TableHead class="h-11 w-44 border-r-0 px-4">
                                    <span class="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody id="admin-failed-body">
                            <TableRow
                                v-for="post in posts.data"
                                :key="post.id"
                                class="last:border-b-0"
                                :data-testid="`admin-failed-post-${post.id}`"
                            >
                                <TableCell class="border-r-0 px-4 py-3 align-top sm:px-5">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <PlatformLogo
                                            :platform="post.platform"
                                            size="xs"
                                            class="mt-0.5"
                                        />
                                        <div class="grid min-w-0">
                                            <span
                                                class="truncate text-sm font-emphasis text-foreground"
                                                >{{ channelLabel(post) }}</span
                                            >
                                            <span
                                                class="truncate text-xs text-muted-foreground"
                                                >{{ post.workspace_name }}</span
                                            >
                                            <Link
                                                v-if="post.owner_id"
                                                :href="userShow.url(post.owner_id)"
                                                class="truncate text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                                >{{ post.owner_name }}</Link
                                            >
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell
                                    class="max-w-md border-r-0 px-4 py-3 align-top text-sm whitespace-normal text-foreground"
                                >
                                    <div class="flex flex-col gap-1">
                                        <Badge variant="destructive-subtle" class="w-fit">{{
                                            errorCategoryLabel(post.error_category)
                                        }}</Badge>
                                        <p class="line-clamp-2">{{ post.error_message }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ postMeta(post) }}
                                        </p>
                                    </div>
                                </TableCell>
                                <TableCell
                                    class="border-r-0 px-4 py-3 align-top text-sm whitespace-nowrap text-muted-foreground"
                                    :title="failedAt(post)"
                                >
                                    {{ failedAgo(post) }}
                                </TableCell>
                                <TableCell class="border-r-0 px-4 py-3 align-top">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            :data-testid="`admin-view-post-${post.id}`"
                                            @click="viewPost(post)"
                                        >
                                            <IconEye class="size-4" />
                                            View
                                        </Button>
                                        <Badge
                                            v-if="channelMissing(post)"
                                            variant="secondary"
                                            title="The channel was disconnected and deleted."
                                            >Channel gone</Badge
                                        >
                                        <Badge
                                            v-else-if="needsReconnect(post)"
                                            variant="warning"
                                            title="The channel must be reconnected before a retry can work."
                                            >Reconnect</Badge
                                        >
                                        <Button
                                            v-else-if="post.retryable"
                                            variant="outline"
                                            size="sm"
                                            :loading="isRetrying(post)"
                                            :data-testid="`admin-retry-post-${post.id}`"
                                            @click="retry(post)"
                                        >
                                            <IconRefresh class="size-4" />
                                            Retry
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <template #next="{ loading }">
                    <TableLoadMore v-if="loading" />
                </template>
            </InfiniteScroll>
        </div>
    </AdminLayout>

    <FailedPostDialog :post="viewedPost" @close="closePost" />

    <AlertDialog v-model:open="confirmOpen">
        <AlertDialogContent data-testid="admin-retry-instagram-dialog">
            <AlertDialogHeader>
                <AlertDialogTitle>Check the first comment on Instagram</AlertDialogTitle>
                <AlertDialogDescription>
                    The post is live on Instagram and its first comment may be
                    too. Retry only after checking that the comment is missing,
                    or it will be posted twice.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel data-testid="admin-retry-instagram-cancel">
                    Cancel
                </AlertDialogCancel>
                <Button
                    data-testid="admin-retry-instagram-confirm"
                    :loading="isRetryingAny"
                    @click="confirmRetry"
                >
                    The comment is missing, retry
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
