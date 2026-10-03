<script setup lang="ts">
import {
    Head,
    InfiniteScroll,
    router,
    useHttp,
    usePoll,
} from '@inertiajs/vue3';
import {
    IconCircleCheck,
    IconCompass,
    IconFolder,
    IconPlus,
    IconRss,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import CreateHeader from '@/components/create/CreateHeader.vue';
import CreateTabs from '@/components/create/CreateTabs.vue';
import AddFeedDialog from '@/components/create/feeds/AddFeedDialog.vue';
import ExploreFeedsDialog from '@/components/create/feeds/ExploreFeedsDialog.vue';
import FeedChips from '@/components/create/feeds/FeedChips.vue';
import FeedItemRow from '@/components/create/feeds/FeedItemRow.vue';
import FeedListHeader from '@/components/create/feeds/FeedListHeader.vue';
import FeedNameDialog from '@/components/create/feeds/FeedNameDialog.vue';
import NewFeedSplitButton from '@/components/create/feeds/NewFeedSplitButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    destroy as destroyCollection,
    store as storeCollection,
    update as updateCollection,
} from '@/routes/app/create/feed-collections';
import { idea, importImage } from '@/routes/app/create/feed-items';
import {
    destroy,
    refresh as refreshFeeds,
    store,
    update,
} from '@/routes/app/create/feeds';
import type { MediaItem } from '@/types/media';
import type {
    RssFeed,
    RssFeedCollection,
    RssFeedDirectoryCategory,
    RssFeedDirectoryEntry,
    RssFeedItem,
    RssFeedItemPage,
    RssFeedsLimits,
    RssFeedsScope,
} from '@/types/rssFeed';

const props = defineProps<{
    scope: RssFeedsScope;
    feeds: RssFeed[];
    collections: RssFeedCollection[];
    items: RssFeedItemPage;
    last_refreshed_at: string | null;
    refreshing: boolean;
    limits: RssFeedsLimits;
    directory?: RssFeedDirectoryCategory[];
}>();

const POLL_PROPS = ['refreshing', 'last_refreshed_at', 'feeds'];
const POLL_LIMIT_MS = 30_000;

const toastFirstError = (errors: Record<string, string>): void => {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
};

const toastRequestFailed = (): false => {
    toast.error(trans('create.feeds.errors.request_failed'));

    return false;
};

const writeOptions = {
    preserveState: true,
    preserveScroll: true,
    onError: toastFirstError,
    onHttpException: toastRequestFailed,
};

const itemList = computed(() => props.items?.data ?? []);

const scopeFeeds = computed(() => {
    if (props.scope.kind === 'collection') {
        const id = props.scope.collection.id;

        return props.feeds.filter((feed) => feed.rss_feed_collection_id === id);
    }

    return props.feeds;
});

const targetCollectionId = ref<string | null>(null);
const modal = ref<'add' | 'explore' | null>(null);
const directory = ref<RssFeedDirectoryCategory[] | null>(
    props.directory ?? null,
);

watch(
    () => props.directory,
    (value) => {
        if (value) {
            directory.value = value;
        }
    },
);

const reloadDirectory = (onFinish?: () => void): void => {
    router.reload({ only: ['directory'], onFinish });
};

const closeModal = (): void => {
    modal.value = null;
    targetCollectionId.value = null;
};

const openModal = (
    next: 'add' | 'explore',
    collectionId: string | null = null,
): void => {
    targetCollectionId.value = collectionId;
    modal.value = next;

    if (next === 'explore') {
        reloadDirectory();
    }
};

const creatingCollection = ref(false);

const startCreatingCollection = (): void => {
    creatingCollection.value = true;
};

const stopCreatingCollection = (): void => {
    creatingCollection.value = false;
};

const renaming = ref(false);

const startRenaming = (): void => {
    renaming.value = true;
};

const stopRenaming = (): void => {
    renaming.value = false;
};

const createCollection = (name: string): void => {
    creatingCollection.value = false;
    router.post(storeCollection.url(), { name }, writeOptions);
};

