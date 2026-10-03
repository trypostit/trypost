<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconCheck,
    IconCompass,
    IconDots,
    IconFolder,
    IconFolderSymlink,
    IconPencil,
    IconPlus,
    IconRefresh,
    IconRss,
    IconTrash,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import FeedFavicon from '@/components/create/feeds/FeedFavicon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import date from '@/date';
import { show } from '@/routes/app/create/feeds';
import type {
    RssFeed,
    RssFeedCollection,
    RssFeedsScope,
} from '@/types/rssFeed';

const props = defineProps<{
    scope: RssFeedsScope;
    feeds: RssFeed[];
    collections: RssFeedCollection[];
    lastRefreshedAt: string | null;
    refreshing: boolean;
}>();

const emit = defineEmits<{
    rename: [];
    addFeed: [];
    explore: [];
    move: [collectionId: string | null];
    delete: [];
    refresh: [];
}>();

const feed = computed<RssFeed | null>(() => {
    if (props.scope.kind !== 'feed') {
        return null;
    }

    const id = props.scope.feed.id;

    return props.feeds.find((candidate) => candidate.id === id) ?? props.scope.feed;
});

const collection = computed<RssFeedCollection | null>(() => {
    if (props.scope.kind !== 'collection') {
        return null;
    }

    const id = props.scope.collection.id;

    return (
        props.collections.find((candidate) => candidate.id === id) ??
        props.scope.collection
    );
});

const name = computed(
    () => feed.value?.display_title ?? collection.value?.name ?? null,
);

const failing = computed(
    () =>
        feed.value !== null &&
        feed.value.consecutive_failures >= 3 &&
        feed.value.last_error !== null,
);

const collectionFeeds = computed(() =>
    collection.value === null
        ? []
        : props.feeds.filter(
              (candidate) =>
                  candidate.rss_feed_collection_id === collection.value?.id,
          ),
);
</script>

<template>
    <div class="mx-auto w-full max-w-[900px] px-4 pt-6 md:px-0">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="flex min-w-0 items-center gap-2">
                <FeedFavicon v-if="feed" :url="feed.icon_url" :size="32" />
                <span
                    v-else
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted"
                >
                    <IconFolder
                        v-if="collection"
                        class="size-4 text-muted-foreground"
                    />
                    <IconRss v-else class="size-4 text-muted-foreground" />
                </span>
                <h2
                    class="truncate font-heading text-lg font-medium text-foreground"
                    data-testid="feeds-scope-name"
                >
                    {{ name ?? $t('create.feeds.all_feeds') }}
                </h2>
                <DropdownMenu v-if="name !== null">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="shrink-0 data-[state=open]:bg-accent"
                            :aria-label="
                                $t('create.feeds.actions_for', { title: name })
                            "
                            data-testid="feeds-scope-menu"
                        >
                            <IconDots class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="start"
                        class="w-52"
                        @close-auto-focus.prevent
                    >
                        <template v-if="collection">
                            <div
                                v-if="collectionFeeds.length"
                                class="max-h-60 overflow-y-auto"
                                data-testid="feeds-collection-menu-feeds"
                            >
                                <DropdownMenuItem
                                    v-for="member in collectionFeeds"
                                    :key="member.id"
                                    as-child
                                >
                                    <Link
                                        :href="show.url(member.id)"
                                        :data-testid="`feeds-collection-menu-feed-${member.id}`"
                                    >
                                        <FeedFavicon :url="member.icon_url" />
                                        <span class="flex-1 truncate">{{
                                            member.display_title
                                        }}</span>
                                    </Link>
                                </DropdownMenuItem>
                            </div>
                            <DropdownMenuSeparator
                                v-if="collectionFeeds.length"
                            />
                            <DropdownMenuSub>
                                <DropdownMenuSubTrigger
                                    data-testid="feeds-add-to-collection"
                                >
                                    <IconPlus class="size-4" />
                                    {{ $t('create.feeds.add_to_collection') }}
                                </DropdownMenuSubTrigger>
                                <DropdownMenuSubContent class="w-52">
                                    <DropdownMenuItem
                                        data-testid="feeds-collection-add-feed"
                                        @click="emit('addFeed')"
                                    >
                                        <IconRss class="size-4" />
                                        {{ $t('create.feeds.add.submit') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        data-testid="feeds-collection-explore"
                                        @click="emit('explore')"
                                    >
                                        <IconCompass class="size-4" />
                                        {{ $t('create.feeds.explore') }}
                                    </DropdownMenuItem>
                                </DropdownMenuSubContent>
                            </DropdownMenuSub>
                        </template>
                        <DropdownMenuSub v-if="feed">
                            <DropdownMenuSubTrigger data-testid="feeds-move-to">
                                <IconFolderSymlink class="size-4" />
                                {{ $t('create.feeds.move_to') }}
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent class="w-52">
                                <DropdownMenuItem
                                    data-testid="feeds-move-to-none"
                                    @click="emit('move', null)"
                                >
                                    <IconRss class="size-4" />
                                    <span class="flex-1 truncate">{{
                                        $t('create.feeds.feeds_root')
                                    }}</span>
                                    <IconCheck
                                        v-if="feed.rss_feed_collection_id === null"
                                        class="size-4"
                                    />
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-for="option in collections"
                                    :key="option.id"
                                    :data-testid="`feeds-move-to-${option.id}`"
                                    @click="emit('move', option.id)"
                                >
                                    <IconFolder class="size-4" />
                                    <span class="flex-1 truncate">{{
                                        option.name
                                    }}</span>
                                    <IconCheck
                                        v-if="
                                            feed.rss_feed_collection_id ===
                                            option.id
                                        "
                                        class="size-4"
                                    />
                                </DropdownMenuItem>
                            </DropdownMenuSubContent>
                        </DropdownMenuSub>
                        <DropdownMenuItem
                            data-testid="feeds-rename"
                            @click="emit('rename')"
                        >
                            <IconPencil class="size-4" />
                            {{ $t('create.feeds.rename') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            data-testid="feeds-delete"
                            @click="emit('delete')"
                        >
                            <IconTrash class="size-4" />
                            {{ $t('create.feeds.delete') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <div class="flex shrink-0 items-center gap-1">
                <span
                    class="text-sm text-muted-foreground"
                    data-testid="feeds-last-refreshed"
                >
                    {{
                        lastRefreshedAt
                            ? $t('create.feeds.last_refreshed', {
                                  time: date.diffForHumans(lastRefreshedAt),
                              })
                            : $t('create.feeds.never_refreshed')
                    }}
                </span>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    :disabled="refreshing"
                    :aria-busy="refreshing"
                    :aria-label="$t('create.feeds.refresh')"
                    data-testid="feeds-refresh"
                    @click="emit('refresh')"
                >
                    <IconRefresh
                        class="size-4"
                        :class="{ 'animate-spin': refreshing }"
                    />
                </Button>
            </div>
        </div>

        <p
            v-if="failing && feed?.last_error"
            role="status"
            class="mt-3 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-sm text-foreground"
            data-testid="feeds-failing-warning"
        >
            <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
            {{
                $t('create.feeds.failing', {
                    error: $t(feed.last_error),
                })
            }}
        </p>
    </div>
</template>