const openFromCollection = (modal: 'add' | 'explore'): void => {
    openModal(
        modal,
        props.scope.kind === 'collection' ? props.scope.collection.id : null,
    );
};

const scopeName = computed(() => {
    const scope = props.scope;

    if (scope.kind === 'feed') {
        return (
            props.feeds.find((feed) => feed.id === scope.feed.id)
                ?.display_title ?? scope.feed.display_title
        );
    }

    if (scope.kind === 'collection') {
        return (
            props.collections.find(
                (collection) => collection.id === scope.collection.id,
            )?.name ?? scope.collection.name
        );
    }

    return '';
});

const rename = (name: string): void => {
    renaming.value = false;

    if (name === scopeName.value) {
        return;
    }

    if (props.scope.kind === 'feed') {
        router.put(
            update.url(props.scope.feed.id),
            { custom_title: name },
            writeOptions,
        );
    }

    if (props.scope.kind === 'collection') {
        router.put(
            updateCollection.url(props.scope.collection.id),
            { name },
            writeOptions,
        );
    }
};

const move = (collectionId: string | null): void => {
    if (props.scope.kind !== 'feed') {
        return;
    }

    router.put(
        update.url(props.scope.feed.id),
        { rss_feed_collection_id: collectionId },
        writeOptions,
    );
};

const feedDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);
const collectionDeleteModal = ref<InstanceType<
    typeof ConfirmDeleteModal
> | null>(null);

const deleteFeed = (feedId: string): void => {
    feedDeleteModal.value?.open({
        url: destroy.url(feedId),
    });
};

const deleteScope = (): void => {
    if (props.scope.kind === 'feed') {
        deleteFeed(props.scope.feed.id);
    }

    if (props.scope.kind === 'collection') {
        collectionDeleteModal.value?.open({
            url: destroyCollection.url(props.scope.collection.id),
        });
    }
};

const pendingDirectoryUrls = ref<Set<string>>(new Set());

const addFromDirectory = (entry: RssFeedDirectoryEntry): void => {
    pendingDirectoryUrls.value = new Set([
        ...pendingDirectoryUrls.value,
        entry.url,
    ]);

    const clearPending = (): void => {
        const next = new Set(pendingDirectoryUrls.value);
        next.delete(entry.url);
        pendingDirectoryUrls.value = next;
    };
    let added = false;

    router.post(
        store.url(),
        {
            url: entry.url,
            source: 'explore',
            rss_feed_collection_id: targetCollectionId.value,
        },
        {
            ...writeOptions,
            onSuccess: () => {
                added = true;
                reloadDirectory(clearPending);
            },
            onFinish: () => {
                if (!added) {
                    clearPending();
                }
            },
        },
    );
};

const directoryDeleteModal = ref<InstanceType<
    typeof ConfirmDeleteModal
> | null>(null);

const removeFromDirectory = (entry: RssFeedDirectoryEntry): void => {
    if (entry.feed_id) {
        directoryDeleteModal.value?.open({
            url: destroy.url(entry.feed_id),
        });
    }
};

const savedIds = ref<Set<string>>(new Set());
const savingIds = ref<Set<string>>(new Set());

const saveAsIdea = (item: RssFeedItem): void => {
    if (savingIds.value.has(item.id) || creatingPostId.value === item.id) {
        return;
    }

    savingIds.value = new Set([...savingIds.value, item.id]);

    router.post(
        idea.url(item.id),
        {},
        {
            ...writeOptions,
            only: ['limits'],
            onSuccess: () => {
                savedIds.value = new Set([...savedIds.value, item.id]);
            },
            onFinish: () => {
                const next = new Set(savingIds.value);
                next.delete(item.id);
                savingIds.value = next;
            },
        },
    );
};

const importHttp = useHttp<Record<string, never>, { data: MediaItem | null }>(
    {},
);
const creatingPostId = ref<string | null>(null);

const createPost = async (item: RssFeedItem): Promise<void> => {
    if (creatingPostId.value !== null || savingIds.value.has(item.id)) {
        return;
    }

    creatingPostId.value = item.id;
    let media: MediaItem[] = [];

    try {
        const result = await importHttp.post(importImage.url(item.id));

        if (result?.data) {
            media = [result.data];
        }
    } catch {
        media = [];
    } finally {
        creatingPostId.value = null;
    }

    openPostComposer({
        draft: {
            content: [item.title, item.url]
                .filter((part) => part)
                .join('\n\n'),
            media,
            scheduled_at: null,
            label_ids: [],
        },
    });
};

const refreshPending = ref(false);
const polling = ref(false);
let pollTimeout: ReturnType<typeof setTimeout> | null = null;

const { start: startPoll, stop: stopPoll } = usePoll(
    3000,
    { only: POLL_PROPS },
    { autoStart: false },
);

const stopPolling = (): void => {
    stopPoll();
    polling.value = false;

    if (pollTimeout) {
        clearTimeout(pollTimeout);
        pollTimeout = null;
    }
};

const startPolling = (): void => {
    stopPolling();
    polling.value = true;
    startPoll();
    pollTimeout = setTimeout(stopPolling, POLL_LIMIT_MS);
};

watch(
    () => props.refreshing,
    (refreshing) => {
        if (refreshing || !polling.value) {
            return;
        }

        stopPolling();
        router.reload({ only: ['items', 'last_refreshed_at'], reset: ['items'] });
    },
);

onBeforeUnmount(stopPolling);

const refresh = (): void => {
    refreshPending.value = true;

    router.post(
        refreshFeeds.url(),
        {
            rss_feed_id: props.scope.kind === 'feed' ? props.scope.feed.id : null,
            rss_feed_collection_id:
                props.scope.kind === 'collection'
                    ? props.scope.collection.id
                    : null,
        },
        {
            ...writeOptions,
            only: POLL_PROPS,
            onSuccess: () => {
                if (props.refreshing) {
                    startPolling();
                }
            },
            onFinish: () => {
                refreshPending.value = false;
            },
        },
    );
};
</script>

<template>
    <Head :title="$t('create.tabs.feeds')" />

    <AppLayout full-width>
        <template #header>
            <CreateHeader />
        </template>

        <template #header-actions>
            <NewFeedSplitButton
                @add="openModal('add')"
                @explore="openModal('explore')"
                @new-collection="startCreatingCollection"
            />
        </template>

        <div
            class="flex min-h-0 flex-1 flex-col overflow-hidden"
            data-testid="feeds-page"
        >
            <CreateTabs active="feeds" />

            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col overflow-auto overscroll-contain"
                data-testid="feeds-scroll"
            >
                <FeedChips
                    :feeds="feeds"
                    :collections="collections"
                    :scope="scope"
                />

                <div
                    v-if="feeds.length === 0"
                    class="flex flex-1"
                    data-testid="feeds-empty"
                >
                    <EmptyState
                        :icon="IconRss"
                        :title="$t('create.feeds.empty.title')"
                        :description="$t('create.feeds.empty.body')"
                    >
                        <template #action>
                            <Button variant="outline" @click="openModal('explore')">
                                <IconCompass class="size-4" />
                                {{ $t('create.feeds.explore') }}
                            </Button>
                            <Button @click="openModal('add')">
                                <IconPlus class="size-4" />
                                {{ $t('create.feeds.add.submit') }}
                            </Button>
                        </template>
                    </EmptyState>
                </div>

                <template v-else>
                    <FeedListHeader
                        :scope="scope"
                        :feeds="feeds"
                        :collections="collections"
                        :last-refreshed-at="last_refreshed_at"
                        :refreshing="refreshing || refreshPending"
                        @rename="startRenaming"
                        @add-feed="openFromCollection('add')"
                        @explore="openFromCollection('explore')"
                        @move="move"
                        @delete="deleteScope"
                        @refresh="refresh"
                    />

                    <div
                        v-if="scopeFeeds.length === 0"
                        data-testid="feeds-empty"
                    >
                        <EmptyState
                            :icon="IconFolder"
                            :title="$t('create.feeds.empty_collection.title')"
                            :description="
                                $t('create.feeds.empty_collection.body')
                            "
                        />
                    </div>

                    <div
                        v-else-if="itemList.length === 0"
                        data-testid="feeds-no-items"
                    >
                        <EmptyState
                            :icon="IconRss"
                            :title="$t('create.feeds.empty_items.title')"
                            :description="$t('create.feeds.empty_items.body')"
                        />
                    </div>

                    <InfiniteScroll
                        v-else
                        data="items"
                        items-element="#feeds-items"
                        preserve-url
                    >
                        <div
                            id="feeds-items"
                            class="mx-auto flex w-full max-w-[900px] flex-col gap-1 px-1 pt-3 md:px-0"
                            data-testid="feeds-items"
                        >
                            <FeedItemRow
                                v-for="item in itemList"
                                :key="item.id"
                                :item="item"
                                :saved="savedIds.has(item.id)"
                                :saving="savingIds.has(item.id)"
                                :creating-post="creatingPostId === item.id"
                                :busy="
                                    creatingPostId !== null ||
                                    savingIds.has(item.id)
                                "
                                @create-post="createPost(item)"
                                @save-idea="saveAsIdea(item)"
                            />
                        </div>

                        <template #next="{ loading, hasMore }">
                            <p
                                v-if="loading"
                                class="py-5 text-center text-sm text-muted-foreground"
                                role="status"
                            >
                                {{ $t('common.loading_more') }}
                            </p>
                            <p
                                v-else-if="!hasMore"
                                class="flex items-center justify-center gap-2 pt-6 pb-12 text-sm text-muted-foreground"
                                data-testid="feeds-caught-up"
                            >
                                <IconCircleCheck class="size-4 text-success" />
                                {{ $t('create.feeds.caught_up') }}
                            </p>
                        </template>
                    </InfiniteScroll>
                </template>
            </div>
        </div>
    </AppLayout>

    <AddFeedDialog
        v-if="modal === 'add'"
        :collection-id="targetCollectionId"
        @close="closeModal"
    />

    <FeedNameDialog
        v-if="creatingCollection"
        :title="$t('create.feeds.new_collection')"
        :label="$t('create.feeds.collection_placeholder')"
        :submit-label="$t('create.feeds.create')"
        testid="feeds-new-collection-dialog"
        @close="stopCreatingCollection"
        @save="createCollection"
    />

    <FeedNameDialog
        v-if="renaming"
        :title="
            scope.kind === 'collection'
                ? $t('create.feeds.rename_collection')
                : $t('create.feeds.rename_feed')
        "
        :label="
            scope.kind === 'collection'
                ? $t('create.feeds.collection_placeholder')
                : $t('create.feeds.feed_name')
        "
        :submit-label="$t('create.feeds.save')"
        :initial-name="scopeName"
        testid="feeds-rename-dialog"
        @close="stopRenaming"
        @save="rename"
    />

    <ExploreFeedsDialog
        v-if="modal === 'explore'"
        :directory="directory"
        :pending-urls="pendingDirectoryUrls"
        @close="closeModal"
        @add="addFromDirectory"
        @remove="removeFromDirectory"
    >
        <ConfirmDeleteModal
            ref="directoryDeleteModal"
            :title="$t('create.feeds.delete_feed.title')"
            :description="$t('create.feeds.delete_feed.body')"
            :action="$t('create.feeds.delete')"
            :cancel="$t('create.feeds.cancel')"
            @deleted="reloadDirectory()"
        />
    </ExploreFeedsDialog>

    <ConfirmDeleteModal
        ref="feedDeleteModal"
        :title="$t('create.feeds.delete_feed.title')"
        :description="$t('create.feeds.delete_feed.body')"
        :action="$t('create.feeds.delete')"
        :cancel="$t('create.feeds.cancel')"
    />

    <ConfirmDeleteModal
        ref="collectionDeleteModal"
        :title="$t('create.feeds.delete_collection.title')"
        :description="$t('create.feeds.delete_collection.body')"
        :action="$t('create.feeds.delete')"
        :cancel="$t('create.feeds.cancel')"
    />
</template>
